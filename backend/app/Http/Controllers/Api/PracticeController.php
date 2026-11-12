<?php

namespace App\Http\Controllers\Api;

use App\Domain\Media\MediaUrlService;
use App\Domain\Practice\ExerciseService;
use App\Domain\Shared\DomainError;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExerciseAttemptResource;
use App\Http\Resources\ExerciseSummaryResource;
use App\Models\Exercise;
use App\Models\ExerciseAnswer;
use App\Models\ExerciseAttempt;
use App\Models\ExerciseQuestion;
use App\Models\LearnerProfile;
use App\Models\LearningSession;
use App\Models\Lesson;
use App\Models\Skill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PracticeController extends Controller
{
    public function __construct(private readonly ExerciseService $exercises) {}

    /** Practice library: available exercises in the profile's language, filterable by skill and level. */
    public function index(Request $request, LearnerProfile $learnerProfile): AnonymousResourceCollection
    {
        $this->authorize('view', $learnerProfile);
        $filters = $request->validate([
            'skill' => ['nullable', Rule::exists('skills', 'slug')],
            'level' => ['nullable', Rule::exists('cefr_levels', 'code')],
            'type' => ['nullable', Rule::in(Exercise::TYPES)],
        ]);

        $lastScores = DB::table('exercise_attempts')->where('learner_profile_id', $learnerProfile->id)->where('status', 'completed')
            ->selectRaw('DISTINCT ON (exercise_id) exercise_id, score')->orderBy('exercise_id')->orderByDesc('completed_at')
            ->pluck('score', 'exercise_id');

        $exercises = Exercise::available()
            ->where('language_id', $learnerProfile->target_language_id)
            ->when($filters['skill'] ?? null, fn ($q, $s) => $q->where('skill_id', Skill::where('slug', $s)->value('id')))
            ->when($filters['level'] ?? null, fn ($q, $l) => $q->whereHas('level', fn ($lv) => $lv->where('code', $l)))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->with(['translations', 'grammarTopic.translations', 'skill', 'level', 'questions'])
            ->orderBy('cefr_level_id')->orderBy('id')->get()
            ->each(fn ($e) => $e->last_score = $lastScores[$e->id] ?? null);

        return ExerciseSummaryResource::collection($exercises);
    }

    public function start(Request $request, LearnerProfile $learnerProfile, Exercise $exercise): JsonResponse
    {
        $this->authorize('update', $learnerProfile);
        $data = $request->validate([
            'lesson_id' => ['nullable', 'string', 'size:26'],
            'session_id' => ['nullable', 'string', 'size:26'],
        ]);
        $lesson = isset($data['lesson_id']) ? Lesson::where('public_id', $data['lesson_id'])->firstOrFail() : null;
        if ($lesson && ! $lesson->exercises()->whereKey($exercise->id)->exists()) {
            throw new DomainError('This exercise is not part of that lesson.', 'not_in_lesson', 422);
        }
        $session = isset($data['session_id']) ? LearningSession::where('public_id', $data['session_id'])->firstOrFail() : null;

        $attempt = $this->exercises->start($learnerProfile, $exercise, $lesson, $session);

        return (new ExerciseAttemptResource($attempt))->response()->setStatusCode($attempt->wasRecentlyCreated ? 201 : 200);
    }

    public function show(ExerciseAttempt $exerciseAttempt): ExerciseAttemptResource
    {
        $this->authorize('view', $exerciseAttempt);

        return new ExerciseAttemptResource($exerciseAttempt);
    }

    public function answer(Request $request, ExerciseAttempt $exerciseAttempt): JsonResponse
    {
        $this->authorize('update', $exerciseAttempt);
        $data = $request->validate([
            'question_id' => ['required', 'integer'],
            'option_id' => ['nullable', 'integer'],
            'text' => ['nullable', 'string', 'max:1000'],
            'order' => ['nullable', 'array', 'max:30'],
            'order.*' => ['integer'],
            'response_time_ms' => ['nullable', 'integer', 'min:0'],
        ]);
        $answer = $this->exercises->answer($exerciseAttempt, $data['question_id'], $data);
        $question = ExerciseQuestion::with(['options', 'acceptedAnswers'])->find($answer->exercise_question_id);

        return response()->json(['data' => self::feedback($exerciseAttempt->exercise, $question, $answer)]);
    }

    public function finish(Request $request, ExerciseAttempt $exerciseAttempt): ExerciseAttemptResource
    {
        $this->authorize('update', $exerciseAttempt);
        $data = $request->validate(['reading_seconds' => ['nullable', 'integer', 'min:1', 'max:7200']]);

        return new ExerciseAttemptResource($this->exercises->finish($exerciseAttempt, $data['reading_seconds'] ?? null));
    }

    /** Signed URL for the attempt's listening passage, or for one dictation sentence. */
    public function audio(Request $request, ExerciseAttempt $exerciseAttempt, MediaUrlService $media): JsonResponse
    {
        $this->authorize('view', $exerciseAttempt);
        $exercise = $exerciseAttempt->exercise;
        $path = $request->filled('question_id')
            ? $exercise->questions()->whereKey((int) $request->input('question_id'))->value('audio_path')
            : $exercise->audio_path;
        abort_unless($path, 404);

        return response()->json(['url' => $media->temporaryUrl($path)]);
    }

    /** Feedback for one answered question: correctness, partial credit, the correct answer and the explanation. */
    public static function feedback(Exercise $exercise, ExerciseQuestion $question, ExerciseAnswer $answer): array
    {
        $correct = match ($exercise->type) {
            'cloze', 'dictation' => $question->acceptedAnswers->first()?->answer,
            'ordering' => $question->options->sortBy('correct_position')->pluck('label')->implode(' '),
            default => $question->options->firstWhere('is_correct', true)?->label,
        };

        return [
            'question_id' => $question->id,
            'is_correct' => $answer->is_correct,
            'score' => $answer->score,
            'feedback_code' => $answer->feedback_code,
            'given' => match ($exercise->type) {
                'cloze', 'dictation' => $answer->response_text,
                'ordering' => collect(explode(',', (string) $answer->response_text))
                    ->map(fn ($id) => $question->options->firstWhere('id', (int) $id)?->label)->filter()->implode(' '),
                default => $question->options->firstWhere('id', $answer->selected_option_id)?->label,
            },
            'selected_option_id' => $answer->selected_option_id,
            'correct_option_id' => in_array($exercise->type, ['mcq', 'reading', 'listening'], true) ? $question->options->firstWhere('is_correct', true)?->id : null,
            'correct_answer' => $correct,
            'explanation' => $question->explanation,
        ];
    }
}
