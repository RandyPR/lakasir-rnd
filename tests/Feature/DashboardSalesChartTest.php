<?php

use App\Filament\Tenant\Pages\Dashboard;
use App\Filament\Tenant\Widgets\BestSellingProduct;
use App\Filament\Tenant\Widgets\SalesChart;
use App\Filament\Tenant\Widgets\SalesPeriodOverview;
use App\Models\Tenants\Category;
use App\Models\Tenants\PaymentMethod;
use App\Models\Tenants\Product;
use App\Models\Tenants\Selling;
use App\Models\Tenants\SellingDetail;
use App\Models\Tenants\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;

uses(RefreshDatabaseWithTenant::class);

/*
|--------------------------------------------------------------------------
| The dashboard: period filter, sales chart, period totals, best sellers
|--------------------------------------------------------------------------
|
| "Now" is Sunday 2026-10-25 09:00 WIB. The widgets are mounted the way the dashboard page mounts them, with the
| page filters as a property.
*/

beforeEach(function () {
    config(['pos.business_timezone' => 'Asia/Jakarta']);
    app()->setLocale('en');
    Carbon::setLocale('en');
    Carbon::setTestNow(Carbon::parse('2026-10-25 02:00:00', 'UTC'));
    Filament::setCurrentPanel(Filament::getPanel('tenant'));
    actingAs(User::first());
});

afterEach(function () {
    Carbon::setTestNow();
});

/** A non-owner whose role holds exactly these permissions. */
function dashboardUserCan(string ...$permissions): User
{
    $user = User::create([
        'email' => 'dash'.uniqid().'@mail.com',
        'password' => bcrypt('password'),
        'is_owner' => false,
    ]);
    $role = Role::create(['name' => 'dash-'.uniqid(), 'guard_name' => 'web']);
    foreach ($permissions as $name) {
        $role->permissions()->syncWithoutDetaching(Permission::where('name', $name)->get());
    }
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function dashboardSale(string $utcDate, array $attributes = []): Selling
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

/** What a widget hands to the chart or the stat cards, whatever their visibility. */
function widgetCall(Testable $component, string $method): mixed
{
    return (fn () => $this->{$method}())->call($component->instance());
}

test('the chart follows the period filter and falls back to the week for an unknown value', function (?string $period, int $points) {
    dashboardSale('2026-10-25 01:00:00');

    $data = widgetCall(Livewire::test(SalesChart::class, ['filters' => ['period' => $period]]), 'getData');

    expect($data['labels'])->toHaveCount($points)
        ->and($data['datasets'][0]['data'])->toHaveCount($points)
        ->and($data['datasets'][1]['data'])->toHaveCount($points);
})->with([
    'week' => ['week', 7],
    'two weeks' => ['two_weeks', 14],
    'month' => ['month', 30],
    'year' => ['year', 12],
    'unknown' => ['decade', 7],
    'missing' => [null, 7],
]);

test('the chart shows revenue as bars and profit as a line, today last, with the formatted amounts', function () {
    dashboardSale('2026-10-25 01:00:00', ['total_price' => 110000, 'tax_price' => 10000, 'total_cost' => 70000]);

    $data = widgetCall(Livewire::test(SalesChart::class, ['filters' => ['period' => 'week']]), 'getData');
    [$revenue, $profit] = $data['datasets'];

    expect($revenue['type'])->toBe('bar')
        ->and($revenue['label'])->toBe('Net sales')
        ->and($revenue['data'][6])->toBe(100000.0)
        ->and($revenue['counts'])->toBe([0, 0, 0, 0, 0, 0, 1])
        ->and($revenue['formatted'][6])->toContain('100,000')
        ->and($profit['type'])->toBe('line')
        ->and($profit['label'])->toBe('Profit')
        ->and($profit['data'][6])->toBe(30000.0)
        ->and($data['labels'][6])->toBe('Sun, 25 Oct');
});

test('the chart options hold no double quote, because they are printed inside a double quoted attribute', function (string $locale) {
    app()->setLocale($locale);

    $options = (string) widgetCall(Livewire::test(SalesChart::class, ['filters' => ['period' => 'week']]), 'getOptions');

    expect($options)->not->toContain('"')
        ->and($options)->toContain("Intl.NumberFormat('{$locale}'");
})->with(['en', 'id']);

test('the chart is for people who may read the revenue overview, and counts only for those who read the sales overview', function () {
    expect(SalesChart::canView())->toBeTrue(); // the owner

    actingAs(dashboardUserCan('read sales overview'));
    expect(SalesChart::canView())->toBeFalse();

    actingAs(dashboardUserCan('read revenue overview'));
    expect(SalesChart::canView())->toBeTrue();

    dashboardSale('2026-10-25 01:00:00');
    $data = widgetCall(Livewire::test(SalesChart::class, ['filters' => ['period' => 'week']]), 'getData');

    expect($data['datasets'][0]['counts'])->toBeNull();
});

test('the totals compare the period with the one before it', function () {
    dashboardSale('2026-10-25 01:00:00');
    dashboardSale('2026-10-24 01:00:00');
    dashboardSale('2026-10-17 01:00:00', ['total_price' => 50000, 'total_cost' => 0]);

    $stats = widgetCall(Livewire::test(SalesPeriodOverview::class, ['filters' => ['period' => 'week']]), 'getStats');
    $byLabel = collect($stats)->keyBy(fn ($stat) => $stat->getLabel());

    expect($byLabel->keys()->all())->toBe(['Net sales', 'Profit', 'Sales', 'Average per sale'])
        ->and($byLabel['Net sales']->getDescription())->toContain('300% increase compared to the 7 days before')
        ->and($byLabel['Sales']->getDescription())->toContain('100% increase')
        ->and($byLabel['Sales']->getValue())->toBe('2')
        ->and($byLabel['Profit']->getDescription())->toContain('60% increase')
        ->and($byLabel['Average per sale']->getDescription())->toContain('100% increase');
});

test('the number of sales is written the way the shop language writes numbers', function () {
    foreach (range(1, 1200) as $i) {
        $rows[] = [
            'code' => 'DASH-'.$i, 'payment_method_id' => PaymentMethod::first()->id, 'total_price' => 1000, 'total_cost' => 0, 'payed_money' => 1000,
            'money_changes' => 0, 'total_qty' => 1, 'date' => '2026-10-25 01:00:00', 'daily_order_number' => $i,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }
    Selling::insert($rows);

    app()->setLocale('id');
    $stats = widgetCall(Livewire::test(SalesPeriodOverview::class, ['filters' => ['period' => 'week']]), 'getStats');
    $sales = collect($stats)->first(fn ($stat) => $stat->getLabel() === 'Penjualan');

    expect($sales->getValue())->toBe('1.200');
});

test('a period with sales after an empty one says so instead of showing a percentage', function () {
    dashboardSale('2026-10-25 01:00:00');

    $stats = widgetCall(Livewire::test(SalesPeriodOverview::class, ['filters' => ['period' => 'week']]), 'getStats');

    expect($stats[0]->getDescription())->toBe('No sales in the 7 days before');
});

test('without any sale the cards are neutral and the page still renders', function () {
    $component = Livewire::test(SalesPeriodOverview::class, ['filters' => ['period' => 'month']])
        ->assertSee('Last 30 days')
        ->assertSee('No sales in the 30 days before');

    $stats = widgetCall($component, 'getStats');

    expect($stats[0]->getColor())->toBe('gray');
});

test('the cards follow the permissions: money needs the revenue overview, the count the sales overview', function () {
    dashboardSale('2026-10-25 01:00:00');
    $labels = fn () => collect(widgetCall(
        Livewire::test(SalesPeriodOverview::class, ['filters' => ['period' => 'week']]),
        'getStats'
    ))->map(fn ($stat) => $stat->getLabel())->all();

    actingAs(dashboardUserCan('read sales overview'));
    expect($labels())->toBe(['Sales']);

    actingAs(dashboardUserCan('read revenue overview'));
    expect($labels())->toBe(['Net sales', 'Profit', 'Average per sale']);
});

test('the best sellers follow the period', function () {
    $category = Category::first();
    $tea = Product::factory()->create(['name' => 'Teh Botol', 'category_id' => $category->id]);
    $old = Product::factory()->create(['name' => 'Kopi Lama', 'category_id' => $category->id]);

    foreach ([[dashboardSale('2026-10-25 01:00:00'), $tea, 3], [dashboardSale('2026-09-01 01:00:00'), $old, 9]] as [$sale, $product, $qty]) {
        SellingDetail::create([
            'selling_id' => $sale->id,
            'product_id' => $product->id,
            'qty' => $qty,
            'price' => $qty * 10000,
            'cost' => 0,
            'discount_price' => 0,
        ]);
    }

    Livewire::test(BestSellingProduct::class, ['filters' => ['period' => 'week']])
        ->assertSee('Teh Botol')
        ->assertDontSee('Kopi Lama')
        ->assertSee('Last 7 days');

    Livewire::test(BestSellingProduct::class, ['filters' => ['period' => 'month']])
        ->assertSee('Teh Botol')
        ->assertDontSee('Kopi Lama');

    Livewire::test(BestSellingProduct::class, ['filters' => ['period' => 'year']])
        ->assertSee('Kopi Lama');
});

test('the dashboard has a period filter that starts on the week', function () {
    Livewire::test(Dashboard::class)
        ->assertFormSet(['period' => 'week'], 'filtersForm')
        ->fillForm(['period' => 'year'], 'filtersForm')
        ->assertSet('filters.period', 'year');
});

test('the dashboard page opens for the owner', function () {
    actingAs(User::first())->get('/member')->assertOk();
});

test('the dashboard page opens for a user who may not read any overview', function () {
    actingAs(dashboardUserCan('read selling'))->get('/member')->assertOk();
});
