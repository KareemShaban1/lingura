<?php

namespace App\Http\Controllers\Api;

use App\Domain\Writing\WritingEvaluator;
use App\Domain\Writing\WritingService;
use App\Http\Controllers\Controller;
use App\Http\Resources\LevelResource;
use App\Http\Resources\ProductionTaskResource;
use App\Models\LearnerProfile;
use App\Models\ProductionTask;
use App\Models\WritingSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WritingController extends Controller
{
    public function __construct(private readonly WritingService $writing) {}

    public function tasks(LearnerProfile $learnerProfile): JsonResponse
    {
        $this->authorize('view', $learnerProfile);
        $latest = WritingSubmission::where('learner_profile_id', $learnerProfile->id)->whereNull('assessment_attempt_id')
            ->with('evaluation')->latest()->get()->unique('production_task_id')->keyBy('production_task_id');

        return response()->json(['data' => ProductionTask::where('language_id', $learnerProfile->target_language_id)->where('is_exam', false)
            ->where('mode', 'written')->where('review_status', 'published')->orderBy('cefr_level_id')->get()
            ->map(fn ($t) => (new ProductionTaskResource($t))->toArray(request()) + [
                'last_score' => $latest->get($t->id)?->evaluation?->overall_score,
                'last_status' => $latest->get($t->id)?->status,
            ])]);
    }

    public function task(LearnerProfile $learnerProfile, ProductionTask $task): JsonResponse
    {
        $this->authorize('view', $learnerProfile);
        abort_unless($task->language_id === $learnerProfile->target_language_id, 404);

        return response()->json(['data' => [
            ...(new ProductionTaskResource($task))->toArray(request()),
            'language' => ['code' => $learnerProfile->targetLanguage->code, 'direction' => $learnerProfile->targetLanguage->direction],
            'submissions' => WritingSubmission::where('learner_profile_id', $learnerProfile->id)->where('production_task_id', $task->id)
                ->whereNull('assessment_attempt_id')->with('evaluation')->latest()->limit(10)->get()
                ->map(fn ($s) => ['id' => $s->public_id, 'status' => $s->status, 'word_count' => $s->word_count, 'score' => $s->evaluation?->overall_score, 'created_at' => $s->created_at->toIso8601String()]),
        ]]);
    }

    public function submit(Request $request, LearnerProfile $learnerProfile, ProductionTask $task): JsonResponse
    {
        $this->authorize('update', $learnerProfile);
        $text = $request->validate(['text' => ['required', 'string', 'max:5000']])['text'];
        $submission = $this->writing->submit($learnerProfile, $task, $text);

        return response()->json(['data' => $this->payload($submission->fresh())], 202);
    }

    public function show(WritingSubmission $submission): JsonResponse
    {
        $this->authorize('view', $submission);

        return response()->json(['data' => $this->payload($submission)]);
    }

    private function payload(WritingSubmission $s): array
    {
        $s->loadMissing(['task.translations', 'task.level', 'task.language', 'evaluation.criteria', 'evaluation.corrections', 'evaluation.level']);
        $e = $s->evaluation;

        return [
            'id' => $s->public_id,
            'status' => $s->status,
            'text' => $s->text,
            'word_count' => $s->word_count,
            'task' => (new ProductionTaskResource($s->task))->toArray(request()),
            'language' => ['code' => $s->task->language->code, 'direction' => $s->task->language->direction],
            'created_at' => $s->created_at->toIso8601String(),
            'evaluation' => $e ? [
                'is_ai_generated' => true,
                'estimated_level' => new LevelResource($e->level),
                'overall_score' => $e->overall_score,
                'summary' => $e->summary,
                'corrected_text' => $e->corrected_text,
                'criteria' => $e->criteria->sortBy(fn ($c) => array_search($c->criterion, WritingEvaluator::CRITERIA, true))
                    ->map(fn ($c) => ['criterion' => $c->criterion, 'score' => $c->score, 'comment' => $c->comment])->values(),
                'corrections' => $e->corrections->map(fn ($c) => ['start' => $c->start, 'length' => $c->length, 'original' => $c->original, 'correction' => $c->correction, 'type' => $c->type, 'explanation' => $c->explanation])->values(),
                'stats' => ['sentences' => $e->sentence_count, 'avg_sentence_length' => $e->avg_sentence_length, 'lexical_variety' => $e->lexical_variety],
            ] : null,
        ];
    }
}
