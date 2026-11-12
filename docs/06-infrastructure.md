# 06 — Infrastructure Requirements & Deployment

## Runtime components
| Component | Dev (docker compose) | Production |
|---|---|---|
| Web/API | `nginx` + `php-fpm` (Laravel) | ≥ 2 replicas behind load balancer, autoscale on CPU/RPS |
| Workers | `horizon` container | Separate pool; queues: `default`, `assessment`, `plans`, `ai`, `audio`, `notifications` |
| Scheduler | `scheduler` container (`schedule:work`) | Single replica (leader lock via Redis `onOneServer`) |
| Frontend | `next dev` | `next start` (standalone output) or Vercel; CDN in front |
| PostgreSQL 16 | container | Managed (RDS/Cloud SQL/Neon) with PITR, read replica |
| Redis 7 | container | Managed, persistence on, separate DBs for cache/queue |
| Object storage | MinIO | S3/R2; private bucket for user audio, public-read CDN bucket for published content audio |
| Mail | Mailpit | SES/Postmark |

## Configuration
All secrets via environment (`.env` locally, secret manager in prod). `OPENAI_API_KEY` is read only by the backend `config/ai.php`. Frontend env contains only `NEXT_PUBLIC_API_URL`.

## Deployment guide (production)
1. Build images in CI (`.github/workflows/ci.yml` runs tests; `deploy.yml` pushes images on tag).
2. Run `php artisan migrate --force` as a one-off release job before rolling pods.
3. `php artisan config:cache route:cache event:cache`.
4. Roll API pods; `php artisan horizon:terminate` to recycle workers.
5. Seed reference data once: `php artisan db:seed --class=ReferenceDataSeeder --force` (idempotent).

## Backups
- Managed PITR (7–35 days) + nightly logical dump (`pg_dump -Fc`) to a separate bucket/account with lifecycle (30 daily, 12 monthly). Script: `ops/backup.sh`.
- Quarterly restore drill into a staging database.

## Monitoring & logging
- JSON logs → stdout → Loki/CloudWatch/Datadog. Context: `request_id`, `user_id`, `learner_profile_id`.
- Horizon dashboard (admin-only gate) + alert on failed jobs > N/5 min.
- AI: `ai_usage_records` → daily cost rollup; alert when daily cost > budget × 1.2 or per-user spend anomaly (> p99 × 3).
- Uptime checks on `/up` (Laravel health route) and the frontend.
