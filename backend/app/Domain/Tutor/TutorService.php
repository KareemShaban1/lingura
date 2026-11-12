<?php

namespace App\Domain\Tutor;

use App\Domain\AI\AiGateway;
use App\Domain\AI\Data\TextRequest;
use App\Domain\AI\ExplanationLanguage;
use App\Domain\AI\PromptRegistry;
use App\Domain\Billing\QuotaGuard;
use App\Domain\Languages\CapabilityMatrix;
use App\Domain\Practice\TextNormalizer;
use App\Domain\Shared\DomainError;
use App\Domain\Shared\LearningEventRecorder;
use App\Jobs\GenerateConversationReport;
use App\Models\AiConversation;
use App\Models\AiFeedback;
use App\Models\AiMessage;
use App\Models\AiTutor;
use App\Models\AudioRecording;
use App\Models\CefrLevel;
use App\Models\ConversationScenario;
use App\Models\GrammarTopic;
use App\Models\LearnerProfile;
use App\Models\LearningSession;
use App\Models\Skill;
use Illuminate\Support\Facades\DB;

/**
 * AI conversation tutor. Every turn returns a reply in the target language at the learner's level plus
 * structured corrections (explained in the learner's explanation language). Learner text is always sent
 * as user content, never merged into instructions (prompt-injection boundary).
 */
class TutorService
{
    public const START = '[conversation start]';

    public function __construct(
        private readonly AiGateway $ai,
        private readonly PromptRegistry $prompts,
        private readonly CapabilityMatrix $capabilities,
        private readonly LearningEventRecorder $events,
        private readonly QuotaGuard $quota,
    ) {}

    public function start(LearnerProfile $profile, AiTutor $tutor, ?ConversationScenario $scenario, ?CefrLevel $level, string $mode, ?LearningSession $session = null): AiConversation
    {
        if (! $this->capabilities->isUsable($profile->targetLanguage->code, 'content_generation')) {
            throw new DomainError('The AI tutor is not available for this language yet.', 'capability_unavailable', 422);
        }
        $level ??= $this->defaultLevel($profile);

        $conversation = DB::transaction(function () use ($profile, $tutor, $scenario, $level, $mode, $session) {
            // A conversation started from a plan item reuses that item's session, so completing it completes the item.
            $session = $session && $session->learner_profile_id === $profile->id && ! $session->ended_at
                ? $session
                : LearningSession::create(['learner_profile_id' => $profile->id, 'activity_type' => 'conversation', 'started_at' => now()]);

            return AiConversation::create([
                'learner_profile_id' => $profile->id,
                'ai_tutor_id' => $tutor->id,
                'conversation_scenario_id' => $scenario?->id,
                'cefr_level_id' => $level->id,
                'learning_session_id' => $session->id,
                'mode' => $mode,
                'prompt_version' => 'tutor.turn@v'.$this->prompts->active('tutor.turn')->version,
                'started_at' => now(),
            ]);
        });
        $this->events->record($profile, 'tutor.started', $conversation, Skill::where('slug', Skill::SPEAKING)->value('id'));

        // The tutor opens the conversation.
        $this->exchange($conversation, self::START, null, 'text', null);

        return $conversation->fresh(['messages.feedback']);
    }

    /** @return array{learner: AiMessage, reply: AiMessage} */
    public function reply(AiConversation $conversation, string $text, ?AudioRecording $recording = null, string $source = 'text', ?int $responseMs = null, bool $prepaid = false): array
    {
        $this->assertActive($conversation);
        $text = trim(mb_substr($text, 0, config('learning.tutor.max_message_chars')));
        if ($text === '') {
            throw new DomainError('Say or type something first.', 'empty_message', 422);
        }
        if ($prepaid) { // the caller already consumed the turn (voice: before paying for transcription)
            return $this->exchange($conversation, $text, $recording, $source, $responseMs);
        }

        return $this->quota->using($conversation->learnerProfile->user, 'tutor_messages',
            fn () => $this->exchange($conversation, $text, $recording, $source, $responseMs));
    }

    /**
     * Realtime voice sessions stream audio directly between browser and provider; the client posts the
     * final transcripts here so the conversation, report and analytics stay complete.
     */
    public function appendRealtimeTranscript(AiConversation $conversation, string $role, string $text): AiMessage
    {
        $this->assertActive($conversation);
        $text = trim(mb_substr($text, 0, config('learning.tutor.max_message_chars')));
        $message = $conversation->messages()->create([
            'role' => $role === 'learner' ? 'learner' : 'assistant',
            'content' => $text,
            'source' => 'realtime',
            'created_at' => now(),
        ]);
        if ($message->role === 'learner') {
            $conversation->increment('learner_turns');
            $conversation->increment('learner_words', count(TextNormalizer::words($text)));
        }

        return $message;
    }

    public function finish(AiConversation $conversation, int $activeSeconds): AiConversation
    {
        if ($conversation->status !== 'active') {
            return $conversation;
        }
        DB::transaction(function () use ($conversation, $activeSeconds) {
            $conversation->update(['status' => 'completed', 'ended_at' => now()]);
            $session = LearningSession::find($conversation->learning_session_id);
            if ($session && ! $session->ended_at) {
                $elapsed = (int) $session->started_at->diffInSeconds(now());
                $session->update(['ended_at' => now(), 'outcome' => 'completed', 'active_seconds' => max(0, min($activeSeconds, $elapsed + 60, 4 * 3600))]);
                $session->planItem?->update(['status' => 'completed', 'completed_at' => now()]);
            }
        });
        $this->events->record($conversation->learnerProfile, 'tutor.completed', $conversation, Skill::where('slug', Skill::SPEAKING)->value('id'), $conversation->learner_turns);
        GenerateConversationReport::dispatch($conversation->id)->afterCommit();

        return $conversation->fresh();
    }

    /** @return array{learner: ?AiMessage, reply: AiMessage} */
    private function exchange(AiConversation $conversation, string $text, ?AudioRecording $recording, string $source, ?int $responseMs): array
    {
        $conversation->loadMissing(['tutor', 'scenario', 'level', 'learnerProfile.targetLanguage']);
        $profile = $conversation->learnerProfile;
        $language = $profile->targetLanguage;
        $topics = GrammarTopic::where('language_id', $language->id)->pluck('slug')->all();

        $prompt = $this->prompts->render('tutor.turn', [
            'tutor_name' => $conversation->tutor->name,
            'tutor_persona' => $conversation->tutor->persona,
            'language' => $language->name,
            'scenario' => $conversation->scenario?->setup ?? 'An open, friendly conversation about everyday topics the learner chooses.',
            'level' => $conversation->level->label,
            'correction_mode' => match ($conversation->tutor->correction_mode) {
                'every_mistake' => 'point out every mistake',
                'end_only' => 'do not mention mistakes in the reply; still list them in "corrections" for the report',
                default => 'mention at most one important mistake briefly in the reply, list all of them in "corrections"',
            },
            'explanation_language' => ExplanationLanguage::for($profile),
            'topic_slugs' => implode(', ', $topics),
        ]);

        // Recent history as proper chat turns; the newest learner message is the input.
        $history = $conversation->messages()->latest('id')->limit(config('learning.tutor.history_messages'))->get()->reverse()
            ->map(fn (AiMessage $m) => ['role' => $m->role === 'learner' ? 'user' : 'assistant', 'content' => $m->content])->values()->all();

        $result = $this->ai->text(new TextRequest(
            operation: 'tutor.turn',
            instructions: $prompt->text,
            input: $text,
            languageCode: $language->code,
            jsonSchema: $prompt->schema,
            schemaName: 'tutor_turn',
            maxOutputTokens: 900,
            userId: $profile->user_id,
            learnerProfileId: $profile->id,
            history: $history,
            temperature: 0.7,
        ));
        $json = $result->json ?? [];
        $reply = trim((string) ($json['reply'] ?? ''));
        if ($reply === '') {
            throw new DomainError('The tutor could not reply. Please try again.', 'ai_empty_reply', 503);
        }

        return DB::transaction(function () use ($conversation, $profile, $text, $recording, $source, $responseMs, $json, $reply, $topics) {
            $learner = null;
            if ($text !== self::START) {
                $learner = $conversation->messages()->create([
                    'role' => 'learner',
                    'content' => $text,
                    'audio_recording_id' => $recording?->id,
                    'natural_alternative' => trim((string) ($json['natural_alternative'] ?? '')) ?: null,
                    'source' => $source,
                    'response_ms' => $responseMs !== null ? min($responseMs, 600_000) : null,
                    'created_at' => now(),
                ]);
                foreach ($json['corrections'] ?? [] as $c) {
                    // Keep only corrections that quote the learner's actual words.
                    if (($c['original'] ?? '') === '' || ! str_contains(mb_strtolower($text), mb_strtolower($c['original']))) {
                        continue;
                    }
                    AiFeedback::create([
                        'ai_conversation_id' => $conversation->id, 'ai_message_id' => $learner->id, 'learner_profile_id' => $profile->id,
                        'source' => $source === 'text' ? 'tutor' : 'speaking',
                        'type' => in_array($c['type'] ?? '', ['grammar', 'vocabulary', 'word_choice', 'spelling'], true) ? $c['type'] : 'grammar',
                        'topic_slug' => in_array($c['topic_slug'] ?? '', $topics, true) ? $c['topic_slug'] : null,
                        'original' => (string) $c['original'], 'correction' => (string) ($c['correction'] ?? ''),
                        'explanation' => (string) ($c['explanation'] ?? ''), 'created_at' => now(),
                    ]);
                }
                $conversation->update([
                    'learner_turns' => $conversation->learner_turns + 1,
                    'learner_words' => $conversation->learner_words + count(TextNormalizer::words($text)),
                    'speaking_seconds' => $conversation->speaking_seconds + (int) round(($recording?->duration_ms ?? 0) / 1000),
                ]);
            }
            $assistant = $conversation->messages()->create([
                'role' => 'assistant',
                'content' => $reply,
                'source' => $source,
                'created_at' => now(),
            ]);

            return ['learner' => $learner?->load('feedback'), 'reply' => $assistant, 'new_vocabulary' => $json['new_vocabulary'] ?? []];
        });
    }

    private function defaultLevel(LearnerProfile $profile): CefrLevel
    {
        $speaking = $profile->skillProfiles()->whereHas('skill', fn ($q) => $q->where('slug', Skill::SPEAKING))->where('status', 'assessed')->first();
        $latest = $profile->assessmentAttempts()->where('status', 'completed')->latest('completed_at')->first();

        return CefrLevel::find($speaking?->cefr_level_id ?? $latest?->overall_level_id ?? $profile->self_reported_level_id)
            ?? CefrLevel::where('code', 'A1')->first();
    }

    private function assertActive(AiConversation $conversation): void
    {
        if (! $conversation->isActive()) {
            throw new DomainError('This conversation has ended.', 'conversation_closed');
        }
    }
}
