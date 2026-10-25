<?php

use App\Models\Tenants\Category;
use App\Models\Tenants\PaymentMethod;
use App\Models\Tenants\Product;
use App\Models\Tenants\Selling;
use App\Models\Tenants\SellingDetail;
use App\Services\Tenants\SalesTrendService;
use App\Support\SalesPeriod;
use Illuminate\Support\Carbon;
use Tests\RefreshDatabaseWithTenant;

uses(RefreshDatabaseWithTenant::class);

/*
|--------------------------------------------------------------------------
| The numbers behind the dashboard chart
|--------------------------------------------------------------------------
|
| "Now" is Sunday 2026-10-25 09:00 WIB (02:00 UTC). Sales are stored in UTC and counted on the WIB day.
*/

beforeEach(function () {
    config(['pos.business_timezone' => 'Asia/Jakarta']);
    $this->now = Carbon::parse('2026-10-25 02:00:00', 'UTC');
});

/**
 * A finished sale. Defaults to 100,000 of goods (cost 60,000), no tax and no discount.
 *
 * @param  array<string, mixed>  $attributes
 */
function trendSale(string $utcDate, array $attributes = []): Selling
{
    return Selling::create(array_merge([
        'payment_method_id' => PaymentMethod::first()->id,
        'total_price' => 100000,
        'total_cost' => 60000,
        'payed_money' => 100000,
        'money_changes' => 0,
        'total_qty' => 1,
        'date' => $utcDate,
    ], $attributes));
}

function trend(SalesPeriod $period): array
{
    return app(SalesTrendService::class)->trend($period, test()->now);
}

test('every period has one zero point per day or month even without any sale', function (SalesPeriod $period, int $points) {
    $trend = trend($period);

    expect($trend['labels'])->toHaveCount($points)
        ->and($trend['revenue'])->toBe(array_fill(0, $points, 0.0))
        ->and($trend['profit'])->toBe(array_fill(0, $points, 0.0))
        ->and($trend['transactions'])->toBe(array_fill(0, $points, 0))
        ->and($trend['totals'])->toBe(['revenue' => 0.0, 'profit' => 0.0, 'transactions' => 0, 'average' => 0.0])
        ->and($trend['previous'])->toBe(['revenue' => 0.0, 'profit' => 0.0, 'transactions' => 0, 'average' => 0.0]);
})->with([
    'week' => [SalesPeriod::Week, 7],
    'two weeks' => [SalesPeriod::TwoWeeks, 14],
    'month' => [SalesPeriod::Month, 30],
    'year' => [SalesPeriod::Year, 12],
]);

test('the week ends with today and starts six days earlier', function () {
    app()->setLocale('en');
    Carbon::setLocale('en');

    expect(trend(SalesPeriod::Week)['labels'])->toBe([
        'Mon, 19 Oct', 'Tue, 20 Oct', 'Wed, 21 Oct', 'Thu, 22 Oct', 'Fri, 23 Oct', 'Sat, 24 Oct', 'Sun, 25 Oct',
    ]);
});

test('the year is twelve months ending with this month', function () {
    Carbon::setLocale('en');
    $labels = trend(SalesPeriod::Year)['labels'];

    expect($labels[0])->toBe('Nov 2025')
        ->and($labels[11])->toBe('Oct 2026');
});

test('a sale lands on its WIB day: 23:59:59 WIB is still yesterday, 00:00:00 WIB is today', function () {
    trendSale('2026-10-24 16:59:59'); // 23:59:59 WIB on the 24th
    trendSale('2026-10-24 17:00:00'); // 00:00:00 WIB on the 25th
    trendSale('2026-10-24 17:00:00');

    $trend = trend(SalesPeriod::Week);

    expect($trend['transactions'])->toBe([0, 0, 0, 0, 0, 1, 2]);
});

test('the day boundary follows the configured business timezone', function () {
    config(['pos.business_timezone' => 'Asia/Jayapura']); // UTC+9: 15:00 UTC is already the next day
    trendSale('2026-10-24 14:59:59');
    trendSale('2026-10-24 15:00:00');

    $trend = app(SalesTrendService::class)->trend(SalesPeriod::Week, $this->now);

    expect($trend['transactions'])->toBe([0, 0, 0, 0, 0, 1, 1]);
});

test('revenue is the price without tax and discounts, profit is revenue minus cost', function () {
    trendSale('2026-10-25 01:00:00', [
        'total_price' => 110000,
        'tax_price' => 10000,
        'total_discount_per_item' => 5000,
        'discount_price' => 5000,
        'total_cost' => 60000,
    ]);

    $trend = trend(SalesPeriod::Week);

    expect($trend['revenue'][6])->toBe(90000.0)
        ->and($trend['profit'][6])->toBe(30000.0)
        ->and($trend['totals'])->toBe(['revenue' => 90000.0, 'profit' => 30000.0, 'transactions' => 1, 'average' => 90000.0]);
});

test('a sale made at a loss gives a negative profit', function () {
    trendSale('2026-10-25 01:00:00', ['total_price' => 50000, 'total_cost' => 80000]);

    expect(trend(SalesPeriod::Week)['profit'][6])->toBe(-30000.0);
});

test('unpaid sales, sales after now and sales before the previous period are left out', function () {
    trendSale('2026-10-25 01:00:00', ['is_paid' => false]);
    trendSale('2026-10-25 17:00:00'); // tomorrow, 00:00 WIB on the 26th
    trendSale('2026-10-11 16:59:59'); // 23:59:59 WIB on the 11th, one second before the previous week starts
    trendSale('2026-10-25 01:00:00'); // counted

    $trend = trend(SalesPeriod::Week);

    expect($trend['totals']['transactions'])->toBe(1)
        ->and($trend['previous']['transactions'])->toBe(0);
});

test('the period right before is summed for the comparison', function () {
    trendSale('2026-10-11 17:00:00'); // 00:00 WIB on the 12th: first moment of the previous week
    trendSale('2026-10-18 16:59:59'); // 23:59:59 WIB on the 18th: last moment of the previous week
    trendSale('2026-10-18 17:00:00'); // 00:00 WIB on the 19th: first day of this week
    trendSale('2026-10-18 17:00:00');
    trendSale('2026-10-18 17:00:00', ['total_price' => 40000, 'total_cost' => 10000]);

    $trend = trend(SalesPeriod::Week);

    expect($trend['previous']['transactions'])->toBe(2)
        ->and($trend['previous']['revenue'])->toBe(200000.0)
        ->and($trend['totals']['transactions'])->toBe(3)
        ->and($trend['totals']['revenue'])->toBe(240000.0)
        ->and($trend['totals']['average'])->toBe(80000.0)
        ->and($trend['totals']['profit'])->toBe(110000.0);
});

test('the year groups by WIB month', function () {
    trendSale('2026-10-31 16:59:59'); // still October in WIB
    trendSale('2026-10-31 17:00:00'); // 1 November 00:00 WIB: next month, not in the window
    trendSale('2025-11-30 17:00:00'); // 1 December 2025 WIB, the second point

    $trend = trend(SalesPeriod::Year);

    expect($trend['transactions'][11])->toBe(1)
        ->and($trend['transactions'][1])->toBe(1)
        ->and($trend['totals']['transactions'])->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Best selling products
|--------------------------------------------------------------------------
*/

function trendLine(Selling $selling, Product $product, int $qty): void
{
    SellingDetail::create([
        'selling_id' => $selling->id,
        'product_id' => $product->id,
        'qty' => $qty,
        'price' => $qty * 10000,
        'cost' => 0,
        'discount_price' => 0,
    ]);
}

test('the best sellers add up the quantity per product inside the period and skip unpaid sales', function () {
    $category = Category::first();
    [$tea, $coffee, $cake, $old] = array_map(
        fn (string $name): Product => Product::factory()->create(['name' => $name, 'category_id' => $category->id]),
        ['Tea', 'Coffee', 'Cake', 'Old stock'],
    );

    $first = trendSale('2026-10-25 01:00:00');
    $second = trendSale('2026-10-23 05:00:00');
    $unpaid = trendSale('2026-10-24 05:00:00', ['is_paid' => false]);
    $outside = trendSale('2026-10-10 05:00:00'); // before the 7 day window

    trendLine($first, $tea, 2);
    trendLine($first, $coffee, 1);
    trendLine($second, $tea, 3);
    trendLine($second, $cake, 4);
    trendLine($unpaid, $cake, 100);
    trendLine($outside, $old, 50);

    $rows = app(SalesTrendService::class)
        ->bestSellingQuery(SalesPeriod::Week, $this->now)
        ->limit(5)
        ->get();

    expect($rows->map(fn ($row) => [$row->product_name, (float) $row->total_qty])->all())
        ->toBe([['Tea', 5.0], ['Cake', 4.0], ['Coffee', 1.0]])
        // The key of a row is the product, so a table widget can tell the rows apart.
        ->and($rows->pluck('id')->all())->toBe([$tea->id, $cake->id, $coffee->id]);
});

test('a longer period reaches further back for the best sellers', function () {
    $product = Product::factory()->create(['name' => 'Tea', 'category_id' => Category::first()->id]);
    trendLine(trendSale('2026-10-10 05:00:00'), $product, 7);

    $week = app(SalesTrendService::class)->bestSellingQuery(SalesPeriod::Week, $this->now)->get();
    $month = app(SalesTrendService::class)->bestSellingQuery(SalesPeriod::Month, $this->now)->get();

    expect($week)->toHaveCount(0)
        ->and($month)->toHaveCount(1);
});
