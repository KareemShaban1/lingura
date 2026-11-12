<?php

namespace App\Domain\Identity;

use App\Domain\Shared\AuditLogger;
use App\Jobs\BuildDataExport;
use App\Models\DataExportRequest;
use App\Models\Language;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function register(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'password' => $data['password'],
                'status' => User::STATUS_ACTIVE,
            ]);
            $user->profile()->create([
                'native_language_id' => Language::where('code', $data['native_language'] ?? null)->value('id'),
                'interface_language_id' => Language::where('code', $data['interface_language'] ?? 'en')->where('is_interface_enabled', true)->value('id')
                    ?? Language::where('code', 'en')->value('id'),
                'timezone' => $data['timezone'] ?? 'UTC',
            ]);
            $user->preferences()->create(['data_processing_consent_at' => now()]);
            $user->roles()->attach(Role::where('slug', 'student')->value('id'), ['created_at' => now()]);
            $this->audit->log('user.registered', $user, actorId: $user->id);

            return $user;
        });
    }

    public function requestExport(User $user): DataExportRequest
    {
        $pending = $user->hasMany(DataExportRequest::class)->where('status', 'pending')->first();
        if ($pending) {
            return $pending;
        }
        $request = DataExportRequest::create(['user_id' => $user->id, 'status' => 'pending']);
        BuildDataExport::dispatch($request->id);
        $this->audit->log('user.data_export_requested', $user);

        return $request;
    }

    /**
     * Immediately anonymises personal data, revokes access and soft-deletes. Learning data is
     * hard-deleted by `lf:accounts:purge` after the grace period (cascading foreign keys).
     */
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->audit->log('user.deletion_requested', $user, actorId: $user->id);
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->forceFill([
                'name' => 'Deleted user',
                'email' => "deleted-{$user->id}-".Str::random(8).'@deleted.invalid',
                'password' => Str::random(64),
                'remember_token' => null,
                'status' => User::STATUS_SUSPENDED,
            ])->save();
            $user->profile()->update(['country_code' => null]);
            $user->delete();
        });
    }
}
