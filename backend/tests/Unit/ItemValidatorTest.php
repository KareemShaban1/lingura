<?php

namespace Tests\Unit;

use App\Domain\Assessment\Items\ItemValidator;
use App\Models\Language;
use Tests\TestCase;

class ItemValidatorTest extends TestCase
{
    private function item(array $overrides = []): array
    {
        return array_merge([
            'skill' => 'grammar', 'level' => 'A1', 'format' => 'cloze_mcq',
            'prompt' => 'Ella ___ en Madrid.', 'stimulus' => null,
            'options' => ['vive', 'vivo', 'vives', 'vivimos'], 'correct_index' => 0,
        ], $overrides);
    }

    public function test_valid_item_passes(): void
    {
        $this->assertSame([], (new ItemValidator)->validate($this->item(), Language::where('code', 'es')->first()));
    }

    public function test_structural_errors_are_reported(): void
    {
        $validator = new ItemValidator;
        $es = Language::where('code', 'es')->first();

        $this->assertContains('options must be distinct', $validator->validate($this->item(['options' => ['vive', 'Vive', 'vivo', 'vives']]), $es));
        $this->assertContains('cloze items must contain a ___ gap', $validator->validate($this->item(['prompt' => 'Ella vive en Madrid.']), $es));
        $this->assertContains('exactly one valid correct option is required', $validator->validate($this->item(['correct_index' => 7]), $es));
        $this->assertNotEmpty($validator->validate($this->item(['format' => 'reading_mcq']), $es), 'reading items need a stimulus');
    }

    public function test_text_must_match_the_target_language_script(): void
    {
        $errors = (new ItemValidator)->validate($this->item(), Language::where('code', 'ar')->first());

        $this->assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'script')));
    }
}
