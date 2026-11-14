#!/usr/bin/env bash
# Run LinguaForge's long-running processes as systemd services (instead of aaPanel's Supervisor Manager):
#   linguaforge-web        the Next.js web app on 127.0.0.1:$WEB_PORT
#   linguaforge-horizon    the queue worker (AI feedback, audio, emails)
#   linguaforge-scheduler  the scheduler (plan roll-over, clean-ups, billing reconciliation)
# They start on boot and restart if they crash. Run as root:
#   WEB_PORT=3020 bash /www/wwwroot/lingura/ops/aapanel/services.sh lingura.example.com
# The domain is optional: when given, the site's Nginx proxy_pass is switched to WEB_PORT and Nginx is reloaded
# (with a backup, and only if `nginx -t` passes). Safe to re-run, e.g. to change the port.
set -euo pipefail

DOMAIN="${1:-}"
WEB_PORT="${WEB_PORT:-3000}"
APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
PHP="${PHP:-/www/server/php/83/bin/php}"
WEB_USER="${WEB_USER:-www}"
NGINX="${NGINX:-$( [ -x /www/server/nginx/sbin/nginx ] && echo /www/server/nginx/sbin/nginx || command -v nginx )}"
NODE="${NODE:-$(command -v node || ls -d /www/server/nodejs/v*/bin/node 2>/dev/null | sort -V | tail -1)}"

[ "$(id -u)" = 0 ] || { echo "Run as root."; exit 1; }
[[ "$WEB_PORT" =~ ^[0-9]+$ ]] || { echo "WEB_PORT must be a number."; exit 1; }
[ -x "$PHP" ] || { echo "PHP not found at $PHP (set PHP=/path/to/php)."; exit 1; }
[ -n "$NODE" ] && [ -x "$NODE" ] || { echo "Node.js not found (install Node 22 in aaPanel, or set NODE=/path/to/node)."; exit 1; }
node_major=$("$NODE" -p 'process.versions.node.split(".")[0]')
[ "$node_major" -ge 20 ] || { echo "Node $("$NODE" -v) is too old; Next.js 16 needs Node 20.9+ (22 recommended)."; exit 1; }
STANDALONE="$APP_DIR/frontend/.next/standalone"
[ -f "$STANDALONE/server.js" ] || { echo "No web app build at $STANDALONE: run ops/aapanel/update.sh first."; exit 1; }
if ss -ltn 2>/dev/null | grep -q ":$WEB_PORT " && ! systemctl is-active --quiet linguaforge-web; then
  echo "Port $WEB_PORT is already used by another program:"; ss -ltnp | grep ":$WEB_PORT " || true; exit 1
fi

echo "==> Using: app=$APP_DIR php=$PHP node=$NODE ($("$NODE" -v)) port=$WEB_PORT user=$WEB_USER"
echo "WEB_PORT=$WEB_PORT" > /etc/linguaforge.env   # read by update.sh and doctor.sh

cat > /etc/systemd/system/linguaforge-web.service <<EOF
[Unit]
Description=LinguaForge web app (Next.js)
After=network.target

[Service]
User=$WEB_USER
WorkingDirectory=$STANDALONE
Environment=NODE_ENV=production PORT=$WEB_PORT HOSTNAME=127.0.0.1 NEXT_TELEMETRY_DISABLED=1
ExecStart=$NODE server.js
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF

cat > /etc/systemd/system/linguaforge-horizon.service <<EOF
[Unit]
Description=LinguaForge queue worker (Laravel Horizon)
After=network.target

[Service]
User=$WEB_USER
WorkingDirectory=$APP_DIR/backend
ExecStart=$PHP artisan horizon
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target
EOF

cat > /etc/systemd/system/linguaforge-scheduler.service <<EOF
[Unit]
Description=LinguaForge scheduler (artisan schedule:work)
After=network.target

[Service]
User=$WEB_USER
WorkingDirectory=$APP_DIR/backend
ExecStart=$PHP artisan schedule:work
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

chown -R "$WEB_USER:$WEB_USER" "$APP_DIR"
systemctl daemon-reload
for s in linguaforge-web linguaforge-horizon linguaforge-scheduler; do
  systemctl enable --quiet "$s"
  systemctl restart "$s"
done

if [ -n "$DOMAIN" ]; then
  echo "==> Pointing $DOMAIN's Nginx proxy at port $WEB_PORT"
  changed=0
  for f in "/www/server/panel/vhost/nginx/$DOMAIN.conf" "/www/server/panel/vhost/rewrite/$DOMAIN.conf"; do
    if [ -f "$f" ] && grep -q 'proxy_pass http://127.0.0.1:[0-9]*;' "$f"; then
      cp "$f" "$f.bak.$(date +%s)"
      sed -i -E "s#proxy_pass http://127\.0\.0\.1:[0-9]+;#proxy_pass http://127.0.0.1:$WEB_PORT;#" "$f"
      changed=1; echo "    updated $f"
    fi
  done
  if [ "$changed" = 1 ]; then
    if "$NGINX" -t >/dev/null 2>&1; then "$NGINX" -s reload && echo "    Nginx reloaded"
    else
      echo "    nginx -t failed; restoring the previous files:"; "$NGINX" -t || true
      for f in "/www/server/panel/vhost/nginx/$DOMAIN.conf" "/www/server/panel/vhost/rewrite/$DOMAIN.conf"; do
        b=$(ls -t "$f".bak.* 2>/dev/null | head -1); [ -n "$b" ] && cp "$b" "$f"
      done
      exit 1
    fi
  else
    echo "    no 'proxy_pass http://127.0.0.1:…' found for $DOMAIN: add the LinguaForge rules (ops/aapanel/nginx-rewrite.conf) first"
  fi
fi

echo "==> Status"
sleep 3
for s in linguaforge-web linguaforge-horizon linguaforge-scheduler; do
  printf '    %-24s %s\n' "$s" "$(systemctl is-active "$s")"
done
code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "http://127.0.0.1:$WEB_PORT/" || true)
echo "    web app on 127.0.0.1:$WEB_PORT answered HTTP $code"
[ "$code" = 000 ] && echo "    It is not answering yet. See why with: journalctl -u linguaforge-web -n 50 --no-pager"
echo "Logs: journalctl -u linguaforge-web -f   (or -horizon / -scheduler)"
echo "If you created these in aaPanel's Supervisor Manager or Cron as well, delete them there so nothing runs twice."
