<?php

namespace Database\Seeders;

use App\Models\CefrLevel;
use App\Models\ExamAttempt;
use App\Models\ExamForm;
use App\Models\ExamFramework;
use App\Models\ExamSection;
use App\Models\Language;
use App\Models\ProductionTask;
use App\Models\Skill;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeds exam frameworks and original practice forms from database/data/exams.php. Idempotent.
 * A form that learners have already taken is immutable: changing it requires a new form key.
 */
class ExamSeeder extends Seeder
{
    public function run(): void
    {
        $data = require database_path('data/exams.php');
        $levels = CefrLevel::pluck('id', 'code');
        $anchors = CefrLevel::pluck('anchor_theta', 'code');
        $skills = Skill::pluck('id', 'slug');

        DB::transaction(function () use ($data, $levels, $anchors, $skills) {
            $position = 0;
            foreach ($data['frameworks'] as $slug => $f) {
                $language = Language::where('code', $f['language'])->firstOrFail();
                $framework = ExamFramework::updateOrCreate(['slug' => $slug], [
                    'language_id' => $language->id, 'exam_name' => $f['exam_name'], 'scale' => $f['scale'],
                    'target_cefr_level_id' => $f['target'] ? $levels[$f['target']] : null, 'prior_cefr_level_id' => $levels[$f['prior']],
                    'overall_rule' => $f['overall_rule'], 'is_active' => true, 'position' => $position++,
                ]);
                $framework->setTranslations('title', $f['title']);
                $framework->setTranslations('description', $f['description']);
                $framework->setTranslations('disclaimer', $data['disclaimer']);

                $sections = [];
                foreach ($f['sections'] as $i => [$skill, $seconds]) {
                    $sections[$skill] = ExamSection::updateOrCreate(
                        ['exam_framework_id' => $framework->id, 'skill_id' => $skills[$skill]],
                        ['position' => $i, 'duration_seconds' => $seconds],
                    );
                }
                $framework->conversions()->delete();
                foreach ($f['conversions'] ?? [] as [$theta, $value]) {
                    $framework->conversions()->create(['theta' => $theta, 'value' => $value]);
                }

                foreach ([$f['form'], ...($data['additional_forms'][$slug] ?? [])] as $spec) {
                    $this->seedForm($framework, $spec, $sections, $levels, $anchors);
                }
            }
        });
    }

    private function seedForm(ExamFramework $framework, array $spec, array $sections, $levels, $anchors): void
    {
        $hash = hash('sha256', json_encode($spec, JSON_UNESCAPED_UNICODE));
        $form = ExamForm::where('key', $spec['key'])->first();
        if ($form && $form->content_hash === $hash) {
            return;
        }
        if ($form && ExamAttempt::where('exam_form_id', $form->id)->exists()) {
            throw new RuntimeException("Exam form {$spec['key']} has attempts and cannot change; add a new form key.");
        }
        $form ??= new ExamForm(['key' => $spec['key']]);
        $form->fill(['exam_framework_id' => $framework->id, 'version' => 1, 'content_hash' => $hash, 'is_active' => true])->save();
        $form->items()->delete();
        $form->tasks()->delete();

        $position = 0;
        foreach ($spec['groups'] as $group) {
            foreach ($group['items'] as $n => $item) {
                [$level, $prompt, $options] = $item;
                $model = $form->items()->create([
                    'exam_section_id' => $sections[$group['section']]->id, 'cefr_level_id' => $levels[$level],
                    'position' => $position++, 'group_key' => $group['key'], 'stimulus_text' => $group['text'],
                    'prompt' => $prompt, 'explanation' => $item[3] ?? null, 'difficulty' => $anchors[$level],
                ]);
                // Deterministic shuffle: the correct answer is not always first, and re-seeding is reproducible.
                $order = $options;
                usort($order, fn ($a, $b) => strcmp(md5($spec['key'].$prompt.$a), md5($spec['key'].$prompt.$b)));
                foreach ($order as $p => $label) {
                    $model->options()->create(['label' => $label, 'is_correct' => $label === $options[0], 'position' => $p]);
                }
            }
        }

        foreach ($spec['tasks'] as $skill => $t) {
            $task = ProductionTask::updateOrCreate(['key' => $t['key']], [
                'language_id' => $framework->language_id, 'cefr_level_id' => $levels[$t['level']],
                'mode' => $skill === 'speaking' ? 'spoken' : 'written', 'type' => $t['type'], 'prompt' => $t['prompt'],
                'min_words' => $t['min'], 'max_words' => $t['max'], 'is_placement' => false, 'is_exam' => true, 'review_status' => 'published',
            ]);
            $task->setTranslations('title', $t['title']);
            $form->tasks()->create(['exam_section_id' => $sections[$skill]->id, 'production_task_id' => $task->id]);
        }
    }
}
