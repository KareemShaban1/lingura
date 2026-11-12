<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assessment extends Model
{
    public const PLACEMENT_SLUG = 'placement';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(AssessmentSection::class)->orderBy('position');
    }

    public static function activePlacement(): self
    {
        return static::query()
            ->where('slug', self::PLACEMENT_SLUG)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->firstOrFail();
    }
}
