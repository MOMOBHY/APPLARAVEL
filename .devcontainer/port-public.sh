#!/usr/bin/env bash
# Rend le port 8000 public : le lien du Codespace s'ouvre alors sans compte GitHub.
[ -n "${CODESPACE_NAME:-}" ] || exit 0
lien="https://${CODESPACE_NAME}-8000.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN}"
for _ in $(seq 1 10); do
  if gh codespace ports visibility 8000:public -c "$CODESPACE_NAME" > /dev/null 2>&1; then
    echo "Plateforme GFP en ligne : $lien"
    exit 0
  fi
  sleep 3
done
echo "Port 8000 non rendu public automatiquement : onglet PORTS, clic droit sur 8000,"
echo "« Port Visibility » puis « Public ». Lien : $lien"
