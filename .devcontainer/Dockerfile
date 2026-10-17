# Image du Codespace : PHP 8.4. La source apt de Yarn livrée avec l'image a une clé GPG expirée qui
# fait échouer apt-get (et donc l'installation du serveur SSH) : on la retire, Yarn n'est pas utilisé.
FROM mcr.microsoft.com/devcontainers/php:1-8.4-bookworm
RUN rm -f /etc/apt/sources.list.d/yarn*.list
