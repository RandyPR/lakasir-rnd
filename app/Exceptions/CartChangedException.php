<?php

namespace App\Exceptions;

use DomainException;
use Throwable;

/**
 * Thrown inside the sale transaction when the cashier's cart is no longer what the payment was built from
 * (another tab or device changed it, or it was already paid). The sale is rolled back; the cashier reviews the
 * cart and pays again.
 */
class CartChangedException extends DomainException
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : __('The cart changed, review it and pay again'), $code, $previous);
    }
}
