<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LanguageResource;
use App\Http\Resources\LevelResource;
use App\Models\CefrLevel;
use App\Models\Language;
use App\Models\LearningPurpose;
use App\Models\Skill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CatalogController extends Controller
{
    public function languages(): AnonymousResourceCollection
    {
        return LanguageResource::collection(Language::active()->with('variants')->orderBy('sort_order')->get());
    }

    public function language(string $code): LanguageResource
    {
        return new LanguageResource(Language::active()->where('code', $code)->with('variants')->firstOrFail());
    }

    public function onboarding(): JsonResponse
    {
        return response()->json([
            'languages' => LanguageResource::collection(Language::active()->with('variants')->orderBy('sort_order')->get()),
            'purposes' => LearningPurpose::orderBy('sort_order')->get(['slug', 'name', 'description']),
            'skills' => Skill::orderBy('sort_order')->get(['slug', 'name', 'is_productive']),
            'levels' => LevelResource::collection(CefrLevel::orderBy('ordinal')->get()),
            'daily_minutes' => ['min' => config('learning.plan.min_daily_minutes'), 'max' => config('learning.plan.max_daily_minutes'), 'suggested' => [10, 15, 20, 30, 45, 60]],
        ]);
    }
}
