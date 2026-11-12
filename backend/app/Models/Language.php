<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Language extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_target_enabled' => 'boolean',
            'is_interface_enabled' => 'boolean',
        ];
    }

    public function variants(): HasMany
    {
        return $this->hasMany(LanguageVariant::class);
    }

    public function capabilities(): HasMany
    {
        return $this->hasMany(LanguageCapability::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isRtl(): bool
    {
        return $this->direction === 'rtl';
    }
}
