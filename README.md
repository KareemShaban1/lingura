# LinguaForge

AI-powered multilingual language-learning platform: honest per-skill placement, explainable adaptive plans, and (in later phases) an AI conversation tutor.

**Status: Phase 5 complete.** Phase 1: architecture, auth, onboarding, capability matrix, adaptive placement, CEFR skill profiles, versioned plans, dashboard, privacy. Phase 2: courses and lessons, six exercise types with deterministic scoring, grammar mastery, FSRS flashcards, listening and reading modules, content-bound plans, extended analytics. Phase 3: AI conversation tutor (text, push-to-talk, realtime voice), conversation reports, speech pipeline and pronunciation estimates, writing evaluation, writing/speaking placement, adaptive engine v1. Phase 4: plans and server-side quotas, Stripe billing, family groups, gamification (XP, streaks, achievements, challenges, opt-in leaderboard), RBAC admin area with review queue and analytics. Phase 5: Mandarin Chinese, timed exam practice with estimated results, bandit-personalised exercise formats, offline lesson packs and an Expo mobile companion app. See [`docs/07-backlog.md`](docs/07-backlog.md).

```
linguaforge/
├── backend/        Laravel 13 API (PHP 8.3, PostgreSQL, Redis, Sanctum, Horizon)
├── frontend/       Next.js 16 App Router PWA (TypeScript, Tailwind 4, Radix/shadcn-style UI, TanStack Query, Zustand)
├── mobile/         Expo (React Native) companion app on token auth — see mobile/README.md
├── docs/           Architecture, ERD, API, frontend, AI layer, infrastructure, backlog, risks/costs, methodology, deployment
├── docker/         Nginx config
├── ops/            Backup script
└── docker-compose.yml
```

## Documentation
| # | Document |
|---|---|
| — | [**User guide** (how to use the app, A to Z)](docs/USER_GUIDE.md) |
| — | [**Deploy on a VPS with aaPanel** (one website, one domain)](docs/DEPLOY_AAPANEL.md) |
| 01 | [Requirements analysis & system architecture](docs/01-architecture.md) |
| 02 | [Database architecture & ERD](docs/02-database-erd.md) |
| 03 | [Backend modules & REST API](docs/03-api.md) — machine-readable: [`backend/openapi.yaml`](backend/openapi.yaml) |
| 04 | [Frontend routes & components](docs/04-frontend.md) |
| 05 | [AI integration architecture & capability matrix](docs/05-ai-architecture.md) |
| 06 | [Infrastructure requirements](docs/06-infrastructure.md) |
| 07 | [Phased backlog](docs/07-backlog.md) |
| 08 | [Technical risks & AI operating costs](docs/08-risks-and-costs.md) |
| 09 | [Placement assessment methodology (CEFR, IRT)](docs/09-assessment-methodology.md) |
| 10 | [Production deployment guide](docs/10-deployment.md) |
| 11 | [Practice, content and spaced repetition](docs/11-practice-and-srs.md) |
| 12 | [AI tutor, speech, writing & adaptive engine](docs/12-tutor-and-adaptive.md) |
| 13 | [Billing, family groups, gamification & administration](docs/13-business-and-admin.md) |
| 14 | [Chinese, exam practice, personalisation, offline & mobile](docs/14-expansion.md) |

## Try it locally with temporary data (Docker)
Requirements: Docker with Compose. Nothing else to install, no API keys.

```bash
docker compose -f docker-compose.demo.yml up --build
```

Open http://localhost:3000 once the `web` container is up (the first build takes a few minutes) and sign in:
- **Learner:** `demo@linguaforge.test` / `password`: Spanish, placement taken, plan, practice history, flashcards, XP.
- **Admin:** `admin@linguaforge.test` / `password`: the admin area is at `/admin`.
- Or register a new account and go through onboarding.

The database and Redis live in memory: stopping the stack (`Ctrl+C`, then `docker compose -f docker-compose.demo.yml down -v`) deletes all data, and the next start creates fresh data. AI features use the built-in fake provider (placeholder replies) and checkout runs in test mode (no payment).

## Local development (without Docker)
Requirements: PHP 8.3 (pdo_pgsql or pdo_mysql, redis, intl), Composer, Node 22, PostgreSQL 16 **or MySQL 8.0+**, Redis 7.

For MySQL set `DB_CONNECTION=mysql`, `DB_PORT=3306` (and the database created with `utf8mb4`) in `backend/.env`; everything else is the same. PostgreSQL remains the primary target; both run the full test suite in CI.

```bash
# database
createuser -s linguaforge && createdb -O linguaforge linguaforge && createdb -O linguaforge linguaforge_test

# backend (http://localhost:8000)
cd backend
cp .env.example .env && php artisan key:generate      # set DB_PASSWORD
php artisan migrate --seed                            # reference data, placement banks and content packs for 7 languages
php artisan db:seed --class=DemoSeeder                # optional: demo learner with history (local only)
php artisan serve
php artisan queue:work redis --queue=default,ai,audio  # in a second terminal

# frontend (http://localhost:3000)
cd frontend
cp .env.example .env.local
npm install && npm run dev
```

The local seeder creates `admin@linguaforge.test` / `password` (super admin, local env only).

### With Docker
```bash
APP_KEY=$(cd backend && php artisan key:generate --show) docker compose up --build
docker compose exec api php artisan migrate --seed
```

### AI providers
Business logic talks only to `App\Domain\AI\AiGateway`. `AI_DEFAULT_PROVIDER=fake` (default for local/tests) is deterministic and never used in production. Set `AI_DEFAULT_PROVIDER=openai` and `OPENAI_API_KEY` (backend only) to enable:
- `php artisan lf:items:synthesize-audio` — TTS audio for placement listening items;
- `php artisan lf:content:synthesize-audio` — TTS audio for listening/dictation exercises (they stay hidden until then);
- `php artisan lf:items:generate es grammar B1 --count=5` — AI-drafted items, stored as **drafts**;
- `php artisan lf:items:publish <ids…> --reviewer=editor@example.com` — human review gate;
- the AI tutor, transcription, pronunciation and writing evaluation (queue workers must listen on `default,ai,audio`).

### Billing
`BILLING_DRIVER=fake` (default locally) runs checkout without payments. For Stripe set `BILLING_DRIVER=stripe`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_{PREMIUM,PRO,FAMILY}_{MONTHLY,YEARLY}`, re-run `php artisan db:seed --class=BillingPlanSeeder`, and send webhooks to `/api/v1/webhooks/billing`. `php artisan lf:billing:reconcile` runs hourly. The local seed creates `admin@linguaforge.test` / `password` (super admin) for `/admin`.

`php artisan lf:exams:synthesize-audio` creates exam listening recordings (those sections are not offered until then).

`php artisan lf:recordings:purge` (scheduled daily) deletes learner audio older than `LF_RECORDING_RETENTION_DAYS` (default 90).

## Content
Starter packs: `backend/database/data/content/{lang}.json`, generated by `python3 backend/database/data/content/build_packs.py backend/database/data/content`, imported by `php artisan db:seed --class=ContentSeeder` (idempotent).

## Tests
```bash
cd backend && php artisan test          # 130 tests: unit (IRT, CEFR, validator, answer checker, FSRS, pronunciation alignment, prompt cache) + feature (auth, onboarding, assessment, plans, content, practice, vocabulary, tutor, writing, adaptive engine, billing & webhooks, quotas, family groups, gamification, admin RBAC, content editor, Chinese text handling, exams, personalisation, offline sync, AI layer, privacy)
cd frontend && npx tsc --noEmit && npm run lint && npm run build
```
