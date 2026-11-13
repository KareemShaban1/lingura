<?php

namespace App\Domain\Offline;

use App\Domain\Courses\CourseService;
use App\Domain\Practice\ExerciseService;
use App\Domain\Shared\DomainError;
use App\Models\Exercise;
use App\Models\ExerciseAttempt;
use App\Models\LearnerProfile;
use App\Models\Lesson;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Offline lesson packs. A pack contains a lesson and its text-based exercises *with* answer keys so the
 * device can give instant feedback without a connection. That feedback is provisional: on sync every
 * answer is replayed through the normal exercise service, which re-checks it server-side, so scores,
 * mastery, XP and progress are never taken from the client.
 */
class OfflinePackService
{
    /** Exercise types that need neither audio nor AI and can be checked on the device. */
    public const OFFLINE_TYPES = ['mcq', 'cloze', 'ordering', 'reading'];

    public function __construct(private readonly CourseService $courses, private readonly ExerciseService $exercises) {}

    /** @return array<string, mixed> */
    public function pack(LearnerProfile $profile, Lesson $lesson): array
    {
        $this->courses->assertBelongsTo($profile, $lesson);
        $lesson->loadMissing(['translations', 'unit.course.language', 'grammarTopic.translations', 'grammarTopic.examples', 'words.translations', 'exercises.questions.options', 'exercises.questions.acceptedAnswers', 'exercises.translations']);
        $language = $lesson->unit->course->language;
        $exercises = $lesson->exercises->filter(fn (Exercise $e) => in_array($e->type, self::OFFLINE_TYPES, true) && $e->review_status === 'published')->values();
        $body = [
            'lesson' => [
                'id' => $lesson->public_id, 'title' => $lesson->translate('title'), 'intro' => $lesson->translate('intro'),
                'grammar' => $lesson->grammarTopic ? [
                    'title' => $lesson->grammarTopic->translate('title'), 'explanation' => $lesson->grammarTopic->translate('explanation'),
                    'examples' => $lesson->grammarTopic->examples->map(fn ($x) => ['sentence' => $x->sentence, 'translation' => $x->translation])->values(),
                ] : null,
                'words' => $lesson->words->map(fn ($w) => ['lemma' => $w->lemma, 'pronunciation' => $w->ipa, 'meaning' => $w->translate('meaning')])->values(),
            ],
            'language' => ['code' => $language->code, 'direction' => $language->direction],
            'exercises' => $exercises->map(fn (Exercise $e) => [
                'id' => $e->public_id, 'type' => $e->type, 'title' => $e->translate('title'), 'text' => $e->stimulus_text,
                'questions' => $e->questions->sortBy('position')->values()->map(fn ($q) => [
                    'id' => $q->id, 'prompt' => $q->prompt, 'explanation' => $q->explanation,
                    'options' => $q->options->sortBy('position')->values()->map(fn ($o) => ['id' => $o->id, 'label' => $o->label, 'correct' => (bool) $o->is_correct, 'position' => $o->correct_position]),
                    'answers' => $q->acceptedAnswers->pluck('answer')->values(),
                ]),
            ])->values(),
        ];

        return $body + [
            'version' => hash('sha256', json_encode($body, JSON_UNESCAPED_UNICODE)),
            'downloaded_at' => now()->toIso8601String(),
            'expires_at' => now()->addDays(config('learning.offline.pack_ttl_days'))->toIso8601String(),
            'skipped_exercises' => $lesson->exercises->count() - $exercises->count(), // audio/speech exercises need a connection
        ];
    }

    /**
     * Replays offline attempts. Idempotent per client_ref; one bad attempt never blocks the others.
     *
     * @param  list<array{client_ref: string, exercise_id: string, lesson_id?: ?string, answers: list<array<string, mixed>>}>  $attempts
     * @return list<array{client_ref: string, status: string, score?: ?float, error?: string}>
     */
    public function sync(LearnerProfile $profile, array $attempts, array $completeLessons = []): array
    {
        $results = [];
        foreach ($attempts as $a) {
            $existing = ExerciseAttempt::where('learner_profile_id', $profile->id)->where('client_ref', $a['client_ref'])->first();
            if ($existing) {
                $results[] = ['client_ref' => $a['client_ref'], 'status' => 'duplicate', 'score' => $existing->score];

                continue;
            }
            try {
                $results[] = DB::transaction(function () use ($profile, $a) {
                    $exercise = Exercise::where('public_id', $a['exercise_id'])->firstOrFail();
                    if (! in_array($exercise->type, self::OFFLINE_TYPES, true)) {
                        throw new DomainError('This exercise cannot be done offline.', 'not_offline', 422);
                    }
                    $lesson = isset($a['lesson_id']) ? Lesson::where('public_id', $a['lesson_id'])->first() : null;
                    $attempt = $this->exercises->start($profile, $exercise, $lesson);
                    $attempt->update(['client_ref' => $a['client_ref']]);
                    foreach ($a['answers'] as $answer) {
                        $this->exercises->answer($attempt, (int) $answer['question_id'], array_intersect_key($answer, array_flip(['option_id', 'text', 'order'])));
                    }
                    $attempt = $this->exercises->finish($attempt->fresh());

                    return ['client_ref' => $a['client_ref'], 'status' => 'synced', 'score' => $attempt->score];
                });
            } catch (Throwable $e) {
                report($e);
                $results[] = ['client_ref' => $a['client_ref'], 'status' => 'rejected', 'error' => $e instanceof DomainError ? $e->errorCode : 'invalid'];
            }
        }
        foreach ($completeLessons as $lessonId) {
            $lesson = Lesson::where('public_id', $lessonId)->first();
            try {
                $lesson && $this->courses->complete($profile, $lesson);
            } catch (DomainError) {
                // Not every exercise done yet (e.g. audio ones): the learner finishes the lesson online.
            }
        }

        return $results;
    }
}
