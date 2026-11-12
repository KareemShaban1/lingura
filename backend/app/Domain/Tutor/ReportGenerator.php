<?php

namespace App\Domain\Tutor;

use App\Domain\AI\AiGateway;
use App\Domain\AI\Data\TextRequest;
use App\Domain\AI\ExplanationLanguage;
use App\Domain\AI\PromptRegistry;
use App\Domain\Practice\TextNormalizer;
use App\Models\AiConversation;
use App\Models\AiConversationReport;
use App\Models\Exercise;
use App\Models\GrammarTopic;
use App\Models\Skill;
use App\Models\VocabularyForm;
use Illuminate\Support\Facades\DB;

/**
 * End-of-conversation report: AI rubric judgement + deterministic statistics + suggested exercises
 * chosen by rules from the learner's actual mistakes (not by the model).
 */
class ReportGenerator
{
    public function __construct(private readonly AiGateway $ai, private readonly PromptRegistry $prompts) {}

    /** @return array<string, int|float> */
    public function stats(AiConversation $conversation): array
    {
        $learner = $conversation->messages->where('role', 'learner');
        $words = $learner->sum(fn ($m) => count(TextNormalizer::words($m->content)));
        $feedback = $conversation->feedback;

        return [
            'learner_turns' => $learner->count(),
            'learner_words' => $words,
            'avg_words_per_turn' => $learner->count() ? round($words / $learner->count(), 1) : 0,
            'speaking_seconds' => $conversation->speaking_seconds,
            'avg_response_seconds' => round(($learner->whereNotNull('response_ms')->avg('response_ms') ?? 0) / 1000, 1),
            'corrections' => $feedback->count(),
            'grammar_corrections' => $feedback->where('type', 'grammar')->count(),
            'vocabulary_corrections' => $feedback->whereIn('type', ['vocabulary', 'word_choice'])->count(),
        ];
    }

    public function generate(AiConversation $conversation): AiConversationReport
    {
        $conversation->load(['messages', 'feedback', 'tutor', 'scenario', 'level', 'learnerProfile.targetLanguage']);
        $profile = $conversation->learnerProfile;
        $language = $profile->targetLanguage;
        $stats = $this->stats($conversation);

        $prompt = $this->prompts->render('tutor.report', [
            'language' => $language->name,
            'level' => $conversation->level->label,
            'scenario' => $conversation->scenario?->setup ?? 'Open conversation',
            'stats' => collect($stats)->map(fn ($v, $k) => "{$k}={$v}")->implode(', ').'; spoken='.($conversation->mode === 'text' ? 'no' : 'yes'),
            'explanation_language' => ExplanationLanguage::for($profile),
        ]);
        $transcript = $conversation->messages->map(fn ($m) => ($m->role === 'learner' ? 'LEARNER: ' : 'TUTOR: ').$m->content)->implode("\n");
        $result = $this->ai->text(new TextRequest(
            operation: 'tutor.report',
            instructions: $prompt->text,
            input: "TRANSCRIPT:\n".$transcript,
            languageCode: $language->code,
            jsonSchema: $prompt->schema,
            schemaName: 'conversation_report',
            maxOutputTokens: 2000,
            userId: $profile->user_id,
            learnerProfileId: $profile->id,
            temperature: 0.3,
        ));
        $json = $result->json ?? [];
        $band = fn ($k) => max(1, min(5, (int) ($json[$k] ?? 1)));

        return DB::transaction(function () use ($conversation, $json, $band, $prompt, $result, $language) {
            $report = AiConversationReport::updateOrCreate(['ai_conversation_id' => $conversation->id], [
                'summary' => (string) ($json['summary'] ?? ''),
                'fluency_feedback' => (string) ($json['fluency_feedback'] ?? ''),
                'pronunciation_feedback' => ($json['pronunciation_feedback'] ?? '') ?: null,
                'task_completion' => $band('task_completion'),
                'grammar_score' => $band('grammar_score'),
                'vocabulary_score' => $band('vocabulary_score'),
                'interaction_score' => $band('interaction_score'),
                'prompt_version' => $prompt->versionTag,
                'model_slug' => $result->model,
            ]);
            $report->items()->delete();
            $position = 0;
            foreach (array_slice($json['vocabulary'] ?? [], 0, 8) as $v) {
                $wordId = VocabularyForm::where('language_id', $language->id)->where('form', TextNormalizer::normalize((string) $v['term']))->value('vocabulary_word_id');
                $report->items()->create(['type' => 'vocabulary', 'term' => mb_substr((string) $v['term'], 0, 200), 'detail' => $v['meaning'] ?? null, 'vocabulary_word_id' => $wordId, 'position' => $position++]);
            }
            foreach (array_slice($json['useful_expressions'] ?? [], 0, 5) as $e) {
                $report->items()->create(['type' => 'expression', 'term' => mb_substr((string) $e['term'], 0, 200), 'detail' => $e['meaning'] ?? null, 'position' => $position++]);
            }
            foreach ($this->suggestedExercises($conversation) as $exercise) {
                $report->items()->create(['type' => 'exercise', 'term' => $exercise->key, 'exercise_id' => $exercise->id, 'position' => $position++]);
            }

            return $report->load('items');
        });
    }

    /** Rules: practise the grammar topics the learner got wrong, then vocabulary if word choice was an issue. */
    private function suggestedExercises(AiConversation $conversation): array
    {
        $feedback = $conversation->feedback;
        $suggested = [];
        $topics = $feedback->whereNotNull('topic_slug')->countBy('topic_slug')->sortDesc()->keys();
        foreach ($topics as $slug) {
            $topicId = GrammarTopic::where('language_id', $conversation->learnerProfile->target_language_id)->where('slug', $slug)->value('id');
            $exercise = Exercise::available()->where('grammar_topic_id', $topicId)->first();
            if ($exercise) {
                $suggested[$exercise->id] = $exercise;
            }
        }
        if ($feedback->whereIn('type', ['vocabulary', 'word_choice'])->isNotEmpty()) {
            $vocab = Exercise::available()->where('language_id', $conversation->learnerProfile->target_language_id)
                ->where('skill_id', Skill::where('slug', Skill::VOCABULARY)->value('id'))->first();
            if ($vocab) {
                $suggested[$vocab->id] = $vocab;
            }
        }

        return array_slice(array_values($suggested), 0, 3);
    }
}
