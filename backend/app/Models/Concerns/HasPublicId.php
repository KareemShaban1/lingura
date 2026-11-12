<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Exposes a ULID `public_id` for URLs while keeping bigint primary keys internal.
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function ($model) {
            $model->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
