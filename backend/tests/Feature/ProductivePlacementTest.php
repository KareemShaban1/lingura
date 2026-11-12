<?php

namespace Tests\Feature;

use App\Domain\AI\Drivers\FakeDriver;
use App\Models\AssessmentAttemptSection;
use App\Models\WritingCorrection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductivePlacementTest extends TestCase
{
    private function criteria(array $keys, int $score): array
    {
        return collect($keys)->mapWithKeys(fn ($k) => [$k => ['score' => $score, 'comment' => "{$k} ok"]])->all();
    }

    public function test_writing_and_speaking_sections_are_rubric_scored_and_complete_the_profile(): void
    {
        Storage::fake('local');
        $profile = $this->makeLearner('es', ['self_reported_level' => 'A1']);
        $this->actingAsUser($profile->user);
        $id = $this->postJson("/api/v1/learner-profiles/{$profile->public_id}/assessments")->json('data.id');
        foreach (['grammar', 'vocabulary', 'reading'] as $skill) {
            $this->postJson("/api/v1/assessment-attempts/{$id}/sections/{$skill}/skip")->assertOk();
        }

        // Writing: the task shown is chosen near the provisional level (self-reported A1 → A1 sentences, 25–60 words).
        $next = $this->getJson("/api/v1/assessment-attempts/{$id}/next")->assertOk()->json();
        $this->assertNull($next['item']);
        $this->assertSame('writing', $next['task']['skill']);
        $this->assertSame('A1', $next['task']['level']['label']);

        $text = 'El verano pasado fui a Granada con mi familia. Visitamos la Alhambra y comimos tapas. Me gusto mucho porque la ciudad es muy bonita y la gente es amable.';
        FakeDriver::queueText([
            'criteria' => $this->criteria(['task_achievement', 'grammar', 'vocabulary', 'coherence', 'organization'], 3),
            'estimated_level' => 'A2',
            'corrections' => [
                ['original' => 'Me gusto', 'correction' => 'Me gustó', 'explanation' => 'Accent on the past tense.', 'type' => 'spelling', 'topic_slug' => ''],
                ['original' => 'text the learner never wrote', 'correction' => 'x', 'explanation' => 'invented', 'type' => 'grammar', 'topic_slug' => ''],
            ],
            'corrected_text' => str_replace('Me gusto', 'Me gustó', $text),
            'summary' => 'Clear narrative.',
        ]);
        $this->postJson("/api/v1/assessment-attempts/{$id}/writing", ['text' => $text])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame(1, WritingCorrection::count(), 'corrections that do not quote the learner are dropped');
        $this->assertSame(mb_strpos($text, 'Me gusto'), WritingCorrection::first()->start);

        // Speaking: upload → transcribe → rubric.
        $next = $this->getJson("/api/v1/assessment-attempts/{$id}/next")->json();
        $this->assertSame('speaking', $next['task']['skill']);
        $recording = $this->uploadRecording($profile, 'speaking_assessment', 30000);
        FakeDriver::queueTranscript('Hola me llamo Ana y soy de Chile'); // 8 words: below the task's 15-word minimum
        FakeDriver::queueText(['criteria' => $this->criteria(['fluency', 'grammar', 'vocabulary', 'coherence'], 4), 'estimated_level' => 'B1', 'summary' => 'Fluent answer.']);
        $this->postJson("/api/v1/assessment-attempts/{$id}/speaking", ['recording_id' => $recording])->assertOk()->assertJsonPath('data.status', 'completed');

        $this->getJson("/api/v1/assessment-attempts/{$id}/next")->assertOk()->assertJsonPath('done', true);
        $report = $this->getJson("/api/v1/assessment-attempts/{$id}/report")->json('data');
        $skills = collect($report['skills'])->keyBy('skill');

        $this->assertSame('A2', $skills['writing']['level']['label']);
        $this->assertSame('B1', $skills['speaking']['level']['label']);
        $this->assertSame('medium', $skills['writing']['confidence'], 'rubric estimates carry a wider standard error');
        $this->assertSame('low', $skills['speaking']['confidence'], 'an answer shorter than the task asks for widens it further');
        $this->assertSame('ai_rubric', $skills['writing']['evaluation']['method']);
        $this->assertCount(4, $skills['speaking']['evaluation']['criteria']);
        $this->assertNotContains('productive_skills_not_assessed', $report['overall']['provisional_reasons']);
        $this->assertSame('A2', $report['overall']['level']['label'], 'lower median of the assessed skills (A2, B1)');
    }

    public function test_productive_sections_degrade_gracefully(): void
    {
        Storage::fake('local');
        $profile = $this->makeLearner('fr');
        $this->actingAsUser($profile->user);
        $id = $this->postJson("/api/v1/learner-profiles/{$profile->public_id}/assessments")->json('data.id');
        foreach (['grammar', 'vocabulary', 'reading'] as $skill) {
            $this->postJson("/api/v1/assessment-attempts/{$id}/sections/{$skill}/skip");
        }
        $this->getJson("/api/v1/assessment-attempts/{$id}/next");

        // Provider outage → the section is skipped with a reason, not failed silently or scored.
        config(['ai.default_provider' => 'openai', 'ai.providers.openai.api_key' => null]);
        $this->postJson("/api/v1/assessment-attempts/{$id}/writing", ['text' => 'Je suis étudiant et j\'habite à Paris avec ma famille.'])
            ->assertOk()->assertJsonPath('data.status', 'skipped')->assertJsonPath('data.reason', 'evaluation_unavailable');
        config(['ai.default_provider' => 'fake']);

        // Silence → no speech detected.
        $this->getJson("/api/v1/assessment-attempts/{$id}/next");
        $recording = $this->uploadRecording($profile, 'speaking_assessment');
        FakeDriver::queueTranscript('');
        $this->postJson("/api/v1/assessment-attempts/{$id}/speaking", ['recording_id' => $recording])
            ->assertOk()->assertJsonPath('data.reason', 'no_speech_detected');
        $this->assertSame(0, AssessmentAttemptSection::where('status', 'completed')->count());
    }

    public function test_arabic_writing_is_offered_as_beta_and_upload_rejects_non_audio(): void
    {
        Storage::fake('local');
        $profile = $this->makeLearner('ar');
        $this->actingAsUser($profile->user);
        $sections = collect($this->postJson("/api/v1/learner-profiles/{$profile->public_id}/assessments")->json('data.sections'))->keyBy('skill');
        $this->assertSame('pending', $sections['writing']['status'], 'writing evaluation is beta for Arabic, so it is offered');

        $fake = UploadedFile::fake()->createWithContent('voice.webm', str_repeat('<?php echo 1; ?>', 20));
        $this->post("/api/v1/learner-profiles/{$profile->public_id}/recordings", ['audio' => $fake, 'purpose' => 'pronunciation'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('code', 'unsupported_audio');
    }
}
