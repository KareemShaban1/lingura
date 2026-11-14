# Deploying LinguaForge on a Contabo VPS with aaPanel (one website)

> **Last updated:** 2026-11-14 · For containers or managed cloud, see `docs/10-deployment.md`.

The whole app (front end and back end) runs from **one folder** and **one aaPanel website on one domain**:

```
https://example.com
 ├── /api/…  /sanctum/…  /horizon  /up   →  Laravel (PHP-FPM, site root = backend/public)
 └── everything else (/, /login, /home…) →  Next.js server on 127.0.0.1:3000 (reverse proxy)
```

The browser talks to a single origin, so there are no CORS or cross-domain cookie problems. Replace `example.com` with your domain throughout. A subdomain such as `learn.example.com` works the same way.

Two files in the repository do most of the work:
- `ops/aapanel/nginx-rewrite.conf`: the Nginx rules for the website (tested with aaPanel's default site rules).
- `ops/aapanel/update.sh`: installs and updates everything in one command.

---

## 0. What you need
- **VPS:** Ubuntu 22.04/24.04 or Debian 12. **8 GB RAM recommended** (4 GB minimum; the front-end build needs about 2 GB free). 2+ vCPU, 40 GB+ disk.
- **DNS:** an `A` record for your domain pointing at the VPS IP.
- **An OpenAI API key** (or another configured AI provider). Production refuses the test AI driver, so the tutor, writing feedback, speech and audio need a real key.
- **SMTP credentials** for password resets, invitations and notifications.
- **Stripe keys** if you will take payments (§9). Optional at first.

## 1. Install aaPanel
SSH into the server as root and run the installer from <https://www.aapanel.com/new/download.html> (copy the current command from that page). Open the panel URL it prints. **Change the panel port, username and password**, and enable the panel's SSL.

## 2. Install the software (aaPanel → App Store)
| Software | Version | Notes |
|---|---|---|
| **Nginx** | latest stable | |
| **MySQL** | **8.0** | Use MySQL 8.0, not MariaDB: the schema uses MySQL 8 generated columns (PostgreSQL 16 also works) |
| **PHP** | **8.3** (or 8.4) | |
| **Redis** | 7.x | Queues, cache, sessions |
| **Supervisor Manager** | latest | Keeps the queue worker and the web app running |
| **Node.js version manager** | Node **22 LTS** | Next.js 16 needs Node ≥ 20.9 |

**PHP 8.3 → Setting:**
1. **Install extensions:** `redis`, `intl`, `fileinfo`, `opcache`, `bcmath`, `zip`. `pdo_mysql` is built in (add `pdo_pgsql` if you use PostgreSQL). Check `pcntl` with `php -m | grep pcntl`; the queue worker needs it.
2. **Disabled functions:** remove `proc_open`, `proc_get_status`, `putenv`, `pcntl_signal`, `pcntl_alarm`, `pcntl_async_signals`, `symlink`, `exec`, `shell_exec`. If you skip this, Composer, Artisan and the queue worker fail.
3. **Configuration:** `upload_max_filesize = 10M`, `post_max_size = 12M`, `memory_limit = 256M`.
4. Restart PHP.

**Composer:** run `composer --version` in SSH. If it's missing, install it from <https://getcomposer.org/download/>.

**Node:** run `node -v` in SSH. It should print v22.x. If the command isn't found, link it: `ln -s /www/server/nodejs/v22.*/bin/node /usr/local/bin/node`, and do the same for `npm`.

## 3. Database and Redis
- **Databases → Add database:** name `linguaforge`, user `linguaforge`, a strong password, access *local server*.
- **App Store → Redis → Setting:** set a password (`requirepass`) and keep Redis bound to `127.0.0.1`.

## 4. Put the code in one folder
```bash
cd /www/wwwroot
git clone https://github.com/KareemShaban1/lingura.git lingura
```
The result is `/www/wwwroot/lingura` containing `backend/`, `frontend/`, `ops/`, and so on. For a private repository, use a deploy key or a personal access token.

## 5. Configure
### 5.1 Back end: `backend/.env`
```bash
cd /www/wwwroot/lingura/backend
cp .env.example .env
```
Edit `backend/.env`. With one domain, **all the URLs are the same**:
```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com
FRONTEND_URL=https://example.com
SANCTUM_STATEFUL_DOMAINS=example.com
SESSION_DOMAIN=null
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

BILLING_DRIVER=stripe     # the test driver is refused in production; see §9
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
```
`SANCTUM_STATEFUL_DOMAINS` is the domain **without** `https://`. If you use `www.example.com` as well, list both, separated by a comma. Better still, redirect `www` to the main domain in aaPanel.

### 5.2 Front end: `frontend/.env.production`
```bash
echo "NEXT_PUBLIC_API_URL=https://example.com" > /www/wwwroot/lingura/frontend/.env.production
```
This is the **same domain** as the site, with no `/api` suffix. The value is built into the front end, so if you change it, rebuild (run `update.sh` again).

## 6. Install and build (one command)
```bash
cd /www/wwwroot/lingura
bash ops/aapanel/update.sh
```
`update.sh` installs PHP and Node dependencies, generates the app key on the first run, runs migrations, seeds the reference data, courses, placement bank and exams (safe to re-run), caches the configuration, builds the web app and sets permissions. If your PHP binary isn't `/www/server/php/83/bin/php`, prefix the command, e.g. `PHP=/www/server/php/84/bin/php bash ops/aapanel/update.sh`.

On a 4 GB server, if the build is killed for lack of memory, add swap (**aaPanel → Linux Tools → Swap**, 2–4 GB) and run it again.

## 7. The website (one site for everything)
### 7.1 Create it (or reuse an existing site)
If the site already exists, for example with root `/www/wwwroot/lingura/dist`, don't create a new one. Change its root in **Site directory** (§7.2) to `/www/wwwroot/lingura/backend/public`; nothing else in the site needs to be recreated.

**Website → Add site:**
- Domain: `example.com` (add `www.example.com` too if you want it)
- **Root directory: `/www/wwwroot/lingura/backend/public`**
- PHP version: 8.3
- No FTP or database (already created)

### 7.2 Site settings
1. **Site directory:** set **Site directory** to `/www/wwwroot/lingura/backend/public`, keep **Running directory** at `/`, and untick **anti-cross-site (open_basedir)**, so Laravel can read `../.env` and `../vendor`. Change the root here rather than by editing the config file: aaPanel rewrites the config from these settings.
2. **URL rewrite:** clear the box, paste the whole content of `ops/aapanel/nginx-rewrite.conf` and save.
   - The file assumes PHP-FPM listens on `unix:/tmp/php-cgi-83.sock` (aaPanel's default for PHP 8.3). Check `/www/server/nginx/conf/enable-php-83.conf`. If yours differs (e.g. `php-cgi-84.sock`), change the `fastcgi_pass` line.
   - SSL certificate checks (`/.well-known/`) keep working with both old and new aaPanel layouts. Tested with aaPanel's `well-known/<domain>.conf` include in each form it takes.
   - **Don't** also add a reverse proxy in the site's *Reverse proxy* tab; the rewrite rules already do it. Two `location /` blocks make Nginx refuse the config.
3. **Config file:** inside `server { … }`, add `client_max_body_size 10m;` (audio uploads and content packs) and save.
4. **SSL:** Let's Encrypt → issue for your domain → turn on **Force HTTPS**.

### 7.3 Run the web app (Supervisor Manager → Add daemon)
| Field | Value |
|---|---|
| Name | `linguaforge-web` |
| Run user | `www` |
| Run directory | `/www/wwwroot/lingura/frontend/.next/standalone` |
| Start command | `/usr/bin/env PORT=3000 HOSTNAME=127.0.0.1 NODE_ENV=production node server.js` |

If Supervisor can't find `node`, use the full path from `which node`.

**Firewall (aaPanel → Security):** do **not** open port 3000. The web app only listens on `127.0.0.1`, and Nginx reaches it locally. Only 80, 443, SSH and the panel port should be public.

### 7.4 Check
| Open | Expect |
|---|---|
| `https://example.com/up` | 200 (Laravel is reachable) |
| `https://example.com/api/v1/languages` | JSON list of languages |
| `https://example.com/` | The LinguaForge home page |
| `https://example.com/register` | You can create an account and land on onboarding |

## 8. Background jobs and scheduler (required)
AI feedback, writing evaluation, audio and emails run on queues. Plan roll-over, account purging, recording clean-up and billing reconciliation run on the scheduler. **Without these, many features wait forever.**

**Queue worker: Supervisor Manager → Add daemon**
| Field | Value |
|---|---|
| Name | `linguaforge-horizon` |
| Run user | `www` |
| Run directory | `/www/wwwroot/lingura/backend` |
| Start command | `/www/server/php/83/bin/php artisan horizon` |

**Scheduler: Cron → Add task** (type *Shell script*, every **1 minute**):
```bash
cd /www/wwwroot/lingura/backend && sudo -u www /www/server/php/83/bin/php artisan schedule:run >> /dev/null 2>&1
```

## 9. First administrator, audio and payments
**Make yourself admin.** Production doesn't create an admin account automatically. Register on the site, then run:
```bash
cd /www/wwwroot/lingura/backend
php artisan tinker --execute='App\Models\User::where("email","you@example.com")->firstOrFail()->roles()->syncWithoutDetaching([App\Models\Role::where("slug","super_admin")->value("id")]);'
```
Sign out and back in to see **Administration** (`/admin`). The queue dashboard is at `/horizon` (staff only).

**Generate listening audio** (queued, so Horizon must be running):
```bash
php artisan lf:items:synthesize-audio
php artisan lf:content:synthesize-audio
php artisan lf:exams:synthesize-audio
```
Until the audio exists, listening exercises and exam listening sections are hidden rather than shown without sound. Also set your AI models' unit prices in **Admin → AI**, so that AI cost reports are meaningful.

**Stripe (when you're ready to charge):**
1. Create six prices (Premium, Pro and Family, each monthly and yearly) and put their IDs in `STRIPE_PRICE_*`. Set `STRIPE_SECRET`.
2. Add a webhook to `https://example.com/api/v1/webhooks/billing` for `checkout.session.completed`, `customer.subscription.created/updated/deleted`, `invoice.paid`, `invoice.payment_succeeded` and `invoice.payment_failed`. Put its signing secret in `STRIPE_WEBHOOK_SECRET`.
3. Make the prices in **Admin → Billing** match the Stripe prices, then run `php artisan config:cache`.

Until Stripe is set up, the free plan works but upgrading fails. Test with Stripe test-mode keys first.

## 10. Updating
```bash
bash /www/wwwroot/lingura/ops/aapanel/update.sh
```
It pulls the code, migrates, seeds new content, rebuilds the web app, restarts the queue worker and asks Supervisor to restart `linguaforge-web`. If it can't do the restart itself, it tells you to do it in Supervisor Manager.

Content that learners have already answered is locked. If an update changes such an exercise or exam form, `db:seed` stops with an error naming it, and nothing is half-imported. Publish the change under a new key instead.

## 11. Backups
- **aaPanel → Cron → Backup database:** daily, keep 7–14 copies, and preferably upload to remote storage.
- Also back up `backend/.env` and `backend/storage/app` (recordings and generated audio).
- Test a restore on a separate database now and then.

## 12. Troubleshooting
Run the read-only check first. It tests Nginx, the web app, Laravel and the background services, and says which part fails:
```bash
bash /www/wwwroot/lingura/ops/aapanel/doctor.sh your-domain
```

| Symptom | Fix |
|---|---|
| **Nginx won't save the rewrite ("duplicate location")** | Remove the site's *Reverse proxy* entry and any old rewrite (e.g. *laravel5*); the rewrite file must be the only `location /`. |
| **404 on every page right after setting things up** | Usually the new Nginx config was never loaded: `nginx -t` fails (often the rules were pasted into both the URL rewrite and the config file), so the old config, with its old root, stays active. Fix what `nginx -t` reports, then reload Nginx. |
| **Site loads but has no styling, or `/_next/...` returns 404** | The rewrite rules aren't active, or `update.sh` didn't copy `.next/static` into `.next/standalone`. Run it again. |
| **502 Bad Gateway on pages** | `linguaforge-web` isn't running. Check Supervisor Manager → its log. |
| **502 on `/api/...`** | The PHP socket path in the rewrite doesn't match `enable-php-83.conf`, or PHP 8.3 is stopped. |
| **500 on `/api/...`** | Check `backend/storage/logs/laravel.log`. Usual causes: `.env` values, permissions on `storage/`, `open_basedir` still on, a PHP function still disabled. |
| **Login fails, 419 / CSRF mismatch, or you're logged out at once** | `APP_URL`, `FRONTEND_URL` and `NEXT_PUBLIC_API_URL` must all be the same `https://` domain. `SANCTUM_STATEFUL_DOMAINS` must be the bare domain, and `SESSION_DOMAIN=null`. Rebuild after changing `NEXT_PUBLIC_API_URL`, and run `php artisan config:cache` after changing `.env`. |
| **"The fake billing driver cannot be used in production"** | Set `BILLING_DRIVER=stripe` and run `php artisan config:cache`. |
| **Writing feedback, tutor reports or audio never arrive** | Horizon isn't running, or Redis is down or the password is wrong. |
| **The daily plan doesn't roll over** | The scheduler cron (§8) isn't running. |
| **Listening exercises missing** | Run the `synthesize-audio` commands (§9) with Horizon running. |
| **Migration errors about keys or generated columns** | You're on MariaDB or MySQL 5.x. Use MySQL 8.0 (or PostgreSQL). |
| **Uploads fail with 413** | Add `client_max_body_size 10m;` (§7.2) and raise PHP's `upload_max_filesize`. |

## 13. Mobile app (optional)
The Expo app in `mobile/` reads `EXPO_PUBLIC_API_URL` and `EXPO_PUBLIC_WEB_URL` at build time (see `mobile/README.md`). Set both to `https://example.com`. The app signs in with tokens, so it needs no cookie settings.
