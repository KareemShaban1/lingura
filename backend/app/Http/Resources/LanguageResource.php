<?php

namespace App\Http\Resources;

use App\Domain\Languages\CapabilityMatrix;
use App\Models\Language;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Language */
class LanguageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'native_name' => $this->native_name,
            'direction' => $this->direction,
            'script' => $this->script,
            'is_target_enabled' => $this->is_target_enabled,
            'is_interface_enabled' => $this->is_interface_enabled,
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->where('is_active', true)->values()->map(fn ($v) => [
                'code' => $v->code, 'name' => $v->name, 'is_default' => $v->is_default,
            ])),
            'capabilities' => app(CapabilityMatrix::class)->forLanguage($this->resource),
        ];
    }
}
