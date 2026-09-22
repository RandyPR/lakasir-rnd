<?php

use App\Filament\Tenant\Pages\Cashier;
use App\Models\Tenants\CartItem;
use App\Models\Tenants\Product;
use App\Models\Tenants\Selling;
use App\Models\Tenants\Setting;
use App\Models\Tenants\User;
use App\Services\Tenants\CustomerNameService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;

uses(RefreshDatabaseWithTenant::class);

beforeEach(function () {
    Setting::set('selling_method', 'fifo');
    Cache::clear();
    $this->product = Product::factory()->create([
        'name' => 'Produk Test',
        'initial_price' => 10000,
        'selling_price' => 25000,
        'stock' => 20,
    ]);
});

test('customer name service formats duplicate names per day correctly', function () {
    // 1. Initial name
    expect(CustomerNameService::generateDailyCustomerName('Budi'))->toBe('Budi');
    expect(CustomerNameService::generateDailyCustomerName(null))->toBeNull();
    expect(CustomerNameService::generateDailyCustomerName('   '))->toBeNull();

    // Create a selling today with "Budi"
    Selling::create([
        'customer_name' => 'Budi',
        'total_price' => 25000,
        'payed_money' => 25000,
        'money_changes' => 0,
        'total_qty' => 1,
        'date' => now()->toDateString(),
    ]);

    // 2. Duplicate case-insensitive
    expect(CustomerNameService::generateDailyCustomerName('Budi'))->toBe('Budi (2)');
    expect(CustomerNameService::generateDailyCustomerName('budi'))->toBe('budi (2)');
    expect(CustomerNameService::generateDailyCustomerName('BUDI'))->toBe('BUDI (2)');

    // Create another selling today with "budi (2)"
    Selling::create([
        'customer_name' => 'budi (2)',
        'total_price' => 25000,
        'payed_money' => 25000,
        'money_changes' => 0,
        'total_qty' => 1,
        'date' => now()->toDateString(),
    ]);

    // 3. Third increment
    expect(CustomerNameService::generateDailyCustomerName('Budi'))->toBe('Budi (3)');

    // 4. Manually typed suffix cleanses and increments
    expect(CustomerNameService::generateDailyCustomerName('Budi (2)'))->toBe('Budi (3)');

    // 5. Tomorrow's date resets counter
    $tomorrow = now()->addDay()->toDateString();
    expect(CustomerNameService::generateDailyCustomerName('Budi', $tomorrow))->toBe('Budi');
});

test('creating selling transactions auto-numbers duplicate customer names within the same day', function () {
    $user = User::first();

    // 1. First transaction: "Test"
    $response1 = actingAs($user)->postJson('/api/transaction/selling', [
        'customer_name' => 'Test',
        'payed_money' => 30000,
        'friend_price' => false,
        'products' => [
            ['product_id' => $this->product->id, 'qty' => 1],
        ],
    ]);

    $response1->assertOk()
        ->assertJsonPath('data.customer_name', 'Test');

    // 2. Second transaction same day: "Test" -> auto-numbered to "Test (2)"
    $response2 = actingAs($user)->postJson('/api/transaction/selling', [
        'customer_name' => 'Test',
        'payed_money' => 30000,
        'friend_price' => false,
        'products' => [
            ['product_id' => $this->product->id, 'qty' => 1],
        ],
    ]);

    $response2->assertOk()
        ->assertJsonPath('data.customer_name', 'Test (2)');

    // 3. Third transaction same day with lowercase: "test" -> auto-numbered to "test (3)"
    $response3 = actingAs($user)->postJson('/api/transaction/selling', [
        'customer_name' => 'test',
        'payed_money' => 30000,
        'friend_price' => false,
        'products' => [
            ['product_id' => $this->product->id, 'qty' => 1],
        ],
    ]);

    $response3->assertOk()
        ->assertJsonPath('data.customer_name', 'test (3)');

    // 4. Fourth transaction same day manually typing "Test (2)" -> auto-numbered to "Test (4)"
    $response4 = actingAs($user)->postJson('/api/transaction/selling', [
        'customer_name' => 'Test (2)',
        'payed_money' => 30000,
        'friend_price' => false,
        'products' => [
            ['product_id' => $this->product->id, 'qty' => 1],
        ],
    ]);

    $response4->assertOk()
        ->assertJsonPath('data.customer_name', 'Test (4)');

    // 5. Unrelated customer name "Siti" is not affected
    $response5 = actingAs($user)->postJson('/api/transaction/selling', [
        'customer_name' => 'Siti',
        'payed_money' => 30000,
        'friend_price' => false,
        'products' => [
            ['product_id' => $this->product->id, 'qty' => 1],
        ],
    ]);

    $response5->assertOk()
        ->assertJsonPath('data.customer_name', 'Siti');
});

test('cashier Livewire component auto formats duplicate customer names', function () {
    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('tenant'));
    $user = User::first();

    // Create an existing selling with "Andi" today
    Selling::create([
        'customer_name' => 'Andi',
        'total_price' => 25000,
        'payed_money' => 25000,
        'money_changes' => 0,
        'total_qty' => 1,
        'date' => now()->toDateString(),
    ]);

    Livewire::actingAs($user)
        ->test(Cashier::class)
        ->set('cartDetail.customer_name', 'Andi')
        ->assertSet('cartDetail.customer_name', 'Andi (2)');
});
