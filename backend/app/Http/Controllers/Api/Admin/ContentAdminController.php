<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Content\ContentPackImporter;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainError;
use App\Http\Controllers\Controller;
use App\Models\ContentReview;
use App\Models\Course;
use App\Models\Exercise;
use App\Models\Language;
use ErrorException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Throwable;
use TypeError;

/**
 * Content is authored as versioned packs (JSON) and imported through the same validating importer used by
 * the seeders; published exercises with learner attempts are immutable, so editors retire and re-key instead.
 */
class ContentAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function summary(): JsonResponse
    {
        return response()->json(['data' => Language::where('is_target_enabled', true)->orderBy('code')->get()->map(fn (Language $l) => [
            'code' => $l->code, 'name' => $l->name,
            'courses' => Course::where('language_id', $l->id)->count(),
            'exercises' => Exercise::where('language_id', $l->id)->where('review_status', 'published')->count(),
            'retired' => Exercise::where('language_id', $l->id)->where('review_status', 'retired')->count(),
            'missing_audio' => Exercise::where('language_id', $l->id)->whereIn('type', ['listening', 'dictation'])->whereNull('audio_path')->count(),
        ])]);
    }

    public function exercises(Request $request): JsonResponse
    {
        $rows = Exercise::query()
            ->when($request->query('language'), fn ($q, $code) => $q->whereHas('language', fn ($l) => $l->where('code', $code)))
            ->when($request->query('status'), fn ($q, $s) => $q->where('review_status', $s))
            ->when($request->query('q'), fn ($q, $term) => $q->where('key', 'ilike', "%{$term}%"))
            ->with(['language', 'skill', 'level', 'translations'])->withCount(['questions'])
            ->orderBy('key')->paginate(25);

        return response()->json([
            'data' => collect($rows->items())->map(fn (Exercise $e) => [
                'id' => $e->public_id, 'key' => $e->key, 'title' => $e->translate('title'), 'language' => $e->language->code,
                'skill' => $e->skill->slug, 'level' => $e->level->code, 'type' => $e->type, 'status' => $e->review_status,
                'questions' => $e->questions_count, 'has_audio' => $e->audio_path !== null,
                'attempts' => DB::table('exercise_attempts')->where('exercise_id', $e->id)->count(),
            ]),
            'meta' => ['page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }

    public function setExerciseStatus(Request $request, string $exercise): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:published,retired'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $model = Exercise::where('public_id', $exercise)->firstOrFail();
        $old = $model->review_status;
        DB::transaction(function () use ($request, $model, $data, $old) {
            $model->update(['review_status' => $data['status'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            ContentReview::create(['reviewable_type' => 'exercise', 'reviewable_id' => $model->id, 'reviewer_id' => $request->user()->id,
                'decision' => $data['status'] === 'retired' ? 'retired' : 'restored', 'notes' => $data['notes'] ?? null, 'created_at' => now()]);
            $this->audit->log('admin.exercise_'.$data['status'], $model, ['review_status' => $old], ['review_status' => $data['status']]);
        });

        return response()->json(['data' => ['id' => $model->public_id, 'status' => $data['status']]]);
    }

    /** Validates (dry run) or imports a content pack. Nothing is written on a dry run or on any error. */
    public function import(Request $request, ContentPackImporter $importer): JsonResponse
    {
        $request->validate(['pack' => ['required', 'file', 'max:5120', 'mimetypes:application/json,text/plain'], 'dry_run' => ['nullable', 'boolean']]);
        $dryRun = $request->boolean('dry_run', true);
        try {
            $pack = json_decode($request->file('pack')->get(), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($pack) || ! isset($pack['language'])) {
                throw new InvalidArgumentException('The pack must be a JSON object with a "language" field.');
            }
            DB::beginTransaction();
            $counts = $importer->import($pack);
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (JsonException $e) {
            throw new DomainError('The file is not valid JSON: '.$e->getMessage(), 'invalid_pack', 422);
        } catch (InvalidArgumentException|DomainError|ModelNotFoundException|ErrorException|TypeError $e) {
            // Malformed packs (missing fields, unknown language/level) are reported, never half-imported.
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw new DomainError($e->getMessage(), 'invalid_pack', 422);
        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $e;
        }
        if (! $dryRun) {
            $this->audit->log('admin.content_imported', null, new: ['language' => $pack['language'], ...$counts]);
        }

        return response()->json(['data' => ['dry_run' => $dryRun, 'language' => $pack['language'], 'counts' => $counts]]);
    }
}
