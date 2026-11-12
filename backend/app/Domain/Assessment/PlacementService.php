<?php

namespace App\Domain\Assessment;

use App\Domain\AI\Exceptions\AiException;
use App\Domain\Assessment\Engine\CefrScale;
use App\Domain\Assessment\Engine\IrtModel;
use App\Domain\Languages\CapabilityMatrix;
use App\Domain\Shared\DomainError;
use App\Domain\Shared\LearningEventRecorder;
use App\Domain\Speech\TranscriptionService;
use App\Domain\Writing\SpeakingEvaluator;
use App\Domain\Writing\TextStats;
use App\Domain\Writing\WritingEvaluator;
use App\Models\Assessment;
use App\Models\AssessmentAnswer;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentAttemptSection;
use App\Models\AssessmentItem;
use App\Models\AssessmentSection;
use App\Models\AudioRecording;
use App\Models\CefrLevel;
use App\Models\LearnerProfile;
use App\Models\ProductionTask;
use App\Models\Skill;
use App\Models\WritingSubmission;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Adaptive placement assessment (engine irt_v1). Each skill section is estimated independently.
 */
class PlacementService
{
    public function __construct(
        private readonly WritingEvaluator $writing,
        private readonly SpeakingEvaluator $speaking,
        private readonly TranscriptionService $transcriber,
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
    /**
     * Returns what to present now: an adaptive item, a productive task (writing/speaking), or null when finished.
     */
    public function next(AssessmentAttempt $attempt): AssessmentItem|ProductionTask|null
    {
        $this->assertInProgress($attempt);

        $item = DB::transaction(function () use ($attempt) {
            AssessmentAttempt::whereKey($attempt->id)->lockForUpdate()->first();
            $sections = $attempt->sections()->with(['section', 'skill'])->get();

            foreach ($sections as $section) {
                if (! $section->isOpen()) {
                    continue;
                }
                if ($section->skill->is_productive) {
                    $task = $section->production_task_id ? ProductionTask::find($section->production_task_id) : $this->selectTask($attempt, $section);
                    if (! $task) {
                        $this->closeSection($section, AssessmentAttemptSection::NOT_AVAILABLE, 'no_content_available');

                        continue;
                    }
                    $section->update(['status' => AssessmentAttemptSection::IN_PROGRESS, 'production_task_id' => $task->id]);

                    return $task;
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

        return $item instanceof AssessmentItem ? $item->load(['options', 'skill', 'level']) : $item?->load(['level', 'translations']);
    }

    /** Placement writing: the response is rubric-scored synchronously (AI with server-side validation). */
    public function submitWriting(AssessmentAttempt $attempt, string $text): AssessmentAttemptSection
    {
        $section = $this->openProductiveSection($attempt, Skill::WRITING);
        $task = $section->productionTask;
        $words = TextStats::of($text)['words'];
        if ($words < 3) {
            throw new DomainError('Write a little more before submitting.', 'text_too_short', 422);
        }
        $submission = WritingSubmission::create([
            'learner_profile_id' => $attempt->learner_profile_id, 'production_task_id' => $task->id,
            'assessment_attempt_id' => $attempt->id, 'text' => mb_substr($text, 0, 5000), 'word_count' => $words,
        ]);
        try {
            $evaluation = $this->writing->evaluate($submission);
        } catch (AiException $e) {
            $submission->update(['status' => 'failed']);
            $this->closeProductive($section, null, null, 'evaluation_unavailable', $submission->id);

            return $section->fresh();
        }
        $this->closeProductive($section, $evaluation->level, $words < $task->min_words, null, $submission->id);

        return $section->fresh();
    }

    /**
     * Placement speaking: transcribe, then rubric-score the transcript with measured fluency facts.
     * The transcript and rubric are stored like a written submission (criteria fluency/grammar/vocabulary/coherence).
     */
    public function submitSpeaking(AssessmentAttempt $attempt, AudioRecording $recording): AssessmentAttemptSection
    {
        $section = $this->openProductiveSection($attempt, Skill::SPEAKING);
        $task = $section->productionTask->load('language');
        try {
            $transcript = $this->transcriber->transcribe($recording, $task->language->code, 'stt.speaking_assessment')->text;
            if (TextStats::of($transcript)['words'] < 3) {
                $this->closeProductive($section, null, null, 'no_speech_detected', null, $recording->id);

                return $section->fresh();
            }
            $result = $this->speaking->evaluate($attempt->learnerProfile, $task, $transcript, $recording->duration_ms);
        } catch (AiException $e) {
            $this->closeProductive($section, null, null, 'evaluation_unavailable', null, $recording->id);

            return $section->fresh();
        }

        $submission = DB::transaction(function () use ($attempt, $task, $transcript, $result) {
            $submission = WritingSubmission::create([
                'learner_profile_id' => $attempt->learner_profile_id, 'production_task_id' => $task->id,
                'assessment_attempt_id' => $attempt->id, 'text' => $transcript, 'word_count' => $result['words'], 'status' => 'evaluated',
            ]);
            $stats = TextStats::of($transcript);
            $evaluation = $submission->evaluation()->create([
                'cefr_level_id' => $result['level']->id,
                'overall_score' => round(collect($result['criteria'])->avg('score'), 2),
                'summary' => $result['summary'], 'corrected_text' => $transcript,
                'sentence_count' => $stats['sentences'], 'avg_sentence_length' => $stats['avg_sentence_length'], 'lexical_variety' => $stats['lexical_variety'],
                'prompt_version' => $result['prompt_version'], 'model_slug' => config('ai.default_provider'),
            ]);
            foreach ($result['criteria'] as $criterion => $c) {
                $evaluation->criteria()->create(['criterion' => $criterion, 'score' => $c['score'], 'comment' => $c['comment']]);
            }

            return $submission;
        });
        $this->closeProductive($section, $result['level'], $result['words'] < $task->min_words, null, $submission->id, $recording->id);

        return $section->fresh();
    }

    private function openProductiveSection(AssessmentAttempt $attempt, string $skill): AssessmentAttemptSection
    {
        $this->assertInProgress($attempt);
        $section = $attempt->sections()->whereHas('skill', fn ($q) => $q->where('slug', $skill))->with('productionTask')->first();
        if (! $section || ! $section->isOpen() || ! $section->productionTask) {
            throw new DomainError('This section is not waiting for a response.', 'section_not_current');
        }

        return $section;
    }

    /**
     * A rubric estimate is placed on the ability scale at its level anchor with a wider standard error
     * than adaptive closed items (wider still for responses shorter than the task asks for).
     */
    private function closeProductive(AssessmentAttemptSection $section, ?CefrLevel $level, ?bool $short, ?string $failure, ?int $submissionId = null, ?int $recordingId = null): void
    {
        $c = config('learning.productive_assessment');
        $section->update($level ? [
            'status' => AssessmentAttemptSection::COMPLETED,
            'theta' => $level->anchor_theta,
            'standard_error' => $short ? $c['short_response_standard_error'] : $c['standard_error'],
            'estimated_level_id' => $level->id,
            'items_answered' => 1,
            'writing_submission_id' => $submissionId,
            'audio_recording_id' => $recordingId,
            'completed_at' => now(),
        ] : [
            'status' => AssessmentAttemptSection::SKIPPED,
            'skip_reason' => $failure,
            'writing_submission_id' => $submissionId,
            'audio_recording_id' => $recordingId,
            'completed_at' => now(),
        ]);
    }

    /** Task closest to the provisional level (lower median of sections finished so far). */
    private function selectTask(AssessmentAttempt $attempt, AssessmentAttemptSection $section): ?ProductionTask
    {
        $profile = $attempt->learnerProfile;
        $ordinals = $attempt->sections()->where('status', AssessmentAttemptSection::COMPLETED)->with('estimatedLevel')->get()
            ->pluck('estimatedLevel.ordinal')->filter(fn ($o) => $o !== null)->values()->all();
        $provisional = CefrScale::load()->lowerMedian($ordinals) ?? $profile->selfReportedLevel?->ordinal ?? 2;
        $mode = $section->skill->slug === Skill::SPEAKING ? 'spoken' : 'written';

        return ProductionTask::where('language_id', $profile->target_language_id)->where('mode', $mode)
            ->where('is_placement', true)->where('review_status', 'published')->with('level')->get()
            ->sortBy(fn ($t) => [abs($t->level->ordinal - $provisional), $t->level->ordinal])->first();
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
        if ($section->skill->is_productive) {
            $needs = $section->skill->slug === Skill::SPEAKING ? ['stt', 'content_generation'] : ['writing_evaluation'];
            foreach ($needs as $feature) {
                if (! $this->capabilities->isUsable($languageCode, $feature)) {
                    return 'not_supported_for_language';
                }
            }
            $mode = $section->skill->slug === Skill::SPEAKING ? 'spoken' : 'written';

            return ProductionTask::where('language_id', $languageId)->where('mode', $mode)->where('is_placement', true)->exists()
                ? null : 'no_content_available';
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
