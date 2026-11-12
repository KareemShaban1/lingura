<?php

namespace App\Http\Controllers\Api;

use App\Domain\Identity\AccountService;
use App\Domain\Media\MediaUrlService;
use App\Domain\Shared\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\DataExportRequest;
use App\Models\Language;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MeController extends Controller
{
    public function __construct(private readonly AccountService $accounts, private readonly AuditLogger $audit) {}

    public function updateProfile(Request $request): UserResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'native_language' => ['sometimes', 'nullable', 'string', Rule::exists('languages', 'code')],
            'interface_language' => ['sometimes', 'string', Rule::exists('languages', 'code')->where('is_interface_enabled', true)],
            'timezone' => ['sometimes', 'timezone:all'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'alpha'],
        ]);
        $user = $request->user();
        $profile = $user->profile()->firstOrCreate([]);
        $before = $profile->only(['native_language_id', 'interface_language_id', 'timezone', 'country_code']);

        if (isset($data['name'])) {
            $user->update(['name' => $data['name']]);
        }
        $profile->update(array_filter([
            'native_language_id' => array_key_exists('native_language', $data) ? Language::where('code', $data['native_language'])->value('id') : null,
            'interface_language_id' => isset($data['interface_language']) ? Language::where('code', $data['interface_language'])->value('id') : null,
            'timezone' => $data['timezone'] ?? null,
            'country_code' => isset($data['country_code']) ? strtoupper($data['country_code']) : null,
        ], fn ($v) => $v !== null));
        $this->audit->log('user.profile_updated', $profile, $before, $profile->fresh()->only(array_keys($before)));

        return $this->current($request);
    }

    public function updatePreferences(Request $request): UserResource
    {
        $data = $request->validate([
            'theme' => ['sometimes', Rule::in(['light', 'dark', 'system'])],
            'daily_reminder_enabled' => ['sometimes', 'boolean'],
            'reminder_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'marketing_opt_in' => ['sometimes', 'boolean'],
        ]);
        $preferences = $request->user()->preferences()->firstOrCreate([]);
        $before = $preferences->only(array_keys($data));
        $preferences->update($data);
        $this->audit->log('user.preferences_updated', $preferences, $before, $data);

        return $this->current($request);
    }

    public function requestExport(Request $request): JsonResponse
    {
        $export = $this->accounts->requestExport($request->user());

        return response()->json(['data' => $this->exportPayload($export->fresh())], 202);
    }

    public function exports(Request $request): JsonResponse
    {
        $exports = DataExportRequest::where('user_id', $request->user()->id)->latest()->limit(5)->get();

        return response()->json(['data' => $exports->map(fn ($e) => $this->exportPayload($e))]);
    }

    public function downloadExport(Request $request, DataExportRequest $export, MediaUrlService $media): JsonResponse
    {
        abort_unless($export->user_id === $request->user()->id, 404);
        abort_unless($export->status === 'ready' && $export->expires_at?->isFuture(), 410, 'This export has expired.');

        return response()->json(['url' => $media->temporaryUrl($export->path)]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();
        if (! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
        }

        $this->accounts->delete($user);
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
        }

        return response()->json(['message' => 'Your account has been deleted.']);
    }

    private function current(Request $request): UserResource
    {
        return new UserResource($request->user()->fresh()->load(['profile.nativeLanguage', 'profile.interfaceLanguage', 'preferences', 'roles', 'learnerProfiles']));
    }

    private function exportPayload(DataExportRequest $export): array
    {
        return [
            'id' => $export->public_id,
            'status' => $export->status,
            'requested_at' => $export->created_at->toIso8601String(),
            'ready_at' => $export->ready_at?->toIso8601String(),
            'expires_at' => $export->expires_at?->toIso8601String(),
        ];
    }
}
