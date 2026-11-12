<?php

namespace App\Domain\Assessment\Items;

use App\Models\AssessmentItem;
use App\Models\Language;

/**
 * Deterministic structural validation for selected-response items.
 * Applied to authored and AI-generated items alike: AI output is never trusted without these checks.
 */
class ItemValidator
{
    /**
     * @param  array{skill:string, level:string, format:string, prompt:string, stimulus:?string, options:list<string>, correct_index:int}  $item
     * @return list<string> validation errors (empty when valid)
     */
    public function validate(array $item, Language $language): array
    {
        $errors = [];
        $prompt = trim($item['prompt'] ?? '');
        $options = array_map(fn ($o) => trim((string) $o), $item['options'] ?? []);

        if ($prompt === '' || mb_strlen($prompt) > 500) {
            $errors[] = 'prompt must be 1–500 characters';
        }
        if (! in_array($item['format'] ?? null, AssessmentItem::FORMATS, true)) {
            $errors[] = 'unknown format';
        }
        if (($item['format'] ?? null) === 'cloze_mcq' && ! str_contains($prompt, '___')) {
            $errors[] = 'cloze items must contain a ___ gap';
        }
        if (in_array($item['format'] ?? null, ['reading_mcq', 'listening_mcq'], true)) {
            $stimulus = trim((string) ($item['stimulus'] ?? ''));
            if ($stimulus === '' || mb_strlen($stimulus) > 2000) {
                $errors[] = 'reading/listening items need a stimulus of 1–2000 characters';
            }
        }
        if (count($options) < 2 || count($options) > 5) {
            $errors[] = 'items need 2–5 options';
        }
        if (in_array('', $options, true)) {
            $errors[] = 'options must not be empty';
        }
        $normalised = array_map(fn ($o) => mb_strtolower($o), $options);
        if (count(array_unique($normalised)) !== count($normalised)) {
            $errors[] = 'options must be distinct';
        }
        $correct = $item['correct_index'] ?? null;
        if (! is_int($correct) || $correct < 0 || $correct >= count($options)) {
            $errors[] = 'exactly one valid correct option is required';
        }
        if (! $this->matchesScript($prompt.' '.implode(' ', $options), $language->script)) {
            $errors[] = "text does not match the {$language->script} script of {$language->code}";
        }

        return $errors;
    }

    private function matchesScript(string $text, string $script): bool
    {
        $letters = preg_replace('/[^\p{L}]/u', '', $text);
        if ($letters === '') {
            return false;
        }
        $pattern = match ($script) {
            'Arab' => '/\p{Arabic}/u',
            'Latn' => '/\p{Latin}/u',
            'Cyrl' => '/\p{Cyrillic}/u',
            'Hani' => '/\p{Han}/u',
            default => null,
        };
        if ($pattern === null) {
            return true; // unknown script: do not block, rely on human review
        }
        $total = mb_strlen($letters);
        $matching = preg_match_all($pattern, $letters);

        return $matching / $total >= 0.6;
    }
}
