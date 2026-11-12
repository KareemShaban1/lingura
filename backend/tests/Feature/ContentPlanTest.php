<?php

namespace Tests\Feature;

use App\Domain\Learning\PlanGenerator;
use App\Models\Exercise;
use App\Models\GrammarMastery;
use App\Models\GrammarTopic;
use App\Models\LearningPlanItem;
use App\Models\UserVocabulary;
use App\Models\VocabularyWord;
use Tests\TestCase;

class ContentPlanTest extends TestCase
{
    public function test_plan_items_are_bound_to_real_lessons_and_exercises(): void
    {
        $profile = $this->makeLearner('es', ['daily_minutes' => 45]);
        $this->actingAsUser($profile->user);

        $items = collect($this->getJson("/api/v1/learner-profiles/{$profile->public_id}/plan/items")->json('data'));
        $bound = $items->filter(fn ($i) => $i['lesson_id'] || $i['exercise_id']);
        $this->assertGreaterThan(0, $bound->count());
        $this->assertTrue($items->contains('activity_type', 'lesson'));
        $this->assertSame($bound->count(), $bound->map(fn ($i) => $i['lesson_id'] ?? $i['exercise_id'])->unique()->count(), 'no content is assigned twice in one week');

        $lesson = $items->firstWhere('activity_type', 'lesson');
        $this->assertStringStartsWith('Lesson: ', $lesson['title']);

        // Speaking and writing remain self-guided until Phase 3.
        $speaking = $items->firstWhere('skill', 'speaking');
        $this->assertNotNull($speaking);
        $this->assertNull($speaking['lesson_id']);
        $this->assertNull($speaking['exercise_id']);
    }

    public function test_due_flashcards_and_weak_grammar_shape_the_next_plan(): void
    {
        $profile = $this->makeLearner('es', ['daily_minutes' => 60, 'focus_skills' => ['vocabulary', 'grammar']]);
        foreach (VocabularyWord::where('language_id', $profile->target_language_id)->limit(8)->get() as $word) {
            UserVocabulary::create(['learner_profile_id' => $profile->id, 'vocabulary_word_id' => $word->id, 'source' => 'lesson', 'due_at' => now()->subHour()]);
        }
        $topic = GrammarTopic::where('language_id', $profile->target_language_id)->where('slug', 'gustar')->first();
        GrammarMastery::create(['learner_profile_id' => $profile->id, 'grammar_topic_id' => $topic->id, 'mastery' => 0.3,
            'answers_total' => 6, 'answers_correct' => 2, 'last_practiced_at' => now()]);

        $version = app(PlanGenerator::class)->generate($profile, 'user_regenerate');
        $items = $version->items;

        $this->assertTrue($items->contains('activity_type', 'vocabulary_review'), 'due cards produce a flashcard review block');
        $weak = $items->firstWhere('reason_code', 'practice_topic_weakness');
        $this->assertNotNull($weak);
        $this->assertSame('gustar', $weak->topic);
        $this->assertSame($topic->id, Exercise::find($weak->exercise_id)->grammar_topic_id, 'bound to an exercise on that topic');
        $this->assertSame(1, $items->where('activity_type', 'vocabulary_review')->count());
        $this->assertSame('vocabulary', $items->firstWhere('activity_type', 'vocabulary_review')->skill->slug, 'item skill follows its content');
        foreach ($items->whereNotNull('exercise_id') as $item) {
            $this->assertSame(Exercise::find($item->exercise_id)->skill_id, $item->skill_id);
        }
    }

    public function test_starting_a_bound_item_and_a_standalone_session(): void
    {
        $profile = $this->makeLearner('en');
        $this->actingAsUser($profile->user);
        $item = LearningPlanItem::whereNotNull('lesson_id')->firstOrFail();

        $this->postJson("/api/v1/plan-items/{$item->public_id}/start")->assertCreated()
            ->assertJsonPath('data.plan_item.lesson_id', $item->lesson->public_id);

        $this->postJson("/api/v1/learner-profiles/{$profile->public_id}/learning-sessions", ['activity_type' => 'vocabulary_review'])
            ->assertCreated()->assertJsonPath('data.plan_item', null);
        $this->postJson("/api/v1/learner-profiles/{$profile->public_id}/learning-sessions", ['activity_type' => 'nap'])->assertUnprocessable();
    }
}
