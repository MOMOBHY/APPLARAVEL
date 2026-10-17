#!/usr/bin/env bash
# Lance la plateforme GFP sur le port 8000 et la mise à jour automatique depuis GitHub (au démarrage
# du Codespace et à chaque ouverture), sauf si elles tournent déjà.
cd "$(dirname "$0")/.."
mkdir -p storage/logs
# Réglages PHP du dépôt (taille des pièces jointes), en plus de ceux de l'image.
export PHP_INI_SCAN_DIR="${PHP_INI_SCAN_DIR:-}:$(pwd)/.devcontainer/php"
if ! pgrep -f .devcontainer/sync.sh > /dev/null; then
  setsid nohup bash .devcontainer/sync.sh >> storage/logs/sync.log 2>&1 < /dev/null &
fi
if curl -s -o /dev/null --max-time 3 http://localhost:8000/up; then
  exit 0
fi
PHP_CLI_SERVER_WORKERS=4 setsid nohup php artisan serve --host=0.0.0.0 --port=8000 --no-reload \
  > storage/logs/serveur.log 2>&1 < /dev/null &
for _ in $(seq 1 20); do
  curl -s -o /dev/null --max-time 3 http://localhost:8000/up && exit 0
  sleep 1
done
echo "Le serveur ne répond pas : voir storage/logs/serveur.log"
