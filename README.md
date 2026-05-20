# Passway

Self-hosted secrets management service on custom PHP 8.1+ stack.

Source of truth for implementation progress lives in `.temp/project_passway_status.md`.

## Current Scope

- Custom Composer PHP app, no Laravel/Symfony
- Entry point: `public/index.php`
- Web UI: login, TOTP, dashboard, org management, directories, secrets, audit, API keys, integrations, passkeys
- API: `/api/v1/...`
- Databases: PostgreSQL and SQLite

## Requirements

- PHP 8.1+
- Composer
- Extensions:
  - `pdo`
  - `mbstring`
  - `json`
  - `sodium`
  - `pdo_sqlite` for SQLite dev/test
  - `pdo_pgsql` for PostgreSQL

## Local Quick Start

1. Install dependencies:
```bash
composer install
```

2. Create local config:
```bash
cp .env.example .env
```

3. Bootstrap config, storage, master key, and migrations:
```bash
php install.php
```

4. Start the app:
```bash
php -S 0.0.0.0:8000 -t public public/index.php
```

5. Open:
```text
http://localhost:8000/setup
```

6. Read the setup token from:
```text
storage/setup_token.txt
```

## Docker Quick Start

1. Build and start services:
```bash
docker compose up --build
```

2. Open:
```text
http://localhost:8000/setup
```

3. Read the setup token:
```bash
docker compose logs app
```

Or inside the volume-backed storage path:
```bash
docker compose exec app sh -lc 'cat /app/storage/setup_token.txt'
```

## Configuration Notes

- `.env.example` is tuned for local development by default
- local defaults use:
  - `DB_DRIVER=sqlite`
  - `APP_URL=http://localhost:8000`
  - `SESSION_COOKIE_SECURE=false`
- for Docker Compose, the bundled `docker-compose.yml` uses PostgreSQL and injects env directly into containers
- in production you should change at least:
  - `APP_ENV=production`
  - `APP_DEBUG=false`
  - `APP_URL=https://...`
  - `SESSION_COOKIE_SECURE=true`
  - `MASTER_KEY`
  - DB credentials
  - `WEBAUTHN_RP_ID` and `WEBAUTHN_ORIGIN`

## Install Script

`php install.php` is idempotent and currently does the following:

- creates `.env` from `.env.example` if missing
- generates `MASTER_KEY` if empty
- aligns WebAuthn defaults with `APP_URL` when example placeholders are still present
- creates storage/log directories
- runs pending migrations

It does not complete `/setup` automatically. Initial admin creation and deploy mode selection still happen through the setup page.

## Common Commands

Install deps:
```bash
composer install
```

Run all tests:
```bash
vendor/bin/phpunit
```

Run migrations:
```bash
composer migrate
```

Migration status:
```bash
composer migrate:status
```

Rollback last batch:
```bash
composer migrate:rollback
```

Fresh local reset:
```bash
php database/migrate.php fresh
```

Run rotation job:
```bash
composer rotate:run
```

Run maintenance cleanup:
```bash
composer maintain:cleanup
```

## Setup Flow

After first boot:

1. open `/setup`
2. submit the setup token
3. create the first admin account
4. choose deploy mode:
   - `team`
   - `solo`

The setup token is one-time. After setup completes it is invalidated and removed from the token file.

## Testing

- tests run against SQLite `:memory:` via `phpunit.xml`
- they do not depend on your local `.env`

Run:
```bash
vendor/bin/phpunit
```

## Known Notes

- `install.php` is now present; older comments and past planning notes that referenced a missing installer are stale
- setup token path is controlled by `SETUP_TOKEN_PATH`
- passkeys require `WEBAUTHN_RP_ID` and `WEBAUTHN_ORIGIN` to match the actual host/origin
- the built-in PHP server is fine for local development only
