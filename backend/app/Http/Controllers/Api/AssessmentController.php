<?php

namespace App\Http\Controllers\Api;

use App\Domain\Assessment\PlacementService;
use App\Domain\Assessment\ReportBuilder;
use App\Domain\Media\MediaUrlService;
use App\Domain\Shared\DomainError;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssessmentAttemptResource;
use App\Http\Resources\AssessmentItemResource;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentItem;
use App\Models\LearnerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AssessmentController extends Controller
{
    public function __construct(private readonly PlacementService $placement, private readonly ReportBuilder $reports) {}

    public function start(LearnerProfile $learnerProfile): JsonResponse
    {
        $this->authorize('update', $learnerProfile);
        $attempt = $this->placement->start($learnerProfile);

        return (new AssessmentAttemptResource($attempt))->response()->setStatusCode($attempt->wasRecentlyCreated ? 201 : 200);
    }

    public function history(LearnerProfile $learnerProfile): AnonymousResourceCollection
    {
        $this->authorize('view', $learnerProfile);

        return AssessmentAttemptResource::collection($learnerProfile->assessmentAttempts()->latest('started_at')->limit(50)->get());
    }

    public function show(AssessmentAttempt $attempt): AssessmentAttemptResource
    {
        $this->authorize('view', $attempt);

        return new AssessmentAttemptResource($attempt);
    }

    public function next(AssessmentAttempt $attempt): JsonResponse
    {
        $this->authorize('update', $attempt);
        $item = $attempt->isInProgress() ? $this->placement->next($attempt) : null;
        $attempt->refresh();

        return response()->json([
            'done' => $item === null,
            'item' => $item ? new AssessmentItemResource($item) : null,
            'attempt' => new AssessmentAttemptResource($attempt->unsetRelation('sections')),
        ]);
    }

    public function answer(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $this->authorize('update', $attempt);
        $data = $request->validate([
            'item_id' => ['required', 'string', 'size:26'],
            'option_id' => ['required', 'integer'],
            'response_time_ms' => ['nullable', 'integer', 'min:0'],
        ]);
        $this->placement->answer($attempt, $data['item_id'], $data['option_id'], $data['response_time_ms'] ?? null);

        // Correctness is intentionally not revealed during a placement test.
        return response()->json(['accepted' => true]);
    }

    public function skip(AssessmentAttempt $attempt, string $skill): JsonResponse
    {
        $this->authorize('update', $attempt);
        $this->placement->skipSection($attempt, $skill);

        return response()->json(['skipped' => $skill]);
    }

    public function complete(AssessmentAttempt $attempt): JsonResponse
    {
        $this->authorize('update', $attempt);
        $attempt = $this->placement->complete($attempt);

        return response()->json(['data' => $this->reports->build($attempt)]);
    }

    public function report(AssessmentAttempt $attempt): JsonResponse
    {
        $this->authorize('view', $attempt);
        if ($attempt->isInProgress()) {
            throw new DomainError('The report is available once the assessment is complete.', 'attempt_in_progress');
        }

        return response()->json(['data' => $this->reports->build($attempt)]);
    }

    /** Signed, short-lived URL for listening audio of the item currently presented to this learner. */
    public function itemAudio(Request $request, AssessmentAttempt $attempt, AssessmentItem $item, MediaUrlService $media): JsonResponse
    {
        $this->authorize('update', $attempt);
        $isCurrent = $attempt->sections()->where('current_item_id', $item->id)->exists();
        abort_unless($isCurrent && $item->audio_path, 404);

        return response()->json(['url' => $media->temporaryUrl($item->audio_path)]);
    }
}
