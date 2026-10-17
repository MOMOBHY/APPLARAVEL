#!/usr/bin/env bash
# Lance la plateforme GFP sur le port 8000, à chaque démarrage du Codespace.
cd "$(dirname "$0")/.."
mkdir -p storage/logs
PHP_CLI_SERVER_WORKERS=4 setsid nohup php artisan serve --host=0.0.0.0 --port=8000 --no-reload \
  > storage/logs/serveur.log 2>&1 < /dev/null &
