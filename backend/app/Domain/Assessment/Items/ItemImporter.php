<?php

namespace App\Domain\Assessment\Items;

use App\Models\AssessmentItem;
use App\Models\CefrLevel;
use App\Models\Language;
use App\Models\Skill;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ItemImporter
{
    public function __construct(private readonly ItemValidator $validator) {}

    /**
     * Import an authored bank file. Convention: in the source file, the FIRST option is the key;
     * positions are shuffled deterministically on import so the key is not always first.
     */
    public function importFile(string $path): int
    {
        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $language = Language::where('code', $data['language'])->firstOrFail();
        $count = 0;

        foreach ($data['items'] as $index => $raw) {
            $item = [
                'skill' => $raw['s'],
                'level' => $raw['l'],
                'format' => $this->formatFor($raw['s'], $raw['p']),
                'prompt' => $raw['p'],
                'stimulus' => $raw['x'] ?? null,
                'topic' => $raw['t'] ?? null,
                'explanation' => $raw['e'] ?? null,
                'options' => $raw['o'],
                'correct_index' => 0,
            ];
            $errors = $this->validator->validate($item, $language);
            if ($errors !== []) {
                throw new InvalidArgumentException(sprintf('%s item #%d invalid: %s', $data['language'], $index, implode('; ', $errors)));
            }
            $this->store($language, $item, source: 'authored', status: AssessmentItem::STATUS_PUBLISHED);
            $count++;
        }

        return $count;
    }

    /**
     * Persist a validated item. Returns null when an identical item already exists.
     */
    public function store(Language $language, array $item, string $source, string $status): ?AssessmentItem
    {
        $hash = hash('sha256', implode('|', [$language->code, $item['skill'], $item['stimulus'] ?? '', $item['prompt']]));
        if (AssessmentItem::withTrashed()->where('content_hash', $hash)->exists()) {
            return null;
        }

        $level = CefrLevel::where('code', $item['level'])->firstOrFail();
        $skill = Skill::where('slug', $item['skill'])->firstOrFail();

        return DB::transaction(function () use ($language, $item, $source, $status, $hash, $level, $skill) {
            $model = AssessmentItem::create([
                'language_id' => $language->id,
                'skill_id' => $skill->id,
                'cefr_level_id' => $level->id,
                'format' => $item['format'],
                'difficulty' => $item['difficulty'] ?? $level->anchor_theta,
                'stimulus_text' => $item['stimulus'] ?? null,
                'stimulus_key' => isset($item['stimulus']) ? substr(hash('sha256', $item['stimulus']), 0, 32) : null,
                'prompt' => $item['prompt'],
                'explanation' => $item['explanation'] ?? null,
                'grammar_topic_slug' => $item['topic'] ?? null,
                'source' => $source,
                'review_status' => $status,
                'reviewed_at' => $status === AssessmentItem::STATUS_PUBLISHED ? now() : null,
                'content_hash' => $hash,
            ]);

            $options = [];
            foreach ($item['options'] as $i => $label) {
                $options[] = ['label' => trim($label), 'is_correct' => $i === $item['correct_index'], 'sort' => hash('sha256', $hash.$label)];
            }
            usort($options, fn ($a, $b) => strcmp($a['sort'], $b['sort']));
            foreach ($options as $position => $option) {
                $model->options()->create(['label' => $option['label'], 'is_correct' => $option['is_correct'], 'position' => $position]);
            }

            return $model;
        });
    }

    public function formatFor(string $skill, string $prompt): string
    {
        return match ($skill) {
            'reading' => 'reading_mcq',
            'listening' => 'listening_mcq',
            default => str_contains($prompt, '___') ? 'cloze_mcq' : 'mcq',
        };
    }
}
