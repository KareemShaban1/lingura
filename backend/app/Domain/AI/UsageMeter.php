<?php

namespace App\Domain\AI;

use App\Models\AiModel;
use App\Models\AiUsageRecord;
use Illuminate\Support\Facades\Log;

/** Records every AI call with tokens/audio/characters and computed cost (from ai_models unit prices). */
class UsageMeter
{
    public function record(string $provider, string $model, string $operation, ?int $userId, ?int $profileId, array $usage): AiUsageRecord
    {
        $modelSlug = $usage['model_slug'] ?? $model;
        $aiModel = AiModel::query()
            ->whereHas('provider', fn ($q) => $q->where('slug', $provider))
            ->whereIn('slug', array_unique([$modelSlug, $model]))
            ->first();

        $cost = 0.0;
        if ($aiModel) {
            $cost += ($usage['input_tokens'] ?? 0) / 1_000_000 * ($aiModel->input_cost_per_mtok ?? 0);
            $cost += ($usage['output_tokens'] ?? 0) / 1_000_000 * ($aiModel->output_cost_per_mtok ?? 0);
            $cost += ($usage['audio_seconds'] ?? 0) / 60 * ($aiModel->audio_cost_per_minute ?? 0);
            $cost += ($usage['characters'] ?? 0) / 1_000_000 * ($aiModel->cost_per_mchar ?? 0);
        }

        $record = AiUsageRecord::create([
            'user_id' => $userId,
            'learner_profile_id' => $profileId,
            'ai_model_id' => $aiModel?->id,
            'provider_slug' => $provider,
            'model_slug' => $modelSlug,
            'operation' => $operation,
            'input_tokens' => $usage['input_tokens'] ?? 0,
            'output_tokens' => $usage['output_tokens'] ?? 0,
            'audio_seconds' => $usage['audio_seconds'] ?? 0,
            'characters' => $usage['characters'] ?? 0,
            'cost_micros' => (int) round($cost * 1_000_000),
            'latency_ms' => $usage['latency_ms'] ?? 0,
            'status' => $usage['status'],
            'error_code' => $usage['error_code'] ?? null,
        ]);

        Log::info('ai.call', ['provider' => $provider, 'model' => $modelSlug, 'operation' => $operation,
            'status' => $usage['status'], 'latency_ms' => $record->latency_ms, 'cost_micros' => $record->cost_micros]);

        return $record;
    }
}
