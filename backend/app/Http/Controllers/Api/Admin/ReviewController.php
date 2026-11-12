<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainError;
use App\Http\Controllers\Controller;
use App\Models\AssessmentItem;
use App\Models\ContentReview;
use App\Models\WritingEvaluation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Human gates: AI-drafted assessment items and the calibration sample of AI writing evaluations. */
class ReviewController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function items(Request $request): JsonResponse
    {
        $status = $request->query('status', 'draft');
        $items = AssessmentItem::whereIn('review_status', $status === 'draft' ? ['draft', 'in_review'] : [$status])
            ->with(['options', 'language', 'skill', 'level'])->latest('id')->paginate(20);

        return response()->json([
            'data' => collect($items->items())->map(fn (AssessmentItem $i) => [
                'id' => $i->public_id, 'language' => $i->language->code, 'skill' => $i->skill->slug, 'level' => $i->level->code,
                'format' => $i->format, 'source' => $i->source, 'status' => $i->review_status, 'difficulty' => (float) $i->difficulty,
                'stimulus' => $i->stimulus_text, 'prompt' => $i->prompt, 'explanation' => $i->explanation, 'topic' => $i->grammar_topic_slug,
                'options' => $i->options->map(fn ($o) => ['label' => $o->label, 'is_correct' => (bool) $o->is_correct]),
                'created_at' => $i->created_at->toIso8601String(),
            ]),
            'meta' => ['page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'total' => $items->total()],
        ]);
    }

    public function decideItem(Request $request, AssessmentItem $item): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected,retired,restored'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $next = match ($data['decision']) {
            'approved', 'restored' => AssessmentItem::STATUS_PUBLISHED,
            'rejected', 'retired' => AssessmentItem::STATUS_RETIRED,
        };
        if ($data['decision'] === 'approved' && ! in_array($item->review_status, ['draft', 'in_review'], true)) {
            throw new DomainError('Only drafts can be approved.', 'invalid_state', 409);
        }
        DB::transaction(function () use ($request, $item, $data, $next) {
            $old = $item->review_status;
            $item->update(['review_status' => $next, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            ContentReview::create(['reviewable_type' => 'assessment_item', 'reviewable_id' => $item->id, 'reviewer_id' => $request->user()->id,
                'decision' => $data['decision'], 'notes' => $data['notes'] ?? null, 'created_at' => now()]);
            $this->audit->log('admin.item_'.$data['decision'], $item, ['review_status' => $old], ['review_status' => $next]);
        });

        return response()->json(['data' => ['id' => $item->public_id, 'status' => $next]]);
    }

    public function evaluations(Request $request): JsonResponse
    {
        $reviewed = $request->boolean('reviewed');
        $rows = WritingEvaluation::where('needs_human_review', true)
            ->when($reviewed, fn ($q) => $q->whereNotNull('reviewed_at'), fn ($q) => $q->whereNull('reviewed_at'))
            ->with(['criteria', 'level', 'submission.task.language'])->latest('id')->paginate(20);

        return response()->json([
            'data' => collect($rows->items())->map(fn (WritingEvaluation $e) => [
                'id' => $e->id,
                'language' => $e->submission->task->language->code,
                'direction' => $e->submission->task->language->direction,
                'task' => $e->submission->task->prompt,
                'text' => $e->submission->text, // pseudonymous: no learner identity is shown to reviewers
                'ai' => ['overall' => $e->overall_score, 'level' => $e->level->code, 'criteria' => $e->criteria->map(fn ($c) => ['criterion' => $c->criterion, 'score' => $c->score, 'comment' => $c->comment])],
                'prompt_version' => $e->prompt_version, 'model' => $e->model_slug,
                'human' => $e->reviewed_at ? ['overall' => $e->human_overall_score, 'notes' => $e->review_notes, 'reviewed_at' => $e->reviewed_at->toIso8601String()] : null,
            ]),
            'meta' => ['page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total(), 'calibration' => $this->calibration()],
        ]);
    }

    public function reviewEvaluation(Request $request, WritingEvaluation $evaluation): JsonResponse
    {
        $data = $request->validate(['overall' => ['required', 'numeric', 'between:1,5'], 'notes' => ['nullable', 'string', 'max:2000']]);
        if (! $evaluation->needs_human_review) {
            throw new DomainError('This evaluation is not part of the review sample.', 'invalid_state', 409);
        }
        $evaluation->update(['human_overall_score' => round((float) $data['overall'], 2), 'review_notes' => $data['notes'] ?? null,
            'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        $this->audit->log('admin.evaluation_reviewed', $evaluation, new: ['ai' => $evaluation->overall_score, 'human' => $evaluation->human_overall_score]);

        return response()->json(['data' => ['id' => $evaluation->id], 'meta' => ['calibration' => $this->calibration()]]);
    }

    /** Agreement between AI and human scores on the reviewed sample (per prompt version). */
    private function calibration(): array
    {
        return WritingEvaluation::whereNotNull('reviewed_at')
            ->groupBy('prompt_version')
            ->selectRaw('prompt_version, count(*) as reviewed, avg(abs(overall_score - human_overall_score)) as mae,
                avg(case when abs(overall_score - human_overall_score) <= 0.5 then 1.0 else 0.0 end) as within_half_band')
            ->get()->map(fn ($r) => ['prompt_version' => $r->prompt_version, 'reviewed' => (int) $r->reviewed,
                'mean_absolute_error' => round((float) $r->mae, 2), 'within_half_band' => round((float) $r->within_half_band, 3)])->all();
    }
}
