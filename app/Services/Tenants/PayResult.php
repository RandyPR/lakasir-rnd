<?php

namespace App\Services\Tenants;

use App\Models\Tenants\Selling;

/**
 * What OpenBillService::pay returns. `alreadyPaid` is true when the bill had been paid before this call
 * (a retried request, or a second device): no new sale was made and `selling` is the existing one.
 * `kitchen` is reserved for a kitchen ticket that belongs to this payment (the caller fills it in).
 */
final class PayResult
{
    public function __construct(
        public Selling $selling,
        public bool $alreadyPaid = false,
        public ?array $kitchen = null,
    ) {
    }
}
