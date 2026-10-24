<?php

use App\Enums\OpenBillStatus;
use App\Filament\Tenant\Pages\Cashier;
use App\Livewire\Cashier\CartItemNote;
use App\Models\Tenants\CartItem;
use App\Models\Tenants\Category;
use App\Models\Tenants\OpenBill;
use App\Models\Tenants\OpenBillItem;
use App\Models\Tenants\PaymentMethod;
use App\Models\Tenants\Product;
use App\Models\Tenants\SellingDetail;
use App\Models\Tenants\Setting;
use App\Models\Tenants\User;
use App\Services\Tenants\OpenBillService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;

uses(RefreshDatabaseWithTenant::class);

/*
|--------------------------------------------------------------------------
| The note of an order line ("tanpa gula")
|--------------------------------------------------------------------------
|
| The note box (App\Livewire\Cashier\CartItemNote) on the private cart, and what happens to the note when the line
| changes, is removed and restored, and is paid. The note box on a bill line is also covered in CashierOpenBillTest.
*/

if (! function_exists('inLine')) {
    function inLine(User $user, Product $product, float $qty = 1, ?string $note = null): CartItem
    {
        return CartItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'qty' => $qty,
            'price' => $product->selling_price * $qty,
            'note' => $note,
        ]);
    }
}

if (! function_exists('inNoteBox')) {
    function inNoteBox(int $lineId, ?string $note = null, string $context = 'cart', ?int $billId = null)
    {
        return Livewire::test(CartItemNote::class, [
            'cartItemId' => $lineId,
            'note' => $note,
            'context' => $context,
            'billId' => $billId,
        ]);
    }
}

beforeEach(function () {
    Setting::set('selling_method', 'fifo');
    Setting::set('default_tax', 0);
    Setting::set('cash_drawer_enabled', false);
    Cache::clear();
    Filament::setCurrentPanel(Filament::getPanel('tenant'));

    $this->owner = User::first();
    actingAs($this->owner);

    $this->kopi = Product::factory()->create([
        'name' => 'Kopi Susu', 'initial_price' => 5000, 'selling_price' => 15000, 'stock' => 50,
        'category_id' => Category::first()->id,
    ]);
});

test('a note is saved trimmed, on one line, without control characters and at most 255 characters', function () {
    $line = inLine($this->owner, $this->kopi);

    inNoteBox($line->id)
        ->call('save', "  tanpa gula  ")
        ->assertSet('note', 'tanpa gula')
        ->assertDispatched('cart-item-note-saved', id: $line->id, note: 'tanpa gula');
    expect($line->fresh()->note)->toBe('tanpa gula');

    inNoteBox($line->id)->call('save', "es\x1b@ sedikit\r\nsaja\tya");
    expect($line->fresh()->note)->toBe('es @ sedikit saja ya');

    inNoteBox($line->id)->call('save', str_repeat('x', 300));
    expect($line->fresh()->note)->toBe(str_repeat('x', 255));

    inNoteBox($line->id)->call('save', 'éè “kutip” 😀');
    expect($line->fresh()->note)->toBe('éè “kutip” 😀');
});

test('an empty or blank note is no note', function () {
    $line = inLine($this->owner, $this->kopi, 1, 'tanpa gula');

    inNoteBox($line->id, 'tanpa gula')->call('save', '   ')->assertSet('note', '');
    expect($line->fresh()->note)->toBeNull();

    $line->update(['note' => 'lagi']);
    inNoteBox($line->id, 'lagi')->call('save', null);
    expect($line->fresh()->note)->toBeNull();
});

test('a cashier never writes the note of a line of another cashier', function () {
    $other = User::create(['email' => 'kasir2@example.test', 'password' => bcrypt('x'), 'name' => 'Kasir Dua']);
    $theirs = inLine($other, $this->kopi, 1, 'punya mereka');

    inNoteBox($theirs->id)->call('save', 'diubah')->assertNotDispatched('cart-item-note-saved');

    expect($theirs->fresh()->note)->toBe('punya mereka');
});

test('the note stays with the line when its quantity changes', function () {
    $line = inLine($this->owner, $this->kopi, 1, 'tanpa gula');
    $page = Livewire::test(Cashier::class);

    $page->call('addCart', $this->kopi->id);
    expect($line->fresh()->qty)->toEqual(2)->and($line->fresh()->note)->toBe('tanpa gula');

    $page->call('addCart', $this->kopi->id, ['amount' => '5']);
    expect($line->fresh()->qty)->toEqual(5)->and($line->fresh()->note)->toBe('tanpa gula');

    $page->call('reduceCart', $this->kopi->id);
    expect($line->fresh()->qty)->toEqual(4)->and($line->fresh()->note)->toBe('tanpa gula');
});

test('a removed line can be undone with its note', function () {
    $line = inLine($this->owner, $this->kopi, 2, 'tanpa gula');
    $page = Livewire::test(Cashier::class);

    // The event goes to the browser as JSON: 2.0 arrives as 2.
    $page->call('deleteCart', $line->id)
        ->assertDispatched('cart-item-removed', fn (string $name, array $params): bool => $params['productId'] === $this->kopi->id
            && (float) $params['qty'] === 2.0
            && $params['note'] === 'tanpa gula'
            && $params['undoable'] === true);
    expect(CartItem::count())->toBe(0);

    $page->call('restoreCartItem', $this->kopi->id, 2, 'tanpa gula');
    $restored = CartItem::sole();
    expect($restored->qty)->toEqual(2)->and($restored->note)->toBe('tanpa gula');
});

test('the note of each line goes into the sale', function () {
    $teh = Product::factory()->create([
        'name' => 'Es Teh', 'initial_price' => 1000, 'selling_price' => 5000, 'stock' => 50,
        'category_id' => Category::first()->id,
    ]);
    inLine($this->owner, $this->kopi, 1, 'tanpa gula');
    inLine($this->owner, $teh, 2);

    Livewire::test(Cashier::class)
        ->call('syncCartForPayment')
        ->set('cartDetail.payment_method_id', PaymentMethod::where('name', 'Cash')->value('id'))
        ->set('cartDetail.payed_money', '50000')
        ->call('proceedThePayment')
        ->assertHasNoErrors();

    expect(SellingDetail::where('product_id', $this->kopi->id)->value('note'))->toBe('tanpa gula')
        ->and(SellingDetail::where('product_id', $teh->id)->value('note'))->toBeNull()
        ->and(CartItem::count())->toBe(0);
});

test('clearing the order removes the lines with their notes', function () {
    inLine($this->owner, $this->kopi, 1, 'tanpa gula');

    Livewire::test(Cashier::class)->call('clearCart');

    expect(CartItem::count())->toBe(0);
});

test('the cart shows a note as text, never as markup', function () {
    inLine($this->owner, $this->kopi, 1, '<script>alert(1)</script> tanpa gula');

    $html = Livewire::test(Cashier::class)->html();

    expect($html)->toContain('tanpa gula')
        ->not->toContain('<script>alert(1)</script>');
});

test('the note box of a bill line that is gone, or of a bill that is closed, makes the page reload', function () {
    inLine($this->owner, $this->kopi, 1, 'tanpa gula');
    $bill = app(OpenBillService::class)->createFromCart($this->owner, []);
    $item = OpenBillItem::where('open_bill_id', $bill->id)->sole();

    // A line that is gone: nothing is written and the page reloads the bill.
    inNoteBox(999999, null, 'bill', $bill->id)
        ->call('save', 'baru')
        ->assertDispatched('open-bill-changed', fn (string $name, array $params): bool => $params['id'] === $bill->id);
    expect($item->fresh()->note)->toBe('tanpa gula');

    OpenBill::whereKey($bill->id)->update(['status' => OpenBillStatus::Paid->value]);

    inNoteBox($item->id, 'tanpa gula', 'bill', $bill->id)
        ->call('save', 'baru')
        ->assertDispatched('open-bill-changed', id: $bill->id)
        ->assertNotDispatched('cart-item-note-saved');
    expect($item->fresh()->note)->toBe('tanpa gula');
});
