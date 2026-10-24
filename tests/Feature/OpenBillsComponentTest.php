<?php

use App\Enums\OpenBillStatus;
use App\Livewire\Cashier\OpenBills;
use App\Models\Tenants\About;
use App\Models\Tenants\CartItem;
use App\Models\Tenants\Category;
use App\Models\Tenants\OpenBill;
use App\Models\Tenants\Product;
use App\Models\Tenants\Table;
use App\Models\Tenants\User;
use App\Services\Tenants\OpenBillService;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;

uses(RefreshDatabaseWithTenant::class);

/*
|--------------------------------------------------------------------------
| The open bills drawer (App\Livewire\Cashier\OpenBills) with its real view
|--------------------------------------------------------------------------
*/

if (! function_exists('obcBill')) {
    /** A bill made the way the cashier makes one: the cart of $user becomes the bill. */
    function obcBill(User $user, array $header, array $lines): OpenBill
    {
        foreach ($lines as [$product, $qty]) {
            CartItem::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'qty' => $qty,
                'price' => $product->selling_price * $qty,
            ]);
        }

        return app(OpenBillService::class)->createFromCart($user, $header);
    }
}

if (! function_exists('obcSend')) {
    /** The kitchen ticket of everything not sent yet, acknowledged as printed. */
    function obcSend(OpenBill $bill, User $user): void
    {
        $service = app(OpenBillService::class);
        $batch = $service->kitchenBatch($service->find($bill->id));

        $service->ackKitchenBatch($bill->id, $batch['ack'], $user);
    }
}

if (! function_exists('obcRows')) {
    /**
     * The bill ids of the drawer rows, in the order they are drawn.
     *
     * @return list<int>
     */
    function obcRows(string $html): array
    {
        preg_match_all('/data-testid="bill-row-(\d+)"/', $html, $matches);

        return array_map('intval', $matches[1]);
    }
}

if (! function_exists('obcCashier')) {
    function obcCashier(array $permissions = ['create selling']): User
    {
        $user = User::create([
            'email' => 'obc'.uniqid().'@example.test',
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

beforeEach(function () {
    $this->owner = User::first();
    actingAs($this->owner);
    About::query()->update(['business_type' => 'fnb']);

    $category = Category::first();
    $this->nasi = Product::factory()->create([
        'name' => 'Nasi Goreng', 'initial_price' => 10000, 'selling_price' => 25000, 'stock' => 50,
        'category_id' => $category->id,
    ]);
    $this->teh = Product::factory()->create([
        'name' => 'Es Teh', 'initial_price' => 1000, 'selling_price' => 5000, 'stock' => 50,
        'category_id' => $category->id,
    ]);

    // #001 Budi at table 5 (sent to the kitchen), #002 table 7, #003 without table or name.
    $this->budi = obcBill($this->owner, ['table_id' => Table::create(['number' => '5'])->id, 'customer_name' => 'Budi'], [
        [$this->nasi, 2], [$this->teh, 1],
    ]);
    obcSend($this->budi, $this->owner);
    $this->table7 = obcBill($this->owner, ['table_id' => Table::create(['number' => '7'])->id], [[$this->nasi, 1]]);
    $this->takeaway = obcBill($this->owner, [], [[$this->teh, 3]]);
});

test('the closed drawer only counts the bills and the ones the kitchen has not been told about', function () {
    $page = Livewire::test(OpenBills::class)
        ->assertViewHas('count', 3)
        ->assertViewHas('unsentCount', 2)
        ->assertViewHas('bills', fn (Collection $bills): bool => $bills->isEmpty())
        ->assertSeeHtml('data-testid="bills-unsent-dot"');

    expect(obcRows($page->html()))->toBe([]);
});

test('the open drawer lists the bills not sent to the kitchen first, then the oldest', function () {
    $page = Livewire::test(OpenBills::class)->call('setOpen', true);

    expect(obcRows($page->html()))->toBe([$this->table7->id, $this->takeaway->id, $this->budi->id]);
    $page->assertSee('Budi')
        ->assertSeeHtml('data-testid="bill-unsent-chip-'.$this->table7->id.'"')
        ->assertSeeHtml('data-testid="bill-sent-chip-'.$this->budi->id.'"')
        ->assertSeeHtml('data-testid="bills-table-chip-5"')
        ->assertSeeHtml('data-testid="bills-table-chip-7"');
});

test('the filters and the search narrow the list down', function () {
    $page = Livewire::test(OpenBills::class)->call('setOpen', true);

    $page->call('setFilter', 'unsent');
    expect(obcRows($page->html()))->toBe([$this->table7->id, $this->takeaway->id]);

    $page->call('setFilter', 'all')->set('search', '7');
    expect(obcRows($page->html()))->toBe([$this->table7->id]);

    // Digits are a bill or a table number, never a part of one.
    $page->set('search', '5');
    expect(obcRows($page->html()))->toBe([$this->budi->id]);

    $page->set('search', '#003');
    expect(obcRows($page->html()))->toBe([$this->takeaway->id]);

    $page->set('search', 'BUD');
    expect(obcRows($page->html()))->toBe([$this->budi->id]);

    $page->set('search', 'nobody');
    expect(obcRows($page->html()))->toBe([]);
    $page->assertSeeHtml('data-testid="bills-empty"');

    // A filter sent straight from the browser is normalized.
    $page->set('search', '')->set('filter', 'everything')->assertSet('filter', 'all');
});

test('"my bills" shows only the bills this cashier opened', function () {
    $other = obcCashier();
    $theirs = obcBill($other, [], [[$this->nasi, 1]]);

    $page = Livewire::test(OpenBills::class)->call('setOpen', true);
    expect(obcRows($page->html()))->toContain($theirs->id);

    $page->call('setFilter', 'mine');
    expect(obcRows($page->html()))->not->toContain($theirs->id)->toHaveCount(3);
});

test('a shop without a kitchen has no "not sent to the kitchen" state', function () {
    About::query()->update(['business_type' => 'retail']);

    Livewire::test(OpenBills::class)
        ->assertSet('kitchenEnabled', false)
        ->assertViewHas('unsentCount', 0)
        ->assertDontSeeHtml('data-testid="bills-unsent-dot"')
        ->call('setOpen', true)
        ->assertDontSeeHtml('data-testid="bills-filter-unsent"')
        ->assertDontSeeHtml('data-testid="bill-unsent-chip-');
});

test('voiding needs a reason and the version on screen, and tells the kitchen only about food it made', function () {
    $page = Livewire::test(OpenBills::class)->call('setOpen', true);

    $page->call('voidBill', $this->takeaway->id, '   ', (int) $this->takeaway->fresh()->version)
        ->assertHasErrors('void');
    $page->call('voidBill', $this->takeaway->id, 'Pelanggan pergi', 999)
        ->assertHasErrors('void');
    expect($this->takeaway->fresh()->status)->toBe(OpenBillStatus::Open);

    // Never sent: voided without a kitchen ticket.
    $page->call('voidBill', $this->takeaway->id, 'Pelanggan pergi', (int) $this->takeaway->fresh()->version)
        ->assertHasNoErrors()
        ->assertDispatched('bill-voided', id: $this->takeaway->id)
        ->assertNotDispatched('bill-print');
    expect($this->takeaway->fresh()->status)->toBe(OpenBillStatus::Void)
        ->and(obcRows($page->html()))->not->toContain($this->takeaway->id);

    // Sent: the kitchen gets a "bill voided" ticket.
    $page->call('voidBill', $this->budi->id, 'Pelanggan pergi', (int) $this->budi->fresh()->version)
        ->assertHasNoErrors()
        ->assertDispatched('bill-print', fn (string $name, array $params): bool => $params['mode'] === 'kitchen'
            && $params['reprint'] === false);
    expect($this->budi->fresh()->status)->toBe(OpenBillStatus::Void);
});

test('printing and previewing send the right job to this device', function () {
    $page = Livewire::test(OpenBills::class)->call('setOpen', true);

    $page->call('printProvisional', $this->table7->id)
        ->assertDispatched('bill-print', fn (string $name, array $params): bool => $params['mode'] === 'bill'
            && $params['reprint'] === false && (int) $params['bill']['id'] === $this->table7->id);

    // Nothing was sent yet, so there is no ticket to print again.
    Livewire::test(OpenBills::class)->call('reprintKitchen', $this->takeaway->id)->assertNotDispatched('bill-print');

    Livewire::test(OpenBills::class)->call('reprintKitchen', $this->budi->id)
        ->assertDispatched('bill-print', fn (string $name, array $params): bool => $params['mode'] === 'kitchen'
            && $params['reprint'] === true);

    Livewire::test(OpenBills::class)->call('previewBill', $this->table7->id, 'kitchen')
        ->assertDispatched('bill-preview', fn (string $name, array $params): bool => $params['mode'] === 'kitchen');
    Livewire::test(OpenBills::class)->call('previewBill', $this->table7->id, 'receipt')
        ->assertNotDispatched('bill-preview');
});

test('opening or paying a bill hands it to the cashier, unless it is no longer open', function () {
    Livewire::test(OpenBills::class)->call('setOpen', true)
        ->call('select', $this->table7->id)
        ->assertDispatched('open-bill-selected', id: $this->table7->id)
        ->assertSet('open', false);

    Livewire::test(OpenBills::class)->call('setOpen', true)
        ->call('pay', $this->table7->id)
        ->assertDispatched('open-bill-pay', id: $this->table7->id);

    OpenBill::whereKey($this->takeaway->id)->update(['status' => OpenBillStatus::Paid->value]);
    Livewire::test(OpenBills::class)->call('setOpen', true)
        ->call('select', $this->takeaway->id)
        ->assertNotDispatched('open-bill-selected')
        ->assertSet('open', true);
});

test('somebody who may not sell cannot see the bills', function () {
    actingAs(obcCashier([]));

    Livewire::test(OpenBills::class)->assertForbidden();
});
