<?php

namespace App\Domain\Learning;

use App\Domain\Personalization\FormatBandit;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainError;
use App\Domain\Shared\LearningEventRecorder;
use App\Models\LearnerProfile;
use App\Models\LearningPlan;
use App\Models\LearningPlanItem;
use App\Models\LearningSession;
use Illuminate\Support\Facades\DB;

class PlanService
{
    /** Upper bound on credited study time per session (protects analytics from abandoned tabs). */
    private const MAX_SESSION_SECONDS = 4 * 3600;

    public function __construct(
        private readonly PlanGenerator $generator,
        private readonly LearningEventRecorder $events,
        private readonly AuditLogger $audit,
    ) {}

    public function livePlan(LearnerProfile $profile): ?LearningPlan
    {
        return $profile->plan()->with('currentVersion.allocations.skill')->first();
    }

    public function regenerate(LearnerProfile $profile, string $trigger = 'user_regenerate'): LearningPlan
    {
        $this->generator->generate($profile, $trigger);
        $plan = $this->livePlan($profile);
        $this->audit->log('plan.regenerated', $plan, new: ['trigger' => $trigger]);

        return $plan;
    }

    public function pause(LearnerProfile $profile): LearningPlan
    {
        $plan = $this->livePlan($profile) ?? throw new DomainError('No learning plan to pause.', 'plan_not_found', 404);
        if ($plan->status === LearningPlan::STATUS_PAUSED) {
            return $plan;
        }
        $plan->update(['status' => LearningPlan::STATUS_PAUSED, 'paused_at' => now()]);
        $this->events->record($profile, 'plan.paused', $plan);
        $this->audit->log('plan.paused', $plan);

        return $plan->fresh('currentVersion.allocations.skill');
    }

    public function resume(LearnerProfile $profile): LearningPlan
    {
        $plan = $this->livePlan($profile) ?? throw new DomainError('No learning plan to resume.', 'plan_not_found', 404);
        if ($plan->status === LearningPlan::STATUS_ACTIVE) {
            return $plan;
        }
        $plan->update(['status' => LearningPlan::STATUS_ACTIVE, 'paused_at' => null]);
        // Dates moved on while paused: build a fresh week from today.
        $this->generator->generate($profile, 'resume');
        $this->events->record($profile, 'plan.resumed', $plan);
        $this->audit->log('plan.resumed', $plan);

        return $this->livePlan($profile);
    }

    public function startItem(LearningPlanItem $item): LearningSession
    {
        $plan = $item->version->plan;
        if ($plan->status !== LearningPlan::STATUS_ACTIVE) {
            throw new DomainError('Resume your plan to start activities.', 'plan_paused');
        }
        if ($plan->current_version_id !== $item->learning_plan_version_id) {
            throw new DomainError('This activity belongs to an older version of your plan.', 'item_superseded');
        }
        if (in_array($item->status, [LearningPlanItem::COMPLETED, LearningPlanItem::SKIPPED], true)) {
            throw new DomainError('This activity is already finished.', 'item_finished');
        }
        if ($item->activity_type === 'placement_assessment') {
            throw new DomainError('Start the placement assessment from the assessment page.', 'use_assessment_flow', 422);
        }

        return DB::transaction(function () use ($item, $plan) {
            $open = $item->sessions()->whereNull('ended_at')->first();
            if ($open) {
                return $open;
            }
            $item->update(['status' => LearningPlanItem::IN_PROGRESS]);
            $session = LearningSession::create([
                'learner_profile_id' => $plan->learner_profile_id,
                'learning_plan_item_id' => $item->id,
                'activity_type' => $item->activity_type,
                'started_at' => now(),
            ]);
            $this->events->record($plan->learnerProfile, 'session.started', $session, $item->skill_id);

            return $session;
        });
    }

    /** A session not tied to a plan item (free practice, lessons, flashcards) so study time is still tracked. */
    public function startStandalone(LearnerProfile $profile, string $activityType): LearningSession
    {
        $session = LearningSession::create([
            'learner_profile_id' => $profile->id,
            'activity_type' => $activityType,
            'started_at' => now(),
        ]);
        $this->events->record($profile, 'session.started', $session, metadata: ['activity_type' => $activityType]);

        return $session;
    }

    public function completeSession(LearningSession $session, int $activeSeconds, ?int $selfRating): LearningSession
    {
        if ($session->ended_at !== null) {
            return $session; // idempotent
        }

        return DB::transaction(function () use ($session, $activeSeconds, $selfRating) {
            $session = LearningSession::whereKey($session->id)->lockForUpdate()->first();
            $elapsed = max(0, (int) $session->started_at->diffInSeconds(now()));
            // Never credit more time than actually elapsed (+60 s clock-skew tolerance).
            $credited = min($activeSeconds, $elapsed + 60, self::MAX_SESSION_SECONDS);

            $session->update([
                'ended_at' => now(),
                'active_seconds' => max(0, $credited),
                'outcome' => 'completed',
                'self_rating' => $selfRating,
            ]);

            $item = $session->planItem;
            $item?->update(['status' => LearningPlanItem::COMPLETED, 'completed_at' => now()]);
            $session->learnerProfile->update(['last_studied_at' => now()]);

            $this->events->record($session->learnerProfile, 'session.completed', $session, $item?->skill_id, round($credited / 60, 2), [
                'activity_type' => $item?->activity_type,
                'self_rating' => $selfRating,
            ]);

            return $session->fresh('planItem');
        });
    }

    public function skipItem(LearningPlanItem $item, ?string $reason): LearningPlanItem
    {
        if ($item->status === LearningPlanItem::COMPLETED) {
            throw new DomainError('Completed activities cannot be skipped.', 'item_finished');
        }
        $item->update(['status' => LearningPlanItem::SKIPPED, 'skip_reason' => $reason]);
        app(FormatBandit::class)->observeSkip($item->version->plan->learner_profile_id, $item->exercise_id);
        $item->sessions()->whereNull('ended_at')->update(['ended_at' => now(), 'outcome' => 'abandoned']);
        $this->events->record($item->version->plan->learnerProfile, 'plan_item.skipped', $item, $item->skill_id, metadata: ['reason' => $reason]);

        return $item;
    }
}
