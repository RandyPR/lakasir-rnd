<?php

use App\Models\Tenants\CartItem;
use App\Models\Tenants\OpenBillItem;
use App\Models\Tenants\PriceUnit;
use App\Services\Tenants\OrderTotals;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * A bill line without touching the database. `price` is the line total before the line discount.
 * With $unitSellingPrice the line is a price-unit line: the stored price is ignored, the unit decides.
 */
function orderTotalsLine(float $qty, ?float $price, ?float $discount = null, ?float $unitSellingPrice = null): OpenBillItem
{
    $line = new OpenBillItem;
    $line->forceFill([
        'qty' => $qty,
        'price' => $price,
        'discount_price' => $discount,
        'price_unit_id' => $unitSellingPrice === null ? null : 1,
    ]);

    if ($unitSellingPrice !== null) {
        $unit = new PriceUnit;
        $unit->forceFill(['id' => 1, 'selling_price' => $unitSellingPrice, 'stock' => 1]);
        $line->setRelation('priceUnit', $unit);
    }

    return $line;
}

/**
 * The formula the cashier used before the refactor (Cashier::calculateTotalPrice), kept here to prove the
 * new math gives the same numbers for ordinary orders.
 *
 * @param  array<int, array{0: float, 1: float}>  $rows  [price, discount]
 */
function orderTotalsOldFormula(array $rows, float $tax): float
{
    $subTotal = 0.0;
    $discount = 0.0;
    foreach ($rows as [$price, $lineDiscount]) {
        $subTotal += $price;
        if ($lineDiscount > 0) {
            $discount += $lineDiscount;
        }
    }

    return $subTotal + ($subTotal * $tax / 100) - $discount;
}

/*
|--------------------------------------------------------------------------
| lineTotal / unitPrice / unitIsExact
|--------------------------------------------------------------------------
*/

test('lineTotal of a plain line is its stored price', function () {
    expect(OrderTotals::lineTotal(orderTotalsLine(2, 50000)))->toBe(50000.0);
    expect(OrderTotals::lineTotal(orderTotalsLine(1, 12345.678)))->toBe(12345.68);
});

test('lineTotal of a line without a price is zero', function () {
    expect(OrderTotals::lineTotal(orderTotalsLine(2, null)))->toBe(0.0);
});

test('lineTotal of a price-unit line is the unit price times qty and ignores the stored price', function () {
    $line = orderTotalsLine(3, 1.0, null, 1500);

    expect(OrderTotals::lineTotal($line))->toBe(4500.0);
});

test('lineTotal of a price-unit line with a fractional qty is rounded to two decimals', function () {
    $line = orderTotalsLine(0.5, null, null, 3333.33);

    expect(OrderTotals::lineTotal($line))->toBe(1666.67);
});

test('works for cart items too', function () {
    $cart = new CartItem;
    $cart->forceFill(['qty' => 2, 'price' => 30000, 'discount_price' => 1000, 'price_unit_id' => null]);

    expect(OrderTotals::lineTotal($cart))->toBe(30000.0);
    expect(OrderTotals::unitPrice($cart))->toBe(15000.0);

    $totals = OrderTotals::compute(collect([$cart]), 0.0);
    expect($totals['subtotal'])->toBe(30000.0);
    expect($totals['line_discount'])->toBe(1000.0);
    expect($totals['amount_due'])->toBe(29000.0);
});

test('unitPrice divides the line total by qty', function () {
    expect(OrderTotals::unitPrice(orderTotalsLine(2, 50000)))->toBe(25000.0);
    expect(OrderTotals::unitPrice(orderTotalsLine(3, 10000)))->toBe(3333.33);
    expect(OrderTotals::unitPrice(orderTotalsLine(0.5, 1250)))->toBe(2500.0);
});

test('unitPrice of a line with zero qty is the line total itself', function () {
    expect(OrderTotals::unitPrice(orderTotalsLine(0, 9000)))->toBe(9000.0);
    expect(OrderTotals::unitPrice(orderTotalsLine(0, null)))->toBe(0.0);
});

test('unitIsExact is true when unit x qty gives the total back', function () {
    expect(OrderTotals::unitIsExact(orderTotalsLine(2, 50000)))->toBeTrue();
    expect(OrderTotals::unitIsExact(orderTotalsLine(0.5, 1250)))->toBeTrue();
    expect(OrderTotals::unitIsExact(orderTotalsLine(1, 3333.33)))->toBeTrue();
});

test('unitIsExact is false when the unit price had to be rounded (the UI prefixes it with a tilde)', function () {
    // 10.000 / 3 = 3.333,33 and 3 x 3.333,33 = 9.999,99
    expect(OrderTotals::unitIsExact(orderTotalsLine(3, 10000)))->toBeFalse();
    expect(OrderTotals::unitIsExact(orderTotalsLine(7, 100)))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| compute()
|--------------------------------------------------------------------------
*/

test('compute of no lines is all zero', function () {
    $totals = OrderTotals::compute(collect(), 11.0);

    expect($totals)->toBe([
        'subtotal' => 0.0,
        'line_discount' => 0.0,
        'tax_price' => 0.0,
        'header_discount' => 0.0,
        'total_price' => 0.0,
        'amount_due' => 0.0,
        'qty' => 0.0,
        'count' => 0,
    ]);
});

test('compute without tax or discount is the sum of the lines', function () {
    $totals = OrderTotals::compute(collect([
        orderTotalsLine(2, 50000),
        orderTotalsLine(1, 15000),
    ]), 0.0);

    expect($totals['subtotal'])->toBe(65000.0);
    expect($totals['tax_price'])->toBe(0.0);
    expect($totals['total_price'])->toBe(65000.0);
    expect($totals['amount_due'])->toBe(65000.0);
    expect($totals['qty'])->toBe(3.0);
    expect($totals['count'])->toBe(2);
});

test('compute mixes plain and price-unit lines', function () {
    $totals = OrderTotals::compute(collect([
        orderTotalsLine(2, 50000),
        orderTotalsLine(3, 1.0, null, 1500),
    ]), 0.0);

    expect($totals['subtotal'])->toBe(54500.0);
});

test('tax is taken from the subtotal before any discount', function () {
    $totals = OrderTotals::compute(collect([orderTotalsLine(1, 100000, 10000)]), 10.0, 5000.0);

    expect($totals['subtotal'])->toBe(100000.0);
    expect($totals['tax_price'])->toBe(10000.0);
    expect($totals['total_price'])->toBe(110000.0);
    expect($totals['line_discount'])->toBe(10000.0);
    expect($totals['header_discount'])->toBe(5000.0);
    // total_price (stored in sellings) is before discounts; amount_due is what the customer pays.
    expect($totals['amount_due'])->toBe(95000.0);
});

test('tax of 11 percent on an odd subtotal is rounded to two decimals', function () {
    $totals = OrderTotals::compute(collect([
        orderTotalsLine(1, 10001),
        orderTotalsLine(1, 5555),
        orderTotalsLine(1, 7),
    ]), 11.0);

    expect($totals['subtotal'])->toBe(15563.0);
    expect($totals['tax_price'])->toBe(1711.93);
    expect($totals['total_price'])->toBe(17274.93);
    expect($totals['amount_due'])->toBe(17274.93);
});

test('ten odd subtotals at 11 percent all equal the old cashier formula to the cent', function (float $subtotal) {
    $totals = OrderTotals::compute(collect([orderTotalsLine(1, $subtotal)]), 11.0);

    $tax = Money::round($subtotal * 11 / 100);
    expect($totals['tax_price'])->toBe($tax);
    expect($totals['total_price'])->toBe(Money::round($subtotal + $tax));
    expect($totals['amount_due'])->toBe(Money::round(orderTotalsOldFormula([[$subtotal, 0.0]], 11.0)));

    // Paying exactly the amount due is never rejected by the comparison.
    expect(Money::gte($totals['amount_due'], $totals['amount_due']))->toBeTrue();
})->with([1, 3, 7, 99, 555, 5555, 12345, 33333, 100001, 2999999]);

test('line discounts are summed and header discount is subtracted', function () {
    $totals = OrderTotals::compute(collect([
        orderTotalsLine(1, 20000, 2000),
        orderTotalsLine(2, 30000, 3000),
        orderTotalsLine(1, 10000, null),
    ]), 0.0, 1500.0);

    expect($totals['subtotal'])->toBe(60000.0);
    expect($totals['line_discount'])->toBe(5000.0);
    expect($totals['header_discount'])->toBe(1500.0);
    expect($totals['amount_due'])->toBe(53500.0);
});

test('zero and negative line discounts are ignored', function () {
    $totals = OrderTotals::compute(collect([
        orderTotalsLine(1, 20000, -500),
        orderTotalsLine(1, 20000, 0),
    ]), 0.0);

    expect($totals['line_discount'])->toBe(0.0);
    expect($totals['amount_due'])->toBe(40000.0);
});

test('a negative header discount counts as zero', function () {
    $totals = OrderTotals::compute(collect([orderTotalsLine(1, 20000)]), 0.0, -3000.0);

    expect($totals['header_discount'])->toBe(0.0);
    expect($totals['amount_due'])->toBe(20000.0);
});

test('amount due never goes below zero', function () {
    $totals = OrderTotals::compute(collect([orderTotalsLine(1, 10000, 8000)]), 0.0, 9000.0);

    expect($totals['amount_due'])->toBe(0.0);
    expect($totals['total_price'])->toBe(10000.0);
});

test('compute equals the old cashier math for ordinary orders', function () {
    $rows = [[25000.0, 0.0], [48000.0, 3000.0], [15500.0, 500.0], [9000.0, 0.0]];
    $lines = collect(array_map(
        fn (array $row, int $i) => orderTotalsLine($i + 1, $row[0], $row[1]),
        $rows,
        array_keys($rows),
    ));

    foreach ([0.0, 10.0, 11.0, 12.5] as $tax) {
        $totals = OrderTotals::compute($lines, $tax);

        expect($totals['amount_due'])->toBe(Money::round(orderTotalsOldFormula($rows, $tax)));
    }
});

test('combination of thirds: unit prices that cannot be divided exactly keep the line total authoritative', function () {
    // A third of 10.000 sold three ways: as 3 pieces, as 1 piece at the rounded third, as 0,333 of a kilo.
    $lines = collect([
        orderTotalsLine(3, 10000),
        orderTotalsLine(1, 3333.33),
        orderTotalsLine(0.333, 1110),
    ]);

    expect(OrderTotals::unitIsExact($lines[0]))->toBeFalse();
    expect(OrderTotals::unitPrice($lines[0]))->toBe(3333.33);

    $totals = OrderTotals::compute($lines, 11.0);

    expect($totals['subtotal'])->toBe(14443.33);
    expect($totals['tax_price'])->toBe(1588.77);
    expect($totals['total_price'])->toBe(16032.1);
    expect($totals['amount_due'])->toBe(16032.1);
    expect($totals['qty'])->toBe(4.333);
    expect($totals['count'])->toBe(3);
});

test('a sum of thirds does not drift: three lines of 3.333,33 are 9.999,99, never 10.000', function () {
    $lines = collect([
        orderTotalsLine(1, 3333.33),
        orderTotalsLine(1, 3333.33),
        orderTotalsLine(1, 3333.33),
    ]);

    $totals = OrderTotals::compute($lines, 0.0);

    expect($totals['subtotal'])->toBe(9999.99);
    expect($totals['amount_due'])->toBe(9999.99);
});

test('qty is summed at three decimals', function () {
    $totals = OrderTotals::compute(collect([
        orderTotalsLine(0.1, 100),
        orderTotalsLine(0.2, 200),
        orderTotalsLine(0.0004, 0),
    ]), 0.0);

    expect($totals['qty'])->toBe(0.3);
});

test('compute accepts any collection of lines', function () {
    $lines = new Collection([orderTotalsLine(1, 100)]);

    expect(OrderTotals::compute($lines, 0.0)['subtotal'])->toBe(100.0);
});
