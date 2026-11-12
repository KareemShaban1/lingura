<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\AI\AiGateway;
use App\Domain\AI\PromptRegistry;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainError;
use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\AiPrompt;
use App\Models\AiPromptVersion;
use App\Models\AiProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** AI configuration: provider/model kill switches and unit prices, versioned prompts with draft → active. */
class AiAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly PromptRegistry $prompts) {}

    public function providers(): JsonResponse
    {
        $usage = DB::table('ai_usage_records')->where('created_at', '>=', now()->subDays(7))
            ->groupBy('ai_model_id')->selectRaw('ai_model_id, count(*) as calls, sum(cost_micros) as cost, avg(latency_ms) as latency, sum(case when status = \'error\' then 1 else 0 end) as errors')
            ->get()->keyBy('ai_model_id');

        return response()->json(['data' => AiProvider::with('models')->orderBy('slug')->get()->map(fn (AiProvider $p) => [
            'id' => $p->id, 'slug' => $p->slug, 'name' => $p->name, 'driver' => $p->driver, 'is_enabled' => $p->is_enabled,
            'is_default' => config('ai.default_provider') === $p->slug,
            'models' => $p->models->sortBy('operation')->values()->map(fn (AiModel $m) => [
                'id' => $m->id, 'slug' => $m->slug, 'operation' => $m->operation, 'is_enabled' => $m->is_enabled,
                'input_cost_per_mtok' => $m->input_cost_per_mtok, 'output_cost_per_mtok' => $m->output_cost_per_mtok,
                'audio_cost_per_minute' => $m->audio_cost_per_minute, 'cost_per_mchar' => $m->cost_per_mchar,
                'usage_7d' => isset($usage[$m->id]) ? ['calls' => (int) $usage[$m->id]->calls, 'cost_micros' => (int) $usage[$m->id]->cost,
                    'avg_latency_ms' => (int) $usage[$m->id]->latency, 'errors' => (int) $usage[$m->id]->errors] : null,
            ]),
        ])]);
    }

    public function updateProvider(Request $request, AiProvider $provider): JsonResponse
    {
        $data = $request->validate(['is_enabled' => ['required', 'boolean']]);
        $old = ['is_enabled' => $provider->is_enabled];
        $provider->update($data);
        $provider->models->each(fn ($m) => Cache::forget(AiGateway::switchKey($provider->slug, $m->slug)));
        $this->audit->log('admin.ai_provider_updated', $provider, $old, $data);

        return $this->providers();
    }

    public function updateModel(Request $request, AiModel $model): JsonResponse
    {
        $data = $request->validate([
            'is_enabled' => ['sometimes', 'boolean'],
            'input_cost_per_mtok' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10000'],
            'output_cost_per_mtok' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10000'],
            'audio_cost_per_minute' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000'],
            'cost_per_mchar' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10000'],
        ]);
        $old = $model->only(array_keys($data));
        $model->update($data);
        Cache::forget(AiGateway::switchKey($model->provider->slug, $model->slug));
        $this->audit->log('admin.ai_model_updated', $model, $old, $data);

        return $this->providers();
    }

    public function prompts(): JsonResponse
    {
        return response()->json(['data' => AiPrompt::with(['versions' => fn ($q) => $q->orderByDesc('version')])->orderBy('key')->get()->map(fn (AiPrompt $p) => [
            'key' => $p->key,
            'description' => $p->description ?? null,
            'versions' => $p->versions->map(fn (AiPromptVersion $v) => [
                'id' => $v->id, 'version' => $v->version, 'status' => $v->status, 'template' => $v->template,
                'json_schema' => $v->json_schema, 'created_at' => $v->created_at?->toIso8601String(),
            ]),
        ])]);
    }

    /** New versions start as drafts; the schema is inherited unless replaced (outputs must stay machine-checkable). */
    public function createVersion(Request $request, string $key): JsonResponse
    {
        $data = $request->validate(['template' => ['required', 'string', 'min:20', 'max:20000'], 'json_schema' => ['nullable', 'array']]);
        $prompt = AiPrompt::where('key', $key)->firstOrFail();
        $active = $prompt->versions()->where('status', 'active')->first();
        $missing = $active ? $this->missingVariables($active->template, $data['template']) : [];
        if ($missing) {
            throw new DomainError('The new template drops variables the application provides: '.implode(', ', $missing).'.', 'template_variables_missing', 422);
        }
        $version = $prompt->versions()->create([
            'version' => ((int) $prompt->versions()->max('version')) + 1,
            'template' => $data['template'],
            'json_schema' => $data['json_schema'] ?? $active?->json_schema,
            'status' => 'draft',
        ]);
        $this->audit->log('admin.prompt_version_created', $version, new: ['key' => $key, 'version' => $version->version]);

        return response()->json(['data' => ['id' => $version->id, 'version' => $version->version, 'status' => 'draft']], 201);
    }

    public function activate(AiPromptVersion $version): JsonResponse
    {
        $key = $version->prompt->key;
        DB::transaction(function () use ($version) {
            AiPromptVersion::where('ai_prompt_id', $version->ai_prompt_id)->where('status', 'active')->update(['status' => 'retired']);
            $version->update(['status' => 'active']);
        });
        $this->prompts->flush($key);
        $this->audit->log('admin.prompt_version_activated', $version, new: ['key' => $key, 'version' => $version->version]);

        return $this->prompts();
    }

    /** @return list<string> */
    private function missingVariables(string $old, string $new): array
    {
        preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $old, $a);
        preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $new, $b);

        return array_values(array_diff(array_unique($a[1]), $b[1]));
    }
}
