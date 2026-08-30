#!/bin/bash
set -euo pipefail

# Variables
BASE_DIR="/var/www/pikaichu-symfony"
RELEASES_DIR="$BASE_DIR/releases"
PROD_LINK="$BASE_DIR/production"
SHARED_DIR="$BASE_DIR/shared"

# On récupère le hash du commit depuis l'argument
if [ $# -lt 1 ]; then
  echo "Usage: $0 <commit_hash>"
  exit 1
fi
COMMIT_HASH=$1
RELEASE_DIR="$RELEASES_DIR/$COMMIT_HASH"

echo "🚀 Déploiement du commit $COMMIT_HASH"

mkdir -p "$RELEASE_DIR"
cd "$RELEASE_DIR"

if [ ! -d ".git" ]; then
  git clone --branch main git@github.com:CNKyudo/pikaichu-symfony.git .
else
  git fetch origin main
  git reset --hard "origin/main"
fi

if [ -f "$SHARED_DIR/.env.local" ]; then
  cp "$SHARED_DIR/.env.local" "$RELEASE_DIR/.env.local"
  echo "✅ Copie du .env.local depuis $SHARED_DIR"
else
  echo "⚠️ Aucun .env.local trouvé dans $SHARED_DIR, pensez à le créer !"
fi

mkdir -p "$RELEASE_DIR/var/cache" "$RELEASE_DIR/var/log"

composer install --no-dev --classmap-authoritative --no-interaction --prefer-dist --no-scripts --no-progress

echo "Permission management..."

# Donner les droits de lecture/écriture/exécution (rwX) à www-data
setfacl -R -m u:www-data:rwX "$RELEASE_DIR/var/cache" "$RELEASE_DIR/var/log"
# Définir les ACL par défaut
setfacl -dR -m u:www-data:rwX "$RELEASE_DIR/var/cache" "$RELEASE_DIR/var/log"

echo "✅ ACL configurées pour www-data sur var/cache et var/log"

php bin/console cache:clear --env=prod
php bin/console importmap:install --env=prod
php bin/console asset-map:compile --env=prod --quiet --no-interaction
php bin/console assets:install public --no-interaction --env=prod --quiet
php bin/console doctrine:migrations:migrate --no-interaction --env=prod

ln -sfn "$RELEASE_DIR" "$PROD_LINK"
echo "✅ Lien symbolique mis à jour -> $PROD_LINK"

# Nettoyer les anciennes releases (garder 5)
ls -dt "$RELEASES_DIR"/* | tail -n +6 | xargs rm -rf || true
echo "🧹 Anciennes releases nettoyées (garde les 5 dernières)."

echo "🎉 Déploiement terminé avec succès !"
