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

mkdir -p "$RELEASE_DIR/var/cache"

# var/log est partagé entre releases (comme .env.local) : sinon prod.log
# serait recréé vide à chaque déploiement puisque RELEASE_DIR change à
# chaque fois.
mkdir -p "$SHARED_DIR/var/log"
ln -sfn "$SHARED_DIR/var/log" "$RELEASE_DIR/var/log"

composer install --no-dev --classmap-authoritative --no-interaction --prefer-dist --no-scripts --no-progress

echo "Permission management..."

# Deux utilisateurs écrivent dans var/cache et var/log à des moments
# différents : pikaichu_user pendant CE déploiement (composer, cache:clear,
# migrations), et www-data pendant les requêtes web servies entre deux
# déploiements (logs Monolog, régénération de cache à chaud). Sans ACL par
# défaut dans les DEUX sens, le déploiement suivant ne peut plus modifier ni
# supprimer les fichiers que www-data aura créés entre-temps — la ligne
# `-d` pour pikaichu_user est celle qui manquait et cause l'échec de
# cache:clear signalé (fichiers appartenant à www-data, sans ACL de retour).
# var/log étant un symlink vers SHARED_DIR/var/log, setfacl déréférence le
# lien et applique les ACL sur le vrai dossier partagé.
setfacl -R  -m u:www-data:rwX      "$RELEASE_DIR/var/cache" "$RELEASE_DIR/var/log"
setfacl -dR -m u:www-data:rwX      "$RELEASE_DIR/var/cache" "$RELEASE_DIR/var/log"
setfacl -R  -m u:pikaichu_user:rwX "$RELEASE_DIR/var/cache" "$RELEASE_DIR/var/log"
setfacl -dR -m u:pikaichu_user:rwX "$RELEASE_DIR/var/cache" "$RELEASE_DIR/var/log"

echo "✅ ACL bidirectionnelles configurées (www-data ⇄ pikaichu_user) sur var/cache et var/log"

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
