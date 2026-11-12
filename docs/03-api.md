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

## Later phases (contract sketch)
- **Curriculum (P2):** `GET /courses?language=`, `GET /courses/{id}`, `GET /lessons/{id}`, `POST /lessons/{id}/complete`.
- **Exercises (P2):** `GET /exercises/{id}`, `POST /exercises/{id}/attempts`, `POST /exercise-attempts/{id}/answers`, `POST /exercise-attempts/{id}/finish`.
- **Vocabulary (P2):** `GET /learner-profiles/{id}/vocabulary?due=1`, `POST /flashcard-reviews`, `POST /vocabulary-collections`, `POST /vocabulary/extract` (from lesson text).
- **Grammar (P2):** `GET /grammar-topics?language=&level=`, `GET /grammar-topics/{slug}`, `POST /grammar-topics/{slug}/practice`.
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
| `api` | 120/min per user |
| `assessment` | 60/min per user |
| `ai` (P3) | per-plan, plus hard monthly quota from entitlements |
