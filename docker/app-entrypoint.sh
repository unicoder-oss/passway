#!/bin/sh
set -eu

mkdir -p /app/storage/logs

if [ "$#" -gt 0 ]; then
    exec "$@"
fi

php /app/install.php

cd /app/public
exec php -S 0.0.0.0:8000 index.php
