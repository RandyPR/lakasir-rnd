<?php

namespace App\Exceptions\OpenBill;

use DomainException;

/**
 * There is nothing to save or to pay: the cart is empty, or the bill has no lines.
 */
final class BillEmptyException extends DomainException
{
    public function __construct(string $message = '')
    {
        parent::__construct($message !== '' ? $message : __('Add the bill first'));
    }
}
