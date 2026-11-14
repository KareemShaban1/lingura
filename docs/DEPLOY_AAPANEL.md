# Deploying LinguaForge on a Contabo VPS with aaPanel

> **Last updated:** 2026-11-14 · Use this for a single-server install. For containers or managed cloud, see `docs/10-deployment.md`.

This guide uses example domains. Replace them with your own throughout:

| Part | Example domain | Served by |
|---|---|---|
| Web app (Next.js) | `app.example.com` | Node process on port 3000, behind Nginx |
| API (Laravel) | `api.example.com` | PHP-FPM + Nginx, root `backend/public` |

**Both must be subdomains of the same parent domain.** Login uses cookies shared between the app and the API, so `app.yourdomain.com` + `api.yourdomain.com` works, but two unrelated domains do not.

---

## 0. What you need
- **VPS:** Ubuntu 22.04/24.04 or Debian 12. **8 GB RAM recommended** (4 GB is the minimum, and building the frontend needs about 2 GB free). 2+ vCPU, 40 GB+ disk.
- **DNS:** two `A` records, `app` and `api`, pointing at the VPS IP.
- **An OpenAI API key** (or another configured provider). The app refuses the `fake` AI driver outside local/testing, so the tutor, writing feedback, speech and audio generation need a real key.
- **SMTP credentials** for password resets, invitations and notifications.
- **Stripe** account keys if you will take payments (see [§9](#9-payments-stripe)).

## 1. Install aaPanel
SSH into the server as root and run the installer from <https://www.aapanel.com/new/download.html> (copy the current command from that page). When it finishes, open the panel URL it prints and log in. **Change the panel port, username and password**, and enable the panel's SSL.

## 2. Install the software stack (aaPanel → App Store)
Install:

| Software | Version | Notes |
|---|---|---|
| **Nginx** | latest stable | |
| **MySQL** | **8.0** | Use MySQL 8.0, not MariaDB: the schema uses MySQL 8 generated columns and is tested on MySQL 8.0 (PostgreSQL 16 is also supported) |
| **PHP** | **8.3** (or 8.4) | |
| **Redis** | 7.x | Queues, cache and locks |
| **Supervisor Manager** | latest | Keeps the queue worker and the web app running |
| **Node.js version manager** (or "Node.js" in Website → Node project) | Node **22 LTS** | Next.js 16 needs Node ≥ 20.9 |

### PHP settings (App Store → PHP 8.3 → Setting)
1. **Install extensions:** `redis`, `intl`, `fileinfo`, `opcache`, `bcmath`, `zip`, and `pdo_mysql` (bundled) or `pdo_pgsql` if you use PostgreSQL. `pcntl` is needed by the queue worker. It is usually compiled into the CLI; check with `php -m | grep pcntl`.
2. **Disabled functions:** remove these from the list, or Composer, Artisan and Horizon will fail: `proc_open`, `proc_get_status`, `putenv`, `pcntl_signal`, `pcntl_alarm`, `pcntl_async_signals`, `symlink`, `exec`, `shell_exec`.
3. **Configuration:** `upload_max_filesize = 10M`, `post_max_size = 12M`, `memory_limit = 256M`, `max_execution_time = 60`.
4. Restart PHP.

### Composer
In SSH: `composer --version`. If it's missing, install it from <https://getcomposer.org/download/>.

## 3. Create the database and Redis password
**Databases → Add database:** name `linguaforge`, user `linguaforge`, a strong password, access *local server*. Keep the password for `.env`.

**App Store → Redis → Setting:** set a `requirepass` password (recommended) and keep it bound to `127.0.0.1`.

## 4. Get the code
```bash
mkdir -p /www/wwwroot && cd /www/wwwroot
git clone https://github.com/KareemShaban1/lingura.git linguaforge
cd linguaforge
```
For a private repository, add a deploy key or use a personal access token. The rest of this guide assumes `/www/wwwroot/linguaforge`.

## 5. Back end (Laravel API)

### 5.1 Install and configure
```bash
cd /www/wwwroot/linguaforge/backend
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `backend/.env`. These are the values that matter in production:
```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.example.com
FRONTEND_URL=https://app.example.com
SANCTUM_STATEFUL_DOMAINS=app.example.com
SESSION_DOMAIN=.example.com
SESSION_SECURE_COOKIE=true
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=linguaforge
DB_USERNAME=linguaforge
DB_PASSWORD=your-db-password

SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=your-redis-password
REDIS_PORT=6379

MAIL_MAILER=smtp
MAIL_HOST=smtp.your-provider.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS="no-reply@example.com"

AI_DEFAULT_PROVIDER=openai
OPENAI_API_KEY=sk-...
MEDIA_DISK=local          # recordings and generated audio stay on this server, outside the web root

BILLING_DRIVER=stripe     # "fake" is refused in production; see §9
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
```

**Keep `.env` private.** It must never be inside a public web root. Only `backend/public` is served (step 5.3).

### 5.2 Database, content and caches
```bash
php artisan migrate --force
php artisan db:seed --force          # reference data, placement bank, courses, exams, plans. Safe to re-run.
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan event:cache
```

File permissions (aaPanel runs PHP as `www`):
```bash
chown -R www:www /www/wwwroot/linguaforge
chmod -R ug+rwX backend/storage backend/bootstrap/cache
```

### 5.3 API website
**Website → Add site**
- Domain: `api.example.com`
- Root directory: `/www/wwwroot/linguaforge/backend/public`
- PHP version: 8.3
- No database or FTP (already created)

Then open the site's settings:
- **Site directory:** untick *anti-cross-site (open_basedir)*, or Laravel can't read `../.env` and `../vendor`. Keep *Running directory* as `/`.
- **URL rewrite:** choose the **laravel5** template, or paste:
  ```nginx
  location / {
      try_files $uri $uri/ /index.php?$query_string;
  }
  ```
- **Config file:** inside the `server { … }` block, add `client_max_body_size 10m;` for audio and pack uploads.
- **SSL:** Let's Encrypt → issue for `api.example.com` → enable **Force HTTPS**.

Check: `https://api.example.com/up` should return 200, and `https://api.example.com/api/v1/languages` should list the languages.

## 6. Front end (Next.js web app)

### 6.1 Build
`NEXT_PUBLIC_API_URL` is baked in at build time, so set it before building:
```bash
cd /www/wwwroot/linguaforge/frontend
npm ci
echo "NEXT_PUBLIC_API_URL=https://api.example.com" > .env.production
npm run build
# The standalone server needs its static files next to it:
cp -r .next/static .next/standalone/.next/static
cp -r public .next/standalone/public
```
On a 4 GB server, if the build is killed for lack of memory, add swap (**aaPanel → Linux Tools → Swap**, 2–4 GB) and build again.

### 6.2 Keep it running (Supervisor Manager → Add daemon)
| Field | Value |
|---|---|
| Name | `linguaforge-web` |
| Run user | `www` |
| Run directory | `/www/wwwroot/linguaforge/frontend/.next/standalone` |
| Start command | `/usr/bin/env PORT=3000 HOSTNAME=127.0.0.1 NODE_ENV=production node server.js` |

If `node` isn't on Supervisor's path, use the full path from `which node` (e.g. `/www/server/nodejs/v22.x.x/bin/node`).

### 6.3 Web app website (reverse proxy)
**Website → Add site** with domain `app.example.com` (static, no PHP). Then:
- **Reverse proxy → Add:** target URL `http://127.0.0.1:3000`, send domain `$host`.
- **SSL:** Let's Encrypt for `app.example.com` → **Force HTTPS**.

**Firewall (aaPanel → Security):** do **not** open port 3000. Only 80, 443, SSH and your panel port should be public.

Open `https://app.example.com` and register an account.

## 7. Background jobs and scheduler (required)
AI feedback, writing evaluation, audio generation and emails run on queues. Plan roll-over, account purging, recording clean-up and billing reconciliation run on the scheduler. **Without these, many features silently wait forever.**

### 7.1 Queue worker: Supervisor Manager → Add daemon
| Field | Value |
|---|---|
| Name | `linguaforge-horizon` |
| Run user | `www` |
| Run directory | `/www/wwwroot/linguaforge/backend` |
| Start command | `/www/server/php/83/bin/php artisan horizon` |

Horizon runs the `default`, `ai` and `audio` queues. Staff can view it at `https://api.example.com/horizon`.

### 7.2 Scheduler: Cron → Add task
- Type: **Shell script**, period **every 1 minute**
- Script:
  ```bash
  cd /www/wwwroot/linguaforge/backend && sudo -u www /www/server/php/83/bin/php artisan schedule:run >> /dev/null 2>&1
  ```

## 8. First administrator and audio
Production does **not** create an admin account automatically. Register normally in the web app, then give that account the super-admin role:
```bash
cd /www/wwwroot/linguaforge/backend
php artisan tinker --execute='App\Models\User::where("email","you@example.com")->firstOrFail()->roles()->syncWithoutDetaching([App\Models\Role::where("slug","super_admin")->value("id")]);'
```
Sign out and back in. **Administration** now appears in the menu (`/admin`).

Generate the listening and dictation audio (uses your TTS provider; runs on the `audio` queue, so Horizon must be running):
```bash
php artisan lf:items:synthesize-audio
php artisan lf:content:synthesize-audio
php artisan lf:exams:synthesize-audio
```
Until this is done, listening exercises and exam listening sections are hidden from learners rather than shown without sound.

Also in **Admin → AI**, set the unit prices of your models so that AI cost reports are meaningful.

## 9. Payments (Stripe)
1. In Stripe, create six prices (Premium, Pro and Family, each monthly and yearly). Put their IDs in `STRIPE_PRICE_*` in `.env`, and set `STRIPE_SECRET`.
2. Add a webhook endpoint `https://api.example.com/api/v1/webhooks/billing` with the events `checkout.session.completed`, `customer.subscription.created/updated/deleted`, `invoice.paid`, `invoice.payment_succeeded`, `invoice.payment_failed`. Put its signing secret in `STRIPE_WEBHOOK_SECRET`.
3. Make the prices shown in **Admin → Billing** match the Stripe prices.
4. Run `php artisan config:cache`.

Until Stripe is configured, the free plan works but upgrading fails. Test with Stripe **test mode** keys first.

## 10. Updating to a new version
```bash
cd /www/wwwroot/linguaforge
git pull

cd backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --force                  # picks up new content (see the note below)
php artisan config:cache && php artisan route:cache && php artisan event:cache
php artisan horizon:terminate                # Supervisor restarts it on the new code
php artisan lf:content:synthesize-audio      # audio for any new content

cd ../frontend
npm ci && npm run build
cp -r .next/static .next/standalone/.next/static && cp -r public .next/standalone/public
chown -R www:www /www/wwwroot/linguaforge
```
Then restart `linguaforge-web` in **Supervisor Manager**.

**Note on content:** exercises and exam forms that learners have already answered are locked. If an update changes one of them, `db:seed` stops with an error naming it, and nothing is half-imported. In that case the update should publish it under a new key instead. Save this block as a script so each update is one command.

## 11. Backups
- **aaPanel → Cron → Backup database:** daily, keep 7–14 copies, and ideally upload to remote storage (S3, Google Drive, FTP).
- Also back up `backend/.env` and `backend/storage/app` (recordings and generated audio).
- Test a restore occasionally on a separate database.

## 12. Troubleshooting
| Symptom | Likely cause / fix |
|---|---|
| **500 error on the API** | Check `backend/storage/logs/laravel.log`. Common causes: wrong `.env`, permissions on `storage/`, `open_basedir` still enabled, or a function still disabled in PHP. |
| **"The fake billing driver cannot be used in production"** | Set `BILLING_DRIVER=stripe`, then `php artisan config:cache`. |
| **Login works but you're immediately logged out, or you get 419 / CSRF errors** | `SESSION_DOMAIN` must be `.example.com`, `SANCTUM_STATEFUL_DOMAINS` must be `app.example.com`, both sites must use HTTPS, and `NEXT_PUBLIC_API_URL` must be the API's `https://` URL (rebuild the front end after changing it). |
| **Writing feedback, tutor reports or audio never arrive** | Horizon isn't running. Check Supervisor Manager → `linguaforge-horizon` logs, and that Redis is running with the right password. |
| **The plan doesn't roll over to a new day** | The scheduler cron isn't running (§7.2). |
| **Listening exercises are missing** | Run the `synthesize-audio` commands (§8) with Horizon running. |
| **Web app shows a blank page or 502** | `linguaforge-web` isn't running, or the static files weren't copied into `.next/standalone` after the build. |
| **`Specified key was too long` or generated-column errors on migrate** | You're on MariaDB or MySQL 5.x. Use MySQL 8.0 (or PostgreSQL). |
| **Uploads fail (413)** | Raise `client_max_body_size` in the API site's Nginx config and `upload_max_filesize` in PHP. |

## 13. Mobile app (optional)
The Expo app in `mobile/` needs the API URL at build time (see `mobile/README.md`). Point it at `https://api.example.com`. It signs in with tokens, so no cookie settings are needed for it.
