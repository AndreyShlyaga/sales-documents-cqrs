# Setup

Step by step from a fresh clone to a green test suite.

## Requirements

- PHP 8.4 with `pdo_pgsql`
- Composer
- Docker

## 1. Install dependencies

```bash
composer install
```

## 2. Start the database

```bash
docker compose up -d database
```

PostgreSQL 16 listens on host port 5432. Credentials are in `compose.yaml`.

## 3. Create local env files

The committed `.env`, `.env.dev` and `.env.test` have `APP_SECRET`, `DEFAULT_URI` and
`DATABASE_URL` left empty on purpose. Copy them and fill in the values locally; the copies are
ignored by git.

```bash
cp .env .env.local
cp .env.test .env.test.local
```

In `.env.local` set `APP_SECRET` to any random 32-character hex string, `DEFAULT_URI` to
`http://localhost`, and `DATABASE_URL` to the PostgreSQL DSN for database `app` using the
credentials from `compose.yaml`.

In `.env.test.local` set `APP_SECRET` to any non-empty string and `DATABASE_URL` to the same DSN
but pointing at database `app_test`.

## 4. Run migrations on both databases

Dev and test are two separate databases in the same container. The test database has to be
created first.

```bash
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console --env=test doctrine:database:create --if-not-exists
php bin/console --env=test doctrine:migrations:migrate --no-interaction
```

## 5. Run the tests

```bash
php vendor/bin/phpunit
```

Expected: `OK (8 tests, 22 assertions)`. One `[error] Failed to notify user ...` line in the
output is expected; it is the logged notification failure from the test that simulates a channel
outage.

## 6. Run the application

```bash
php -S 127.0.0.1:8000 -t public public/index.php
```

Both endpoints accept `POST` only, so the root URL in a browser shows the default Symfony welcome
page.

Create a quote:

```bash
curl -s -X POST http://127.0.0.1:8000/sales-documents \
  -H 'Content-Type: application/json' \
  -d '{"contractor_id": 77, "created_by": 5}'
```

Approve it (use the `id` from the previous response):

```bash
curl -s -X POST http://127.0.0.1:8000/sales-documents/1/approve \
  -H 'Content-Type: application/json' \
  -d '{"approved_by": 9}'
```

Approving a missing document returns 404, approving an already approved one returns 409.
