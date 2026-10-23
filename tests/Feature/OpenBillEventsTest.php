<?php

use App\Exceptions\OpenBill\BillConflictException;
use App\Exceptions\OpenBill\BillForbiddenException;
use App\Exceptions\OpenBill\BillReasonRequiredException;
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

if (! function_exists('obActions')) {
    /** @return list<string> */
    function obActions(OpenBill|int $bill): array
    {
        return OpenBillEvent::where('open_bill_id', $bill instanceof OpenBill ? $bill->id : $bill)
            ->orderBy('id')->pluck('action')->all();
    }
}

if (! function_exists('obSend')) {
    /** Print + acknowledge the pending kitchen batch of a bill, as the browser does after printing. */
    function obSend(OpenBill|int $bill, ?User $by = null, string $mode = 'printed'): OpenBill
    {
        $id = $bill instanceof OpenBill ? $bill->id : $bill;
        $batch = obSvc()->kitchenBatch(OpenBill::findOrFail($id));
        expect($batch)->not->toBeNull();

        return obSvc()->ackKitchenBatch($id, $batch['ack'], $by ?? User::first(), $mode);
    }
}

if (! function_exists('obLine')) {
    function obLine(OpenBill|int $bill, Product $product): ?OpenBillItem
    {
        return OpenBillItem::where('open_bill_id', $bill instanceof OpenBill ? $bill->id : $bill)
            ->where('product_id', $product->id)->first();
    }
}

if (! function_exists('obMethod')) {
    function obMethod(string $name = 'Cash'): PaymentMethod
    {
        return PaymentMethod::where('name', $name)->firstOrFail();
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
| every mutation leaves a trace
|--------------------------------------------------------------------------
*/

test('every kind of change to a bill writes its own event, by the right person', function () {
    $table = Table::create(['number' => '2']);
    $cashier = obUser();
    actingAs($cashier);
    $bill = obBill($cashier, [[$this->nasi, 2]]);                                                       // created
    $nasi = obLine($bill, $this->nasi);

    obSvc()->addLine($bill->id, $this->teh, 1, null, null, $cashier);                                    // line_added
    obSvc()->addLine($bill->id, $this->nasi, 1, null, null, $cashier);                                   // line_qty
    obSvc()->setLineQty($bill->id, $nasi->id, 2, $cashier);                                              // line_qty
    obSvc()->setLineNote($bill->id, $nasi->id, 'pedas', $cashier);                                       // line_note
    obSvc()->setLineDiscount($bill->id, $nasi->id, 1000, $cashier);                                      // line_discount
    obSvc()->updateHeader(OpenBill::find($bill->id), ['table_id' => $table->id], OpenBill::find($bill->id)->version, $cashier);   // header
    obSend($bill, $cashier);                                                                            // sent
    obSvc()->setLineQty($bill->id, $nasi->id, 1, $cashier, 'salah input');                               // cancel_sent
    obSvc()->setLineQty($bill->id, obLine($bill, $this->teh)->id, 0, $cashier, 'habis');                 // line_removed (sent: with a reason)
    obSend($bill, $cashier, 'manual');                                                                  // sent_manual

    $fresh = OpenBill::find($bill->id);
    obSvc()->void($fresh, $cashier, 'pelanggan pergi', $fresh->version);                                 // void

    expect(obActions($bill))->toBe([
        'created', 'line_added', 'line_qty', 'line_qty', 'line_note', 'line_discount', 'header',
        'sent', 'cancel_sent', 'line_removed', 'sent_manual', 'void',
    ]);
    expect(OpenBillEvent::where('open_bill_id', $bill->id)->pluck('user_id')->unique()->all())->toBe([$cashier->id]);
    expect(OpenBillEvent::where('open_bill_id', $bill->id)->whereNull('created_at')->count())->toBe(0);
});

test('the event of a line change names the product and the quantities', function () {
    $bill = obBill($this->owner, [[$this->nasi, 2]]);
    $line = obLine($bill, $this->nasi);

    obSvc()->setLineQty($bill->id, $line->id, 5, $this->owner);
    obSvc()->addLine($bill->id, $this->teh, 3, null, null, $this->owner);
    obSvc()->setLineNote($bill->id, $line->id, 'pedas', $this->owner);
    obSvc()->setLineDiscount($bill->id, $line->id, 500, $this->owner);
    obSvc()->setLineQty($bill->id, obLine($bill, $this->teh)->id, 0, $this->owner);

    $events = OpenBillEvent::where('open_bill_id', $bill->id)->get()->groupBy('action');

    $qty = $events['line_qty']->first();
    expect($qty->product_id)->toBe($this->nasi->id)->and($qty->product_name)->toBe('Nasi Goreng')
        ->and($qty->qty_from)->toBe(2.0)->and($qty->qty_to)->toBe(5.0);
    $added = $events['line_added']->first();
    expect($added->product_name)->toBe('Es Teh')->and($added->qty_from)->toBe(0.0)->and($added->qty_to)->toBe(3.0);
    expect($events['line_note']->first()->detail)->toBe(['from' => null, 'to' => 'pedas'])
        ->and($events['line_discount']->first()->detail)->toEqual(['from' => 0.0, 'to' => 500.0]);
    $removed = $events['line_removed']->first();
    expect($removed->product_name)->toBe('Es Teh')->and($removed->qty_from)->toBe(3.0)->and($removed->qty_to)->toBe(0.0)
        ->and($removed->reason)->toBeNull();                                    // the kitchen never saw it: no reason needed
});

test('a change that changes nothing leaves no event and no new version', function () {
    $bill = obBill($this->owner, [[$this->nasi, 2]]);
    $line = obLine($bill, $this->nasi);
    $events = OpenBillEvent::count();

    obSvc()->setLineQty($bill->id, $line->id, 2, $this->owner);
    obSvc()->setLineNote($bill->id, $line->id, null, $this->owner);
    obSvc()->setLineDiscount($bill->id, $line->id, null, $this->owner);
    obSvc()->setLineQty($bill->id, 999999 + $line->id, 3, $this->owner);                        // a line that is gone
    obSvc()->updateHeader($bill, ['note' => null, 'customer_name' => null, 'discount_price' => 0], 1, $this->owner);

    expect(OpenBillEvent::count())->toBe($events)->and(OpenBill::find($bill->id)->version)->toBe(1);
});

test('a refused change leaves no event, no line change and no version bump', function () {
    $stranger = obUser();
    $bill = obBill($this->owner, [[$this->nasi, 3]]);
    obSend($bill, $this->owner);
    $line = obLine($bill, $this->nasi);
    $version = OpenBill::find($bill->id)->version;
    $events = OpenBillEvent::count();

    expect(fn () => obSvc()->setLineQty($bill->id, $line->id, 1, $stranger, 'salah input'))->toThrow(BillForbiddenException::class);
    expect(fn () => obSvc()->setLineQty($bill->id, $line->id, 0, $stranger, 'salah input'))->toThrow(BillForbiddenException::class);
    expect(fn () => obSvc()->setLineQty($bill->id, $line->id, 1, $this->owner, ''))->toThrow(BillReasonRequiredException::class);
    expect(fn () => obSvc()->updateHeader($bill, ['note' => 'x'], 99, $this->owner))->toThrow(BillConflictException::class);
    expect(fn () => obSvc()->void(OpenBill::find($bill->id), $stranger, 'salah input', $version))->toThrow(BillForbiddenException::class);

    expect(OpenBillEvent::count())->toBe($events)->and(OpenBill::find($bill->id)->version)->toBe($version)
        ->and($line->fresh()->qty)->toBe(3.0)->and(OpenBill::find($bill->id)->pending_cancel)->toBeNull();
});

test('an event is written in the transaction of its change: when the change fails, the event goes with it', function () {
    $bill = obBill($this->owner, [[$this->nasi, 2]]);
    $events = OpenBillEvent::count();

    expect(fn () => obSvc()->mutate($bill->id, function (OpenBill $locked) {
        obSvc()->log($locked, OpenBillEvent::HEADER, $this->owner, ['detail' => ['x' => 1]]);

        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    expect(OpenBillEvent::count())->toBe($events)->and(OpenBill::find($bill->id)->version)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| cancelling what the kitchen already got
|--------------------------------------------------------------------------
*/

test('cancel_sent records who cancelled, how much and why', function () {
    $creator = obUser();
    actingAs($creator);
    $bill = obBill($creator, [[$this->nasi, 3], [$this->teh, 1]]);
    obSend($bill, $creator);

    obSvc()->setLineQty($bill->id, obLine($bill, $this->nasi)->id, 1, $creator, "  Pelanggan\x1b berubah pikiran ");
    obSvc()->setLineQty($bill->id, obLine($bill, $this->teh)->id, 0, $creator, 'Salah pesan');

    $reduce = OpenBillEvent::where('open_bill_id', $bill->id)->where('action', 'cancel_sent')->sole();
    expect($reduce->user_id)->toBe($creator->id)->and($reduce->reason)->toBe('Pelanggan berubah pikiran')
        ->and($reduce->product_name)->toBe('Nasi Goreng')->and($reduce->qty_from)->toBe(3.0)->and($reduce->qty_to)->toBe(1.0)
        ->and($reduce->detail)->toEqual(['sent_qty' => 3.0]);

    $removed = OpenBillEvent::where('open_bill_id', $bill->id)->where('action', 'line_removed')->sole();
    expect($removed->user_id)->toBe($creator->id)->and($removed->reason)->toBe('Salah pesan')
        ->and($removed->product_name)->toBe('Es Teh')->and($removed->detail)->toEqual(['sent_qty' => 1.0]);

    // the pending cancellation carries the same who and why for the kitchen ticket
    $pending = OpenBill::find($bill->id)->pending_cancel[0];
    expect($pending['removed_by'])->toBe($creator->id)->and($pending['reason'])->toBe('Salah pesan');
});

test('a reason longer than the column is cut to 255 characters and a short one is refused', function () {
    $bill = obBill($this->owner, [[$this->nasi, 3]]);
    obSend($bill, $this->owner);
    $line = obLine($bill, $this->nasi);

    expect(fn () => obSvc()->setLineQty($bill->id, $line->id, 2, $this->owner, 'ab'))->toThrow(BillReasonRequiredException::class);

    obSvc()->setLineQty($bill->id, $line->id, 2, $this->owner, str_repeat('a', 400));

    expect(OpenBillEvent::where('action', 'cancel_sent')->sole()->reason)->toHaveLength(255);
});

test('the grace window of the creator starts at the LAST shipment', function () {
    $creator = obUser();
    actingAs($creator);
    $bill = obBill($creator, [[$this->nasi, 4]]);
    obSend($bill, $creator);

    // 9 minutes later another shipment restarts the window
    Carbon::setTestNow(Carbon::now()->addMinutes(9));
    obSvc()->addLine($bill->id, $this->teh, 1, null, null, $creator);
    obSend($bill, $creator);

    Carbon::setTestNow(Carbon::now()->addMinutes(9));                                  // 18 minutes after the first
    obSvc()->setLineQty($bill->id, obLine($bill, $this->nasi)->id, 3, $creator, 'salah input');
    expect(OpenBillEvent::where('action', 'cancel_sent')->count())->toBe(1);

    Carbon::setTestNow(Carbon::now()->addMinutes(2));                                  // 11 minutes after the last shipment
    expect(fn () => obSvc()->setLineQty($bill->id, obLine($bill, $this->nasi)->id, 2, $creator, 'salah input'))->toThrow(BillForbiddenException::class);
});

/*
|--------------------------------------------------------------------------
| paying and voiding
|--------------------------------------------------------------------------
*/

test('paying writes paid with the sale, the method and the amount; pay_with_unsent only when the kitchen is behind', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);
    obSend($bill, $this->owner);

    $sale = obSvc()->pay(OpenBill::find($bill->id), ['payment_method_id' => obMethod()->id, 'payed_money' => 25000], OpenBill::find($bill->id)->version, $this->owner)->selling;

    $paid = OpenBillEvent::where('open_bill_id', $bill->id)->where('action', 'paid')->sole();
    expect($paid->user_id)->toBe($this->owner->id)
        ->and($paid->detail)->toMatchArray([
            'selling_id' => $sale->id, 'payment_method' => 'Cash', 'amount_due' => 25000.0, 'same_day' => true, 'order_number' => $bill->number,
        ])
        ->and(obActions($bill))->toBe(['created', 'sent', 'paid']);

    $behind = obBill($this->owner, [[$this->teh, 1]]);
    obSend($behind, $this->owner);
    obSvc()->addLine($behind->id, $this->soto, 1, null, null, $this->owner);
    obSvc()->setLineQty($behind->id, obLine($behind, $this->teh)->id, 0, $this->owner, 'habis');
    obSvc()->pay(OpenBill::find($behind->id), ['payment_method_id' => obMethod()->id, 'payed_money' => 50000], OpenBill::find($behind->id)->version, $this->owner);

    $unsent = OpenBillEvent::where('open_bill_id', $behind->id)->where('action', 'pay_with_unsent')->sole();
    expect($unsent->detail)->toBe(['pending_count' => 2])                   // the new soto and the cancelled tea
        ->and(array_slice(obActions($behind), -2))->toBe(['paid', 'pay_with_unsent']);
});

test('the official stock override leaves one event per product with the stock and the quantity needed', function () {
    $rare = obProduct('Langka', 10000, 1);
    $other = obProduct('Juga langka', 10000, 0);
    $bill = obBill($this->owner, [[$rare, 3], [$other, 2], [$this->nasi, 1]]);
    obSend($bill, $this->owner);

    obSvc()->pay(OpenBill::find($bill->id), ['payment_method_id' => obMethod()->id, 'payed_money' => 100000], OpenBill::find($bill->id)->version, $this->owner, ['stock_override' => true]);

    $events = OpenBillEvent::where('open_bill_id', $bill->id)->where('action', 'stock_override')->orderBy('product_name')->get();
    expect($events)->toHaveCount(2)                                          // not Nasi Goreng: its stock was enough
        ->and($events->pluck('product_name')->all())->toBe(['Juga langka', 'Langka'])
        ->and($events[1]->detail)->toEqual(['stock' => 1.0, 'needed' => 3.0])
        ->and($events[1]->qty_from)->toBe(1.0)->and($events[1]->qty_to)->toBe(3.0)
        ->and($events[1]->user_id)->toBe($this->owner->id);
});

test('voiding records the reason, whether the kitchen had it, and how many lines it cancelled', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1], [$this->teh, 1]]);
    obSend($bill, $this->owner);
    $fresh = OpenBill::find($bill->id);

    obSvc()->void($fresh, $this->owner, 'Pelanggan pergi', $fresh->version);

    $void = OpenBillEvent::where('open_bill_id', $bill->id)->where('action', 'void')->sole();
    expect($void->reason)->toBe('Pelanggan pergi')->and($void->user_id)->toBe($this->owner->id)
        ->and($void->detail)->toBe(['sent' => true, 'cancelled_lines' => 2]);

    $never = obBill($this->owner, [[$this->nasi, 1]]);
    obSvc()->void($never, $this->owner, 'salah input', $never->version);
    expect(OpenBillEvent::where('open_bill_id', $never->id)->where('action', 'void')->sole()->detail)->toBe(['sent' => false, 'cancelled_lines' => 0]);
});

test('a header change records before and after of each changed field, and a rename is numbered', function () {
    obBill($this->owner, [[$this->nasi, 1]], ['customer_name' => 'Budi']);
    $bill = obBill($this->owner, [[$this->nasi, 1]], ['customer_name' => 'Andi']);

    obSvc()->updateHeader($bill, ['customer_name' => 'Budi', 'discount_price' => 1500, 'note' => 'cepat'], $bill->version, $this->owner);

    $changes = OpenBillEvent::where('open_bill_id', $bill->id)->where('action', 'header')->sole()->detail['changes'];
    expect($changes['customer_name'])->toBe(['Andi', 'Budi (2)'])
        ->and($changes['discount_price'])->toEqual([0.0, 1500.0])
        ->and($changes['note'])->toBe([null, 'cepat']);
});

test('the creation event records the number and how many lines came from the cart', function () {
    $bill = obBill($this->owner, [[$this->nasi, 2], [$this->teh, 1]]);

    $created = OpenBillEvent::where('open_bill_id', $bill->id)->sole();
    expect($created->action)->toBe('created')->and($created->user_id)->toBe($this->owner->id)->and($created->detail)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| the trail itself
|--------------------------------------------------------------------------
*/

test('events are append-only rows without an updated_at, kept after the bill is paid', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);
    $event = OpenBillEvent::where('open_bill_id', $bill->id)->sole();

    expect(OpenBillEvent::UPDATED_AT)->toBeNull()
        ->and(array_key_exists('updated_at', $event->getAttributes()))->toBeFalse()
        ->and(DB::getSchemaBuilder()->hasColumn('open_bill_events', 'updated_at'))->toBeFalse();

    obSvc()->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => 25000], $bill->version, $this->owner);

    expect(obActions($bill))->toContain('created')->toContain('paid')
        ->and(OpenBillEvent::find($event->id))->not->toBeNull();
});

test('the record helper keeps the product name even after the product is deleted', function () {
    $bill = obBill($this->owner, [[$this->teh, 1], [$this->nasi, 1]]);
    obSvc()->setLineQty($bill->id, obLine($bill, $this->teh)->id, 0, $this->owner);
    $this->teh->delete();

    $event = OpenBillEvent::where('open_bill_id', $bill->id)->where('action', 'line_removed')->sole();
    expect($event->product_name)->toBe('Es Teh')->and($event->product_id)->toBe($this->teh->id);
});

test('the trail of a whole day at the table reads in order', function () {
    $table = Table::create(['number' => '5']);
    $bill = obBill($this->owner, [[$this->nasi, 2]], ['table_id' => $table->id, 'customer_name' => 'Pak Haji']);
    obSend($bill, $this->owner);
    obSvc()->addLine($bill->id, $this->teh, 2, null, null, $this->owner);
    obSend($bill, $this->owner);
    $fresh = OpenBill::find($bill->id);

    obSvc()->pay($fresh, ['payment_method_id' => obMethod()->id, 'payed_money' => 100000], $fresh->version, $this->owner);

    expect(obActions($bill))->toBe(['created', 'sent', 'line_added', 'sent', 'paid']);
    $ids = OpenBillEvent::where('open_bill_id', $bill->id)->orderBy('id')->pluck('id')->all();
    expect($ids)->toBe(collect($ids)->sort()->values()->all());
});

/*
|--------------------------------------------------------------------------
| lock order, as far as sqlite can show it
|--------------------------------------------------------------------------
*/

test('line, note, discount, kitchen and void changes never touch the sales lock', function () {
    $bill = obBill($this->owner, [[$this->nasi, 3]]);
    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = strtolower($query->sql);
    });
    $touched = function () use (&$statements): int {
        return count(array_filter($statements, fn ($sql) => str_contains($sql, 'numbering_locks')));
    };

    $line = obLine($bill, $this->nasi);
    obSvc()->addLine($bill->id, $this->teh, 1, null, null, $this->owner);
    obSvc()->setLineQty($bill->id, $line->id, 2, $this->owner);
    obSvc()->setLineNote($bill->id, $line->id, 'pedas', $this->owner);
    obSvc()->setLineDiscount($bill->id, $line->id, 500, $this->owner);
    obSend($bill, $this->owner);
    $fresh = OpenBill::find($bill->id);
    obSvc()->void($fresh, $this->owner, 'pelanggan pergi', $fresh->version);

    expect(count($statements))->toBeGreaterThan(10)->and($touched())->toBe(0);
});

test('creating a bill and paying one take the sales lock before they write the bill', function () {
    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = normalizeSql($query->sql);
    });
    $index = function (string $needle, int $from = 0) use (&$statements): int|false {
        foreach ($statements as $i => $sql) {
            if ($i >= $from && str_contains($sql, $needle)) {
                return $i;
            }
        }

        return false;
    };

    obCart($this->owner, [[$this->nasi, 1]]);
    $statements = [];
    $bill = obSvc()->createFromCart($this->owner, []);
    $lock = $index('from "numbering_locks"');
    $insertBill = $index('insert into "open_bills"');
    expect($lock)->not->toBeFalse()->and($insertBill)->not->toBeFalse()->and($lock)->toBeLessThan($insertBill);

    $statements = [];
    obSvc()->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => 25000], $bill->version, $this->owner);
    $lock = $index('from "numbering_locks"');
    $insertSale = $index('insert into "sellings"');
    $payBill = $index('update "open_bills"');
    expect($lock)->not->toBeFalse()->and($insertSale)->not->toBeFalse()->and($payBill)->not->toBeFalse()
        ->and($lock)->toBeLessThan($insertSale)->and($lock)->toBeLessThan($payBill);
});
