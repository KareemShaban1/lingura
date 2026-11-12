<?php

namespace App\Http\Resources;

use App\Http\Controllers\Api\PracticeController;
use App\Models\ExerciseAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An attempt with its exercise. Answer keys appear only for questions already answered,
 * and the listening transcript only after the attempt is finished.
 *
 * @mixin ExerciseAttempt
 */
class ExerciseAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['exercise.questions.options', 'exercise.questions.acceptedAnswers', 'exercise.objectives.translations', 'exercise.language', 'answers', 'lesson']);
        $exercise = $this->exercise;
        $answers = $this->answers->keyBy('exercise_question_id');
        $done = $this->isCompleted();

        return [
            'id' => $this->public_id,
            'status' => $this->status,
            'score' => $this->score,
            'questions_total' => $this->questions_total,
            'questions_answered' => $this->questions_answered,
            'words_per_minute' => $this->words_per_minute,
            'lesson_id' => $this->lesson?->public_id,
            'started_at' => $this->started_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'exercise' => [
                ...(new ExerciseSummaryResource($exercise))->toArray($request),
                'language' => ['code' => $exercise->language->code, 'direction' => $exercise->language->direction],
                'objectives' => $exercise->objectives->map(fn ($o) => $o->translate('description'))->values(),
                'stimulus' => $exercise->type === 'listening' && ! $done ? null : $exercise->stimulus_text,
                'has_audio' => $exercise->audio_path !== null,
                'questions' => $exercise->questions->map(function ($q) use ($exercise, $answers) {
                    $answer = $answers->get($q->id);

                    return [
                        'id' => $q->id,
                        'prompt' => $q->prompt,
                        'reference_text' => $q->reference_text,
                        'has_audio' => $q->audio_path !== null,
                        'options' => $q->options->map(fn ($o) => ['id' => $o->id, 'label' => $o->label])->values(),
                        'answer' => $answer ? PracticeController::feedback($exercise, $q, $answer) : null,
                    ];
                })->values(),
            ],
        ];
    }
}
