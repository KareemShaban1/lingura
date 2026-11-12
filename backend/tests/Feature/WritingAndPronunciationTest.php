<?php

namespace Tests\Feature;

use App\Domain\AI\Drivers\FakeDriver;
use App\Models\AiFeedback;
use App\Models\Exercise;
use App\Models\ProductionTask;
use App\Models\WritingEvaluation;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WritingAndPronunciationTest extends TestCase
{
    public function test_writing_practice_is_evaluated_with_anchored_corrections(): void
    {
        $profile = $this->makeLearner('fr');
        $this->actingAsUser($profile->user);
        $tasks = $this->getJson("/api/v1/learner-profiles/{$profile->public_id}/writing-tasks")->assertOk()->json('data');
        $this->assertCount(4, $tasks);
        $task = collect($tasks)->firstWhere('type', 'email');

        $this->postJson("/api/v1/learner-profiles/{$profile->public_id}/writing-tasks/{$task['id']}/submissions", ['text' => 'Salut'])
            ->assertStatus(422)->assertJsonPath('code', 'text_too_short');

        $text = "Salut Marc,\nJe t'invite à ma fête d'anniversaire samedi soir chez moi. On va manger une pizza et danser. Tu peux venir à huit heures ? J'espère que tu viens !\nÀ bientôt,\nLéa";
        FakeDriver::queueText([
            'criteria' => collect(['task_achievement', 'grammar', 'vocabulary', 'coherence', 'organization'])->mapWithKeys(fn ($c) => [$c => ['score' => 4, 'comment' => 'Bien.']])->all(),
            'estimated_level' => 'C1', // two levels above the A2 task → flagged for human calibration
            'corrections' => [['original' => "J'espère que tu viens", 'correction' => "J'espère que tu viendras", 'explanation' => 'Future after espérer.', 'type' => 'grammar', 'topic_slug' => '']],
            'corrected_text' => str_replace("J'espère que tu viens", "J'espère que tu viendras", $text),
            'summary' => 'Une invitation claire.',
        ]);
        $submission = $this->postJson("/api/v1/learner-profiles/{$profile->public_id}/writing-tasks/{$task['id']}/submissions", ['text' => $text])
            ->assertStatus(202)->json('data');

        $data = $this->getJson("/api/v1/writing-submissions/{$submission['id']}")->assertOk()->json('data');
        $this->assertSame('evaluated', $data['status']);
        $this->assertSame(4.0, (float) $data['evaluation']['overall_score']);
        $this->assertCount(5, $data['evaluation']['criteria']);
        $this->assertSame("J'espère que tu viens", mb_substr($text, $data['evaluation']['corrections'][0]['start'], $data['evaluation']['corrections'][0]['length']));
        $this->assertTrue(WritingEvaluation::first()->needs_human_review);
        $this->assertSame('writing', AiFeedback::first()->source);
        $this->assertDatabaseHas('ai_usage_records', ['operation' => 'writing.evaluate']);

        $this->actingAsUser($this->makeUser());
        $this->getJson("/api/v1/writing-submissions/{$submission['id']}")->assertForbidden();
    }

    public function test_spoken_tasks_are_not_writing_tasks(): void
    {
        $profile = $this->makeLearner('de');
        $this->actingAsUser($profile->user);
        $spoken = ProductionTask::where('key', 'de-routine')->first();
        $this->postJson("/api/v1/learner-profiles/{$profile->public_id}/writing-tasks/{$spoken->public_id}/submissions", ['text' => 'Ich stehe um sieben Uhr auf und trinke Kaffee.'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_task');
    }

    public function test_repeat_after_me_scores_intelligibility_word_by_word(): void
    {
        Storage::fake('local');
        $profile = $this->makeLearner('it');
        $this->actingAsUser($profile->user);
        $exercise = Exercise::where('key', 'it-a1-repeat')->first();
        $attempt = $this->postJson("/api/v1/learner-profiles/{$profile->public_id}/exercises/{$exercise->public_id}/attempts")->assertCreated()->json('data');
        $question = $attempt['exercise']['questions'][1];
        $this->assertSame('La mia casa è piccola.', $question['reference_text']);

        $this->postJson("/api/v1/exercise-attempts/{$attempt['id']}/answers", ['question_id' => $question['id']])
            ->assertStatus(422)->assertJsonPath('code', 'recording_required');

        $recording = $this->uploadRecording($profile, 'pronunciation', 2500);
        FakeDriver::queueTranscript('la mia cosa è piccola');
        $feedback = $this->postJson("/api/v1/exercise-attempts/{$attempt['id']}/answers", ['question_id' => $question['id'], 'recording_id' => $recording])
            ->assertOk()->json('data');

        $this->assertTrue($feedback['is_correct'], '4 of 5 words understood meets the 0.8 threshold');
        $this->assertSame(0.8, $feedback['score']);
        $this->assertSame('transcript_alignment_v1', $feedback['pronunciation']['method']);
        $this->assertSame(['correct', 'correct', 'substituted', 'correct', 'correct'], array_column($feedback['pronunciation']['words'], 'status'));
        $this->assertSame('casa', $feedback['pronunciation']['words'][2]['expected']);
        $this->assertSame('cosa', $feedback['pronunciation']['words'][2]['heard']);

        // Arabic pronunciation is unsupported in the capability matrix → the exercise is not offered.
        $arabic = Exercise::where('key', 'ar-a1-repeat')->first();
        $this->subscribe($profile->user, 'premium_monthly');
        $arProfile = $this->makeLearner('ar', user: $profile->user);
        $this->postJson("/api/v1/learner-profiles/{$arProfile->public_id}/exercises/{$arabic->public_id}/attempts")
            ->assertStatus(409)->assertJsonPath('code', 'exercise_unavailable');
    }
}
