# 14 — Expansion: Chinese, Exam Practice, Personalisation, Offline, Mobile (Phase 5)

What Phase 5 ships, how each part keeps the platform's rules (no fake data, no official-score claims, server-side authority), and its limits.

## 1. Chinese (Mandarin)
- **Catalog:** `zh` (script `Hans`) with one variant, `zh-CN` (simplified). A traditional-script variant needs its own content, so it is not offered. Capabilities: content generation, TTS and STT are supported; writing evaluation is supported (tier 2); realtime voice and pronunciation are **beta**.
- **Content (original):** an A1–A2 course with 4 grammar topics (是 sentences, measure words, verb sentences with time and 在, 吗 questions and 喜欢 + verb), 24 words with pinyin, 11 exercises, a 34-item placement bank (Pre-A1–C2), and writing/speaking tasks.
- **Script-aware text handling.** This is done at the Unicode level, not with Chinese-specific code, so Japanese or Thai would follow the same path:
  - Scripts written without spaces (Han, kana, Thai, Lao, Khmer, Myanmar) are tokenised **per character** (`TextNormalizer::words`). Characters are also how these languages measure length, so word counts become character counts, dictation WER becomes CER, and pronunciation is aligned character by character.
  - Tap-to-translate returns every dictionary word found in the text (`candidates`). Both server and client segment with **greedy longest match**, so 你好 or 学生 is one tappable word.
  - Full-width characters are normalised, and bracketed hints in content (e.g. "(xuéxí)") may use another script.
  - The API reports `count_unit` (`words | characters`) for tasks and reading speed. Writing targets for Chinese are doubled (`length_factor`) and shown as characters.
- **Pinyin:** stored per word (`vocabulary_words.ipa`, exposed as `pronunciation`). It is shown on flashcards, lessons, tap-to-translate, offline packs and in the mobile app.
- **Tone-aware pronunciation estimate** (`transcript_alignment_tone_v1`): a character → pinyin map is derived from the lexicon itself, by aligning each word's characters with its syllables (`PronunciationLexicon`). If the recogniser heard a different character with the *same toneless syllable but a different tone* (买 mǎi heard as 卖 mài), the unit is marked `tone`. This is a tone-sensitive intelligibility signal, not acoustic pitch analysis. Characters missing from the lexicon fall back to `substituted`. Polyphonic characters use their most frequent reading in the lexicon.

## 2. Exam practice
| Exam (format followed) | Language | Sections | Result |
|---|---|---|---|
| IELTS Academic | en | listening, reading, writing, speaking | estimated band per section and overall, with ranges |
| DELE A2 | es | reading, listening, writing, speaking | estimated readiness for A2 |
| DELF A2 | fr | listening, reading, writing, speaking | estimated readiness for A2 |
| Goethe-Zertifikat A2 | de | reading, listening, writing, speaking | estimated readiness for A2 |
| HSK 1 | zh | listening, reading | estimated readiness for level 1 (≈ A1) |

- **Content:** each exam has one original practice form: 12 questions in 4 passage/recording groups, plus writing and speaking tasks where the exam has them (`database/data/exams.php`). Exam names are used only to say which published format is followed. Every screen shows a disclaimer: *not affiliated, results are estimates, not official scores*. A form becomes immutable once someone has taken it, so changing it requires a new form key.
- **Timing is server-authoritative.** Starting a section sets a `deadline_at`. Answers after the deadline (plus 15 s of network grace) are refused, and the section is scored with what was answered in time. Any read sweeps expired sections closed, so closing the tab does not stop the clock. Writing and speaking get 90 s of grace to upload, and are marked `time_over` if later. As on paper, answers can be changed until the section is submitted.
- **Listening:** recordings come from `php artisan lf:exams:synthesize-audio` (one TTS recording per group). Until they exist, the listening section is **not offered** (`audio_not_ready`); it is never scored without audio. Scripts are never sent during the test. They appear in the review afterwards.
- **Scoring:**
  - Closed sections use the placement IRT model (Rasch with a guessing floor, EAP estimation). Unanswered questions count as wrong, and the prior is the exam's nominal level.
  - Writing and speaking use the rubric evaluators. The result sits at the level's anchor with the productive-assessment standard error (0.7, or 0.9 for short answers).
  - **Band scales:** the ability estimate is interpolated piecewise-linearly through an indicative CEFR-aligned conversion table (`exam_score_conversions`; B1 ≈ 4–5, B2 ≈ 5.5–6.5, C1 ≈ 7–8, C2 ≈ 8.5+). Results are reported to the nearest half band, with a ±1 SE range.
  - **Readiness scales:** P(ability > target level cut) = Φ((θ − cut)/SE), labelled *likely* (≥ 0.7), *borderline* (≥ 0.4) or *not yet*.
  - **Overall result:** follows the exam's own published rule. IELTS uses the mean of the four bands rounded to the nearest half band. Level exams require every part, so the combined chance is the product of the section chances. If any part is missing, no overall result is given.
- **Quota:** `exam_mocks` per month (Free 1, Premium/Family 4, Pro 10). Resuming an open attempt is free.

## 3. Personalisation: exercise-format bandit
- **Scope:** `FormatBandit` (`thompson_beta_v1`) only chooses *which exercise format* to use (e.g. cloze vs ordering) among exercises that already passed the rule-based filters for skill, level and recency. Skill time, levels and plan reasons stay rule-based and explainable.
- **Reward ("desirable difficulty"):**
  - finished with a score between 60 % and 90 % = **1**;
  - finished outside that band = **0.5**;
  - skipped, or assigned and not finished within 7 days = **0**.
- **Model:** each format is a Beta distribution updated with fractional rewards. New learners start from a **population prior** (same language and skill), capped at 5 pseudo-observations.
- **Reproducible:** sampling uses a seeded PRNG (learner, skill, day, decision index), so regenerating a plan from the same state gives the same choices.
- **Auditable:** each decision is stored in `personalization_decisions` with the posteriors, the sampled values and Monte-Carlo **propensities**, so other policies can be evaluated offline (inverse propensity weighting). The admin overview shows choices and mean reward per format. Switch it off with `LF_PERSONALIZATION=false`.

## 4. Offline lesson packs (PWA)
- **Download:** "Save for offline" on a lesson stores a pack in **IndexedDB**: lesson text, words with pinyin, grammar, and the text exercises (mcq, cloze, ordering, reading) with answer keys. Audio and speech exercises stay online-only, and the pack says how many were left out. Packs expire after 30 days.
- **Offline use:** the service worker caches `/offline` and its assets, and serves that page for any navigation while offline. `/offline` works without the API: it runs exercises from IndexedDB with **provisional** on-device checking and queues attempts in an outbox.
- **Sync:** `POST /learner-profiles/{id}/offline-sync` is idempotent per `client_ref`. It replays every answer through the normal exercise service, so **the server re-checks everything**: scores, mastery, XP, streaks, the bandit and lesson completion never trust the client. A rejected attempt does not block the others. Sync runs automatically when the connection returns.
- **Privacy:** offline data is cleared on sign-out. Attempts made offline are dated when they sync (the device clock is not trusted).

## 5. Mobile app (Expo)
`mobile/` is an Expo Router app (SDK 58) on **token authentication**. Sign-in sends `device_name`, and the Sanctum token is stored with `expo-secure-store` (keychain/keystore); sign-out revokes it.
- **Screens:** today's plan (activities open in the web app), FSRS flashcard review with pinyin, rewards, and profile/language switch. Strings are en/ar and follow the account's interface language.
- **CI:** typecheck and an Android bundle export (`expo export`).

## 6. Known limitations
- Exam conversion tables are indicative alignments through the CEFR, not calibrated against real exam results; each practice form is short (6 questions per closed section), so ranges are wide. More forms and calibration data are needed before results are more than a rough guide.
- Chinese tone feedback depends on the recogniser transcribing a wrong tone as a homophone with another tone; it is not pitch-contour analysis. Polyphonic characters may be misjudged.
- Offline mode covers text exercises only; tutor, writing, listening and speaking need a connection. Offline attempts count towards the day they sync.
- The mobile app has not been run on a physical device or emulator in CI; store builds (EAS), push notifications, RTL layout mirroring and native offline packs are not done.
- The bandit only personalises exercise format; activity type, skill mix and difficulty remain rule-based by design.
