<?php

namespace App\Exceptions\OpenBill;

use App\Enums\OpenBillStatus;
use DomainException;

/**
 * The bill is not open any more (paid, void) or does not exist. `status` and `sellingId` are filled when the
 * bill was found: a paid bill carries the sale that paid it, so a retried payment can open the same receipt.
 */
final class BillClosedException extends DomainException
{
    public function __construct(
        string $message = '',
        public ?OpenBillStatus $status = null,
        public ?int $sellingId = null,
    ) {
        if ($message === '') {
            $message = $status === OpenBillStatus::Paid ? __('Bill already paid') : __('Bill is closed');
        }

        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self(__('Bill not found'));
    }

    public function isPaid(): bool
    {
        return $this->status === OpenBillStatus::Paid;
    }
}
