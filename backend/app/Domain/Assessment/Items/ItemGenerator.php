<?php

namespace App\Domain\Assessment\Items;

use App\Domain\AI\AiGateway;
use App\Domain\AI\Data\TextRequest;
use App\Models\AssessmentItem;
use App\Models\CefrLevel;
use App\Models\Language;
use App\Models\Skill;

/**
 * Drafts new placement items with AI. Output is schema-constrained, then validated deterministically,
 * and stored as `draft` — it is never shown to learners until a human reviewer publishes it.
 */
class ItemGenerator
{
    public function __construct(
        private readonly AiGateway $ai,
        private readonly ItemValidator $validator,
        private readonly ItemImporter $importer,
    ) {}

    /**
     * @return array{created: list<AssessmentItem>, rejected: list<array{item: array, errors: list<string>}>}
     */
    public function generate(Language $language, Skill $skill, CefrLevel $level, int $count, ?int $userId = null): array
    {
        $result = $this->ai->text(new TextRequest(
            operation: 'assessment.item_generation',
            instructions: $this->instructions($language, $skill, $level),
            input: "Write {$count} new, original items. Avoid well-known textbook sentences.",
            languageCode: $language->code,
            jsonSchema: $this->schema(),
            schemaName: 'assessment_items',
            maxOutputTokens: 4000,
            userId: $userId,
        ));

        $created = $rejected = [];
        foreach ($result->json['items'] ?? [] as $raw) {
            $item = [
                'skill' => $skill->slug,
                'level' => $level->code,
                // The format is what we asked for, not what the model happened to return.
                'format' => match ($skill->slug) {
                    'reading' => 'reading_mcq',
                    'listening' => 'listening_mcq',
                    default => 'cloze_mcq',
                },
                'prompt' => (string) ($raw['prompt'] ?? ''),
                'stimulus' => in_array($skill->slug, ['reading', 'listening'], true) ? ($raw['stimulus'] ?: null) : null,
                'topic' => $raw['topic'] ?: null,
                'explanation' => $raw['explanation'] ?: null,
                'options' => $raw['options'] ?? [],
                'correct_index' => $raw['correct_index'] ?? -1,
            ];
            $errors = $this->validator->validate($item, $language);
            if ($errors !== []) {
                $rejected[] = ['item' => $item, 'errors' => $errors];

                continue;
            }
            $stored = $this->importer->store($language, $item, source: 'ai_generated', status: AssessmentItem::STATUS_DRAFT);
            $stored ? $created[] = $stored : $rejected[] = ['item' => $item, 'errors' => ['duplicate']];
        }

        return ['created' => $created, 'rejected' => $rejected];
    }

    private function instructions(Language $language, Skill $skill, CefrLevel $level): string
    {
        $shape = match ($skill->slug) {
            'reading' => 'Each item has a short reading passage ("stimulus") appropriate to the level and one comprehension question ("prompt").',
            'listening' => 'Each item has a short spoken-style script ("stimulus") that will be converted to audio, and one comprehension question ("prompt").',
            default => 'Each item is a single sentence with exactly one gap written as ___ ("prompt"); "stimulus" must be an empty string.',
        };

        return <<<TXT
        You are an experienced {$language->name} language assessment writer.
        Write selected-response placement items testing {$skill->name} at CEFR level {$level->label} ({$level->description}).
        {$shape}
        Rules:
        - All learner-facing text (stimulus, prompt, options) must be written in {$language->name} only.
        - Exactly four options. Exactly one is correct; distractors must be plausible but unambiguously wrong.
        - "correct_index" is the zero-based index of the correct option.
        - "topic" is a short snake_case grammar or vocabulary topic identifier (e.g. "past_tense", "collocations").
        - "explanation" briefly explains in English why the key is correct (for reviewers).
        - Content must be original, culturally neutral, and suitable for adults.
        TXT;
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['items'],
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['stimulus', 'prompt', 'options', 'correct_index', 'topic', 'explanation'],
                        'properties' => [
                            'stimulus' => ['type' => 'string'],
                            'prompt' => ['type' => 'string'],
                            'options' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'correct_index' => ['type' => 'integer'],
                            'topic' => ['type' => 'string'],
                            'explanation' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
