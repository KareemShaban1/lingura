<?php

namespace App\Http\Controllers\Api;

use App\Domain\AI\AiGateway;
use App\Domain\AI\Data\RealtimeSessionRequest;
use App\Domain\AI\Data\SpeechRequest;
use App\Domain\AI\ExplanationLanguage;
use App\Domain\AI\PromptRegistry;
use App\Domain\Billing\EntitlementService;
use App\Domain\Billing\QuotaGuard;
use App\Domain\Languages\CapabilityMatrix;
use App\Domain\Media\MediaUrlService;
use App\Domain\Shared\DomainError;
use App\Domain\Speech\TranscriptionService;
use App\Domain\Tutor\ReportGenerator;
use App\Domain\Tutor\TutorService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\LevelResource;
use App\Jobs\GenerateConversationReport;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiTutor;
use App\Models\AudioRecording;
use App\Models\CefrLevel;
use App\Models\ConversationScenario;
use App\Models\LearnerProfile;
use App\Models\LearningSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class TutorController extends Controller
{
    public function __construct(private readonly TutorService $tutor, private readonly CapabilityMatrix $capabilities) {}

    /** Tutors, scenarios and what this language supports (text, push-to-talk voice, realtime voice). */
    public function catalog(LearnerProfile $learnerProfile): JsonResponse
    {
        $this->authorize('view', $learnerProfile);
        $code = $learnerProfile->targetLanguage->code;
        $level = $learnerProfile->assessmentAttempts()->where('status', 'completed')->latest('completed_at')->first()?->overallLevel
            ?? $learnerProfile->selfReportedLevel ?? CefrLevel::where('code', 'A1')->first();

        return response()->json(['data' => [
            'tutors' => AiTutor::where('is_active', true)->with('translations')->orderBy('position')->get()->map(fn ($t) => [
                'slug' => $t->slug, 'name' => $t->name, 'style' => $t->style, 'correction_mode' => $t->correction_mode, 'description' => $t->translate('description'),
            ]),
            'scenarios' => ConversationScenario::where('is_active', true)->with(['translations', 'minLevel', 'maxLevel'])->orderBy('position')->get()->map(fn ($s) => [
                'slug' => $s->slug, 'icon' => $s->icon, 'title' => $s->translate('title'), 'description' => $s->translate('description'),
                'min_level' => $s->minLevel->label, 'max_level' => $s->maxLevel->label,
                'recommended' => $s->minLevel->ordinal <= $level->ordinal && $s->maxLevel->ordinal >= $level->ordinal,
            ]),
            'levels' => LevelResource::collection(CefrLevel::orderBy('ordinal')->get()),
            'default_level' => $level->code,
            'capabilities' => [
                'text' => $this->capabilities->isUsable($code, 'content_generation'),
                'voice' => $this->capabilities->isUsable($code, 'stt') && $this->capabilities->isUsable($code, 'tts'),
                // Realtime needs a real provider: the browser connects to it directly with an ephemeral token.
                'realtime' => $this->capabilities->isUsable($code, 'realtime_voice') && config('ai.default_provider') !== 'fake',
            ],
            // Plan limits so the UI can show what is left (and offer an upgrade) before the learner hits a 429.
            'quota' => collect(['tutor_messages', 'realtime_minutes'])->mapWithKeys(fn ($f) => [$f => [
                'limit' => app(EntitlementService::class)->limit($learnerProfile->user, $f),
                'remaining' => app(QuotaGuard::class)->remaining($learnerProfile->user, $f),
                'resets_at' => app(QuotaGuard::class)->resetsAt($learnerProfile->user, $f)?->toIso8601String(),
            ]]),
        ]]);
    }

    public function index(LearnerProfile $learnerProfile): JsonResponse
    {
        $this->authorize('view', $learnerProfile);

        return response()->json(['data' => ConversationResource::collection(
            AiConversation::where('learner_profile_id', $learnerProfile->id)->latest('started_at')->limit(30)->get(),
        )]);
    }

    public function start(Request $request, LearnerProfile $learnerProfile): JsonResponse
    {
        $this->authorize('update', $learnerProfile);
        $data = $request->validate([
            'tutor' => ['required', Rule::exists('ai_tutors', 'slug')->where('is_active', true)],
            'scenario' => ['nullable', Rule::exists('conversation_scenarios', 'slug')->where('is_active', true)],
            'level' => ['nullable', Rule::exists('cefr_levels', 'code')],
            'mode' => ['required', Rule::in(['text', 'voice', 'realtime'])],
            'session_id' => ['nullable', 'string', 'size:26'],
        ]);
        $conversation = $this->tutor->start(
            $learnerProfile,
            AiTutor::where('slug', $data['tutor'])->first(),
            isset($data['scenario']) ? ConversationScenario::where('slug', $data['scenario'])->first() : null,
            isset($data['level']) ? CefrLevel::where('code', $data['level'])->first() : null,
            $data['mode'],
            isset($data['session_id']) ? LearningSession::where('public_id', $data['session_id'])->first() : null,
        );

        return (new ConversationResource($conversation->load('messages.feedback')))->response()->setStatusCode(201);
    }

    public function show(AiConversation $conversation): ConversationResource
    {
        $this->authorize('view', $conversation);

        return new ConversationResource($conversation->load('messages.feedback'));
    }

    public function message(Request $request, AiConversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);
        $data = $request->validate(['text' => ['required', 'string', 'max:2000'], 'response_ms' => ['nullable', 'integer', 'min:0']]);

        return $this->turnResponse($conversation, $this->tutor->reply($conversation, $data['text'], null, 'text', $data['response_ms'] ?? null));
    }

    /** Push-to-talk voice turn: the uploaded recording is transcribed, then handled like a text turn. */
    public function voice(Request $request, AiConversation $conversation, TranscriptionService $transcriber, QuotaGuard $quota): JsonResponse
    {
        $this->authorize('update', $conversation);
        $data = $request->validate(['recording_id' => ['required', 'string', 'size:26'], 'response_ms' => ['nullable', 'integer', 'min:0']]);
        $recording = AudioRecording::where('public_id', $data['recording_id'])->where('learner_profile_id', $conversation->learner_profile_id)->firstOrFail();
        // The turn is charged before transcription so an exhausted quota never incurs speech-to-text cost.
        $turn = $quota->using($conversation->learnerProfile->user, 'tutor_messages', function () use ($transcriber, $recording, $conversation, $data) {
            $text = $transcriber->transcribe($recording, $conversation->learnerProfile->targetLanguage->code, 'stt.conversation')->text;

            return $this->tutor->reply($conversation, $text, $recording, 'voice', $data['response_ms'] ?? null, prepaid: true);
        });

        return $this->turnResponse($conversation, $turn);
    }

    /**
     * Issues a short-lived credential for a browser WebRTC session with the provider. The permanent API key never
     * leaves the server; instructions are fixed server-side so the client cannot change the tutor's behaviour.
     */
    public function realtimeSession(AiConversation $conversation, AiGateway $ai, PromptRegistry $prompts, QuotaGuard $quota): JsonResponse
    {
        $this->authorize('update', $conversation);
        if (! $conversation->isActive()) {
            throw new DomainError('This conversation has ended.', 'conversation_closed');
        }
        $conversation->loadMissing(['tutor', 'scenario', 'level', 'learnerProfile.targetLanguage']);
        $profile = $conversation->learnerProfile;
        $prompt = $prompts->render('tutor.realtime', [
            'tutor_name' => $conversation->tutor->name,
            'tutor_persona' => $conversation->tutor->persona,
            'language' => $profile->targetLanguage->name,
            'scenario' => $conversation->scenario?->setup ?? 'An open, friendly conversation about everyday topics.',
            'level' => $conversation->level->label,
            'correction_mode' => $conversation->tutor->correction_mode === 'end_only' ? 'do not correct during the conversation' : 'correct gently by recasting',
            'explanation_language' => ExplanationLanguage::for($profile),
        ]);
        // Realtime audio is pre-charged in blocks (it streams browser↔provider, so it cannot be metered per second here).
        $user = $profile->user;
        $remaining = $quota->remaining($user, 'realtime_minutes');
        $minutes = $remaining === null ? config('billing.realtime_block_minutes') : max(1, min(config('billing.realtime_block_minutes'), $remaining));
        $session = $quota->using($user, 'realtime_minutes',
            fn () => $ai->realtimeSession(new RealtimeSessionRequest($prompt->text, $profile->targetLanguage->code, $conversation->tutor->voice, $profile->user_id)), $minutes);
        $conversation->update(['mode' => 'realtime']);

        return response()->json(['data' => [
            'client_secret' => $session->clientSecret,
            'expires_at' => $session->expiresAt->format(DATE_ATOM),
            'model' => $session->model,
            'provider' => $session->provider,
            'max_seconds' => $minutes * 60, // the client hangs up at the end of the block
        ]]);
    }

    public function realtimeTranscript(Request $request, AiConversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);
        $data = $request->validate(['role' => ['required', Rule::in(['learner', 'assistant'])], 'text' => ['required', 'string', 'max:2000']]);
        $message = $this->tutor->appendRealtimeTranscript($conversation, $data['role'], $data['text']);

        return response()->json(['data' => ConversationResource::message($message->load('feedback'))], 201);
    }

    public function finish(Request $request, AiConversation $conversation): ConversationResource
    {
        $this->authorize('update', $conversation);
        $seconds = (int) $request->validate(['active_seconds' => ['required', 'integer', 'min:0', 'max:86400']])['active_seconds'];

        return new ConversationResource($this->tutor->finish($conversation, $seconds));
    }

    public function report(AiConversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);
        $conversation->load(['report.items.exercise.translations', 'report.items.exercise.grammarTopic.translations', 'feedback', 'messages']);
        $report = $conversation->report;
        $status = $report ? 'ready' : ($conversation->status === 'report_failed' ? 'failed' : ($conversation->learner_turns === 0 && $conversation->status === 'completed' ? 'empty' : 'pending'));

        return response()->json(['data' => [
            'status' => $status,
            'conversation' => new ConversationResource($conversation),
            'stats' => app(ReportGenerator::class)->stats($conversation),
            'report' => $report ? [
                'summary' => $report->summary,
                'fluency_feedback' => $report->fluency_feedback,
                'pronunciation_feedback' => $report->pronunciation_feedback,
                'scores' => ['task_completion' => $report->task_completion, 'grammar' => $report->grammar_score, 'vocabulary' => $report->vocabulary_score, 'interaction' => $report->interaction_score],
                'vocabulary' => $report->items->where('type', 'vocabulary')->map(fn ($i) => ['term' => $i->term, 'meaning' => $i->detail, 'word_id' => $i->vocabulary_word_id])->values(),
                'expressions' => $report->items->where('type', 'expression')->map(fn ($i) => ['term' => $i->term, 'meaning' => $i->detail])->values(),
                'exercises' => $report->items->where('type', 'exercise')->filter(fn ($i) => $i->exercise)->map(fn ($i) => [
                    'id' => $i->exercise->public_id, 'type' => $i->exercise->type,
                    'title' => $i->exercise->translate('title') ?? $i->exercise->grammarTopic?->translate('title'),
                ])->values(),
                'is_ai_generated' => true,
            ] : null,
            'mistakes' => $conversation->feedback->map(fn ($f) => [
                'type' => $f->type, 'original' => $f->original, 'correction' => $f->correction, 'explanation' => $f->explanation,
            ])->values(),
        ]]);
    }

    public function retryReport(AiConversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);
        if ($conversation->status !== 'report_failed') {
            throw new DomainError('The report is not in a failed state.', 'report_not_failed');
        }
        $conversation->update(['status' => 'completed']);
        GenerateConversationReport::dispatch($conversation->id);

        return response()->json(['data' => ['status' => 'pending']], 202);
    }

    /** Text-to-speech for a tutor message (generated once, cached privately, served via signed URL). */
    public function messageAudio(Request $request, AiMessage $message, AiGateway $ai, MediaUrlService $media): JsonResponse
    {
        $conversation = $message->conversation()->with(['tutor', 'learnerProfile.targetLanguage'])->first();
        $this->authorize('view', $conversation);
        abort_unless($message->role === 'assistant', 404);

        $disk = Storage::disk(config('learning.media.disk'));
        $path = "tutor-audio/{$conversation->public_id}/{$message->id}.mp3";
        if (! $disk->exists($path)) {
            $audio = $ai->speech(new SpeechRequest(
                operation: 'tts.tutor',
                text: $message->content,
                languageCode: $conversation->learnerProfile->targetLanguage->code,
                voice: $conversation->tutor->voice,
                instructions: 'Speak clearly and a little slowly, like a friendly language teacher.',
                userId: $request->user()->id,
            ));
            $disk->put($path, $audio->bytes);
        }

        return response()->json(['url' => $media->temporaryUrl($path)]);
    }

    private function turnResponse(AiConversation $conversation, array $turn): JsonResponse
    {
        $hide = $conversation->tutor->correction_mode === 'end_only';

        return response()->json(['data' => [
            'learner' => ConversationResource::message($turn['learner'], $hide),
            'reply' => ConversationResource::message($turn['reply']->load('feedback')),
            'new_vocabulary' => $turn['new_vocabulary'] ?? [],
        ]]);
    }
}
