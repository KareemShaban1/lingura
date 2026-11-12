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

## Phase 2 — Content & practice ✅
| # | Item | Status |
|---|---|---|
| 2.1 | Content model with translations; courses/units/lessons/objectives | ✅ |
| 2.2 | Starter A1–A2 packs (6 languages) + validated idempotent importer; immutable published content | ✅ |
| 2.3 | Exercise engine: mcq, cloze, ordering, reading, listening, dictation; deterministic scoring; feedback | ✅ |
| 2.4 | Grammar topics, mastery tracking, common-mistake log | ✅ |
| 2.5 | Vocabulary: FSRS-4.5 flashcards, review log, collections, dictionary, extraction from text | ✅ |
| 2.6 | Listening (TTS-cached audio, speed control, transcript reveal, dictation) and reading (tap-to-translate, reading speed) | ✅ |
| 2.7 | Plan items bound to lessons/exercises/flashcards; practice weaknesses feed new plan versions | ✅ |
| 2.8 | Daily per-skill rollups; progress analytics (accuracy, retention, reading speed, daily activity) | ✅ |
| — | Deferred: OpenAPI-generated TS types; B1+ content; admin authoring UI (Phase 4); per-skill time for standalone sessions | → later |

## Phase 3 — AI tutor & productive skills
Prompt registry · text tutor with streaming + corrections · realtime voice with ephemeral tokens + PTT fallback · scenarios & personalities · conversation reports · audio upload pipeline (private S3, signed URLs, virus/mime checks) · STT · pronunciation assessment · speaking & writing placement sections (rubric-based AI scoring with human-review sampling) · writing module · adaptive engine v1 (mistake pattern detection, difficulty adaptation, revision scheduling).

## Phase 4 — Business
Admin dashboard (RBAC) for all content and AI configuration · content review queue · Stripe subscriptions (monthly/annual), entitlements, quotas, usage metering, billing notifications · organizations/family seats · gamification (XP tied to verified learning outcomes, streaks with freeze, achievements, weekly challenges, leaderboards opt-in) · platform analytics (retention cohorts, AI cost, engagement).

## Phase 5 — Expansion
Exam preparation frameworks (original/licensed material, timed mocks, *estimated* band scores) · more languages (HSK requires Chinese: tone-aware pronunciation, character script handling) · advanced personalization (bandit-based activity selection) · mobile app (Expo) on token auth · offline lesson packs.
