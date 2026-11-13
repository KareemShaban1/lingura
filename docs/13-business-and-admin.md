# 13 — Billing, Family Groups, Gamification & Administration (Phase 4)

What Phase 4 ships, the rules it enforces, and where its limits are.

## 1. Plans, entitlements and quotas
**Plans** (`subscription_plans`) and **limits** (`plan_entitlements`) are seeded from `config/billing.php` by `BillingPlanSeeder` and edited afterwards by admins (the database is the runtime source of truth).

| Feature | Period | Free | Premium / Family | Pro |
|---|---|---|---|---|
| `tutor_messages` | day | 10 | 150 | 400 |
| `realtime_minutes` | month | 0 | 30 | 120 |
| `writing_evaluations` | month | 3 | 40 | 100 |
| `pronunciation_checks` | day | 10 | 100 | 300 |
| `placement_productive` (AI-scored placement sections) | month | 2 | 10 | 20 |
| `target_languages` | standing | 1 | 3 | unlimited |
| `exam_mocks` (Phase 5) | month | 1 | 4 | 10 |

Placement (closed sections), plans, lessons, exercises, grammar and flashcards are free on every plan. Prices: Premium $12.99/mo · $99.99/yr, Pro $24.99/mo · $199.99/yr, Family (6 seats) $19.99/mo · $159.99/yr. Displayed prices come from the database; **the charged amount is the provider price** (`provider_price_id`), so keep them in sync.

**Resolution** (`EntitlementService`): the highest-ranked plan among the user's own live subscription and the live seat subscriptions of groups they belong to; otherwise Free. Live = `trialing | active | past_due` (past due keeps access during the provider's retry window).

**Enforcement** (`QuotaGuard`): counters in `usage_counters (user, feature, period_start)`, day/month periods in the learner's timezone. Consumption is one conditional `INSERT … ON CONFLICT … WHERE used + n <= limit RETURNING`, so concurrent requests can never overspend. `using()` refunds the units when the operation throws: learners are never charged for our failures (invalid AI output, provider outage, disabled provider). Exceeding a limit returns `429 {code: "quota_exceeded", details: {feature, limit, resets_at, plan}}`; the UI turns this into a toast with **See plans**.

Where quotas apply: tutor text turns; voice turns (charged *before* transcription, so an exhausted quota never pays for STT); realtime sessions; writing submissions (refunded if the evaluation job finally fails); pronunciation checks; AI-scored placement sections (not charged when the evaluation was unavailable); adding a language (existing languages stay usable after a downgrade).

**Realtime voice** streams directly between browser and provider, so it cannot be metered per second server-side. Each session is **pre-charged as a block** (`LF_REALTIME_BLOCK_MINUTES`, default 5, or what is left); the response carries `max_seconds` and the client hangs up at the end of the block. Unused minutes of a block are not refunded (a client-reported duration would be untrusted).

## 2. Payments
`BillingGateway` is provider-agnostic: `StripeGateway` (Stripe REST over `Http`, secret key server-side only) and `FakeGateway` (local/testing; refused in production).

- **Checkout:** `POST /billing/checkout {plan}` creates the Stripe customer once, then a hosted Checkout Session with `metadata` (`user_id`, `plan`, `organization_id`) copied onto the subscription. Only reads are retried (a retried create could duplicate charges).
- **Webhooks:** `POST /webhooks/billing` verifies `Stripe-Signature` (HMAC-SHA256, 5-minute tolerance), stores the event in `billing_events` (unique per provider event id → idempotent), and **re-fetches the subscription** from Stripe instead of trusting the event body, so out-of-order or replayed events converge. Handled: `checkout.session.completed`, `customer.subscription.*`, `invoice.paid|payment_succeeded|payment_failed`. Processing errors mark the event `failed` and return non-2xx so Stripe retries; admins see failed events.
- **Lifecycle:** cancel/resume at period end (in-app), Stripe customer portal for payment methods and invoices, payments history, `lf:billing:reconcile` (hourly) ends lapsed complimentary grants. Status changes notify the learner (mail + in-app): activated, cancellation scheduled, resumed, ended, payment failed/succeeded.
- **Complimentary grants:** support staff with `billing.manage` can grant a time-boxed plan with a reason (audited); grants never count toward MRR.
- **Local testing:** with `BILLING_DRIVER=fake`, checkout redirects to `/billing/return?session_id=fake_cs_…`, which confirms the session (single use, owner only).

Stripe setup: create the six prices, set `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_*`, `BILLING_DRIVER=stripe`, and point a webhook endpoint at `/api/v1/webhooks/billing` with the events above.

## 3. Family groups
`organizations` (type `family`; schools/companies later), `organization_members` (owner/admin/member), `organization_invitations`.
- Buying a Family plan creates the group; members inherit its plan while it is live. Seats = members + pending invitations, capped by the plan's `seats` and counted under a row lock.
- Invitations are emailed; the token is stored only as a SHA-256 hash, expires in 7 days, and works only for the invited email address. The owner may also copy the link.
- Privacy: members see each other's **names only**; emails are visible to the group's admins; no learning data is shared through a group.
- Removing a member or the plan ending drops members back to their own plan (or Free). Progress is never deleted.

## 4. Gamification
Driven exclusively by recorded learning events (`LearningEventRecorder` → `GamificationService`, inside a savepoint so a rewards failure never undoes learning).

| Outcome | XP | Once per |
|---|---|---|
| Exercise finished | 10 × score (0 if nothing correct) | exercise per day |
| Lesson completed | 20 | lesson |
| Flashcard remembered (rating ≥ 2) | 1 | card per day |
| Tutor conversation finished with ≥ 4 learner turns | 15 | conversation |
| Writing evaluated (practice, not placement) | 10 + 2 × band | submission |
| Placement completed | 30 | attempt |

Each award has a unique `dedupe_key`, so replays and double submissions earn nothing. No XP for time spent or app opens.
- **Streaks:** a day counts with ≥ 1 XP-earning outcome or a completed study session of ≥ 5 credited minutes. One freeze is earned per 7 consecutive days (max 2) and covers a missed day automatically. This is now the single streak shown everywhere (dashboard and Rewards).
- **Achievements:** 13 original achievements (en/ar) on lessons, exercises, words in long-term review, streaks, conversations, texts, placement and total XP; some grant bonus XP.
- **Weekly challenges:** three per ISO week drawn deterministically from a pool (XP, exercises, flashcards, minutes, conversations), progress computed from the ledger; completion grants bonus XP once.
- **Leaderboard:** weekly XP, **opt-in only** (off by default), showing an alias or "First L." — never full names or other data. Suspended accounts are excluded.

## 5. Administration
Area at `/admin`, gated by `admin.access`; each section needs its own permission, checked by the `permission:` middleware **before** route-model binding (unauthorised staff cannot probe which records exist). Every mutation is written to `audit_logs`.

| Section | Permission | What it does |
|---|---|---|
| Overview | `analytics.view` | Users (total, new, DAU/WAU/MAU), MRR and paying/complimentary/cancelling/past-due counts, 7-day engagement, 30-day AI cost by day and operation, weekly retention cohorts |
| Users | `users.manage` (+ `billing.manage` for grants) | Search, suspend/reactivate (revokes API tokens), roles (only super admins grant or remove admin roles; nobody edits their own), complimentary grants |
| Review queue | `content.review` | Approve/reject AI-drafted assessment items; score the 5 % writing calibration sample (shown without learner identity) and see AI–human agreement per prompt version |
| Content | `content.manage` | Per-language summary, retire/restore exercises (retirements survive re-imports), validate (dry run) or import a content pack through the same importer as the seeders |
| Editor | `content.manage` (publish: `content.review`) | Write or edit single exercises in the browser; see §5.1 |
| AI | `ai.manage` | Provider and model kill switches (effective within a minute; learners see "temporarily unavailable" and are refunded), unit prices, prompt versions: draft → activate (cache flushed; drafts that drop template variables are rejected) |
| Billing | `billing.manage` | Plan prices, provider price ids, visibility and limits; subscriptions; webhook events |
| Languages | `languages.manage` | Capability matrix (supported/beta/unsupported per feature) |
| Audit log | `audit.view` | Filterable log of administrative and billing actions |

### 5.1 Exercise editor
Single exercises can be authored in the browser (`/admin/editor`) instead of a JSON pack. Drafts use the pack format, so everything an editor writes goes through the same importer rules.
- **Lifecycle:** `draft → in_review → published`, or `in_review → rejected` (with required notes) `→ draft` again. Drafts are editable only while `draft` or `rejected`.
- **Checks:** field rules per exercise type (key must start with the language code; first option is the answer; cloze needs `___`; ordering lists chunks in the correct order), then **Check** runs the real importer in a rolled-back transaction, so duplicate options, script mismatches, unknown topics or an immutable key are reported before review. Nothing is written.
- **Four-eyes:** publishing needs `content.review` and a reviewer **other than the author** (`self_review`, 403). Publishing imports the draft for real; the exercise appears in the practice library (listening and dictation once `lf:content:synthesize-audio` has produced audio).
- **Editing existing exercises:** "Edit in editor" on the Content page exports the exercise to a draft. If learners have already answered it, the draft gets a new key (`…-v2`) and publishing **retires the original**, so past answers keep pointing at the questions they were given.
- Every step (`admin.content_draft_created|updated|submitted|rejected|published`) is audited, and publishing also records a content review.
- Courses and lessons are still authored as packs: the editor covers exercises only.

## 6. Known limitations
- Stripe is implemented against its REST API and tested with recorded responses (`Http::fake`); it has **not** been run against a live Stripe account. Do a test-mode run before launch (checkout, renewal, failed payment, cancellation, portal).
- No proration or in-place plan switching yet: changing plan goes through the Stripe portal (when configured) or cancel + new checkout.
- Taxes/VAT, coupons beyond Stripe promotion codes, refunds UI and dunning emails beyond Stripe's own are not implemented.
- Family is the only organization type; school/company seats, teacher dashboards and pooled quotas are future work.
- Realtime voice is charged in blocks without refunds (see §1).
- The in-app editor covers single exercises; courses, lessons, grammar topics and vocabulary are still authored as JSON packs.
- Analytics are computed on request from raw tables; at scale they should move to nightly rollups.
