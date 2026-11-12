<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_user_can_register_and_receives_profile_preferences_and_student_role(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Amira', 'email' => 'Amira@Example.test',
            'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123',
            'native_language' => 'ar', 'interface_language' => 'ar', 'timezone' => 'Africa/Cairo',
            'accept_terms' => true, 'device_name' => 'phpunit',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.email', 'amira@example.test')
            ->assertJsonPath('user.profile.native_language', 'ar')
            ->assertJsonPath('user.profile.interface_direction', 'rtl')
            ->assertJsonPath('user.roles.0', 'student')
            ->assertJsonStructure(['token']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.registered']);
    }

    public function test_registration_validates_input(): void
    {
        $this->postJson('/api/v1/auth/register', ['email' => 'not-an-email', 'password' => 'short'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password', 'accept_terms']);
    }

    public function test_login_with_token_and_me_and_logout(): void
    {
        $user = $this->makeUser(['email' => 'lina@example.test']);

        $this->postJson('/api/v1/auth/login', ['email' => 'lina@example.test', 'password' => 'wrong-password', 'device_name' => 't'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'LINA@example.test', 'password' => 'secret-pass-123', 'device_name' => 't'])
            ->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'lina@example.test')
            ->assertJsonPath('data.features', ['onboarding', 'assessment', 'plan', 'progress', 'courses', 'practice', 'grammar', 'vocabulary']);

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(0, $user->tokens()->count());
        $this->assertTrue(AuditLog::where('action', 'user.login')->exists());
    }

    public function test_suspended_users_cannot_log_in(): void
    {
        $user = $this->makeUser(['email' => 'blocked@example.test']);
        $user->update(['status' => User::STATUS_SUSPENDED]);

        $this->postJson('/api/v1/auth/login', ['email' => 'blocked@example.test', 'password' => 'secret-pass-123', 'device_name' => 't'])
            ->assertUnprocessable();
    }

    public function test_protected_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/learner-profiles')->assertUnauthorized();
    }

    public function test_password_reset_does_not_reveal_whether_an_account_exists(): void
    {
        Notification::fake();
        $user = $this->makeUser(['email' => 'known@example.test']);

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'known@example.test'])->assertOk()->json('message');
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'unknown@example.test'])->assertOk()->json('message');

        $this->assertSame($known, $unknown);
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            $this->postJson('/api/v1/auth/reset-password', [
                'token' => $n->token, 'email' => $user->email,
                'password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1',
            ])->assertOk();

            return true;
        });
    }

    public function test_login_is_rate_limited(): void
    {
        $this->makeUser(['email' => 'rl@example.test']);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'rl@example.test', 'password' => 'nope', 'device_name' => 't']);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'rl@example.test', 'password' => 'nope', 'device_name' => 't'])
            ->assertStatus(429);
    }
}
