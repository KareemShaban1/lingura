<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Languages\CapabilityMatrix;
use App\Domain\Shared\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Language;
use App\Models\LanguageCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Language capability matrix and audit log browsing. */
class SystemAdminController extends Controller
{
    public function languages(): JsonResponse
    {
        return response()->json(['data' => Language::orderBy('code')->with('capabilities')->get()->map(fn (Language $l) => [
            'code' => $l->code, 'name' => $l->name, 'is_active' => (bool) $l->is_active, 'is_target_enabled' => (bool) $l->is_target_enabled,
            'capabilities' => $l->capabilities->map(fn (LanguageCapability $c) => [
                'id' => $c->id, 'feature' => $c->feature, 'provider' => $c->provider_slug, 'status' => $c->status,
                'quality_tier' => $c->quality_tier, 'notes' => $c->notes,
            ])->values(),
        ])]);
    }

    public function updateCapability(Request $request, LanguageCapability $capability, CapabilityMatrix $matrix, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:supported,beta,unsupported'],
            'quality_tier' => ['sometimes', 'integer', 'between:1,3'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $old = $capability->only(array_keys($data));
        $capability->update($data);
        $matrix->flush();
        $audit->log('admin.capability_updated', $capability, $old, $data);

        return $this->languages();
    }

    public function audit(Request $request): JsonResponse
    {
        $rows = AuditLog::query()
            ->when($request->query('action'), fn ($q, $a) => $q->where('action', 'like', str_replace(['%', '_'], ['\%', '\_'], $a).'%'))
            ->when($request->query('actor'), fn ($q, $id) => $q->where('actor_user_id', (int) $id))
            ->with('actor:id,name,email')->latest('id')->paginate(50);

        return response()->json([
            'data' => collect($rows->items())->map(fn (AuditLog $l) => [
                'id' => $l->id, 'action' => $l->action, 'actor' => $l->actor ? ['id' => $l->actor->id, 'name' => $l->actor->name, 'email' => $l->actor->email] : null,
                'subject' => $l->auditable_type ? class_basename($l->auditable_type).'#'.$l->auditable_id : null,
                'old' => $l->old_values, 'new' => $l->new_values, 'ip' => $l->ip_address, 'created_at' => $l->created_at->toIso8601String(),
            ]),
            'meta' => ['page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }
}
