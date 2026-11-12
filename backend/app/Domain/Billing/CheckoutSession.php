<?php

namespace App\Domain\Billing;

final readonly class CheckoutSession
{
    public function __construct(public string $id, public string $url) {}
}
