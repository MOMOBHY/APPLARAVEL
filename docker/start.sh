#!/bin/sh
# Démarrage du conteneur sur Render : clé d'application, cache de configuration, migrations, puis Apache.
set -e

# Render génère APP_KEY sous forme de chaîne aléatoire : on en dérive une clé AES-256 au format Laravel.
case "$APP_KEY" in
  base64:*) ;;
  *) APP_KEY="base64:$(php -r 'echo base64_encode(hash("sha256", getenv("APP_KEY"), true));')"; export APP_KEY ;;
esac

# Adresse publique du service, fournie par Render.
if [ -z "$APP_URL" ] && [ -n "$RENDER_EXTERNAL_URL" ]; then
  export APP_URL="$RENDER_EXTERNAL_URL"
fi

php artisan config:cache
php artisan migrate --force

exec apache2-foreground
