<?php

namespace App\Http\Controllers\Api;

use App\Domain\Courses\GrammarService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExerciseSummaryResource;
use App\Http\Resources\GrammarTopicResource;
use App\Models\Exercise;
use App\Models\GrammarTopic;
use App\Models\LearnerProfile;
use Illuminate\Http\JsonResponse;

class GrammarController extends Controller
{
    public function __construct(private readonly GrammarService $grammar) {}

    public function index(LearnerProfile $learnerProfile): JsonResponse
    {
        $this->authorize('view', $learnerProfile);
        $mastery = $this->grammar->masteryMap($learnerProfile);

        return response()->json(['data' => $this->grammar->topics($learnerProfile)
            ->map(fn ($t) => new GrammarTopicResource($t, $mastery->get($t->id)))]);
    }

    public function show(LearnerProfile $learnerProfile, string $slug): JsonResponse
    {
        $this->authorize('view', $learnerProfile);
        $topic = GrammarTopic::where('language_id', $learnerProfile->target_language_id)->where('slug', $slug)->firstOrFail();
        $mastery = $this->grammar->masteryMap($learnerProfile)->get($topic->id);

        return response()->json(['data' => [
            ...(new GrammarTopicResource($topic, $mastery, detailed: true))->toArray(request()),
            'language' => ['code' => $learnerProfile->targetLanguage->code, 'direction' => $learnerProfile->targetLanguage->direction],
            'exercises' => ExerciseSummaryResource::collection(Exercise::available()->where('grammar_topic_id', $topic->id)->get()),
            'recent_mistakes' => $this->grammar->recentMistakes($learnerProfile, $topic)->map(fn ($a) => [
                'prompt' => $a->question->prompt,
                'given' => $a->response_text ?? $a->question->options->firstWhere('id', $a->selected_option_id)?->label,
                'correct' => $a->question->acceptedAnswers->first()?->answer ?? $a->question->options->firstWhere('is_correct', true)?->label,
                'explanation' => $a->question->explanation,
                'answered_at' => $a->answered_at->toIso8601String(),
            ]),
        ]]);
    }
}
