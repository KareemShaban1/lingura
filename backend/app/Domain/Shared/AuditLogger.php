<?php

namespace App\Domain\Shared;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public function log(string $action, ?Model $subject = null, array $old = [], array $new = [], ?int $actorId = null): AuditLog
    {
        $request = app()->runningInConsole() ? null : request();

        return AuditLog::create([
            'actor_user_id' => $actorId ?? $request?->user()?->id ?? auth()->id(), // auth() also covers console/test contexts
            'action' => $action,
            'auditable_type' => $subject ? $subject->getMorphClass() : null,
            'auditable_id' => $subject?->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }
}
