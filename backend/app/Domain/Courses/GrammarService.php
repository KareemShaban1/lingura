<?php

namespace App\Domain\Courses;

use App\Models\ExerciseAnswer;
use App\Models\GrammarMastery;
use App\Models\GrammarTopic;
use App\Models\LearnerProfile;
use Illuminate\Support\Collection;

class GrammarService
{
    /** Topics with fewer answers than this show "not enough practice" instead of a mastery figure. */
    public const MIN_EVIDENCE = 3;

    /** @return Collection<int, GrammarTopic> */
    public function topics(LearnerProfile $profile): Collection
    {
        return GrammarTopic::where('language_id', $profile->target_language_id)->where('review_status', 'published')
            ->with(['translations', 'level'])->withCount(['exercises' => fn ($q) => $q->available()])
            ->orderBy('cefr_level_id')->orderBy('position')->get();
    }

    /** @return Collection<int, GrammarMastery> keyed by topic id */
    public function masteryMap(LearnerProfile $profile): Collection
    {
        return GrammarMastery::where('learner_profile_id', $profile->id)->get()->keyBy('grammar_topic_id');
    }

    /** Recent mistakes on a topic — the learner's own common-mistake log. */
    public function recentMistakes(LearnerProfile $profile, GrammarTopic $topic, int $limit = 5): Collection
    {
        return ExerciseAnswer::query()
            ->join('exercise_attempts', 'exercise_attempts.id', '=', 'exercise_answers.exercise_attempt_id')
            ->join('exercises', 'exercises.id', '=', 'exercise_attempts.exercise_id')
            ->where('exercise_attempts.learner_profile_id', $profile->id)
            ->where('exercises.grammar_topic_id', $topic->id)
            ->where('exercise_answers.is_correct', false)
            ->orderByDesc('exercise_answers.answered_at')
            ->select('exercise_answers.*')
            ->with(['question.options', 'question.acceptedAnswers'])
            ->limit($limit)
            ->get();
    }

    /**
     * Topics that need work: low mastery with enough evidence, worst first (feeds the plan generator).
     *
     * @return Collection<int, GrammarMastery>
     */
    public function weakTopics(LearnerProfile $profile, float $threshold = 0.6): Collection
    {
        return GrammarMastery::where('learner_profile_id', $profile->id)
            ->where('answers_total', '>=', self::MIN_EVIDENCE)
            ->where('mastery', '<', $threshold)
            ->with('topic')
            ->orderBy('mastery')
            ->get();
    }
}
