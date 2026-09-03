# sales-documents-cqrs

## Pliki środowiskowe

Sekrety i dane połączenia są celowo puste w commitowanych `.env`, `.env.dev` i `.env.test`.
Prawdziwe wartości trzymamy w nieśledzonych `.env.local` / `.env.test.local`:

```bash
cp .env .env.local
cp .env.test .env.test.local
```

`.env.local`:

```dotenv
APP_SECRET=<dowolny 32-znakowy ciąg hex>
DEFAULT_URI=http://localhost
DATABASE_URL="postgresql://app:app_secret@127.0.0.1:5432/app?serverVersion=16&charset=utf8"
```

`.env.test.local`:

```dotenv
APP_SECRET=<dowolny niepusty ciąg>
DATABASE_URL="postgresql://app:app_secret@127.0.0.1:5432/app_test?serverVersion=16&charset=utf8"
```

Dane logowania jak w `compose.yaml` (`app` / `app_secret`).

## Problem 1. Zatwierdzenie zgłaszane jako nieudane, mimo że się powiodło

### Przyczyna

Handler zatwierdza dokument w transakcji, a powiadomienia wysyła dopiero po jej zamknięciu i to
akurat jest dobra decyzja, bo awaria kanału powiadomień nie powinna cofać zatwierdzenia. Zabrakło
jednak drugiej połowy tej decyzji, czyli obsługi błędu. Powiadomienie rzuca wyjątek, nikt go nie
łapie, więc leci on dalej z handlera. Messenger opakowuje go w `HandlerFailedException`, a że
szyna działa na transporcie `sync`, wyjątek wraca prosto z `dispatch()` do kontrolera i kończy się
odpowiedzią 500, chociaż dokument jest już zatwierdzony i zapisany.

### Skutek

Danych nie da się już cofnąć, więc odpowiedź kłóci się ze stanem bazy. Wynik operacji zaczyna
zależeć od podsystemu, który nie ma z nią nic wspólnego, bo to samo zatwierdzenie zwraca raz 200,
a raz 500, zależnie od tego czy akurat działa kanał powiadomień. Klient, który uwierzy w 500 i
ponowi żądanie, dostaje w odpowiedzi "Document cannot be approved in its current status", czyli
drugi błąd wywołany przez pierwszy, a dokument przez cały ten czas jest zatwierdzony.

### Jak należało to rozwiązać

Powiadomienie ma zostać tam gdzie jest, czyli po zamknięciu transakcji, ale jego błąd nie może
decydować o wyniku operacji, która już się zakończyła. Zatwierdzenie jest faktem, a poinformowanie
o nim to tylko próba dostarczenia wiadomości i nieudana próba nie unieważnia samego faktu.

Rozważyłem przeniesienie powiadomień do wnętrza transakcji, ale wtedy niedostępny kanał
blokowałby zatwierdzanie dokumentów, czyli awaria poczty zatrzymywałaby sprzedaż. Odrzuciłem też
transport asynchroniczny z ponawianiem, bo zadanie wprost go wyklucza i nie jest tu potrzebny,
skoro problem leży w granicy odpowiedzialności, a nie w synchroniczności.

Łapię `\Throwable`, a nie tylko `\Exception`, celowo. Chodzi o to, żeby zatwierdzenie nie
przepadło w żadnych okolicznościach, a błąd programistyczny wewnątrz kanału powiadomień
(`TypeError`, wywołanie na `null`) jest z punktu widzenia klienta tym samym co awaria kanału.
Taki błąd i tak trafia do logu razem z wyjątkiem, więc nie ginie.

### Jak zostało rozwiązane

Powiadomienia po zatwierdzeniu trafiły do `ApprovalNotifier` w `src/Notification/`, który izoluje
kanał. Każdy odbiorca jest obsługiwany osobno, błąd trafia do logu i nie wychodzi na zewnątrz, a
awaria przy pierwszym odbiorcy nie anuluje drugiego.

```php
private function notifyQuietly(int $userId, string $message): void
{
    try {
        $this->notifier->notify($userId, $message);
    } catch (\Throwable $e) {
        $this->logger->error('Failed to notify user {userId} about an approved document', [
            'userId' => $userId,
            'exception' => $e,
        ]);
    }
}
```

Handler nie wie już nic o odporności kanału, więc zostaje przy samej logice zatwierdzania.
Przy okazji transakcja zwraca teraz zatwierdzoną encję zamiast jej identyfikatora, więc nie ma już
ponownego `find()` po commicie ani ścieżki, na której mógłby on zwrócić `null`. Sama granica
transakcji pozostała nietknięta.

Osobna klasa zamiast `try/catch` w handlerze ma jeszcze jeden powód. Test podmienia usługę przez
`getContainer()->set(NotifierPort::class, ...)`, czyli po tym samym identyfikatorze, więc dekorator
nałożony na `NotifierPort` zostałby przy takiej podmianie pominięty.

Samo `try/catch` bez logowania też załatwiłoby test, ale wprowadziłoby drugi błąd w miejsce
pierwszego, bo powiadomienia ginęłyby po cichu i nikt nigdy by się nie dowiedział, że kanał leży.
Łapię wyjątek po to, żeby nie kłamać klientowi, a loguję go po to, żeby nie okłamywać samego
siebie, więc awaria kanału zostaje widoczna na poziomie `error` razem z odbiorcą i wyjątkiem.

## Spostrzeżenia poza zakresem zadania

Zamówienie tworzone przy zatwierdzaniu oferty dostaje w `createdBy` identyfikator osoby
zatwierdzającej, a nie autora oferty. Powiadomienie o zatwierdzeniu idzie potem do
`createdBy` tego zamówienia, czyli do osoby, która właśnie kliknęła "zatwierdź" i doskonale o tym
wie, natomiast autor oferty, dla którego ta wiadomość ma największą wartość, nie dostaje nic. Test
sprawdza tylko liczbę powiadomień, więc tego nie widzi.

Nie zmieniałem tego, bo to decyzja biznesowa, a nie błąd w kodzie. Nie wiem, czy "twórcą"
zamówienia ma być osoba zatwierdzająca (bo to jej działanie je wygenerowało), czy autor oferty (bo
to jego praca została zatwierdzona). Gdyby chodziło o drugi wariant, poprawka sprowadza się do
`setCreatedBy($document->getCreatedBy())` w handlerze albo do wysyłania powiadomień na podstawie
zatwierdzanej oferty zamiast utworzonego zamówienia. Zostawiam to do ustalenia z zespołem.
