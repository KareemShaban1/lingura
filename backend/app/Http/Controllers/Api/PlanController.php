<?php

namespace App\Http\Controllers\Api;

use App\Domain\Learning\PlanService;
use App\Http\Controllers\Controller;
use App\Http\Resources\LearningPlanResource;
use App\Http\Resources\LearningSessionResource;
use App\Http\Resources\PlanItemResource;
use App\Http\Resources\PlanVersionResource;
use App\Models\LearnerProfile;
use App\Models\LearningPlanItem;
use App\Models\LearningSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlanController extends Controller
{
    public function __construct(private readonly PlanService $plans) {}

    public function show(LearnerProfile $learnerProfile): JsonResponse
    {
        $this->authorize('view', $learnerProfile);
        $plan = $this->plans->livePlan($learnerProfile);

        return response()->json(['data' => $plan ? new LearningPlanResource($plan) : null]);
    }

    public function items(Request $request, LearnerProfile $learnerProfile): AnonymousResourceCollection
    {
        $this->authorize('view', $learnerProfile);
        $range = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $plan = $this->plans->livePlan($learnerProfile);

        $items = $plan?->current_version_id
            ? LearningPlanItem::where('learning_plan_version_id', $plan->current_version_id)
                ->when($range['from'] ?? null, fn ($q, $from) => $q->whereDate('scheduled_date', '>=', $from))
                ->when($range['to'] ?? null, fn ($q, $to) => $q->whereDate('scheduled_date', '<=', $to))
                ->with(['skill', 'targetLevel', 'version.plan.learnerProfile.targetLanguage'])->orderBy('scheduled_date')->orderBy('position')->get()
            : collect();

        return PlanItemResource::collection($items);
    }

    public function versions(LearnerProfile $learnerProfile): AnonymousResourceCollection
    {
        $this->authorize('view', $learnerProfile);
        $plan = $this->plans->livePlan($learnerProfile);
        $versions = $plan
            ? $plan->versions()->with('allocations.skill')
                ->withCount(['items', 'items as completed_items_count' => fn ($q) => $q->where('status', LearningPlanItem::COMPLETED)])
                ->limit(50)->get()
            : collect();

        return PlanVersionResource::collection($versions);
    }

    public function regenerate(LearnerProfile $learnerProfile): LearningPlanResource
    {
        $this->authorize('update', $learnerProfile);

        return new LearningPlanResource($this->plans->regenerate($learnerProfile));
    }

    public function pause(LearnerProfile $learnerProfile): LearningPlanResource
    {
        $this->authorize('update', $learnerProfile);

        return new LearningPlanResource($this->plans->pause($learnerProfile));
    }

    public function resume(LearnerProfile $learnerProfile): LearningPlanResource
    {
        $this->authorize('update', $learnerProfile);

        return new LearningPlanResource($this->plans->resume($learnerProfile));
    }

    public function startItem(LearningPlanItem $planItem): JsonResponse
    {
        $this->authorize('update', $planItem);
        $session = $this->plans->startItem($planItem);

        return (new LearningSessionResource($session))->response()->setStatusCode(201);
    }

    public function skipItem(Request $request, LearningPlanItem $planItem): PlanItemResource
    {
        $this->authorize('update', $planItem);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:120']]);

        return new PlanItemResource($this->plans->skipItem($planItem, $data['reason'] ?? null)->load(['skill', 'targetLevel']));
    }

    public function startSession(Request $request, LearnerProfile $learnerProfile): JsonResponse
    {
        $this->authorize('update', $learnerProfile);
        $data = $request->validate(['activity_type' => ['required', 'in:lesson,exercise,vocabulary_review']]);

        return (new LearningSessionResource($this->plans->startStandalone($learnerProfile, $data['activity_type'])))->response()->setStatusCode(201);
    }

    public function showSession(LearningSession $session): LearningSessionResource
    {
        $this->authorize('view', $session);

        return new LearningSessionResource($session);
    }

    public function completeSession(Request $request, LearningSession $session): LearningSessionResource
    {
        $this->authorize('update', $session);
        $data = $request->validate([
            'active_seconds' => ['required', 'integer', 'min:0', 'max:86400'],
            'self_rating' => ['nullable', 'integer', 'between:1,5'],
        ]);

        return new LearningSessionResource($this->plans->completeSession($session, $data['active_seconds'], $data['self_rating'] ?? null));
    }
}
