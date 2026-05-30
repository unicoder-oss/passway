# Passway [WIP]

Languages: English | [Русский](README.ru.md)

Passway is a web service for secrets management for PHP 8.1+ with PostgreSQL and SQLite support.

## Features

- Secrets, directories, organizations, access log, API, and dynamic secrets with rotation support through external services.
- Full-featured web interface for initial setup, authentication, and secrets management.
- Single-user and team deployment modes for different usage scenarios.
- English and Russian localization support. PRs for adding other languages are welcome.

## Requirements

- PHP 8.1+
- Composer
- PHP extensions: `pdo`, `mbstring`, `json`, `sodium`
- Database extension: `pdo_pgsql` for PostgreSQL or `pdo_sqlite` for SQLite

## Local Quick Start

1. Install dependencies:

```bash
composer install
```

2. Create the configuration file and adjust values for your environment:

```bash
cp .env.example .env
```

3. Run the installation script:

```bash
php install.php
```

4. Start the application:

```bash
php -S 0.0.0.0:8000 -t public public/index.php
```

5. Open in your browser:

```text
http://localhost:8000/setup
```

The initial setup token can be found in the application logs or in the file specified by `SETUP_TOKEN_PATH`.

## Docker Compose Quick Start
1. Start services:
```bash
docker compose up
```
2. Open in your browser:

```text
http://localhost:8000/setup
```
3. Copy the initial setup token from the Docker Compose log, then press `d` to detach from the process

> [!NOTE]
> Scheduled secret rotation and audit log cleanup require periodic background runs. The example `docker-compose.yml` starts separate scheduler services for this: `scheduler-rotate` runs `php bin/rotate.php` every 30 seconds, and `scheduler-maintenance` runs `php bin/maintenance.php cleanup` once per day. If you run Passway directly on a host, configure cron or systemd timers manually.

## Configuration

| Variable | Description | Allowed Values |
| --- | --- | --- |
| `APP_NAME` | Application name in the UI and TOTP issuer. | Any string, for example `Passway` |
| `APP_ENV` | Application environment. | `production` or any other string |
| `APP_URL` | Public base URL. | Examples: `http://localhost:8000`, `https://passway.example.com` |
| `APP_DEBUG` | Enables detailed debug output. | `true`, `false` |
| `APP_LOCALE` | Web interface language used when the browser sends an unsupported language. | `en`, `ru` |
| `APP_TIMEZONE` | Application timezone. | `UTC`, `Europe/Moscow` |
| `APP_BEHIND_PROXY` | Trust reverse proxy headers with the client IP. Enable only behind a trusted proxy. | `true`, `false` |
| `DB_DRIVER` | Database driver. | `pgsql`, `sqlite` |
| `DB_HOST` | PostgreSQL host. | `127.0.0.1`, `db` |
| `DB_PORT` | PostgreSQL port. | `5432` |
| `DB_NAME` | PostgreSQL database name. | `passway` |
| `DB_USER` | PostgreSQL user. | `passway` |
| `DB_PASS` | PostgreSQL password. | Any password string |
| `DB_SSLMODE` | PostgreSQL SSL mode. | `disable`, `require`, `verify-full` |
| `DB_SQLITE_PATH` | SQLite database path. | `storage/passway.db`, `:memory:` |
| `MASTER_KEY` | 32-byte encryption master key. | Generate with: `php -r "echo bin2hex(random_bytes(32));"` |
| `SESSION_TTL` | Session lifetime in seconds. | `86400` |
| `SESSION_COOKIE_NAME` | HTTP session cookie name. | `passway_session` |
| `SESSION_COOKIE_SECURE` | Send session cookies only over HTTPS. | `true`, `false` |
| `SESSION_COOKIE_SAMESITE` | SameSite policy for session cookies. | `Strict`, `Lax`, `None` |
| `DEPLOY_MODE` | Deployment mode selected during initial setup. Leave empty before setup. | `team`, `solo`, empty |
| `RATE_LIMIT_API` | API request limit per minute. | `100` |
| `RATE_LIMIT_AUTH` | Authentication endpoint request limit per minute. | `20` |
| `WEBAUTHN_RP_ID` | WebAuthn relying party identifier. Must match the real domain. | `localhost`, `passway.example.com` |
| `WEBAUTHN_RP_NAME` | WebAuthn relying party display name. | `Passway` |
| `WEBAUTHN_ORIGIN` | WebAuthn origin. Must match the address in the browser. | `http://localhost:8000`, `https://passway.example.com` |
| `LOG_CHANNEL` | Log channel. | `file`, `stderr` |
| `LOG_LEVEL` | Minimum logging level. | `debug`, `info`, `warning`, `error` |
| `LOG_PATH` | Path to the log file when `LOG_CHANNEL=file`. | `storage/logs/passway.log` |
| `LOG_RETENTION_DAYS` | Audit log retention window. | `90` |
| `SCHEDULER_SECRET` | Random token for protecting scheduler endpoints. | Random high-entropy string |
| `SETUP_TOKEN_PATH` | Path to the initial setup token file. | `storage/setup_token.txt` |

## Public Server Setup

Deploy Passway behind a reverse proxy such as nginx, Caddy, or Traefik. TLS is terminated by the proxy, and HTTP traffic is proxied to the web application.

### Recommended Deployment Settings

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://passway.example.com
APP_BEHIND_PROXY=true
SESSION_COOKIE_SECURE=true
DB_DRIVER=pgsql
WEBAUTHN_RP_ID=passway.example.com
WEBAUTHN_ORIGIN=https://passway.example.com
```

### Nginx Configuration Example With TLS Termination

```nginx
server {
    listen 443 ssl http2;
    server_name passway.example.com;

    ssl_certificate /etc/letsencrypt/live/passway.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/passway.example.com/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
    }
}

server {
    listen 80;
    server_name passway.example.com;
    return 301 https://$host$request_uri;
}
```

> [!IMPORTANT]
> Set `APP_BEHIND_PROXY=true` only when these headers come from a trusted proxy and cannot be set directly by clients.

## Initial Setup Flow

After the first deployment:

1. Open `/setup`.
2. Enter the initial setup token.
3. Create the first administrator account.
4. Choose team or single-user deployment mode.

## Common Commands

#### Install Dependencies

```bash
composer install
```

#### Run All Tests

```bash
vendor/bin/phpunit
```

#### Run Migrations

```bash
composer migrate
```

#### Migration Status

```bash
composer migrate:status
```

#### Roll Back Last Batch

```bash
composer migrate:rollback
```

#### Reset Database (local testing only)

```bash
php database/migrate.php fresh
```

#### Run Rotation

```bash
composer rotate:run
```

#### Clean Up Old Log Entries

```bash
composer maintain:cleanup
```

## Testing

Tests run against SQLite `:memory:` using `phpunit.xml.dist`. A local `phpunit.xml` may be used for overrides and is intentionally ignored by git.
```bash
vendor/bin/phpunit
```
