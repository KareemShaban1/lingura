<?php

namespace App\Domain\Exams;

use App\Domain\AI\Exceptions\AiException;
use App\Domain\Assessment\Engine\CefrScale;
use App\Domain\Assessment\Engine\IrtModel;
use App\Domain\Billing\QuotaGuard;
use App\Domain\Languages\CapabilityMatrix;
use App\Domain\Shared\DomainError;
use App\Domain\Shared\LearningEventRecorder;
use App\Domain\Speech\TranscriptionService;
use App\Domain\Writing\SpeakingEvaluator;
use App\Domain\Writing\TextStats;
use App\Domain\Writing\WritingEvaluator;
use App\Models\AudioRecording;
use App\Models\CefrLevel;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptSection;
use App\Models\ExamFramework;
use App\Models\ExamItem;
use App\Models\LearnerProfile;
use App\Models\Skill;
use App\Models\WritingSubmission;
use Illuminate\Support\Facades\DB;

/**
 * Timed exam practice. Timers are server-authoritative: each section gets a deadline when it starts;
 * answers after the deadline (plus a small network grace) are refused and the section is closed with
 * what was answered in time. Results are estimates (ExamScorer), never official scores.
 */
class ExamService
{
    public function __construct(
        private readonly QuotaGuard $quota,
        private readonly CapabilityMatrix $capabilities,
        private readonly WritingEvaluator $writing,
        private readonly SpeakingEvaluator $speaking,
        private readonly TranscriptionService $transcriber,
        private readonly LearningEventRecorder $events,
    ) {}

    public function start(LearnerProfile $profile, ExamFramework $framework): ExamAttempt
    {
        if (! $framework->is_active || $framework->language_id !== $profile->target_language_id) {
            throw new DomainError('This exam is not available for your language.', 'exam_unavailable', 422);
        }
        $form = $framework->activeForm() ?? throw new DomainError('No practice test is available yet.', 'exam_unavailable', 422);
        $existing = ExamAttempt::where('learner_profile_id', $profile->id)->where('exam_form_id', $form->id)->where('status', 'in_progress')->first();
        if ($existing) {
            return $existing; // resume; not charged twice
        }

        return $this->quota->using($profile->user, 'exam_mocks', fn () => DB::transaction(function () use ($profile, $framework, $form) {
            $attempt = ExamAttempt::create(['learner_profile_id' => $profile->id, 'exam_form_id' => $form->id, 'status' => 'in_progress', 'started_at' => now()]);
            $code = $framework->language->code;
            foreach ($framework->sections()->with('skill')->get() as $section) {
                $reason = match ($section->skill->slug) {
                    Skill::LISTENING => $form->items()->where('exam_section_id', $section->id)->whereNull('audio_path')->exists() ? 'audio_not_ready' : null,
                    Skill::WRITING => $this->capabilities->isUsable($code, 'writing_evaluation') ? null : 'not_supported',
                    Skill::SPEAKING => $this->capabilities->isUsable($code, 'stt') ? null : 'not_supported',
                    default => null,
                };
                $attempt->sections()->create(['exam_section_id' => $section->id, 'status' => $reason ? 'unavailable' : 'pending', 'reason' => $reason]);
            }
            $this->events->record($profile, 'exam.started', $attempt, metadata: ['framework' => $framework->slug]);

            return $attempt;
        }));
    }

    /** Closes sections whose time is over. Called on every read so a closed tab cannot keep a timer open. */
    public function sweep(ExamAttempt $attempt): ExamAttempt
    {
        if (! $attempt->isInProgress()) {
            return $attempt;
        }
        foreach ($attempt->sections()->where('status', 'in_progress')->with('section.skill')->get() as $s) {
            if ($this->pastDeadline($s, $this->isProductive($s) ? 'productive_grace_seconds' : 'grace_seconds')) {
                $this->isProductive($s) ? $s->update(['status' => 'expired', 'reason' => 'time_over', 'completed_at' => now()]) : $this->scoreClosed($s, 'expired');
            }
        }
        $this->finalizeIfDone($attempt);

        return $attempt->fresh();
    }

    public function startSection(ExamAttempt $attempt, ExamAttemptSection $s): ExamAttemptSection
    {
        $this->assertInProgress($attempt);
        if ($attempt->sections()->where('status', 'in_progress')->where('id', '!=', $s->id)->exists()) {
            throw new DomainError('Finish the current section first.', 'section_in_progress', 409);
        }
        if ($s->status === 'in_progress') {
            return $s;
        }
        if ($s->status !== 'pending') {
            throw new DomainError('This section is closed.', 'section_closed', 409);
        }
        $s->update(['status' => 'in_progress', 'started_at' => now(), 'deadline_at' => now()->addSeconds($s->section->duration_seconds)]);

        return $s;
    }

    public function answer(ExamAttempt $attempt, string $itemId, int $optionId): ExamAnswer
    {
        $this->assertInProgress($attempt);
        $item = ExamItem::where('public_id', $itemId)->where('exam_form_id', $attempt->exam_form_id)->with('options')->firstOrFail();
        $s = $attempt->sections()->where('exam_section_id', $item->exam_section_id)->with('section.skill')->firstOrFail();
        if ($s->status !== 'in_progress') {
            throw new DomainError('This section is not open.', 'section_closed', 409);
        }
        if ($this->pastDeadline($s, 'grace_seconds')) {
            $this->scoreClosed($s, 'expired');
            $this->finalizeIfDone($attempt);
            throw new DomainError('Time is up for this section.', 'section_time_over', 409);
        }
        $option = $item->options->firstWhere('id', $optionId) ?? throw new DomainError('Invalid option.', 'invalid_option', 422);

        // Like a paper exam, answers can be changed until the section is submitted.
        return ExamAnswer::updateOrCreate(
            ['exam_attempt_section_id' => $s->id, 'exam_item_id' => $item->id],
            ['exam_item_option_id' => $option->id, 'is_correct' => $option->is_correct, 'answered_at' => now()],
        );
    }

    /** @param array{text?: string, recording?: AudioRecording} $payload */
    public function submit(ExamAttempt $attempt, ExamAttemptSection $s, array $payload = []): ExamAttemptSection
    {
        $this->assertInProgress($attempt);
        if ($s->status !== 'in_progress') {
            throw new DomainError('This section is not open.', 'section_closed', 409);
        }
        $skill = $s->section->skill->slug;
        if (! $this->isProductive($s)) {
            $this->scoreClosed($s, $this->pastDeadline($s, 'grace_seconds') ? 'expired' : 'completed');
        } elseif ($this->pastDeadline($s, 'productive_grace_seconds')) {
            $s->update(['status' => 'expired', 'reason' => 'time_over', 'completed_at' => now()]);
        } else {
            $skill === Skill::WRITING ? $this->scoreWriting($attempt, $s, (string) ($payload['text'] ?? '')) : $this->scoreSpeaking($attempt, $s, $payload['recording'] ?? null);
        }
        $this->finalizeIfDone($attempt);

        return $s->fresh();
    }

    public function skip(ExamAttempt $attempt, ExamAttemptSection $s): ExamAttemptSection
    {
        $this->assertInProgress($attempt);
        if (! $s->isOpen()) {
            throw new DomainError('This section is closed.', 'section_closed', 409);
        }
        $s->update(['status' => 'skipped', 'completed_at' => now()]);
        $this->finalizeIfDone($attempt);

        return $s;
    }

    private function scoreClosed(ExamAttemptSection $s, string $status): void
    {
        $attempt = $s->attempt;
        $items = ExamItem::where('exam_form_id', $attempt->exam_form_id)->where('exam_section_id', $s->exam_section_id)->withCount('options')->get();
        $answers = $s->answers()->get()->keyBy('exam_item_id');
        // Unanswered questions count as wrong, as in the real exam.
        $responses = $items->map(fn ($i) => ['difficulty' => $i->difficulty, 'options' => $i->options_count, 'correct' => (bool) $answers->get($i->id)?->is_correct])->all();
        $framework = $attempt->form->framework;
        $estimate = IrtModel::fromConfig()->estimate($responses, (float) $framework->priorLevel->anchor_theta);

        $this->close($s, $status, $estimate['theta'], $estimate['se'], [
            'raw_correct' => $answers->where('is_correct', true)->count(),
            'raw_total' => $items->count(),
        ]);
    }

    private function scoreWriting(ExamAttempt $attempt, ExamAttemptSection $s, string $text): void
    {
        $task = $this->task($attempt, $s);
        $words = TextStats::of($text)['words'];
        if ($words < 3) {
            throw new DomainError('Write your answer before submitting.', 'text_too_short', 422);
        }
        $submission = WritingSubmission::create([
            'learner_profile_id' => $attempt->learner_profile_id, 'production_task_id' => $task->id,
            'text' => mb_substr($text, 0, 8000), 'word_count' => $words, 'status' => 'pending',
        ]);
        try {
            $evaluation = $this->writing->evaluate($submission);
        } catch (AiException) {
            $submission->update(['status' => 'failed']);
            $s->update(['status' => 'unavailable', 'reason' => 'evaluation_unavailable', 'writing_submission_id' => $submission->id, 'completed_at' => now()]);

            return;
        }
        $this->closeProductive($s, $evaluation->level, $words < $task->min_words, ['writing_submission_id' => $submission->id]);
    }

    private function scoreSpeaking(ExamAttempt $attempt, ExamAttemptSection $s, ?AudioRecording $recording): void
    {
        if (! $recording) {
            throw new DomainError('Record your answer first.', 'recording_required', 422);
        }
        $task = $this->task($attempt, $s);
        try {
            $transcript = $this->transcriber->transcribe($recording, $task->language->code, 'stt.exam')->text;
            if (TextStats::of($transcript)['words'] < 3) {
                $s->update(['status' => 'completed', 'reason' => 'no_speech_detected', 'audio_recording_id' => $recording->id, 'completed_at' => now()]);

                return;
            }
            $result = $this->speaking->evaluate($attempt->learnerProfile, $task, $transcript, $recording->duration_ms);
        } catch (AiException) {
            $s->update(['status' => 'unavailable', 'reason' => 'evaluation_unavailable', 'audio_recording_id' => $recording->id, 'completed_at' => now()]);

            return;
        }
        $this->closeProductive($s, $result['level'], $result['words'] < $task->min_words, ['audio_recording_id' => $recording->id]);
    }

    /** Rubric levels sit at their level anchor with the wider standard error used for productive placement. */
    private function closeProductive(ExamAttemptSection $s, CefrLevel $level, bool $short, array $extra): void
    {
        $c = config('learning.productive_assessment');
        $this->close($s, 'completed', (float) $level->anchor_theta, $short ? $c['short_response_standard_error'] : $c['standard_error'], $extra);
    }

    private function close(ExamAttemptSection $s, string $status, float $theta, float $se, array $extra = []): void
    {
        $framework = $s->attempt->form->framework->loadMissing(['conversions', 'targetLevel']);
        $scale = CefrScale::load();
        $s->update([
            'status' => $status, 'theta' => round($theta, 3), 'standard_error' => round($se, 3),
            'cefr_level_id' => $scale->levelForTheta($theta)->id,
            'estimate' => $this->estimate($framework, $theta, $se),
            'completed_at' => now(),
            ...$extra,
        ]);
    }

    /** @return array<string, mixed> */
    public function estimate(ExamFramework $framework, float $theta, float $se): array
    {
        if ($framework->scale === 'band') {
            return ExamScorer::bandEstimate($theta, $se, $framework->conversions->map(fn ($c) => ['theta' => $c->theta, 'value' => $c->value])->all());
        }
        $target = $framework->targetLevel;
        $below = CefrLevel::where('ordinal', $target->ordinal - 1)->first();
        $cut = $below ? ($below->anchor_theta + $target->anchor_theta) / 2 : $target->anchor_theta - 0.5;

        return ExamScorer::readiness($theta, $se, $cut);
    }

    private function finalizeIfDone(ExamAttempt $attempt): void
    {
        $attempt->refresh();
        if (! $attempt->isInProgress() || $attempt->sections()->whereIn('status', ExamAttemptSection::OPEN)->exists()) {
            return;
        }
        $framework = $attempt->form->framework;
        $sections = $attempt->sections()->get();
        $scored = $sections->whereNotNull('estimate')->pluck('estimate')->values()->all();
        $overall = ExamScorer::overall($framework->overall_rule, $scored, $sections->count());
        $attempt->update(['status' => 'completed', 'completed_at' => now(), 'result' => [
            'overall' => $overall,
            'scale' => $framework->scale,
            'sections_scored' => count($scored),
            'sections_total' => $sections->count(),
        ]]);
        $this->events->record($attempt->learnerProfile, 'exam.completed', $attempt, metadata: ['framework' => $framework->slug, 'overall' => $overall]);
    }

    private function task(ExamAttempt $attempt, ExamAttemptSection $s)
    {
        return $attempt->form->tasks()->where('exam_section_id', $s->exam_section_id)->with('task.language')->firstOrFail()->task;
    }

    private function isProductive(ExamAttemptSection $s): bool
    {
        return in_array($s->section->skill->slug, [Skill::WRITING, Skill::SPEAKING], true);
    }

    private function pastDeadline(ExamAttemptSection $s, string $grace): bool
    {
        return $s->deadline_at !== null && now()->greaterThan($s->deadline_at->copy()->addSeconds(config("learning.exams.{$grace}")));
    }

    private function assertInProgress(ExamAttempt $attempt): void
    {
        if (! $attempt->isInProgress()) {
            throw new DomainError('This practice test is finished.', 'attempt_closed', 409);
        }
    }
}
