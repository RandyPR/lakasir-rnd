<?php

namespace App\Exceptions\OpenBill;

use DomainException;

/**
 * Cancelling a sent item or voiding a bill needs a written reason (3 to 255 characters).
 */
final class BillReasonRequiredException extends DomainException
{
    public function __construct(string $message = '')
    {
        parent::__construct($message !== '' ? $message : __('A reason is required'));
    }
}
