<?php

namespace App\Domain\Practice;

use App\Domain\Shared\DomainError;
use App\Domain\Shared\LearningEventRecorder;
use App\Models\Exercise;
use App\Models\ExerciseAnswer;
use App\Models\ExerciseAttempt;
use App\Models\ExerciseQuestion;
use App\Models\GrammarMastery;
use App\Models\LearnerProfile;
use App\Models\LearningSession;
use App\Models\Lesson;
use App\Models\LessonProgress;
use Illuminate\Support\Facades\DB;

/**
 * Practice exercises: attempts, deterministic scoring with immediate feedback, grammar mastery,
 * reading speed and daily progress. Unlike the placement test, practice reveals the answer and explanation.
 */
class ExerciseService
{
    /** Weight of the newest answer in the exponentially weighted mastery estimate. */
    private const MASTERY_ALPHA = 0.3;

    /** Reading speeds outside this range are treated as unreliable (skimming or idle tab). */
    private const WPM_RANGE = [20, 600];

    public function __construct(
        private readonly AnswerChecker $checker,
        private readonly ProgressRecorder $progress,
        private readonly LearningEventRecorder $events,
    ) {}

    public function start(LearnerProfile $profile, Exercise $exercise, ?Lesson $lesson = null, ?LearningSession $session = null): ExerciseAttempt
    {
        if ($exercise->language_id !== $profile->target_language_id) {
            throw new DomainError('This exercise is for a different language.', 'wrong_language', 422);
        }
        $exercise->loadMissing('questions');
        if (! $exercise->isAvailable()) {
            throw new DomainError('This exercise is not available yet.', 'exercise_unavailable');
        }
        if ($session && $session->learner_profile_id !== $profile->id) {
            throw new DomainError('Invalid learning session.', 'invalid_session', 422);
        }

        return DB::transaction(function () use ($profile, $exercise, $lesson, $session) {
            $open = ExerciseAttempt::where('learner_profile_id', $profile->id)
                ->where('exercise_id', $exercise->id)
                ->where('status', 'in_progress')
                ->lockForUpdate()->first();
            if ($open) {
                return $open;
            }
            if ($lesson) {
                LessonProgress::firstOrCreate(
                    ['learner_profile_id' => $profile->id, 'lesson_id' => $lesson->id],
                    ['status' => 'in_progress', 'started_at' => now()],
                );
            }
            $attempt = ExerciseAttempt::create([
                'learner_profile_id' => $profile->id,
                'exercise_id' => $exercise->id,
                'lesson_id' => $lesson?->id,
                'learning_session_id' => $session?->id,
                'questions_total' => $exercise->questions->count(),
                'started_at' => now(),
            ]);
            $this->events->record($profile, 'exercise.started', $attempt, $exercise->skill_id);

            return $attempt;
        });
    }

    /**
     * @param  array{option_id?: int, text?: string, order?: list<int>, response_time_ms?: int}  $payload
     */
    public function answer(ExerciseAttempt $attempt, int $questionId, array $payload): ExerciseAnswer
    {
        if ($attempt->isCompleted()) {
            throw new DomainError('This attempt is already finished.', 'attempt_closed');
        }

        return DB::transaction(function () use ($attempt, $questionId, $payload) {
            $attempt = ExerciseAttempt::whereKey($attempt->id)->lockForUpdate()->first();
            $question = ExerciseQuestion::with(['options', 'acceptedAnswers'])
                ->where('exercise_id', $attempt->exercise_id)->find($questionId)
                ?? throw new DomainError('This question is not part of the exercise.', 'invalid_question', 422);

            $existing = $attempt->answers()->where('exercise_question_id', $question->id)->first();
            if ($existing) {
                return $existing; // idempotent: the first answer counts
            }

            $exercise = $attempt->exercise;
            [$result, $response, $optionId] = $this->score($exercise->type, $question, $payload);

            $answer = $attempt->answers()->create([
                'exercise_question_id' => $question->id,
                'selected_option_id' => $optionId,
                'response_text' => $response,
                'is_correct' => $result['is_correct'],
                'score' => $result['score'],
                'feedback_code' => $result['feedback_code'],
                'response_time_ms' => isset($payload['response_time_ms']) ? min((int) $payload['response_time_ms'], 3_600_000) : null,
                'answered_at' => now(),
            ]);
            $attempt->update([
                'questions_answered' => $attempt->questions_answered + 1,
                'score_sum' => $attempt->score_sum + $result['score'],
            ]);

            $profile = $attempt->learnerProfile;
            if ($exercise->grammar_topic_id) {
                $this->updateMastery($profile, $exercise->grammar_topic_id, $result['score'], $result['is_correct']);
            }
            $this->progress->increment($profile, $exercise->skill_id, [
                'questions_answered' => 1,
                'questions_correct' => $result['is_correct'] ? 1 : 0,
            ]);

            return $answer;
        });
    }

    public function finish(ExerciseAttempt $attempt, ?int $readingSeconds = null): ExerciseAttempt
    {
        if ($attempt->isCompleted()) {
            return $attempt;
        }

        return DB::transaction(function () use ($attempt, $readingSeconds) {
            $attempt = ExerciseAttempt::whereKey($attempt->id)->lockForUpdate()->first();
            if ($attempt->isCompleted()) {
                return $attempt;
            }
            $exercise = $attempt->exercise;
            $score = $attempt->questions_total ? $attempt->score_sum / $attempt->questions_total : 0;

            $words = $wpm = null;
            if ($exercise->type === 'reading' && $readingSeconds) {
                $words = count(TextNormalizer::words((string) $exercise->stimulus_text));
                $candidate = (int) round($words / max(1, $readingSeconds) * 60);
                $wpm = $candidate >= self::WPM_RANGE[0] && $candidate <= self::WPM_RANGE[1] ? $candidate : null;
            }

            $attempt->update([
                'status' => 'completed',
                'completed_at' => now(),
                'score' => round($score, 4),
                'reading_seconds' => $readingSeconds,
                'words_count' => $words,
                'words_per_minute' => $wpm,
            ]);

            if ($attempt->lesson_id) {
                $progress = LessonProgress::where('learner_profile_id', $attempt->learner_profile_id)->where('lesson_id', $attempt->lesson_id)->first();
                $progress?->update(['best_score' => max($progress->best_score ?? 0, round($score, 4))]);
            }

            $profile = $attempt->learnerProfile;
            $this->progress->increment($profile, $exercise->skill_id, ['exercises_completed' => 1]);
            $this->events->record($profile, 'exercise.completed', $attempt, $exercise->skill_id, round($score, 4), [
                'exercise' => $exercise->key, 'type' => $exercise->type, 'wpm' => $wpm,
            ]);

            return $attempt;
        });
    }

    /** @return array{0: array{is_correct: bool, score: float, feedback_code: ?string}, 1: ?string, 2: ?int} */
    private function score(string $type, ExerciseQuestion $question, array $payload): array
    {
        return match ($type) {
            'mcq', 'reading', 'listening' => isset($payload['option_id'])
                ? [$this->checker->choice($question, (int) $payload['option_id']), null, $question->options->contains('id', (int) $payload['option_id']) ? (int) $payload['option_id'] : null]
                : throw new DomainError('Choose an option.', 'option_required', 422),
            'cloze' => [$this->checker->typed((string) ($payload['text'] ?? ''), $question->acceptedAnswers->pluck('answer')->all()), mb_substr((string) ($payload['text'] ?? ''), 0, 500), null],
            'dictation' => [$this->checker->dictation((string) ($payload['text'] ?? ''), $question->acceptedAnswers->first()->answer), mb_substr((string) ($payload['text'] ?? ''), 0, 1000), null],
            'ordering' => [$this->checker->ordering($question, array_map('intval', $payload['order'] ?? [])), implode(',', array_map('intval', $payload['order'] ?? [])), null],
        };
    }

    private function updateMastery(LearnerProfile $profile, int $topicId, float $score, bool $correct): void
    {
        $row = GrammarMastery::where('learner_profile_id', $profile->id)->where('grammar_topic_id', $topicId)->lockForUpdate()->first();
        $previous = $row?->mastery ?? 0.5; // neutral prior before any evidence
        $mastery = $previous + self::MASTERY_ALPHA * ($score - $previous);

        GrammarMastery::updateOrCreate(
            ['learner_profile_id' => $profile->id, 'grammar_topic_id' => $topicId],
            [
                'mastery' => round($mastery, 4),
                'answers_total' => ($row?->answers_total ?? 0) + 1,
                'answers_correct' => ($row?->answers_correct ?? 0) + ($correct ? 1 : 0),
                'last_practiced_at' => now(),
            ],
        );
    }
}
