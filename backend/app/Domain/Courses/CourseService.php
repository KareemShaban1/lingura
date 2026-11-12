<?php

namespace App\Domain\Courses;

use App\Domain\Shared\DomainError;
use App\Domain\Shared\LearningEventRecorder;
use App\Domain\Vocabulary\VocabularyService;
use App\Models\Course;
use App\Models\ExerciseAttempt;
use App\Models\LearnerProfile;
use App\Models\Lesson;
use App\Models\LessonProgress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CourseService
{
    public function __construct(private readonly VocabularyService $vocabulary, private readonly LearningEventRecorder $events) {}

    /** @return Collection<int, Course> */
    public function courses(LearnerProfile $profile): Collection
    {
        return Course::where('language_id', $profile->target_language_id)->where('review_status', 'published')
            ->with(['translations', 'levelFrom', 'levelTo', 'units.translations', 'units.lessons' => fn ($q) => $q->where('review_status', 'published'), 'units.lessons.translations', 'units.lessons.skill', 'units.lessons.level'])
            ->orderBy('position')->get();
    }

    /** @return Collection<int, LessonProgress> keyed by lesson id */
    public function progressMap(LearnerProfile $profile): Collection
    {
        return LessonProgress::where('learner_profile_id', $profile->id)->get()->keyBy('lesson_id');
    }

    /** The first lesson in course order that is not completed. */
    public function nextLesson(LearnerProfile $profile, ?int $skillId = null): ?Lesson
    {
        $done = LessonProgress::where('learner_profile_id', $profile->id)->where('status', 'completed')->pluck('lesson_id');
        foreach ($this->courses($profile) as $course) {
            foreach ($course->units as $unit) {
                foreach ($unit->lessons as $lesson) {
                    if (! $done->contains($lesson->id) && ($skillId === null || $lesson->skill_id === $skillId)) {
                        return $lesson;
                    }
                }
            }
        }

        return null;
    }

    /** Next uncompleted lesson after $current that is not in $excludedKeys ("lesson:{id}"). */
    public function nextLessonAfter(LearnerProfile $profile, Lesson $current, array $excludedKeys): ?Lesson
    {
        $done = LessonProgress::where('learner_profile_id', $profile->id)->where('status', 'completed')->pluck('lesson_id');
        $passed = false;
        foreach ($this->courses($profile) as $course) {
            foreach ($course->units as $unit) {
                foreach ($unit->lessons as $lesson) {
                    if ($lesson->id === $current->id) {
                        $passed = true;

                        continue;
                    }
                    if ($passed && ! $done->contains($lesson->id) && ! in_array('lesson:'.$lesson->id, $excludedKeys, true)) {
                        return $lesson;
                    }
                }
            }
        }

        return null;
    }

    public function assertBelongsTo(LearnerProfile $profile, Lesson $lesson): void
    {
        if ($lesson->unit->course->language_id !== $profile->target_language_id) {
            throw new DomainError('This lesson is for a different language.', 'wrong_language', 422);
        }
    }

    /**
     * A lesson is complete when every available exercise in it has a completed attempt.
     * Completion adds the lesson's words to the learner's spaced-repetition deck.
     */
    public function complete(LearnerProfile $profile, Lesson $lesson): LessonProgress
    {
        $this->assertBelongsTo($profile, $lesson);
        $exercises = $lesson->exercises()->with('questions')->get()->filter->isAvailable();
        $latest = ExerciseAttempt::where('learner_profile_id', $profile->id)
            ->whereIn('exercise_id', $exercises->pluck('id'))->where('status', 'completed')
            ->orderByDesc('completed_at')->get()->unique('exercise_id');

        $missing = $exercises->pluck('id')->diff($latest->pluck('exercise_id'));
        if ($missing->isNotEmpty()) {
            throw new DomainError('Finish all exercises in this lesson first.', 'lesson_incomplete');
        }

        return DB::transaction(function () use ($profile, $lesson, $latest) {
            $progress = LessonProgress::firstOrCreate(
                ['learner_profile_id' => $profile->id, 'lesson_id' => $lesson->id],
                ['status' => 'in_progress', 'started_at' => now()],
            );
            $alreadyDone = $progress->status === 'completed';
            $score = $latest->isNotEmpty() ? round($latest->avg('score'), 4) : null;
            $progress->update([
                'status' => 'completed',
                'completed_at' => $progress->completed_at ?? now(),
                'best_score' => max($progress->best_score ?? 0, $score ?? 0),
            ]);
            $added = $this->vocabulary->addLessonWords($profile, $lesson);
            if (! $alreadyDone) {
                $this->events->record($profile, 'lesson.completed', $lesson, $lesson->skill_id, $score, ['words_added' => $added]);
            }

            return $progress;
        });
    }
}
