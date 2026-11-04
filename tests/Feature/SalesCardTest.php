<?php

use App\Filament\Tenant\Resources\SellingResource\Pages\ListSellings;
use App\Filament\Tenant\Resources\SellingResource\Widgets\SellingListOverview;
use App\Filament\Tenant\Resources\SellingResource\Widgets\SellingOverview;
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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;

uses(RefreshDatabaseWithTenant::class);

/*
|--------------------------------------------------------------------------
| The compact card: net sales - cost of goods (HPP) = profit
|--------------------------------------------------------------------------
|
| "Now" is Sunday 2026-10-25 17:00 WIB (10:00 UTC). The business day runs from 2026-10-24 17:00 UTC to
| 2026-10-25 17:00 UTC. The widgets are mounted the way the pages mount them.
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

/** The observer gives every new sale to the logged in user, so another cashier is set afterwards. */
function cardSale(string $utcDate, array $attributes = []): Selling
{
    $cashier = $attributes['user_id'] ?? null;
    unset($attributes['user_id']);

    $sale = Selling::create(array_merge([
        'payment_method_id' => PaymentMethod::first()->id,
        'total_price' => 100000,
        'total_cost' => 60000,
        'payed_money' => 100000,
        'money_changes' => 0,
        'total_qty' => 1,
        'date' => $utcDate,
    ], $attributes));

    if ($cashier) {
        Selling::whereKey($sale->id)->update(['user_id' => $cashier]);
    }

    return $sale->refresh();
}

function cardOf(Testable $component): ?array
{
    return (fn () => $this->card())->call($component->instance());
}

function cardUserCan(string ...$permissions): User
{
    $user = User::create(['email' => 'card'.uniqid().'@mail.com', 'password' => bcrypt('password'), 'is_owner' => false]);
    $role = Role::create(['name' => 'card-'.uniqid(), 'guard_name' => 'web']);
    foreach ($permissions as $name) {
        $role->permissions()->syncWithoutDetaching(Permission::where('name', $name)->get());
    }
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

// ---------------------------------------------------------------------------------------------------------------
// SalesSummaryService
// ---------------------------------------------------------------------------------------------------------------

test('net sales is the price without tax and discounts, HPP is the stored cost, profit is the difference', function () {
    cardSale('2026-10-25 01:00:00', [
        'total_price' => 110000, 'tax_price' => 10000, 'total_discount_per_item' => 5000, 'discount_price' => 5000, 'total_cost' => 60000,
    ]);
    cardSale('2026-10-25 02:00:00', ['total_price' => 40000, 'total_cost' => 30000]);

    $summary = app(SalesSummaryService::class)->summarize('2026-10-24 17:00:00', '2026-10-25 17:00:00');

    expect($summary)->toBe([
        'omzet' => 130000.0,
        'hpp' => 90000.0,
        'laba' => 40000.0,
        'margin' => 30.8,
        'transactions' => 2,
        'discount' => 10000.0,
        'average' => 65000.0,
    ]);
});

test('an empty stretch is all zeros and has no margin', function () {
    $summary = app(SalesSummaryService::class)->summarize('2026-10-24 17:00:00', '2026-10-25 17:00:00');

    expect($summary)->toBe([
        'omzet' => 0.0, 'hpp' => 0.0, 'laba' => 0.0, 'margin' => null, 'transactions' => 0, 'discount' => 0.0, 'average' => 0.0,
    ]);
});

test('the stretch starts at its first second and ends before its last, sales in the trash and unpaid ones do not count', function () {
    cardSale('2026-10-24 17:00:00');                                  // the first second: in
    cardSale('2026-10-25 16:59:59');                                  // the last second: in
    cardSale('2026-10-24 16:59:59');                                  // one second before: out
    cardSale('2026-10-25 17:00:00');                                  // the next stretch: out
    cardSale('2026-10-25 01:00:00', ['is_paid' => false]);            // not paid: out
    cardSale('2026-10-25 02:00:00')->delete();                        // in the trash: out

    $summary = app(SalesSummaryService::class)->summarize('2026-10-24 17:00:00', '2026-10-25 17:00:00');

    expect($summary['transactions'])->toBe(2)
        ->and($summary['omzet'])->toBe(200000.0);
});

test('a cashier can be asked for on its own', function () {
    $other = User::create(['email' => 'other@mail.com', 'password' => bcrypt('password'), 'is_owner' => false]);
    cardSale('2026-10-25 01:00:00', ['user_id' => User::first()->id]);
    cardSale('2026-10-25 02:00:00', ['user_id' => $other->id, 'total_price' => 30000, 'total_cost' => 10000]);

    $service = app(SalesSummaryService::class);

    expect($service->summarize('2026-10-24 17:00:00', '2026-10-25 17:00:00', $other->id)['omzet'])->toBe(30000.0)
        ->and($service->summarize('2026-10-24 17:00:00', '2026-10-25 17:00:00')['omzet'])->toBe(130000.0);
});

test('a stretch that is still running is compared with the same stretch of the one before, not with the whole of it', function () {
    cardSale('2026-10-25 01:00:00');                                  // today, 08:00 WIB
    cardSale('2026-10-24 05:00:00', ['total_price' => 80000]);        // yesterday 12:00 WIB, before the same time: counted
    cardSale('2026-10-24 12:00:00', ['total_price' => 500000]);       // yesterday 19:00 WIB, after the same time: not counted

    $compare = app(SalesSummaryService::class)->compare('2026-10-24 17:00:00', '2026-10-25 17:00:00');

    expect($compare['partial'])->toBeTrue()
        ->and($compare['days'])->toBe(1)
        ->and($compare['current']['omzet'])->toBe(100000.0)
        ->and($compare['previous']['omzet'])->toBe(80000.0);
});

test('a finished stretch is compared with the whole stretch before it', function () {
    cardSale('2026-10-23 01:00:00');                                  // in 22-24 Oct (WIB days)
    cardSale('2026-10-20 01:00:00', ['total_price' => 80000]);        // the 3 days before: 19-21 Oct
    cardSale('2026-10-21 12:00:00', ['total_price' => 20000]);

    $compare = app(SalesSummaryService::class)->compare('2026-10-21 17:00:00', '2026-10-24 17:00:00');

    expect($compare['days'])->toBe(3)
        ->and($compare['partial'])->toBeFalse()
        ->and($compare['previous']['omzet'])->toBe(100000.0);
});

test('products sold without a cost price are named once, and sales in the trash are left out', function () {
    $category = Category::first();
    $free = Product::factory()->create(['name' => 'Es Jeruk', 'category_id' => $category->id]);
    $paid = Product::factory()->create(['name' => 'Soto', 'category_id' => $category->id]);
    $gone = Product::factory()->create(['name' => 'Kopi', 'category_id' => $category->id]);

    foreach ([[cardSale('2026-10-25 01:00:00'), $free, 0], [cardSale('2026-10-25 02:00:00'), $free, 0], [cardSale('2026-10-25 03:00:00'), $paid, 9000]] as [$sale, $product, $cost]) {
        SellingDetail::create(['selling_id' => $sale->id, 'product_id' => $product->id, 'price' => 10000, 'cost' => $cost, 'qty' => 1]);
    }
    $trashed = cardSale('2026-10-25 04:00:00');
    SellingDetail::create(['selling_id' => $trashed->id, 'product_id' => $gone->id, 'price' => 10000, 'cost' => 0, 'qty' => 1]);
    $trashed->delete();

    $result = app(SalesSummaryService::class)->productsWithoutCost('2026-10-24 17:00:00', '2026-10-25 17:00:00');

    expect($result)->toBe(['count' => 1, 'names' => ['Es Jeruk']]);
});

// ---------------------------------------------------------------------------------------------------------------
// The card of today (dashboard)
// ---------------------------------------------------------------------------------------------------------------

test('the card of today adds up the business day and compares it with the same time yesterday', function () {
    cardSale('2026-10-25 01:00:00', ['discount_price' => 5000]);
    cardSale('2026-10-24 05:00:00', ['total_price' => 50000, 'total_cost' => 30000]);

    $card = cardOf(Livewire::test(SellingOverview::class));

    expect($card['kind'])->toBe('today')
        ->and($card['title'])->toBe('Today')
        ->and($card['date'])->toBe('Sun, 25 Oct 2026')
        ->and($card['as_of'])->toBe('as of 17.00')
        ->and($card['vs'])->toBe('compared to the same time yesterday')
        ->and($card['omzet'])->toContain('95,000')
        ->and($card['omzet_delta'])->toBe(['dir' => 'up', 'text' => '90%'])
        ->and($card['hpp'])->toContain('60,000')
        ->and($card['laba'])->toContain('35,000')
        ->and($card['margin'])->toBe('36.8%')
        ->and($card['discount'])->toContain('5,000')
        ->and($card['transactions'])->toBe('1');
});

test('the business day changes at midnight WIB, not at midnight UTC', function () {
    cardSale('2026-10-24 16:59:00');   // 23:59 WIB on the 24th: yesterday
    cardSale('2026-10-24 17:01:00');   // 00:01 WIB on the 25th: today

    $card = cardOf(Livewire::test(SellingOverview::class));

    expect($card['transactions'])->toBe('1');
});

test('the card of today follows the permissions, and the cost warning is for those who see money', function () {
    $product = Product::factory()->create(['name' => 'Es Jeruk', 'category_id' => Category::first()->id]);
    $sale = cardSale('2026-10-25 01:00:00');
    SellingDetail::create(['selling_id' => $sale->id, 'product_id' => $product->id, 'price' => 10000, 'cost' => 0, 'qty' => 1]);

    actingAs(cardUserCan('read sales overview'));
    $card = cardOf(Livewire::test(SellingOverview::class));
    expect($card['revenue'])->toBeFalse()
        ->and($card['sales'])->toBeTrue()
        ->and($card['warning'])->toBeNull();
    Livewire::test(SellingOverview::class)
        ->assertDontSee('Cost of goods')
        ->assertDontSee('Es Jeruk')
        ->assertSee('Transactions');

    actingAs(cardUserCan('read revenue overview'));
    $card = cardOf(Livewire::test(SellingOverview::class));
    expect($card['revenue'])->toBeTrue()
        ->and($card['sales'])->toBeFalse()
        ->and($card['warning'])->toBe(['count' => 1, 'names' => ['Es Jeruk']]);
    Livewire::test(SellingOverview::class)->assertSee('Es Jeruk')->assertDontSee('Transactions');

    actingAs(cardUserCan());
    expect(SellingOverview::canView())->toBeFalse();
});

test('the card is written in the shop language', function () {
    cardSale('2026-10-25 01:00:00');
    cardSale('2026-10-24 01:00:00');                                  // yesterday, so there is something to compare with
    app()->setLocale('id');
    Carbon::setLocale('id');

    Livewire::test(SellingOverview::class)
        ->assertSee('Hari ini')
        ->assertSee('Omzet')
        ->assertSee('HPP')
        ->assertSee('Laba')
        ->assertSee('setelah HPP')
        ->assertSee('dibanding jam yang sama kemarin')
        ->assertSee('Transaksi');
});

// ---------------------------------------------------------------------------------------------------------------
// The card of the sales list
// ---------------------------------------------------------------------------------------------------------------

test('the card of the sales list shows today until a date range is chosen', function () {
    cardSale('2026-10-25 01:00:00');
    cardSale('2026-10-24 05:00:00');

    $card = cardOf(Livewire::test(SellingListOverview::class));

    expect($card['title'])->toBe('Today')
        ->and($card['transactions'])->toBe('1')
        ->and($card['meta'])->toBe('Follows the date and cashier filters of the table');
});

test('the card of the sales list follows the date filter of the table, whole days in the shop timezone', function () {
    cardSale('2026-10-25 01:00:00');                                  // 25 Oct
    cardSale('2026-10-24 05:00:00', ['total_price' => 80000]);        // 24 Oct
    cardSale('2026-10-23 05:00:00', ['total_price' => 20000]);        // 23 Oct
    cardSale('2026-10-22 16:59:00');                                  // 22 Oct 23:59 WIB: out

    $card = cardOf(Livewire::test(SellingListOverview::class, [
        'tableFilters' => ['date' => ['start_date' => '2026-10-23', 'end_date' => '2026-10-24']],
    ]));

    expect($card['title'])->toBe('23 Oct – 24 Oct 2026')
        ->and($card['transactions'])->toBe('2')
        ->and($card['omzet'])->toContain('100,000')
        ->and($card['date'])->toBe('')
        ->and($card['as_of'])->toBeNull()
        ->and($card['vs'])->toBe('compared to the 2 days before');

    $one = cardOf(Livewire::test(SellingListOverview::class, [
        'tableFilters' => ['date' => ['start_date' => '2026-10-24', 'end_date' => '2026-10-24']],
    ]));

    expect($one['title'])->toBe('Sat, 24 Oct 2026')
        ->and($one['vs'])->toBe('compared to the day before')
        ->and($one['omzet'])->toContain('80,000');
});

test('the card of the sales list follows the cashier filter', function () {
    $other = User::create(['email' => 'other@mail.com', 'password' => bcrypt('password'), 'is_owner' => false]);
    cardSale('2026-10-25 01:00:00', ['user_id' => User::first()->id]);
    cardSale('2026-10-25 02:00:00', ['user_id' => $other->id, 'total_price' => 30000, 'total_cost' => 10000]);

    $card = cardOf(Livewire::test(SellingListOverview::class, ['tableFilters' => ['user_id' => ['value' => (string) $other->id]]]));

    expect($card['omzet'])->toContain('30,000')
        ->and($card['transactions'])->toBe('1');
});

test('half a date range or an unreadable one falls back to today', function (array $filter) {
    cardSale('2026-10-25 01:00:00');
    cardSale('2026-10-24 05:00:00');

    $card = cardOf(Livewire::test(SellingListOverview::class, ['tableFilters' => ['date' => $filter]]));

    expect($card['title'])->toBe('Today')
        ->and($card['transactions'])->toBe('1');
})->with([
    'only a start' => [['start_date' => '2026-10-23', 'end_date' => null]],
    'only an end' => [['start_date' => null, 'end_date' => '2026-10-24']],
    'backwards' => [['start_date' => '2026-10-25', 'end_date' => '2026-10-20']],
    'not a date' => [['start_date' => 'soon', 'end_date' => 'later']],
]);

test('the Trash tab has no card, because a total of trashed sales would be taken for real sales', function () {
    cardSale('2026-10-25 01:00:00');

    expect(cardOf(Livewire::test(SellingListOverview::class, ['activeTab' => 'trash'])))->toBeNull();
    Livewire::test(SellingListOverview::class, ['activeTab' => 'trash'])->assertDontSee('Cost of goods');
    expect(cardOf(Livewire::test(SellingListOverview::class, ['activeTab' => 'selling'])))->not->toBeNull();
});

test('the sales list page carries the card', function () {
    cardSale('2026-10-25 01:00:00');

    Livewire::test(ListSellings::class)
        ->assertSeeLivewire(SellingListOverview::class)
        ->assertSee('Cost of goods');
});

test('the sales list page hands the filters of the table to the card, also when they come from the address', function () {
    cardSale('2026-10-24 05:00:00', ['total_price' => 80000]);
    cardSale('2026-10-23 05:00:00', ['total_price' => 20000]);

    // Opened with the filter in the address, the way a shared link or a reload does.
    $fromAddress = Livewire::withQueryParams(['tableFilters' => ['date' => ['start_date' => '2026-10-23', 'end_date' => '2026-10-24']]])
        ->test(ListSellings::class);

    expect($fromAddress->instance()->getWidgetData())->toHaveKeys(['activeTab', 'tableFilters'])
        ->and($fromAddress->instance()->getWidgetData()['tableFilters']['date']['start_date'])->toStartWith('2026-10-23');
    $fromAddress->assertSee('23 Oct – 24 Oct 2026');

});

test('the card gets new filters when Apply is pressed, as the rows do', function () {
    // The filters of this table are deferred, so nothing moves while the cashier is still picking dates.
    $deferred = Livewire::withQueryParams([])->test(ListSellings::class)
        ->assertSee('Today')
        ->set('tableDeferredFilters.date.start_date', '2026-10-24')
        ->set('tableDeferredFilters.date.end_date', '2026-10-24');

    expect($deferred->instance()->getWidgetData()['tableFilters']['date']['start_date'] ?? null)->toBeNull();

    $deferred->call('applyTableFilters');

    expect($deferred->instance()->getWidgetData()['tableFilters']['date']['start_date'])->toBe('2026-10-24');
});

test('the rows of the sales list and its card are the same whole days, even when the picker brings the time of day', function () {
    $inside = [
        cardSale('2026-10-22 17:00:00'),   // 23 Oct 00:00 WIB: the first second of the first day
        cardSale('2026-10-23 05:00:00'),
        cardSale('2026-10-24 16:59:59'),   // 24 Oct 23:59:59 WIB: the last second of the last day
    ];
    $outside = [
        cardSale('2026-10-22 16:59:59'),   // 22 Oct 23:59:59 WIB
        cardSale('2026-10-24 17:00:00'),   // 25 Oct 00:00 WIB
    ];

    // The date pickers fill themselves from the address with the current time of day (10:00 UTC here).
    $page = Livewire::withQueryParams(['tableFilters' => ['date' => ['start_date' => '2026-10-23', 'end_date' => '2026-10-24']]])
        ->test(ListSellings::class);

    expect($page->instance()->tableFilters['date']['start_date'])->toContain(':')
        ->and($page->instance()->getFilteredTableQuery()->pluck('id')->sort()->values()->all())
        ->toBe(collect($inside)->pluck('id')->sort()->values()->all());

    $card = cardOf(Livewire::test(SellingListOverview::class, ['tableFilters' => $page->instance()->tableFilters]));
    expect($card['transactions'])->toBe('3')
        ->and($card['title'])->toBe('23 Oct – 24 Oct 2026');

    $page->assertCanSeeTableRecords($inside)->assertCanNotSeeTableRecords($outside);
});

test('one date alone is no filter, for the rows and for the card', function () {
    cardSale('2026-10-25 01:00:00');
    $old = cardSale('2026-10-10 05:00:00');

    $page = Livewire::withQueryParams(['tableFilters' => ['date' => ['start_date' => '2026-10-20', 'end_date' => '']]])
        ->test(ListSellings::class);

    $page->assertCanSeeTableRecords([$old]);
    expect(cardOf(Livewire::test(SellingListOverview::class, ['tableFilters' => $page->instance()->tableFilters]))['title'])->toBe('Today');
});

test('the dashboard card can show another day, never a future one, and the sales list card has no picker', function () {
    cardSale('2026-10-25 01:00:00');                                  // today, Sun 25 Oct
    cardSale('2026-10-24 05:00:00', ['total_price' => 80000]);        // Sat 24 Oct
    cardSale('2026-10-23 05:00:00', ['total_price' => 20000]);        // Fri 23 Oct

    $widget = Livewire::test(SellingOverview::class)
        ->assertSee('Today')
        ->assertDontSee('Back to today');
    expect(cardOf($widget)['transactions'])->toBe('1');

    $widget->call('previousDay')->assertSee('Back to today');
    $card = cardOf($widget);
    expect($widget->get('day'))->toBe('2026-10-24')
        ->and($card['title'])->toBe('Saturday')
        ->and($card['date'])->toBe('Sat, 24 Oct 2026')
        ->and($card['omzet'])->toContain('80,000')
        ->and($card['as_of'])->toBeNull()
        ->and($card['vs'])->toBe('compared to the day before')
        ->and($card['omzet_delta'])->toBe(['dir' => 'up', 'text' => '300%']);

    $widget->call('previousDay');
    expect(cardOf($widget)['omzet'])->toContain('20,000');

    $widget->call('nextDay')->call('nextDay');
    expect($widget->get('day'))->toBe('2026-10-25')
        ->and(cardOf($widget)['title'])->toBe('Today');

    $widget->call('nextDay');                                         // tomorrow: not allowed
    expect(cardOf($widget)['title'])->toBe('Today');

    $widget->set('day', '2026-10-24')->call('today');
    expect($widget->get('day'))->toBeNull();

    foreach (['2026-13-45', 'yesterday', '2026-10-30', '', '2026-10-2'] as $bad) {
        expect(cardOf(Livewire::test(SellingOverview::class, ['day' => $bad]))['title'])->toBe('Today');
    }

    Livewire::test(SellingListOverview::class)->assertDontSee('Pick a day');
    Livewire::test(SellingOverview::class)->assertSee('Pick a day');
});

test('a day in the past is cut at WIB midnight and has no "as of" time', function () {
    cardSale('2026-10-23 16:59:00');   // 23:59 WIB on the 23rd
    cardSale('2026-10-23 17:00:00');   // 00:00 WIB on the 24th
    cardSale('2026-10-24 16:59:59');   // 23:59:59 WIB on the 24th
    cardSale('2026-10-24 17:00:00');   // the 25th

    $card = cardOf(Livewire::test(SellingOverview::class, ['day' => '2026-10-24']));

    expect($card['transactions'])->toBe('2');
});
