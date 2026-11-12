<?php

namespace App\Console\Commands;

use App\Domain\Billing\BillingService;
use Illuminate\Console\Command;

class ReconcileBilling extends Command
{
    protected $signature = 'lf:billing:reconcile';

    protected $description = 'End lapsed complimentary grants (and roll local fake subscriptions). Paid subscriptions follow provider webhooks.';

    public function handle(BillingService $billing): int
    {
        $this->info($billing->reconcile().' subscription(s) updated.');

        return self::SUCCESS;
    }
}
