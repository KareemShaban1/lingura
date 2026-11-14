#!/usr/bin/env bash
# Diagnose a LinguaForge install on aaPanel. Read-only: it changes nothing. Run as root:
#   bash /www/wwwroot/lingura/ops/aapanel/doctor.sh lingura.example.com
# Paste the output when asking for help (it prints no passwords or keys).
set -uo pipefail

DOMAIN="${1:-}"
APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
PHP="${PHP:-/www/server/php/83/bin/php}"
NGINX="${NGINX:-$(command -v nginx || echo /www/server/nginx/sbin/nginx)}"
ok()   { printf '  \033[32mOK\033[0m    %s\n' "$*"; }
bad()  { printf '  \033[31mFAIL\033[0m  %s\n' "$*"; }
info() { printf '        %s\n' "$*"; }

[ -n "$DOMAIN" ] || { echo "Usage: bash $0 your-domain"; exit 1; }
echo "LinguaForge check for $DOMAIN (app folder: $APP_DIR)"

echo "1. Nginx"
if out=$("$NGINX" -t 2>&1); then ok "configuration is valid"; else bad "configuration has errors (a failed reload keeps the OLD config running):"; echo "$out" | sed 's/^/        /'; fi
REWRITE="/www/server/panel/vhost/rewrite/$DOMAIN.conf"
VHOST="/www/server/panel/vhost/nginx/$DOMAIN.conf"
in_vhost=$(grep -c 'location @laravel' "$VHOST" 2>/dev/null || true)
in_rewrite=$(grep -c 'location @laravel' "$REWRITE" 2>/dev/null || true)
if [ "${in_vhost:-0}" -ge 1 ] && [ "${in_rewrite:-0}" -ge 1 ]; then bad "the LinguaForge rules are in BOTH the site config and the URL rewrite file; keep them in one place only"
elif [ "${in_vhost:-0}" -ge 1 ] || [ "${in_rewrite:-0}" -ge 1 ]; then ok "LinguaForge rules found ($( [ "${in_rewrite:-0}" -ge 1 ] && echo 'URL rewrite' || echo 'site config'))"
else bad "LinguaForge rules not found in $VHOST or $REWRITE"; fi
if grep -qE '^\s*location\s+/\s*\{' "$REWRITE" "$VHOST" 2>/dev/null; then bad "another 'location / {' exists (e.g. the laravel5 rewrite or a reverse proxy); remove it"; fi
root=$(grep -E '^\s*root ' "$VHOST" 2>/dev/null | head -1 | awk '{print $2}' | tr -d ';')
[ "$root" = "$APP_DIR/backend/public" ] && ok "site root is $root" || bad "site root is '$root', expected $APP_DIR/backend/public"
sock=$(grep -ho 'unix:[^;]*' /www/server/nginx/conf/enable-php-83.conf 2>/dev/null | head -1)
rules_sock=$(grep -ho 'fastcgi_pass unix:[^;]*' "$VHOST" "$REWRITE" 2>/dev/null | head -1 | sed 's/fastcgi_pass //')
[ -n "$sock" ] && [ "$sock" = "$rules_sock" ] && ok "PHP socket matches ($sock)" || bad "PHP socket in rules '$rules_sock' differs from enable-php-83.conf '$sock'"
[ -S "${sock#unix:}" ] && ok "PHP-FPM socket exists" || bad "PHP-FPM socket ${sock#unix:} missing: is PHP 8.3 running?"

echo "2. Web app (Next.js on 127.0.0.1:3000)"
[ -f "$APP_DIR/frontend/.next/standalone/server.js" ] && ok "build exists" || bad "no build: run ops/aapanel/update.sh"
[ -d "$APP_DIR/frontend/.next/standalone/.next/static" ] && ok "static files copied" || bad "static files not copied into .next/standalone (run update.sh)"
code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 http://127.0.0.1:3000/ || true)
case "$code" in 200|307|308) ok "web app answers on port 3000 (HTTP $code)";; 000) bad "nothing listening on port 3000: start 'linguaforge-web' in Supervisor Manager";; *) bad "web app answered HTTP $code";; esac
if [ -f "$APP_DIR/frontend/.env.production" ]; then info "frontend/.env.production: $(grep NEXT_PUBLIC_API_URL "$APP_DIR/frontend/.env.production")"; else bad "frontend/.env.production missing"; fi

echo "3. Laravel"
[ -f "$APP_DIR/backend/.env" ] && ok ".env exists" || bad "backend/.env missing"
[ -d "$APP_DIR/backend/vendor" ] && ok "vendor installed" || bad "vendor missing: run update.sh"
for k in APP_ENV APP_URL FRONTEND_URL SANCTUM_STATEFUL_DOMAINS SESSION_DOMAIN DB_CONNECTION BILLING_DRIVER AI_DEFAULT_PROVIDER; do info "$(grep -E "^$k=" "$APP_DIR/backend/.env" 2>/dev/null || echo "$k=(not set)")"; done
grep -q '^APP_KEY=base64:' "$APP_DIR/backend/.env" 2>/dev/null && ok "APP_KEY set" || bad "APP_KEY empty: run update.sh (it generates one)"
if out=$(cd "$APP_DIR/backend" && "$PHP" artisan migrate:status --no-ansi 2>&1 | tail -1); then info "migrations: $out"; fi

echo "4. Through Nginx (as a visitor would)"
for path in /up /api/v1/languages /sanctum/csrf-cookie / /login; do
  res=$(curl -sk -o /tmp/lf-doctor.$$ -w '%{http_code} %{content_type}' --max-time 15 --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN$path" || true)
  code=${res%% *}; type=${res#* }
  if [ "$code" = "000" ]; then who="no response"
  elif [[ "$type" == *json* ]] || [ "$path" = /up ] || [ "$path" = /sanctum/csrf-cookie ]; then who="Laravel"
  elif grep -q '_next/static' /tmp/lf-doctor.$$ 2>/dev/null; then who="web app"
  elif grep -qi 'nginx' /tmp/lf-doctor.$$ 2>/dev/null; then who="nginx (request never reached the app)"
  else who="unknown"; fi
  case "$code" in 200|204|302|307|308) ok "$path → $code ($who)";; *) bad "$path → $code (answered by: $who)";; esac
done
rm -f /tmp/lf-doctor.$$

echo "5. Background services"
pgrep -f "artisan horizon" >/dev/null && ok "Horizon (queue worker) running" || bad "Horizon not running: add/start 'linguaforge-horizon' in Supervisor Manager"
pgrep -f "redis-server" >/dev/null && ok "Redis running" || bad "Redis not running"
crontab -l 2>/dev/null | grep -q "schedule:run" || grep -rqs "schedule:run" /www/server/cron 2>/dev/null && ok "scheduler cron found" || bad "no cron running 'artisan schedule:run'"

echo "6. Recent errors"
info "Nginx: $(tail -n 3 "/www/wwwlogs/$DOMAIN.error.log" 2>/dev/null | tr '\n' ' ' | cut -c1-400)"
info "Laravel: $(grep -h '\.ERROR' "$APP_DIR/backend/storage/logs/"*.log 2>/dev/null | tail -n 2 | cut -c1-300 | tr '\n' ' ')"
