<?php

use App\Filament\Tenant\Pages\Printer;
use App\Models\Tenants\Product;
use App\Models\Tenants\Setting;
use App\Models\Tenants\User;
use Illuminate\Support\Facades\Cache;
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

test('cashier can create selling transaction with customer_name', function () {
    $user = User::first();

    $response = actingAs($user)->postJson('/api/transaction/selling', [
        'customer_name' => 'Budi Santoso',
        'payed_money' => 30000,
        'friend_price' => false,
        'products' => [
            [
                'product_id' => $this->product->id,
                'qty' => 1,
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'success create selling')
        ->assertJsonPath('data.customer_name', 'Budi Santoso')
        ->assertJsonPath('data.daily_order_number', 1)
        ->assertJsonPath('data.formatted_daily_order_number', 'Order #001');

    $this->assertDatabaseHas('sellings', [
        'customer_name' => 'Budi Santoso',
        'daily_order_number' => 1,
        'total_price' => 25000,
    ]);

    // Second transaction should auto-increment to 2
    $response2 = actingAs($user)->postJson('/api/transaction/selling', [
        'customer_name' => 'Siti Rahma',
        'payed_money' => 30000,
        'friend_price' => false,
        'products' => [
            [
                'product_id' => $this->product->id,
                'qty' => 1,
            ],
        ],
    ]);

    $response2->assertOk()
        ->assertJsonPath('data.daily_order_number', 2)
        ->assertJsonPath('data.formatted_daily_order_number', 'Order #002');
});

test('cashier can create selling transaction without customer_name as optional', function () {
    $user = User::first();

    $response = actingAs($user)->postJson('/api/transaction/selling', [
        'payed_money' => 30000,
        'friend_price' => false,
        'products' => [
            [
                'product_id' => $this->product->id,
                'qty' => 1,
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'success create selling')
        ->assertJsonPath('data.customer_name', null);

    $this->assertDatabaseHas('sellings', [
        'customer_name' => null,
        'total_price' => 25000,
    ]);
});

test('receipt printer settings can be saved and retrieved on server', function () {
    $printerPage = new Printer();
    $printerPage->saveToServer([
        'header' => 'Header Toko Kami',
        'footer' => 'Terima kasih atas kunjungan Anda',
        'paper_width' => '80',
        'logo' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    ]);

    expect(Setting::get('receipt_header'))->toBe('Header Toko Kami');
    expect(Setting::get('receipt_footer'))->toBe('Terima kasih atas kunjungan Anda');
    expect(Setting::get('receipt_paper_width'))->toBe('80');
    expect(Setting::get('receipt_logo'))->toContain('data:image/png;base64');
});
