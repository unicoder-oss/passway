# Passway [WIP]

Языки: [English](README.md) | Русский

Passway — это веб-сервис для управления секретами для PHP 8.1+ с поддержкой PostgreSQL и SQLite

## Возможности
- Секреты, директории, организации, журнал доступа, API, динамические секреты с поддержкой роотации через внешние сервисы
- Полноценный веб-интерефейс для первичной настройки, авторизации и управления секретами
- Однопользовательский и командный режимы развёртывания для различных сценариев использования
- Поддержка английской и русской локализации (принимаются PR на добавление других языков!)

## Требования
- PHP 8.1+
- Composer
- PHP-расширения: `pdo`, `mbstring`, `json`, `sodium`
- Расширение базы данных: `pdo_pgsql` для PostgreSQL или `pdo_sqlite` для SQLite

## Быстрый локальный запуск
1. Установите зависимости:
```bash
composer install
```
2. Создайте файл конфигурации и измените значения под себя:
```bash
cp .env.example .env
```
3. Прогоните установочный скрипт:
```bash
php install.php
```
4. Запустите приложение:
```bash
php -S 0.0.0.0:8000 -t public public/index.php
```
5. Откройте в браузере:
```text
http://localhost:8000/setup
```
Токен начальной настройки можно найти в логах приложения или файле, указанном в переменной `SETUP_TOKEN_PATH`

## Быстрый запуск через Docker Compose
1. Запустите сервисы:
```bash
docker compose up
```
2. Откройте в браузере:
```text
http://localhost:8000/setup
```
3. Скопируйте из лога Docker Compose токен для первоначальной настройки, нажмите `d` для отвязывания от процесса

> [!NOTE]
> Плановая ротация секретов, очистка журналов аудита и перманентная очистка удалённых организаций требуют периодического фонового выполнения. Пример `docker-compose.yml` запускает для этого отдельные сервисы: `scheduler-rotate` запускает `php bin/rotate.php` каждые 30 секунд, `scheduler-maintenance` запускает `php bin/maintenance.php cleanup` каждый день и `scheduler-organization-purge` запускает `php bin/maintenance.php purge-organizations` один раз в день. Если вы запускаете Passway напрямую в системе, настройте cron или systemd-таймеры вручную.

## Конфигурация

| Переменная | Описание | Возможные значения |
| --- | --- | --- |
| `APP_NAME` | Имя приложения в UI и эмитент для TOTP | Любая строка (например, `Passway`) |
| `APP_ENV` | Окружение приложения | `production` или любая другая строка  |
| `APP_URL` | Публичный базовый URL | Примеры: `http://localhost:8000`, `https://passway.example.com` |
| `APP_DEBUG` | Включить подробный отладочный вывод | `true`, `false` |
| `APP_LOCALE` | Язык веб-интерфейса, если браузер передал неподдерживаемый язык | `en`, `ru` |
| `APP_TIMEZONE` | Временная зона приложения | `UTC`, `Europe/Moscow` |
| `APP_BEHIND_PROXY` | Доверять заголовкам обратного прокси с IP клиента. Включайте только за доверенным прокси | `true`, `false` |
| `DB_DRIVER` | Драйвер базы данных | `pgsql`, `sqlite` |
| `DB_HOST` | Хост PostgreSQL | `127.0.0.1`, `db` |
| `DB_PORT` | Порт PostgreSQL | `5432` |
| `DB_NAME` | Имя базы PostgreSQL | `passway` |
| `DB_USER` | Пользователь PostgreSQL | `passway` |
| `DB_PASS` | Пароль PostgreSQL | Любая строка с паролем |
| `DB_SSLMODE` | Режим SSL для PostgreSQL | `disable`, `require`, `verify-full` |
| `DB_SQLITE_PATH` | Путь к базе SQLite | `storage/passway.db`, `:memory:` |
| `MASTER_KEY` | 32-битный мастер-ключ для шифрования | Команда для генерации значения: `php -r "echo bin2hex(random_bytes(32));"` |
| `SESSION_TTL` | Время жизни сессии в секундах | `86400` |
| `SESSION_COOKIE_NAME` | Имя куков HTTP-сессии | `passway_session` |
| `SESSION_COOKIE_SECURE` | Отправлять куки сессии только по HTTPS | `true`, `false`cookie |
| `SESSION_COOKIE_SAMESITE` | Правила SameSite для куков сессии | `Strict`, `Lax`, `None` |
| `DEPLOY_MODE` | Режим развертывания, выбранный при первоначальной настройке. Оставьте пустым до её выполнения | `team`, `solo`, пусто |
| `RATE_LIMIT_API` | Лимит API-запросов в минуту | `100` |
| `RATE_LIMIT_AUTH` | Лимит запросов к эндпоинту аутентификации в минуту | `20` |
| `WEBAUTHN_RP_ID` | Идентификатор проверяющей стороны WebAuthn. Должен совпадать с реальным доменом | `localhost`, `passway.example.com` |
| `WEBAUTHN_RP_NAME` | Отображаемое имя проверяющей стороны WebAuthn | `Passway` |
| `WEBAUTHN_ORIGIN` | Источник WebAuthn. Должен совпадать с адресом в браузере | `http://localhost:8000`, `https://passway.example.com` |
| `LOG_CHANNEL` | Канал для логов | `file`, `stderr` |
| `LOG_LEVEL` | Минимальный уровень логирования | `debug`, `info`, `warning`, `error` |
| `LOG_PATH` | Путь к файлу с логами при `LOG_CHANNEL=file` | `storage/logs/passway.log` |
| `LOG_RETENTION_DAYS` | Окно хранения журнала аудита в днях | `90` |
| `ORG_DELETED_PURGE_DAYS` | Период хранения перед окончательным физическим удалением обратимо удалённых организаций, а также их секретов и каталогов. | `30` |
| `SCHEDULER_SECRET` | Случайный токен для защиты эндпоинтов расписаний | Случайная строка с высокой энтропией |
| `SETUP_TOKEN_PATH` | Путь до файла с токеном первоначальной настройки | `storage/setup_token.txt` |

## Настройка на публичном сервере
Разверните Passway за обратным прокси, например nginx, Caddy или Traefik. TLS терминируется на стороне прокси, а HTTP-трафик проксируется в веб-приложение

### Рекомендуемые настройки для развёртывания
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

### Пример конфигурации nginx с терминацией TLS

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
> Устанавливайте `APP_BEHIND_PROXY=true`, только когда эти заголовки приходят от доверенного прокси и не могут быть напрямую заданы клиентом

## Первоначальная настройка

После первого запуска:
1. Откройте `/setup`
2. Укажите токен первоначальной настройки
3. Создайте аккаунт первого администратора
4. Выберите командный или однопользовательский режим развёртывания
## Частые команды
#### Установить зависимости
```bash
composer install
```
#### Прогнать все тесты
```bash
vendor/bin/phpunit
```
#### Запустить миграции
```bash
composer migrate
```
#### Статус миграций
```bash
composer migrate:status
```
#### Откатить последний batch
```bash
composer migrate:rollback
```
#### Сброс БД (только для локального тестирования!)
```bash
php database/migrate.php fresh
```
#### Запустить ротацию
```bash
composer rotate:run
```
#### Запустить очистку старых записей в журнале
```bash
composer maintain:cleanup
```
## Тестирование
Тесты запускаются на SQLite `:memory:` через `phpunit.xml.dist`. Локальный `phpunit.xml` можно использовать для переопределений; он намеренно игнорируется git.
```bash
vendor/bin/phpunit
```

## Полезное
- [Демонстрационный сервис ротации SSH-ключей](https://github.com/unicoder-oss/passway-demo-ssh-rotation-service)