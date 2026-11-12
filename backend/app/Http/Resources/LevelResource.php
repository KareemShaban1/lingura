<?php

namespace App\Http\Resources;

use App\Models\CefrLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CefrLevel */
class LevelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['code' => $this->code, 'label' => $this->label, 'ordinal' => $this->ordinal];
    }
}
