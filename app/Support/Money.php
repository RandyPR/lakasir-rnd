<?php

namespace App\Support;

/**
 * The only place that rounds or compares money (2 decimals) and quantities (3 decimals).
 * Totals, payments and stock checks must agree to the cent, so nothing else calls round() or == on them.
 */
final class Money
{
    private function __construct()
    {
    }

    public static function round(float|int|string|null $v, int $scale = 2): float
    {
        return round((float) $v, $scale);
    }

    /**
     * Same as the old Livewire input handling: everything except digits and the dot is dropped.
     */
    public static function parse(mixed $v): float
    {
        if (is_array($v) || is_object($v)) {
            return 0.0;
        }

        return floatval(preg_replace('/[^\d.]/', '', (string) $v));
    }

    public static function gte(float $a, float $b): bool
    {
        return self::round($a) >= self::round($b);
    }

    public static function eq(float $a, float $b): bool
    {
        return self::round($a) === self::round($b);
    }

    public static function qty(float $v): float
    {
        return round($v, 3);
    }

    public static function qtyEq(float $a, float $b): bool
    {
        return self::qty($a) === self::qty($b);
    }
}
