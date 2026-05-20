#!/bin/sh
set -eu

mkdir -p /app/storage/logs

php /app/install.php

exec php -S 0.0.0.0:8000 -t /app/public /app/public/index.php
