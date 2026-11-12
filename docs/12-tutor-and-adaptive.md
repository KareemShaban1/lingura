# 12 — AI Tutor, Speech, Writing & Adaptive Engine (Phase 3)

This document describes what Phase 3 actually ships, how each AI-assisted judgement is constrained, and where the honest limits are.

## 1. Principles carried into Phase 3
- **Provider-agnostic.** Domain services call `AiGateway` (`text`, `transcribe`, `speech`, `realtimeSession`); drivers (`OpenAiDriver`, deterministic `FakeDriver`) are swappable per operation via `ai_models`.
- **Keys stay on the server.** Browser voice uses a short-lived ephemeral client secret minted by `POST /conversations/{id}/realtime-session`; no permanent key reaches the client.
- **AI never decides correctness alone.** Exercise answers stay deterministic (`AnswerChecker`); AI corrections must quote the learner's own text or they are dropped; writing corrections must anchor to an exact span.
- **Estimates, not certificates.** Every AI score is labelled as an estimate, writing/speaking placement contributes with a wider standard error, and a sample of evaluations is flagged for human calibration.
- **Everything is quota-checked on the backend** (`throttle:ai` 30/min, tutor daily turn cap, capability matrix checks).

## 2. Prompt registry
`ai_prompts` / `ai_prompt_versions` hold versioned templates and JSON schemas, seeded from `database/data/prompts.php`:

| Key | Purpose | Output |
|---|---|---|
| `tutor.turn` | One tutor reply + corrections of the learner's last message | `{reply, corrections[{original, corrected, type, explanation}], new_words[]}` |
| `tutor.realtime` | Instructions for the realtime voice session | free text |
| `tutor.report` | End-of-conversation report | strengths, mistakes, vocabulary, scores per criterion |
| `writing.evaluate` | Rubric evaluation of a text | 5 criteria (1–5), corrections with `{original, corrected, explanation}`, corrected text |
| `speaking.evaluate` | Rubric evaluation of a transcript | fluency, range, accuracy, coherence, task achievement |

`PromptRegistry::render()` substitutes variables and returns `{text, schema, versionTag}`; the tag (e.g. `tutor.turn@v1`) is stored on every message, evaluation and report so results stay traceable when prompts change. Active versions are cached as **plain attributes** (never serialized models) for 5 minutes; `flush($key)` invalidates.
Learner input is placed inside an explicit boundary and the prompt instructs the model to treat it as data (prompt-injection hygiene). Explanations are written in the learner's interface language (`ExplanationLanguage`).

## 3. AI conversation tutor
- **Catalog:** 4 original tutor personas (`ai_tutors`: patient, lively, precise, business-like) × 10 scenarios (`conversation_scenarios`: café, directions, job interview, doctor, …) with level ranges. Language-agnostic: scenarios carry translated titles/briefs, not language-specific assumptions.
- **Modes:** `text`, `voice` (push-to-talk: record → upload → STT → turn), `realtime` (WebRTC with ephemeral secret; transcripts posted back to `/realtime-transcript` so reports and mistake detection work the same way).
- **Turn flow (`TutorService`):** last 16 messages as history → `tutor.turn` → schema-validated JSON → corrections kept only if `original` occurs in the learner's message → `ai_feedback` rows (`source=tutor`) → reply stored; TTS on demand via `GET /ai-messages/{id}/audio` (cached, signed URL).
- **Limits:** message ≤ 1000 chars, daily turn cap 150 (`LF_TUTOR_DAILY_TURNS`, moves to plan entitlements in Phase 4), throttle 30/min, an existing open conversation for the same tutor/scenario is reused.
- **Report:** `finish` closes the conversation (time credited from the linked learning session, capped at real elapsed time) and queues `GenerateConversationReport` on the `ai` queue. The report lists strengths, mistakes with corrections, useful vocabulary and criterion scores, and is labelled *AI feedback, not an official assessment*. Failed reports can be retried.

## 4. Speech pipeline
- **Upload:** `POST /learner-profiles/{id}/recordings` — multipart, ≤ 4 MB / 120 s, MIME **sniffed** with `finfo` (webm/ogg/mp4/mpeg/wav), stored on the private disk, SHA-256 checksum, purpose `conversation | pronunciation | speaking_assessment`. Playback only via 5-minute signed URLs.
- **Retention:** `lf:recordings:purge` (daily 03:40) deletes audio files older than `LF_RECORDING_RETENTION_DAYS` (90) and keeps the row (transcript and scores remain, path emptied).
- **Transcription:** `TranscriptionService` → `speech_transcriptions` (text, language, confidence, model, duration).
- **Pronunciation (`transcript_alignment_v1`):** the recording is transcribed *without* priming the recogniser with the reference sentence; the transcript is aligned word-by-word with the reference. Output: accuracy (words understood), completeness, per-word status (`correct | mispronounced | omitted | inserted`). This measures **intelligibility, not phoneme accuracy**, and the UI says so. Phoneme scoring can be added behind `PronunciationAssessor` later.
- **Capability gating:** "Repeat after me" exercises need `stt` + `pronunciation` support in the capability matrix; Arabic is marked unsupported for pronunciation, so those exercises are hidden there rather than producing unreliable scores.

## 5. Writing
- **Tasks:** `production_tasks` (mode `written|spoken`, type, prompt, min/max words, `is_placement`): 7 task types × 6 languages, original prompts.
- **Submission:** `POST /writing-tasks/{task}/submissions` stores text + `TextStats` (words, sentences, words/sentence, lexical variety) and queues `EvaluateWriting`.
- **Evaluation (`WritingEvaluator`):** `writing.evaluate` → 5 rubric criteria (task achievement, grammar, vocabulary, coherence, organization) 1–5, an estimated CEFR band, and corrections. Every correction is anchored by locating its `original` in the submitted text (`start`, `length`); corrections that cannot be located are discarded. Out-of-range scores are rejected. A configurable sample (5 %) is flagged `needs_human_review` for calibration.
- **Feedback UI:** annotated original text, corrected version, criterion bars with comments, rewrite (“write a new version”) loop.

## 6. Productive placement sections
Placement now has writing and speaking sections (`/assessment-attempts/{id}/writing`, `/speaking`). `next` returns either an item or a `task`. Each productive section is scored by rubric (`WritingEvaluator` / `SpeakingEvaluator`), converted to an ability estimate with a **standard error of 0.7** (0.9 for very short responses, which also forces *low* confidence), and contributes to the overall level through the same **lower-median** rule as receptive skills — never an arithmetic average. Learners can skip either section; skipped skills are reported as *not assessed*.

## 7. Adaptive engine v1 (`App\Domain\Adaptive\AdaptiveEngine`)
Rule-based and explainable; every threshold lives in `config/learning.php → adaptive`.

| Signal | Rule | Effect |
|---|---|---|
| Mistake patterns | Same category/topic ≥ 3 times in 30 days across tutor, writing and exercises → `learner_mistake_patterns` | Topic prioritised; plan item reason `recurring_mistake` |
| Difficulty offset | Per-skill practice accuracy over 14 days, ≥ 15 answers: ≥ 85 % → +1 level, < 50 % → −1 level (clamped) | Content selection level shifts |
| Revision | Grammar topic not practised for 7 days with mastery < 0.8 | Item reason `topic_due_for_revision` |
| Readiness | ≥ 80 % on next-level practice (≥ 10 answers) | `level_progress` shows *ready for a progress check* (not a level change) |

The engine refreshes after every finished exercise attempt (`RefreshAdaptiveState`, a unique queued job) and at the end of the tutor-report and writing-evaluation jobs. When signals change it creates a new plan version with trigger `adaptive`, at most once per 24 h, so plans do not churn. Level changes still only come from assessments.

## 8. Data model added
`ai_prompts`, `ai_prompt_versions`, `ai_tutors`, `conversation_scenarios`, `ai_conversations`, `ai_messages`, `ai_feedback` (`source` tutor|writing|speaking), `ai_conversation_reports` (+ items), `audio_recordings`, `speech_transcriptions`, `pronunciation_results`, `pronunciation_word_results`, `production_tasks`, `writing_submissions`, `writing_evaluations` (+ criteria, corrections with span), `learner_mistake_patterns`; new columns on `assessment_attempt_sections`, `learning_plan_items` (`production_task_id`, `conversation_scenario_id`), `exercise_answers` (`audio_recording_id`), `exercise_questions` (`reference_text`).

## 9. Known limitations
- Tested against the deterministic `FakeDriver`; live OpenAI calls (Responses API JSON schema, transcription, TTS) and the realtime WebRTC handshake are implemented but **not exercised in CI** — verify with a real key in staging before launch.
- Text tutor replies are returned per turn (JSON), not token-streamed; streaming via SSE is a planned improvement.
- Pronunciation is intelligibility-based (see §4); no phoneme/prosody scoring yet.
- Uploads are not virus-scanned yet (MIME sniffing, size and duration limits are enforced). Add ClamAV or the storage provider's scanner before production.
- Human review of flagged evaluations has no UI until the Phase 4 admin.
