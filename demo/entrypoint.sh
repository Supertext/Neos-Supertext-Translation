#!/bin/sh
# First boot: database schema, content repository, Neos.Demo import, French + Italian.
# Every boot: demo accounts, resources, caches.
set -e

APP=/var/www/neos
DATA=/data
cd "$APP"

# mod_php needs the prefork MPM. On Railway a second MPM ends up enabled and
# Apache refuses to start ("More than one MPM loaded"), so keep only prefork.
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*
[ -e /etc/apache2/mods-enabled/mpm_prefork.load ] || a2enmod -q mpm_prefork

# Apache listens on Railway's $PORT (default 80).
PORT="${PORT:-80}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Uploaded assets live on the volume; the database holds everything else.
mkdir -p "$DATA/Persistent"
if [ ! -L "$APP/Data/Persistent" ]; then
  if [ -d "$APP/Data/Persistent" ] && [ -z "$(ls -A "$DATA/Persistent")" ]; then
    cp -a "$APP/Data/Persistent/." "$DATA/Persistent/" 2>/dev/null || true
  fi
  rm -rf "$APP/Data/Persistent"
fi
ln -sfn "$DATA/Persistent" "$APP/Data/Persistent"

echo "Waiting for the database at ${DB_HOST}:${DB_PORT:-3306}..."
i=0
until php -r 'new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: 3306).";dbname=".getenv("DB_NAME"), getenv("DB_USER"), getenv("DB_PASSWORD"));' 2>/dev/null; do
  i=$((i + 1))
  if [ "$i" -ge 60 ]; then echo "Database not reachable after 120 s." >&2; exit 1; fi
  sleep 2
done

./flow doctrine:migrate --quiet
./flow cr:setup

if ! ./flow site:list | grep -q neosdemo; then
  echo "First boot: importing Neos.Demo..."
  ./flow site:importall --package-key Neos.Demo
  # Extend the root node to the demo's added languages (French, Italian).
  ./flow nodemigration:execute 20261005150000 --force
  echo "Neos.Demo imported."
fi

./flow demo:ensureaccounts
./flow resource:publish
./flow flow:cache:warmup

chown -R www-data:www-data "$APP/Data" "$APP/Web/_Resources" "$DATA"
exec "$@"
