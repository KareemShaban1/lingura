<?php

namespace App\Jobs;

use App\Models\DataExportRequest;
use App\Models\LearningEvent;
use App\Models\User;
use App\Notifications\DataExportReady;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** GDPR-style export of everything we hold about a user, written to private storage. */
class BuildDataExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $requestId) {}

    public function handle(): void
    {
        $request = DataExportRequest::findOrFail($this->requestId);
        $user = User::with(['profile.nativeLanguage', 'profile.interfaceLanguage', 'preferences', 'roles'])->findOrFail($request->user_id);

        $profiles = $user->learnerProfiles()->withTrashed()->with([
            'targetLanguage', 'variant', 'purpose', 'focusSkills', 'scheduleSlots', 'preferences',
            'skillProfiles.skill', 'skillProfiles.level', 'skillProfiles.scores',
            'assessmentAttempts.sections.skill', 'assessmentAttempts.answers', 'assessmentAttempts.findings',
            'sessions',
        ])->get();

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'user' => $user->only(['name', 'email', 'created_at']),
            'profile' => $user->profile,
            'preferences' => $user->preferences,
            'roles' => $user->roles->pluck('slug'),
            'learner_profiles' => $profiles,
            'learning_events' => LearningEvent::where('user_id', $user->id)->orderBy('occurred_at')->get(),
        ];

        $path = "exports/{$user->id}/{$request->public_id}.json";
        Storage::disk(config('learning.media.disk'))->put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $request->update([
            'status' => 'ready',
            'path' => $path,
            'ready_at' => now(),
            'expires_at' => now()->addHours(config('learning.data_export_ttl_hours')),
        ]);
        $user->notify(new DataExportReady($request));
    }

    public function failed(Throwable $e): void
    {
        DataExportRequest::whereKey($this->requestId)->update(['status' => 'failed']);
    }
}
