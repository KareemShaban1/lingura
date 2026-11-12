<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasUlids;

    /** Statuses that grant the plan's entitlements (past_due keeps access during the provider's retry window). */
    public const LIVE_STATUSES = ['trialing', 'active', 'past_due'];

    protected $guarded = ['id'];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime', 'current_period_end' => 'datetime', 'canceled_at' => 'datetime',
            'ended_at' => 'datetime', 'cancel_at_period_end' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE_STATUSES, true);
    }
}
