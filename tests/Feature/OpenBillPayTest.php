<?php

use App\Enums\OpenBillStatus;
use App\Exceptions\OpenBill\BillClosedException;
use App\Exceptions\OpenBill\BillConflictException;
use App\Exceptions\OpenBill\BillEmptyException;
use App\Exceptions\OpenBill\BillForbiddenException;
use App\Models\Tenants\CartItem;
use App\Models\Tenants\CashDrawer;
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
use App\Models\Tenants\Stock;
use App\Models\Tenants\User;
use App\Models\Tenants\Voucher;
use App\Services\Tenants\CashierReportService;
use App\Services\Tenants\OpenBillService;
use App\Services\Tenants\PayResult;
use App\Services\Tenants\ProductReportService;
use App\Services\Tenants\SellingReportService;
use App\Services\Tenants\SellingService;
use App\Support\BusinessDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

if (! function_exists('obRacing')) {
    /**
     * An OpenBillService whose sale service first runs $sneak: something that happens on another device between the
     * pre-checks of pay() and the moment the sale transaction takes its locks.
     */
    function obRacing(Closure $sneak): OpenBillService
    {
        return new OpenBillService(new class($sneak) extends SellingService
        {
            public function __construct(private Closure $sneak)
            {
            }

            public function create(array $data, array $hooks = []): Selling
            {
                ($this->sneak)();

                return parent::create($data, $hooks);
            }
        });
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
| an unpaid bill is not a sale
|--------------------------------------------------------------------------
*/

test('a bill that is not paid is in no sales figure, no report and no stock', function () {
    $bill = obBill($this->owner, [[$this->nasi, 2], [$this->teh, 3]], ['customer_name' => 'Budi']);
    obSend($bill, $this->owner);
    obSvc()->addLine($bill->id, $this->soto, 1, null, null, $this->owner);

    expect(Selling::count())->toBe(0)
        ->and($this->nasi->fresh()->stock)->toBe(50.0)->and($this->teh->fresh()->stock)->toBe(50.0)
        ->and(Receivable::count())->toBe(0)
        ->and(CashDrawer::count())->toBe(0);

    // the report services read the signed-in user's profile timezone
    DB::table('profiles')->insert(['user_id' => $this->owner->id, 'timezone' => 'Asia/Jakarta', 'created_at' => now(), 'updated_at' => now()]);
    $range = ['start_date' => '2026-10-15', 'end_date' => '2026-10-15'];
    expect(app(SellingReportService::class)->generate($range)['reports'])->toBe([])
        ->and(app(CashierReportService::class)->generate($range)['reports'])->toBe([])
        ->and(app(ProductReportService::class)->generate($range)['reports'])->toBe([]);

    $list = $this->getJson('/api/transaction/selling');
    $list->assertOk();
    expect($list->json('data.data'))->toBeEmpty();

    foreach (['total-sales' => 'total_sales', 'total-revenue' => 'total_revenue', 'total-gross-profit' => 'total_gross_profit'] as $path => $key) {
        $response = $this->getJson('/api/transaction/dashboard/'.$path.'?filter_type=today&timezone=UTC');
        $response->assertOk();
        expect((float) $response->json('data.'.$key))->toBe(0.0);
    }

    // ... and the same screens see the sale once the bill is paid
    $paid = obSvc()->pay(OpenBill::find($bill->id), ['payment_method_id' => obMethod()->id, 'payed_money' => 500000], OpenBill::find($bill->id)->version, $this->owner);

    expect(Selling::count())->toBe(1)
        ->and(app(SellingReportService::class)->generate($range)['reports'])->toHaveCount(3)
        ->and(app(CashierReportService::class)->generate($range)['reports'])->toHaveCount(1)
        ->and(app(ProductReportService::class)->generate($range)['reports'])->toHaveCount(3);
    expect((float) $this->getJson('/api/transaction/dashboard/total-sales?filter_type=today&timezone=UTC')->json('data.total_sales'))->toBe(1.0);
    expect($this->getJson('/api/transaction/selling')->json('data.data'))->toHaveCount(1);
    expect($paid->selling->daily_order_number)->toBe($bill->number);
});

/*
|--------------------------------------------------------------------------
| paying
|--------------------------------------------------------------------------
*/

test('paying creates exactly one sale, takes the stock once and closes the bill', function () {
    Setting::set('default_tax', 10);
    $bill = obBill($this->owner, [[$this->nasi, 2], [$this->teh, 1]], ['customer_name' => 'Budi', 'discount_price' => 1000]);
    obSvc()->setLineDiscount($bill->id, obLine($bill, $this->teh)->id, 500, $this->owner);
    $bill = OpenBill::find($bill->id);

    // 55.000 + 10% = 60.500, minus 500 and 1.000
    expect(obSvc()->totals(obSvc()->find($bill->id))['amount_due'])->toBe(59000.0);

    $result = obSvc()->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => 100000], $bill->version, $this->owner);

    expect($result)->toBeInstanceOf(PayResult::class)->and($result->alreadyPaid)->toBeFalse()->and($result->kitchen)->toBeNull();
    $sale = $result->selling->fresh();
    expect(Selling::count())->toBe(1)
        ->and($sale->sellingDetails()->count())->toBe(2)
        ->and((float) $sale->total_price)->toBe(60500.0)
        ->and((float) $sale->discount_price)->toBe(1000.0)
        ->and((float) $sale->total_discount_per_item)->toBe(500.0)
        ->and((float) $sale->tax)->toBe(10.0)
        ->and((float) $sale->payed_money)->toBe(100000.0)
        ->and((float) $sale->money_changes)->toBe(41000.0)
        ->and($sale->customer_name)->toBe('Budi')
        ->and($sale->date)->toBe('2026-10-15 05:00:00');

    expect($this->nasi->fresh()->stock)->toBe(48.0)->and($this->teh->fresh()->stock)->toBe(49.0);

    $paid = OpenBill::find($bill->id);
    expect($paid->status)->toBe(OpenBillStatus::Paid)->and($paid->selling_id)->toBe($sale->id)
        ->and($paid->paid_by)->toBe($this->owner->id)->and($paid->paid_at)->not->toBeNull()
        ->and($paid->version)->toBe($bill->version + 1)
        // a bill the kitchen never received counts as "not sent" at payment time (the screen asks what to do)
        ->and(obActions($bill))->toBe(['created', 'line_discount', 'paid', 'pay_with_unsent']);
    // the lines are kept as the record of what was ordered
    expect(OpenBillItem::where('open_bill_id', $bill->id)->count())->toBe(2);
});

test('the payer is the signed-in user and is recorded on the bill and on the sale', function () {
    $creator = obUser(['create selling']);
    $bill = obBill($creator, [[$this->nasi, 1]]);
    $payer = obUser(['create selling']);
    actingAs($payer);

    $result = obSvc()->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => 25000], $bill->version, $payer);

    expect(OpenBill::find($bill->id)->paid_by)->toBe($payer->id)->and(OpenBill::find($bill->id)->created_by)->toBe($creator->id)
        ->and($result->selling->fresh()->user_id)->toBe($payer->id);
});

test('somebody who is not signed in, or has no right to sell, cannot pay', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);
    $other = obUser(['create selling']);
    $noRight = obUser([]);

    // the payer must be the authenticated user (the observer stamps the sale with auth())
    expect(fn () => obSvc()->pay($bill, ['payment_method_id' => obMethod()->id], $bill->version, $other))->toThrow(BillForbiddenException::class);

    actingAs($noRight);
    expect(fn () => obSvc()->pay($bill, ['payment_method_id' => obMethod()->id], $bill->version, $noRight))->toThrow(BillForbiddenException::class);

    expect(Selling::count())->toBe(0)->and(OpenBill::find($bill->id)->status)->toBe(OpenBillStatus::Open);
});

test('only the payment fields come from the client; totals, discount and tax come from the bill', function () {
    Setting::set('default_tax', 10);
    $bill = obBill($this->owner, [[$this->nasi, 2]], ['discount_price' => 1000]);

    $result = obSvc()->pay($bill, [
        'payment_method_id' => obMethod()->id,
        'payed_money' => 100000,
        // everything below must be ignored
        'total_price' => 1,
        'discount_price' => 99999,
        'total_discount_per_item' => 99999,
        'tax' => 0,
        'friend_price' => true,
        'products' => [['product_id' => $this->soto->id, 'qty' => 9]],
        'customer_name' => 'Hacker',
        'user_id' => 999,
    ], $bill->version, $this->owner);

    $sale = $result->selling->fresh();
    expect((float) $sale->total_price)->toBe(55000.0)->and((float) $sale->discount_price)->toBe(1000.0)
        ->and((float) $sale->tax)->toBe(10.0)->and($sale->friend_price)->toBeFalsy()
        ->and($sale->customer_name)->not->toBe('Hacker')
        ->and($sale->sellingDetails()->pluck('product_id')->all())->toBe([$this->nasi->id])
        ->and($this->soto->fresh()->stock)->toBe(50.0);
});

test('a friend price cannot be forced onto a bill', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);
    $this->nasi->update(['initial_price' => 100]);

    $sale = obSvc()->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => 25000, 'friend_price' => true], $bill->version, $this->owner)->selling->fresh();

    expect((float) $sale->total_price)->toBe(25000.0)->and($sale->friend_price)->toBeFalsy();
});

test('the tax percent of the bill is frozen when it is created', function () {
    Setting::set('default_tax', 10);
    $bill = obBill($this->owner, [[$this->nasi, 2]]);
    Setting::set('default_tax', 25);
    Cache::clear();

    $sale = obSvc()->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => 100000], $bill->version, $this->owner)->selling->fresh();

    expect((float) $sale->tax)->toBe(10.0)->and((float) $sale->total_price)->toBe(55000.0)->and((float) $sale->tax_price)->toBe(5000.0);
});

test('the line price is the price of the moment it was ordered, not today\'s catalog price', function () {
    $bill = obBill($this->owner, [[$this->nasi, 2]]);
    $this->nasi->update(['selling_price' => 40000]);

    $sale = obSvc()->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => 50000], $bill->version, $this->owner)->selling->fresh();

    expect((float) $sale->total_price)->toBe(50000.0)->and((float) $sale->sellingDetails->first()->price)->toBe(50000.0);
});

test('the line note and the customer, table and member travel to the sale', function () {
    $table = App\Models\Tenants\Table::create(['number' => '8']);
    $member = Member::factory()->create();
    $bill = obBill($this->owner, [[$this->nasi, 1]], ['table_id' => $table->id, 'member_id' => $member->id, 'note' => 'cepat ya']);
    obSvc()->setLineNote($bill->id, obLine($bill, $this->nasi)->id, 'tanpa telur', $this->owner);

    $sale = obSvc()->pay(OpenBill::find($bill->id), ['payment_method_id' => obMethod()->id, 'payed_money' => 25000], OpenBill::find($bill->id)->version, $this->owner)->selling->fresh();

    expect($sale->table_id)->toBe($table->id)->and($sale->member_id)->toBe($member->id)
        ->and($sale->sellingDetails->first()->note)->toBe('tanpa telur');
});

/*
|--------------------------------------------------------------------------
| payment kinds and the listeners
|--------------------------------------------------------------------------
*/

test('a credit payment keeps the down payment and opens a receivable, whether the method is flagged cash or not', function () {
    $member = Member::factory()->create();

    foreach ([true, false] as $flaggedCash) {
        $method = PaymentMethod::create(['name' => 'Kredit '.($flaggedCash ? 'tunai' : 'murni'), 'is_cash' => $flaggedCash, 'is_debit' => false, 'is_credit' => true, 'is_wallet' => false, 'icon' => 'x']);
        $bill = obBill($this->owner, [[$this->nasi, 2]]);

        // credit without member and without a due date is refused, and nothing is written
        expect(fn () => obSvc()->pay($bill, ['payment_method_id' => $method->id, 'payed_money' => 10000], $bill->version, $this->owner))
            ->toThrow(ValidationException::class);
        expect(Selling::where('payment_method_id', $method->id)->count())->toBe(0)
            ->and(OpenBill::find($bill->id)->status)->toBe(OpenBillStatus::Open);

        $sale = obSvc()->pay($bill, [
            'payment_method_id' => $method->id, 'payed_money' => 10000, 'member_id' => $member->id, 'due_date' => '2026-11-01',
        ], $bill->version, $this->owner)->selling->fresh();

        $receivable = Receivable::where('selling_id', $sale->id)->sole();
        expect((float) $sale->payed_money)->toBe(10000.0)->and((float) $sale->money_changes)->toBe(0.0)
            ->and($sale->member_id)->toBe($member->id)
            ->and((float) $receivable->total_receivable)->toBe(50000.0)->and((float) $receivable->rest_receivable)->toBe(40000.0)
            ->and($receivable->receivablePayments)->toHaveCount(1)
            ->and((float) $receivable->receivablePayments->first()->amount)->toBe(10000.0);
    }
});

test('a credit payment without a down payment has a receivable and no receivable payment', function () {
    $member = Member::factory()->create();
    $method = PaymentMethod::create(['name' => 'Bon', 'is_cash' => false, 'is_debit' => false, 'is_credit' => true, 'is_wallet' => false, 'icon' => 'x']);
    $bill = obBill($this->owner, [[$this->nasi, 1]], ['discount_price' => 5000]);

    $sale = obSvc()->pay($bill, ['payment_method_id' => $method->id, 'payed_money' => 0, 'member_id' => $member->id, 'due_date' => '2026-11-01'], $bill->version, $this->owner)->selling->fresh();

    $receivable = Receivable::where('selling_id', $sale->id)->sole();
    // the debt is what is owed AFTER the discount
    expect((float) $receivable->total_receivable)->toBe(20000.0)->and((float) $receivable->rest_receivable)->toBe(20000.0)
        ->and($receivable->receivablePayments)->toHaveCount(0);
});

test('an exact method (QRIS, debit, wallet) takes the amount due and ignores the number the client sent', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1], [$this->teh, 1]]);

    $sale = obSvc()->pay($bill, ['payment_method_id' => obMethod('QRIS')->id, 'payed_money' => 1], $bill->version, $this->owner)->selling->fresh();

    expect((float) $sale->payed_money)->toBe(30000.0)->and((float) $sale->money_changes)->toBe(0.0);
});

test('cash that is too short is refused with a message under payed_money', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);

    try {
        obSvc()->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => 100], $bill->version, $this->owner);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('payed_money');
    }

    expect(Selling::count())->toBe(0)->and(OpenBill::find($bill->id)->status)->toBe(OpenBillStatus::Open);

    // an empty amount means "exact cash", and the change is computed from the bill
    $sale = obSvc()->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => ''], $bill->version, $this->owner)->selling->fresh();
    expect((float) $sale->payed_money)->toBe(25000.0)->and((float) $sale->money_changes)->toBe(0.0);
});

test('a payment method that no longer exists is a validation error, not a crash', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);

    foreach ([999999, null, ''] as $id) {
        try {
            obSvc()->pay($bill, ['payment_method_id' => $id], $bill->version, $this->owner);
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('payment_method_id');
        }
    }
    expect(Selling::count())->toBe(0);
});

test('a bill with no lines cannot be paid', function () {
    $bill = obBill($this->owner, [[$this->teh, 1]]);
    obSvc()->setLineQty($bill->id, obLine($bill, $this->teh)->id, 0, $this->owner);

    expect(fn () => obSvc()->pay(OpenBill::find($bill->id), ['payment_method_id' => obMethod()->id], OpenBill::find($bill->id)->version, $this->owner))
        ->toThrow(BillEmptyException::class);
});

/*
|--------------------------------------------------------------------------
| number and name: one sequence, midnight, one moment
|--------------------------------------------------------------------------
*/

test('paid on the same business day, the sale keeps the bill number and the customer name', function () {
    $other = obBill($this->owner, [[$this->soto, 1]], ['customer_name' => 'Dewi']);
    $bill = obBill($this->owner, [[$this->nasi, 1]], ['customer_name' => 'Budi']);
    $twin = obBill($this->owner, [[$this->nasi, 1]], ['customer_name' => 'Budi']);                // "Budi (2)"

    $sale = obSvc()->pay($twin, ['payment_method_id' => obMethod()->id, 'payed_money' => 25000], $twin->version, $this->owner)->selling->fresh();

    expect($twin->customer_name)->toBe('Budi (2)')
        ->and($sale->daily_order_number)->toBe($twin->number)->and($twin->number)->toBe(3)
        ->and($sale->customer_name)->toBe('Budi (2)');

    // a direct sale after the three bills continues the same sequence
    $service = new SellingService;
    $data = ['payed_money' => 5000, 'friend_price' => false, 'products' => [['product_id' => $this->teh->id, 'qty' => 1]]];
    $next = $service->create(array_merge($data, $service->mapProductRequest($data)));
    expect($next->daily_order_number)->toBe(4)->and($bill->number)->toBe(2)->and($other->number)->toBe(1);
});

test('paid after midnight the sale gets a new number and the name is numbered again', function () {
    // bill made at 23:55 WIB on the 15th
    Carbon::setTestNow(Carbon::parse('2026-10-15 16:55:00', 'UTC'));
    $bill = obBill($this->owner, [[$this->nasi, 1]], ['customer_name' => 'Rina']);
    expect($bill->business_date->format('Y-m-d'))->toBe('2026-10-15')->and($bill->number)->toBe(1);

    // 00:05 WIB on the 16th: a new day started, with a bill of its own
    Carbon::setTestNow(Carbon::parse('2026-10-15 17:05:00', 'UTC'));
    $today = obBill($this->owner, [[$this->teh, 1]], ['customer_name' => 'Rina']);
    expect($today->business_date->format('Y-m-d'))->toBe('2026-10-16')->and($today->number)->toBe(1)->and($today->customer_name)->toBe('Rina');

    $sale = obSvc()->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => 25000], $bill->version, $this->owner)->selling->fresh();

    // the payment day wins: a new number after today's bill 1, and the name "Rina" is taken today
    expect($sale->daily_order_number)->toBe(2)->and($sale->customer_name)->toBe('Rina (2)')
        ->and(BusinessDay::dateOf($sale->date))->toBe('2026-10-16')
        ->and(OpenBill::find($bill->id)->number)->toBe(1);
});

test('the date is one moment: paying across 16:59:59Z and 17:00:00Z never gives two sales the same number', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 16:50:00', 'UTC'));
    $bill = obBill($this->owner, [[$this->nasi, 1]], ['customer_name' => 'Tono']);
    $second = obBill($this->owner, [[$this->teh, 1]], ['customer_name' => 'Tini']);

    // the payment starts in the last second of the 15th and the clock passes midnight while it runs
    Carbon::setTestNow(Carbon::parse('2026-10-15 16:59:59', 'UTC'));
    $racing = obRacing(function () {
        Carbon::setTestNow(Carbon::parse('2026-10-15 17:00:00', 'UTC'));
    });
    $sale = $racing->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => 25000], $bill->version, $this->owner)->selling->fresh();

    expect($sale->date)->toBe('2026-10-15 16:59:59')                       // the pre-checked moment, not the later clock
        ->and($sale->daily_order_number)->toBe(1)->and($sale->customer_name)->toBe('Tono');

    // now it is the 16th: the next payment starts a new day at 1 and the old numbers never collide
    $other = obSvc()->pay($second, ['payment_method_id' => obMethod()->id, 'payed_money' => 5000], $second->version, $this->owner)->selling->fresh();
    expect($other->date)->toBe('2026-10-15 17:00:00')->and($other->daily_order_number)->toBe(1)
        ->and(BusinessDay::dateOf($other->date))->toBe('2026-10-16');

    $pairs = Selling::all()->map(fn (Selling $s) => BusinessDay::dateOf($s->date).'#'.$s->daily_order_number);
    expect($pairs->unique()->count())->toBe($pairs->count());
});

test('a bill kept past midnight is flagged as crossed, and as stale only after the stale hours', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 16:55:00', 'UTC'));
    $bill = obBill($this->owner, [[$this->nasi, 1]]);

    Carbon::setTestNow(Carbon::parse('2026-10-15 17:05:00', 'UTC'));
    $row = obSvc()->listOpen($this->owner)->firstWhere('id', $bill->id);
    expect($row['crossed_midnight'])->toBeTrue()->and($row['stale'])->toBeFalse()->and($row['business_date_label'])->toBe('KEMARIN');

    Carbon::setTestNow(Carbon::parse('2026-10-16 00:56:00', 'UTC'));                       // 6 h 1 min later, still yesterday's bill
    $row = obSvc()->listOpen($this->owner)->firstWhere('id', $bill->id);
    expect($row['stale'])->toBeTrue()->and($row['crossed_midnight'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| stock
|--------------------------------------------------------------------------
*/

test('short stock rolls everything back: the bill stays open and intact, the voucher is not used, no number is lost', function () {
    $rare = obProduct('Langka', 10000, 1);
    $voucher = Voucher::factory()->create(['code' => 'HEMAT', 'nominal' => 2000, 'kuota' => 3, 'minimal_buying' => 0]);
    $bill = obBill($this->owner, [[$this->nasi, 1], [$rare, 3]], ['customer_name' => 'Budi']);
    obSvc()->setLineNote($bill->id, obLine($bill, $this->nasi)->id, 'pedas', $this->owner);
    $bill = OpenBill::find($bill->id);
    $before = [OpenBillItem::where('open_bill_id', $bill->id)->orderBy('id')->get()->toArray(), $bill->version];

    try {
        obSvc()->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => 500000, 'voucher' => 'HEMAT'], $bill->version, $this->owner);
        $this->fail('expected a stock error');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('products.1.qty')->and($e->errors()['products.1.qty'][0])->toContain('Langka');
    }

    $after = OpenBill::find($bill->id);
    expect(Selling::count())->toBe(0)
        ->and($after->status)->toBe(OpenBillStatus::Open)->and($after->selling_id)->toBeNull()->and($after->version)->toBe($before[1])
        ->and([OpenBillItem::where('open_bill_id', $bill->id)->orderBy('id')->get()->toArray(), $after->version])->toEqual($before)
        ->and($voucher->fresh()->kuota)->toBe(3)
        ->and($this->nasi->fresh()->stock)->toBe(50.0)->and($rare->fresh()->stock)->toBe(1.0)
        ->and(obActions($bill))->toBe(['created', 'line_note']);

    // the failed attempt used no order number: the next sale is number 2 (the bill is 1)
    $next = obBill($this->owner, [[$this->teh, 1]]);
    expect($next->number)->toBe(2);
});

test('two payments in a row for one unit of stock: exactly one succeeds', function () {
    $last = obProduct('Satu-satunya', 10000, 1);
    $a = obBill($this->owner, [[$last, 1]]);
    $b = obBill($this->owner, [[$last, 1]]);

    $first = obSvc()->pay($a, ['payment_method_id' => obMethod()->id, 'payed_money' => 10000], $a->version, $this->owner);
    expect($first->selling->exists)->toBeTrue();

    expect(fn () => obSvc()->pay($b, ['payment_method_id' => obMethod()->id, 'payed_money' => 10000], $b->version, $this->owner))
        ->toThrow(ValidationException::class);

    expect(Selling::count())->toBe(1)->and($last->fresh()->stock)->toBe(0.0)
        ->and(OpenBill::find($b->id)->status)->toBe(OpenBillStatus::Open);
});

test('stock is checked inside the transaction: it can run out between the cashier\'s check and the sale', function () {
    $last = obProduct('Satu-satunya', 10000, 1);
    $bill = obBill($this->owner, [[$last, 1]]);

    // another till sells the last unit after pay() started
    $racing = obRacing(function () use ($last) {
        $service = new SellingService;
        $data = ['payed_money' => 10000, 'friend_price' => false, 'products' => [['product_id' => $last->id, 'qty' => 1]]];
        $service->create(array_merge($data, $service->mapProductRequest($data)));
    });

    expect(fn () => $racing->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => 10000], $bill->version, $this->owner))
        ->toThrow(ValidationException::class);

    expect(Selling::count())->toBe(1)->and(OpenBill::find($bill->id)->status)->toBe(OpenBillStatus::Open);
});

test('the stock override is only for lines the kitchen already served, and only for people who may', function () {
    $rare = obProduct('Langka', 10000, 0);
    $unsent = obBill($this->owner, [[$rare, 1]]);

    // not served: the override flag changes nothing
    expect(fn () => obSvc()->pay($unsent, ['payment_method_id' => obMethod()->id, 'payed_money' => 10000], $unsent->version, $this->owner, ['stock_override' => true]))
        ->toThrow(ValidationException::class);
    expect(Selling::count())->toBe(0);

    // served, but asked by someone without "update selling"
    $served = obBill($this->owner, [[$rare, 1]]);
    obSend($served, $this->owner);
    $cashier = obUser(['create selling']);
    actingAs($cashier);
    $served = OpenBill::find($served->id);
    expect(fn () => obSvc()->pay($served, ['payment_method_id' => obMethod()->id, 'payed_money' => 10000], $served->version, $cashier, ['stock_override' => true]))
        ->toThrow(ValidationException::class);
    expect(Selling::count())->toBe(0);

    // served, without the flag: still refused
    actingAs($this->owner);
    expect(fn () => obSvc()->pay($served, ['payment_method_id' => obMethod()->id, 'payed_money' => 10000], $served->version, $this->owner))
        ->toThrow(ValidationException::class);

    // served + flag + an owner: sold, and the override is on record
    $result = obSvc()->pay($served, ['payment_method_id' => obMethod()->id, 'payed_money' => 10000], $served->version, $this->owner, ['stock_override' => true]);
    expect($result->selling->exists)->toBeTrue()->and(Selling::count())->toBe(1);

    $event = OpenBillEvent::where('open_bill_id', $served->id)->where('action', 'stock_override')->sole();
    expect($event->user_id)->toBe($this->owner->id)->and($event->product_name)->toBe('Langka')
        ->and($event->detail)->toMatchArray(['stock' => 0.0, 'needed' => 1.0]);
});

test('a manager with update selling may override, a line mixed of served and unserved products overrides only the served one', function () {
    $served = obProduct('Sudah disajikan', 10000, 0);
    $fresh = obProduct('Belum disajikan', 10000, 0);
    $manager = obUser(['create selling', 'update selling']);
    actingAs($manager);
    $bill = obBill($manager, [[$served, 1]]);
    obSend($bill, $manager);
    obSvc()->addLine($bill->id, $fresh, 1, null, null, $manager);
    $bill = OpenBill::find($bill->id);

    try {
        obSvc()->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => 20000], $bill->version, $manager, ['stock_override' => true]);
        $this->fail('the unserved product has no stock');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['products.1.qty']);
    }

    obSvc()->setLineQty($bill->id, obLine($bill, $fresh)->id, 0, $manager);
    $bill = OpenBill::find($bill->id);
    $result = obSvc()->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => 10000], $bill->version, $manager, ['stock_override' => true]);
    expect($result->selling->exists)->toBeTrue();
});

test('a product deleted after it was added is named in the error, and nothing is written', function () {
    $bill = obBill($this->owner, [[$this->teh, 1], [$this->nasi, 1]]);
    $this->teh->delete();

    try {
        obSvc()->pay(OpenBill::find($bill->id), ['payment_method_id' => obMethod()->id, 'payed_money' => 100000], OpenBill::find($bill->id)->version, $this->owner);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect(array_values($e->errors())[0][0])->toContain('Es Teh');
    }

    expect(Selling::count())->toBe(0)->and(OpenBill::find($bill->id)->status)->toBe(OpenBillStatus::Open)
        ->and($this->nasi->fresh()->stock)->toBe(50.0);
});

/*
|--------------------------------------------------------------------------
| versions, double payment
|--------------------------------------------------------------------------
*/

test('a stale version is a conflict with no effect, a fresh one pays', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);
    obSvc()->addLine($bill->id, $this->teh, 1, null, null, $this->owner);                    // another device edits: version 2

    try {
        obSvc()->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => 100000], $bill->version, $this->owner);
        $this->fail('expected a conflict');
    } catch (BillConflictException $e) {
        expect($e->serverVersion)->toBe(2);
    }
    expect(Selling::count())->toBe(0)->and($this->nasi->fresh()->stock)->toBe(50.0)
        ->and(OpenBill::find($bill->id)->status)->toBe(OpenBillStatus::Open)->and(obActions($bill))->toBe(['created', 'line_added']);

    $paid = obSvc()->pay(OpenBill::find($bill->id), ['payment_method_id' => obMethod()->id, 'payed_money' => 100000], 2, $this->owner);
    expect((float) $paid->selling->total_price)->toBe(30000.0);
});

test('a bill edited between the pre-check and the lock is a conflict, a price unit that changed price too', function () {
    $item = null;
    $bill = obBill($this->owner, [[$this->teh, 1]]);
    $item = obLine($bill, $this->teh);

    expect(fn () => obRacing(fn () => obSvc()->setLineQty($bill->id, $item->id, 5, $this->owner))
        ->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => 100000], $bill->version, $this->owner))
        ->toThrow(BillConflictException::class);
    expect(Selling::count())->toBe(0)->and($this->teh->fresh()->stock)->toBe(50.0);

    // a price unit's price changes after the pre-check: the version does not move, the line fingerprint does
    $unit = App\Models\Tenants\PriceUnit::factory()->create(['product_id' => $this->soto->id, 'selling_price' => 90000, 'stock' => 1]);
    $unitBill = obBill($this->owner, [[$this->soto, 1]]);
    DB::table('open_bill_items')->where('open_bill_id', $unitBill->id)->update(['price_unit_id' => $unit->id]);
    $unitBill = OpenBill::find($unitBill->id);

    expect(fn () => obRacing(fn () => $unit->update(['selling_price' => 95000]))
        ->pay($unitBill, ['payment_method_id' => obMethod()->id, 'payed_money' => 200000], $unitBill->version, $this->owner))
        ->toThrow(BillConflictException::class);
    expect(Selling::count())->toBe(0);

    $ok = obSvc()->pay(OpenBill::find($unitBill->id), ['payment_method_id' => obMethod()->id, 'payed_money' => 200000], $unitBill->version, $this->owner);
    expect((float) $ok->selling->fresh()->total_price)->toBe(95000.0);
});

test('paying a bill that is already paid returns the same sale and creates no second one', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);
    $first = obSvc()->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => 25000], $bill->version, $this->owner);

    // a double click, a retry after a lost response, a second device: the version they hold is old
    foreach ([$bill->version, 1, 99] as $version) {
        $again = obSvc()->pay(OpenBill::find($bill->id), ['payment_method_id' => obMethod()->id, 'payed_money' => 25000], $version, $this->owner);
        expect($again->alreadyPaid)->toBeTrue()->and($again->selling->id)->toBe($first->selling->id);
    }

    expect(Selling::count())->toBe(1)->and($this->nasi->fresh()->stock)->toBe(49.0)
        ->and(OpenBillEvent::where('open_bill_id', $bill->id)->where('action', 'paid')->count())->toBe(1);
});

test('losing the race against another payment of the same bill reports that sale as already paid', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);
    $winner = null;

    $result = obRacing(function () use ($bill, &$winner) {
        $winner = obSvc()->pay(OpenBill::find($bill->id), ['payment_method_id' => obMethod()->id, 'payed_money' => 25000], 1, $this->owner);
    })->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => 25000], 1, $this->owner);

    expect($result->alreadyPaid)->toBeTrue()->and($result->selling->id)->toBe($winner->selling->id)
        ->and(Selling::count())->toBe(1)->and($this->nasi->fresh()->stock)->toBe(49.0);
});

test('a voided bill cannot be paid and a paid bill cannot be voided', function () {
    $void = obBill($this->owner, [[$this->nasi, 1]]);
    obSvc()->void($void, $this->owner, 'salah input', $void->version);

    try {
        obSvc()->pay(OpenBill::find($void->id), ['payment_method_id' => obMethod()->id], OpenBill::find($void->id)->version, $this->owner);
        $this->fail('a void bill is closed');
    } catch (BillClosedException $e) {
        expect($e->isPaid())->toBeFalse();
    }

    $paid = obBill($this->owner, [[$this->nasi, 1]]);
    obSvc()->pay($paid, ['payment_method_id' => obMethod()->id, 'payed_money' => 25000], $paid->version, $this->owner);
    expect(fn () => obSvc()->void(OpenBill::find($paid->id), $this->owner, 'terlambat', OpenBill::find($paid->id)->version))
        ->toThrow(BillClosedException::class);

    expect(Selling::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| cash drawer
|--------------------------------------------------------------------------
*/

test('with the cash drawer not opened, paying gives a translated message under cash_drawer, not a server error', function () {
    Setting::set('cash_drawer_enabled', true);
    $bill = obBill($this->owner, [[$this->nasi, 1]]);

    try {
        obSvc()->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => 25000], $bill->version, $this->owner);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors()['cash_drawer'][0])->toBe(__('Cash drawer is not opened'));
    }
    expect(Selling::count())->toBe(0)->and(OpenBill::find($bill->id)->status)->toBe(OpenBillStatus::Open);

    $drawer = CashDrawer::create(['opened_by' => $this->owner->id, 'cash' => 0]);
    $sale = obSvc()->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => 25000], $bill->version, $this->owner)->selling->fresh();

    expect($sale->cash_drawer_id)->toBe($drawer->id)->and($sale->user_id)->toBe($this->owner->id);
});

test('a drawer closed between the pre-check and the insert is still a message and not a server error', function () {
    Setting::set('cash_drawer_enabled', true);
    $drawer = CashDrawer::create(['opened_by' => $this->owner->id, 'cash' => 0]);
    $bill = obBill($this->owner, [[$this->nasi, 1]]);

    try {
        obRacing(fn () => $drawer->update(['closed_by' => $this->owner->id]))
            ->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => 25000], $bill->version, $this->owner);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('cash_drawer');
    }

    expect(Selling::count())->toBe(0)->and(OpenBill::find($bill->id)->status)->toBe(OpenBillStatus::Open)
        ->and($this->nasi->fresh()->stock)->toBe(50.0);
});

/*
|--------------------------------------------------------------------------
| voucher
|--------------------------------------------------------------------------
*/

test('a voucher is used once, and a payment that fails does not burn it', function () {
    $voucher = Voucher::factory()->create(['code' => 'HEMAT', 'nominal' => 2000, 'kuota' => 1, 'minimal_buying' => 0]);
    $bill = obBill($this->owner, [[$this->nasi, 1]]);

    // cash too short: the failure comes after the voucher was mapped
    expect(fn () => obSvc()->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => 100, 'voucher' => 'HEMAT'], $bill->version, $this->owner))
        ->toThrow(ValidationException::class);
    expect($voucher->fresh()->kuota)->toBe(1);

    $sale = obSvc()->pay(OpenBill::find($bill->id), ['payment_method_id' => obMethod()->id, 'payed_money' => 30000, 'voucher' => 'HEMAT'], $bill->version, $this->owner)->selling->fresh();
    expect($voucher->fresh()->kuota)->toBe(0)->and((float) $sale->discount_price)->toBe(2000.0)->and((float) $sale->money_changes)->toBe(7000.0);

    // the quota is gone: the next bill is not discounted
    $second = obBill($this->owner, [[$this->nasi, 1]]);
    $plain = obSvc()->pay($second, ['payment_method_id' => obMethod()->id, 'payed_money' => 30000, 'voucher' => 'HEMAT'], $second->version, $this->owner)->selling->fresh();
    expect((float) $plain->discount_price)->toBe(0.0);
});

test('a voucher that runs out between the pre-check and the sale rolls the whole payment back', function () {
    $voucher = Voucher::factory()->create(['code' => 'HEMAT', 'nominal' => 2000, 'kuota' => 1, 'minimal_buying' => 0]);
    $bill = obBill($this->owner, [[$this->nasi, 1]]);

    try {
        obRacing(fn () => $voucher->update(['kuota' => 0]))
            ->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => 30000, 'voucher' => 'HEMAT'], $bill->version, $this->owner);
        $this->fail('expected a voucher error');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('voucher');
    }

    expect(Selling::count())->toBe(0)->and(OpenBill::find($bill->id)->status)->toBe(OpenBillStatus::Open)
        ->and($this->nasi->fresh()->stock)->toBe(50.0);
});

/*
|--------------------------------------------------------------------------
| kitchen at payment time
|--------------------------------------------------------------------------
*/

test('paying with changes the kitchen has not seen records pay_with_unsent, and the ticket can still be built from the batch taken before', function () {
    $table = App\Models\Tenants\Table::create(['number' => '6']);
    $bill = obBill($this->owner, [[$this->nasi, 1]], ['table_id' => $table->id]);
    obSend($bill, $this->owner);
    obSvc()->addLine($bill->id, $this->teh, 2, null, null, $this->owner);
    $bill = OpenBill::find($bill->id);

    // the cashier screen builds the ticket BEFORE paying ...
    $batch = obSvc()->kitchenBatch($bill);
    expect($batch['kind'])->toBe('addition')->and($batch['pending_count'])->toBe(1);

    $result = obSvc()->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => 100000], $bill->version, $this->owner);

    // ... pay() leaves the printing to the screen ...
    expect($result->kitchen)->toBeNull()
        ->and(obActions($bill))->toContain('paid')->toContain('pay_with_unsent');
    $unsent = OpenBillEvent::where('open_bill_id', $bill->id)->where('action', 'pay_with_unsent')->sole();
    expect($unsent->detail)->toMatchArray(['pending_count' => 1]);

    // ... which prints the batch it kept, after the bill is paid
    $payload = obSvc()->printPayload(OpenBill::find($bill->id), 'kitchen', $batch, false, $this->owner);
    expect($payload['job_key'])->toBe('k'.$bill->id.'-2')->and($payload['kitchen']['kind'])->toBe('addition')
        ->and($payload['kitchen']['table_label'])->toBe('6')->and($payload['selling_details'])->toHaveCount(1);
    expect(obSvc()->kitchenBatch(OpenBill::find($bill->id)))->toBeNull();
});

test('paying a bill the kitchen has fully seen writes no pay_with_unsent event', function () {
    $bill = obBill($this->owner, [[$this->nasi, 1]]);
    obSend($bill, $this->owner);

    obSvc()->pay(OpenBill::find($bill->id), ['payment_method_id' => obMethod()->id, 'payed_money' => 25000], OpenBill::find($bill->id)->version, $this->owner);

    expect(obActions($bill))->not->toContain('pay_with_unsent')->toContain('paid');
});

/*
|--------------------------------------------------------------------------
| sizes
|--------------------------------------------------------------------------
*/

test('a bill of 150 long-noted lines is paid in one sale', function () {
    $lines = [];
    for ($i = 0; $i < 150; $i++) {
        $lines[] = [obProduct('Menu '.$i, 1000, 5), 1];
    }
    $bill = obBill($this->owner, $lines);
    foreach ($bill->items as $item) {
        obSvc()->setLineNote($bill->id, $item->id, str_repeat('x', 250), $this->owner);
    }
    $bill = OpenBill::find($bill->id);

    $sale = obSvc()->pay($bill, ['payment_method_id' => obMethod()->id, 'payed_money' => 200000], $bill->version, $this->owner)->selling->fresh();

    expect($sale->sellingDetails()->count())->toBe(150)->and((float) $sale->total_price)->toBe(150000.0)
        ->and($sale->sellingDetails()->first()->note)->toBe(str_repeat('x', 250));
});
