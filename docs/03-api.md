# 03 — Backend Modules & REST API

Base URL: `/api/v1`. JSON only. Auth: Sanctum (SPA cookie + `X-XSRF-TOKEN`, or `Authorization: Bearer` for mobile).
Errors follow a single envelope:

```json
{ "message": "Human readable", "code": "quota_exceeded", "errors": { "field": ["..."] } }
```

Pagination: cursor-based for feeds (`?cursor=`), page-based for admin tables (`?page=&per_page=` max 100).
Machine-readable spec: `backend/openapi.yaml` (served at `GET /api/v1/openapi.yaml`). ✅ = implemented.

## Identity
| Method | Path | Description | |
|---|---|---|---|
| POST | `/auth/register` | Create account (name, email, password, native_language, interface_language, timezone) | ✅ |
| POST | `/auth/login` | Session login (SPA) or token (`device_name` given) | ✅ |
| POST | `/auth/logout` | Revoke session / current token | ✅ |
| GET | `/auth/me` | Current user + profile + roles + learner profiles | ✅ |
| POST | `/auth/forgot-password`, `/auth/reset-password` | Password reset | ✅ |
| PATCH | `/me/profile` | Update native/interface language, timezone, name | ✅ |
| PATCH | `/me/preferences` | Theme, reminders, consents | ✅ |
| GET/POST | `/me/data-exports` | List / queue a full personal-data export (email when ready) | ✅ |
| GET | `/me/data-exports/{id}/download` | 5-minute signed URL for a ready export | ✅ |
| DELETE | `/me` | Request account deletion (password confirm; soft-delete + queued erase) | ✅ |

## Languages
| GET | `/languages` | Active catalog, with variants and capability summary | ✅ |
| GET | `/languages/{code}` | Single language incl. capability matrix | ✅ |
| GET | `/catalog/onboarding` | Purposes, skills, CEFR levels, languages (one call for the wizard) | ✅ |

## Learner profiles (one per target language)
| GET | `/learner-profiles` | List my target languages | ✅ |
| POST | `/learner-profiles` | Onboarding submit (target language, variant, purpose, level, daily minutes, schedule, focus skills, preferences) | ✅ |
| GET | `/learner-profiles/{id}` | Profile + skill profiles + plan summary | ✅ |
| PATCH | `/learner-profiles/{id}` | Change goal / daily minutes / schedule (triggers plan re-version) | ✅ |
| DELETE | `/learner-profiles/{id}` | Archive language | ✅ |
| GET | `/learner-profiles/{id}/dashboard` | Dashboard aggregate (today’s items, streak, skill levels, minutes this week) | ✅ |
| GET | `/learner-profiles/{id}/progress` | Skill history, 12-week activity, practice by skill, assessment history | ✅ |

## Assessment
| POST | `/learner-profiles/{id}/assessments` | Start placement attempt (resumes an in-progress one) | ✅ |
| GET | `/assessment-attempts/{attempt}` | Attempt state, per-section progress | ✅ |
| GET | `/assessment-attempts/{attempt}/next` | Next adaptive item (no answer key), or `{done:true}` | ✅ |
| POST | `/assessment-attempts/{attempt}/answers` | Submit answer `{item_id, option_id, response_time_ms}` (idempotent) | ✅ |
| POST | `/assessment-attempts/{attempt}/sections/{skill}/skip` | Skip a section (marked *not assessed*) | ✅ |
| POST | `/assessment-attempts/{attempt}/complete` | Finalise early (remaining sections skipped) | ✅ |
| GET | `/assessment-attempts/{attempt}/report` | Report: per-skill level, confidence, findings, recommendations | ✅ |
| GET | `/learner-profiles/{id}/assessment-attempts` | History | ✅ |
| GET | `/assessment-attempts/{attempt}/items/{item}/audio` | `{url}` — signed 5-minute URL, only for the item currently presented | ✅ |

## Learning plans
| GET | `/learner-profiles/{id}/plan` | Active plan + current version + allocations | ✅ |
| GET | `/learner-profiles/{id}/plan/items?from=&to=` | Items in a date range (roadmap / today) | ✅ |
| GET | `/learner-profiles/{id}/plan/versions` | Version history with rationale | ✅ |
| POST | `/learner-profiles/{id}/plan/regenerate` | New version from current evidence | ✅ |
| POST | `/learner-profiles/{id}/plan/pause` · `/resume` | Pause/resume | ✅ |
| POST | `/plan-items/{item}/start` | Starts a learning session | ✅ |
| GET | `/learning-sessions/{session}` | Session with its plan item | ✅ |
| POST | `/learning-sessions/{session}/complete` | `{active_seconds, self_rating}` → item completed, events emitted; credit capped at real elapsed time | ✅ |
| POST | `/plan-items/{item}/skip` | Skip with reason | ✅ |

## Phase 2 — content & practice ✅
| Method | Path | Description |
|---|---|---|
| POST | `/learner-profiles/{id}/learning-sessions` | Standalone session (`lesson`, `exercise`, `vocabulary_review`) for time tracking |
| GET | `/learner-profiles/{id}/courses` | Course → units → lessons with progress, `next_lesson_id` |
| GET | `/learner-profiles/{id}/lessons/{lesson}` | Lesson: intro, objectives, grammar topic, words, exercises with completion |
| POST | `/learner-profiles/{id}/lessons/{lesson}/complete` | Requires all available exercises completed; adds lesson words to flashcards |
| GET | `/learner-profiles/{id}/grammar-topics` | Topics with mastery |
| GET | `/learner-profiles/{id}/grammar-topics/{slug}` | Explanation, examples, exercises, recent mistakes |
| GET | `/learner-profiles/{id}/exercises?skill=&level=&type=` | Practice library (only usable exercises; audio types need audio) |
| POST | `/learner-profiles/{id}/exercises/{exercise}/attempts` | Start/resume an attempt `{lesson_id?, session_id?}` |
| GET | `/exercise-attempts/{attempt}` | Attempt; keys only for answered questions; listening transcript after finishing |
| POST | `/exercise-attempts/{attempt}/answers` | `{question_id, option_id \| text \| order[]}` → feedback (correctness, partial credit, correct answer, explanation). Idempotent |
| POST | `/exercise-attempts/{attempt}/finish` | `{reading_seconds?}` → score, words per minute |
| GET | `/exercise-attempts/{attempt}/audio?question_id=` | Signed URL for listening passage / dictation sentence |
| GET | `/learner-profiles/{id}/vocabulary?filter=&collection=&q=` | Deck (paginated) |
| POST | `/learner-profiles/{id}/vocabulary` | Add word `{word_id, source?, collection_id?}` (idempotent) |
| GET | `/learner-profiles/{id}/vocabulary/summary` | Totals, due, mastered, 30-day retention |
| GET | `/learner-profiles/{id}/vocabulary/review-queue?limit=` | Due cards, relearning first |
| GET | `/learner-profiles/{id}/vocabulary/lookup?q=` | Dictionary search (lemma or inflected form) |
| POST | `/learner-profiles/{id}/vocabulary/extract` | Catalogue words found in a text (tap-to-translate) |
| POST | `/user-vocabulary/{card}/reviews` | `{rating 1–4, duration_ms?}` → FSRS schedule |
| DELETE | `/user-vocabulary/{card}` | Remove from deck |
| GET/POST | `/learner-profiles/{id}/vocabulary-collections` | List / create collections |
| DELETE | `/vocabulary-collections/{collection}` | Delete |
| POST | `/vocabulary-collections/{collection}/items` | Toggle a card in a collection |

Plan items now carry `lesson_id`, `exercise_id` and `topic`; dashboard adds `vocabulary_due`; progress adds `skill_accuracy`, `daily_skill_activity`, `vocabulary`, `grammar`, `reading`, `exercises_completed`.

## Phase 3 — tutor, speech, writing ✅
All routes below use the `ai` limiter (30/min per user). Details: [12-tutor-and-adaptive.md](12-tutor-and-adaptive.md).

| Method | Path | Description |
|---|---|---|
| GET | `/learner-profiles/{id}/tutor/catalog` | Tutors, scenarios (filtered by level), available modes per capability matrix, remaining daily turns |
| GET | `/learner-profiles/{id}/conversations` | Recent conversations |
| POST | `/learner-profiles/{id}/conversations` | `{tutor, scenario?, level?, mode: text\|voice\|realtime, session_id?}` (reuses an open one) |
| GET | `/conversations/{c}` | Conversation, messages, corrections |
| POST | `/conversations/{c}/messages` | `{text ≤ 2000, response_ms?}` → tutor reply + corrections (JSON per turn) |
| POST | `/conversations/{c}/voice` | `{recording_id}` → transcribe, then same as a text turn |
| POST | `/conversations/{c}/realtime-session` | Ephemeral client secret for WebRTC (never the API key) |
| POST | `/conversations/{c}/realtime-transcript` | `{role, text}` — persists realtime turns |
| POST | `/conversations/{c}/finish` | `{active_seconds}` → closes, queues report |
| GET | `/conversations/{c}/report` · POST `/report/retry` | Report (`pending\|ready\|failed`) / retry a failed one |
| GET | `/ai-messages/{m}/audio` | Signed URL for tutor-reply TTS (cached) |
| POST | `/learner-profiles/{id}/recordings` | Multipart `{audio ≤ 4 MB, purpose, duration_ms?}`, MIME-sniffed, private |
| GET | `/recordings/{r}/url` | 5-minute signed playback URL (owner only) |
| GET | `/learner-profiles/{id}/writing-tasks` · `/{task}` | Writing tasks with past submissions |
| POST | `/learner-profiles/{id}/writing-tasks/{task}/submissions` | `{text ≤ 5000}` → 202, queued evaluation |
| GET | `/writing-submissions/{s}` | Submission + evaluation (criteria, anchored corrections, corrected text) |
| POST | `/assessment-attempts/{a}/writing` · `/speaking` | Productive placement answers `{text}` / `{recording_id}` |
| POST | `/exercise-attempts/{a}/answers` | Now also `{question_id, recording_id}` for repeat-after-me (pronunciation result in feedback) |

`GET /assessment-attempts/{a}/next` returns `{item}` or `{task}`. Progress adds `level_progress`, `writing`, `speaking`, `mistake_patterns`; plan items add `writing_task_id` and `scenario`.

## Phase 4 — billing, family, gamification, admin ✅
Details: [13-business-and-admin.md](13-business-and-admin.md). Quota errors: `429 {code: "quota_exceeded", details: {feature, limit, resets_at, plan}}`.

| Method | Path | Description |
|---|---|---|
| GET | `/billing/plans` | Public plan list with limits; `meta.checkout_available` |
| GET | `/billing/subscription` | Effective plan, source (`free\|personal\|organization`), subscription, usage per feature |
| POST | `/billing/checkout` | `{plan}` → `{url}` (provider-hosted checkout) |
| POST | `/billing/checkout/confirm` | `{session_id}` — completes test-mode sessions; paid ones are confirmed by webhook |
| POST | `/billing/subscription/cancel` · `/resume` | Cancel at period end / undo |
| POST | `/billing/portal` | `{url}` of the provider portal (payment methods, invoices) |
| GET | `/billing/payments` | Payment history |
| POST | `/webhooks/billing` | Provider webhook (signature-verified, idempotent) |
| GET · POST | `/me/notifications` · `/me/notifications/read` | In-app notifications / mark all read |
| GET · POST | `/organizations` | My groups / create a family group |
| GET · PATCH | `/organizations/{id}` | Group with seats, members, pending invitations (admins) / rename |
| POST | `/organizations/{id}/invitations` | `{email, role?}` → invitation + shareable `accept_url` |
| DELETE | `/organization-invitations/{id}` | Revoke |
| GET · POST | `/invitations/{token}` · `/accept` | Preview / accept (invited email only) |
| DELETE · POST | `/organizations/{id}/members/{member}` · `/leave` | Remove a member / leave |
| GET | `/me/gamification` · `/me/xp` · `/me/achievements` · `/me/challenges` | XP, streak, history, achievements, weekly challenges |
| GET · PATCH | `/leaderboards/weekly` · `/me/leaderboard` | Opt-in weekly leaderboard / `{opt_in, alias?}` |
| — | `/admin/*` | Analytics, users, review queue, content, AI, billing, languages, audit (see doc 13 §5) |

## Phase 5 — exams, offline, Chinese ✅
Details: [14-expansion.md](14-expansion.md).

| Method | Path | Description |
|---|---|---|
| GET | `/learner-profiles/{id}/exams` | Exam practice for the profile language (sections, disclaimer, past attempts) |
| POST | `/learner-profiles/{id}/exams/{framework}/attempts` | Start or resume (quota `exam_mocks`) |
| GET | `/exam-attempts/{a}` | State; sweeps expired sections; current section's questions (no keys, no listening scripts) or task |
| POST | `/exam-attempts/{a}/sections/{skill}/start` · `/skip` | Start (sets the server deadline) / skip a section |
| POST | `/exam-attempts/{a}/answers` | `{item_id, option_id}` — changeable until submit; refused after the deadline |
| POST | `/exam-attempts/{a}/sections/{skill}/submit` | Closed sections, `{text}` for writing, `{recording_id}` for speaking |
| GET | `/exam-attempts/{a}/review` | After the test: answers, keys, explanations, scripts |
| GET | `/exam-attempts/{a}/items/{item}/audio` | Signed recording URL while that section is open |
| GET | `/learner-profiles/{id}/lessons/{lesson}/offline-pack` | Lesson pack with text exercises and keys (`no-store`) |
| POST | `/learner-profiles/{id}/offline-sync` | `{attempts:[{client_ref, exercise_id, lesson_id?, answers}], complete_lessons?}` — idempotent, re-checked server-side |

Words now include `pronunciation` (IPA or pinyin); writing tasks include `count_unit`; exercise attempts include `reading_unit`.

## Rate limits
| Limiter | Limit |
|---|---|
| `auth` | 10/min per IP+email |
| `api` | 300/min per user (interactive practice makes several calls per minute) |
| `assessment` | 60/min per user |
| `ai` | 30/min per user ✅ plus per-plan quotas (`QuotaGuard`) ✅ |
| checkout / invitations | 10/min and 20/min per user ✅; webhooks 120/min |
