# 08 — Technical Risks & AI Operating Costs

## Technical risks
| Risk | Impact | Mitigation |
|---|---|---|
| AI scoring is inconsistent / biased across languages | Wrong CEFR, user distrust | Closed items scored deterministically; AI only for open production with rubrics, structured output, evidence spans, periodic human calibration samples; every estimate labelled "AI estimate — not a certification". |
| Item bank too small → overexposure, memorisation | Inflated retests | Exposure counts, randomised selection among near-equal-information items, AI-generated drafts through review to grow bank, item retirement. |
| Uneven language support (esp. Arabic dialects, TTS quality) | Broken UX | Capability matrix gates features per language; variant-aware content; beta badges. |
| Realtime voice cost/latency | Margin erosion | Per-plan minute quotas, session hard caps, server-side reservation, fallback to PTT / text. |
| Provider outage or API change | Feature outage | Provider-agnostic contracts, circuit breaker, fallback chain, contract tests with FakeDriver. |
| Prompt injection via learner input | Policy bypass, data leak | Learner text always in user role, never concatenated into system instructions; outputs schema-validated; no tool access in learner-facing calls. |
| Privacy of voice recordings (biometric-adjacent) | Legal exposure | Private bucket, signed URLs (≤ 5 min), retention policy (default 90 days), consent at first recording, export/delete. |
| Gamification rewards grinding | Fake engagement | XP weighted by correctness and new mastery, diminishing returns on repeats. |
| Plan generator "black box" | Low trust | Every allocation and item has `reason_code` + rationale text rendered in UI. |

## AI operating cost model
Unit prices change frequently; **do not hard-code them** — they live in `ai_models` (`input_cost_per_mtok`, `output_cost_per_mtok`, `audio_cost_per_minute`) and the cost of every call is computed and recorded in `ai_usage_records.cost_micros`. Formula per active learner per month:

```
C = Σ_ops  calls_op × (in_tok_op × P_in + out_tok_op × P_out)  +  voice_min × P_rt_min  +  tts_chars × P_tts  +  stt_min × P_stt
```

Illustrative usage assumptions (to be replaced by measured telemetry):
| Operation | Volume / active learner / month | Notes |
|---|---|---|
| Plan generation (P1 rules) | 0 AI calls | Rule-based, free |
| Placement test (P1) | 0 AI calls per attempt | Pre-generated, reviewed item bank; TTS audio generated once per item and cached |
| Writing evaluation (P3) | ~8 × (1.5k in + 1k out tokens) | Mid-tier model |
| Text tutor (P3) | ~30 turns × (2k in + 300 out) | Cache system prompt; trim history with rolling summary |
| Realtime voice (P3) | Free: 0 · Premium: ~30 min · Pro: ~120 min | **Dominant cost driver** — must be quota-gated |
| Reports & recommendations | ~10 × (3k in + 800 out) | Async, batch-eligible |

Cost-control levers: cache generated content globally (not per user) wherever it is not personal; use smaller models for classification/extraction; batch APIs for non-interactive jobs; prompt caching for static system prompts; hard monthly caps per entitlement; daily anomaly alerts.
