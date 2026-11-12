<?php

namespace App\Domain\Learning;

use App\Domain\Courses\CourseService;
use App\Models\Exercise;
use App\Models\ExerciseAttempt;
use App\Models\GrammarTopic;
use App\Models\LearnerProfile;
use App\Models\Lesson;
use App\Models\Skill;
use App\Models\UserVocabulary;

/**
 * Binds a plan block (skill + level + optional topic) to concrete content: a course lesson, a practice
 * exercise, or a flashcard review. Each piece of content is used at most once per plan version.
 * Skills without Phase 2 content (speaking, writing) stay self-guided.
 */
class ContentSelector
{
    /** Exercises completed within this window are not re-assigned (except for revision). */
    private const RECENT_DAYS = 14;

    /** Below this many due cards, a vocabulary block teaches new words instead of reviewing. */
    private const MIN_DUE_FOR_REVIEW = 5;

    /** @var array<string, true> */
    private array $used = [];

    public function __construct(private readonly CourseService $courses) {}

    public function reset(): void
    {
        $this->used = [];
    }

    /**
     * @return array{activity_type: string, lesson: ?Lesson, exercise: ?Exercise}
     */
    public function bind(LearnerProfile $profile, string $skill, string $activity, int $ordinal, ?string $topic): array
    {
        $none = ['activity_type' => $activity, 'lesson' => null, 'exercise' => null];

        if ($activity === 'revision') {
            if (UserVocabulary::where('learner_profile_id', $profile->id)->exists() && $this->claim('flashcards')) {
                return ['activity_type' => 'vocabulary_review', 'lesson' => null, 'exercise' => null];
            }
            $retry = ExerciseAttempt::where('learner_profile_id', $profile->id)->where('status', 'completed')
                ->where('score', '<', 0.8)->latest('completed_at')->with('exercise.questions')->get()
                ->first(fn ($a) => $a->exercise?->isAvailable() && ! isset($this->used['exercise:'.$a->exercise_id]));
            if ($retry && $this->claim('exercise:'.$retry->exercise_id)) {
                return ['activity_type' => 'revision', 'lesson' => null, 'exercise' => $retry->exercise];
            }

            return $none;
        }

        return match ($skill) {
            Skill::VOCABULARY => $this->vocabulary($profile, $ordinal) ?? $none,
            Skill::GRAMMAR => $this->grammar($profile, $ordinal, $topic) ?? $none,
            Skill::READING => $this->exercise($profile, ['reading'], $ordinal, $activity) ?? $none,
            Skill::LISTENING => $this->exercise($profile, ['listening', 'dictation'], $ordinal, $activity) ?? $none,
            default => $none, // speaking & writing: AI-evaluated practice arrives in Phase 3
        };
    }

    private function vocabulary(LearnerProfile $profile, int $ordinal): ?array
    {
        $due = UserVocabulary::where('learner_profile_id', $profile->id)->where('due_at', '<=', now()->endOfDay())->count();
        if ($due >= self::MIN_DUE_FOR_REVIEW && $this->claim('flashcards')) {
            return ['activity_type' => 'vocabulary_review', 'lesson' => null, 'exercise' => null];
        }

        return $this->exercise($profile, ['mcq', 'cloze'], $ordinal, 'vocabulary_building', Skill::VOCABULARY)
            ?? $this->lesson($profile);
    }

    private function grammar(LearnerProfile $profile, int $ordinal, ?string $topic): ?array
    {
        if ($topic) {
            $topicId = GrammarTopic::where('language_id', $profile->target_language_id)->where('slug', $topic)->value('id');
            if ($topicId) {
                $exercise = Exercise::available()->where('grammar_topic_id', $topicId)->get()
                    ->first(fn ($e) => $this->claim('exercise:'.$e->id));
                if ($exercise) {
                    return ['activity_type' => 'grammar_practice', 'lesson' => null, 'exercise' => $exercise];
                }
            }
        }

        return $this->lesson($profile)
            ?? $this->exercise($profile, ['cloze', 'mcq', 'ordering'], $ordinal, 'grammar_practice', Skill::GRAMMAR);
    }

    private function lesson(LearnerProfile $profile): ?array
    {
        $lesson = $this->courses->nextLesson($profile);
        // Walk forward past lessons already used in this plan version.
        while ($lesson && ! $this->claim('lesson:'.$lesson->id)) {
            $lesson = $this->courses->nextLessonAfter($profile, $lesson, array_keys($this->used));
        }

        return $lesson ? ['activity_type' => 'lesson', 'lesson' => $lesson, 'exercise' => null] : null;
    }

    /** @param list<string> $types */
    private function exercise(LearnerProfile $profile, array $types, int $ordinal, string $activity, ?string $skillSlug = null): ?array
    {
        $recent = ExerciseAttempt::where('learner_profile_id', $profile->id)->where('status', 'completed')
            ->where('completed_at', '>=', now()->subDays(self::RECENT_DAYS))->pluck('exercise_id');

        $candidates = Exercise::available()
            ->where('language_id', $profile->target_language_id)
            ->whereIn('type', $types)
            ->when($skillSlug, fn ($q) => $q->whereHas('skill', fn ($s) => $s->where('slug', $skillSlug)))
            ->whereNotIn('id', $recent)
            ->with('level')
            ->get()
            // Closest level first, preferring at-level over easier, never more than one level above.
            ->filter(fn ($e) => $e->level->ordinal <= $ordinal + 1)
            ->sortBy(fn ($e) => [abs($e->level->ordinal - $ordinal), $e->id]);

        $exercise = $candidates->first(fn ($e) => $this->claim('exercise:'.$e->id));

        return $exercise ? ['activity_type' => $activity, 'lesson' => null, 'exercise' => $exercise] : null;
    }

    private function claim(string $key): bool
    {
        if (isset($this->used[$key])) {
            return false;
        }
        $this->used[$key] = true;

        return true;
    }
}
