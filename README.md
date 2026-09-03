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





## Problem 2. Kontroler odpowiada 500 na każdy błąd i sam odpytuje bazę

### Przyczyna

W `approve()` cały `dispatch()` był owinięty w `catch (\Throwable)`, który każdy wyjątek zamieniał
na 500 z `$e->getMessage()` w treści. Kontroler nie mógłby rozróżnić błędów nawet gdyby chciał, bo
handler rzucał ten sam `\RuntimeException` zarówno dla nieistniejącego dokumentu, jak i dla
dokumentu w złym statusie. Do tego po zatwierdzeniu kontroler czytał wynik surowym `SELECT` przez
`getConnection()`, mimo że `SalesDocumentRepository` z metodą `find()` już istniał.

### Skutek

Klient dostawał 500 tam, gdzie sam podał zły identyfikator, i nie miał jak odróżnić własnej
pomyłki od awarii serwera. W treści odpowiedzi wychodziła na zewnątrz pełna nazwa klasy komendy
razem z wewnętrznym komunikatem, czyli szczegóły implementacji, na których klient i tak nie może
polegać, bo zmieniają się przy każdej refaktoryzacji. Surowy SQL w kontrolerze wiązał warstwę HTTP
z nazwami tabeli i kolumn, więc zmiana schematu psułaby kontroler.

### Jak należało to rozwiązać

Różne sytuacje muszą być różnymi typami wyjątków, bo tylko typ da się bezpiecznie sprawdzić.
Rozróżnianie po treści komunikatu nie wchodzi w grę, bo komunikat nie jest kontraktem. Kontroler
ma wysłać komendę i odczytać wynik przez repozytorium, a tłumaczenie błędów domenowych na kody HTTP
ma być w jednym miejscu dla wszystkich endpointów, a nie kopiowane do każdej akcji.

### Jak zostało rozwiązane

Powstały wyjątki domenowe w `src/Exception/`. `SalesDocumentNotFound` odpowiada za brak dokumentu,
`SalesDocumentStatusConflict` za operację niedozwoloną w bieżącym statusie, obie dziedziczą po
abstrakcyjnym `SalesDocumentException`, który wymaga metody `errorCode()`. Bazą jest
`\RuntimeException`, bo test dla `RejectSalesDocument` oczekuje właśnie tego typu, a podklasa ten
warunek spełnia. Handler rzuca teraz te wyjątki zamiast gołego `\RuntimeException`.

Tłumaczenie na HTTP robi `DomainExceptionSubscriber` nasłuchujący na `kernel.exception`. Najpierw
rozpakowuje `HandlerFailedException`, w który Messenger owija każdy wyjątek z handlera, bo bez tego
`instanceof` nigdy by nie zadziałał. Potem sprawdza czy w środku jest `SalesDocumentException` i
jeśli tak, ustawia odpowiedź JSON z polem `error` zawierającym stały kod dla klienta i polem
`message` z tekstem napisanym przez nas. Każdy inny wyjątek przepuszcza dalej, więc nieoczekiwany
błąd nadal kończy się jako 500, bo tym właśnie jest.

Wybrałem subskrybenta zamiast `try/catch` w kontrolerze, bo tłumaczenie błędów jest potrzebne
każdemu endpointowi, który wysyła komendę, i nie chciałem powielać go w każdej akcji razem z
rozpakowywaniem `HandlerFailedException`. Zadanie mówi, że kontroler ma mapować błędy, a
jednocześnie daje swobodę w organizacji plików, więc mapowanie zostało w warstwie HTTP, tylko w
osobnym pliku. Sam kontroler wysyła komendę, czyta wynik przez `find()` z repozytorium i zwraca
odpowiedź, bez `try`, bez SQL i bez wiedzy o kodach błędów.

Dla braku dokumentu wybrałem 404, a dla złego statusu 409, a nie 422. Kod 422 oznacza żądanie
poprawne składniowo, ale bezsensowne semantycznie, na przykład ujemny identyfikator. Tutaj
żądanie jest w porządku, tylko zasób zdążył zmienić stan, a to jest właśnie konflikt.

Test `testApprovingMissingDocumentCurrentlyReturns500` zmienił nazwę na
`testApprovingMissingDocumentReturns404` i sprawdza status oraz kod `error`. Dopisałem też
`testApprovingAnAlreadyApprovedDocumentReturns409`, bo bez niego druga połowa wymagania nie miałaby
żadnego dowodu.

Kontroler nadal woła repozytorium bezpośrednio, bo zadanie o to prosi. Gdybym miał wolną rękę,
wstawiłbym między nie serwis odczytowy i tylko on znałby warstwę trwałości, a kontroler zajmowałby
się wyłącznie przyjęciem żądania i zwróceniem odpowiedzi.




## Problem 3. Zamienione dane właściciela w części nowych dokumentów

### Jakim tropem poszedłem

Zgłoszenie supportu nie wskazywało miejsca w kodzie, ale zawierało trzy podpowiedzi i każda z
nich zawężała poszukiwania. Nie sięgałem po Xdebug, bo sam tekst zgłoszenia doprowadził mnie do
przyczyny w trzech krokach czytania kodu.

Pierwsza podpowiedź to "nowo utworzonych dokumentów". Skoro chodzi o nowe dokumenty, problem leży
na ścieżce tworzenia, a nie zatwierdzania. Dokumenty tworzy wyłącznie `CreateSalesDocumentHandler`.
Sprawdziłem go i okazał się czysty, bo bierze `contractorId` i `createdBy` z komendy i zapisuje je
bez żadnej zamiany. Skoro handler jest czysty, a dane lądują zamienione, to muszą być zamienione
jeszcze zanim komenda do niego trafi.

Druga podpowiedź to "nie za każdym razem". Skoro nie zawsze, to muszą istnieć co najmniej dwie
ścieżki tworzenia i tylko jedna z nich jest zepsuta. Poszukałem, kto buduje `CreateSalesDocument`.
Są dwa miejsca. Testy budują komendę samodzielnie i wysyłają ją prosto do szyny, a
`SalesDocumentController::create()` buduje ją z ciała żądania HTTP.

Trzecia podpowiedź to "nie widać tego w żadnym z testów". Teraz było jasne dlaczego. Testy
handlerów omijają kontroler, więc idą zdrową ścieżką. Jedyny test przez HTTP sprawdzał tylko
`type` i `parent_quote_id`, właściciela nie dotykał. Zepsuta ścieżka to więc kontroler.

W `create()` dane z żądania nie szły prosto do komendy, tylko przez prywatną metodę
`resolveDocumentOwnership()`, która zwracała `contractorId` z klucza `created_by` i `createdBy` z
klucza `contractor_id`. Klucze były zamienione miejscami. Nazwa metody sugerowała jakąś logikę
ustalania właściciela, a w środku było tylko przełożenie dwóch wartości, i właśnie ta nazwa
pozwoliła błędowi przejść przez przegląd kodu. Oba pola są typu `int`, więc typy też niczego nie
wychwyciły.

### Jak to potwierdziłem

Hipotezę sprawdziłem żądaniem, a nie tylko czytaniem kodu. Wysłałem przez `curl` dokument z
`contractor_id` równym 10 i `created_by` równym 77, a potem odczytałem wiersz z bazy. W tabeli
`contractor_id` miał wartość 77, a `created_by` wartość 10, czyli dokładnie odwrotnie niż w
żądaniu.

Zanim cokolwiek poprawiłem, napisałem test
`testCreatingThroughHttpStoresContractorAndCreatorAsSent`. Tworzy dokument przez HTTP z różnymi
wartościami obu pól, odczytuje encję przez repozytorium i sprawdza każde pole osobno. Wartości
muszą być różne, bo przy identycznych zamiana byłaby niewidoczna. Test uruchomiony na niezmienionym
kodzie upadł z komunikatem, że oczekiwano 77, a otrzymano 5.

### Jak zostało rozwiązane

Usunąłem `resolveDocumentOwnership()` w całości i przekazuję pola z żądania prosto do komendy, bo
metoda nie miała żadnej logiki poza zamianą kluczy i nie było czego w niej poprawiać. Po tej
zmianie test przeszedł, a ten sam `curl` co wcześniej zapisał w bazie `contractor_id` równe 10 i
`created_by` równe 77, czyli tak jak w żądaniu. Cały zestaw testów pozostał zielony poza dwoma
testami dla `RejectSalesDocument`, którego jeszcze nie było.

Xdebug byłby potrzebny, gdyby wartość ulegała zniekształceniu tam, gdzie nie ma jawnego kodu, na
przykład w listenerze Doctrine albo w mapowaniu. Tutaj zgłoszenie doprowadziło do jawnego kodu w
kontrolerze, więc debugger nie był potrzebny. Gdyby kontroler okazał się czysty, kolejnym krokiem
byłoby właśnie prześledzenie w debuggerze drogi od ciała żądania przez komendę i encję do zapisu.




## Problem 4. Nowa operacja RejectSalesDocument

### Co wynika z testu

Jedynym źródłem wymagań był `RejectSalesDocumentHandlerTest`, więc najpierw wyciągnąłem z niego
kontrakt. Komenda `RejectSalesDocument` leży w `App\Message\Command` i przyjmuje nazwane argumenty
`documentId` oraz `rejectedBy`, bo tak buduje ją test i nazw parametrów nie da się zmienić.
W enumie `SalesDocumentStatus` musi istnieć przypadek `Rejected`, bo test porównuje z nim status
po operacji. Odrzucenie dokumentu, który nie jest szkicem, ma rzucić `\RuntimeException` albo jego
podklasę. Handler może nie zwracać niczego, bo test czyta wynik przez `?->getResult()`, w
odróżnieniu od testu zatwierdzania, który używa zwykłego `->getResult()`.

### Jak zostało zrobione

Komenda jest lustrzanym odbiciem `ApproveSalesDocument`, czyli klasą `final` z dwoma polami
`public readonly` i bez logiki. Do enuma doszedł przypadek `Rejected` o wartości `rejected`.
Kolumna `status` jest typu `VARCHAR`, więc nowy przypadek nie wymaga migracji, co potwierdziłem
przez `doctrine:schema:validate`.

`RejectSalesDocumentHandler` jest zarejestrowany na `command.bus` tak samo jak pozostałe handlery.
Szuka dokumentu przez repozytorium, dla brakującego rzuca `SalesDocumentNotFound`, dla dokumentu w
statusie innym niż `draft` rzuca `SalesDocumentStatusConflict::cannotReject`, a dla szkicu ustawia
status `Rejected` i zapisuje. Oba wyjątki dziedziczą po `\RuntimeException`, więc test oczekujący
tego typu przechodzi bez żadnej specjalnej obsługi. Dzięki temu samemu subskrybentowi co przy
zatwierdzaniu odrzucenie przez HTTP odpowiadałoby 404 i 409 bez dodatkowego kodu, gdyby endpoint
kiedyś powstał.

Handler zwraca `void`, bo odrzucenie niczego nie tworzy i nie ma identyfikatora do oddania, a
test wprost dopuszcza brak wyniku. Nie używa też `wrapInTransaction`, bo zatwierdzanie potrzebuje
transakcji do zapisania dwóch encji naraz, a odrzucenie zmienia jeden wiersz i pojedynczy `flush()`
jest już w Doctrine atomowy.

### Rozważany wariant, z którego świadomie zrezygnowałem

Zatwierdzanie zapisuje kto i kiedy zatwierdził, w polach `approvedBy` i `approvedAt`. Operacja w
tym samym stylu powinna zapisywać `rejectedBy` i `rejectedAt`, tym bardziej że komenda już
przyjmuje `rejectedBy`. Rozważałem dodanie tych dwóch pól do encji razem z migracją i uważam, że
w prawdziwym projekcie tak bym to zrobił, bo komenda, która przyjmuje parametr i go wyrzuca,
wygląda na niedokończoną.

Zostałem jednak przy samym statusie, bo zadanie mówi wprost, żeby zaprojektować kod na podstawie
samego testu, a test sprawdza wyłącznie status. Dwie kolumny i migracja pod coś, czego żaden test
nie weryfikuje, byłyby wyjściem poza wymagania. W handlerze zostawiłem komentarz, że `rejectedBy`
jest przyjmowane, ale nie zapisywane, żeby nikt nie wziął tego za przeoczenie. Dodanie tych pól to
jedna migracja i dwie linie w handlerze, gdyby zespół uznał, że są potrzebne.

Z tego samego powodu nie ma endpointu HTTP dla odrzucania ani powiadomień po odrzuceniu. Test jest
testem handlera, a nie kontrolera, i nie mówi nic o efektach ubocznych.





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
