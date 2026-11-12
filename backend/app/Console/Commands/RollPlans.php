<?php

namespace App\Console\Commands;

use App\Domain\Learning\PlanGenerator;
use App\Models\LearningPlan;
use Illuminate\Console\Command;

/** Creates the next weekly version for active plans whose current window has ended. */
class RollPlans extends Command
{
    protected $signature = 'lf:plans:roll';

    protected $description = 'Generate the next plan version for active plans whose week has ended';

    public function handle(PlanGenerator $generator): int
    {
        $count = 0;
        LearningPlan::where('status', LearningPlan::STATUS_ACTIVE)
            ->whereHas('currentVersion', fn ($q) => $q->whereDate('valid_until', '<', now()->subDay()->toDateString()))
            ->with('learnerProfile')
            ->chunkById(200, function ($plans) use ($generator, &$count) {
                foreach ($plans as $plan) {
                    if ($plan->learnerProfile) {
                        $generator->generate($plan->learnerProfile, 'weekly_rollover');
                        $count++;
                    }
                }
            });
        $this->info("{$count} plans rolled over.");

        return self::SUCCESS;
    }
}
