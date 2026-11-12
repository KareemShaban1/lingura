<?php

namespace Tests\Feature;

use App\Models\DataExportRequest;
use App\Models\User;
use App\Notifications\DataExportReady;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivacyTest extends TestCase
{
    public function test_user_can_export_their_data(): void
    {
        Storage::fake('local');
        Notification::fake();
        $profile = $this->makeLearner('es');
        $this->actingAsUser($profile->user);

        $this->postJson('/api/v1/me/data-exports')->assertAccepted()->assertJsonPath('data.status', 'ready');
        $export = DataExportRequest::firstOrFail();
        Storage::disk('local')->assertExists($export->path);
        $json = json_decode(Storage::disk('local')->get($export->path), true);
        $this->assertSame($profile->user->email, $json['user']['email']);
        $this->assertSame('es', $json['learner_profiles'][0]['target_language']['code']);
        Notification::assertSentTo($profile->user, DataExportReady::class);

        $url = $this->getJson("/api/v1/me/data-exports/{$export->public_id}/download")->assertOk()->json('url');
        $this->get($url)->assertOk();

        // Another user cannot download it.
        $this->actingAsUser($this->makeUser());
        $this->getJson("/api/v1/me/data-exports/{$export->public_id}/download")->assertNotFound();
    }

    public function test_account_deletion_requires_password_anonymises_and_purges_later(): void
    {
        $profile = $this->makeLearner('es');
        $user = $this->actingAsUser($profile->user);
        $email = $user->email;

        $this->deleteJson('/api/v1/me', ['password' => 'wrong'])->assertUnprocessable();
        $this->deleteJson('/api/v1/me', ['password' => 'secret-pass-123'])->assertOk();

        $deleted = User::withTrashed()->find($user->id);
        $this->assertNotNull($deleted->deleted_at);
        $this->assertNotSame($email, $deleted->email);
        $this->assertSame('Deleted user', $deleted->name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.deletion_requested']);

        $this->travel(31)->days();
        $this->artisan('lf:accounts:purge')->assertSuccessful();
        $this->assertNull(User::withTrashed()->find($user->id));
        $this->assertDatabaseMissing('learner_profiles', ['id' => $profile->id]);
    }

    public function test_preferences_and_interface_language_update(): void
    {
        $this->actingAsUser($this->makeUser());

        $this->patchJson('/api/v1/me/preferences', ['theme' => 'dark', 'marketing_opt_in' => true])->assertOk()
            ->assertJsonPath('data.preferences.theme', 'dark');
        $this->patchJson('/api/v1/me/profile', ['interface_language' => 'ar', 'timezone' => 'Asia/Dubai'])->assertOk()
            ->assertJsonPath('data.profile.interface_direction', 'rtl')
            ->assertJsonPath('data.profile.timezone', 'Asia/Dubai');
        $this->patchJson('/api/v1/me/profile', ['interface_language' => 'de'])->assertUnprocessable();
    }
}
