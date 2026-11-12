<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PurgeDeletedAccounts extends Command
{
    protected $signature = 'lf:accounts:purge';

    protected $description = 'Permanently delete accounts whose deletion grace period has passed';

    public function handle(): int
    {
        $count = 0;
        User::onlyTrashed()
            ->where('deleted_at', '<', now()->subDays(config('learning.account_purge_after_days')))
            ->chunkById(100, function ($users) use (&$count) {
                foreach ($users as $user) {
                    $user->forceDelete(); // learning data cascades via foreign keys
                    $count++;
                }
            });
        $this->info("{$count} accounts purged.");

        return self::SUCCESS;
    }
}
