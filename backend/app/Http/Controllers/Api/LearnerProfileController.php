<?php

namespace App\Http\Controllers\Api;

use App\Domain\Learning\LearnerProfileService;
use App\Domain\Learning\ProgressService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLearnerProfileRequest;
use App\Http\Requests\UpdateLearnerProfileRequest;
use App\Http\Resources\AssessmentAttemptResource;
use App\Http\Resources\LearnerProfileResource;
use App\Http\Resources\PlanItemResource;
use App\Http\Resources\SkillProfileResource;
use App\Models\LearnerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LearnerProfileController extends Controller
{
    public function __construct(private readonly LearnerProfileService $profiles, private readonly ProgressService $progress) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return LearnerProfileResource::collection($request->user()->learnerProfiles()->latest()->get());
    }

    public function store(StoreLearnerProfileRequest $request): JsonResponse
    {
        $profile = $this->profiles->create($request->user(), $request->validated());

        return (new LearnerProfileResource($profile))->response()->setStatusCode(201);
    }

    public function show(LearnerProfile $learnerProfile): LearnerProfileResource
    {
        $this->authorize('view', $learnerProfile);

        return new LearnerProfileResource($learnerProfile);
    }

    public function update(UpdateLearnerProfileRequest $request, LearnerProfile $learnerProfile): LearnerProfileResource
    {
        $this->authorize('update', $learnerProfile);

        return new LearnerProfileResource($this->profiles->update($learnerProfile, $request->validated()));
    }

    public function destroy(LearnerProfile $learnerProfile): JsonResponse
    {
        $this->authorize('delete', $learnerProfile);
        $this->profiles->archive($learnerProfile);

        return response()->json(['message' => 'Language archived.']);
    }

    public function dashboard(LearnerProfile $learnerProfile): JsonResponse
    {
        $this->authorize('view', $learnerProfile);
        $data = $this->progress->dashboard($learnerProfile);

        return response()->json(['data' => [
            'profile' => new LearnerProfileResource($learnerProfile),
            'today' => $data['today'],
            'plan_status' => $data['plan_status'],
            'today_items' => PlanItemResource::collection($data['today_items']),
            'today_minutes_planned' => $data['today_minutes_planned'],
            'today_minutes_done' => $data['today_minutes_done'],
            'week' => $data['week'],
            'week_minutes' => $data['week_minutes'],
            'streak' => $data['streak'],
            'skills' => SkillProfileResource::collection($data['skills']),
            'latest_attempt' => $data['latest_attempt'] ? new AssessmentAttemptResource($data['latest_attempt']) : null,
            'vocabulary_due' => $data['vocabulary_due'],
        ]]);
    }

    public function progress(LearnerProfile $learnerProfile): JsonResponse
    {
        $this->authorize('view', $learnerProfile);
        $data = $this->progress->progress($learnerProfile);

        return response()->json(['data' => [
            'skills' => SkillProfileResource::collection($data['skills']),
            'weekly_activity' => $data['weekly_activity'],
            'practice_by_skill' => $data['practice_by_skill'],
            'streak' => $data['streak'],
            'total_minutes' => $data['total_minutes'],
            'skill_accuracy' => $data['skill_accuracy'],
            'daily_skill_activity' => $data['daily_skill_activity'],
            'vocabulary' => $data['vocabulary'],
            'grammar' => $data['grammar'],
            'reading' => $data['reading'],
            'exercises_completed' => $data['exercises_completed'],
            'attempts' => $data['attempts']->map(fn ($a) => [
                'id' => $a->public_id,
                'completed_at' => $a->completed_at->toIso8601String(),
                'overall_level' => $a->overallLevel?->label,
                'overall_is_provisional' => $a->overall_is_provisional,
                'skills' => $a->sections->mapWithKeys(fn ($s) => [$s->skill->slug => $s->estimatedLevel?->label]),
            ]),
        ]]);
    }
}
