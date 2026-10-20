<?php

use App\Filament\Tenant\Pages\Cashier;
use App\Models\Tenants\CartItem;
use App\Models\Tenants\Category;
use App\Models\Tenants\Member;
use App\Models\Tenants\OpenBill;
use App\Models\Tenants\PaymentMethod;
use App\Models\Tenants\Product;
use App\Models\Tenants\Selling;
use App\Models\Tenants\Setting;
use App\Models\Tenants\Table;
use App\Models\Tenants\User;
use App\Services\Tenants\CashierDraftService;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;

uses(RefreshDatabaseWithTenant::class);

/*
|--------------------------------------------------------------------------
| The customer name and the draft of a new order, on the cashier page with its real view
|--------------------------------------------------------------------------
|
| The name is kept exactly as typed; the page only hints what the sale will be recorded as ("Andi (2)") and the
| number is given once, by the server, when the sale is made. The header of a NEW order (name, note, member,
| table) survives a reload as a draft of that cashier, as long as the cart has lines. The numbering service itself
| is tested in CustomerNameNumberingTest.
*/

if (! function_exists('cdPay')) {
    /** Pays the cart of the signed-in cashier in cash, through the page. */
    function cdPay(Testable $page, string $money = '100000'): Testable
    {
        return $page->call('syncCartForPayment')
            ->set('cartDetail.payment_method_id', PaymentMethod::where('name', 'Cash')->value('id'))
            ->set('cartDetail.payed_money', $money)
            ->call('proceedThePayment')
            ->assertHasNoErrors();
    }
}

if (! function_exists('cdLine')) {
    function cdLine(User $user, Product $product): CartItem
    {
        return CartItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'qty' => 1,
            'price' => $product->selling_price,
        ]);
    }
}

if (! function_exists('cdDraft')) {
    function cdDraft(User $user): array
    {
        return app(CashierDraftService::class)->load($user->id);
    }
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 05:00:00', 'UTC')); // 12:00 WIB
    Setting::set('selling_method', 'fifo');
    Setting::set('default_tax', 0);
    Setting::set('cash_drawer_enabled', false);
    Cache::clear();
    Filament::setCurrentPanel(Filament::getPanel('tenant'));

    $this->owner = User::first();
    actingAs($this->owner);

    $this->soto = Product::factory()->create([
        'name' => 'Soto', 'initial_price' => 10000, 'selling_price' => 20000, 'stock' => 50,
        'category_id' => Category::first()->id,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('the name is kept as typed; saving the order details again never numbers it', function () {
    Selling::create([
        'customer_name' => 'Andi', 'total_price' => 20000, 'payed_money' => 20000, 'money_changes' => 0,
        'total_qty' => 1, 'date' => now()->toDateString(),
    ]);
    cdLine($this->owner, $this->soto);

    $page = Livewire::test(Cashier::class)
        ->call('commitCustomerName', '  Andi  ')
        ->assertSet('cartDetail.customer_name', 'Andi')
        ->assertSet('customerNameHint', 'Andi (2)')
        ->assertSeeHtml('data-testid="customer-name-hint"');

    $page->call('storeCart')->call('storeCart')->call('syncCartForPayment')
        ->assertSet('cartDetail.customer_name', 'Andi')
        ->assertSet('customerNameHint', 'Andi (2)');

    // A new name without a sale of its own today: no hint.
    $page->call('commitCustomerName', 'Siti')->assertSet('customerNameHint', null)
        ->assertDontSeeHtml('data-testid="customer-name-hint"');

    // At most 240 characters, blank is no name.
    $page->call('commitCustomerName', str_repeat('b', 300));
    expect(mb_strlen($page->get('cartDetail.customer_name')))->toBe(240);

    $page->call('commitCustomerName', '   ')->assertSet('cartDetail.customer_name', null);
});

test('the sale numbers a name already used today, once, and the next order starts blank', function () {
    Selling::create([
        'customer_name' => 'Andi', 'total_price' => 20000, 'payed_money' => 20000, 'money_changes' => 0,
        'total_qty' => 1, 'date' => now()->toDateString(),
    ]);

    cdLine($this->owner, $this->soto);
    $page = Livewire::test(Cashier::class)->call('commitCustomerName', 'Andi');
    cdPay($page);

    expect(Selling::query()->latest('id')->value('customer_name'))->toBe('Andi (2)');
    $page->assertSet('cartDetail.customer_name', null)
        ->assertSet('customerNameHint', null);
    expect(cdDraft($this->owner))->toBe([]);

    cdLine($this->owner, $this->soto);
    cdPay(Livewire::test(Cashier::class)->call('commitCustomerName', 'Andi'));
    expect(Selling::query()->latest('id')->value('customer_name'))->toBe('Andi (3)');

    // No name stays no name.
    cdLine($this->owner, $this->soto);
    cdPay(Livewire::test(Cashier::class)->call('commitCustomerName', ''));
    expect(Selling::query()->latest('id')->value('customer_name'))->toBeNull();
});

test('the header of a new order comes back after a reload: name, note, member and table', function () {
    $member = Member::create(['name' => 'Pak Budi']);
    $table = Table::create(['number' => '7']);
    cdLine($this->owner, $this->soto);

    Livewire::test(Cashier::class)
        ->call('commitCustomerName', 'Budi')
        ->call('selectTable', $table->id)
        ->set('cartDetail.note', 'Tanpa sambal')
        ->set('cartDetail.member_id', $member->id)
        ->call('storeCart');

    expect(cdDraft($this->owner))->toBe([
        'customer_name' => 'Budi', 'table_id' => $table->id, 'note' => 'Tanpa sambal', 'member_id' => $member->id,
    ]);

    Livewire::test(Cashier::class)
        ->assertSet('draftRestored', true)
        ->assertSet('cartDetail.customer_name', 'Budi')
        ->assertSet('cartDetail.note', 'Tanpa sambal')
        ->assertSet('cartDetail.member_id', $member->id)
        ->assertSet('cartDetail.member_label', 'Pak Budi')
        ->assertSet('cartDetail.table_id', $table->id)
        ->assertSet('cartDetail.table_label', '7')
        ->assertSeeHtml('data-testid="draft-restored"');
});

test('a draft whose table or member was deleted brings back the rest', function () {
    $member = Member::create(['name' => 'Pak Budi']);
    $table = Table::create(['number' => '7']);
    cdLine($this->owner, $this->soto);
    app(CashierDraftService::class)->save($this->owner->id, [
        'customer_name' => 'Budi', 'member_id' => $member->id, 'table_id' => $table->id,
    ]);

    $member->delete();
    $table->delete();

    Livewire::test(Cashier::class)
        ->assertSet('draftRestored', true)
        ->assertSet('cartDetail.customer_name', 'Budi')
        ->assertSet('cartDetail.member_id', null)
        ->assertSet('cartDetail.member_label', null)
        ->assertSet('cartDetail.table_id', null)
        ->assertSet('cartDetail.table_label', null);
});

test('without lines there is no draft: an empty cart forgets it', function () {
    app(CashierDraftService::class)->save($this->owner->id, ['customer_name' => 'Budi', 'note' => 'lama']);

    Livewire::test(Cashier::class)
        ->assertSet('draftRestored', false)
        ->assertSet('cartDetail.customer_name', null)
        ->assertDontSeeHtml('data-testid="draft-restored"');
    expect(cdDraft($this->owner))->toBe([]);

    // Removing the last line forgets the draft too.
    $line = cdLine($this->owner, $this->soto);
    $page = Livewire::test(Cashier::class)->call('commitCustomerName', 'Budi');
    expect(cdDraft($this->owner))->toBe(['customer_name' => 'Budi']);

    $page->call('deleteCart', $line->id)->assertSet('draftRestored', false);
    expect(cdDraft($this->owner))->toBe([]);
});

test('"discard draft" empties the header, keeps the lines and forgets the draft', function () {
    $table = Table::create(['number' => '3']);
    cdLine($this->owner, $this->soto);
    app(CashierDraftService::class)->save($this->owner->id, [
        'customer_name' => 'Budi', 'note' => 'pedas', 'table_id' => $table->id,
    ]);

    Livewire::test(Cashier::class)
        ->assertSet('draftRestored', true)
        ->call('discardDraft')
        ->assertDispatched('order-reset')
        ->assertSet('draftRestored', false)
        ->assertSet('cartDetail.customer_name', null)
        ->assertSet('cartDetail.note', null)
        ->assertSet('cartDetail.table_id', null)
        ->assertSet('customerNameHint', null);

    expect(cdDraft($this->owner))->toBe([])
        ->and(CartItem::count())->toBe(1);
});

test('a draft belongs to one cashier', function () {
    $other = User::create(['email' => 'kasir2@example.test', 'password' => bcrypt('x'), 'name' => 'Kasir Dua']);
    cdLine($this->owner, $this->soto);
    cdLine($other, $this->soto);

    Livewire::test(Cashier::class)->call('commitCustomerName', 'Budi');

    actingAs($other);
    Livewire::test(Cashier::class)
        ->assertSet('draftRestored', false)
        ->assertSet('cartDetail.customer_name', null);
    expect(cdDraft($other))->toBe([])
        ->and(cdDraft($this->owner))->toBe(['customer_name' => 'Budi']);
});

test('saving the order as a bill moves the header to the bill; an open bill never writes or restores the draft', function () {
    $table = Table::create(['number' => '5']);
    cdLine($this->owner, $this->soto);

    $page = Livewire::test(Cashier::class)
        ->call('commitCustomerName', 'Budi')
        ->call('selectTable', $table->id)
        ->call('saveBill');

    $bill = OpenBill::sole();
    expect($bill->customer_name)->toBe('Budi')
        ->and($bill->table_id)->toBe($table->id)
        ->and(cdDraft($this->owner))->toBe([]);
    $page->assertSet('cartDetail.customer_name', null)->assertSet('cartDetail.table_id', null);

    // The name and the order details edited on an open bill go to the bill, not to the draft of the new order.
    $page->call('openBill', $bill->id)->call('commitCustomerName', 'Budi Santoso')
        ->set('cartDetail.note', 'Meja pojok')->call('storeCart');
    expect($bill->fresh()->customer_name)->toBe('Budi Santoso')
        ->and($bill->fresh()->note)->toBe('Meja pojok')
        ->and(cdDraft($this->owner))->toBe([]);

    // A draft left from a new order is not laid over the bill that comes back after a reload.
    cdLine($this->owner, $this->soto);
    app(CashierDraftService::class)->save($this->owner->id, ['customer_name' => 'Orang lain']);
    Livewire::test(Cashier::class)
        ->assertSet('openBillId', $bill->id)
        ->assertSet('draftRestored', false)
        ->assertSet('cartDetail.customer_name', 'Budi Santoso');

    // Back to the new order: its own draft is there again.
    Livewire::test(Cashier::class)->call('leaveBill')
        ->assertSet('openBillId', null)
        ->assertSet('cartDetail.customer_name', 'Orang lain')
        ->assertSet('draftRestored', true);
});
