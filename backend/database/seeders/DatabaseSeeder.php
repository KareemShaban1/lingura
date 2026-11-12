<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([ReferenceDataSeeder::class, ItemBankSeeder::class, ContentSeeder::class, PromptSeeder::class, Phase3ContentSeeder::class, BillingPlanSeeder::class, GamificationSeeder::class]);

        if (app()->environment('local')) {
            $admin = User::firstOrCreate(
                ['email' => 'admin@linguaforge.test'],
                ['name' => 'Local Admin', 'password' => 'password', 'email_verified_at' => now()],
            );
            $admin->roles()->syncWithoutDetaching([Role::where('slug', 'super_admin')->value('id')]);
        }
    }
}
