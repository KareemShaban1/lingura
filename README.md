# LinguaForge

AI-powered multilingual language-learning platform: honest per-skill placement, explainable adaptive plans, and (in later phases) an AI conversation tutor.

**Status: Phase 1 complete** — architecture, database, authentication, onboarding, language catalog with capability matrix, adaptive placement assessment, CEFR skill profiles and reports, versioned rule-based learning plans, dashboard, progress analytics, privacy controls. See [`docs/07-backlog.md`](docs/07-backlog.md).

```
linguaforge/
├── backend/        Laravel 13 API (PHP 8.3, PostgreSQL, Redis, Sanctum, Horizon)
├── frontend/       Next.js 16 App Router PWA (TypeScript, Tailwind 4, Radix/shadcn-style UI, TanStack Query, Zustand)
├── docs/           Architecture, ERD, API, frontend, AI layer, infrastructure, backlog, risks/costs, methodology, deployment
├── docker/         Nginx config
├── ops/            Backup script
└── docker-compose.yml
```

## Documentation
| # | Document |
|---|---|
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

## Local development (without Docker)
Requirements: PHP 8.3 (pdo_pgsql, redis, intl), Composer, Node 22, PostgreSQL 16, Redis 7.

```bash
# database
createuser -s linguaforge && createdb -O linguaforge linguaforge && createdb -O linguaforge linguaforge_test

# backend (http://localhost:8000)
cd backend
cp .env.example .env && php artisan key:generate      # set DB_PASSWORD
php artisan migrate --seed                            # reference data + 204-item placement bank
php artisan serve
php artisan queue:work redis --queue=default,audio     # in a second terminal

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
- `php artisan lf:items:synthesize-audio` — TTS audio for listening items (enables the listening section);
- `php artisan lf:items:generate es grammar B1 --count=5` — AI-drafted items, stored as **drafts**;
- `php artisan lf:items:publish <ids…> --reviewer=editor@example.com` — human review gate.

## Tests
```bash
cd backend && php artisan test          # 44 tests: unit (IRT, CEFR scale, validator) + feature (auth, onboarding, assessment, plans, AI layer, privacy)
cd frontend && npx tsc --noEmit && npm run lint && npm run build
```
