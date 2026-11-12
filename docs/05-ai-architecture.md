# 05 — AI Integration Architecture

## Goals
1. Business logic never knows which vendor it talks to.
2. Every call is capability-checked, quota-checked, metered, logged, and reproducible (model + prompt version stored).
3. AI is never the sole source of truth for correctness of closed items.
4. Secrets stay on the server; browsers get only short-lived, scoped tokens.

## Layers

```
Domain services (Assessment, Tutor, Writing, Content)
        │  depend on ↓ contracts only
┌───────▼──────────────────────────────────────────────┐
│ App\Domain\AI\Contracts                               │
│  TextGenerator      ->generate(TextRequest): TextResult (supports JSON-schema structured output)
│  SpeechSynthesizer  ->synthesize(SpeechRequest): AudioResult
│  SpeechTranscriber  ->transcribe(TranscriptionRequest): TranscriptionResult
│  RealtimeSessionIssuer ->issue(RealtimeSessionRequest): EphemeralSession
│  PronunciationAssessor ->assess(...)               (P3; may be a non-LLM vendor)
└───────┬──────────────────────────────────────────────┘
        │ resolved by
┌───────▼────────────┐   ┌──────────────────────────┐
│ AiGateway          │──►│ CapabilityGuard          │ languages × feature × provider
│  - route(operation,│   │ QuotaGuard (P4)          │ entitlements
│    language)       │   │ UsageMeter               │ ai_usage_records (+ cost)
│  - retry/backoff   │   │ Circuit breaker (Redis)  │ fail fast when provider degraded
│  - fallback chain  │   └──────────────────────────┘
└───────┬────────────┘
        │ drivers (config/ai.php + ai_providers/ai_models tables)
  OpenAiDriver (Responses API, audio/speech, audio/transcriptions, realtime client_secrets)
  FakeDriver   (deterministic; used in tests & local dev without keys)
  <future>     AnthropicDriver, AzureSpeechDriver, ElevenLabsDriver, …
```

## Prompt management (P3, schema ready P1)
- `ai_prompts(key)` + `ai_prompt_versions(version, template, json_schema, status)`; services request a prompt by key; the active version is pinned and recorded on every output row.
- All evaluation prompts request **structured JSON** validated against a schema server-side; invalid output → retry once → mark `needs_review`.
- Rubrics are passed in the prompt *and* the model returns per-criterion scores with quoted evidence spans, which are stored (explainability).

## Content generation & review
`AssessmentItemGenerator` (P1, artisan `lf:generate-items`) asks the `TextGenerator` for items in a strict schema, runs deterministic validation (exactly one correct option, no duplicate options, target-language script check, length bounds), and stores them as `review_status = draft`. Only `published` items are served to learners. Admin review UI arrives in P4; until then editors publish via `lf:items:publish`.

## Realtime voice (P3)
1. Client: `POST /conversations/{id}/realtime-session`.
2. Server: policy check → capability check (`realtime_voice` for target language) → quota reservation → `RealtimeSessionIssuer::issue()` with server-side instructions (tutor persona, CEFR constraints) → returns `{client_secret, expires_at, model, voice}` (TTL ≤ 60 s to connect).
3. Client connects via WebRTC directly to the provider with the ephemeral secret.
4. Transcript events are posted back (`/conversations/{id}/events`) and usage reconciled from provider usage events; quota released/settled.
5. Fallback: if `realtime_voice` unsupported or quota exhausted → push-to-talk (STT → text turn → TTS) → text-only.

## Language capability matrix
Seeded in `language_capabilities` and editable by admins. Initial seed (conservative; admins should revalidate against vendor docs and internal evals):

| Feature | en | es | fr | de | it | ar |
|---|---|---|---|---|---|---|
| content_generation | supported | supported | supported | supported | supported | supported (tier 2) |
| tts | supported | supported | supported | supported | supported | beta |
| stt | supported | supported | supported | supported | supported | supported (tier 2) |
| realtime_voice | supported | supported | supported | supported | supported | beta |
| pronunciation | beta | beta | beta | beta | beta | unsupported |
| writing_evaluation | supported | supported | supported | supported | supported | beta |

`beta` features are shown with a "beta" badge; `unsupported` features are hidden and the assessment marks the section `not_available`.
