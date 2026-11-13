<?php

namespace Tests\Feature;

use App\Domain\AI\Drivers\FakeDriver;
use App\Models\ExamAttemptSection;
use App\Models\ExamFramework;
use App\Models\ExamItem;
use App\Models\ExamItemOption;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExamTest extends TestCase
{
    private function correctOption(string $itemId): int
    {
        return ExamItemOption::whereHas('item', fn ($q) => $q->where('public_id', $itemId))->where('is_correct', true)->value('id');
    }

    public function test_catalog_lists_exams_for_the_learner_language_with_a_disclaimer(): void
    {
        $profile = $this->makeLearner('es');
        $this->actingAsUser($profile->user);
        $exams = $this->getJson("/api/v1/learner-profiles/{$profile->public_id}/exams")->assertOk()->json('data');

        $this->assertSame(['dele_a2'], array_column($exams, 'slug'));
        $this->assertSame(['reading', 'listening', 'writing', 'speaking'], array_column($exams[0]['sections'], 'skill'));
        $this->assertStringContainsString('not official', $exams[0]['disclaimer']);
    }

    public function test_timed_closed_section_scoring_review_and_quota(): void
    {
        $profile = $this->makeLearner('es');
        $this->actingAsUser($profile->user);
        $start = "/api/v1/learner-profiles/{$profile->public_id}/exams/dele_a2/attempts";

        $attempt = $this->postJson($start)->assertCreated()->json('data');
        $this->assertSame('unavailable', collect($attempt['sections'])->firstWhere('skill', 'listening')['status'], 'no recordings yet → not offered');
        $this->postJson($start)->assertOk()->assertJsonPath('data.id', $attempt['id']); // resumed, not charged again
        $id = $attempt['id'];

        $current = $this->postJson("/api/v1/exam-attempts/{$id}/sections/reading/start")->assertOk()->json('data.current');
        $this->assertCount(2, $current['groups']);
        $this->assertArrayNotHasKey('is_correct', $current['groups'][0]['items'][0]['options'][0], 'no answer key during the test');
        $this->postJson("/api/v1/exam-attempts/{$id}/sections/writing/start")->assertStatus(409)->assertJsonPath('code', 'section_in_progress');

        foreach (collect($current['groups'])->flatMap(fn ($g) => $g['items']) as $item) {
            $this->postJson("/api/v1/exam-attempts/{$id}/answers", ['item_id' => $item['id'], 'option_id' => $this->correctOption($item['id'])])->assertOk();
        }
        $this->getJson("/api/v1/exam-attempts/{$id}/review")->assertStatus(409);
        $data = $this->postJson("/api/v1/exam-attempts/{$id}/sections/reading/submit")->assertOk()->json('data');
        $reading = collect($data['sections'])->firstWhere('skill', 'reading');
        $this->assertSame(['correct' => 6, 'total' => 6], $reading['raw']);
        $this->assertSame('likely', $reading['estimate']['label']);

        // Writing: time runs out → the late answer is not accepted.
        $this->postJson("/api/v1/exam-attempts/{$id}/sections/writing/start")->assertOk();
        Carbon::setTestNow(now()->addMinutes(32));
        $this->postJson("/api/v1/exam-attempts/{$id}/sections/writing/submit", ['text' => 'Hola, tengo casa nueva.'])->assertOk()
            ->assertJsonPath('data.sections.2.status', 'expired');
        Carbon::setTestNow();
        $done = $this->postJson("/api/v1/exam-attempts/{$id}/sections/speaking/skip")->assertOk()->json('data');

        $this->assertSame('completed', $done['status']);
        $this->assertNull($done['result']['overall'], 'no overall estimate when parts are missing');
        $review = $this->getJson("/api/v1/exam-attempts/{$id}/review")->assertOk()->json('data');
        $this->assertNotNull(collect($review)->firstWhere('skill', 'listening')['stimulus'], 'scripts are shown after the test');

        // Free plan: one practice test a month.
        $this->postJson($start)->assertStatus(429)->assertJsonPath('details.feature', 'exam_mocks');
    }

    public function test_full_ielts_practice_gives_section_bands_and_an_overall_estimate(): void
    {
        Storage::fake('local');
        $profile = $this->makeLearner('en');
        $user = $this->actingAsUser($profile->user);
        $this->subscribe($user, 'premium_monthly');
        $form = ExamFramework::where('slug', 'ielts_academic')->first()->activeForm();
        ExamItem::where('exam_form_id', $form->id)->update(['audio_path' => 'exam-audio/test.mp3']); // recordings synthesised
        $id = $this->postJson("/api/v1/learner-profiles/{$profile->public_id}/exams/ielts_academic/attempts")->assertCreated()->json('data.id');

        foreach (['listening', 'reading'] as $skill) {
            $current = $this->postJson("/api/v1/exam-attempts/{$id}/sections/{$skill}/start")->assertOk()->json('data.current');
            if ($skill === 'listening') {
                $this->assertNull($current['groups'][0]['stimulus'], 'listening scripts stay hidden');
                $this->getJson("/api/v1/exam-attempts/{$id}/items/{$current['groups'][0]['audio_item_id']}/audio")->assertOk();
            }
            $items = collect($current['groups'])->flatMap(fn ($g) => $g['items'])->values();
            foreach ($items->take(4) as $item) { // 4 of 6 right, 2 unanswered
                $this->postJson("/api/v1/exam-attempts/{$id}/answers", ['item_id' => $item['id'], 'option_id' => $this->correctOption($item['id'])])->assertOk();
            }
            $this->postJson("/api/v1/exam-attempts/{$id}/sections/{$skill}/submit")->assertOk();
        }

        $this->postJson("/api/v1/exam-attempts/{$id}/sections/writing/start")->assertOk();
        FakeDriver::queueText([
            'criteria' => collect(['task_achievement', 'grammar', 'vocabulary', 'coherence', 'organization'])->mapWithKeys(fn ($c) => [$c => ['score' => 4, 'comment' => 'Good.']])->all(),
            'estimated_level' => 'B2', 'corrections' => [], 'corrected_text' => 'x', 'summary' => 'Clear essay.',
        ]);
        $essay = str_repeat('Universities should balance employment skills with knowledge for its own sake. ', 25);
        $this->postJson("/api/v1/exam-attempts/{$id}/sections/writing/submit", ['text' => $essay])->assertOk();

        $this->postJson("/api/v1/exam-attempts/{$id}/sections/speaking/start")->assertOk();
        $recording = $this->uploadRecording($profile, 'speaking_assessment', 60000);
        FakeDriver::queueTranscript(str_repeat('I learned to cook from my grandmother and I use it every day ', 12));
        FakeDriver::queueText(['criteria' => collect(['fluency', 'grammar', 'vocabulary', 'coherence'])->mapWithKeys(fn ($c) => [$c => ['score' => 4, 'comment' => 'Good.']])->all(), 'estimated_level' => 'B2', 'summary' => 'Fluent.']);
        $data = $this->postJson("/api/v1/exam-attempts/{$id}/sections/speaking/submit", ['recording_id' => $recording])->assertOk()->json('data');

        $this->assertSame('completed', $data['status']);
        $writing = collect($data['sections'])->firstWhere('skill', 'writing');
        $this->assertSame(6.0, (float) $writing['estimate']['band'], 'B2 anchor ≈ band 6');
        $this->assertNotNull($writing['writing_submission_id']);
        $overall = $data['result']['overall'];
        $this->assertSame(0.0, fmod($overall['band'] * 2, 1.0), 'reported in half bands');
        $this->assertLessThanOrEqual($overall['band'], $overall['low']);
        $this->assertGreaterThanOrEqual($overall['band'], $overall['high']);
        $this->assertSame(4, ExamAttemptSection::whereNotNull('estimate')->count());
        $this->assertDatabaseHas('learning_events', ['event_type' => 'exam.completed']);
    }

    public function test_answers_after_the_deadline_are_refused_and_others_cannot_access_an_attempt(): void
    {
        $profile = $this->makeLearner('zh');
        $this->actingAsUser($profile->user);
        $id = $this->postJson("/api/v1/learner-profiles/{$profile->public_id}/exams/hsk_1/attempts")->assertCreated()->json('data.id');
        $item = $this->postJson("/api/v1/exam-attempts/{$id}/sections/reading/start")->assertOk()->json('data.current.groups.0.items.0');

        Carbon::setTestNow(now()->addMinutes(18));
        $this->postJson("/api/v1/exam-attempts/{$id}/answers", ['item_id' => $item['id'], 'option_id' => $this->correctOption($item['id'])])
            ->assertStatus(409)->assertJsonPath('code', 'section_time_over');
        $reading = collect($this->getJson("/api/v1/exam-attempts/{$id}")->json('data.sections'))->firstWhere('skill', 'reading');
        $this->assertSame('expired', $reading['status']);
        $this->assertSame(['correct' => 0, 'total' => 6], $reading['raw']);
        Carbon::setTestNow();

        $this->actingAsUser($this->makeUser());
        $this->getJson("/api/v1/exam-attempts/{$id}")->assertForbidden();
    }

    public function test_listening_audio_is_synthesised_once_per_group(): void
    {
        Storage::fake('local');
        $this->artisan('lf:exams:synthesize-audio')->assertSuccessful();

        $form = ExamFramework::where('slug', 'delf_a2')->first()->activeForm();
        $paths = ExamItem::where('exam_form_id', $form->id)->whereNotNull('audio_path')->pluck('audio_path');
        $this->assertCount(6, $paths, 'every listening question has its recording');
        $this->assertCount(2, $paths->unique(), 'one recording per listening group');
        $this->assertTrue(ExamItem::where('exam_form_id', $form->id)->whereNull('audio_path')->count() === 6, 'reading has no audio');
    }
}
