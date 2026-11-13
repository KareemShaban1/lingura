<?php

namespace Database\Seeders;

use App\Models\AiTutor;
use App\Models\CefrLevel;
use App\Models\ConversationScenario;
use App\Models\Language;
use App\Models\ProductionTask;
use Illuminate\Database\Seeder;

/** Tutors, scenarios and productive tasks (idempotent). */
class Phase3ContentSeeder extends Seeder
{
    public function run(): void
    {
        $data = require database_path('data/phase3_content.php');
        $levels = CefrLevel::pluck('id', 'code');

        foreach ($data['tutors'] as $i => $t) {
            $tutor = AiTutor::updateOrCreate(['slug' => $t['slug']], [
                'name' => $t['name'], 'voice' => $t['voice'], 'style' => $t['style'], 'correction_mode' => $t['correction_mode'],
                'persona' => $t['persona'], 'is_active' => true, 'position' => $i,
            ]);
            $tutor->setTranslations('description', $t['description']);
        }

        foreach ($data['scenarios'] as $i => $s) {
            $scenario = ConversationScenario::updateOrCreate(['slug' => $s['slug']], [
                'min_level_id' => $levels[$s['min']], 'max_level_id' => $levels[$s['max']],
                'setup' => $s['setup'], 'icon' => $s['icon'], 'is_active' => true, 'position' => $i,
            ]);
            $scenario->setTranslations('title', $s['title']);
            $scenario->setTranslations('description', $s['description']);
        }

        foreach ($data['task_prompts'] as $code => $prompts) {
            $language = Language::where('code', $code)->firstOrFail();
            foreach ($data['task_types'] as $i => [$suffix, $mode, $type, $level, $min, $max, $titleEn, $titleAr]) {
                $factor = $data['length_factor'][$code] ?? 1;
                $task = ProductionTask::updateOrCreate(['key' => "{$code}-{$suffix}"], [
                    'language_id' => $language->id, 'cefr_level_id' => $levels[$level], 'mode' => $mode, 'type' => $type,
                    'prompt' => $prompts[$i], 'min_words' => $min * $factor, 'max_words' => $max * $factor, 'is_placement' => true,
                    'review_status' => 'published',
                ]);
                $task->setTranslations('title', ['en' => $titleEn, 'ar' => $titleAr]);
            }
        }
    }
}
