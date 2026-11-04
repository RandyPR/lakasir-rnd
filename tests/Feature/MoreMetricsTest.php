<?php

use App\Filament\Tenant\Pages\Dashboard;
use App\Filament\Tenant\Resources\SellingResource\Pages\ListSellings;
use App\Filament\Tenant\Widgets\BusyHours;
use App\Filament\Tenant\Widgets\ProfitPerProduct;
use App\Models\Tenants\Category;
use App\Models\Tenants\PaymentMethod;
use App\Models\Tenants\Product;
use App\Models\Tenants\Selling;
use App\Models\Tenants\SellingDetail;
use App\Models\Tenants\User;
use App\Services\Tenants\SalesSummaryService;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;

uses(RefreshDatabaseWithTenant::class);

/*
|--------------------------------------------------------------------------
| Busy hours, profit per product, profit per sale
|--------------------------------------------------------------------------
|
| "Now" is Sunday 2026-10-25 17:00 WIB (10:00 UTC).
*/

beforeEach(function () {
    config(['pos.business_timezone' => 'Asia/Jakarta']);
    app()->setLocale('en');
    Carbon::setLocale('en');
    Carbon::setTestNow(Carbon::parse('2026-10-25 10:00:00', 'UTC'));
    Filament::setCurrentPanel(Filament::getPanel('tenant'));
    actingAs(User::first());
});

afterEach(function () {
    Carbon::setTestNow();
});

function metricSale(string $utcDate, array $attributes = []): Selling
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

function metricLine(Selling $sale, Product $product, float $qty, float $price, float $cost, float $discount = 0): void
{
    SellingDetail::create(['selling_id' => $sale->id, 'product_id' => $product->id, 'qty' => $qty, 'price' => $price, 'cost' => $cost, 'discount_price' => $discount]);
}

function metricCall(Testable $component, string $method): mixed
{
    return (fn () => $this->{$method}())->call($component->instance());
}

test('sales are counted per hour of the shop day, not of UTC', function () {
    metricSale('2026-10-25 12:30:00');                                    // 19.30 WIB
    metricSale('2026-10-25 12:59:59', ['total_price' => 50000]);          // 19.59 WIB
    metricSale('2026-10-25 13:00:00');                                    // 20.00 WIB
    metricSale('2026-10-24 17:10:00');                                    // 00.10 WIB
    metricSale('2026-10-25 12:30:00', ['is_paid' => false]);              // not paid
    metricSale('2026-10-25 12:30:00')->delete();                          // in the trash

    $hours = app(SalesSummaryService::class)->busyHours('2026-10-24 00:00:00', '2026-10-26 00:00:00');

    expect($hours)->toHaveCount(24)
        ->and($hours[19]['transactions'])->toBe(2)
        ->and($hours[19]['omzet'])->toBe(150000.0)
        ->and($hours[20]['transactions'])->toBe(1)
        ->and($hours[0]['transactions'])->toBe(1)
        ->and(array_sum(array_column($hours, 'transactions')))->toBe(4);
});

test('the busy hours chart names the busiest hour, keeps to the hours around the sales and follows the period', function () {
    foreach (range(1, 3) as $i) {
        metricSale('2026-10-25 12:'.(10 + $i).':00');                     // 19.xx
    }
    metricSale('2026-10-25 11:00:00');                                    // 18.00
    metricSale('2026-09-01 12:00:00');                                    // far back: only in the year

    $week = Livewire::test(BusyHours::class, ['filters' => ['period' => 'week']]);
    $data = metricCall($week, 'getData');

    expect((string) metricCall($week, 'getDescription'))->toBe('Busiest 19.00–20.00, 75% of sales · Last 7 days')
        ->and($data['labels'])->toContain('18', '19')
        ->and(count($data['labels']))->toBeGreaterThanOrEqual(8)
        ->and($data['datasets'][0]['data'][array_search('19', $data['labels'], true)])->toBe(3);

    $year = Livewire::test(BusyHours::class, ['filters' => ['period' => 'year']]);
    expect(array_sum(metricCall($year, 'getData')['datasets'][0]['data']))->toBe(5);
});

test('the busy hours chart is empty without sales and shows no money to someone who may not see it', function () {
    $empty = Livewire::test(BusyHours::class, ['filters' => ['period' => 'week']]);
    expect(metricCall($empty, 'getData'))->toBe(['labels' => [], 'datasets' => [['data' => []]]])
        ->and((string) metricCall($empty, 'getDescription'))->toBe('Last 7 days');

    expect(BusyHours::canView())->toBeTrue();
});

test('profit per product adds up the lines, with line discounts, and leaves out trash and unpaid sales', function () {
    $category = Category::first();
    $soto = Product::factory()->create(['name' => 'Soto', 'category_id' => $category->id]);
    $tea = Product::factory()->create(['name' => 'Teh', 'category_id' => $category->id]);
    $loss = Product::factory()->create(['name' => 'Rugi', 'category_id' => $category->id]);
    $gone = Product::factory()->create(['name' => 'Kopi', 'category_id' => $category->id]);

    metricLine(metricSale('2026-10-25 01:00:00'), $soto, 2, 60000, 30000);                    // profit 30000
    metricLine(metricSale('2026-10-25 02:00:00'), $soto, 1, 30000, 15000, 5000);              // 30000-5000-15000 = 10000
    metricLine(metricSale('2026-10-25 03:00:00'), $tea, 10, 60000, 10000);                    // 50000
    metricLine(metricSale('2026-10-25 04:00:00'), $loss, 1, 10000, 12000);                    // -2000
    metricLine(metricSale('2026-10-25 05:00:00', ['is_paid' => false]), $gone, 1, 99000, 0);
    metricLine(tap(metricSale('2026-10-25 06:00:00'))->delete(), $gone, 1, 99000, 0);
    metricLine(metricSale('2026-09-01 06:00:00'), $gone, 1, 99000, 0);                        // before the stretch

    $rows = app(SalesSummaryService::class)->profitPerProductQuery('2026-10-24 17:00:00', '2026-10-25 17:00:00')->get();

    expect($rows->pluck('product_name')->all())->toBe(['Teh', 'Soto', 'Rugi'])
        ->and((float) $rows[0]->profit)->toBe(50000.0)
        ->and((float) $rows[1]->profit)->toBe(40000.0)
        ->and((float) $rows[1]->omzet)->toBe(85000.0)
        ->and((float) $rows[1]->total_qty)->toBe(3.0)
        ->and((float) $rows[2]->profit)->toBe(-2000.0);
});

test('the profit per product table marks thin and negative margins and needs the revenue permission', function () {
    $category = Category::first();
    $rich = Product::factory()->create(['name' => 'Kaya', 'category_id' => $category->id]);
    $thin = Product::factory()->create(['name' => 'Tipis', 'category_id' => $category->id]);
    $loss = Product::factory()->create(['name' => 'Rugi', 'category_id' => $category->id]);

    metricLine(metricSale('2026-10-25 01:00:00'), $rich, 1, 100000, 40000);                   // 60%
    metricLine(metricSale('2026-10-25 02:00:00'), $thin, 1, 100000, 90000);                   // 10%
    metricLine(metricSale('2026-10-25 03:00:00'), $loss, 1, 100000, 120000);                  // -20%

    Livewire::test(ProfitPerProduct::class, ['filters' => ['period' => 'week']])
        ->assertSee('Most profitable products')
        ->assertSee('Kaya')->assertSee('60.0%')
        ->assertSee('Tipis')->assertSee('10.0%')
        ->assertSee('Rugi')->assertSee('-20.0%');

    expect(ProfitPerProduct::canView())->toBeTrue();

    $user = User::create(['email' => 'nomoney'.uniqid().'@mail.com', 'password' => bcrypt('password'), 'is_owner' => false]);
    actingAs($user);
    expect(ProfitPerProduct::canView())->toBeFalse();
});

test('the dashboard lists the new widgets', function () {
    $widgets = (new Dashboard)->getWidgets();

    expect($widgets)->toContain(BusyHours::class, ProfitPerProduct::class);
});

test('the sales list has a profit per sale column that can be sorted', function () {
    $small = metricSale('2026-10-25 01:00:00', ['total_price' => 100000, 'total_cost' => 90000]);                       // 10000
    $big = metricSale('2026-10-25 02:00:00', ['total_price' => 100000, 'total_cost' => 40000, 'discount_price' => 10000]); // 50000
    $loss = metricSale('2026-10-25 03:00:00', ['total_price' => 100000, 'total_cost' => 120000]);                      // -20000

    Livewire::test(ListSellings::class)
        ->assertTableColumnExists('profit')
        ->assertTableColumnStateSet('profit', 10000.0, $small)
        ->assertTableColumnStateSet('profit', 50000.0, $big)
        ->assertTableColumnStateSet('profit', -20000.0, $loss)
        ->sortTable('profit', 'desc')
        ->assertCanSeeTableRecords([$big, $small, $loss], inOrder: true);
});
