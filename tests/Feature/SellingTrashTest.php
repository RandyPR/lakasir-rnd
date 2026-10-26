<?php

use App\Filament\Tenant\Resources\ProductResource\Widgets\StatsProduct;
use App\Filament\Tenant\Resources\ReceivableResource\Widgets\StatsReceivable;
use App\Filament\Tenant\Resources\SellingResource\Pages\ListSellings;
use App\Filament\Tenant\Resources\SellingResource\Pages\ViewSelling;
use App\Models\Tenants\Member;
use App\Models\Tenants\PaymentMethod;
use App\Models\Tenants\Product;
use App\Models\Tenants\Receivable;
use App\Models\Tenants\Selling;
use App\Models\Tenants\SellingDetail;
use App\Models\Tenants\Setting;
use App\Models\Tenants\User;
use App\Services\Tenants\NumberingLock;
use App\Services\Tenants\SalesTrendService;
use App\Services\Tenants\SellingService;
use App\Services\Tenants\SellingTrashService;
use App\Support\SalesPeriod;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;

uses(RefreshDatabaseWithTenant::class);

/*
|--------------------------------------------------------------------------
| Trash for sales
|--------------------------------------------------------------------------
|
| A trashed sale keeps its row, lines, number and code; the stock goes back; every list, report and the API
| leave it out; restoring undoes all of it. "Now" is 2026-10-25 09:00 WIB.
*/

beforeEach(function () {
    config(['pos.business_timezone' => 'Asia/Jakarta']);
    app()->setLocale('en');
    Carbon::setTestNow(Carbon::parse('2026-10-25 02:00:00', 'UTC'));
    Setting::set('selling_method', 'fifo');
    Cache::clear();
    NumberingLock::flush();
    Filament::setCurrentPanel(Filament::getPanel('tenant'));
    $this->owner = User::first();
    actingAs($this->owner);
    $this->product = Product::factory()->create([
        'name' => 'Kopi Susu',
        'initial_price' => 6000,
        'selling_price' => 15000,
        'stock' => 10,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
    NumberingLock::flush();
});

/**
 * A paid sale made the normal way (number, code, stock and totals as the cashier produces them).
 *
 * @param  array<string, mixed>  $data
 */
function trashSale(Product $product, int $qty = 1, array $data = []): Selling
{
    $service = new SellingService;
    $data = array_merge([
        'payed_money' => 15000 * $qty,
        'friend_price' => false,
        'products' => [['product_id' => $product->id, 'qty' => $qty]],
    ], $data);

    return $service->create(array_merge($data, $service->mapProductRequest($data)));
}

function trashStockOf(Product $product): float
{
    return (float) DB::table('stocks')->where('product_id', $product->id)->sum('stock');
}

function trashService(): SellingTrashService
{
    return app(SellingTrashService::class);
}

/** A non-owner whose role holds exactly these permissions. */
function trashUserCan(string ...$permissions): User
{
    $user = User::create([
        'email' => 'trash'.uniqid().'@mail.com',
        'password' => bcrypt('password'),
        'is_owner' => false,
    ]);
    $role = Role::create(['name' => 'trash-'.uniqid(), 'guard_name' => 'web']);
    foreach ($permissions as $name) {
        $role->permissions()->syncWithoutDetaching(Permission::where('name', $name)->get());
    }
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->profile()->firstOrCreate([], ['timezone' => 'Asia/Jakarta']);

    return $user;
}

/*
|--------------------------------------------------------------------------
| The service
|--------------------------------------------------------------------------
*/

test('trashing keeps the row and its lines, hides the sale and records who and why', function () {
    $sale = trashSale($this->product, 2);

    $result = trashService()->trash([$sale->id], $this->owner, '  salah input  ');

    expect($result)->toBe(['done' => 1, 'skipped' => 0, 'failed' => []]);
    expect(Selling::find($sale->id))->toBeNull();

    $trashed = Selling::onlyTrashed()->find($sale->id);
    expect($trashed)->not->toBeNull()
        ->and($trashed->deleted_by)->toBe($this->owner->id)
        ->and($trashed->delete_reason)->toBe('salah input')
        ->and($trashed->code)->toBe($sale->code)
        ->and($trashed->daily_order_number)->toBe($sale->daily_order_number)
        ->and(SellingDetail::where('selling_id', $sale->id)->count())->toBe(1);
});

test('trashing returns the stock and restoring takes it out again', function () {
    $sale = trashSale($this->product, 3);
    expect(trashStockOf($this->product))->toBe(7.0);

    trashService()->trash([$sale->id], $this->owner);

    expect(trashStockOf($this->product))->toBe(10.0)
        ->and((float) $this->product->fresh()->stock)->toBe(10.0);

    $result = trashService()->restore([$sale->id]);

    expect($result)->toBe(['done' => 1, 'skipped' => 0, 'failed' => []]);
    expect(trashStockOf($this->product))->toBe(7.0)
        ->and((float) $this->product->fresh()->stock)->toBe(7.0);

    $restored = Selling::find($sale->id);
    expect($restored)->not->toBeNull()
        ->and($restored->deleted_by)->toBeNull()
        ->and($restored->delete_reason)->toBeNull()
        ->and($restored->date)->toBe($sale->date);
});

test('trashing the same sale twice returns the stock once', function () {
    $sale = trashSale($this->product, 4);

    $first = trashService()->trash([$sale->id], $this->owner);
    $second = trashService()->trash([$sale->id], $this->owner);

    expect($first['done'])->toBe(1)
        ->and($second)->toBe(['done' => 0, 'skipped' => 1, 'failed' => []])
        ->and(trashStockOf($this->product))->toBe(10.0);
});

test('restoring a sale that is not in the trash does nothing', function () {
    $sale = trashSale($this->product, 1);

    expect(trashService()->restore([$sale->id]))->toBe(['done' => 0, 'skipped' => 1, 'failed' => []])
        ->and(trashStockOf($this->product))->toBe(9.0);
});

test('a sale is not restored when its stock is gone, and nothing changes', function () {
    $sale = trashSale($this->product, 8);
    trashService()->trash([$sale->id], $this->owner);
    trashSale($this->product, 5); // 10 - 5 = 5 left, the trashed sale needs 8

    $result = trashService()->restore([$sale->id]);

    expect($result['done'])->toBe(0)
        ->and($result['failed'])->toHaveKey($sale->code)
        ->and($result['failed'][$sale->code])->toContain('Kopi Susu');
    expect(Selling::onlyTrashed()->find($sale->id))->not->toBeNull()
        ->and(trashStockOf($this->product))->toBe(5.0);
});

test('a bulk restore restores the sales that fit and reports the one that does not', function () {
    $small = trashSale($this->product, 2);
    $big = trashSale($this->product, 6);
    trashService()->trash([$small->id, $big->id], $this->owner);
    trashSale($this->product, 5); // 5 left

    $result = trashService()->restore([$small->id, $big->id]);

    expect($result['done'])->toBe(1)
        ->and(array_keys($result['failed']))->toBe([$big->code]);
    expect(Selling::find($small->id))->not->toBeNull()
        ->and(Selling::onlyTrashed()->find($big->id))->not->toBeNull()
        ->and(trashStockOf($this->product))->toBe(3.0);
});

test('a deleted product and a product without stock are left alone', function () {
    $gone = Product::factory()->create(['name' => 'Sudah Dihapus', 'initial_price' => 1000, 'selling_price' => 2000, 'stock' => 5]);
    $service = Product::factory()->create(['name' => 'Jasa', 'initial_price' => 0, 'selling_price' => 5000, 'stock' => 5]);
    $service->forceFill(['is_non_stock' => true])->save();

    $sale = trashSale($gone, 1, ['products' => [
        ['product_id' => $gone->id, 'qty' => 1],
        ['product_id' => $service->id, 'qty' => 1],
    ]]);
    $stockOfGone = trashStockOf($gone);
    $stockOfService = trashStockOf($service);
    $gone->delete();

    expect(trashService()->trash([$sale->id], $this->owner)['done'])->toBe(1);
    expect(trashStockOf($gone))->toBe($stockOfGone)
        ->and(trashStockOf($service))->toBe($stockOfService);

    expect(trashService()->restore([$sale->id])['done'])->toBe(1);
    expect(trashStockOf($gone))->toBe($stockOfGone)
        ->and(trashStockOf($service))->toBe($stockOfService);
});

test('a price unit line returns the quantity in the base unit', function () {
    $unit = $this->product->priceUnits()->create(['unit' => 'Box', 'stock' => 4, 'selling_price' => 50000]);
    $sale = trashSale($this->product, 1, [
        'payed_money' => 50000,
        'products' => [['product_id' => $this->product->id, 'qty' => 1, 'price_unit_id' => $unit->id]],
    ]);
    expect(trashStockOf($this->product))->toBe(6.0);

    trashService()->trash([$sale->id], $this->owner);

    expect(trashStockOf($this->product))->toBe(10.0);
});

/*
|--------------------------------------------------------------------------
| Numbers are never handed out twice
|--------------------------------------------------------------------------
*/

test('the daily order number and the code of a trashed sale are not given to a new sale', function () {
    $first = trashSale($this->product);
    $second = trashSale($this->product);
    trashService()->trash([$second->id], $this->owner);

    $third = trashSale($this->product);

    expect($first->daily_order_number)->toBe(1)
        ->and($second->daily_order_number)->toBe(2)
        ->and($third->daily_order_number)->toBe(3)
        ->and($third->code)->not->toBe($second->code)
        ->and([$first->code, $second->code, $third->code])->toBe(['SELL0001', 'SELL0002', 'SELL0003']);

    // Restoring brings back the same number without meeting another sale that has it.
    trashService()->restore([$second->id]);
    expect(Selling::pluck('daily_order_number')->sort()->values()->all())->toBe([1, 2, 3]);
});

test('the numbered customer name continues after a trashed sale', function () {
    $one = trashSale($this->product, 1, ['customer_name' => 'Budi']);
    $two = trashSale($this->product, 1, ['customer_name' => 'Budi']);
    trashService()->trash([$two->id], $this->owner);

    $three = trashSale($this->product, 1, ['customer_name' => 'Budi']);

    expect($one->customer_name)->toBe('Budi')
        ->and($two->customer_name)->toBe('Budi (2)')
        ->and($three->customer_name)->toBe('Budi (3)');
});

/*
|--------------------------------------------------------------------------
| Nothing leaks: every number leaves the trashed sale out
|--------------------------------------------------------------------------
*/

test('the dashboard totals, the chart and the best sellers leave a trashed sale out', function () {
    $keep = trashSale($this->product, 1);
    $drop = trashSale($this->product, 3);
    $service = app(SalesTrendService::class);

    $before = $service->trend(SalesPeriod::Week, Carbon::now());
    expect($before['totals']['transactions'])->toBe(2);
    expect((int) $service->bestSellingQuery(SalesPeriod::Week, Carbon::now())->get()->sum('total_qty'))->toBe(4);

    trashService()->trash([$drop->id], $this->owner);

    $after = $service->trend(SalesPeriod::Week, Carbon::now());
    expect($after['totals']['transactions'])->toBe(1)
        ->and($after['totals']['revenue'])->toBe((float) $keep->grand_total_price);
    expect((int) $service->bestSellingQuery(SalesPeriod::Week, Carbon::now())->get()->sum('total_qty'))->toBe(1);
});

test('the sold figures of a product leave a trashed sale out', function () {
    trashSale($this->product, 1);
    $drop = trashSale($this->product, 3);
    $stat = function () {
        $widget = Livewire::test(StatsProduct::class, ['product' => $this->product])->instance();
        $stats = (new ReflectionMethod($widget, 'getStats'))->invoke($widget);

        return collect($stats)->filter()->mapWithKeys(fn ($stat) => [(string) $stat->getLabel() => $stat->getValue()]);
    };

    expect($stat()->get('Sold'))->toBe(4.0);

    trashService()->trash([$drop->id], $this->owner);

    expect($stat()->get('Sold'))->toBe(1.0);
    expect(SellingDetail::ofSellingNotTrashed()->where('product_id', $this->product->id)->count())->toBe(1);
});

test('the receivables of a trashed sale are hidden and come back with the sale', function () {
    $sale = trashSale($this->product);
    $member = Member::factory()->create();
    Receivable::create([
        'member_id' => $member->id,
        'selling_id' => $sale->id,
        'total_receivable' => 15000,
        'rest_receivable' => 15000,
        'due_date' => '2026-11-01',
    ]);
    $total = function () {
        $widget = Livewire::test(StatsReceivable::class)->instance();
        $stats = (new ReflectionMethod($widget, 'getStats'))->invoke($widget);

        return $stats[1]->getValue();
    };
    expect(Receivable::ofSellingNotTrashed()->count())->toBe(1);

    trashService()->trash([$sale->id], $this->owner);

    expect(Receivable::ofSellingNotTrashed()->count())->toBe(0)
        ->and(Receivable::count())->toBe(1)
        ->and($total())->toBe('0');

    trashService()->restore([$sale->id]);

    expect(Receivable::ofSellingNotTrashed()->count())->toBe(1);
});

test('the sales list and the detail of the API leave a trashed sale out', function () {
    $keep = trashSale($this->product);
    $drop = trashSale($this->product);
    trashService()->trash([$drop->id], $this->owner);
    Laravel\Sanctum\Sanctum::actingAs($this->owner, ['*']);

    $ids = collect(test()->getJson('/api/transaction/selling')->assertOk()->json('data.data'))->pluck('id')->all();
    expect($ids)->toContain($keep->id)->not->toContain($drop->id);

    test()->getJson('/api/transaction/selling/'.$drop->id)->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Who may, and what the pages show
|--------------------------------------------------------------------------
*/

test('the owner and a role with the delete permission may trash and restore, others may not', function () {
    $sale = trashSale($this->product);
    $allowed = trashUserCan('delete selling');
    $reader = trashUserCan('read selling');

    expect(Gate::forUser($this->owner)->allows('delete', $sale))->toBeTrue()
        ->and(Gate::forUser($this->owner)->allows('restore', $sale))->toBeTrue()
        ->and(Gate::forUser($allowed)->allows('delete', $sale))->toBeTrue()
        ->and(Gate::forUser($allowed)->allows('restore', $sale))->toBeTrue()
        ->and(Gate::forUser($reader)->allows('delete', $sale))->toBeFalse()
        ->and(Gate::forUser($reader)->allows('restore', $sale))->toBeFalse()
        ->and(Gate::forUser($reader)->allows('deleteAny', Selling::class))->toBeFalse();
});

test('nobody can delete a sale for good, the owner included', function () {
    $sale = trashSale($this->product);

    expect(Gate::forUser($this->owner)->allows('forceDelete', $sale))->toBeFalse()
        ->and(Gate::forUser($this->owner)->allows('forceDeleteAny', Selling::class))->toBeFalse();
});

test('the list shows sales, and the Trash tab shows only trashed sales', function () {
    $keep = trashSale($this->product);
    $drop = trashSale($this->product);
    trashService()->trash([$drop->id], $this->owner);

    Livewire::test(ListSellings::class)
        ->assertCanSeeTableRecords([$keep])
        ->assertCanNotSeeTableRecords([$drop]);

    Livewire::test(ListSellings::class)
        ->set('activeTab', 'trash')
        ->assertCanSeeTableRecords([$drop])
        ->assertCanNotSeeTableRecords([$keep]);
});

test('someone who may not restore never sees the trash, not even with ?activeTab=trash', function () {
    $keep = trashSale($this->product);
    $drop = trashSale($this->product);
    trashService()->trash([$drop->id], $this->owner);
    actingAs(trashUserCan('read selling'));

    Livewire::test(ListSellings::class)
        ->set('activeTab', 'trash')
        ->assertCanSeeTableRecords([$keep])
        ->assertCanNotSeeTableRecords([$drop])
        ->assertTableBulkActionHidden('trash')
        ->assertTableBulkActionHidden('restore');
});

test('the bulk actions move several sales to the trash and bring them back', function () {
    $a = trashSale($this->product);
    $b = trashSale($this->product);
    $c = trashSale($this->product);

    Livewire::test(ListSellings::class)
        ->assertTableBulkActionVisible('trash')
        ->assertTableBulkActionHidden('restore')
        ->callTableBulkAction('trash', [$a, $b], data: ['reason' => 'transaksi uji'])
        ->assertHasNoTableBulkActionErrors();

    expect(Selling::pluck('id')->all())->toBe([$c->id])
        ->and(Selling::onlyTrashed()->pluck('delete_reason')->unique()->all())->toBe(['transaksi uji'])
        ->and(trashStockOf($this->product))->toBe(9.0);

    Livewire::test(ListSellings::class)
        ->set('activeTab', 'trash')
        ->assertTableBulkActionVisible('restore')
        ->assertTableBulkActionHidden('trash')
        ->callTableBulkAction('restore', [$a, $b])
        ->assertHasNoTableBulkActionErrors();

    expect(Selling::count())->toBe(3)
        ->and(trashStockOf($this->product))->toBe(7.0);
});

test('the sale page can move the sale to the trash and restore it', function () {
    $sale = trashSale($this->product);

    Livewire::test(ViewSelling::class, ['record' => $sale->id])
        ->assertActionVisible('trash')
        ->assertActionHidden('restore')
        ->callAction('trash', ['reason' => 'salah input'])
        ->assertHasNoActionErrors();

    expect(Selling::onlyTrashed()->find($sale->id)->delete_reason)->toBe('salah input');

    Livewire::test(ViewSelling::class, ['record' => $sale->id])
        ->assertActionHidden('trash')
        ->assertActionVisible('restore')
        ->assertActionHidden('printReceipt')
        ->callAction('restore')
        ->assertHasNoActionErrors();

    expect(Selling::find($sale->id))->not->toBeNull();
});

test('a trashed sale page is a 404 for someone who may not restore', function () {
    $sale = trashSale($this->product);
    trashService()->trash([$sale->id], $this->owner);
    actingAs(trashUserCan('read selling'));

    Livewire::test(ViewSelling::class, ['record' => $sale->id])->assertNotFound();
});

test('without the permission the buttons are not there and the action is refused', function () {
    $sale = trashSale($this->product);
    actingAs(trashUserCan('read selling'));

    Livewire::test(ViewSelling::class, ['record' => $sale->id])
        ->assertActionHidden('trash');

    expect(fn () => App\Filament\Tenant\Resources\SellingResource\SellingTrashActions::trash([$sale->id]))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(Selling::find($sale->id))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The migration (additive, safe to repeat)
|--------------------------------------------------------------------------
*/

const TRASH_MIGRATION = '2026_10_26_000001_add_soft_deletes_to_sellings_table';

test('the trash migration only adds nullable columns and an index, through SafeMigration', function () {
    $source = (string) file_get_contents(database_path('migrations/'.TRASH_MIGRATION.'.php'));

    expect($source)->toContain('SafeMigration::addColumn')
        ->and($source)->toContain('SafeMigration::index')
        ->and($source)->not->toContain('->after(')
        ->and($source)->not->toContain('->change(')
        ->and($source)->not->toContain('DB::table');

    foreach (['deleted_at', 'deleted_by', 'delete_reason'] as $column) {
        $info = collect(Illuminate\Support\Facades\Schema::getColumns('sellings'))->firstWhere('name', $column);
        expect($info['nullable'])->toBeTrue();
    }
});

test('the trash migration can run again, completes a stopped run and keeps trashed sales', function () {
    $sale = trashSale($this->product);
    trashService()->trash([$sale->id], $this->owner);
    $migration = require database_path('migrations/'.TRASH_MIGRATION.'.php');

    $migration->up();
    expect(Selling::onlyTrashed()->find($sale->id))->not->toBeNull();

    App\Support\SafeMigration::dropIndexIfExists('sellings', 'sellings_deleted_at_index');
    $migration->up();

    expect(Illuminate\Support\Facades\Schema::hasIndex('sellings', 'sellings_deleted_at_index'))->toBeTrue()
        ->and(Selling::onlyTrashed()->find($sale->id))->not->toBeNull();
});
