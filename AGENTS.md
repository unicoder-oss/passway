# Passway

## Entry Points
- This is a custom Composer PHP app, not Laravel/Symfony. HTTP goes through `public/index.php` into `app/Core/Application.php`.
- Route wiring lives in `routes/web.php` and `routes/api.php`. Most implemented product behavior is in the API/service layer; `/` in `routes/web.php` is still a placeholder page.
- DI bindings for controllers, services, and middleware are centralized in `Application::registerCoreBindings()`. Check that file first before adding new wiring.

## Commands
- Install deps: `composer install`
- Run all tests: `vendor/bin/phpunit`
- Run one test file: `vendor/bin/phpunit tests/Services/SecretServiceTest.php`
- Run one test method: `vendor/bin/phpunit --filter testMethodName`
- Migration status: `composer migrate:status`
- Apply migrations: `composer migrate`
- Roll back last batch: `composer migrate:rollback`
- Dev-only reset and reapply all migrations: `php database/migrate.php fresh`

## Migrations And DB
- Migration files are ordered by numeric prefix in `database/migrations/` and are classmapped from `composer.json`; keep the `NNN_ClassName.php` pattern.
- `php database/migrate.php reset` is interactive (`readline()` asks for `yes`), so prefer `fresh` for unattended local rebuilds.
- The app supports PostgreSQL and SQLite. Tests use SQLite `:memory:` via `phpunit.xml`.

## Tests
- DB-backed tests should extend `tests/DatabaseTestCase`; it rebuilds schema once per class with `MigrationRunner` and truncates tables between tests.
- `phpunit.xml` injects the test env (`APP_ENV=testing`, `DB_DRIVER=sqlite`, `DB_SQLITE_PATH=:memory:`, fixed `MASTER_KEY`), so do not depend on the developer's `.env` in tests.
- Some test classes require `pdo_sqlite` and/or `sodium` explicitly; verify extensions before assuming a failure is in app code.

## Config Quirks
- `Config` requires `APP_ENV`, `APP_URL`, and `DB_DRIVER`. `.env` can be absent only if those are supplied by the OS environment.
- WebAuthn/passkey work depends on `WEBAUTHN_RP_ID` and `WEBAUTHN_ORIGIN` matching the real host/origin.
- Session cookie security is env-driven in `SessionService`; local HTTP work may need `SESSION_COOKIE_SECURE=false`.

## Known Stale References
- `composer.json`, `.env.example`, and some comments still mention `install.php`, but that file is not in the repo. Do not assume an installer exists; use `.env`, `composer install`, and migration commands directly.
