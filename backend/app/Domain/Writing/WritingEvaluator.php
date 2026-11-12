<?php

namespace App\Domain\Writing;

use App\Domain\AI\AiGateway;
use App\Domain\AI\Data\TextRequest;
use App\Domain\AI\ExplanationLanguage;
use App\Domain\AI\PromptRegistry;
use App\Models\AiFeedback;
use App\Models\CefrLevel;
use App\Models\GrammarTopic;
use App\Models\WritingEvaluation;
use App\Models\WritingSubmission;
use Illuminate\Support\Facades\DB;

/**
 * AI writing evaluation with server-side safeguards: rubric-constrained JSON, corrections kept only when
 * the quoted text really occurs in the submission, deterministic text statistics, and a random sample
 * flagged for human calibration review.
 */
class WritingEvaluator
{
    public const CRITERIA = ['task_achievement', 'grammar', 'vocabulary', 'coherence', 'organization'];

    public function __construct(private readonly AiGateway $ai, private readonly PromptRegistry $prompts) {}

    public function evaluate(WritingSubmission $submission): WritingEvaluation
    {
        $submission->loadMissing(['task.language', 'task.level', 'learnerProfile']);
        $task = $submission->task;
        $profile = $submission->learnerProfile;
        $prompt = $this->prompts->render('writing.evaluate', [
            'language' => $task->language->name,
            'level' => $task->level->label,
            'min_words' => $task->min_words,
            'max_words' => $task->max_words,
            'task' => $task->prompt,
            'levels' => CefrLevel::orderBy('ordinal')->pluck('code')->implode(', '),
            'explanation_language' => ExplanationLanguage::for($profile),
        ]);

        $result = $this->ai->text(new TextRequest(
            operation: 'writing.evaluate',
            instructions: $prompt->text,
            input: "LEARNER TEXT:\n".$submission->text,
            languageCode: $task->language->code,
            jsonSchema: $prompt->schema,
            schemaName: 'writing_evaluation',
            maxOutputTokens: 3000,
            userId: $profile->user_id,
            learnerProfileId: $profile->id,
            temperature: 0.2,
        ));
        $json = $result->json ?? [];
        $stats = TextStats::of($submission->text);
        $level = CefrLevel::where('code', $json['estimated_level'] ?? '')->first() ?? $task->level;
        $scores = collect(self::CRITERIA)->mapWithKeys(fn ($c) => [$c => max(1, min(5, (int) ($json['criteria'][$c]['score'] ?? 1)))]);
        $topics = GrammarTopic::where('language_id', $task->language_id)->pluck('slug')->all();

        return DB::transaction(function () use ($submission, $json, $stats, $level, $scores, $prompt, $result, $task, $profile, $topics) {
            $evaluation = WritingEvaluation::updateOrCreate(['writing_submission_id' => $submission->id], [
                'cefr_level_id' => $level->id,
                'overall_score' => round($scores->avg(), 2),
                'summary' => (string) ($json['summary'] ?? ''),
                'corrected_text' => (string) ($json['corrected_text'] ?? $submission->text),
                'sentence_count' => $stats['sentences'],
                'avg_sentence_length' => $stats['avg_sentence_length'],
                'lexical_variety' => $stats['lexical_variety'],
                // Calibration: a random sample, plus any estimate two or more levels away from the task level.
                'needs_human_review' => mt_rand() / mt_getrandmax() < config('learning.writing.human_review_sample_rate')
                    || abs($level->ordinal - $task->level->ordinal) >= 2,
                'prompt_version' => $prompt->versionTag,
                'model_slug' => $result->model,
            ]);
            $evaluation->criteria()->delete();
            foreach ($scores as $criterion => $score) {
                $evaluation->criteria()->create(['criterion' => $criterion, 'score' => $score, 'comment' => (string) ($json['criteria'][$criterion]['comment'] ?? '')]);
            }

            $evaluation->corrections()->delete();
            $cursor = 0;
            foreach ($json['corrections'] ?? [] as $c) {
                $original = (string) ($c['original'] ?? '');
                // Anchor every correction to the learner's real text; drop anything the model invented.
                $pos = $original === '' ? false : mb_strpos($submission->text, $original, $cursor);
                $pos = $pos === false && $original !== '' ? mb_strpos($submission->text, $original) : $pos;
                if ($pos === false || $original === ($c['correction'] ?? '')) {
                    continue;
                }
                $cursor = $pos + mb_strlen($original);
                $evaluation->corrections()->create([
                    'start' => $pos, 'length' => mb_strlen($original), 'original' => $original,
                    'correction' => (string) $c['correction'], 'type' => (string) ($c['type'] ?? 'grammar'), 'explanation' => (string) ($c['explanation'] ?? ''),
                ]);
                AiFeedback::create([
                    'learner_profile_id' => $profile->id, 'source' => 'writing', 'type' => (string) ($c['type'] ?? 'grammar'),
                    'topic_slug' => in_array($c['topic_slug'] ?? '', $topics, true) ? $c['topic_slug'] : null,
                    'original' => $original, 'correction' => (string) $c['correction'], 'explanation' => (string) ($c['explanation'] ?? ''),
                    'created_at' => now(),
                ]);
            }
            $submission->update(['status' => 'evaluated']);

            return $evaluation->load(['criteria', 'corrections', 'level']);
        });
    }
}
