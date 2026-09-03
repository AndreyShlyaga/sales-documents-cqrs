# sales-documents-cqrs

## Environment files

Secrets and connection strings are intentionally empty in the committed `.env`, `.env.dev` and
`.env.test`. Real values live in untracked `.env.local` / `.env.test.local`:

```bash
cp .env .env.local
cp .env.test .env.test.local
```

`.env.local`:

```dotenv
APP_SECRET=<any 32-char hex string>
DEFAULT_URI=http://localhost
DATABASE_URL="postgresql://app:app_secret@127.0.0.1:5432/app?serverVersion=16&charset=utf8"
```

`.env.test.local`:

```dotenv
APP_SECRET=<any non-empty string>
DATABASE_URL="postgresql://app:app_secret@127.0.0.1:5432/app_test?serverVersion=16&charset=utf8"
```

Credentials match `compose.yaml` (`app` / `app_secret`).
