<?php

namespace Tests\Feature;

use App\Domain\AI\Drivers\FakeDriver;
use App\Domain\Content\ContentPackImporter;
use App\Domain\Practice\TextNormalizer;
use App\Domain\Speech\Pinyin;
use App\Domain\Speech\PronunciationLexicon;
use App\Domain\Speech\PronunciationScorer;
use App\Models\Exercise;
use App\Models\ExerciseOption;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class ChineseSupportTest extends TestCase
{
    public function test_unspaced_scripts_are_tokenised_per_character_and_segmented_by_dictionary(): void
    {
        $this->assertSame(['我', '是', '学', '生'], TextNormalizer::words('我是学生。'));
        $this->assertSame(['我', '有', '2', '本', '书'], TextNormalizer::words('我有２本书！'), 'full-width digits are normalised');
        $this->assertSame(['hello', 'world'], TextNormalizer::words('Hello, world!'), 'spaced scripts are unchanged');

        $dictionary = ['你好' => true, '学生' => true, '好' => true];
        $this->assertSame(['你好', '我', '是', '学生'], TextNormalizer::segment('你好！我是学生。', fn ($w) => isset($dictionary[$w])));
        $this->assertContains('学生', TextNormalizer::candidates('我是学生'));
    }

    public function test_pinyin_tones_and_lexicon_readings(): void
    {
        $this->assertSame(3, Pinyin::tone('mǎi'));
        $this->assertSame(4, Pinyin::tone('mài'));
        $this->assertSame(5, Pinyin::tone('xie'));
        $this->assertSame('mai', Pinyin::base('mǎi'));
        $this->assertSame('lü', Pinyin::base('lǜ'), 'ü keeps its diaeresis');

        $readings = app(PronunciationLexicon::class)->readings('zh');
        $this->assertSame('mǎi', $readings['买']);
        $this->assertSame('mài', $readings['卖']);
        $this->assertSame([], app(PronunciationLexicon::class)->readings('es'), 'non-tonal languages have no readings');

        $score = app(PronunciationScorer::class)->score('我去商店买水果。', '我去商店卖水果', $readings);
        $this->assertSame('tone', $score['words'][4]['status']);
        $this->assertSame(1.0, $score['completeness']);
        $this->assertEqualsWithDelta(6 / 7, $score['accuracy'], 0.0001);
    }

    public function test_chinese_learner_flow_reading_ordering_writing_and_tone_feedback(): void
    {
        Storage::fake('local');
        $profile = $this->makeLearner('zh');
        $this->actingAsUser($profile->user);
        $id = $profile->public_id;

        // Placement uses the Chinese item bank.
        $attempt = $this->postJson("/api/v1/learner-profiles/{$id}/assessments")->assertSuccessful()->json('data.id');
        $item = $this->getJson("/api/v1/assessment-attempts/{$attempt}/next")->assertOk()->json('item');
        $this->assertMatchesRegularExpression('/\p{Han}/u', $item['prompt']);

        // Tap-to-translate finds multi-character words inside unspaced text.
        $found = $this->postJson("/api/v1/learner-profiles/{$id}/vocabulary/extract", ['text' => '你好！我是学生，我喜欢喝茶。'])->assertOk()->json('data');
        $this->assertSame(['你好', '好', '学生', '喜欢', '茶'], array_values(array_intersect(['你好', '好', '学生', '喜欢', '茶'], array_keys($found))));

        // Ordering answers are joined without spaces.
        $order = Exercise::where('key', 'zh-a1-order')->firstOrFail();
        $a = $this->postJson("/api/v1/learner-profiles/{$id}/exercises/{$order->public_id}/attempts")->assertSuccessful()->json('data');
        $q = $a['exercise']['questions'][0];
        $ids = ExerciseOption::whereIn('id', collect($q['options'])->pluck('id'))->orderBy('correct_position')->pluck('id')->all();
        $this->postJson("/api/v1/exercise-attempts/{$a['id']}/answers", ['question_id' => $q['id'], 'order' => $ids])
            ->assertOk()->assertJsonPath('data.correct_answer', '我是学生');

        // Writing targets are in characters.
        $task = collect($this->getJson("/api/v1/learner-profiles/{$id}/writing-tasks")->assertOk()->json('data'))->firstWhere('type', 'sentences');
        $this->assertSame('characters', $task['count_unit']);
        $this->assertSame(50, $task['min_words']);

        // Repeat-after-me: a wrong tone is reported as such.
        $repeat = Exercise::where('key', 'zh-a1-repeat')->firstOrFail();
        $r = $this->postJson("/api/v1/learner-profiles/{$id}/exercises/{$repeat->public_id}/attempts")->assertSuccessful()->json('data');
        $question = collect($r['exercise']['questions'])->firstWhere('reference_text', '我去商店买水果。');
        $recording = $this->uploadRecording($profile, 'pronunciation', 2500);
        FakeDriver::queueTranscript('我去商店卖水果');
        $feedback = $this->postJson("/api/v1/exercise-attempts/{$r['id']}/answers", ['question_id' => $question['id'], 'recording_id' => $recording])
            ->assertOk()->json('data');
        $this->assertSame('transcript_alignment_tone_v1', $feedback['pronunciation']['method']);
        $this->assertSame(['expected' => '买', 'heard' => '卖', 'status' => 'tone'], array_intersect_key($feedback['pronunciation']['words'][4], ['expected' => 0, 'heard' => 0, 'status' => 0]));
    }

    public function test_imports_reject_text_in_the_wrong_script_but_allow_bracketed_hints(): void
    {
        $pack = json_decode(file_get_contents(database_path('data/content/zh.json')), true);
        $pack['exercises'] = [['key' => 'zh-bad', 'type' => 'cloze', 'skill' => 'grammar', 'level' => 'A1', 'text' => 'This is English only.', 'questions' => [['p' => 'I ___ here. (zài)', 'a' => ['在']]]]];
        unset($pack['course']);

        $this->expectException(InvalidArgumentException::class);
        app(ContentPackImporter::class)->import($pack);
    }
}
