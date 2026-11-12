<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\UserVocabulary;
use Tests\TestCase;

class LessonsAndVocabularyTest extends TestCase
{
    private function completeExercise(string $profileId, string $exercisePublicId, string $lessonId): void
    {
        $attempt = $this->postJson("/api/v1/learner-profiles/{$profileId}/exercises/{$exercisePublicId}/attempts", ['lesson_id' => $lessonId])
            ->assertSuccessful()->json('data');
        $exercise = Exercise::where('public_id', $exercisePublicId)->with('questions.acceptedAnswers', 'questions.options')->first();
        foreach ($exercise->questions as $q) {
            $payload = match ($exercise->type) {
                'cloze', 'dictation' => ['text' => $q->acceptedAnswers->first()->answer],
                'ordering' => ['order' => $q->options->sortBy('correct_position')->pluck('id')->all()],
                default => ['option_id' => $q->options->firstWhere('is_correct', true)->id],
            };
            $this->postJson("/api/v1/exercise-attempts/{$attempt['id']}/answers", ['question_id' => $q->id, ...$payload])->assertOk();
        }
        $this->postJson("/api/v1/exercise-attempts/{$attempt['id']}/finish")->assertOk();
    }

    public function test_course_tree_lesson_detail_and_completion_adds_words_to_the_deck(): void
    {
        $profile = $this->makeLearner('fr');
        $this->actingAsUser($profile->user);
        $id = $profile->public_id;

        $courses = $this->getJson("/api/v1/learner-profiles/{$id}/courses")->assertOk()->json();
        $this->assertSame('French foundations', $courses['data'][0]['title']);
        $this->assertSame(4, $courses['data'][0]['lessons_total']);
        $first = $courses['data'][0]['units'][0]['lessons'][0];
        $this->assertSame($first['id'], $courses['next_lesson_id']);

        $lesson = $this->getJson("/api/v1/learner-profiles/{$id}/lessons/{$first['id']}")->assertOk()->json('data');
        $this->assertNotEmpty($lesson['objectives']);
        $this->assertSame('etre_present', $lesson['grammar_topic']['slug']);
        $this->assertNotEmpty($lesson['grammar_topic']['explanation']);
        $this->assertCount(6, $lesson['words']);

        $this->postJson("/api/v1/learner-profiles/{$id}/lessons/{$first['id']}/complete")->assertStatus(409)->assertJsonPath('code', 'lesson_incomplete');
        foreach ($lesson['exercises'] as $exercise) {
            $this->completeExercise($id, $exercise['id'], $first['id']);
        }
        $this->postJson("/api/v1/learner-profiles/{$id}/lessons/{$first['id']}/complete")->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertSame(6, UserVocabulary::where('learner_profile_id', $profile->id)->count());
        $next = $this->getJson("/api/v1/learner-profiles/{$id}/courses")->json('next_lesson_id');
        $this->assertNotSame($first['id'], $next);
    }

    public function test_lesson_content_follows_the_interface_language(): void
    {
        $profile = $this->makeLearner('de', user: $this->makeUser(['interface_language' => 'ar']));
        $this->actingAsUser($profile->user);
        $lesson = Lesson::where('key', 'de-u1-l1')->first();

        $data = $this->getJson("/api/v1/learner-profiles/{$profile->public_id}/lessons/{$lesson->public_id}")->json('data');
        $this->assertSame('من أنت؟', $data['title']);
        $this->assertSame('الفعل sein في المضارع', $data['grammar_topic']['title']);
        $this->assertSame('مرحبًا', $data['words'][0]['meaning']);
    }

    public function test_spaced_repetition_review_cycle_and_summary(): void
    {
        $profile = $this->makeLearner('it');
        $this->actingAsUser($profile->user);
        $id = $profile->public_id;
        $word = $this->getJson("/api/v1/learner-profiles/{$id}/vocabulary/lookup?q=libri")->assertOk()->json('data.0');
        $this->assertSame('libro', $word['lemma'], 'inflected forms find the lemma');

        $card = $this->postJson("/api/v1/learner-profiles/{$id}/vocabulary", ['word_id' => $word['id']])->assertCreated()->json('data');
        $this->postJson("/api/v1/learner-profiles/{$id}/vocabulary", ['word_id' => $word['id']])->assertOk(); // idempotent

        $queue = $this->getJson("/api/v1/learner-profiles/{$id}/vocabulary/review-queue")->assertOk()->json('data');
        $this->assertSame($card['id'], $queue[0]['id']);

        $reviewed = $this->postJson("/api/v1/user-vocabulary/{$card['id']}/reviews", ['rating' => 3, 'duration_ms' => 2500])->assertOk()->json('data');
        $this->assertSame('review', $reviewed['state']);
        $this->assertFalse($reviewed['is_due']);
        $this->assertSame(0, count($this->getJson("/api/v1/learner-profiles/{$id}/vocabulary/review-queue")->json('data')));

        $this->travel(5)->days();
        $again = $this->postJson("/api/v1/user-vocabulary/{$card['id']}/reviews", ['rating' => 1])->assertOk()->json('data');
        $this->assertSame('relearning', $again['state']);
        $this->assertSame(1, $again['lapses']);

        $summary = $this->getJson("/api/v1/learner-profiles/{$id}/vocabulary/summary")->json('data');
        $this->assertSame(1, $summary['total']);
        $this->assertSame(0.0, (float) $summary['retention_30d'], 'one scheduled review, forgotten');

        $this->postJson("/api/v1/user-vocabulary/{$card['id']}/reviews", ['rating' => 7])->assertUnprocessable();
        $this->actingAsUser($this->makeUser());
        $this->postJson("/api/v1/user-vocabulary/{$card['id']}/reviews", ['rating' => 3])->assertForbidden();
    }

    public function test_words_are_extracted_from_text_and_collections_work(): void
    {
        $profile = $this->makeLearner('es');
        $this->actingAsUser($profile->user);
        $id = $profile->public_id;

        $found = $this->postJson("/api/v1/learner-profiles/{$id}/vocabulary/extract", [
            'text' => 'Los sábados compramos pan y fruta en el mercado con mi familia.',
        ])->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['compramos', 'pan', 'mercado', 'familia'], array_keys($found));
        $this->assertSame('comprar', $found['compramos']['lemma']);
        $this->assertFalse($found['pan']['in_deck']);

        $collection = $this->postJson("/api/v1/learner-profiles/{$id}/vocabulary-collections", ['name' => 'Market'])->assertCreated()->json('data');
        $this->postJson("/api/v1/learner-profiles/{$id}/vocabulary-collections", ['name' => 'Market'])->assertUnprocessable();
        $card = $this->postJson("/api/v1/learner-profiles/{$id}/vocabulary", ['word_id' => $found['pan']['id'], 'source' => 'reading', 'collection_id' => $collection['id']])
            ->assertCreated()->json('data');
        $this->assertSame('Market', $card['collections'][0]['name']);

        $inCollection = $this->getJson("/api/v1/learner-profiles/{$id}/vocabulary?collection={$collection['id']}")->json('data');
        $this->assertCount(1, $inCollection);
        $this->postJson("/api/v1/vocabulary-collections/{$collection['id']}/items", ['user_vocabulary_id' => $card['id']])->assertJsonPath('data.attached', false);
    }
}
