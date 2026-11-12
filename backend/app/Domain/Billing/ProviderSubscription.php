<?php

namespace App\Domain\Billing;

use Illuminate\Support\Carbon;

/** Provider-neutral snapshot of a subscription, the only shape BillingService syncs from. */
final readonly class ProviderSubscription
{
    /** @param array<string, string> $metadata */
    public function __construct(
        public string $id,
        public string $customerId,
        public string $status,
        public ?string $priceId,
        public ?Carbon $periodStart,
        public ?Carbon $periodEnd,
        public bool $cancelAtPeriodEnd,
        public ?Carbon $canceledAt,
        public ?Carbon $endedAt,
        public array $metadata = [],
    ) {}
}
