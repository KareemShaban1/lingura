<?php

namespace Tests\Feature;

use App\Domain\Adaptive\AdaptiveEngine;
use App\Domain\Learning\PlanGenerator;
use App\Models\AiFeedback;
use App\Models\CefrLevel;
use App\Models\GrammarMastery;
use App\Models\GrammarTopic;
use App\Models\LearnerMistakePattern;
use App\Models\Skill;
use App\Models\SkillProfile;
use App\Models\UserProgress;
use Tests\TestCase;

class AdaptiveEngineTest extends TestCase
{
    public function test_recurring_mistakes_across_sources_reshape_the_plan(): void
    {
        $profile = $this->makeLearner('es', ['daily_minutes' => 45]);
        foreach (['tutor', 'writing', 'tutor'] as $source) {
            AiFeedback::create(['learner_profile_id' => $profile->id, 'source' => $source, 'type' => 'grammar', 'topic_slug' => 'gustar',
                'original' => 'me gusta los libros', 'correction' => 'me gustan los libros', 'explanation' => 'plural', 'created_at' => now()]);
        }

        app(AdaptiveEngine::class)->refresh($profile);

        $pattern = LearnerMistakePattern::where('topic_slug', 'gustar')->firstOrFail();
        $this->assertSame(3, $pattern->occurrences);
        $this->assertSame(2, $pattern->sources);

        $version = $profile->plan()->first()->currentVersion;
        $this->assertSame('adaptive', $version->trigger);
        $this->assertSame(['gustar'], $version->inputs_snapshot['adaptive']['recurring']);
        $item = $version->items()->where('reason_code', 'recurring_mistake')->first();
        $this->assertSame('gustar', $item->topic);

        // Same signals → no new version; cooldown prevents churn.
        app(AdaptiveEngine::class)->refresh($profile);
        $this->assertSame($version->id, $profile->plan()->first()->current_version_id);
    }

    public function test_practice_accuracy_moves_content_difficulty(): void
    {
        $profile = $this->makeLearner('es');
        $reading = Skill::where('slug', 'reading')->value('id');
        UserProgress::create(['learner_profile_id' => $profile->id, 'date' => now()->toDateString(), 'skill_id' => $reading, 'questions_answered' => 20, 'questions_correct' => 19]);
        $grammar = Skill::where('slug', 'grammar')->value('id');
        UserProgress::create(['learner_profile_id' => $profile->id, 'date' => now()->toDateString(), 'skill_id' => $grammar, 'questions_answered' => 20, 'questions_correct' => 6]);
        UserProgress::create(['learner_profile_id' => $profile->id, 'date' => now()->toDateString(), 'skill_id' => Skill::where('slug', 'vocabulary')->value('id'), 'questions_answered' => 5, 'questions_correct' => 5]);

        $offsets = app(AdaptiveEngine::class)->difficultyOffsets($profile);
        $this->assertSame(['grammar' => -1, 'reading' => 1], $offsets, 'too few vocabulary answers to adapt');

        $version = app(PlanGenerator::class)->generate($profile, 'user_regenerate');
        $readingItem = $version->items()->whereHas('skill', fn ($q) => $q->where('slug', 'reading'))->with('targetLevel')->first();
        $this->assertSame('A2', $readingItem->targetLevel->label, 'A1 baseline + 1');
    }

    public function test_stale_unmastered_topics_are_scheduled_for_revision_and_readiness_is_reported(): void
    {
        $profile = $this->makeLearner('es');
        $topic = GrammarTopic::where('slug', 'ser_present')->where('language_id', $profile->target_language_id)->first();
        GrammarMastery::create(['learner_profile_id' => $profile->id, 'grammar_topic_id' => $topic->id, 'mastery' => 0.65,
            'answers_total' => 6, 'answers_correct' => 4, 'last_practiced_at' => now()->subDays(9)]);
        $this->assertSame(['ser_present'], app(AdaptiveEngine::class)->revisionTopics($profile));

        $version = app(PlanGenerator::class)->generate($profile, 'user_regenerate');
        $this->assertSame('ser_present', $version->items()->where('reason_code', 'topic_due_for_revision')->value('topic'));

        SkillProfile::where('learner_profile_id', $profile->id)->delete();
        SkillProfile::create(['learner_profile_id' => $profile->id, 'skill_id' => Skill::where('slug', 'grammar')->value('id'),
            'cefr_level_id' => CefrLevel::where('code', 'A1')->value('id'), 'theta' => -2.0, 'standard_error' => 0.5, 'status' => 'assessed', 'confidence' => 'high']);
        $progress = app(AdaptiveEngine::class)->levelProgress($profile)['grammar'];
        $this->assertSame('A2', $progress['next']);
        $this->assertSame(0.5, $progress['progress'], 'theta at the A1 anchor is halfway through the A1 band');
        $this->assertFalse($progress['ready_for_check']);
    }
}
