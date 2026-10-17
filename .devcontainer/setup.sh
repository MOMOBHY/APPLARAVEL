#!/usr/bin/env bash
# Installation de la plateforme GFP dans un Codespace GitHub (une seule fois, à la création) :
# dépendances, fichier .env, base SQLite et comptes de démonstration.
set -euo pipefail
cd "$(dirname "$0")/.."

composer install --no-interaction --prefer-dist --no-progress

[ -f .env ] || cp .env.example .env

# Écrit (ou remplace) une variable du fichier .env.
set_env() {
  if grep -q "^$1=" .env; then
    sed -i "s|^$1=.*|$1=$2|" .env
  else
    echo "$1=$2" >> .env
  fi
}

set_env APP_NAME '"Plateforme GFP"'
set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_LOCALE fr
set_env APP_FALLBACK_LOCALE fr
if [ -n "${CODESPACE_NAME:-}" ]; then
  set_env APP_URL "https://${CODESPACE_NAME}-8000.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN}"
fi
set_env DB_CONNECTION sqlite
set_env QUEUE_CONNECTION sync
set_env MAIL_MAILER log

grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force

# Données de départ uniquement à la création de la base : un nouveau passage écraserait les
# modifications faites depuis l'espace administrateur.
if [ ! -f database/database.sqlite ]; then
  touch database/database.sqlite
  php artisan migrate --force --seed
else
  php artisan migrate --force
fi
