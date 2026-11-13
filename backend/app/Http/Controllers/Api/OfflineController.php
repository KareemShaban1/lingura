<?php

namespace App\Http\Controllers\Api;

use App\Domain\Offline\OfflinePackService;
use App\Http\Controllers\Controller;
use App\Models\LearnerProfile;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfflineController extends Controller
{
    public function __construct(private readonly OfflinePackService $offline) {}

    public function pack(LearnerProfile $learnerProfile, Lesson $lesson): JsonResponse
    {
        $this->authorize('view', $learnerProfile);

        return response()->json(['data' => $this->offline->pack($learnerProfile, $lesson)])->header('Cache-Control', 'private, no-store');
    }

    public function sync(Request $request, LearnerProfile $learnerProfile): JsonResponse
    {
        $this->authorize('update', $learnerProfile);
        $data = $request->validate([
            'attempts' => ['present', 'array', 'max:50'],
            'attempts.*.client_ref' => ['required', 'string', 'max:40'],
            'attempts.*.exercise_id' => ['required', 'string', 'size:26'],
            'attempts.*.lesson_id' => ['nullable', 'string', 'size:26'],
            'attempts.*.answers' => ['required', 'array', 'max:60'],
            'attempts.*.answers.*.question_id' => ['required', 'integer'],
            'attempts.*.answers.*.option_id' => ['nullable', 'integer'],
            'attempts.*.answers.*.text' => ['nullable', 'string', 'max:1000'],
            'attempts.*.answers.*.order' => ['nullable', 'array', 'max:30'],
            'attempts.*.answers.*.order.*' => ['integer'],
            'complete_lessons' => ['nullable', 'array', 'max:20'],
            'complete_lessons.*' => ['string', 'size:26'],
        ]);

        return response()->json(['data' => $this->offline->sync($learnerProfile, $data['attempts'], $data['complete_lessons'] ?? [])]);
    }
}
