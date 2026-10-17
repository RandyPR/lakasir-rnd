<?php

namespace App\Enums;

enum OpenBillStatus: string
{
    case Open = 'open';
    case Paid = 'paid';
    case Void = 'void';

    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
