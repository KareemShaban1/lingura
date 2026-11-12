# 07 — Phased Development Backlog

Definition of done for every item: migrations + seed data, API with FormRequest validation and Policy, Resource output, feature test, frontend screen with loading/empty/error states, docs updated.

## Phase 1 — Foundation, onboarding, placement, basic plan
| # | Item | Status |
|---|---|---|
| 1.1 | Monorepo, Docker compose, CI (backend tests on PostgreSQL, frontend typecheck/lint/build) | ✅ |
| 1.2 | Reference data: languages, variants, capability matrix, pairs, skills, CEFR levels, purposes + skill weights | ✅ |
| 1.3 | Auth: register, login (SPA + token), logout, me, password reset, rate limiting, RBAC tables | ✅ |
| 1.4 | User profile & preferences, data export job, account deletion | ✅ |
| 1.5 | Onboarding: learner profiles (multi-target), schedule, focus skills, preferences | ✅ |
| 1.6 | Assessment item bank (original, authored items for 6 languages) + AI item generator with draft/publish workflow | ✅ |
| 1.7 | Adaptive placement engine (Rasch/EAP, per-skill), idempotent answers, skip/not-available sections | ✅ |
| 1.8 | CEFR estimation, independent skill profiles + score history, report with findings | ✅ |
| 1.9 | Rule-based plan generator (rules_v1) with versioning, allocations, reasons; pause/resume/regenerate; sessions | ✅ |
| 1.10 | Student dashboard, roadmap, progress, profile screens | ✅ |
| 1.11 | AI gateway skeleton (contracts, OpenAI + Fake drivers, usage metering) | ✅ |
| 1.12 | Audit logging, learning events | ✅ |

## Phase 2 — Content & practice
Courses/levels/modules/lessons + lesson player · exercise engine (all closed formats, deterministic scoring) · grammar topics & mastery · vocabulary + FSRS spaced repetition + flashcards · listening module (TTS-cached audio, speed control, transcript reveal, dictation) · reading module (interactive glossary, reading speed) · plan items bound to concrete lessons/exercises · daily progress rollups + charts · OpenAPI-generated TS types.

## Phase 3 — AI tutor & productive skills
Prompt registry · text tutor with streaming + corrections · realtime voice with ephemeral tokens + PTT fallback · scenarios & personalities · conversation reports · audio upload pipeline (private S3, signed URLs, virus/mime checks) · STT · pronunciation assessment · speaking & writing placement sections (rubric-based AI scoring with human-review sampling) · writing module · adaptive engine v1 (mistake pattern detection, difficulty adaptation, revision scheduling).

## Phase 4 — Business
Admin dashboard (RBAC) for all content and AI configuration · content review queue · Stripe subscriptions (monthly/annual), entitlements, quotas, usage metering, billing notifications · organizations/family seats · gamification (XP tied to verified learning outcomes, streaks with freeze, achievements, weekly challenges, leaderboards opt-in) · platform analytics (retention cohorts, AI cost, engagement).

## Phase 5 — Expansion
Exam preparation frameworks (original/licensed material, timed mocks, *estimated* band scores) · more languages (HSK requires Chinese: tone-aware pronunciation, character script handling) · advanced personalization (bandit-based activity selection) · mobile app (Expo) on token auth · offline lesson packs.
