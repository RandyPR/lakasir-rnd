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

test('the period card compares net sales, cost of goods and profit with the period before', function () {
    dashboardSale('2026-10-25 01:00:00');
    dashboardSale('2026-10-24 01:00:00');
    dashboardSale('2026-10-17 01:00:00', ['total_price' => 50000, 'total_cost' => 0]);

    $card = widgetCall(Livewire::test(SalesPeriodOverview::class, ['filters' => ['period' => 'week']]), 'card');

    expect($card['kind'])->toBe('period')
        ->and($card['title'])->toBe('Last 7 days')
        ->and($card['meta'])->toBe('compared to the 7 days before')
        ->and($card['omzet'])->toContain('200,000')
        ->and($card['omzet_delta'])->toBe(['dir' => 'up', 'text' => '300%'])
        ->and($card['hpp'])->toContain('120,000')
        ->and($card['hpp_share'])->toBe('60%')
        ->and($card['laba'])->toContain('80,000')
        ->and($card['laba_delta'])->toBe(['dir' => 'up', 'text' => '60%'])
        ->and($card['margin'])->toBe('40%')
        ->and($card['transactions'])->toBe('2')
        ->and($card['transactions_delta'])->toBe(['dir' => 'up', 'text' => '100%'])
        ->and($card['average'])->toContain('100,000');
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
    $card = widgetCall(Livewire::test(SalesPeriodOverview::class, ['filters' => ['period' => 'week']]), 'card');

    expect($card['transactions'])->toBe('1.200');
});

test('a period with sales after an empty one shows no percentage', function () {
    dashboardSale('2026-10-25 01:00:00');

    $card = widgetCall(Livewire::test(SalesPeriodOverview::class, ['filters' => ['period' => 'week']]), 'card');

    expect($card['omzet_delta']['dir'])->toBe('none')
        ->and($card['laba_delta']['dir'])->toBe('none');
});

test('without any sale the card shows zeros and the page still renders', function () {
    $component = Livewire::test(SalesPeriodOverview::class, ['filters' => ['period' => 'month']])
        ->assertSee('Last 30 days')
        ->assertSee('compared to the 30 days before');

    $card = widgetCall($component, 'card');

    expect($card['margin'])->toBeNull()
        ->and($card['hpp_share'])->toBeNull()
        ->and($card['omzet_delta']['dir'])->toBe('none');
});

test('the card follows the permissions: money needs the revenue overview, the count the sales overview', function () {
    dashboardSale('2026-10-25 01:00:00');
    $flags = fn () => collect(widgetCall(
        Livewire::test(SalesPeriodOverview::class, ['filters' => ['period' => 'week']]),
        'card'
    ))->only(['revenue', 'sales'])->all();

    actingAs(dashboardUserCan('read sales overview'));
    expect($flags())->toBe(['revenue' => false, 'sales' => true]);
    Livewire::test(SalesPeriodOverview::class, ['filters' => ['period' => 'week']])
        ->assertDontSee('Net sales')
        ->assertDontSee('Cost of goods')
        ->assertSee('Transactions');

    actingAs(dashboardUserCan('read revenue overview'));
    expect($flags())->toBe(['revenue' => true, 'sales' => false]);
    Livewire::test(SalesPeriodOverview::class, ['filters' => ['period' => 'week']])
        ->assertSee('Cost of goods')
        ->assertDontSee('Transactions');

    actingAs(dashboardUserCan());
    expect(SalesPeriodOverview::canView())->toBeFalse();
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

// Not through the route: the page layout reads the Vite manifest, which only exists after `npm run build` (CI has none).
test('the dashboard page renders for the owner', function () {
    Livewire::test(Dashboard::class)->assertOk()->assertSee('Period');
});

test('the dashboard page renders for a user who may not read any overview', function () {
    actingAs(dashboardUserCan('read selling'));

    Livewire::test(Dashboard::class)->assertOk()->assertSee('Period');
});
