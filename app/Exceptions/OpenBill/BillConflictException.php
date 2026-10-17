<?php

namespace App\Exceptions\OpenBill;

use DomainException;

/**
 * The bill changed since the client loaded it (its version moved on). Nothing was written.
 * The caller shows the new state (`serverVersion`) and lets the cashier reload.
 */
final class BillConflictException extends DomainException
{
    public function __construct(public int $serverVersion, string $message = '')
    {
        parent::__construct($message !== '' ? $message : __('Bill was changed on another device'));
    }
}
