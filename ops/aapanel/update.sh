#!/usr/bin/env bash
# Install or update LinguaForge on an aaPanel server (one website). Run as root from anywhere, e.g.:
#   bash /www/wwwroot/lingura/ops/aapanel/update.sh
# The app folder is found from this script's location (two levels up).
# Override paths with environment variables if yours differ, e.g. PHP=/www/server/php/84/bin/php.
set -euo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
PHP="${PHP:-/www/server/php/83/bin/php}"
COMPOSER="${COMPOSER:-$(command -v composer || echo /usr/bin/composer)}"
WEB_USER="${WEB_USER:-www}"

cd "$APP_DIR"
echo "==> Pulling the latest code"
git pull --ff-only

echo "==> Back end"
cd "$APP_DIR/backend"
COMPOSER_ALLOW_SUPERUSER=1 "$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction
test -f .env || { echo "Missing backend/.env (copy .env.example and fill it in first)"; exit 1; }
grep -q '^APP_KEY=base64:' .env || "$PHP" artisan key:generate --force   # first install only
"$PHP" artisan migrate --force
"$PHP" artisan db:seed --force
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan event:cache

echo "==> Web app (NEXT_PUBLIC_API_URL comes from frontend/.env.production)"
cd "$APP_DIR/frontend"
test -f .env.production || { echo "Missing frontend/.env.production (NEXT_PUBLIC_API_URL=https://your-domain)"; exit 1; }
npm ci --no-audit --no-fund
npm run build
cp -r .next/static .next/standalone/.next/static
cp -r public .next/standalone/public

echo "==> Permissions"
chown -R "$WEB_USER:$WEB_USER" "$APP_DIR"
chmod -R ug+rwX "$APP_DIR/backend/storage" "$APP_DIR/backend/bootstrap/cache"

echo "==> Restarting background workers and the web app"
cd "$APP_DIR/backend"
if systemctl list-unit-files linguaforge-web.service >/dev/null 2>&1 && systemctl cat linguaforge-web >/dev/null 2>&1; then
  systemctl restart linguaforge-web linguaforge-scheduler
  "$PHP" artisan horizon:terminate || true   # finishes current jobs; systemd starts it again on the new code
else
  "$PHP" artisan horizon:terminate || true
  echo "Services not installed yet: run  WEB_PORT=3000 bash $APP_DIR/ops/aapanel/services.sh your-domain"
fi

echo "==> Audio for any new content (queued; needs Horizon and a TTS provider)"
"$PHP" artisan lf:content:synthesize-audio || true

echo "Done."
