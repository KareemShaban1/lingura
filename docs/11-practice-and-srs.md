# 11 — Practice, Content and Spaced Repetition (Phase 2)

## 1. Content model
| Concept | Tables | Notes |
|---|---|---|
| Localised strings | `translations` (type, id, field, locale) | Any content field in any interface locale; falls back to English. |
| Grammar | `grammar_topics`, `grammar_topic_examples` | Per-language topics with level, bilingual explanation, glossed examples. |
| Vocabulary | `vocabulary_words`, `vocabulary_forms`, `vocabulary_examples`, `vocabulary_relations` | Inflected forms make words recognisable in running text. |
| Exercises | `exercises`, `exercise_questions`, `exercise_options`, `exercise_accepted_answers`, `exercise_objectives` | Every exercise has language, CEFR level, skill, objectives, questions, answers/criteria, difficulty, estimated time; explanations where authored. |
| Courses | `courses` → `course_units` → `lessons` (+ `lesson_objectives`, `lesson_words`, `lesson_exercises`) | |

Authored packs live in `backend/database/data/content/{lang}.json` (generated from `build_packs.py`) and are imported by `ContentPackImporter`:
- idempotent (keyed by slug/lemma/key) and validated (script check, cloze gaps, distinct options, known topics/words/exercises);
- **published content with learner attempts is immutable** — editing it is refused; retire it and publish a new key;
- ordering exercises are never displayed pre-solved.

The packs cover two courses per language for en/ar/es/fr/de/it/zh:
- **Foundations** (`{lang}.json`, Pre-A1→A2): 4 grammar topics, 24 words, 11 exercises and a 4-lesson course.
- **Intermediate** (`{lang}-b1.json`, B1→B2, authored in `content_b1.py`): 4 B1 grammar topics (e.g. preterite vs imperfect, subjunctive, conditional and por/para for Spanish; 了, 过, 比 and 把 for Chinese), 20 words, 11 exercises (including a B2 reading and a B1 listening) and a 4-lesson course.

Courses are ordered by their starting level (`position` = the level's ordinal). The "next lesson" skips courses whose target level the learner has already reached (latest placement, else self-reported level) while a course at their level still has lessons; after that, earlier courses are offered again. This is a foundation for the review workflow, not a complete curriculum: native-speaker review is required before production. Single exercises can also be written in the in-app editor (see doc 13, §5.1).

## 2. Exercise types and deterministic scoring
| Type | Learner action | Scoring |
|---|---|---|
| `mcq`, `reading`, `listening` | choose an option | exact key |
| `cloze` | type the missing word | normalised match = 1; differs only in accents/diacritics (or Arabic hamza/alef/yaa forms) = 0.5 + `check_accents` hint; else 0 |
| `ordering` | tap words in order | exact sequence = 1; partial credit = correct-prefix share |
| `dictation` | type the sentence heard | word accuracy = 1 − word edit distance / reference length; correct at ≥ 0.9 |

Normalisation (`TextNormalizer`) is script-aware but language-agnostic: Unicode NFC, case folding, punctuation removal, Arabic tatweel and **tashkeel are always optional** (learners are never required to type short vowels). No AI is involved in grading Phase 2 exercises.

Practice reveals correctness, the correct answer and the explanation immediately after each answer (unlike placement). Answers are idempotent per (attempt, question).

## 3. Mastery, progress and reading speed
- **Grammar mastery** per topic: exponentially weighted accuracy, `m ← m + 0.3·(score − m)`, prior 0.5. Shown only after 3 answers. Topics with mastery < 0.6 (≥ 3 answers) feed the next plan version as `practice_topic_weakness`.
- **Daily rollups** (`user_progress`): questions answered/correct, exercises completed, words reviewed per skill and local date, written with one atomic upsert.
- **Reading speed**: words in the passage ÷ time until the first answer. Values outside 20–600 wpm are discarded as unreliable (skimming or an idle tab).
- **Study time** always flows through `learning_sessions`; credited time is capped by the server at real elapsed time.

## 4. Spaced repetition (FSRS-4.5)
`App\Domain\Vocabulary\Fsrs` implements FSRS-4.5 with the published default weights and 90 % target retention:
- retrievability `R(t,S) = (1 + 19/81 · t/S)^−0.5`; interval at 90 % retention ≈ stability (days), capped at 365;
- ratings Again/Hard/Good/Easy update difficulty (mean-reverting, clamped 1–10) and stability (success and lapse formulas);
- *Again* brings the card back after 10 minutes in the same session.

Every review is appended to `flashcard_reviews` (rating, state, retrievability, new stability/difficulty, elapsed and scheduled days) so the parameters can be re-fitted on platform data later.

Metrics: a word is **mastered** when it is in review state with stability ≥ 21 days; **retention (30 days)** = share of scheduled reviews (state `review`) not rated *Again*.

Words enter a learner's deck when a lesson is completed (automatic extraction from lesson content), when tapped in a reading, or from the dictionary search (`vocabulary_forms` lets inflected forms like *compramos* resolve to *comprar*).

## 5. How plans use content (`ContentSelector`)
| Plan block | Bound to |
|---|---|
| grammar | weak topic's exercise → next uncompleted course lesson → level-appropriate grammar exercise |
| vocabulary | flashcard review if ≥ 5 cards are due → vocabulary exercise → next lesson |
| reading / listening | reading or listening/dictation exercise at the learner's level (±1), not completed in 14 days |
| revision | flashcard review if the learner has cards → retry an exercise scored < 80 % |
| speaking / writing | self-guided until Phase 3 (AI evaluation) |

Each piece of content is used at most once per plan version, and an item's skill follows its content.
