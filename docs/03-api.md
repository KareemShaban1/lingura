# 03 — Backend Modules & REST API

Base URL: `/api/v1`. JSON only. Auth: Sanctum (SPA cookie + `X-XSRF-TOKEN`, or `Authorization: Bearer` for mobile).
Errors follow a single envelope:

```json
{ "message": "Human readable", "code": "quota_exceeded", "errors": { "field": ["..."] } }
```

Pagination: cursor-based for feeds (`?cursor=`), page-based for admin tables (`?page=&per_page=` max 100).
Machine-readable spec: `backend/openapi.yaml` (served at `GET /api/v1/openapi.yaml`). ✅ = implemented in Phase 1.

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

## Later phases (contract sketch)
- **Speech (P3):** `POST /audio-recordings` (multipart, ≤ 2 MB / 60 s, mime-sniffed), `GET /audio-recordings/{id}/transcription`, `POST /pronunciation-assessments`.
- **Tutor (P3):** `GET /tutors`, `GET /scenarios`, `POST /conversations`, `POST /conversations/{id}/messages` (SSE stream), `POST /conversations/{id}/realtime-session` (ephemeral token, quota-checked), `POST /conversations/{id}/finish` → report job, `GET /conversations/{id}/report`.
- **Writing (P3):** `POST /writing-submissions`, `GET /writing-submissions/{id}/evaluation`.
- **Billing (P4):** `GET /billing/plans`, `POST /billing/checkout`, `POST /billing/portal`, `GET /billing/subscription`, `GET /billing/usage`, `POST /webhooks/stripe` (signature-verified).
- **Gamification (P4):** `GET /me/xp`, `/me/achievements`, `/leaderboards/{scope}`, `/challenges`.
- **Admin (P4):** `/admin/users`, `/admin/languages`, `/admin/language-capabilities`, `/admin/assessment-items` (+ `/review`), `/admin/courses|lessons|exercises`, `/admin/ai/providers|models|prompts`, `/admin/subscriptions`, `/admin/analytics/*`, `/admin/audit-logs` — all behind `role:admin|content_editor|support` + per-ability policies.
- **Exams (P5):** `/exam-frameworks`, `/exam-attempts`, `/exam-attempts/{id}/sections/{s}`.

## Rate limits
| Limiter | Limit |
|---|---|
| `auth` | 10/min per IP+email |
| `api` | 300/min per user (interactive practice makes several calls per minute) |
| `assessment` | 60/min per user |
| `ai` (P3) | per-plan, plus hard monthly quota from entitlements |
