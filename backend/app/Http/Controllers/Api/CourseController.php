<?php

namespace App\Http\Controllers\Api;

use App\Domain\Courses\CourseService;
use App\Http\Controllers\Controller;
use App\Http\Resources\LessonResource;
use App\Http\Resources\LevelResource;
use App\Models\ExerciseAttempt;
use App\Models\LearnerProfile;
use App\Models\Lesson;
use App\Models\LessonProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    public function __construct(private readonly CourseService $courses) {}

    public function index(Request $request, LearnerProfile $learnerProfile): JsonResponse
    {
        $this->authorize('view', $learnerProfile);
        $progress = $this->courses->progressMap($learnerProfile);
        $next = $this->courses->nextLesson($learnerProfile);

        return response()->json([
            'data' => $this->courses->courses($learnerProfile)->map(fn ($course) => [
                'id' => $course->public_id,
                'title' => $course->translate('title'),
                'description' => $course->translate('description'),
                'level_from' => new LevelResource($course->levelFrom),
                'level_to' => new LevelResource($course->levelTo),
                'units' => $course->units->map(fn ($unit) => [
                    'title' => $unit->translate('title'),
                    'lessons' => $unit->lessons->map(fn ($lesson) => (new LessonResource($lesson, $this->progressFor($progress->get($lesson->id))))->toArray($request))->values(),
                ])->values(),
                'lessons_total' => $course->units->sum(fn ($u) => $u->lessons->count()),
                'lessons_completed' => $course->units->sum(fn ($u) => $u->lessons->filter(fn ($l) => $progress->get($l->id)?->status === 'completed')->count()),
            ]),
            'next_lesson_id' => $next?->public_id,
        ]);
    }

    public function show(LearnerProfile $learnerProfile, Lesson $lesson): LessonResource
    {
        $this->authorize('view', $learnerProfile);
        $this->courses->assertBelongsTo($learnerProfile, $lesson);
        $attempts = ExerciseAttempt::where('learner_profile_id', $learnerProfile->id)->where('status', 'completed')
            ->whereIn('exercise_id', $lesson->exercises()->pluck('exercises.id'))
            ->orderByDesc('completed_at')->get()->unique('exercise_id')->keyBy('exercise_id');
        $progress = LessonProgress::where('learner_profile_id', $learnerProfile->id)->where('lesson_id', $lesson->id)->first();

        return new LessonResource($lesson, [...$this->progressFor($progress), 'attempts' => $attempts], detailed: true);
    }

    public function complete(LearnerProfile $learnerProfile, Lesson $lesson): JsonResponse
    {
        $this->authorize('update', $learnerProfile);
        $progress = $this->courses->complete($learnerProfile, $lesson);

        return response()->json(['data' => ['status' => $progress->status, 'best_score' => $progress->best_score, 'completed_at' => $progress->completed_at?->toIso8601String()]]);
    }

    private function progressFor(?LessonProgress $p): array
    {
        return ['status' => $p?->status ?? 'not_started', 'best_score' => $p?->best_score];
    }
}
