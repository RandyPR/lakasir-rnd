<?php

use App\Models\Tenants\Member;
use App\Models\Tenants\PaymentMethod;
use App\Models\Tenants\Product;
use App\Models\Tenants\Receivable;
use App\Models\Tenants\ReceivablePayment;
use App\Models\Tenants\Selling;
use App\Models\Tenants\Setting;
use App\Models\Tenants\User;
use App\Services\Tenants\NumberingLock;
use App\Services\Tenants\PaymentPlanner;
use App\Services\Tenants\SellingService;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;

uses(RefreshDatabaseWithTenant::class);

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('tenant'));
    Carbon::setTestNow(Carbon::parse('2026-10-15 05:00:00', 'UTC'));
    Setting::set('selling_method', 'fifo');
    Cache::clear();
    NumberingLock::flush();
    actingAs(User::first());
    $this->member = Member::factory()->create();
    $this->product = Product::factory()->create([
        'name' => 'Produk Piutang',
        'initial_price' => 10000,
        'selling_price' => 25000,
        'stock' => 50,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
    NumberingLock::flush();
});

function receivableMethod(bool $isCash): PaymentMethod
{
    return PaymentMethod::create([
        'name' => $isCash ? 'Kredit Tunai' : 'Kredit',
        'icon' => 'assets/images/payment-methods/cash.png',
        'is_cash' => $isCash,
        'is_credit' => true,
        'is_wallet' => false,
        'is_debit' => false,
    ]);
}

/**
 * Sells on credit through the service, the way the cashier does: the cart supplies price (line total) and
 * discount per line, the planner decides the down payment.
 *
 * @param  array<string, mixed>  $extra
 */
function receivableSale(object $test, PaymentMethod $method, float $downPayment, array $extra = [], int $qty = 2, float $lineDiscount = 0.0): Selling
{
    $service = new SellingService;
    $linePrice = $test->product->selling_price * $qty;
    $data = array_merge([
        'friend_price' => false,
        'member_id' => $test->member->id,
        'payment_method_id' => $method->id,
        'due_date' => '2026-11-15',
        'products' => [[
            'product_id' => $test->product->id,
            'qty' => $qty,
            'price' => $linePrice,
            'discount_price' => $lineDiscount,
        ]],
    ], $extra);

    $subtotal = $linePrice;
    $tax = (float) ($data['tax'] ?? 0);
    $amountDue = max(0, round($subtotal + $subtotal * $tax / 100 - $lineDiscount - (float) ($data['discount_price'] ?? 0), 2));
    $plan = PaymentPlanner::plan($method, $amountDue, [
        'payed_money' => $downPayment,
        'member_id' => $data['member_id'],
        'due_date' => $data['due_date'],
    ]);
    $data['payed_money'] = $plan['payed_money'];

    return $service->create(array_merge($data, $service->mapProductRequest($data)));
}

test('the receivable is the amount after the header and line discounts', function (bool $isCash) {
    $sale = receivableSale($this, receivableMethod($isCash), 0, ['discount_price' => 5000], qty: 2, lineDiscount: 1000);

    $receivable = Receivable::where('selling_id', $sale->id)->first();

    expect($receivable)->not->toBeNull();
    expect((float) $sale->total_price)->toBe(50000.0);
    expect((float) $receivable->total_receivable)->toBe(44000.0);
    expect((float) $receivable->rest_receivable)->toBe(44000.0);
})->with([
    'credit method that is not cash' => [false],
    'credit method that is also flagged cash' => [true],
]);

test('the down payment reduces the rest and is recorded as a payment', function (bool $isCash) {
    $sale = receivableSale($this, receivableMethod($isCash), 10000, ['discount_price' => 5000], qty: 2, lineDiscount: 1000);

    $receivable = Receivable::where('selling_id', $sale->id)->first();
    $payment = ReceivablePayment::where('receivable_id', $receivable->id)->first();

    expect((float) $receivable->total_receivable)->toBe(44000.0);
    expect((float) $receivable->rest_receivable)->toBe(34000.0);
    expect(ReceivablePayment::where('receivable_id', $receivable->id)->count())->toBe(1);
    expect((float) $payment->amount)->toBe(10000.0);
    expect((float) $payment->last_receivable)->toBe(34000.0);
    expect($payment->payment_method_id)->toBe($sale->payment_method_id);
    expect((float) $sale->payed_money)->toBe(10000.0);
    expect((float) $sale->money_changes)->toBe(0.0);
})->with([
    'credit method that is not cash' => [false],
    'credit method that is also flagged cash' => [true],
]);

test('without a down payment there is no payment row', function () {
    $sale = receivableSale($this, receivableMethod(false), 0);

    $receivable = Receivable::where('selling_id', $sale->id)->first();

    expect((float) $receivable->rest_receivable)->toBe(50000.0);
    expect(ReceivablePayment::where('receivable_id', $receivable->id)->count())->toBe(0);
});

test('tax is part of what the customer owes, discounts come off after it', function () {
    $sale = receivableSale($this, receivableMethod(false), 20000, ['tax' => 10, 'discount_price' => 2500], qty: 2, lineDiscount: 500);

    $receivable = Receivable::where('selling_id', $sale->id)->first();

    // 50.000 + 10% tax = 55.000, minus 2.500 header discount, minus 500 line discount = 52.000.
    expect((float) $sale->total_price)->toBe(55000.0);
    expect((float) $receivable->total_receivable)->toBe(52000.0);
    expect((float) $receivable->rest_receivable)->toBe(32000.0);
});

test('a down payment that equals the amount due leaves nothing to pay', function () {
    $sale = receivableSale($this, receivableMethod(false), 1_000_000, ['discount_price' => 5000]);

    $receivable = Receivable::where('selling_id', $sale->id)->first();

    // The planner clamps the down payment to the amount due (45.000).
    expect((float) $sale->payed_money)->toBe(45000.0);
    expect((float) $receivable->total_receivable)->toBe(45000.0);
    expect((float) $receivable->rest_receivable)->toBe(0.0);
});

test('receivable amounts are rounded to two decimals', function () {
    $sale = receivableSale($this, receivableMethod(false), 0, ['discount_price' => 0.333], qty: 1, lineDiscount: 0.004);

    $receivable = Receivable::where('selling_id', $sale->id)->first();

    expect((float) $receivable->total_receivable)->toBe(24999.66);
    expect((float) $receivable->rest_receivable)->toBe(24999.66);
});

test('the receivable keeps the due date, the member and its items', function () {
    $sale = receivableSale($this, receivableMethod(false), 0, qty: 2);

    $receivable = Receivable::where('selling_id', $sale->id)->first();

    expect($receivable->member_id)->toBe($this->member->id);
    expect(Carbon::parse($receivable->due_date)->toDateString())->toBe('2026-11-15');
    expect($receivable->receivableItems)->toHaveCount(1);
    expect((float) $receivable->receivableItems->first()->subtotal)->toBe(50000.0);
    expect((float) $receivable->receivableItems->first()->price)->toBe(25000.0);
});

test('a cash sale creates no receivable', function () {
    $service = new SellingService;
    $data = ['payed_money' => 25000, 'friend_price' => false, 'products' => [['product_id' => $this->product->id, 'qty' => 1]]];

    $sale = $service->create(array_merge($data, $service->mapProductRequest($data)));

    expect(Receivable::where('selling_id', $sale->id)->exists())->toBeFalse();
});
