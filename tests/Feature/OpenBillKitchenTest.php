<?php

use App\Exceptions\OpenBill\BillConflictException;
use App\Models\Tenants\CartItem;
use App\Models\Tenants\Category;
use App\Models\Tenants\OpenBill;
use App\Models\Tenants\OpenBillEvent;
use App\Models\Tenants\OpenBillItem;
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
| batch 1 and the two-phase protocol
|--------------------------------------------------------------------------
*/

test('the first batch carries every line, and building it writes nothing', function () {
    $table = Table::create(['number' => '4']);
    $bill = obBill($this->owner, [[$this->nasi, 2], [$this->teh, 1]], ['table_id' => $table->id]);
    $events = OpenBillEvent::count();

    $batch = obSvc()->kitchenBatch($bill);

    expect($batch['kind'])->toBe('new')->and($batch['batch_no'])->toBe(1)
        ->and($batch['items'])->toHaveCount(2)->and($batch['pending_count'])->toBe(2)
        ->and($batch['moved'])->toBeNull()->and($batch['table_label'])->toBe('4')
        ->and($batch['cancelled'])->toBe([])->and($batch['changed'])->toBe([]);

    $nasi = collect($batch['items'])->firstWhere('name', 'Nasi Goreng');
    expect($nasi['qty'])->toBe(2.0)->and($nasi['total_qty'])->toBe(2.0)->and($nasi['item_id'])->toBe(obLine($bill, $this->nasi)->id);

    // pure: nothing was marked as sent, no event, no version bump
    $fresh = OpenBill::find($bill->id);
    expect($fresh->kitchen_batch_count)->toBe(0)->and($fresh->kitchen_sent_at)->toBeNull()
        ->and($fresh->version)->toBe(1)->and(OpenBillEvent::count())->toBe($events)
        ->and((float) OpenBillItem::where('open_bill_id', $bill->id)->sum('sent_qty'))->toBe(0.0);
});

test('a print that failed and is tried again produces the very same batch', function () {
    $bill = obBill($this->owner, [[$this->nasi, 2], [$this->teh, 1]]);

    $first = obSvc()->kitchenBatch($bill);
    $retry = obSvc()->kitchenBatch(OpenBill::find($bill->id));

    expect($retry)->toEqual($first);

    $one = obSvc()->printPayload($bill, 'kitchen', $first, false, $this->owner);
    $two = obSvc()->printPayload($bill, 'kitchen', $retry, false, $this->owner);
    expect($two['job_key'])->toBe($one['job_key'])->and($one['job_key'])->toBe('k'.$bill->id.'-1')
        ->and($two['selling_details'])->toEqual($one['selling_details'])
        ->and($two['kitchen'])->toEqual($one['kitchen']);
});

test('the acknowledgement marks the lines as sent, stores the ticket and writes one sent event', function () {
    $table = Table::create(['number' => '4']);
    $bill = obBill($this->owner, [[$this->nasi, 2], [$this->teh, 1]], ['table_id' => $table->id]);
    $batch = obSvc()->kitchenBatch($bill);

    $after = obSvc()->ackKitchenBatch($bill->id, $batch['ack'], $this->owner);

    expect($after->kitchen_batch_count)->toBe(1)->and($after->version)->toBe(2)
        ->and($after->kitchen_sent_at)->not->toBeNull()
        ->and($after->last_kitchen_batch['table_label'])->toBe('4')
        ->and($after->last_kitchen_batch['items'])->toHaveCount(2)
        ->and(obLine($bill, $this->nasi)->sent_qty)->toBe(2.0)
        ->and(obLine($bill, $this->teh)->sent_qty)->toBe(1.0)
        ->and(obActions($bill))->toBe(['created', 'sent']);

    // nothing left to tell
    expect(obSvc()->kitchenBatch($after))->toBeNull()->and(obSvc()->pendingCount($after))->toBe(0);
});

test('the acknowledgement is idempotent, ignores an old batch and refuses one from the future', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);
    $batch = obSvc()->kitchenBatch($bill);
    obSvc()->ackKitchenBatch($bill->id, $batch['ack'], $this->owner);
    $version = OpenBill::find($bill->id)->version;
    $events = OpenBillEvent::count();

    // twice, and a late retry of batch 1 after batch 2 exists
    obSvc()->ackKitchenBatch($bill->id, $batch['ack'], $this->owner);
    obSvc()->addLine($bill->id, $this->teh, 1, null, null, $this->owner);
    obSend($bill, $this->owner);
    $afterSecond = OpenBill::find($bill->id);
    obSvc()->ackKitchenBatch($bill->id, $batch['ack'], $this->owner);

    expect(OpenBill::find($bill->id)->kitchen_batch_count)->toBe(2)
        ->and(OpenBill::find($bill->id)->version)->toBe($afterSecond->version)
        ->and(OpenBillEvent::where('open_bill_id', $bill->id)->where('action', 'sent')->count())->toBe(2);

    $future = $batch['ack'];
    $future['batch_no'] = 5;
    expect(fn () => obSvc()->ackKitchenBatch($bill->id, $future, $this->owner))->toThrow(BillConflictException::class);
    expect(OpenBill::find($bill->id)->kitchen_batch_count)->toBe(2);

    // an acknowledgement for another bill is refused
    $other = obBill($this->owner, [[$this->soto, 1]]);
    expect(fn () => obSvc()->ackKitchenBatch($other->id, $batch['ack'], $this->owner))->toThrow(InvalidArgumentException::class);

    expect($version)->toBe(2)->and($events)->toBeGreaterThan(0);
});

test('"mark as sent without printing" records a sent_manual event and still stores the ticket', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);

    $after = obSend($bill, $this->owner, 'manual');

    expect(obActions($bill))->toBe(['created', 'sent_manual'])
        ->and($after->kitchen_batch_count)->toBe(1)
        ->and(obSvc()->lastKitchenBatch($after)['batch_no'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| differences after the first shipment
|--------------------------------------------------------------------------
*/

test('adding quantity to a sent line sends only the difference, as an addition', function () {
    $bill = obBill($this->owner, [[$this->nasi, 2], [$this->teh, 1]]);
    obSend($bill, $this->owner);

    obSvc()->addLine($bill->id, $this->nasi, 1, null, null, $this->owner);
    obSvc()->addLine($bill->id, $this->soto, 2, null, null, $this->owner);
    $batch = obSvc()->kitchenBatch(OpenBill::find($bill->id));

    $byName = collect($batch['items'])->keyBy('name');
    expect($batch['kind'])->toBe('addition')->and($batch['batch_no'])->toBe(2)
        ->and($byName)->toHaveCount(2)
        ->and($byName['Nasi Goreng']['qty'])->toBe(1.0)->and($byName['Nasi Goreng']['total_qty'])->toBe(3.0)
        ->and($byName['Soto']['qty'])->toBe(2.0)->and($byName['Soto']['total_qty'])->toBe(2.0);
});

test('reducing below the sent quantity becomes a cancellation and never lowers sent_qty', function () {
    $bill = obBill($this->owner, [[$this->nasi, 3]]);
    obSend($bill, $this->owner);
    $line = obLine($bill, $this->nasi);

    obSvc()->setLineQty($bill->id, $line->id, 1, $this->owner, 'Salah input');

    expect($line->fresh()->qty)->toBe(1.0)->and($line->fresh()->sent_qty)->toBe(3.0);

    $batch = obSvc()->kitchenBatch(OpenBill::find($bill->id));
    expect($batch['kind'])->toBe('cancel_only')->and($batch['items'])->toBe([])
        ->and($batch['cancelled'])->toHaveCount(1)
        ->and($batch['cancelled'][0])->toMatchArray(['source' => 'reduce', 'qty' => 2.0, 'name' => 'Nasi Goreng', 'ref' => 'r'.$line->id]);

    // once printed, the kitchen knows 1: the line is quiet again and sent_qty followed
    obSend($bill, $this->owner);
    expect($line->fresh()->sent_qty)->toBe(1.0)->and(obSvc()->kitchenBatch(OpenBill::find($bill->id)))->toBeNull();
});

test('minus and then plus on a sent line tells the kitchen nothing', function () {
    $bill = obBill($this->owner, [[$this->nasi, 3]]);
    obSend($bill, $this->owner);
    $line = obLine($bill, $this->nasi);

    obSvc()->setLineQty($bill->id, $line->id, 2, $this->owner, 'Salah input');
    obSvc()->setLineQty($bill->id, $line->id, 3, $this->owner);

    expect(obSvc()->kitchenBatch(OpenBill::find($bill->id)))->toBeNull()
        ->and(obSvc()->pendingCount(OpenBill::find($bill->id)))->toBe(0);

    // the same through addLine (the "+" button)
    obSvc()->setLineQty($bill->id, $line->id, 1, $this->owner, 'Salah input');
    obSvc()->addLine($bill->id, $this->nasi, 2, null, null, $this->owner);
    expect(obSvc()->kitchenBatch(OpenBill::find($bill->id)))->toBeNull();
});

test('removing a sent line and adding the same product again nets to zero through pending_cancel', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1], [$this->teh, 2]]);
    obSend($bill, $this->owner);
    $teh = obLine($bill, $this->teh);

    obSvc()->setLineQty($bill->id, $teh->id, 0, $this->owner, 'Pelanggan batal');

    $removed = OpenBill::find($bill->id);
    expect(OpenBillItem::find($teh->id))->toBeNull()
        ->and($removed->pending_cancel)->toHaveCount(1)
        ->and((float) $removed->pending_cancel[0]['qty'])->toBe(2.0)
        ->and(strlen($removed->pending_cancel[0]['ref']))->toBe(8)
        ->and(obSvc()->pendingCount($removed))->toBe(1);

    $batch = obSvc()->kitchenBatch($removed);
    expect($batch['kind'])->toBe('cancel_only')
        ->and($batch['cancelled'][0])->toMatchArray(['source' => 'removed', 'name' => 'Es Teh', 'qty' => 2.0, 'item_id' => null])
        ->and($batch['ack']['cancel_refs'])->toBe([$removed->pending_cancel[0]['ref']]);

    // the same product comes back: the entry is consumed and the new line starts as already sent
    obSvc()->addLine($bill->id, $this->teh, 2, null, null, $this->owner);
    $back = OpenBill::find($bill->id);
    expect($back->pending_cancel)->toBeNull()
        ->and(obLine($bill, $this->teh)->sent_qty)->toBe(2.0)
        ->and(obSvc()->kitchenBatch($back))->toBeNull();
});

test('a removed sent line that stays removed is told to the kitchen once, then forgotten', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1], [$this->teh, 2]]);
    obSend($bill, $this->owner);
    obSvc()->setLineQty($bill->id, obLine($bill, $this->teh)->id, 0, $this->owner, 'Pelanggan batal');

    obSend($bill, $this->owner);

    expect(OpenBill::find($bill->id)->pending_cancel)->toBeNull()
        ->and(obSvc()->kitchenBatch(OpenBill::find($bill->id)))->toBeNull();
});

test('Undo of a removed sent line restores it as already sent', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1], [$this->teh, 1]]);
    obSend($bill, $this->owner);
    $teh = obLine($bill, $this->teh);
    obSvc()->setLineQty($bill->id, $teh->id, 0, $this->owner, 'oops oops');

    obSvc()->restoreLine($bill->id, ['product_id' => $this->teh->id, 'qty' => 1, 'note' => null], $this->owner);

    $restored = obLine($bill, $this->teh);
    expect($restored->sent_qty)->toBe(1.0)->and(OpenBill::find($bill->id)->pending_cancel)->toBeNull()
        ->and(obSvc()->kitchenBatch(OpenBill::find($bill->id)))->toBeNull();
});

test('a changed note on a sent line is a change, not an addition', function () {
    $bill = obBill($this->owner, [[$this->nasi, 2]]);
    obSvc()->setLineNote($bill->id, obLine($bill, $this->nasi)->id, 'pedas', $this->owner);
    obSend($bill, $this->owner);

    obSvc()->setLineNote($bill->id, obLine($bill, $this->nasi)->id, 'tidak pedas', $this->owner);
    $batch = obSvc()->kitchenBatch(OpenBill::find($bill->id));

    expect($batch['kind'])->toBe('change_only')->and($batch['items'])->toBe([])
        ->and($batch['changed'])->toHaveCount(1)
        ->and($batch['changed'][0])->toMatchArray(['name' => 'Nasi Goreng', 'note' => 'tidak pedas', 'was' => 'pedas'])
        ->and($batch['pending_count'])->toBe(1);

    // a note that goes from none to "" or back is not a change (null == '')
    obSend($bill, $this->owner);
    obSvc()->setLineNote($bill->id, obLine($bill, $this->nasi)->id, '   ', $this->owner);
    $cleared = obSvc()->kitchenBatch(OpenBill::find($bill->id));
    expect($cleared['changed'][0])->toMatchArray(['note' => null, 'was' => 'tidak pedas']);
});

test('moving the table makes the Send button active with no item change', function () {
    $four = Table::create(['number' => '4']);
    $six = Table::create(['number' => '6']);
    $bill = obBill($this->owner, [[$this->nasi, 1]], ['table_id' => $four->id]);
    obSend($bill, $this->owner);
    expect(obSvc()->kitchenBatch(OpenBill::find($bill->id)))->toBeNull();

    $bill = obSvc()->updateHeader(OpenBill::find($bill->id), ['table_id' => $six->id], OpenBill::find($bill->id)->version, $this->owner);
    $batch = obSvc()->kitchenBatch($bill);

    expect($batch['kind'])->toBe('change_only')->and($batch['items'])->toBe([])
        ->and($batch['moved'])->toBe(['from' => '4', 'to' => '6'])
        ->and($batch['table_label'])->toBe('6')->and($batch['pending_count'])->toBe(1)
        ->and(obSvc()->pendingCount($bill))->toBe(1);

    // the ticket tells the kitchen where the table is now; printed once, the move is history
    $sent = obSvc()->ackKitchenBatch($bill->id, $batch['ack'], $this->owner);
    expect($sent->last_kitchen_batch['table_label'])->toBe('6')
        ->and(obSvc()->kitchenBatch($sent))->toBeNull();

    // a move together with items: the items ride on the same ticket
    obSvc()->addLine($bill->id, $this->teh, 1, null, null, $this->owner);
    $fresh = OpenBill::find($bill->id);
    obSvc()->updateHeader($fresh, ['table_id' => $four->id], $fresh->version, $this->owner);
    $both = obSvc()->kitchenBatch(OpenBill::find($bill->id));
    expect($both['kind'])->toBe('addition')->and($both['moved'])->toBe(['from' => '6', 'to' => '4'])->and($both['pending_count'])->toBe(2);
});

test('there is nothing to send when nothing differs, and an empty unsent bill has no batch', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);
    obSend($bill, $this->owner);
    expect(obSvc()->kitchenBatch(OpenBill::find($bill->id)))->toBeNull();

    $empty = obBill($this->owner, [[$this->teh, 1]]);
    obSvc()->setLineQty($empty->id, obLine($empty, $this->teh)->id, 0, $this->owner);
    $emptyBill = OpenBill::find($empty->id);
    expect(obSvc()->kitchenBatch($emptyBill))->toBeNull()->and(obSvc()->pendingCount($emptyBill))->toBe(0);
});

test('a line removed while its ticket was printing is told to the kitchen on the next ticket', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1], [$this->teh, 1]]);
    $batch = obSvc()->kitchenBatch($bill);

    // another device removes the tea (never sent, so no gate) between the batch and the acknowledgement
    obSvc()->setLineQty($bill->id, obLine($bill, $this->teh)->id, 0, $this->owner);
    obSvc()->ackKitchenBatch($bill->id, $batch['ack'], $this->owner);

    $after = OpenBill::find($bill->id);
    expect($after->pending_cancel)->toHaveCount(1)->and($after->pending_cancel[0]['name'])->toBe('Es Teh')
        ->and(obSvc()->kitchenBatch($after)['kind'])->toBe('cancel_only');
});

test('a stale acknowledgement cannot lower what a later edit added', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);
    $batch = obSvc()->kitchenBatch($bill);

    obSvc()->addLine($bill->id, $this->nasi, 2, null, null, $this->owner);   // after the batch was built: qty 3
    obSvc()->ackKitchenBatch($bill->id, $batch['ack'], $this->owner);

    // the kitchen got 1 of the 3: the other 2 are still to send
    $next = obSvc()->kitchenBatch(OpenBill::find($bill->id));
    expect($next['kind'])->toBe('addition')->and($next['items'][0]['qty'])->toBe(2.0)->and($next['items'][0]['total_qty'])->toBe(3.0);
});

/*
|--------------------------------------------------------------------------
| reprint, void ticket, big bills
|--------------------------------------------------------------------------
*/

test('reprinting the last ticket changes nothing on the bill', function () {
    $table = Table::create(['number' => '12']);
    $bill = obBill($this->owner, [[$this->nasi, 2], [$this->teh, 1]], ['table_id' => $table->id]);
    obSend($bill, $this->owner);
    $before = OpenBill::find($bill->id);
    $events = OpenBillEvent::count();
    Carbon::setTestNow(Carbon::now()->addMinutes(7));

    $last = obSvc()->lastKitchenBatch($before);
    $payload = obSvc()->printPayload($before, 'kitchen', $last, true, $this->owner);

    expect($payload['job_key'])->toBe('kitchen'.$bill->id.'-r')
        ->and($payload['kitchen']['reprint'])->toBeTrue()->and($payload['kitchen']['batch_no'])->toBe(1)
        ->and($payload['kitchen']['table_label'])->toBe('12')
        ->and($payload['selling_details'])->toHaveCount(2)
        ->and($payload['printed_at_label'])->toBe('12:00')                  // the original time, not 7 minutes later
        ->and($payload['printed_on_label'])->toBe('15/10/2026');            // and the date, for a ticket that lies around

    $after = OpenBill::find($bill->id);
    expect($after->version)->toBe($before->version)->and($after->kitchen_batch_count)->toBe(1)
        ->and($after->kitchen_sent_at->equalTo($before->kitchen_sent_at))->toBeTrue()
        ->and(OpenBillEvent::count())->toBe($events)
        ->and(obLine($bill, $this->nasi)->sent_qty)->toBe(2.0);

    // a bill never sent has nothing to reprint
    $fresh = obBill($this->owner, [[$this->soto, 1]]);
    expect(obSvc()->lastKitchenBatch($fresh))->toBeNull();
});

test('the print payload of a kitchen ticket lists only the batch and keeps the totals of the whole bill', function () {
    $bill = obBill($this->owner, [[$this->nasi, 2]]);
    obSend($bill, $this->owner);
    obSvc()->addLine($bill->id, $this->nasi, 1, null, null, $this->owner);
    obSvc()->addLine($bill->id, $this->teh, 1, null, null, $this->owner);
    $bill = OpenBill::find($bill->id);

    $payload = obSvc()->printPayload($bill, 'kitchen', obSvc()->kitchenBatch($bill), false, $this->owner);

    expect($payload['job_key'])->toBe('k'.$bill->id.'-2')
        ->and($payload['kitchen']['kind'])->toBe('addition')
        ->and(collect($payload['selling_details'])->map(fn ($d) => [$d['product']['name'], $d['qty'], $d['total_qty']])->all())
        ->toContain(['Nasi Goreng', 1.0, 3.0], ['Es Teh', 1.0, 1.0])
        ->and($payload['amount_due'])->toBe(80000.0);
    json_encode($payload, JSON_THROW_ON_ERROR);
});

test('the void ticket cancels everything the kitchen was told, including a line removed earlier', function () {
    $table = Table::create(['number' => '3']);
    $bill = obBill($this->owner, [[$this->nasi, 1], [$this->teh, 2], [$this->soto, 1]], ['table_id' => $table->id]);
    obSend($bill, $this->owner);
    obSvc()->setLineQty($bill->id, obLine($bill, $this->soto)->id, 0, $this->owner, 'habis');   // pending cancel, not yet printed
    obSvc()->addLine($bill->id, $this->nasi, 1, null, null, $this->owner);                       // not sent: must not be cancelled
    $fresh = OpenBill::find($bill->id);

    $ticket = obSvc()->void($fresh, $this->owner, 'Pelanggan pergi', $fresh->version);

    expect($ticket)->not->toBeNull()
        ->and($ticket['is_void'])->toBeTrue()->and($ticket['kind'])->toBe('void')->and($ticket['batch_no'])->toBe(0)
        ->and($ticket['void_reason'])->toBe('Pelanggan pergi')->and($ticket['ack'])->toBeNull()
        ->and($ticket['table_label'])->toBe('3')
        ->and(collect($ticket['cancelled'])->map(fn ($c) => [$c['name'], $c['qty'], $c['source']])->sortBy(0)->values()->all())
        ->toBe([['Es Teh', 2.0, 'void'], ['Nasi Goreng', 1.0, 'void'], ['Soto', 1.0, 'removed']]);

    $payload = obSvc()->printPayload(OpenBill::find($bill->id), 'kitchen', $ticket, false, $this->owner);
    expect($payload['job_key'])->toBe('k'.$bill->id.'-void')
        ->and($payload['kitchen']['is_void'])->toBeTrue()->and($payload['kitchen']['void_reason'])->toBe('Pelanggan pergi')
        ->and($payload['selling_details'])->toBe([]);

    // the lines stay, and the kitchen is never asked to ship a voided bill
    expect(OpenBillItem::where('open_bill_id', $bill->id)->count())->toBe(2)
        ->and(obSvc()->kitchenBatch(OpenBill::find($bill->id)))->toBeNull();
});

test('a bill the kitchen never saw voids without a ticket', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);

    expect(obSvc()->void($bill, $this->owner, 'salah input', $bill->version))->toBeNull();
});

test('150 lines with long notes survive the stored ticket and the acknowledgement', function () {
    $lines = [];
    for ($i = 0; $i < 150; $i++) {
        $lines[] = [obProduct('Menu '.$i, 1000, 5), 1];
    }
    $bill = obBill($this->owner, $lines);
    foreach ($bill->items as $item) {
        obSvc()->setLineNote($bill->id, $item->id, str_repeat('x', 250), $this->owner);
    }

    $batch = obSvc()->kitchenBatch(OpenBill::find($bill->id));
    expect($batch['items'])->toHaveCount(150)->and(strlen(json_encode($batch['ack'])))->toBeGreaterThan(65535);

    obSvc()->ackKitchenBatch($bill->id, $batch['ack'], $this->owner);

    $stored = DB::table('open_bills')->where('id', $bill->id)->value('last_kitchen_batch');
    $last = obSvc()->lastKitchenBatch(OpenBill::find($bill->id));
    expect(strlen($stored))->toBeGreaterThan(50000)
        ->and(json_decode($stored, true))->not->toBeNull()
        ->and($last['items'])->toHaveCount(150)
        ->and($last['items'][149]['note'])->toBe(str_repeat('x', 250));

    // and the pending cancellations of a big bill fit as well
    foreach (OpenBillItem::where('open_bill_id', $bill->id)->limit(100)->get() as $item) {
        obSvc()->setLineQty($bill->id, $item->id, 0, $this->owner, 'habis semua');
    }
    $pending = OpenBill::find($bill->id)->pending_cancel;
    expect($pending)->toHaveCount(100)->and($pending[99]['note'])->toBe(str_repeat('x', 250));
});
