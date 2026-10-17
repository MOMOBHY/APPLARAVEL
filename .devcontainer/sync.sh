#!/usr/bin/env bash
# Mise à jour automatique du Codespace : toutes les 30 secondes, récupère les nouveaux commits de la
# branche main sur GitHub, puis met à jour les dépendances et la base si besoin. Le serveur PHP relit
# les fichiers à chaque requête : les modifications sont en ligne sans redémarrage.
cd "$(dirname "$0")/.."
while true; do
  if git fetch -q origin main 2>/dev/null && [ "$(git rev-parse HEAD)" != "$(git rev-parse origin/main)" ]; then
    avant=$(git rev-parse HEAD)
    if git merge -q --ff-only origin/main; then
      git diff --quiet "$avant" HEAD -- composer.lock \
        || composer install --no-interaction --prefer-dist --no-progress
      php artisan migrate --force
      echo "$(date '+%F %T') mis à jour : $(git log -1 --format='%h %s')"
    else
      echo "$(date '+%F %T') mise à jour impossible : modifications locales dans le Codespace"
    fi
  fi
  sleep 30
done
