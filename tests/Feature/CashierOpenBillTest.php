<?php

use App\Enums\OpenBillStatus;
use App\Exceptions\CartChangedException;
use App\Filament\Tenant\Pages\CartItem as CartItemPage;
use App\Filament\Tenant\Pages\Cashier;
use App\Filament\Tenant\Pages\POS;
use App\Livewire\Cashier\CartItemNote;
use App\Models\Tenants\About;
use App\Models\Tenants\CartItem;
use App\Models\Tenants\Category;
use App\Models\Tenants\Member;
use App\Models\Tenants\OpenBill;
use App\Models\Tenants\OpenBillEvent;
use App\Models\Tenants\OpenBillItem;
use App\Models\Tenants\PaymentMethod;
use App\Models\Tenants\Product;
use App\Models\Tenants\Receivable;
use App\Models\Tenants\Selling;
use App\Models\Tenants\Setting;
use App\Models\Tenants\Table;
use App\Models\Tenants\User;
use App\Services\Tenants\OpenBillService;
use App\Services\Tenants\SellingService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;

uses(RefreshDatabaseWithTenant::class);

/*
|--------------------------------------------------------------------------
| helpers (shared by the cashier feature tests; every one is guarded so a file runs alone)
|
| The cashier page is tested through a subclass whose view is a one-line stub, so these tests exercise the
| component (state, services, events) and do not depend on the Blade markup. The real markup is rendered by
| the "real view" smoke test at the end.
|--------------------------------------------------------------------------
*/

if (! class_exists('ObStubCashier')) {
    class ObStubCashier extends Cashier
    {
        public function render(): View
        {
            return view('ob-stub');
        }
    }
}

if (! class_exists('ObStubNote')) {
    class ObStubNote extends CartItemNote
    {
        public function render()
        {
            return view('ob-stub');
        }
    }
}

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

if (! function_exists('obStubViews')) {
    /** Makes the one-line stub view available. */
    function obStubViews(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ob-stub-views';
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (! is_file($dir.DIRECTORY_SEPARATOR.'ob-stub.blade.php')) {
            file_put_contents($dir.DIRECTORY_SEPARATOR.'ob-stub.blade.php', '<div>ob</div>');
        }
        app('view')->addLocation($dir);
    }
}

if (! function_exists('obCashier')) {
    /** The cashier page for the signed-in user. */
    function obCashier(): Testable
    {
        obStubViews();
        Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('tenant'));

        return Livewire::test(ObStubCashier::class);
    }
}

if (! function_exists('obEvents')) {
    /**
     * The parameters of every event of this name that the LAST request of the component dispatched.
     *
     * @return list<array<string, mixed>>
     */
    function obEvents(Testable $component, string $name): array
    {
        return collect(data_get($component->effects, 'dispatches'))
            ->where('name', $name)
            ->pluck('params')
            ->values()
            ->all();
    }
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 05:00:00', 'UTC')); // 12:00 WIB
    Setting::set('selling_method', 'fifo');
    Setting::set('default_tax', 0);
    Setting::set('cash_drawer_enabled', false);
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
| Simpan: the order becomes a bill and the screen is a new empty order
|--------------------------------------------------------------------------
*/

test('Save turns the order into a bill and leaves an empty new order', function () {
    $table = Table::create(['number' => '4']);
    $page = obCashier();
    $page->call('addCart', $this->nasi->id)->call('addCart', $this->nasi->id)->call('addCart', $this->teh->id);
    $page->call('selectTable', $table->id);
    $page->call('commitCustomerName', 'Budi');
    expect(CartItem::count())->toBe(2);

    $page->call('saveBill');

    $bill = OpenBill::sole();
    expect($bill->items)->toHaveCount(2)->and($bill->customer_name)->toBe('Budi')->and($bill->table_id)->toBe($table->id)
        ->and($bill->kitchen_batch_count)->toBe(0)
        ->and(CartItem::count())->toBe(0)
        ->and($page->get('openBillId'))->toBeNull()->and($page->get('cartItems'))->toHaveCount(0)
        ->and($page->get('cartDetail.customer_name'))->toBeNull()->and($page->get('cartDetail.table_id'))->toBeNull();

    $saved = obEvents($page, 'bill-saved');
    expect($saved)->toHaveCount(1)
        ->and($saved[0])->toMatchArray(['id' => $bill->id, 'number' => 1, 'sent' => false])
        ->and($saved[0]['label'])->toContain('Budi');
    $page->assertDispatched('order-reset');
});

test('the product added after Save starts a new order and never lands in the bill just saved', function () {
    $page = obCashier();
    $page->call('addCart', $this->nasi->id)->call('saveBill');
    $bill = OpenBill::sole();

    $page->call('addCart', $this->teh->id);

    expect(CartItem::where('user_id', $this->owner->id)->pluck('product_id')->all())->toBe([$this->teh->id])
        ->and(OpenBillItem::where('open_bill_id', $bill->id)->pluck('product_id')->all())->toBe([$this->nasi->id])
        ->and(OpenBill::find($bill->id)->version)->toBe(1);
});

test('Save with an empty order says so and creates nothing', function () {
    $page = obCashier();

    $page->call('saveBill');

    expect(OpenBill::count())->toBe(0);
    $page->assertNotified(__('Add the bill first'));
});

test('with the kill switch off no new bill can be made, but an open one can still be paid', function () {
    $open = obBill($this->owner, [[$this->nasi, 1]]);
    Setting::set('open_bill_enabled', '0');
    Cache::clear();

    $page = obCashier();
    expect($page->get('billsEnabled'))->toBeFalse()->and($page->get('billsVisible'))->toBeTrue();

    $page->call('addCart', $this->teh->id)->call('saveBill');
    expect(OpenBill::count())->toBe(1);
    $page->assertNotified(__('Bills are unavailable until the update is finished'));

    $page->call('openBill', $open->id);
    expect($page->get('openBillId'))->toBe($open->id);
});

test('without the bills table the cashier still works and the bill UI is hidden', function () {
    // sqlite DDL inside the test transaction is rolled back with it
    foreach (['open_bill_events', 'open_bill_items', 'open_bills'] as $table) {
        Illuminate\Support\Facades\Schema::dropIfExists($table);
    }
    App\Services\Tenants\NumberingLock::flush();
    App\Services\Tenants\DailyNumber::flush();

    try {
        $page = obCashier();
        expect($page->get('billsVisible'))->toBeFalse();

        $page->call('addCart', $this->nasi->id);
        expect(CartItem::count())->toBe(1);
    } finally {
        App\Services\Tenants\NumberingLock::flush();
        App\Services\Tenants\DailyNumber::flush();
    }
})->skip(fn () => Illuminate\Support\Facades\DB::getDriverName() !== 'sqlite', 'drops tables; sqlite test database only');

/*
|--------------------------------------------------------------------------
| Kirim: the kitchen ticket and the acknowledgement
|--------------------------------------------------------------------------
*/

test('Send to kitchen saves the bill, hands the ticket and its ack to the browser, and marks it sent only after the ack', function () {
    About::query()->update(['business_type' => 'fnb']);
    $table = Table::create(['number' => '7']);
    $page = obCashier();
    $page->call('addCart', $this->nasi->id)->call('addCart', $this->teh->id);
    $page->call('selectTable', $table->id);

    $page->call('sendToKitchen');

    $bill = OpenBill::sole();
    $print = obEvents($page, 'bill-print');
    expect($print)->toHaveCount(1)
        ->and($print[0]['mode'])->toBe('kitchen')->and($print[0]['reprint'])->toBeFalse()
        ->and($print[0]['job_key'])->toBe('k'.$bill->id.'-1')
        ->and($print[0]['ack'])->toMatchArray(['bill_id' => $bill->id, 'batch_no' => 1])
        ->and($print[0]['bill']['kitchen']['kind'])->toBe('new')->and($print[0]['bill']['kitchen']['table_label'])->toBe('7')
        ->and($print[0]['bill']['selling_details'])->toHaveCount(2);
    // the screen is a new empty order again
    expect($page->get('openBillId'))->toBeNull()->and(CartItem::count())->toBe(0);
    expect(obEvents($page, 'bill-saved')[0])->toMatchArray(['id' => $bill->id, 'sent' => true]);

    // printing happened only in the browser: until its ack the bill is NOT sent
    expect($bill->fresh()->kitchen_batch_count)->toBe(0);

    $page->dispatch('kitchen-ack', ack: $print[0]['ack'], mode: 'printed');

    $sent = $bill->fresh();
    expect($sent->kitchen_batch_count)->toBe(1)->and($sent->kitchen_sent_at)->not->toBeNull()
        ->and(obLine($bill, $this->nasi)->sent_qty)->toBe(1.0)
        ->and(obActions($bill))->toBe(['created', 'sent']);
    $page->assertDispatched('open-bills-changed');
});

test('a print that failed (no ack) leaves the bill unsent, and sending again produces the same batch', function () {
    About::query()->update(['business_type' => 'fnb']);
    $bill = obBill($this->owner, [[$this->nasi, 2]], ['customer_name' => 'Budi']);
    $page = obCashier();

    $page->call('sendToKitchen', $bill->id);
    $first = obEvents($page, 'bill-print')[0];
    // the printer failed: no kitchen-ack arrives. The cashier taps Send again.
    $page->call('sendToKitchen', $bill->id);
    $second = obEvents($page, 'bill-print')[0];

    expect($bill->fresh()->kitchen_batch_count)->toBe(0)->and(obActions($bill))->toBe(['created'])
        ->and($second['job_key'])->toBe($first['job_key'])->and($second['ack'])->toEqual($first['ack'])
        ->and($second['bill']['selling_details'])->toEqual($first['bill']['selling_details']);

    // either ack finally marks it, and a second ack of the same ticket changes nothing
    $page->dispatch('kitchen-ack', ack: $second['ack'], mode: 'printed');
    $page->dispatch('kitchen-ack', ack: $first['ack'], mode: 'printed');
    expect($bill->fresh()->kitchen_batch_count)->toBe(1)->and(obActions($bill))->toBe(['created', 'sent']);
});

test('"mark as sent without printing" acknowledges the ticket as manual', function () {
    About::query()->update(['business_type' => 'fnb']);
    $bill = obBill($this->owner, [[$this->nasi, 1]], ['customer_name' => 'Budi']);
    $page = obCashier();
    $page->call('sendToKitchen', $bill->id);
    $ack = obEvents($page, 'bill-print')[0]['ack'];

    $page->dispatch('kitchen-ack', ack: $ack, mode: 'manual');

    expect(obActions($bill))->toBe(['created', 'sent_manual'])->and($bill->fresh()->kitchen_batch_count)->toBe(1);
});

test('nothing new to send says so and, if a ticket was sent before, offers to reprint it', function () {
    About::query()->update(['business_type' => 'fnb']);
    $bill = obBill($this->owner, [[$this->nasi, 1]], ['customer_name' => 'Budi']);
    obSend($bill, $this->owner);
    $page = obCashier();

    $page->call('sendToKitchen', $bill->id);

    $page->assertNotified(__('Nothing new to send'))->assertNotDispatched('bill-print');
    expect(OpenBill::find($bill->id)->version)->toBe(2);

    // the reprint button: the last ticket as a copy, no change to the bill
    $page->dispatch('reprint-kitchen-last', $bill->id);
    $reprint = obEvents($page, 'bill-print');
    expect($reprint)->toHaveCount(1)->and($reprint[0]['reprint'])->toBeTrue()->and($reprint[0]['ack'])->toBeNull()
        ->and($reprint[0]['job_key'])->toBe('kitchen'.$bill->id.'-r')
        ->and(OpenBill::find($bill->id)->version)->toBe(2)->and(OpenBill::find($bill->id)->kitchen_batch_count)->toBe(1)
        ->and(obActions($bill))->toBe(['created', 'sent', 'reprint']);
});

test('without table and name a food-and-beverage order asks for confirmation before it says TAKEAWAY', function () {
    About::query()->update(['business_type' => 'fnb']);
    $page = obCashier();
    $page->call('addCart', $this->nasi->id);

    $page->call('sendToKitchen');

    expect(obEvents($page, 'takeaway-prompt'))->toHaveCount(1);
    expect(OpenBill::count())->toBe(0)->and(CartItem::count())->toBe(1);
    $page->assertNotDispatched('bill-print');

    $page->call('sendToKitchen', null, true);

    $bill = OpenBill::sole();
    $print = obEvents($page, 'bill-print');
    expect($print)->toHaveCount(1)->and($print[0]['bill']['table'])->toBeNull()->and($print[0]['bill']['customer_name'])->toBeNull()
        ->and($print[0]['bill']['number_label'])->toBe('#001')->and($bill->kitchen_batch_count)->toBe(0);
});

test('retail shops have no kitchen: Send does nothing', function () {
    $page = obCashier();
    $page->call('addCart', $this->nasi->id);

    $page->call('sendToKitchen');

    expect(OpenBill::count())->toBe(0)->and(CartItem::count())->toBe(1);
    $page->assertNotDispatched('bill-print');
});

/*
|--------------------------------------------------------------------------
| working on an open bill
|--------------------------------------------------------------------------
*/

test('opening a bill shows its lines and keeps the private cart untouched', function () {
    $bill = obBill(obUser(), [[$this->soto, 2]], ['customer_name' => 'Ani']);
    $page = obCashier();
    $page->call('addCart', $this->nasi->id);        // an unsaved order in progress

    $page->call('openBill', $bill->id);

    expect($page->get('openBillId'))->toBe($bill->id)->and($page->get('openBillVersion'))->toBe(1)
        ->and($page->get('cartItems'))->toHaveCount(1)->and($page->get('cartItems')->first())->toBeInstanceOf(OpenBillItem::class)
        ->and($page->get('openBill'))->toMatchArray(['id' => $bill->id, 'number' => 1, 'version' => 1, 'sent' => false])
        ->and($page->get('cartDetail.customer_name'))->toBe('Ani')
        ->and(CartItem::where('user_id', $this->owner->id)->pluck('product_id')->all())->toBe([$this->nasi->id]);

    // every change now goes to the bill; the cart table is not touched
    $page->call('addCart', $this->teh->id);
    expect(OpenBillItem::where('open_bill_id', $bill->id)->count())->toBe(2)
        ->and(CartItem::where('user_id', $this->owner->id)->pluck('product_id')->all())->toBe([$this->nasi->id]);

    $page->call('leaveBill');
    expect($page->get('openBillId'))->toBeNull()->and($page->get('cartItems'))->toHaveCount(1)
        ->and($page->get('cartItems')->first()->product_id)->toBe($this->nasi->id);
});

test('only an open bill can be opened', function () {
    $paid = obBill($this->owner, [[$this->nasi, 1]]);
    obSvc()->pay($paid, ['payment_method_id' => obMethod()->id, 'payed_money' => 25000], $paid->version, $this->owner);
    $void = obBill($this->owner, [[$this->nasi, 1]]);
    obSvc()->void($void, $this->owner, 'salah input', $void->version);
    $page = obCashier();

    $page->call('openBill', $paid->id);
    $page->assertNotified(__('Bill already paid'));
    expect($page->get('openBillId'))->toBeNull();

    $page->call('openBill', $void->id);
    $page->assertNotified(__('Bill is closed'));
    expect($page->get('openBillId'))->toBeNull();

    $page->call('openBill', 999999);
    $page->assertNotified(__('Bill not found'));
    expect($page->get('openBillId'))->toBeNull();

    // the same through the events the list sends
    $page->dispatch('open-bill-selected', $paid->id);
    expect($page->get('openBillId'))->toBeNull();
});

test('the bill that was open comes back after a reload, only while it is still open', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]], ['customer_name' => 'Ani']);
    obCashier()->call('openBill', $bill->id);

    $again = obCashier();
    expect($again->get('openBillId'))->toBe($bill->id)->and($again->get('billResumed'))->toBeTrue();

    obSvc()->void(OpenBill::find($bill->id), $this->owner, 'salah input', OpenBill::find($bill->id)->version);
    $third = obCashier();
    expect($third->get('openBillId'))->toBeNull();
});

test('quick plus, plus, Send raises no false conflict', function () {
    About::query()->update(['business_type' => 'fnb']);
    $bill = obBill($this->owner, [[$this->nasi, 1]], ['customer_name' => 'Budi']);
    obSend($bill, $this->owner);
    $page = obCashier();
    $page->call('openBill', $bill->id);

    $page->call('addCart', $this->nasi->id)->call('addCart', $this->nasi->id)->call('sendToKitchen');

    $page->assertNotDispatched('bill-conflict');
    $print = obEvents($page, 'bill-print');
    expect($print)->toHaveCount(1)->and($print[0]['bill']['kitchen']['kind'])->toBe('addition')
        ->and($print[0]['bill']['selling_details'][0]['qty'])->toEqual(2.0)
        ->and(obLine($bill, $this->nasi)->qty)->toBe(3.0);
});

test('a note or a discount edited on the bill, then Send or Pay, raises no false conflict', function () {
    About::query()->update(['business_type' => 'fnb']);
    $bill = obBill($this->owner, [[$this->nasi, 2]], ['customer_name' => 'Budi']);
    $line = obLine($bill, $this->nasi);
    $page = obCashier();
    $page->call('openBill', $bill->id);

    // the note box and the discount box are components of their own: they write the bill, then tell the page
    obSvc()->setLineNote($bill->id, $line->id, 'pedas', $this->owner);
    $page->dispatch('open-bill-changed', $bill->id);
    $page->call('addDiscountPricePerItem', $line->id, 1000);
    expect($page->get('openBillVersion'))->toBe(OpenBill::find($bill->id)->version);

    // header edits use the version the page holds: they must be current
    $page->call('commitCustomerName', 'Budi S');
    $page->assertNotDispatched('bill-conflict');
    expect(OpenBill::find($bill->id)->customer_name)->toBe('Budi S')->and($page->get('openBillVersion'))->toBe(OpenBill::find($bill->id)->version);

    $page->call('syncCartForPayment')
        ->set('cartDetail.payment_method_id', obMethod()->id)->set('cartDetail.payed_money', '100000')
        ->call('proceedThePayment');

    $page->assertNotDispatched('bill-conflict');
    $sale = Selling::sole();
    expect((float) $sale->total_price)->toBe(50000.0)->and((float) $sale->total_discount_per_item)->toBe(1000.0)
        ->and(OpenBill::find($bill->id)->status)->toBe(OpenBillStatus::Paid);
});

test('a second device changes the bill: paying is refused with the new total, and nothing is written', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]], ['customer_name' => 'Budi']);
    $page = obCashier();
    $page->call('openBill', $bill->id)->call('syncCartForPayment');
    expect($page->get('paymentPinned'))->toBeTrue();

    // the other device adds a tea
    obSvc()->addLine($bill->id, $this->teh, 1, null, null, $this->owner);

    $page->set('cartDetail.payment_method_id', obMethod()->id)->set('cartDetail.payed_money', '100000')
        ->call('proceedThePayment');

    $conflict = obEvents($page, 'bill-conflict');
    expect($conflict)->toHaveCount(1)
        ->and($conflict[0])->toMatchArray(['id' => $bill->id, 'serverVersion' => 2])
        ->and($conflict[0]['amount_due'])->toEqual(30000.0);
    $page->assertNotified(__('Bill was changed on another device'));
    expect(Selling::count())->toBe(0)->and(OpenBill::find($bill->id)->status)->toBe(OpenBillStatus::Open)
        ->and($this->nasi->fresh()->stock)->toBe(50.0);

    // "Reload bill" pins the new version, and the payment of the new total goes through
    $page->call('syncCartForPayment')->set('cartDetail.payed_money', '100000')->call('proceedThePayment');

    expect((float) Selling::sole()->total_price)->toBe(30000.0)->and(OpenBill::find($bill->id)->status)->toBe(OpenBillStatus::Paid);
});

test('the poll does nothing while the payment dialog is open, and picks up the change afterwards', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]], ['customer_name' => 'Budi']);
    $page = obCashier();
    $page->call('openBill', $bill->id)->call('syncCartForPayment');
    $pinned = $page->get('openBillVersion');

    obSvc()->addLine($bill->id, $this->teh, 1, null, null, $this->owner);
    $page->call('syncBill');

    expect($page->get('openBillVersion'))->toBe($pinned)->and($page->get('cartItems'))->toHaveCount(1);

    $page->call('paymentClosed')->call('syncBill');

    expect($page->get('openBillVersion'))->toBe(2)->and($page->get('cartItems'))->toHaveCount(2);
    $page->assertNotified(__('Bill was changed on another device'));

    // nothing changed: the next poll is silent
    $page->call('syncBill');
    expect($page->get('openBillVersion'))->toBe(2);
});

test('the poll leaves the new order alone and tells when the bill was closed elsewhere', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);
    $page = obCashier();
    $page->call('syncBill');                       // no bill: nothing to do
    $page->call('openBill', $bill->id);

    obSvc()->void(OpenBill::find($bill->id), $this->owner, 'salah input', OpenBill::find($bill->id)->version);
    $page->call('syncBill');

    expect($page->get('openBillId'))->toBeNull();
    $page->assertNotified(__('Bill is closed'));
});

test('adding to a bill checks the stock, and the bill keeps what fits', function () {
    $rare = obProduct('Langka', 10000, 2);
    $bill = obBill($this->owner, [[$this->teh, 1]]);
    $page = obCashier();
    $page->call('openBill', $bill->id);

    $page->call('addCart', $rare->id)->call('addCart', $rare->id)->call('addCart', $rare->id);

    expect(obLine($bill, $rare)->qty)->toBe(2.0);
    $page->assertNotified(__('Stock is out'));
});

test('minus on a line the kitchen has, below what was sent, opens the cancel dialog instead of cancelling', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);
    obSend($bill, $this->owner);
    $line = obLine($bill, $this->nasi);
    $page = obCashier();
    $page->call('openBill', $bill->id);

    $page->call('reduceCart', $this->nasi->id);

    $request = obEvents($page, 'cancel-sent-request');
    expect($request)->toHaveCount(1)->and($request[0])->toMatchArray(['itemId' => $line->id, 'sentQty' => 1.0, 'newQty' => 0.0, 'name' => 'Nasi Goreng']);
    expect($line->fresh()->qty)->toBe(1.0);

    $page->call('deleteCart', $line->id);
    expect(obEvents($page, 'cancel-sent-request'))->toHaveCount(1)->and(OpenBillItem::find($line->id))->not->toBeNull();

    // with a reason it goes through, is logged, and the kitchen is told at the next Send
    $page->call('cancelSent', $line->id, 0, 'Pelanggan batal');
    $cancel = OpenBillEvent::where('open_bill_id', $bill->id)->where('action', 'line_removed')->sole();
    expect($cancel->reason)->toBe('Pelanggan batal')->and($cancel->user_id)->toBe($this->owner->id)
        ->and(OpenBill::find($bill->id)->pending_cancel)->toHaveCount(1);
});

test('cancelSent without a reason or by a stranger is refused and writes nothing', function () {
    $creator = obUser();
    actingAs($creator);
    $bill = obBill($creator, [[$this->nasi, 2]]);
    obSend($bill, $creator);
    $line = obLine($bill, $this->nasi);
    $page = obCashier();
    $page->call('openBill', $bill->id);

    $page->call('cancelSent', $line->id, 1, 'ab');
    expect($line->fresh()->qty)->toBe(2.0);

    // 11 minutes later the creator's own right has lapsed
    Carbon::setTestNow(Carbon::now()->addMinutes(11));
    $page->call('cancelSent', $line->id, 1, 'Pelanggan batal');
    expect($line->fresh()->qty)->toBe(2.0)->and(obActions($bill))->toBe(['created', 'sent']);
    $page->assertNotified(__('Only the owner or a manager can cancel items already sent to the kitchen'));
});

test('the provisional bill prints without paying and changes nothing; an unsaved order is saved first', function () {
    $page = obCashier();
    $page->call('addCart', $this->nasi->id)->call('addCart', $this->teh->id);

    $page->call('printProvisional');

    $bill = OpenBill::sole();
    $print = obEvents($page, 'bill-print');
    expect($print)->toHaveCount(1)->and($print[0]['mode'])->toBe('bill')->and($print[0]['ack'])->toBeNull()
        ->and($print[0]['job_key'])->toBe('b'.$bill->id.'-v'.$bill->version)
        ->and($print[0]['bill']['amount_due'])->toEqual(30000.0)
        // the cashier is probably about to take the payment: the bill stays open on screen
        ->and($page->get('openBillId'))->toBe($bill->id);
    expect(Selling::count())->toBe(0)->and($this->nasi->fresh()->stock)->toBe(50.0);

    $before = OpenBill::find($bill->id)->version;
    $page->call('printProvisional');
    expect(OpenBill::find($bill->id)->version)->toBe($before);
});

test('voiding from the cashier asks for a reason, prints the kitchen cancellation and leaves the bill', function () {
    About::query()->update(['business_type' => 'fnb']);
    $bill = obBill($this->owner, [[$this->nasi, 1]], ['customer_name' => 'Budi']);
    obSend($bill, $this->owner);
    $page = obCashier();
    $page->call('openBill', $bill->id);

    $page->call('voidBill', '');
    expect(OpenBill::find($bill->id)->status)->toBe(OpenBillStatus::Open);

    $page->call('voidBill', 'Pelanggan pergi');

    $void = OpenBill::find($bill->id);
    expect($void->status)->toBe(OpenBillStatus::Void)->and($void->void_reason)->toBe('Pelanggan pergi')
        ->and($page->get('openBillId'))->toBeNull();
    $print = obEvents($page, 'bill-print');
    expect($print)->toHaveCount(1)->and($print[0]['bill']['kitchen']['is_void'])->toBeTrue()
        ->and($print[0]['job_key'])->toBe('k'.$bill->id.'-void');
    expect(obEvents($page, 'bill-voided')[0])->toMatchArray(['id' => $bill->id]);
});

test('a bill voided from the list closes on the cashier that has it open', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);
    $page = obCashier();
    $page->call('openBill', $bill->id);
    obSvc()->void(OpenBill::find($bill->id), $this->owner, 'salah input', OpenBill::find($bill->id)->version);

    $page->dispatch('bill-voided', $bill->id);

    expect($page->get('openBillId'))->toBeNull();
    $page->dispatch('bill-voided', 999);                       // another bill: nothing happens
});

test('a note written through the note box on a bill goes through the service and tells the page', function () {
    obStubViews();
    $bill = obBill($this->owner, [[$this->nasi, 2]]);
    $line = obLine($bill, $this->nasi);

    $note = Livewire::test(ObStubNote::class, ['cartItemId' => $line->id, 'note' => null, 'context' => 'bill', 'billId' => $bill->id])
        ->call('save', "  tanpa\x1b telur ");

    expect($line->fresh()->note)->toBe('tanpa telur')->and(obActions($bill))->toBe(['created', 'line_note'])
        ->and(OpenBill::find($bill->id)->version)->toBe(2);
    $changed = obEvents($note, 'open-bill-changed');
    expect($changed)->toHaveCount(1)->and($changed[0])->toMatchArray(['id' => $bill->id, 'version' => 2]);

    // a user who may not use bills cannot write it
    actingAs(obUser([]));
    Livewire::test(ObStubNote::class, ['cartItemId' => $line->id, 'note' => null, 'context' => 'bill', 'billId' => $bill->id])
        ->call('save', 'hack')->assertForbidden();
    expect($line->fresh()->note)->toBe('tanpa telur');
});

/*
|--------------------------------------------------------------------------
| paying: tokens, double submit, credit
|--------------------------------------------------------------------------
*/

test('a payment that failed validation is retried with a new token and then succeeds', function () {
    $member = Member::factory()->create();
    $credit = PaymentMethod::create(['name' => 'Kredit', 'is_cash' => false, 'is_debit' => false, 'is_credit' => true, 'is_wallet' => false, 'icon' => 'x']);
    $page = obCashier();
    $page->call('addCart', $this->nasi->id)->call('syncCartForPayment');
    $firstToken = $page->get('paymentToken');
    expect($firstToken)->not->toBe('');

    $page->set('cartDetail.payment_method_id', $credit->id)->set('cartDetail.payed_money', '5000')->call('proceedThePayment');

    $page->assertHasErrors(['member_id', 'due_date']);
    expect(Selling::count())->toBe(0)->and(CartItem::count())->toBe(1)
        ->and($page->get('paymentToken'))->not->toBe($firstToken);

    $page->set('cartDetail.member_id', $member->id)->set('cartDetail.due_date', '2026-11-01')->call('proceedThePayment');

    $page->assertHasNoErrors();
    expect(Selling::count())->toBe(1)->and(CartItem::count())->toBe(0);
});

test('the same payment tapped twice shows the receipt that exists and sells nothing twice', function () {
    $page = obCashier();
    $page->call('addCart', $this->nasi->id)->call('syncCartForPayment')
        ->set('cartDetail.payment_method_id', obMethod()->id)->set('cartDetail.payed_money', '30000');

    $page->call('proceedThePayment');
    $sale = Selling::sole();
    expect(obEvents($page, 'selling-created'))->toHaveCount(1);

    // the answer got lost / a double tap: the same token
    $page->call('proceedThePayment');

    $again = obEvents($page, 'selling-created');
    expect(Selling::count())->toBe(1)->and($again)->toHaveCount(1)->and($again[0]['alreadyPaid'])->toBeTrue()
        ->and($again[0]['selling']['id'])->toBe($sale->id)
        ->and($this->nasi->fresh()->stock)->toBe(49.0);
});

test('a bill paid twice with the same token shows the same sale', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]], ['customer_name' => 'Budi']);
    $page = obCashier();
    $page->call('openBill', $bill->id)->call('syncCartForPayment')
        ->set('cartDetail.payment_method_id', obMethod()->id)->set('cartDetail.payed_money', '30000')
        ->call('proceedThePayment');
    $sale = Selling::sole();

    $page->call('proceedThePayment');

    expect(Selling::count())->toBe(1)->and(obEvents($page, 'selling-created')[0]['alreadyPaid'])->toBeTrue()
        ->and(OpenBill::find($bill->id)->selling_id)->toBe($sale->id);
});

test('paying a bill someone else already paid opens that sale and sells nothing again', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);
    $page = obCashier();
    $page->call('openBill', $bill->id)->call('syncCartForPayment')
        ->set('cartDetail.payment_method_id', obMethod()->id)->set('cartDetail.payed_money', '30000');

    $other = obSvc()->pay(OpenBill::find($bill->id), ['payment_method_id' => obMethod()->id, 'payed_money' => 25000], 1, $this->owner);
    $page->call('proceedThePayment');

    // the sale that exists is shown (not a second one) and the cashier is back on a new order
    expect(Selling::count())->toBe(1)->and($page->get('openBillId'))->toBeNull();
    $created = obEvents($page, 'selling-created');
    expect($created)->toHaveCount(1)->and($created[0]['alreadyPaid'])->toBeTrue()->and($created[0]['selling']['id'])->toBe($other->selling->id);
});

test('two instances of the page for one account pay one cart: one sale, the second is told the cart changed', function () {
    $a = obCashier();
    $b = obCashier();
    $a->call('addCart', $this->nasi->id);
    $b->call('addCart', $this->nasi->id)->call('syncCartForPayment');    // b has seen the cart with 2 nasi
    $b->set('cartDetail.payment_method_id', obMethod()->id)->set('cartDetail.payed_money', '100000');

    // while b is paying, a changes the cart (after b read it, before b locks it)
    $sneak = new class($this->owner->id) extends SellingService
    {
        public function __construct(private int $userId)
        {
        }

        public function create(array $data, array $hooks = []): Selling
        {
            CartItem::where('user_id', $this->userId)->update(['qty' => 5, 'price' => 125000]);

            return parent::create($data, $hooks);
        }
    };
    app()->instance(SellingService::class, $sneak);

    $b->call('proceedThePayment');

    app()->forgetInstance(SellingService::class);
    $b->assertNotified(__('The cart changed, review it and pay again'));
    expect(Selling::count())->toBe(0)->and(CartItem::first()->qty)->toBe(5.0)->and($this->nasi->fresh()->stock)->toBe(50.0);
    // the screen was refreshed from the cart and the token rotated: b can look and pay again
    expect($b->get('paymentPinned'))->toBeTrue();
});

test('two instances paying one after the other produce one sale: the second finds an empty cart', function () {
    $a = obCashier();
    $b = obCashier();
    $a->call('addCart', $this->nasi->id)->call('syncCartForPayment');
    $b->call('refreshCart')->call('syncCartForPayment');
    expect($b->get('cartItems'))->toHaveCount(1);

    $a->set('cartDetail.payment_method_id', obMethod()->id)->set('cartDetail.payed_money', '30000')->call('proceedThePayment');
    $b->set('cartDetail.payment_method_id', obMethod()->id)->set('cartDetail.payed_money', '30000')->call('proceedThePayment');

    expect(Selling::count())->toBe(1)->and($this->nasi->fresh()->stock)->toBe(49.0);
    $b->assertNotified(__('No item'));
});

test('a credit down payment is kept on the sale and the receivable is the rest', function () {
    $member = Member::factory()->create();
    $credit = PaymentMethod::create(['name' => 'Kredit', 'is_cash' => true, 'is_debit' => false, 'is_credit' => true, 'is_wallet' => false, 'icon' => 'x']);
    $page = obCashier();
    $page->call('addCart', $this->nasi->id)->call('addCart', $this->nasi->id)->call('syncCartForPayment');

    $page->set('cartDetail.payment_method_id', $credit->id)->set('cartDetail.member_id', $member->id)
        ->set('cartDetail.due_date', '2026-11-01')->set('cartDetail.payed_money', '20000')
        ->call('proceedThePayment');

    $sale = Selling::sole();
    $receivable = Receivable::where('selling_id', $sale->id)->sole();
    expect((float) $sale->payed_money)->toBe(20000.0)->and((float) $sale->money_changes)->toBe(0.0)
        ->and((float) $receivable->total_receivable)->toBe(50000.0)->and((float) $receivable->rest_receivable)->toBe(30000.0)
        ->and($receivable->receivablePayments)->toHaveCount(1);
});

test('a credit down payment on a bill is kept as well', function () {
    $member = Member::factory()->create();
    $credit = PaymentMethod::create(['name' => 'Kredit', 'is_cash' => false, 'is_debit' => false, 'is_credit' => true, 'is_wallet' => false, 'icon' => 'x']);
    $bill = obBill($this->owner, [[$this->nasi, 2]], ['member_id' => $member->id]);
    $page = obCashier();
    $page->call('openBill', $bill->id)->call('syncCartForPayment');

    $page->set('cartDetail.payment_method_id', $credit->id)->set('cartDetail.due_date', '2026-11-01')
        ->set('cartDetail.payed_money', '20000')->call('proceedThePayment');

    $sale = Selling::sole();
    expect((float) $sale->payed_money)->toBe(20000.0)
        ->and((float) Receivable::where('selling_id', $sale->id)->sole()->rest_receivable)->toBe(30000.0)
        ->and(OpenBill::find($bill->id)->status)->toBe(OpenBillStatus::Paid);
});

test('paying a bill with changes the kitchen has not seen prints the kitchen ticket first, then the receipt data', function () {
    About::query()->update(['business_type' => 'fnb']);
    $bill = obBill($this->owner, [[$this->nasi, 1]], ['customer_name' => 'Budi']);
    obSend($bill, $this->owner);
    obSvc()->addLine($bill->id, $this->teh, 2, null, null, $this->owner);
    $page = obCashier();
    $page->call('openBill', $bill->id)->call('syncCartForPayment');
    expect($page->get('kitchenPreview'))->toBe(['pending' => 1])->and($page->get('printKitchenOnPay'))->toBeTrue();

    $page->set('cartDetail.payment_method_id', obMethod()->id)->set('cartDetail.payed_money', '100000')->call('proceedThePayment');

    $dispatches = collect(data_get($page->effects, 'dispatches'))->pluck('name')->values();
    $print = obEvents($page, 'bill-print');
    expect($print)->toHaveCount(1)->and($print[0]['mode'])->toBe('kitchen')->and($print[0]['ack'])->toBeNull()
        ->and($print[0]['bill']['kitchen']['kind'])->toBe('addition')
        ->and($dispatches->search('bill-print'))->toBeLessThan($dispatches->search('selling-created'))
        ->and(obEvents($page, 'selling-created')[0]['kitchenPrinted'])->toBeTrue()
        ->and(obActions($bill))->toContain('pay_with_unsent');
});

test('with the kitchen switch off at payment, no kitchen ticket is printed', function () {
    About::query()->update(['business_type' => 'fnb']);
    $bill = obBill($this->owner, [[$this->nasi, 1]], ['customer_name' => 'Budi']);
    $page = obCashier();
    $page->call('openBill', $bill->id)->call('syncCartForPayment')->set('printKitchenOnPay', false)
        ->set('cartDetail.payment_method_id', obMethod()->id)->set('cartDetail.payed_money', '100000')->call('proceedThePayment');

    expect(obEvents($page, 'bill-print'))->toBe([])->and(obActions($bill))->toContain('pay_with_unsent');
});

test('the stock override flag is honoured only for the official override: served lines and a user with update selling', function () {
    $rare = obProduct('Langka', 10000, 0);
    $bill = obBill($this->owner, [[$rare, 1]]);
    obSend($bill, $this->owner);
    $page = obCashier();
    $page->call('openBill', $bill->id)->call('syncCartForPayment');
    expect($page->get('stockOverride'))->toBeFalse();

    $page->set('cartDetail.payment_method_id', obMethod()->id)->set('cartDetail.payed_money', '10000')->call('proceedThePayment');
    $page->assertHasErrors(['products.0.qty']);
    expect(Selling::count())->toBe(0);

    $page->set('stockOverride', true)->call('proceedThePayment');

    expect(Selling::count())->toBe(1)->and(obActions($bill))->toContain('stock_override');
});

test('a bill is paid with the tax it was created with', function () {
    Setting::set('default_tax', 10);
    $bill = obBill($this->owner, [[$this->nasi, 2]]);
    Setting::set('default_tax', 25);
    Cache::clear();
    $page = obCashier();
    $page->call('openBill', $bill->id)->call('syncCartForPayment');

    expect($page->get('total_price'))->toBe(55000.0);
    $page->set('cartDetail.payment_method_id', obMethod()->id)->set('cartDetail.payed_money', '60000')->call('proceedThePayment');

    expect((float) Selling::sole()->tax)->toBe(10.0)->and((float) Selling::sole()->total_price)->toBe(55000.0);
});

/*
|--------------------------------------------------------------------------
| permissions
|--------------------------------------------------------------------------
*/

test('every bill action of the cashier page answers 403 to somebody who may not use bills, and writes nothing', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);
    obSend($bill, $this->owner);
    $stranger = obUser([]);

    // A request that aborts leaves the test component without a usable snapshot (the next call would answer 404),
    // so every call starts from a page the owner opened the bill on.
    $page = function () use ($bill, $stranger): Testable {
        actingAs($this->owner);
        $page = obCashier();
        $page->call('openBill', $bill->id);
        actingAs($stranger);

        return $page;
    };

    actingAs($this->owner);
    obCashier()->call('openBill', $bill->id);
    $before = [OpenBill::count(), OpenBillEvent::count(), OpenBill::find($bill->id)->version, OpenBillItem::count()];

    $calls = [
        ['openBill', [$bill->id]],
        ['saveBill', []],
        ['sendToKitchen', []],
        ['sendToKitchen', [$bill->id]],
        ['printProvisional', []],
        ['reprintKitchen', []],
        ['cancelSent', [OpenBillItem::first()->id, 0, 'Pelanggan batal']],
        ['voidBill', ['Pelanggan pergi']],
        ['addCart', [$this->teh->id]],
        ['reduceCart', [$this->nasi->id]],
        ['restoreCartItem', [$this->teh->id, 1]],
        ['commitCustomerName', ['Hacker']],
        ['selectTable', [null]],
        ['syncBill', []],
        ['syncCartForPayment', []],
    ];
    foreach ($calls as [$method, $args]) {
        try {
            $page()->call($method, ...$args)->assertForbidden();
        } catch (PHPUnit\Framework\AssertionFailedError $e) {
            $this->fail($method.' must answer 403: '.$e->getMessage());
        }
    }
    foreach (['open-bill-selected', 'open-bill-pay', 'open-bill-changed'] as $event) {
        $page()->dispatch($event, $bill->id);
    }
    $page()->dispatch('kitchen-ack', ack: ['bill_id' => $bill->id, 'batch_no' => 2, 'items' => []], mode: 'printed')->assertForbidden();

    actingAs($this->owner);
    expect([OpenBill::count(), OpenBillEvent::count(), OpenBill::find($bill->id)->version, OpenBillItem::count()])->toBe($before)
        ->and(OpenBill::find($bill->id)->status)->toBe(OpenBillStatus::Open);
});

test('the payment of an open bill answers 403 to somebody who may not use bills', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);
    $page = obCashier();
    $page->call('openBill', $bill->id)->call('syncCartForPayment')
        ->set('cartDetail.payment_method_id', obMethod()->id)->set('cartDetail.payed_money', '30000');

    actingAs(obUser([]));
    $page->call('proceedThePayment')->assertForbidden();

    expect(Selling::count())->toBe(0)->and(OpenBill::find($bill->id)->status)->toBe(OpenBillStatus::Open);
});

test('the version and the bill id cannot be set from the browser', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);
    $other = obBill($this->owner, [[$this->soto, 1]]);
    $page = obCashier();
    $page->call('openBill', $bill->id);

    foreach (['openBillId' => $other->id, 'openBillVersion' => 99, 'paymentPinned' => true, 'kitchenEnabled' => true, 'billsEnabled' => false] as $property => $value) {
        expect(fn () => $page->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class);
    }
    expect($page->get('openBillId'))->toBe($bill->id);
});

/*
|--------------------------------------------------------------------------
| the pages that share the cart code keep working
|--------------------------------------------------------------------------
*/

test('the POS page and the cart page still mount and use the private cart', function () {
    obCart($this->owner, [[$this->nasi, 2]]);

    Livewire::test(POS::class)->assertStatus(200);
    Livewire::test(CartItemPage::class)->assertStatus(200);

    $pos = Livewire::test(POS::class);
    $pos->call('addCart', $this->teh->id);
    expect(CartItem::where('user_id', $this->owner->id)->pluck('product_id')->sort()->values()->all())
        ->toBe(collect([$this->nasi->id, $this->teh->id])->sort()->values()->all())
        ->and(OpenBill::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| the real markup
|--------------------------------------------------------------------------
*/

test('the cashier page renders with its real view, with and without an open bill', function () {
    $bill = obBill($this->owner, [[$this->nasi, 2]], ['customer_name' => 'Budi']);
    obCart($this->owner, [[$this->teh, 1]]);

    Livewire::test(Cashier::class)->assertStatus(200);

    Livewire::test(Cashier::class)->call('openBill', $bill->id)->assertStatus(200)->assertSee('Budi');
});
