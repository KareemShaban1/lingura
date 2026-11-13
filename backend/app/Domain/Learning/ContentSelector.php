<?php

namespace App\Domain\Learning;

use App\Domain\Courses\CourseService;
use App\Domain\Languages\CapabilityMatrix;
use App\Domain\Personalization\FormatBandit;
use App\Models\ConversationScenario;
use App\Models\Exercise;
use App\Models\ExerciseAttempt;
use App\Models\GrammarTopic;
use App\Models\LearnerProfile;
use App\Models\Lesson;
use App\Models\ProductionTask;
use App\Models\Skill;
use App\Models\UserVocabulary;
use App\Models\WritingSubmission;

/**
 * Binds a plan block (skill + level + optional topic) to concrete content: a course lesson, a practice
 * exercise, or a flashcard review. Each piece of content is used at most once per plan version.
 * Speaking binds to repeat-after-me or a tutor conversation; writing binds to an AI-evaluated task.
 */
class ContentSelector
{
    /** Exercises completed within this window are not re-assigned (except for revision). */
    private const RECENT_DAYS = 14;

    /** Below this many due cards, a vocabulary block teaches new words instead of reviewing. */
    private const MIN_DUE_FOR_REVIEW = 5;

    /** @var array<string, true> */
    private array $used = [];

    public function __construct(private readonly CourseService $courses, private readonly FormatBandit $bandit) {}

    public function reset(): void
    {
        $this->used = [];
    }

    /**
     * @return array{activity_type: string, lesson: ?Lesson, exercise: ?Exercise, task: ?ProductionTask, scenario: ?ConversationScenario}
     */
    public function bind(LearnerProfile $profile, string $skill, string $activity, int $ordinal, ?string $topic): array
    {
        return $this->bindContent($profile, $skill, $activity, $ordinal, $topic) + ['lesson' => null, 'exercise' => null, 'task' => null, 'scenario' => null];
    }

    private function bindContent(LearnerProfile $profile, string $skill, string $activity, int $ordinal, ?string $topic): array
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
            Skill::SPEAKING => $this->speaking($profile, $ordinal) ?? $none,
            Skill::WRITING => $this->writing($profile, $ordinal) ?? $none,
            default => $none,
        };
    }

    /** Alternate pronunciation practice and tutor conversations; conversations need AI content generation. */
    private function speaking(LearnerProfile $profile, int $ordinal): ?array
    {
        $repeat = isset($this->used['speaking:repeat']) ? null : $this->exercise($profile, ['repeat'], $ordinal, 'speaking_practice');
        if ($repeat) {
            $this->used['speaking:repeat'] = true;

            return $repeat;
        }
        if (! app(CapabilityMatrix::class)->isUsable($profile->targetLanguage->code, 'content_generation')) {
            return null;
        }
        $scenario = ConversationScenario::where('is_active', true)->with(['minLevel', 'maxLevel'])->orderBy('position')->get()
            ->filter(fn ($s) => $s->minLevel->ordinal <= $ordinal && $s->maxLevel->ordinal >= $ordinal)
            ->first(fn ($s) => $this->claim('scenario:'.$s->id));

        return $scenario ? ['activity_type' => 'conversation', 'scenario' => $scenario] : null;
    }

    private function writing(LearnerProfile $profile, int $ordinal): ?array
    {
        if (! app(CapabilityMatrix::class)->isUsable($profile->targetLanguage->code, 'writing_evaluation')) {
            return null;
        }
        $recent = WritingSubmission::where('learner_profile_id', $profile->id)->where('created_at', '>=', now()->subDays(self::RECENT_DAYS))->pluck('production_task_id');
        $task = ProductionTask::where('language_id', $profile->target_language_id)->where('mode', 'written')->where('is_exam', false)->where('review_status', 'published')
            ->whereNotIn('id', $recent)->with('level')->get()
            ->filter(fn ($t) => $t->level->ordinal <= $ordinal + 1)
            ->sortBy(fn ($t) => [abs($t->level->ordinal - $ordinal), $t->id])
            ->first(fn ($t) => $this->claim('task:'.$t->id));

        return $task ? ['activity_type' => 'writing_task', 'task' => $task] : null;
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

        // Best unclaimed candidate per format; the format bandit picks among formats when there is a choice.
        $byFormat = [];
        foreach ($candidates as $e) {
            if (! isset($byFormat[$e->type]) && ! isset($this->used['exercise:'.$e->id])) {
                $byFormat[$e->type] = $e;
            }
        }
        if ($byFormat === []) {
            return null;
        }
        $exercise = $this->bandit->choose($profile, $skillSlug ?? $activity, $byFormat);
        $this->claim('exercise:'.$exercise->id);

        return ['activity_type' => $activity, 'lesson' => null, 'exercise' => $exercise];
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
