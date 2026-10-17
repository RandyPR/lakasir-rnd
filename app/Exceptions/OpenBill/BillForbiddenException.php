<?php

namespace App\Exceptions\OpenBill;

use DomainException;

/**
 * The user may not do this (OpenBillGate said no). Nothing was written.
 */
final class BillForbiddenException extends DomainException
{
    public function __construct(string $message = '')
    {
        parent::__construct($message !== '' ? $message : __('You are not allowed to do this'));
    }

    /**
     * Cancelling something the kitchen already received needs more than creating the bill.
     */
    public static function cancelSent(): self
    {
        return new self(__('Only the owner or a manager can cancel items already sent to the kitchen'));
    }
}
