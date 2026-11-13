<?php

namespace Tests\Feature;

use App\Models\ExerciseAttempt;
use App\Models\Lesson;
use App\Models\XpEntry;
use Tests\TestCase;

class OfflineTest extends TestCase
{
    public function test_pack_contains_text_exercises_with_keys_and_skips_audio_ones(): void
    {
        $profile = $this->makeLearner('es');
        $this->actingAsUser($profile->user);
        $lesson = Lesson::where('key', 'es-u2-l2')->firstOrFail(); // includes a listening exercise

        $pack = $this->getJson("/api/v1/learner-profiles/{$profile->public_id}/lessons/{$lesson->public_id}/offline-pack")
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');

        $this->assertSame($lesson->public_id, $pack['lesson']['id']);
        $this->assertNotEmpty($pack['lesson']['words']);
        $types = array_column($pack['exercises'], 'type');
        $this->assertNotContains('listening', $types);
        $this->assertSame(1, $pack['skipped_exercises']);
        $this->assertNotEmpty($pack['version']);

        $other = $this->makeLearner('fr');
        $this->actingAsUser($other->user);
        $this->getJson("/api/v1/learner-profiles/{$other->public_id}/lessons/{$lesson->public_id}/offline-pack")->assertStatus(422);
    }

    public function test_offline_answers_are_rechecked_on_sync_and_replays_are_idempotent(): void
    {
        $profile = $this->makeLearner('es');
        $this->actingAsUser($profile->user);
        $lesson = Lesson::where('key', 'es-u1-l1')->firstOrFail();
        $pack = $this->getJson("/api/v1/learner-profiles/{$profile->public_id}/lessons/{$lesson->public_id}/offline-pack")->json('data');

        $attempts = [];
        foreach ($pack['exercises'] as $i => $e) {
            $answers = collect($e['questions'])->map(fn ($q) => match ($e['type']) {
                'cloze' => ['question_id' => $q['id'], 'text' => $q['answers'][0]],
                'ordering' => ['question_id' => $q['id'], 'order' => collect($q['options'])->sortBy('position')->pluck('id')->all()],
                // The device claims every multiple-choice answer was right, but picks a wrong option.
                default => ['question_id' => $q['id'], 'option_id' => collect($q['options'])->firstWhere('correct', false)['id']],
            })->all();
            $attempts[] = ['client_ref' => "dev-{$i}", 'exercise_id' => $e['id'], 'lesson_id' => $lesson->public_id, 'answers' => $answers];
        }
        $attempts[] = ['client_ref' => 'bad', 'exercise_id' => str_repeat('0', 26), 'answers' => [['question_id' => 1, 'option_id' => 1]]];

        $result = collect($this->postJson("/api/v1/learner-profiles/{$profile->public_id}/offline-sync", ['attempts' => $attempts, 'complete_lessons' => [$lesson->public_id]])
            ->assertOk()->json('data'))->keyBy('client_ref');

        $this->assertSame('rejected', $result['bad']['status'], 'one bad attempt does not block the rest');
        foreach ($pack['exercises'] as $i => $e) {
            $this->assertSame('synced', $result["dev-{$i}"]['status']);
            if ($e['type'] === 'mcq') {
                $this->assertEquals(0.0, $result["dev-{$i}"]['score'], 'the server re-checks: wrong options score 0');
            }
        }
        $this->assertSame(count($pack['exercises']), ExerciseAttempt::where('learner_profile_id', $profile->id)->where('status', 'completed')->count());
        $xp = (int) XpEntry::where('user_id', $profile->user_id)->sum('amount');

        // A retried sync (e.g. the response was lost) changes nothing.
        $again = collect($this->postJson("/api/v1/learner-profiles/{$profile->public_id}/offline-sync", ['attempts' => array_slice($attempts, 0, -1)])->json('data'));
        $this->assertTrue($again->every(fn ($r) => $r['status'] === 'duplicate'));
        $this->assertSame($xp, (int) XpEntry::where('user_id', $profile->user_id)->sum('amount'));
    }
}
