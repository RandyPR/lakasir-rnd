<?php

namespace App\Services\Tenants;

use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * The single formula for an order total. Used by the cart, a bill, the provisional bill, the bill list
 * and the payment modal so they always show the same numbers.
 *
 * A "line" is any object with qty, price, discount_price and (optionally) a priceUnit relation:
 * CartItem or OpenBillItem. `price` is the line total before the line discount.
 */
final class OrderTotals
{
    private function __construct()
    {
    }

    /**
     * Line total before the line discount. A price-unit line is always priced from the unit, like the old cart.
     */
    public static function lineTotal($line): float
    {
        $unit = ($line->price_unit_id ?? null) ? $line->priceUnit : null;

        if ($unit) {
            return Money::round($unit->selling_price * (float) $line->qty);
        }

        return Money::round($line->price ?? 0);
    }

    public static function unitPrice($line): float
    {
        $total = self::lineTotal($line);
        $qty = (float) $line->qty;

        return $qty > 0 ? Money::round($total / $qty) : $total;
    }

    /**
     * False when unit price x qty does not give the line total back (UI and print prefix the unit with "~").
     */
    public static function unitIsExact($line): bool
    {
        return Money::eq(self::unitPrice($line) * (float) $line->qty, self::lineTotal($line));
    }

    /**
     * Tax is taken from the subtotal before any discount (same as the old Cashier and mapProductRequest).
     * total_price is the value stored in sellings.total_price; amount_due is what the customer pays.
     *
     * @param  Collection<int, mixed>  $lines
     * @return array{subtotal:float,line_discount:float,tax_price:float,header_discount:float,total_price:float,amount_due:float,qty:float,count:int}
     */
    public static function compute(Collection $lines, float $taxPercent, float $headerDiscount = 0.0): array
    {
        $subtotal = 0.0;
        $lineDiscount = 0.0;
        $qty = 0.0;

        foreach ($lines as $line) {
            $subtotal += self::lineTotal($line);
            $discount = (float) ($line->discount_price ?? 0);
            if ($discount > 0) {
                $lineDiscount += $discount;
            }
            $qty += (float) $line->qty;
        }

        $subtotal = Money::round($subtotal);
        $lineDiscount = Money::round($lineDiscount);
        $headerDiscount = Money::round(max(0.0, $headerDiscount));
        $taxPrice = Money::round($subtotal * $taxPercent / 100);
        $totalPrice = Money::round($subtotal + $taxPrice);
        $amountDue = max(0.0, Money::round($totalPrice - $lineDiscount - $headerDiscount));

        return [
            'subtotal' => $subtotal,
            'line_discount' => $lineDiscount,
            'tax_price' => $taxPrice,
            'header_discount' => $headerDiscount,
            'total_price' => $totalPrice,
            'amount_due' => $amountDue,
            'qty' => Money::qty($qty),
            'count' => $lines->count(),
        ];
    }
}
