<?php

namespace Tests;

use App\Domain\AI\Drivers\FakeDriver;
use App\Domain\Identity\AccountService;
use App\Domain\Learning\LearnerProfileService;
use App\Models\LearnerProfile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = DatabaseSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        FakeDriver::reset();
    }

    protected function makeUser(array $overrides = []): User
    {
        static $n = 0;
        $n++;

        return app(AccountService::class)->register(array_merge([
            'name' => "Learner {$n}",
            'email' => "learner{$n}_".uniqid().'@example.test',
            'password' => 'secret-pass-123',
            'native_language' => 'ar',
            'interface_language' => 'en',
            'timezone' => 'UTC',
        ], $overrides));
    }

    protected function makeLearner(string $language = 'es', array $profile = [], ?User $user = null): LearnerProfile
    {
        $user ??= $this->makeUser();

        return app(LearnerProfileService::class)->create($user, array_merge([
            'target_language' => $language,
            'purpose' => 'general',
            'daily_minutes' => 30,
        ], $profile));
    }

    protected function actingAsUser(User $user): User
    {
        Sanctum::actingAs($user->fresh());

        return $user;
    }
}
