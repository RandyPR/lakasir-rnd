<?php

use App\Exceptions\SaleBusyException;
use App\Models\Tenants\PaymentMethod;
use App\Models\Tenants\PriceUnit;
use App\Models\Tenants\Product;
use App\Models\Tenants\Selling;
use App\Models\Tenants\SellingDetail;
use App\Models\Tenants\Setting;
use App\Models\Tenants\Stock;
use App\Models\Tenants\User;
use App\Models\Tenants\Voucher;
use App\Services\Tenants\NumberingLock;
use App\Services\Tenants\OrderTotals;
use App\Services\Tenants\SellingService;
use App\Support\Money;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;

uses(RefreshDatabaseWithTenant::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 05:00:00', 'UTC'));
    Setting::set('selling_method', 'fifo');
    Cache::clear();
    NumberingLock::flush();
    actingAs(User::first());
    $this->product = Product::factory()->create([
        'name' => 'Produk Hook',
        'initial_price' => 10000,
        'selling_price' => 25000,
        'stock' => 10,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
    NumberingLock::flush();
});

/**
 * Builds the data the FormRequest would hand to create(), and creates the sale.
 *
 * @param  array<string, mixed>  $data
 * @param  array<string, mixed>  $hooks
 */
function hookSale(Product $product, array $data = [], array $hooks = [], ?SellingService $service = null): Selling
{
    $service ??= new SellingService;
    $data = array_merge([
        'payed_money' => 25000,
        'friend_price' => false,
        'products' => [['product_id' => $product->id, 'qty' => 1]],
    ], $data);

    return $service->create(array_merge($data, $service->mapProductRequest($data)), $hooks);
}

/** Request data as built by the form request, without creating the sale. */
function hookData(SellingService $service, Product $product, array $data = []): array
{
    $data = array_merge([
        'payed_money' => 25000,
        'friend_price' => false,
        'products' => [['product_id' => $product->id, 'qty' => 1]],
    ], $data);

    return array_merge($data, $service->mapProductRequest($data));
}

function hookContention(int $driverCode, string $sqlState = '40001'): QueryException
{
    $previous = new PDOException('simulated');
    $previous->errorInfo = [$sqlState, $driverCode, 'simulated'];
    (new ReflectionProperty(Exception::class, 'code'))->setValue($previous, $sqlState);

    return new QueryException('mysql', 'select 1', [], $previous);
}

function hookStockOf(Product $product): float
{
    return (float) DB::table('stocks')->where('product_id', $product->id)->sum('stock');
}

/*
|--------------------------------------------------------------------------
| hooks: before and after run inside the transaction
|--------------------------------------------------------------------------
*/

test('before runs inside the transaction, after the sales lock and before anything is written', function () {
    $outer = DB::transactionLevel();
    $seen = [];
    $service = new SellingService;
    $data = hookData($service, $this->product);
    NumberingLock::ensure();

    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$seen) {
        $seen[] = strtolower($query->sql);
    });

    $service->create($data, [
        'before' => function () use (&$seen, $outer) {
            $seen[] = 'before-hook:level='.(DB::transactionLevel() - $outer);
        },
    ]);
    Event::forget(QueryExecuted::class);

    $hook = array_search('before-hook:level=1', $seen, true);
    expect($hook)->not->toBeFalse();
    // The lock query comes first, the hook right after it, and no write happens before the hook.
    expect($seen[0])->toContain('numbering_locks');
    expect($hook)->toBe(1);
});

test('after runs inside the transaction with the saved sale, its details and the updated stock', function () {
    $outer = DB::transactionLevel();
    $captured = [];

    $sale = hookSale($this->product, hooks: [
        'after' => function (Selling $selling) use (&$captured, $outer) {
            $captured = [
                'level' => DB::transactionLevel() - $outer,
                'id' => $selling->id,
                'number' => $selling->daily_order_number,
                'details' => SellingDetail::where('selling_id', $selling->id)->count(),
                'stock' => hookStockOf($this->product),
                'product_stock' => (float) Product::find($this->product->id)->stock,
            ];
        },
    ]);

    expect($captured['level'])->toBe(1);
    expect($captured['id'])->toBe($sale->id);
    expect($captured['number'])->toBe(1);
    expect($captured['details'])->toBe(1);
    expect($captured['stock'])->toBe(9.0);
    expect($captured['product_stock'])->toBe(9.0);
});

test('hooks run in order: before, then the sale, then after', function () {
    $order = [];

    hookSale($this->product, hooks: [
        'before' => function () use (&$order) {
            $order[] = 'before:sellings='.Selling::count();
        },
        'after' => function () use (&$order) {
            $order[] = 'after:sellings='.Selling::count();
        },
    ]);

    expect($order)->toBe(['before:sellings=0', 'after:sellings=1']);
});

test('create works with no hooks at all and with empty hooks', function () {
    expect(hookSale($this->product)->exists)->toBeTrue();
    expect(hookSale($this->product, hooks: [])->exists)->toBeTrue();
    expect(hookSale($this->product, hooks: ['before' => null, 'after' => null])->exists)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| a failure in a hook rolls everything back
|--------------------------------------------------------------------------
*/

test('an exception in after rolls back the sale, its details, the stock and the number', function () {
    $stockBefore = hookStockOf($this->product);

    expect(fn () => hookSale($this->product, hooks: [
        'after' => fn () => throw new RuntimeException('bill update failed'),
    ]))->toThrow(RuntimeException::class, 'bill update failed');

    expect(Selling::count())->toBe(0);
    expect(SellingDetail::count())->toBe(0);
    expect(hookStockOf($this->product))->toBe($stockBefore);
    expect((float) $this->product->fresh()->stock)->toBe($stockBefore);

    // The number was not used up: the next sale is order 1 again.
    expect(hookSale($this->product)->daily_order_number)->toBe(1);
});

test('an exception in after does not consume the voucher', function () {
    $voucher = Voucher::factory()->create(['code' => 'ONLY1', 'kuota' => 1]);

    expect(fn () => hookSale($this->product, ['voucher' => 'ONLY1'], [
        'after' => fn () => throw new RuntimeException('late failure'),
    ]))->toThrow(RuntimeException::class);

    expect($voucher->fresh()->kuota)->toBe(1);
    expect(Selling::count())->toBe(0);
});

test('an exception in before writes nothing and the next sale is unaffected', function () {
    expect(fn () => hookSale($this->product, hooks: [
        'before' => fn () => throw new RuntimeException('cart changed'),
    ]))->toThrow(RuntimeException::class, 'cart changed');

    expect(Selling::count())->toBe(0);
    expect(hookStockOf($this->product))->toBe(10.0);
    expect(hookSale($this->product)->daily_order_number)->toBe(1);
});

test('a failing sale does not leave a transaction open', function () {
    $level = DB::transactionLevel();

    try {
        hookSale($this->product, hooks: ['after' => fn () => throw new RuntimeException('x')]);
    } catch (RuntimeException) {
    }

    expect(DB::transactionLevel())->toBe($level);
});

test('a non contention QueryException is not retried', function () {
    $calls = 0;

    expect(function () use (&$calls) {
        hookSale($this->product, hooks: ['before' => function () use (&$calls) {
            $calls++;
            throw hookContention(1062, '23000');
        }]);
    })->toThrow(QueryException::class);

    expect($calls)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| deadlock / lock wait timeout: one retry, then SaleBusyException
|--------------------------------------------------------------------------
*/

test('a deadlock (1213) is retried once and the sale then succeeds', function () {
    $calls = 0;

    $sale = hookSale($this->product, hooks: ['before' => function () use (&$calls) {
        $calls++;
        if ($calls === 1) {
            throw hookContention(1213);
        }
    }]);

    expect($calls)->toBe(2);
    expect(Selling::count())->toBe(1);
    expect($sale->daily_order_number)->toBe(1);
    expect(SellingDetail::count())->toBe(1);
    expect(hookStockOf($this->product))->toBe(9.0);
});

test('a lock wait timeout (1205) is retried once too', function () {
    $calls = 0;

    hookSale($this->product, hooks: ['before' => function () use (&$calls) {
        $calls++;
        if ($calls === 1) {
            throw hookContention(1205, 'HY000');
        }
    }]);

    expect($calls)->toBe(2);
    expect(Selling::count())->toBe(1);
});

test('a second deadlock gives SaleBusyException and the sale is rolled back completely', function () {
    $calls = 0;

    try {
        hookSale($this->product, hooks: ['before' => function () use (&$calls) {
            $calls++;
            throw hookContention(1213);
        }]);
        $thrown = null;
    } catch (SaleBusyException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(SaleBusyException::class);
    expect($thrown->getPrevious())->toBeInstanceOf(QueryException::class);
    expect($calls)->toBe(2);
    expect(Selling::count())->toBe(0);
    expect(hookStockOf($this->product))->toBe(10.0);
});

test('a deadlock in the middle of the sale (after the details are written) is retried without duplicates', function () {
    $calls = 0;

    hookSale($this->product, hooks: ['after' => function () use (&$calls) {
        $calls++;
        if ($calls === 1) {
            throw hookContention(1213);
        }
    }]);

    expect($calls)->toBe(2);
    expect(Selling::count())->toBe(1);
    expect(SellingDetail::count())->toBe(1);
    expect(hookStockOf($this->product))->toBe(9.0);
});

test('SaleBusyException answers 503 to JSON clients and defers to the default renderer otherwise', function () {
    $exception = new SaleBusyException;

    $json = $exception->render(Request::create('/api/transaction/selling', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']));
    expect($json->getStatusCode())->toBe(503);
    expect($json->getData(true))->toBe(['message' => 'The system is busy, please try again']);

    $web = $exception->render(Request::create('/admin', 'GET'));
    expect($web)->toBeNull();
});

test('the API answers 503 when the sale stays busy', function () {
    // The listener fires inside the sale transaction on every attempt, like a real lock timeout would.
    $product = $this->product;
    Event::listen(App\Events\SellingCreated::class, function () {
        throw hookContention(1205, 'HY000');
    });

    actingAs(User::first())->postJson('/api/transaction/selling', [
        'payed_money' => 25000,
        'friend_price' => false,
        'products' => [['product_id' => $product->id, 'qty' => 1]],
    ])->assertStatus(503);

    expect(Selling::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| keep_customer_name
|--------------------------------------------------------------------------
*/

test('a customer name is numbered by default', function () {
    hookSale($this->product, ['customer_name' => 'Budi']);
    $second = hookSale($this->product, ['customer_name' => 'Budi']);

    expect($second->customer_name)->toBe('Budi (2)');
});

test('keep_customer_name keeps a name that is already final', function () {
    hookSale($this->product, ['customer_name' => 'Budi']);

    $kept = hookSale($this->product, ['customer_name' => 'Budi (7)'], ['keep_customer_name' => true]);
    $sameAsExisting = hookSale($this->product, ['customer_name' => 'Budi'], ['keep_customer_name' => true]);

    expect($kept->customer_name)->toBe('Budi (7)');
    expect($sameAsExisting->customer_name)->toBe('Budi');
});

test('keep_customer_name does not stop the daily order number', function () {
    hookSale($this->product, ['customer_name' => 'Budi']);

    $second = hookSale($this->product, ['customer_name' => 'Budi'], ['keep_customer_name' => true]);

    expect($second->daily_order_number)->toBe(2);
});

test('the transient flags are not columns and never reach the database', function () {
    expect(Schema::hasColumn('sellings', 'numberingLocked'))->toBeFalse();
    expect(Schema::hasColumn('sellings', 'keepCustomerName'))->toBeFalse();

    $sale = hookSale($this->product, ['customer_name' => 'Siti'], ['keep_customer_name' => true]);

    expect($sale->numberingLocked)->toBeTrue();
    expect($sale->keepCustomerName)->toBeTrue();
    expect(array_key_exists('numberingLocked', $sale->fresh()->getAttributes()))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| one moment: $data['date'] is normalised
|--------------------------------------------------------------------------
*/

test('date with an offset is converted to UTC', function () {
    $sale = hookSale($this->product, ['date' => '2026-10-14T08:30:00+07:00']);

    expect($sale->fresh()->date)->toBe('2026-10-14 01:30:00');
});

test('date as a Carbon in another timezone is converted to UTC', function () {
    $sale = hookSale($this->product, ['date' => Carbon::parse('2026-10-14 08:30:00', 'Asia/Jakarta')]);

    expect($sale->fresh()->date)->toBe('2026-10-14 01:30:00');
});

test('garbage and empty dates become now', function (mixed $date) {
    $sale = hookSale($this->product, ['date' => $date]);

    expect($sale->fresh()->date)->toBe('2026-10-15 05:00:00');
})->with([
    'garbage' => ['not a date'],
    'relative word' => ['tomorrow'],
    'empty' => [''],
    'null' => [null],
]);

test('a missing date becomes now', function () {
    expect(hookSale($this->product)->fresh()->date)->toBe('2026-10-15 05:00:00');
});

test('number, name and column come from the same moment', function () {
    hookSale($this->product, ['customer_name' => 'Budi', 'date' => '2026-10-15T23:59:00+07:00']);
    $next = hookSale($this->product, ['customer_name' => 'Budi', 'date' => '2026-10-16T00:01:00+07:00']);

    expect($next->daily_order_number)->toBe(1);
    expect($next->customer_name)->toBe('Budi');
    expect($next->fresh()->date)->toBe('2026-10-15 17:01:00');
});

/*
|--------------------------------------------------------------------------
| verify_stock
|--------------------------------------------------------------------------
*/

test('verify_stock: with one unit left the first sale passes and the second fails', function () {
    $product = Product::factory()->create(['name' => 'Satu Unit', 'initial_price' => 1000, 'selling_price' => 2000, 'stock' => 1]);

    hookSale($product, ['payed_money' => 2000], ['verify_stock' => true]);
    expect(hookStockOf($product))->toBe(0.0);

    $second = fn () => hookSale($product, ['payed_money' => 2000], ['verify_stock' => true]);
    expect($second)->toThrow(ValidationException::class);

    expect(Selling::count())->toBe(1);
    expect(SellingDetail::count())->toBe(1);
    expect(hookStockOf($product))->toBe(0.0);
});

test('verify_stock names the product and the quantity left on the failing line', function () {
    $product = Product::factory()->create(['name' => 'Roti Bakar', 'initial_price' => 1000, 'selling_price' => 2000, 'stock' => 2]);
    $service = new SellingService;
    $data = hookData($service, $product, ['payed_money' => 6000, 'products' => [['product_id' => $product->id, 'qty' => 3]]]);

    try {
        $service->create($data, ['verify_stock' => true]);
        $errors = [];
    } catch (ValidationException $e) {
        $errors = $e->errors();
    }

    expect(array_keys($errors))->toBe(['products.0.qty']);
    expect($errors['products.0.qty'][0])->toBe('Stock of Roti Bakar is not enough (left 2)');
});

test('a failed stock check rolls back before anything is written and keeps the number', function () {
    $product = Product::factory()->create(['name' => 'Habis', 'initial_price' => 1000, 'selling_price' => 2000, 'stock' => 0]);

    expect(fn () => hookSale($product, ['payed_money' => 2000], ['verify_stock' => true]))->toThrow(ValidationException::class);

    expect(Selling::count())->toBe(0);
    expect(hookSale($this->product)->daily_order_number)->toBe(1);
});

test('without verify_stock the service does not check the stock (the old behaviour of internal callers)', function () {
    $product = Product::factory()->create(['name' => 'Habis Lama', 'initial_price' => 1000, 'selling_price' => 2000, 'stock' => 0]);

    $sale = hookSale($product, ['payed_money' => 2000]);

    expect($sale->exists)->toBeTrue();
});

test('stock_override_product_ids lets an official override pass for those products only', function () {
    $served = Product::factory()->create(['name' => 'Sudah Disajikan', 'initial_price' => 1000, 'selling_price' => 2000, 'stock' => 0]);
    $other = Product::factory()->create(['name' => 'Lainnya', 'initial_price' => 1000, 'selling_price' => 2000, 'stock' => 0]);

    $sale = hookSale($served, ['payed_money' => 2000], ['verify_stock' => true, 'stock_override_product_ids' => [$served->id]]);
    expect($sale->exists)->toBeTrue();

    expect(fn () => hookSale($other, ['payed_money' => 2000], ['verify_stock' => true, 'stock_override_product_ids' => [$served->id]]))
        ->toThrow(ValidationException::class);
});

test('verify_stock checks the whole sale: one failing line fails all of it', function () {
    $short = Product::factory()->create(['name' => 'Kurang', 'initial_price' => 1000, 'selling_price' => 2000, 'stock' => 1]);
    $service = new SellingService;
    $data = hookData($service, $short, ['payed_money' => 100000, 'products' => [
        ['product_id' => $this->product->id, 'qty' => 1],
        ['product_id' => $short->id, 'qty' => 5],
    ]]);

    try {
        $service->create($data, ['verify_stock' => true]);
        $errors = [];
    } catch (ValidationException $e) {
        $errors = $e->errors();
    }

    expect(array_keys($errors))->toBe(['products.1.qty']);
    expect(Selling::count())->toBe(0);
    expect(hookStockOf($this->product))->toBe(10.0);
});

test('the API refuses to oversell the last unit twice', function () {
    $product = Product::factory()->create(['name' => 'Unit Terakhir', 'initial_price' => 1000, 'selling_price' => 2000, 'stock' => 1]);
    $payload = ['payed_money' => 2000, 'friend_price' => false, 'products' => [['product_id' => $product->id, 'qty' => 1]]];

    actingAs(User::first())->postJson('/api/transaction/selling', $payload)->assertOk();
    actingAs(User::first())->postJson('/api/transaction/selling', $payload)->assertUnprocessable();

    expect(Selling::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| voucher: consumed once, atomically, inside the transaction
|--------------------------------------------------------------------------
*/

test('mapProductRequest applies the voucher discount but does not consume it', function () {
    $voucher = Voucher::factory()->create(['code' => 'DISC2K', 'kuota' => 5]);
    $service = new SellingService;

    $mapped = $service->mapProductRequest([
        'payed_money' => 25000,
        'friend_price' => false,
        'voucher' => 'DISC2K',
        'products' => [['product_id' => $this->product->id, 'qty' => 1]],
    ]);

    expect($mapped['discount_price'])->toEqual(2000);
    expect($voucher->fresh()->kuota)->toBe(5);
});

test('a sale with a voucher consumes exactly one use', function () {
    $voucher = Voucher::factory()->create(['code' => 'DISC2K', 'kuota' => 5]);

    $sale = hookSale($this->product, ['voucher' => 'DISC2K', 'payed_money' => 23000]);

    expect($voucher->fresh()->kuota)->toBe(4);
    expect($sale->discount_price)->toBe(2000.0);
});

test('mapping a second time without a sale consumes nothing and a repeated create does not consume twice', function () {
    $voucher = Voucher::factory()->create(['code' => 'DISC2K', 'kuota' => 5]);
    $service = new SellingService;

    $data = hookData($service, $this->product, ['voucher' => 'DISC2K', 'payed_money' => 23000]);
    hookData($service, $this->product, ['voucher' => 'DISC2K', 'payed_money' => 23000]);
    $service->create($data);
    // The same service instance creating again without mapping again must not consume the voucher a second time.
    $service->create($data);

    expect($voucher->fresh()->kuota)->toBe(4);
});

test('quota 1: the first sale takes the voucher and a second sale that was already mapped fails and rolls back', function () {
    $voucher = Voucher::factory()->create(['code' => 'LAST1', 'kuota' => 1]);
    $serviceA = new SellingService;
    $serviceB = new SellingService;
    // Both cashiers validated the voucher while the quota was still 1.
    $dataA = hookData($serviceA, $this->product, ['voucher' => 'LAST1', 'payed_money' => 23000]);
    $dataB = hookData($serviceB, $this->product, ['voucher' => 'LAST1', 'payed_money' => 23000]);

    $serviceA->create($dataA);
    expect($voucher->fresh()->kuota)->toBe(0);

    try {
        $serviceB->create($dataB);
        $errors = [];
    } catch (ValidationException $e) {
        $errors = $e->errors();
    }

    expect(array_keys($errors))->toBe(['voucher']);
    expect($voucher->fresh()->kuota)->toBe(0);
    expect(Selling::count())->toBe(1);
    expect(SellingDetail::count())->toBe(1);
    expect(hookStockOf($this->product))->toBe(9.0);
});

test('an unusable voucher is not applied and not consumed', function () {
    $voucher = Voucher::factory()->create(['code' => 'EXPIRED', 'kuota' => 0]);

    $sale = hookSale($this->product, ['voucher' => 'EXPIRED']);

    expect((float) $sale->discount_price)->toBe(0.0);
    expect($voucher->fresh()->kuota)->toBe(0);
});

test('the voucher period is judged on the business date, not the UTC date', function () {
    // 16 Oct 02:00 Jakarta = 15 Oct 19:00 UTC. A voucher that starts on the 16th is valid.
    Carbon::setTestNow(Carbon::parse('2026-10-15 19:00:00', 'UTC'));
    $voucher = Voucher::factory()->create([
        'code' => 'NEXTDAY',
        'kuota' => 3,
        'start_date' => '2026-10-16',
        'expired' => '2026-10-20',
    ]);

    $sale = hookSale($this->product, ['voucher' => 'NEXTDAY', 'payed_money' => 23000]);

    expect($sale->discount_price)->toBe(2000.0);
    expect($voucher->fresh()->kuota)->toBe(2);
});

/*
|--------------------------------------------------------------------------
| AssignProduct and notes
|--------------------------------------------------------------------------
*/

test('a sale line without a note does not touch the note column', function () {
    $sql = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$sql) {
        $sql[] = normalizeSql($query->sql);
    });

    hookSale($this->product);
    Event::forget(QueryExecuted::class);

    $inserts = array_values(array_filter($sql, fn ($s) => str_starts_with($s, 'insert into "selling_details"')));
    expect($inserts)->toHaveCount(1);
    expect($inserts[0])->not->toContain('"note"');
});

test('a blank note is dropped too', function () {
    $service = new SellingService;
    $data = hookData($service, $this->product, ['products' => [['product_id' => $this->product->id, 'qty' => 1, 'note' => '   ']]]);

    $sale = $service->create($data);

    expect($sale->sellingDetails->first()->note)->toBeNull();
});

test('a sale without notes still works when the note column does not exist yet (partial deploy)', function () {
    Schema::table('selling_details', fn ($table) => $table->dropColumn('note'));
    expect(Schema::hasColumn('selling_details', 'note'))->toBeFalse();

    $sale = hookSale($this->product);

    expect($sale->exists)->toBeTrue();
    expect(SellingDetail::count())->toBe(1);
});

test('a line with a note stores it', function () {
    $service = new SellingService;
    $data = hookData($service, $this->product, ['products' => [['product_id' => $this->product->id, 'qty' => 1, 'note' => 'tanpa es']]]);

    $service->create($data);

    expect(SellingDetail::first()->note)->toBe('tanpa es');
});

/*
|--------------------------------------------------------------------------
| mapProductRequest: money is rounded the same way as OrderTotals
|--------------------------------------------------------------------------
*/

/**
 * @param  array<int, array{0: Product, 1: int|float}>  $lines
 * @return array{0: array<string, mixed>, 1: array<string, float>} [mapped request, order totals]
 */
function hookCompare(array $lines, float $tax, float $payed = 0.0): array
{
    $service = new SellingService;
    $products = array_map(fn (array $line) => ['product_id' => $line[0]->id, 'qty' => $line[1]], $lines);
    $mapped = $service->mapProductRequest([
        'payed_money' => $payed,
        'friend_price' => false,
        'tax' => $tax,
        'products' => $products,
    ]);

    $items = collect($lines)->map(function (array $line) {
        $item = new App\Models\Tenants\OpenBillItem;
        $item->forceFill(['qty' => $line[1], 'price' => $line[0]->selling_price * $line[1], 'discount_price' => 0, 'price_unit_id' => null]);

        return $item;
    });

    return [$mapped, OrderTotals::compute($items, $tax)];
}

test('OrderTotals and mapProductRequest agree to the cent at 11 percent on odd subtotals', function (float $price, int $qty) {
    $product = Product::factory()->create(['name' => 'Harga Ganjil', 'initial_price' => 1, 'selling_price' => $price, 'stock' => 100]);

    [$mapped, $totals] = hookCompare([[$product, $qty]], 11.0);

    expect($mapped['tax_price'])->toBe($totals['tax_price']);
    expect($mapped['total_price'])->toBe($totals['total_price']);
})->with([
    'one unit' => [10001, 1],
    'three units' => [5555, 3],
    'seven' => [7, 7],
    'big odd' => [33333, 3],
    'tiny' => [1, 3],
    'larger odd' => [100001, 9],
    'thirds' => [3333.33, 3],
]);

test('several lines (the 1 and 3 combination) agree as well', function () {
    $a = Product::factory()->create(['name' => 'A', 'initial_price' => 1, 'selling_price' => 10001, 'stock' => 100]);
    $b = Product::factory()->create(['name' => 'B', 'initial_price' => 1, 'selling_price' => 3333.33, 'stock' => 100]);
    $c = Product::factory()->create(['name' => 'C', 'initial_price' => 1, 'selling_price' => 7, 'stock' => 100]);

    [$mapped, $totals] = hookCompare([[$a, 1], [$b, 3], [$c, 3]], 11.0);

    expect($mapped['tax_price'])->toBe($totals['tax_price']);
    expect($mapped['total_price'])->toBe($totals['total_price']);
});

test('paying exactly the amount due at 11 percent is never rejected by the API', function (float $price, int $qty) {
    $product = Product::factory()->create(['name' => 'Bayar Pas', 'initial_price' => 1, 'selling_price' => $price, 'stock' => 100]);
    [, $totals] = hookCompare([[$product, $qty]], 11.0);

    $response = actingAs(User::first())->postJson('/api/transaction/selling', [
        'payed_money' => $totals['amount_due'],
        'friend_price' => false,
        'tax' => 11,
        'products' => [['product_id' => $product->id, 'qty' => $qty]],
    ]);

    $response->assertOk();
    $sale = Selling::latest('id')->first();
    expect(Money::round($sale->total_price))->toBe($totals['total_price']);
    expect(Money::round($sale->money_changes))->toBe(0.0);
})->with([
    'one unit' => [10001, 1],
    'three units' => [5555, 3],
    'seven' => [7, 7],
    'big odd' => [33333, 3],
    'larger odd' => [100001, 9],
]);

test('money_changes is rounded to two decimals', function () {
    $service = new SellingService;

    $mapped = $service->mapProductRequest([
        'payed_money' => 30000.123,
        'friend_price' => false,
        'tax' => 0,
        'products' => [['product_id' => $this->product->id, 'qty' => 1]],
    ]);

    expect($mapped['money_changes'])->toBe(5000.12);
});

test('a credit method never gives change', function () {
    $credit = PaymentMethod::create(['name' => 'Kredit', 'is_cash' => false, 'is_credit' => true, 'is_wallet' => false, 'is_debit' => false, 'icon' => 'x']);
    $service = new SellingService;

    $mapped = $service->mapProductRequest([
        'payed_money' => 90000,
        'friend_price' => false,
        'payment_method_id' => $credit->id,
        'products' => [['product_id' => $this->product->id, 'qty' => 1]],
    ]);

    expect($mapped['money_changes'])->toEqual(0);
});

test('price-unit lines are priced from the unit', function () {
    $unit = PriceUnit::create(['product_id' => $this->product->id, 'unit' => 'box', 'stock' => 10, 'selling_price' => 200000]);
    $service = new SellingService;

    $mapped = $service->mapProductRequest([
        'payed_money' => 200000,
        'friend_price' => false,
        'products' => [['product_id' => $this->product->id, 'qty' => 1, 'price_unit_id' => $unit->id]],
    ]);

    expect($mapped['total_price'])->toBe(200000.0);
});

test('a price-unit sale sells the unit stock and prices the line from the unit', function () {
    $unit = PriceUnit::create(['product_id' => $this->product->id, 'unit' => 'box', 'stock' => 4, 'selling_price' => 90000]);

    $sale = hookSale($this->product, [
        'payed_money' => 90000,
        'products' => [['product_id' => $this->product->id, 'qty' => 1, 'price_unit_id' => $unit->id]],
    ], ['verify_stock' => true]);

    expect(hookStockOf($this->product))->toBe(6.0);
    expect((float) $sale->sellingDetails->first()->price)->toBe(90000.0);
    expect((float) $sale->sellingDetails->first()->qty)->toBe(4.0);
});

test('price-unit lines whose unit price has more than two decimals total the same in both calculations', function (float $unitPrice, int $qty, int $otherQty) {
    $product = Product::factory()->create(['name' => 'Satuan Pecahan', 'initial_price' => 1, 'selling_price' => 1000, 'stock' => 1000]);
    $unit = PriceUnit::create(['product_id' => $product->id, 'unit' => 'pak', 'stock' => 1, 'selling_price' => $unitPrice]);
    $other = Product::factory()->create(['name' => 'Pendamping', 'initial_price' => 1, 'selling_price' => 3333.33, 'stock' => 1000]);
    $service = new SellingService;

    $mapped = $service->mapProductRequest([
        'payed_money' => 0,
        'friend_price' => false,
        'tax' => 11,
        'products' => [
            ['product_id' => $product->id, 'qty' => $qty, 'price_unit_id' => $unit->id],
            ['product_id' => $other->id, 'qty' => $otherQty],
        ],
    ]);

    $line = new App\Models\Tenants\OpenBillItem;
    $line->forceFill(['qty' => $qty, 'price' => 0, 'discount_price' => 0, 'price_unit_id' => $unit->id]);
    $line->setRelation('priceUnit', $unit);
    $second = new App\Models\Tenants\OpenBillItem;
    $second->forceFill(['qty' => $otherQty, 'price' => $other->selling_price * $otherQty, 'discount_price' => 0, 'price_unit_id' => null]);
    $totals = OrderTotals::compute(collect([$line, $second]), 11.0);

    expect($mapped['total_price'])->toBe($totals['total_price']);
    expect($mapped['tax_price'])->toBe($totals['tax_price']);
})->with([
    'whole prices' => [1500.0, 3, 1],
    'thirds' => [3333.33, 3, 3],
    'half a cent' => [33.335, 3, 1],
    'sub-cent unit' => [0.333, 7, 3],
]);
