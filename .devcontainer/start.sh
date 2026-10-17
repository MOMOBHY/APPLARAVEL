#!/usr/bin/env bash
# Lance la plateforme GFP sur le port 8000 (au démarrage du Codespace et à chaque ouverture),
# sauf si elle répond déjà.
cd "$(dirname "$0")/.."
if curl -s -o /dev/null --max-time 3 http://localhost:8000/up; then
  exit 0
fi
mkdir -p storage/logs
PHP_CLI_SERVER_WORKERS=4 setsid nohup php artisan serve --host=0.0.0.0 --port=8000 --no-reload \
  > storage/logs/serveur.log 2>&1 < /dev/null &
for _ in $(seq 1 20); do
  curl -s -o /dev/null --max-time 3 http://localhost:8000/up && exit 0
  sleep 1
done
echo "Le serveur ne répond pas : voir storage/logs/serveur.log"
