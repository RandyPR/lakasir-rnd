<?php

namespace App\Services\Tenants;

use App\Support\Money;

/**
 * Line price when the quantity of a BILL line changes. The price stored on a bill line is what the
 * customer was quoted when it was ordered (a later price change must not rewrite it), so only the
 * added part is priced at today's unit price and a reduction scales the stored price down.
 * Lines of the private cart keep the old behaviour (selling price x qty) and do not use this.
 */
final class OrderLinePricing
{
    private function __construct()
    {
    }

    public static function repriceOnQtyChange(float $oldQty, float $oldPrice, float $newQty, float $currentUnitPrice): float
    {
        if (Money::qtyEq($oldQty, $newQty)) {
            return Money::round($oldPrice);
        }

        if ($newQty > $oldQty) {
            return Money::round($oldPrice + ($newQty - $oldQty) * $currentUnitPrice);
        }

        if ($oldQty <= 0) {
            return Money::round($newQty * $currentUnitPrice);
        }

        return Money::round($oldPrice / $oldQty * $newQty);
    }
}
