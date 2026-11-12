<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    public const FREE = 'free';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_public' => 'boolean'];
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(PlanEntitlement::class);
    }

    public static function free(): self
    {
        return static::where('slug', self::FREE)->firstOrFail();
    }
}
