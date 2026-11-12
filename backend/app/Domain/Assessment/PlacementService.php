<?php

namespace App\Domain\Assessment;

use App\Domain\Assessment\Engine\CefrScale;
use App\Domain\Assessment\Engine\IrtModel;
use App\Domain\Languages\CapabilityMatrix;
use App\Domain\Shared\DomainError;
use App\Domain\Shared\LearningEventRecorder;
use App\Models\Assessment;
use App\Models\AssessmentAnswer;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentAttemptSection;
use App\Models\AssessmentItem;
use App\Models\AssessmentSection;
use App\Models\LearnerProfile;
use App\Models\Skill;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Adaptive placement assessment (engine irt_v1). Each skill section is estimated independently.
 */
class PlacementService
{
    public function __construct(
        private readonly CapabilityMatrix $capabilities,
        private readonly AttemptFinalizer $finalizer,
        private readonly LearningEventRecorder $events,
    ) {}

    public function start(LearnerProfile $profile): AssessmentAttempt
    {
        return DB::transaction(function () use ($profile) {
            // Serialise starts per profile so double-clicks cannot create two attempts.
            LearnerProfile::whereKey($profile->id)->lockForUpdate()->first();

            $existing = $profile->assessmentAttempts()->where('status', AssessmentAttempt::STATUS_IN_PROGRESS)->first();
            if ($existing) {
                return $existing;
            }

            $assessment = Assessment::activePlacement()->load('sections.skill');
            $attempt = $profile->assessmentAttempts()->create([
                'assessment_id' => $assessment->id,
                'status' => AssessmentAttempt::STATUS_IN_PROGRESS,
                'engine_version' => config('learning.assessment.engine_version'),
                'started_at' => now(),
            ]);

            $language = $profile->targetLanguage;
            foreach ($assessment->sections as $section) {
                $unavailableReason = $this->unavailableReason($section, $language->code, $language->id);
                $attempt->sections()->create([
                    'assessment_section_id' => $section->id,
                    'skill_id' => $section->skill_id,
                    'position' => $section->position,
                    'status' => $unavailableReason ? AssessmentAttemptSection::NOT_AVAILABLE : AssessmentAttemptSection::PENDING,
                    'skip_reason' => $unavailableReason,
                ]);
            }

            $this->events->record($profile, 'assessment.started', $attempt);

            return $attempt;
        });
    }

    /**
     * Returns the item to present now, or null when the attempt is finished.
     */
    public function next(AssessmentAttempt $attempt): ?AssessmentItem
    {
        $this->assertInProgress($attempt);

        $item = DB::transaction(function () use ($attempt) {
            AssessmentAttempt::whereKey($attempt->id)->lockForUpdate()->first();
            $sections = $attempt->sections()->with('section')->get();

            foreach ($sections as $section) {
                if (! $section->isOpen()) {
                    continue;
                }
                if ($section->current_item_id) {
                    return AssessmentItem::find($section->current_item_id);
                }
                if ($this->shouldStop($section)) {
                    $this->closeSection($section);

                    continue;
                }
                $item = $this->selectItem($attempt, $section);
                if (! $item) {
                    $this->closeSection($section);

                    continue;
                }
                $section->update([
                    'status' => AssessmentAttemptSection::IN_PROGRESS,
                    'current_item_id' => $item->id,
                    'current_item_served_at' => now(),
                ]);

                return $item;
            }

            return null;
        });

        if ($item === null) {
            $this->finalizer->finalize($attempt->fresh());
        }

        return $item?->load(['options', 'skill', 'level']);
    }

    /**
     * Records an answer to the currently served item. Idempotent per (attempt, item).
     */
    public function answer(AssessmentAttempt $attempt, string $itemPublicId, int $optionId, ?int $responseTimeMs): AssessmentAnswer
    {
        $this->assertInProgress($attempt);

        return DB::transaction(function () use ($attempt, $itemPublicId, $optionId, $responseTimeMs) {
            AssessmentAttempt::whereKey($attempt->id)->lockForUpdate()->first();

            $item = AssessmentItem::where('public_id', $itemPublicId)->with('options')->first()
                ?? throw new DomainError('Unknown item.', 'item_not_found', 404);

            $existing = $attempt->answers()->where('assessment_item_id', $item->id)->first();
            if ($existing) {
                return $existing; // retry of an already-recorded answer
            }

            $section = $attempt->sections()->where('current_item_id', $item->id)->first()
                ?? throw new DomainError('This item is not the one currently presented.', 'item_not_current');

            $option = $item->options->firstWhere('id', $optionId)
                ?? throw new DomainError('The selected option does not belong to this item.', 'invalid_option', 422);

            $priorMean = $this->priorMean($attempt);
            $responses = $section->answers()->with('item.options')->get()
                ->map(fn (AssessmentAnswer $a) => [
                    'difficulty' => $a->item->difficulty,
                    'options' => $a->item->options->count(),
                    'correct' => $a->is_correct,
                ])->all();
            $responses[] = ['difficulty' => $item->difficulty, 'options' => $item->options->count(), 'correct' => $option->is_correct];
            $estimate = IrtModel::fromConfig()->estimate($responses, $priorMean);

            $answer = $attempt->answers()->create([
                'assessment_attempt_section_id' => $section->id,
                'assessment_item_id' => $item->id,
                'selected_option_id' => $option->id,
                'is_correct' => $option->is_correct,
                'response_time_ms' => $responseTimeMs !== null ? min($responseTimeMs, 3_600_000) : null,
                'theta_after' => $estimate['theta'],
                'se_after' => $estimate['se'],
                'answered_at' => now(),
            ]);

            $section->update([
                'theta' => $estimate['theta'],
                'standard_error' => $estimate['se'],
                'items_answered' => $section->items_answered + 1,
                'items_correct' => $section->items_correct + ($option->is_correct ? 1 : 0),
                'current_item_id' => null,
                'current_item_served_at' => null,
            ]);
            $item->increment('exposure_count');

            return $answer;
        });
    }

    public function skipSection(AssessmentAttempt $attempt, string $skillSlug): AssessmentAttemptSection
    {
        $this->assertInProgress($attempt);

        return DB::transaction(function () use ($attempt, $skillSlug) {
            AssessmentAttempt::whereKey($attempt->id)->lockForUpdate()->first();
            $skillId = Skill::where('slug', $skillSlug)->value('id') ?? throw new DomainError('Unknown skill.', 'unknown_skill', 404);
            $section = $attempt->sections()->where('skill_id', $skillId)->with('section')->firstOrFail();

            if (! $section->isOpen()) {
                throw new DomainError('This section is already closed.', 'section_closed');
            }
            $this->closeSection($section, AssessmentAttemptSection::SKIPPED, 'skipped_by_learner');

            return $section->fresh();
        });
    }

    /** Ends the attempt now. Sections with enough evidence are scored; the rest are marked skipped. */
    public function complete(AssessmentAttempt $attempt): AssessmentAttempt
    {
        $this->assertInProgress($attempt);

        DB::transaction(function () use ($attempt) {
            AssessmentAttempt::whereKey($attempt->id)->lockForUpdate()->first();
            foreach ($attempt->sections()->with('section')->get() as $section) {
                if ($section->isOpen()) {
                    $this->closeSection($section, AssessmentAttemptSection::SKIPPED, 'ended_early');
                }
            }
        });

        return $this->finalizer->finalize($attempt->fresh());
    }

    public function priorMean(AssessmentAttempt $attempt): float
    {
        $level = $attempt->learnerProfile->selfReportedLevel;

        return $level ? (float) $level->anchor_theta : (float) config('learning.assessment.default_prior_mean');
    }

    private function shouldStop(AssessmentAttemptSection $section): bool
    {
        $config = $section->section;
        if ($section->items_answered >= $config->max_items) {
            return true;
        }

        return $section->items_answered >= $config->min_items
            && $section->standard_error !== null
            && $section->standard_error <= $config->target_standard_error;
    }

    /**
     * Closes a section. With at least min_items answered, an early end still produces a (lower-confidence) estimate.
     */
    private function closeSection(AssessmentAttemptSection $section, ?string $status = null, ?string $reason = null): void
    {
        $answered = $section->items_answered;
        $enough = $answered >= max(1, $section->section->min_items);
        $exhausted = $status === null;

        if ($answered > 0 && ($enough || $exhausted)) {
            $status = AssessmentAttemptSection::COMPLETED;
            $reason = null;
        } elseif ($status === null) {
            $status = AssessmentAttemptSection::NOT_AVAILABLE;
            $reason = 'item_bank_exhausted';
        }

        $section->update([
            'status' => $status,
            'skip_reason' => $reason,
            'current_item_id' => null,
            'current_item_served_at' => null,
            'estimated_level_id' => $status === AssessmentAttemptSection::COMPLETED && $section->theta !== null
                ? CefrScale::load()->levelForTheta($section->theta)->id
                : null,
            'completed_at' => now(),
        ]);
    }

    private function selectItem(AssessmentAttempt $attempt, AssessmentAttemptSection $section): ?AssessmentItem
    {
        $profile = $attempt->learnerProfile;
        $answered = $attempt->answers()->pluck('assessment_item_id');
        $usedStimuli = AssessmentItem::whereIn('id', $answered)->whereNotNull('stimulus_key')->pluck('stimulus_key');

        $candidates = AssessmentItem::published()
            ->where('language_id', $profile->target_language_id)
            ->where('skill_id', $section->skill_id)
            ->whereNotIn('id', $answered)
            ->when($usedStimuli->isNotEmpty(), fn ($q) => $q->where(fn ($q) => $q->whereNull('stimulus_key')->orWhereNotIn('stimulus_key', $usedStimuli)))
            ->when($section->skill->slug === Skill::LISTENING, fn ($q) => $q->whereNotNull('audio_path'))
            ->withCount('options')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $irt = IrtModel::fromConfig();
        $theta = $section->theta ?? $this->priorMean($attempt);
        $ranked = $candidates
            ->map(fn (AssessmentItem $item) => ['item' => $item, 'info' => $irt->information($theta, $item->difficulty, $item->options_count)])
            ->sort(fn ($a, $b) => [$b['info'], $a['item']->exposure_count] <=> [$a['info'], $b['item']->exposure_count])
            ->values();

        $top = $ranked->take((int) config('learning.assessment.top_k_selection', 3))->all();

        return Arr::random($top)['item'];
    }

    private function unavailableReason(AssessmentSection $section, string $languageCode, int $languageId): ?string
    {
        if ($section->availability !== 'enabled') {
            return 'coming_in_future_release';
        }

        $query = AssessmentItem::published()->where('language_id', $languageId)->where('skill_id', $section->skill_id);

        if ($section->skill->slug === Skill::LISTENING) {
            if (! $this->capabilities->isUsable($languageCode, 'tts')) {
                return 'audio_not_supported_for_language';
            }
            $query->whereNotNull('audio_path');
        }

        return $query->exists() ? null : 'no_content_available';
    }

    private function assertInProgress(AssessmentAttempt $attempt): void
    {
        if (! $attempt->isInProgress()) {
            throw new DomainError('This assessment attempt is no longer in progress.', 'attempt_closed');
        }
    }
}
