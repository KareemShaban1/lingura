# 01 — Requirements Analysis & System Architecture

## 1. Requirements analysis

### 1.1 What the product actually is
A *personal AI language school*: **assess → diagnose → plan → teach → practise → re-assess → re-plan**, per learner, per target language. Every other feature (tutor, gamification, analytics, billing) either feeds that loop with evidence or monetises it.

### 1.2 Requirements that shape the architecture

| Requirement | Architectural consequence |
|---|---|
| Learn *any* supported language, multiple targets per user | `learner_language_profiles` is the aggregate root for all learning state, not `users`. Every learning table keys on the profile. |
| No language-specific assumptions in the core | Languages, grammar topics, phoneme inventories, scripts, directionality are **data**. Engines operate on skill/level/topic IDs. Language behaviour is plugged in via a `LanguageCapabilityMatrix` and per-language content. |
| Models don't support all languages equally | `language_capabilities` table (feature × language × provider × quality tier). Every AI call is routed through a capability check; UI hides/downgrades unsupported features. |
| Independent skill CEFR levels, no arithmetic average | `skill_profiles` (one row per profile × skill) + append-only `skill_scores`. Overall level is a documented rule (see `09-assessment-methodology.md`). |
| Never rely exclusively on AI for correctness | Deterministic scoring for closed items (MCQ, cloze, ordering). AI evaluation only for open production (writing/speaking) and always rubric-anchored, schema-validated and stored with model + prompt version. |
| Versioned, explainable plans | `learning_plans` → immutable `learning_plan_versions` (with `rationale` + inputs snapshot) → `learning_plan_items` each carrying a `reason_code`. |
| Keys never leave backend; quotas enforced server-side | AI gateway lives only in Laravel. Browser voice uses short-lived ephemeral tokens minted by the backend after a quota check. |
| Multi-tenant education subscriptions later | `organizations` + `organization_members` designed in from day one (nullable FK on subscriptions), no tenant-per-DB. |
| Commercial SaaS: audit, privacy, export/deletion | `audit_logs`, soft deletes on user-owned content, queued export/erase jobs. |

### 1.3 Non-functional targets (initial)
- p95 API latency < 300 ms for non-AI endpoints; AI endpoints are async (job + polling/SSE) unless interactive.
- 99.9 % availability for core API; graceful degradation when AI provider is down (text fallback, cached content).
- Horizontal scale: stateless API pods, Redis for cache/queues/rate limits, Postgres primary + read replica.

## 2. System architecture

```
┌──────────────────────────────── Clients ────────────────────────────────┐
│ Next.js (App Router) PWA — student app  │  /admin (same Next app, RBAC)  │
│ TanStack Query · Zustand · RHF+Zod · shadcn/ui · Recharts               │
└───────────────┬───────────────────────────────────────┬─────────────────┘
                │ HTTPS JSON (Sanctum SPA cookie/Bearer)│ WebRTC (voice)
                ▼                                       │ ephemeral token
┌──────────────── Nginx (TLS, gzip, rate-limit edge) ───┼─────────────────┐
│                                                        │                 │
│  Laravel API (stateless, php-fpm)                      │                 │
│  ├─ Http (Controllers · FormRequests · Resources)      │                 │
│  ├─ Domain modules (see §3)                            │                 │
│  ├─ AI Gateway (provider-agnostic interfaces) ─────────┼──► OpenAI / other
│  └─ Policies · Gates · Events · Notifications          │   providers     │
│                                                        ▼                 │
│  Horizon workers: ai, audio, assessment, plans, notifications, default   │
└───────┬─────────────────┬───────────────────┬────────────────────────────┘
        ▼                 ▼                   ▼
   PostgreSQL 16      Redis 7            S3-compatible storage
   (primary+replica)  cache/queue/locks  (private audio, signed URLs) → CDN
```

### 2.1 Key decisions
1. **Modular monolith (Laravel)**, not microservices. Modules have their own `Models/Services/Http` namespaces under `app/Domain/*` and talk via services + events. This keeps transactions simple while preserving extraction seams (AI gateway and audio processing are the likely first extractions).
2. **Single Next.js app** serving marketing, student and admin route groups; admin is gated by RBAC on both client (UX) and API (authority).
3. **Sanctum SPA authentication** (first-party cookie + CSRF) for the web app; personal access tokens for future mobile apps.
4. **Async AI.** Anything that takes > ~2 s (lesson generation, writing evaluation, reports) is a queued job with a status resource. Interactive AI (tutor turns) streams.
5. **Content is human-reviewable.** AI-generated content enters `draft → in_review → published` via `content_reviews`; learners only see `published` content (or explicitly flagged ephemeral, per-user generated practice).

## 3. Backend modules

| Module (`app/Domain/…`) | Responsibility | Phase |
|---|---|---|
| `Identity` | Registration, login, Sanctum, email verification, password reset, roles/permissions | 1 |
| `Languages` | Language catalog, capability matrix, language pairs, regional variants | 1 |
| `Onboarding` | Learner profile creation, goals, preferences, schedule | 1 |
| `Assessment` | Item bank, adaptive placement engine, scoring, CEFR estimation, reports | 1 (closed items), 3 (speaking/writing) |
| `Learning` | Plans, versions, items, sessions, recommendation engine | 1 (rule-based), 3 (adaptive) |
| `Curriculum` | Courses, levels, modules, lessons, objectives | 2 |
| `Exercises` | Exercises, questions, options, attempts, deterministic scoring | 2 |
| `Vocabulary` | Words, user vocabulary, SRS (FSRS/SM-2), reviews | 2 |
| `Grammar` | Topics, attempts, mastery | 2 |
| `Speech` | Recordings, transcription, pronunciation results | 3 |
| `Tutor` | Tutors, conversations, messages, feedback, realtime sessions | 3 |
| `AI` | Provider registry, models, prompts (versioned), gateway, usage metering | 1 (skeleton), 3 |
| `Analytics` | `learning_events` ingest, progress rollups, dashboards | 1 (events), 2–4 |
| `Gamification` | XP, streaks, achievements, challenges, leaderboards | 4 |
| `Billing` | Plans, subscriptions, payments (Stripe), quotas, usage records | 4 |
| `Admin` | Admin APIs, content review, moderation, audit | 4 |
| `Exams` | Exam frameworks, mock tests, estimated scores | 5 |

## 4. Cross-cutting concerns
- **Authorization:** Policies per model (`LearnerProfilePolicy`, `AssessmentAttemptPolicy`, …); learners can only touch their own profiles. Admin abilities via role → permission mapping.
- **Rate limiting:** named limiters (`auth`, `api`, `ai`, `assessment-answer`) in Redis.
- **Idempotency:** answer submission keyed on `(attempt_id, item_id)` unique constraint — retries are safe.
- **Observability:** structured JSON logs (request id, user id, profile id), Horizon metrics, Sentry-compatible error reporting, AI call log (`ai_usage_records`) with latency/tokens/cost.
- **i18n:** UI strings via message catalogs (`next-intl`), content strings stored per language; RTL determined by `languages.direction` of the *interface* language.
