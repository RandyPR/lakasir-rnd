<?php

use App\Enums\OpenBillStatus;
use App\Exceptions\OpenBill\BillClosedException;
use App\Exceptions\OpenBill\BillConflictException;
use App\Exceptions\OpenBill\BillEmptyException;
use App\Exceptions\OpenBill\BillForbiddenException;
use App\Exceptions\OpenBill\BillReasonRequiredException;
use App\Models\Tenants\About;
use App\Models\Tenants\CartItem;
use App\Models\Tenants\Category;
use App\Models\Tenants\OpenBill;
use App\Models\Tenants\OpenBillEvent;
use App\Models\Tenants\OpenBillItem;
use App\Models\Tenants\PaymentMethod;
use App\Models\Tenants\Product;
use App\Models\Tenants\Setting;
use App\Models\Tenants\Table;
use App\Models\Tenants\User;
use App\Services\Tenants\OpenBillService;
use App\Services\Tenants\SellingService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;

uses(RefreshDatabaseWithTenant::class);

/*
|--------------------------------------------------------------------------
| helpers (shared by the OpenBill*Test files; every one is guarded so a file runs alone)
|--------------------------------------------------------------------------
*/

if (! function_exists('obSvc')) {
    function obSvc(): OpenBillService
    {
        return app(OpenBillService::class);
    }
}

if (! function_exists('obProduct')) {
    function obProduct(string $name, float $price, float $stock = 50): Product
    {
        return Product::factory()->create([
            'name' => $name,
            'initial_price' => $price / 2,
            'selling_price' => $price,
            'stock' => $stock,
            'category_id' => Category::first()->id,
        ]);
    }
}

if (! function_exists('obUser')) {
    /** A non-owner cashier holding exactly these permissions. */
    function obUser(array $permissions = ['create selling']): User
    {
        $user = User::create([
            'email' => 'ob'.uniqid().'@example.test',
            'password' => bcrypt('x'),
            'name' => 'Kasir '.uniqid(),
            'is_owner' => false,
        ]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }
}

if (! function_exists('obCart')) {
    /** @param  list<array{0:Product,1:float|int}>  $lines */
    function obCart(User $user, array $lines): void
    {
        foreach ($lines as [$product, $qty]) {
            CartItem::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'qty' => $qty,
                'price' => $product->selling_price * $qty,
            ]);
        }
    }
}

if (! function_exists('obBill')) {
    /** A bill made from a private cart in one call. */
    function obBill(User $user, array $lines, array $header = []): OpenBill
    {
        obCart($user, $lines);

        return obSvc()->createFromCart($user, $header);
    }
}

if (! function_exists('obSale')) {
    /** A paid sale through the real sale service (direct sale, no bill). */
    function obSale(Product $product, array $extra = []): App\Models\Tenants\Selling
    {
        $service = new SellingService;
        $data = array_merge([
            'payed_money' => 100000,
            'friend_price' => false,
            'products' => [['product_id' => $product->id, 'qty' => 1]],
        ], $extra);

        return $service->create(array_merge($data, $service->mapProductRequest($data)));
    }
}

if (! function_exists('obActions')) {
    /** @return list<string> */
    function obActions(OpenBill|int $bill): array
    {
        return OpenBillEvent::where('open_bill_id', $bill instanceof OpenBill ? $bill->id : $bill)
            ->orderBy('id')->pluck('action')->all();
    }
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 05:00:00', 'UTC')); // 12:00 WIB
    Setting::set('selling_method', 'fifo');
    Setting::set('default_tax', 0);
    Cache::clear();
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('tenant'));
    $this->owner = User::first();
    actingAs($this->owner);
    $this->nasi = obProduct('Nasi Goreng', 25000);
    $this->teh = obProduct('Es Teh', 5000);
    $this->soto = obProduct('Soto', 20000);
});

afterEach(function () {
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| createFromCart
|--------------------------------------------------------------------------
*/

test('createFromCart moves the private cart into a numbered bill and empties the cart', function () {
    $table = Table::create(['number' => '5']);

    $bill = obBill($this->owner, [[$this->nasi, 2], [$this->teh, 1]], [
        'customer_name' => 'Budi', 'table_id' => $table->id, 'note' => 'cepat',
    ]);

    expect($bill->number)->toBe(1)
        ->and($bill->status)->toBe(OpenBillStatus::Open)
        ->and($bill->version)->toBe(1)
        ->and($bill->customer_name)->toBe('Budi')
        ->and($bill->note)->toBe('cepat')
        ->and($bill->created_by)->toBe($this->owner->id)
        ->and($bill->kitchen_batch_count)->toBe(0)
        ->and($bill->items)->toHaveCount(2)
        ->and(CartItem::where('user_id', $this->owner->id)->count())->toBe(0)
        ->and(DB::table('open_bills')->value('business_date'))->toBe('2026-10-15');

    $nasi = $bill->items->firstWhere('product_id', $this->nasi->id);
    expect($nasi->qty)->toBe(2.0)->and($nasi->price)->toBe(50000.0)->and($nasi->sent_qty)->toBe(0.0);
    expect(obActions($bill))->toBe(['created']);
});

test('createFromCart only takes the cart of the user who saves', function () {
    $other = obUser();
    obCart($other, [[$this->soto, 3]]);

    $bill = obBill($this->owner, [[$this->nasi, 1]]);

    expect($bill->items)->toHaveCount(1)
        ->and(CartItem::where('user_id', $other->id)->count())->toBe(1);
});

test('createFromCart refuses an empty cart and consumes no bill number', function () {
    expect(fn () => obSvc()->createFromCart($this->owner, []))->toThrow(BillEmptyException::class);

    expect(OpenBill::count())->toBe(0);

    // the failed attempt left no gap: the next bill is number 1
    expect(obBill($this->owner, [[$this->nasi, 1]])->number)->toBe(1);
});

test('createFromCart merges duplicate product rows of the cart', function () {
    CartItem::create(['user_id' => $this->owner->id, 'product_id' => $this->nasi->id, 'qty' => 1, 'price' => 25000, 'note' => 'pedas']);
    CartItem::create(['user_id' => $this->owner->id, 'product_id' => $this->nasi->id, 'qty' => 2, 'price' => 50000]);

    $bill = obSvc()->createFromCart($this->owner, []);

    expect($bill->items)->toHaveCount(1);
    $line = $bill->items->first();
    expect($line->qty)->toBe(3.0)->and($line->price)->toBe(75000.0)->and($line->note)->toBe('pedas');
});

test('bill numbers share one sequence with sales of the same business day', function () {
    obSale($this->teh);                                        // sale 1
    $bill = obBill($this->owner, [[$this->nasi, 1]]);          // bill 2
    $second = obBill($this->owner, [[$this->soto, 1]]);        // bill 3
    $sale = obSale($this->teh);                                // sale 4

    expect($bill->number)->toBe(2)
        ->and($second->number)->toBe(3)
        ->and($sale->daily_order_number)->toBe(4);
});

test('two bills created one after the other never share a number', function () {
    $numbers = [];
    for ($i = 0; $i < 5; $i++) {
        $numbers[] = obBill($this->owner, [[$this->nasi, 1]])->number;
    }

    expect($numbers)->toBe([1, 2, 3, 4, 5]);
});

test('a customer name already used today is numbered, counting open bills and sales', function () {
    $first = obBill($this->owner, [[$this->nasi, 1]], ['customer_name' => 'Budi']);
    $second = obBill($this->owner, [[$this->nasi, 1]], ['customer_name' => 'budi']);
    obSale($this->teh, ['customer_name' => 'Budi']);              // a paid sale named Budi counts too
    $third = obBill($this->owner, [[$this->nasi, 1]], ['customer_name' => 'Budi']);

    expect($first->customer_name)->toBe('Budi')
        ->and($second->customer_name)->toBe('budi (2)')
        ->and($third->customer_name)->toBe('Budi (4)');
});

test('a voided bill no longer blocks its customer name', function () {
    $first = obBill($this->owner, [[$this->nasi, 1]], ['customer_name' => 'Ani']);
    obSvc()->void($first, $this->owner, 'salah input', $first->version);

    $again = obBill($this->owner, [[$this->nasi, 1]], ['customer_name' => 'Ani']);

    expect($again->customer_name)->toBe('Ani');
});

test('the database refuses two bills with the same business date and number', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);

    expect(fn () => OpenBill::create([
        'number' => $bill->number,
        'business_date' => '2026-10-15',
        'status' => 'open',
    ]))->toThrow(UniqueConstraintViolationException::class);

    // another day may reuse the number
    expect(OpenBill::create(['number' => $bill->number, 'business_date' => '2026-10-16', 'status' => 'open']))->toBeInstanceOf(OpenBill::class);
});

test('the bill freezes the tax percent when it is created', function () {
    Setting::set('default_tax', 10);
    $bill = obBill($this->owner, [[$this->nasi, 2]]);

    Setting::set('default_tax', 25);
    Cache::clear();

    $fresh = obSvc()->find($bill->id);
    expect($fresh->tax_percent)->toBe(10.0);
    $totals = obSvc()->totals($fresh);
    expect($totals['tax_price'])->toBe(5000.0)->and($totals['amount_due'])->toBe(55000.0);
});

test('the bill belongs to the business day in the business timezone, not the UTC date', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 17:30:00', 'UTC')); // 00:30 WIB on the 16th

    $bill = obBill($this->owner, [[$this->nasi, 1]]);

    expect($bill->business_date->format('Y-m-d'))->toBe('2026-10-16')->and($bill->number)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| mutate and the line operations
|--------------------------------------------------------------------------
*/

test('mutate raises the version once per change and not for a no-op', function () {
    $bill = obBill($this->owner, [[$this->nasi, 2]]);
    $item = $bill->items->first();

    obSvc()->addLine($bill->id, $this->teh, 1, null, null, $this->owner);
    expect(OpenBill::find($bill->id)->version)->toBe(2);

    obSvc()->setLineQty($bill->id, $item->id, 3, $this->owner);
    expect(OpenBill::find($bill->id)->version)->toBe(3);

    // nothing changes: the same quantity, the same note
    obSvc()->setLineQty($bill->id, $item->id, 3, $this->owner);
    obSvc()->setLineNote($bill->id, $item->id, null, $this->owner);
    expect(OpenBill::find($bill->id)->version)->toBe(3);
});

test('mutate refuses a paid, voided or missing bill', function () {
    $paid = obBill($this->owner, [[$this->nasi, 1]]);
    $cash = PaymentMethod::where('name', 'Cash')->first();
    $result = obSvc()->pay($paid, ['payment_method_id' => $cash->id], $paid->version, $this->owner);

    try {
        obSvc()->addLine($paid->id, $this->teh, 1, null, null, $this->owner);
        $this->fail('a paid bill must not be edited');
    } catch (BillClosedException $e) {
        expect($e->isPaid())->toBeTrue()->and($e->sellingId)->toBe($result->selling->id);
    }

    $void = obBill($this->owner, [[$this->nasi, 1]]);
    obSvc()->void($void, $this->owner, 'salah input', $void->version);
    try {
        obSvc()->setLineQty($void->id, $void->items->first()->id, 5, $this->owner);
        $this->fail('a void bill must not be edited');
    } catch (BillClosedException $e) {
        expect($e->isPaid())->toBeFalse()->and($e->status)->toBe(OpenBillStatus::Void);
    }

    expect(fn () => obSvc()->mutate(999999, fn () => null))->toThrow(BillClosedException::class);
    expect(fn () => obSvc()->find(999999))->toThrow(BillClosedException::class);
});

test('a line change is priced at the order-time price and only the difference at today\'s price', function () {
    $bill = obBill($this->owner, [[$this->nasi, 2]]);
    $this->nasi->update(['selling_price' => 30000]);
    $line = $bill->items->first();

    obSvc()->addLine($bill->id, $this->nasi->fresh(), 1, null, null, $this->owner);
    expect($line->fresh()->qty)->toBe(3.0)->and($line->fresh()->price)->toBe(80000.0); // 50.000 + 30.000

    obSvc()->setLineQty($bill->id, $line->id, 2, $this->owner);
    expect($line->fresh()->price)->toBe(53333.33);
});

test('line note and line discount are cleaned and bounded', function () {
    $bill = obBill($this->owner, [[$this->nasi, 2]]);
    $line = $bill->items->first();

    obSvc()->setLineNote($bill->id, $line->id, "  tanpa\x1b telur ", $this->owner);
    expect($line->fresh()->note)->toBe('tanpa telur');

    obSvc()->setLineDiscount($bill->id, $line->id, 1000, $this->owner);
    expect($line->fresh()->discount_price)->toBe(1000.0);

    obSvc()->setLineDiscount($bill->id, $line->id, 99999999, $this->owner);
    expect($line->fresh()->discount_price)->toBe(50000.0);          // never above the line total

    obSvc()->setLineDiscount($bill->id, $line->id, null, $this->owner);
    expect($line->fresh()->discount_price)->toBeNull();
});

test('adding a product that is already in the bill with another price unit is refused', function () {
    $unit = App\Models\Tenants\PriceUnit::factory()->create(['product_id' => $this->nasi->id, 'selling_price' => 100000, 'stock' => 5]);
    $other = App\Models\Tenants\PriceUnit::factory()->create(['product_id' => $this->nasi->id, 'selling_price' => 1, 'stock' => 1]);
    $bill = obBill($this->owner, [[$this->teh, 1]]);

    $line = obSvc()->addLine($bill->id, $this->nasi, 2, $unit->id, null, $this->owner);
    expect($line->price)->toBe(200000.0);

    expect(fn () => obSvc()->addLine($bill->id, $this->nasi, 1, $other->id, null, $this->owner))
        ->toThrow(Illuminate\Validation\ValidationException::class);
    expect(OpenBillItem::where('open_bill_id', $bill->id)->where('product_id', $this->nasi->id)->count())->toBe(1);
});

test('a bill line is unique per product', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);

    expect(fn () => OpenBillItem::create([
        'open_bill_id' => $bill->id, 'product_id' => $this->nasi->id, 'qty' => 1, 'price' => 25000,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

/*
|--------------------------------------------------------------------------
| updateHeader
|--------------------------------------------------------------------------
*/

test('updateHeader refuses a stale version and writes nothing', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]], ['customer_name' => 'Andi']);
    $events = OpenBillEvent::count();

    try {
        obSvc()->updateHeader($bill, ['customer_name' => 'Budi'], $bill->version + 1, $this->owner);
        $this->fail('expected a conflict');
    } catch (BillConflictException $e) {
        expect($e->serverVersion)->toBe(1);
    }

    $fresh = OpenBill::find($bill->id);
    expect($fresh->customer_name)->toBe('Andi')->and($fresh->version)->toBe(1)->and(OpenBillEvent::count())->toBe($events);
});

test('updateHeader numbers a renamed customer again and ignores the bill itself', function () {
    obBill($this->owner, [[$this->nasi, 1]], ['customer_name' => 'Budi']);
    $bill = obBill($this->owner, [[$this->nasi, 1]], ['customer_name' => 'Andi']);

    $bill = obSvc()->updateHeader($bill, ['customer_name' => 'Budi', 'note' => 'x'], $bill->version, $this->owner);
    expect($bill->customer_name)->toBe('Budi (2)')->and($bill->note)->toBe('x')->and($bill->version)->toBe(2);

    // the same value again: no new "(3)", no version bump
    $same = obSvc()->updateHeader($bill, ['customer_name' => 'Budi (2)'], $bill->version, $this->owner);
    expect($same->customer_name)->toBe('Budi (2)')->and($same->version)->toBe(2);

    $cleared = obSvc()->updateHeader($same, ['customer_name' => null, 'discount_price' => 2500, 'member_id' => null], 2, $this->owner);
    expect($cleared->customer_name)->toBeNull()->and($cleared->discount_price)->toBe(2500.0)->and($cleared->version)->toBe(3);
});

test('updateHeader writes one header event per real change with before and after', function () {
    $table = Table::create(['number' => '7']);
    $bill = obBill($this->owner, [[$this->nasi, 1]]);

    $bill = obSvc()->updateHeader($bill, ['table_id' => $table->id, 'note' => 'cepat'], $bill->version, $this->owner);

    $event = OpenBillEvent::where('open_bill_id', $bill->id)->where('action', 'header')->sole();
    expect($event->user_id)->toBe($this->owner->id)
        ->and($event->detail['changes']['table_id'])->toBe([null, $table->id])
        ->and($event->detail['changes']['note'])->toBe([null, 'cepat']);

    obSvc()->updateHeader($bill, ['table_id' => $table->id, 'note' => 'cepat'], $bill->version, $this->owner);
    expect(OpenBillEvent::where('open_bill_id', $bill->id)->where('action', 'header')->count())->toBe(1);
});

test('only a rename takes the sales lock; table, note and discount edits do not', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]], ['customer_name' => 'Budi']);
    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = strtolower($query->sql);
    });

    $lockQueries = function () use (&$statements): int {
        return count(array_filter($statements, fn ($sql) => str_contains($sql, 'numbering_locks')));
    };

    $bill = obSvc()->updateHeader($bill, ['note' => 'tanpa kuah', 'discount_price' => 1000], $bill->version, $this->owner);
    expect($lockQueries())->toBe(0);

    obSvc()->updateHeader($bill, ['customer_name' => 'Candra'], $bill->version, $this->owner);
    expect($lockQueries())->toBeGreaterThan(0);

    // R1: the sales lock is taken before the bill row is written
    $firstLock = collect($statements)->search(fn ($sql) => str_contains($sql, 'numbering_locks'));
    $firstBillWrite = collect($statements)->search(fn ($sql, $i) => $i > 0 && str_starts_with($sql, 'update "open_bills"')
        && $i > $firstLock);
    expect($firstLock)->not->toBeFalse()->and($firstBillWrite)->not->toBeFalse()->and($firstLock)->toBeLessThan($firstBillWrite);
});

test('a header write on a paid bill is refused', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);
    $cash = PaymentMethod::where('name', 'Cash')->first();
    obSvc()->pay($bill, ['payment_method_id' => $cash->id], $bill->version, $this->owner);

    expect(fn () => obSvc()->updateHeader($bill, ['note' => 'late'], $bill->version, $this->owner))
        ->toThrow(BillClosedException::class);
});

/*
|--------------------------------------------------------------------------
| listOpen: a constant number of queries
|--------------------------------------------------------------------------
*/

test('listOpen runs the same few queries for 5 and for 30 bills', function () {
    About::query()->update(['business_type' => 'fnb']);
    $table = Table::create(['number' => '9']);
    $makeBills = function (int $count) use ($table): void {
        for ($i = 0; $i < $count; $i++) {
            $bill = obBill($this->owner, [[$this->nasi, 1 + $i], [$this->teh, 1]], [
                'table_id' => $i % 2 ? $table->id : null,
                'customer_name' => $i % 2 ? null : 'Tamu '.$i.uniqid(),
            ]);
            if ($i % 5 === 0) {
                obSvc()->ackKitchenBatch($bill->id, obSvc()->kitchenBatch($bill)['ack'], $this->owner);
                obSvc()->addLine($bill->id, $this->soto, 1, null, null, $this->owner);
            }
        }
    };

    $measure = function (): array {
        obSvc()->listOpen($this->owner);                       // warm the permission cache
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = obSvc()->listOpen($this->owner)->all();
        $log = DB::getQueryLog();
        DB::disableQueryLog();
        $domain = array_filter($log, fn ($q) => preg_match('/open_bill|abouts|"tables"|"users"|price_units|"products"/', $q['query']));

        return [count($rows), count($log), count($domain)];
    };

    $makeBills(5);
    [$rows5, $all5, $domain5] = $measure();
    $makeBills(25);
    [$rows30, $all30, $domain30] = $measure();

    expect($rows5)->toBe(5)->and($rows30)->toBe(30)
        ->and($all30)->toBe($all5)
        ->and($domain30)->toBeLessThanOrEqual(3);
});

test('listOpen rows carry the fields the bill list needs', function () {
    About::query()->update(['business_type' => 'fnb']);
    $table = Table::create(['number' => '9']);
    $unsent = obBill($this->owner, [[$this->nasi, 2], [$this->teh, 1]], ['customer_name' => 'Tamu']);
    $onTable = obBill($this->owner, [[$this->nasi, 1], [$this->teh, 1]], ['table_id' => $table->id]);
    obSvc()->ackKitchenBatch($onTable->id, obSvc()->kitchenBatch($onTable)['ack'], $this->owner);
    obSvc()->addLine($onTable->id, $this->soto, 1, null, null, $this->owner);

    $rows = obSvc()->listOpen($this->owner)->keyBy('number');

    $a = $rows[$unsent->number];
    expect($a['label'])->toBe('Tamu')->and($a['sent'])->toBeFalse()->and($a['unsent'])->toBeTrue()
        ->and($a['items_count'])->toBe(2)->and($a['qty_total'])->toBe(3.0)->and($a['amount_due'])->toBe(55000.0)
        ->and($a['table_label'])->toBeNull()->and($a['can_void'])->toBeTrue()->and($a['version'])->toBe(1);

    $b = $rows[$onTable->number];
    expect($b['sent'])->toBeTrue()->and($b['unsent'])->toBeTrue()->and($b['unsent_count'])->toBe(1)
        ->and($b['table_label'])->toBe('9')->and($b['label'])->toContain('9');
});

test('listOpen lists only open bills, can narrow to one cashier and needs the use permission', function () {
    $mine = obBill($this->owner, [[$this->nasi, 1]]);
    $other = obUser();
    $theirs = obBill($other, [[$this->nasi, 1]]);
    $void = obBill($this->owner, [[$this->nasi, 1]]);
    obSvc()->void($void, $this->owner, 'salah input', $void->version);

    expect(obSvc()->listOpen($this->owner)->pluck('id')->sort()->values()->all())->toBe([$mine->id, $theirs->id])
        ->and(obSvc()->listOpen($this->owner, $other->id)->pluck('id')->all())->toBe([$theirs->id])
        ->and(obSvc()->listOpen($this->owner, 999999))->toHaveCount(0);

    expect(fn () => obSvc()->listOpen(obUser([])))->toThrow(BillForbiddenException::class);
});

/*
|--------------------------------------------------------------------------
| void
|--------------------------------------------------------------------------
*/

test('void needs a reason of at least three characters, for every bill', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);

    foreach (['', '  ', 'ab'] as $reason) {
        expect(fn () => obSvc()->void($bill, $this->owner, $reason, $bill->version))
            ->toThrow(BillReasonRequiredException::class);
    }

    expect(OpenBill::find($bill->id)->status)->toBe(OpenBillStatus::Open);
    expect(obSvc()->void($bill, $this->owner, 'Pelanggan batal', $bill->version))->toBeNull();

    $void = OpenBill::find($bill->id);
    expect($void->status)->toBe(OpenBillStatus::Void)
        ->and($void->void_reason)->toBe('Pelanggan batal')
        ->and($void->voided_by)->toBe($this->owner->id)
        ->and($void->voided_at)->not->toBeNull()
        ->and($void->version)->toBe(2);
});

test('void keeps the lines of the bill', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1], [$this->teh, 2]]);

    obSvc()->void($bill, $this->owner, 'salah input', $bill->version);

    expect(OpenBillItem::where('open_bill_id', $bill->id)->count())->toBe(2);
});

test('void refuses a stale version and writes nothing', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);

    expect(fn () => obSvc()->void($bill, $this->owner, 'salah input', 99))->toThrow(BillConflictException::class);

    expect(OpenBill::find($bill->id)->status)->toBe(OpenBillStatus::Open)->and(obActions($bill))->toBe(['created']);
});

test('who may void a bill that was never sent: the creator, update selling or delete selling', function () {
    $creator = obUser();
    $stranger = obUser();
    $updater = obUser(['create selling', 'update selling']);
    $deleter = obUser(['create selling', 'delete selling']);

    $bill = obBill($creator, [[$this->nasi, 1]]);
    expect(fn () => obSvc()->void($bill, $stranger, 'salah input', $bill->version))->toThrow(BillForbiddenException::class);
    expect(OpenBill::find($bill->id)->status)->toBe(OpenBillStatus::Open);

    foreach ([$creator, $updater, $deleter, $this->owner] as $voider) {
        $candidate = obBill($creator, [[$this->nasi, 1]]);
        obSvc()->void($candidate, $voider, 'salah input', $candidate->version);
        expect(OpenBill::find($candidate->id)->voided_by)->toBe($voider->id);
    }
});

test('who may void a bill the kitchen received: the creator for 10 minutes, then only update or delete selling', function () {
    $creator = obUser();
    $updater = obUser(['create selling', 'update selling']);
    $bill = obBill($creator, [[$this->nasi, 1], [$this->teh, 2]]);
    obSvc()->ackKitchenBatch($bill->id, obSvc()->kitchenBatch($bill)['ack'], $creator);

    // inside the window the creator may
    $fresh = OpenBill::find($bill->id);
    Carbon::setTestNow(Carbon::now()->addMinutes(9));
    $other = obBill($creator, [[$this->nasi, 1]]);
    obSvc()->ackKitchenBatch($other->id, obSvc()->kitchenBatch($other)['ack'], $creator);
    $other = OpenBill::find($other->id);
    obSvc()->void($other, $creator, 'pelanggan pergi', $other->version);
    expect(OpenBill::find($other->id)->status)->toBe(OpenBillStatus::Void);

    // outside it the creator is refused, and nothing was written
    Carbon::setTestNow(Carbon::now()->addMinutes(11));
    $before = OpenBillEvent::count();
    expect(fn () => obSvc()->void($fresh, $creator, 'pelanggan pergi', $fresh->version))->toThrow(BillForbiddenException::class);
    expect(OpenBill::find($bill->id)->status)->toBe(OpenBillStatus::Open)->and(OpenBillEvent::count())->toBe($before);

    // a manager still may, and gets the ticket that tells the kitchen to stop
    $ticket = obSvc()->void($fresh, $updater, 'pelanggan pergi', $fresh->version);
    expect($ticket['is_void'])->toBeTrue()->and($ticket['kind'])->toBe('void')
        ->and($ticket['cancelled'])->toHaveCount(2)->and($ticket['void_reason'])->toBe('pelanggan pergi');
});

test('the void decision is made on the locked bill, not on the copy the creator holds', function () {
    $creator = obUser();
    $bill = obBill($creator, [[$this->nasi, 1]]);
    $stale = OpenBill::find($bill->id);                       // this copy says: never sent

    // another device sends it to the kitchen, and the grace period passes
    obSvc()->ackKitchenBatch($bill->id, obSvc()->kitchenBatch($bill)['ack'], $this->owner);
    Carbon::setTestNow(Carbon::now()->addMinutes(30));

    $current = OpenBill::find($bill->id);
    expect($stale->kitchen_batch_count)->toBe(0);
    expect(fn () => obSvc()->void($stale, $creator, 'pelanggan pergi', $current->version))
        ->toThrow(BillForbiddenException::class);
    expect(OpenBill::find($bill->id)->status)->toBe(OpenBillStatus::Open);
});

test('a bill that is already closed cannot be voided again', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);
    obSvc()->void($bill, $this->owner, 'salah input', $bill->version);

    expect(fn () => obSvc()->void($bill, $this->owner, 'sekali lagi', 2))->toThrow(BillClosedException::class);
});

test('a sent line can only be reduced by someone who may cancel sent items, with a reason', function () {
    $creator = obUser();
    $stranger = obUser();
    $bill = obBill($creator, [[$this->nasi, 3]]);
    obSvc()->ackKitchenBatch($bill->id, obSvc()->kitchenBatch($bill)['ack'], $creator);
    $line = OpenBillItem::where('open_bill_id', $bill->id)->first();

    expect(fn () => obSvc()->setLineQty($bill->id, $line->id, 1, $stranger, 'salah input'))->toThrow(BillForbiddenException::class);
    expect(fn () => obSvc()->setLineQty($bill->id, $line->id, 1, $creator, null))->toThrow(BillReasonRequiredException::class);
    expect($line->fresh()->qty)->toBe(3.0);

    obSvc()->setLineQty($bill->id, $line->id, 2, $creator, 'salah input');       // creator, inside the window
    expect($line->fresh()->qty)->toBe(2.0)->and($line->fresh()->sent_qty)->toBe(3.0);

    Carbon::setTestNow(Carbon::now()->addMinutes(11));
    expect(fn () => obSvc()->setLineQty($bill->id, $line->id, 1, $creator, 'salah input'))->toThrow(BillForbiddenException::class);
    expect(fn () => obSvc()->setLineQty($bill->id, $line->id, 0, $creator, 'salah input'))->toThrow(BillForbiddenException::class);
    expect(OpenBillItem::find($line->id))->not->toBeNull();
});

test('a line the kitchen never received can be removed without a reason', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1], [$this->teh, 1]]);
    $line = $bill->items->firstWhere('product_id', $this->teh->id);

    obSvc()->setLineQty($bill->id, $line->id, 0, obUser());

    expect(OpenBillItem::find($line->id))->toBeNull()->and(OpenBill::find($bill->id)->pending_cancel)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| totals
|--------------------------------------------------------------------------
*/

test('totals use the frozen tax, the header discount and the line discounts', function () {
    Setting::set('default_tax', 11);
    $bill = obBill($this->owner, [[$this->nasi, 2], [$this->teh, 1]], ['discount_price' => 1000]);
    obSvc()->setLineDiscount($bill->id, $bill->items->firstWhere('product_id', $this->teh->id)->id, 500, $this->owner);

    $totals = obSvc()->totals(obSvc()->find($bill->id));

    // 55.000 + 11% = 61.050 ; minus 500 and 1.000
    expect($totals['subtotal'])->toBe(55000.0)->and($totals['tax_price'])->toBe(6050.0)
        ->and($totals['total_price'])->toBe(61050.0)->and($totals['amount_due'])->toBe(59550.0);
});
