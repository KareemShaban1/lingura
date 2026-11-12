# 10 — Production Deployment Guide

## 1. Prerequisites
- PostgreSQL 16 (managed, with PITR enabled), Redis 7, an S3-compatible **private** bucket, SMTP/SES.
- Container registry + orchestrator (ECS, Kubernetes, Fly, Render…), TLS at the load balancer.
- Secrets in a secret manager: `APP_KEY`, `DB_PASSWORD`, `OPENAI_API_KEY`, `AWS_*`, mail credentials.

## 2. Build
```bash
docker build -t registry.example.com/linguaforge-api:$(git rev-parse --short HEAD) --target app backend
docker build -t registry.example.com/linguaforge-web:$(git rev-parse --short HEAD) --build-arg NEXT_PUBLIC_API_URL=https://api.example.com frontend
```

## 3. Required environment (API, Horizon, scheduler)
| Variable | Value |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` / `FRONTEND_URL` | `https://api.example.com` / `https://app.example.com` |
| `SANCTUM_STATEFUL_DOMAINS` / `SESSION_DOMAIN` | `app.example.com` / `.example.com` (API and app must share the parent domain for SPA cookies) |
| `SESSION_SECURE_COOKIE` | `true` |
| `CACHE_STORE` / `QUEUE_CONNECTION` / `SESSION_DRIVER` | `redis` |
| `MEDIA_DISK` / `AWS_BUCKET` | `s3` / private bucket (block all public access) |
| `AI_DEFAULT_PROVIDER` | `openai` (the `fake` driver refuses to boot outside local/testing) |
| `OPENAI_API_KEY` | from secret manager — never exposed to the frontend |
| `LOG_CHANNEL` | `stderr` (ship JSON logs from stdout/stderr) |

## 4. Release procedure
1. Run migrations as a one-off task: `php artisan migrate --force`.
2. Seed/refresh reference data (idempotent): `php artisan db:seed --class=ReferenceDataSeeder --force`; first deploy only: `--class=ItemBankSeeder`.
3. Warm caches in the image entrypoint: `php artisan config:cache && php artisan route:cache && php artisan event:cache`.
4. Roll API pods (php-fpm + nginx sidecar using `docker/nginx/default.conf`).
5. `php artisan horizon:terminate` so workers restart on the new code.
6. Exactly one scheduler replica: `php artisan schedule:work` (plan roll-over hourly, account purge daily).
7. Generate listening audio once a TTS provider is configured: `php artisan lf:items:synthesize-audio` (queued on `audio`).

## 5. Post-deploy checks
- `GET /up` returns 200; `GET /api/v1/languages` returns six languages.
- Horizon dashboard (`/horizon`, staff only) shows workers on `default`, `audio`.
- Set AI unit prices in `ai_models` so `ai_usage_records.cost_micros` is meaningful.

## 6. Backups & recovery
- Managed PITR + `ops/backup.sh` nightly to a separate account/bucket with lifecycle rules.
- Restore drill quarterly: `pg_restore --clean --no-owner -d <staging> <dump>`, then run the test suite against it.

## 7. Monitoring
- Alerts: failed jobs > 5 in 5 min; p95 API latency > 500 ms; `ai.call` error rate > 5 %; daily AI cost > budget × 1.2.
- Dashboards from `ai_usage_records` (cost by operation/model/user) and `learning_events` (activation, sessions, retention).
