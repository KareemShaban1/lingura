# 09 — Placement Assessment Methodology (engine `irt_v1`)

> All results are **AI-platform estimates aligned to CEFR descriptors**. They are not official certifications and are labelled as such in the UI and API (`is_official: false`).

## 1. Scope per phase
| Skill | Phase 1 | Method |
|---|---|---|
| Grammar | ✅ | Adaptive closed items (MCQ / cloze) |
| Vocabulary | ✅ | Adaptive closed items (meaning-in-context, collocation) |
| Reading | ✅ | Adaptive passage + comprehension item |
| Listening | ✅ when the item bank has audio for the language (TTS capability) | Adaptive audio + comprehension item; otherwise section is `not_available` |
| Writing | Phase 3 | Rubric-scored by AI with human calibration |
| Speaking | Phase 3 | STT + pronunciation + rubric scoring |

Each skill is estimated **independently**; there is no shared ability parameter across skills.

## 2. Measurement model
Item response function (Rasch with a fixed guessing floor for selected-response items):

```
P(correct | θ, b, k) = c + (1 − c) / (1 + e^−(θ − b)),    c = 1 / k   (k = number of options)
```

- `θ` — learner ability for one skill (logits). `b` — item difficulty (logits).
- Nominal item difficulty is anchored to its CEFR target level: Pre-A1 −3, A1 −2, A2 −1, B1 0, B2 +1, C1 +2, C2 +3. Authors/calibration may adjust `b` within ±0.5 of the anchor.

## 3. Ability estimation
Expected-a-posteriori (EAP) over a grid θ ∈ [−4, 4], step 0.05.
- Prior: Normal(μ₀, 1.5²). μ₀ = anchor of the learner’s self-reported level, or −1.0 (A2) when unknown. The prior is weak (σ = 1.5) so that it only matters for the first few items.
- `θ̂` = posterior mean; `SE` = posterior standard deviation.

## 4. Item selection
Among published, unseen items for (language, skill): choose the item maximising Fisher information at θ̂

```
I(θ) = (P′)² / (P (1 − P))
```

To limit exposure, one item is drawn uniformly from the top-3 by information (ties broken by lower `exposure_count`). For reading/listening, a stimulus is never repeated within an attempt.

## 5. Stopping rule (per section)
Stop when **any** holds: `answered ≥ max_items`; `answered ≥ min_items AND SE ≤ target_standard_error`; no eligible items remain. Defaults (configurable in `assessment_sections`): grammar & vocabulary 5–10 items, SE ≤ 0.55; reading & listening 4–7 items, SE ≤ 0.65.

## 6. From θ to CEFR
Cut points sit halfway between adjacent anchors:

| θ̂ | < −2.5 | −2.5…−1.5 | −1.5…−0.5 | −0.5…0.5 | 0.5…1.5 | 1.5…2.5 | ≥ 2.5 |
|---|---|---|---|---|---|---|---|
| Level | Pre-A1 | A1 | A2 | B1 | B2 | C1 | C2 |

Interpretation: the learner’s ability is closer to the difficulty of items at that level than to either neighbour.
Confidence: `high` if SE ≤ 0.5, `medium` if SE ≤ 0.8, else `low`.
Scaled score shown to learners: `round((θ̂ + 4) / 8 × 100)`, clamped 0–100, explained as "position on the platform’s ability scale", not a percentage correct.

## 7. Overall level (never an arithmetic average)
1. Take the CEFR ordinals of all **assessed** skills (status `completed`).
2. Overall = **lower median** of those ordinals (robust to one outlier skill; never rounds up).
3. Overall is flagged `provisional` when fewer than 4 skills were assessed, when any assessed skill has `low` confidence, or when neither productive skill (speaking/writing) was assessed — which is always true in Phase 1, and the UI says so explicitly.

## 8. Strengths, weaknesses, recommendations (rule set `findings_v1`)
- **Strength:** skill ordinal ≥ overall + 1.
- **Weakness:** skill ordinal ≤ overall − 1, or skill confidence low.
- **Topic weakness (unexpected error):** an incorrect answer on an item tagged with a topic (`grammar_topic_slug`) whose CEFR level is at or below the learner’s estimated level in that skill. Topics are ranked by error count. (With the Phase 1 starter bank most topics appear once per attempt, so a single unexpected error is flagged; the threshold becomes configurable as the bank grows.)
- **Recommendation:** each weakness maps to an activity (`reason_code`), and every section not assessed produces a "complete this section" recommendation.

## 9. Data retained
Every answer (item, option chosen, correctness, response time, θ and SE after the answer), per-section final θ/SE/level, and `engine_version`. Skill profile history is append-only (`skill_scores`), so later engine versions can be compared against earlier attempts.
