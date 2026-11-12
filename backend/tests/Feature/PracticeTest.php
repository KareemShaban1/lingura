<?php

namespace Tests\Feature;

use App\Domain\Practice\TextNormalizer;
use App\Jobs\SynthesizeExerciseAudio;
use App\Models\Exercise;
use App\Models\ExerciseOption;
use App\Models\GrammarMastery;
use App\Models\LearnerProfile;
use App\Models\UserProgress;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PracticeTest extends TestCase
{
    private LearnerProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->profile = $this->makeLearner('es');
        $this->actingAsUser($this->profile->user);
    }

    private function start(string $key): array
    {
        $exercise = Exercise::where('key', $key)->firstOrFail();

        return $this->postJson("/api/v1/learner-profiles/{$this->profile->public_id}/exercises/{$exercise->public_id}/attempts")
            ->assertSuccessful()->json('data');
    }

    public function test_library_lists_only_usable_exercises_for_the_profile_language(): void
    {
        $list = collect($this->getJson("/api/v1/learner-profiles/{$this->profile->public_id}/exercises")->assertOk()->json('data'));

        $this->assertCount(8, $list, 'listening and dictation are hidden until their audio exists');
        $this->assertEmpty($list->whereIn('type', ['listening', 'dictation']));
        $this->assertTrue($list->every(fn ($e) => str_starts_with(Exercise::where('public_id', $e['id'])->value('key'), 'es-')));

        $grammar = $this->getJson("/api/v1/learner-profiles/{$this->profile->public_id}/exercises?skill=reading")->json('data');
        $this->assertCount(2, $grammar);
    }

    public function test_cloze_attempt_with_feedback_partial_credit_and_mastery(): void
    {
        $attempt = $this->start('es-a1-ar-cloze');
        $this->assertNull($attempt['exercise']['questions'][0]['answer'], 'no answer key before answering');
        $q = collect($attempt['exercise']['questions']);

        // "compráis" typed without the accent → near miss with half credit and a specific hint.
        $last = $q->last();
        $this->postJson("/api/v1/exercise-attempts/{$attempt['id']}/answers", ['question_id' => $last['id'], 'text' => 'comprais'])
            ->assertOk()
            ->assertJsonPath('data.is_correct', false)
            ->assertJsonPath('data.score', 0.5)
            ->assertJsonPath('data.feedback_code', 'check_accents')
            ->assertJsonPath('data.correct_answer', 'compráis');

        $this->postJson("/api/v1/exercise-attempts/{$attempt['id']}/answers", ['question_id' => $q[0]['id'], 'text' => ' Hablo '])
            ->assertOk()->assertJsonPath('data.is_correct', true);
        // Idempotent: a second, different answer to the same question is ignored.
        $this->postJson("/api/v1/exercise-attempts/{$attempt['id']}/answers", ['question_id' => $q[0]['id'], 'text' => 'wrong'])
            ->assertOk()->assertJsonPath('data.is_correct', true);

        foreach ([1 => 'trabaja', 2 => 'estudiamos'] as $i => $text) {
            $this->postJson("/api/v1/exercise-attempts/{$attempt['id']}/answers", ['question_id' => $q[$i]['id'], 'text' => $text])->assertOk();
        }
        $done = $this->postJson("/api/v1/exercise-attempts/{$attempt['id']}/finish")->assertOk()->json('data');
        $this->assertSame('completed', $done['status']);
        $this->assertEqualsWithDelta(3.5 / 4, $done['score'], 0.0001);

        $mastery = GrammarMastery::where('learner_profile_id', $this->profile->id)->firstOrFail();
        $this->assertSame(4, $mastery->answers_total);
        $this->assertSame(3, $mastery->answers_correct);
        $this->assertSame(4, (int) UserProgress::where('learner_profile_id', $this->profile->id)->sum('questions_answered'));
        $this->assertSame(1, (int) UserProgress::where('learner_profile_id', $this->profile->id)->sum('exercises_completed'));

        $this->postJson("/api/v1/exercise-attempts/{$attempt['id']}/answers", ['question_id' => $q[3]['id'], 'text' => 'x'])
            ->assertStatus(409)->assertJsonPath('code', 'attempt_closed');
    }

    public function test_multiple_choice_and_ordering_scoring(): void
    {
        $attempt = $this->start('es-a1-order');
        $question = $attempt['exercise']['questions'][0];
        $ids = collect($question['options'])->pluck('id');
        $correct = ExerciseOption::whereIn('id', $ids)->orderBy('correct_position')->pluck('id')->all();

        $this->postJson("/api/v1/exercise-attempts/{$attempt['id']}/answers", ['question_id' => $question['id'], 'order' => $correct])
            ->assertOk()->assertJsonPath('data.is_correct', true)->assertJsonPath('data.correct_answer', 'Yo soy de España');

        $mcq = $this->start('es-a1-gustar-mcq');
        $q = $mcq['exercise']['questions'][0];
        $wrong = ExerciseOption::whereIn('id', collect($q['options'])->pluck('id'))->where('is_correct', false)->first();
        $this->postJson("/api/v1/exercise-attempts/{$mcq['id']}/answers", ['question_id' => $q['id'], 'option_id' => $wrong->id])
            ->assertOk()->assertJsonPath('data.is_correct', false)->assertJsonPath('data.correct_answer', 'gusta');
    }

    public function test_reading_speed_is_recorded_and_implausible_speeds_are_discarded(): void
    {
        $attempt = $this->start('es-a1-read-1');
        $words = count(TextNormalizer::words(Exercise::where('key', 'es-a1-read-1')->value('stimulus_text')));
        $this->postJson("/api/v1/exercise-attempts/{$attempt['id']}/finish", ['reading_seconds' => 40])
            ->assertOk()->assertJsonPath('data.words_per_minute', (int) round($words / 40 * 60));

        $fast = $this->start('es-a2-read-2');
        $this->postJson("/api/v1/exercise-attempts/{$fast['id']}/finish", ['reading_seconds' => 2])
            ->assertOk()->assertJsonPath('data.words_per_minute', null);
    }

    public function test_listening_needs_audio_and_reveals_the_transcript_only_after_finishing(): void
    {
        Storage::fake('local');
        $listening = Exercise::where('key', 'es-a1-listen-1')->firstOrFail();
        $this->postJson("/api/v1/learner-profiles/{$this->profile->public_id}/exercises/{$listening->public_id}/attempts")
            ->assertStatus(409)->assertJsonPath('code', 'exercise_unavailable');

        SynthesizeExerciseAudio::dispatchSync($listening->id);
        SynthesizeExerciseAudio::dispatchSync(Exercise::where('key', 'es-a1-dictation')->value('id'));

        $attempt = $this->start('es-a1-listen-1');
        $this->assertTrue($attempt['exercise']['has_audio']);
        $this->assertNull($attempt['exercise']['stimulus']);
        $url = $this->getJson("/api/v1/exercise-attempts/{$attempt['id']}/audio")->assertOk()->json('url');
        $this->get($url)->assertOk();

        $finished = $this->postJson("/api/v1/exercise-attempts/{$attempt['id']}/finish")->json('data');
        $this->assertStringContainsString('café con leche', $finished['exercise']['stimulus']);

        $dictation = $this->start('es-a1-dictation');
        $q = $dictation['exercise']['questions'][0];
        $this->assertTrue($q['has_audio']);
        $this->getJson("/api/v1/exercise-attempts/{$dictation['id']}/audio?question_id={$q['id']}")->assertOk();
        $this->postJson("/api/v1/exercise-attempts/{$dictation['id']}/answers", ['question_id' => $q['id'], 'text' => 'Me llamo Ana y soy de chile'])
            ->assertOk()->assertJsonPath('data.is_correct', true)->assertJsonPath('data.score', 1);
    }

    public function test_other_learners_cannot_touch_an_attempt_or_use_another_languages_exercise(): void
    {
        $attempt = $this->start('es-a1-ser-cloze');
        $french = Exercise::where('key', 'fr-a1-etre-cloze')->firstOrFail();
        $this->postJson("/api/v1/learner-profiles/{$this->profile->public_id}/exercises/{$french->public_id}/attempts")
            ->assertStatus(422)->assertJsonPath('code', 'wrong_language');

        $this->actingAsUser($this->makeUser());
        $this->getJson("/api/v1/exercise-attempts/{$attempt['id']}")->assertForbidden();
        $this->postJson("/api/v1/exercise-attempts/{$attempt['id']}/finish")->assertForbidden();
    }
}
