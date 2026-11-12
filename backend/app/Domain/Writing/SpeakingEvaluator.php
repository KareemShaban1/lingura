<?php

namespace App\Domain\Writing;

use App\Domain\AI\AiGateway;
use App\Domain\AI\Data\TextRequest;
use App\Domain\AI\ExplanationLanguage;
use App\Domain\AI\PromptRegistry;
use App\Models\CefrLevel;
use App\Models\LearnerProfile;
use App\Models\ProductionTask;

/** Placement speaking: rubric scoring of a transcript plus measured fluency facts. */
class SpeakingEvaluator
{
    public function __construct(private readonly AiGateway $ai, private readonly PromptRegistry $prompts) {}

    /** @return array{level: CefrLevel, criteria: array<string, array{score:int, comment:string}>, summary: string, prompt_version: string, words: int} */
    public function evaluate(LearnerProfile $profile, ProductionTask $task, string $transcript, ?int $durationMs): array
    {
        $stats = TextStats::of($transcript);
        $wpm = $durationMs ? (int) round($stats['words'] / ($durationMs / 60000)) : null;
        $prompt = $this->prompts->render('speaking.evaluate', [
            'language' => $task->language->name,
            'task' => $task->prompt,
            'stats' => sprintf('%d words in %s; %s words per minute', $stats['words'], $durationMs ? round($durationMs / 1000).' seconds' : 'unknown time', $wpm ?? 'unknown'),
            'levels' => CefrLevel::orderBy('ordinal')->pluck('code')->implode(', '),
            'explanation_language' => ExplanationLanguage::for($profile),
        ]);
        $result = $this->ai->text(new TextRequest(
            operation: 'speaking.evaluate',
            instructions: $prompt->text,
            input: "TRANSCRIPT:\n".$transcript,
            languageCode: $task->language->code,
            jsonSchema: $prompt->schema,
            schemaName: 'speaking_evaluation',
            maxOutputTokens: 1200,
            userId: $profile->user_id,
            learnerProfileId: $profile->id,
            temperature: 0.2,
        ));
        $json = $result->json ?? [];

        return [
            'level' => CefrLevel::where('code', $json['estimated_level'] ?? '')->first() ?? CefrLevel::where('code', 'PRE_A1')->first(),
            'criteria' => collect(['fluency', 'grammar', 'vocabulary', 'coherence'])->mapWithKeys(fn ($c) => [$c => [
                'score' => max(1, min(5, (int) ($json['criteria'][$c]['score'] ?? 1))),
                'comment' => (string) ($json['criteria'][$c]['comment'] ?? ''),
            ]])->all(),
            'summary' => (string) ($json['summary'] ?? ''),
            'prompt_version' => $prompt->versionTag,
            'words' => $stats['words'],
        ];
    }
}
