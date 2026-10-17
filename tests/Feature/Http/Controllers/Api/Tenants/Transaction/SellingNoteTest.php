<?php

use App\Models\Tenants\Product;
use App\Models\Tenants\SellingDetail;
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
        'name' => 'Produk Catatan',
        'initial_price' => 10000,
        'selling_price' => 25000,
        'stock' => 20,
    ]);
    $this->sell = fn (array $line) => actingAs(User::first())->postJson('/api/transaction/selling', [
        'payed_money' => 25000,
        'friend_price' => false,
        'products' => [array_merge(['product_id' => $this->product->id, 'qty' => 1], $line)],
    ]);
});

test('an item note is stored on the sold line and returned by the api', function () {
    $response = ($this->sell)(['note' => 'tanpa gula']);

    $response->assertOk()
        ->assertJsonPath('message', 'success create selling')
        ->assertJsonPath('data.selling_details.0.note', 'tanpa gula');

    $this->assertDatabaseHas('selling_details', ['product_id' => $this->product->id, 'note' => 'tanpa gula']);
});

test('the stored note is returned when the sale is read back', function () {
    $id = ($this->sell)(['note' => 'extra es'])->json('data.id');

    actingAs(User::first())->getJson("/api/transaction/selling/{$id}")
        ->assertOk()
        ->assertJsonPath('data.selling_details.0.note', 'extra es');
});

test('a request from an old client without a note key still works and the note is null', function () {
    $response = ($this->sell)([]);

    $response->assertOk()
        ->assertJsonPath('message', 'success create selling')
        ->assertJsonPath('data.selling_details.0.note', null);

    expect(SellingDetail::first()->note)->toBeNull();
});

test('a null or empty note is accepted and stored as null', function (mixed $note) {
    $response = ($this->sell)(['note' => $note]);

    $response->assertOk();
    expect(SellingDetail::first()->note)->toBeNull();
})->with([
    'null' => [null],
    'empty string' => [''],
]);

test('a note of exactly 255 characters is accepted', function () {
    $note = str_repeat('a', 255);

    ($this->sell)(['note' => $note])->assertOk();

    expect(SellingDetail::first()->note)->toBe($note);
});

test('a note of 256 characters is rejected with 422 on that line and nothing is sold', function () {
    ($this->sell)(['note' => str_repeat('a', 256)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['products.0.note']);

    expect(SellingDetail::count())->toBe(0);
    expect($this->product->fresh()->stock)->toBe(20.0);
});

test('a note that is not a string is rejected', function () {
    ($this->sell)(['note' => ['a', 'b']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['products.0.note']);
});

test('control characters in a note are replaced by spaces and whitespace is collapsed', function () {
    ($this->sell)(['note' => "pedas\x1b\x1d\x56\x00  banget\n\n tanpa   bawang "])->assertOk();

    expect(SellingDetail::first()->note)->toBe('pedas V banget tanpa bawang');
});

test('different lines keep their own notes', function () {
    $other = Product::factory()->create([
        'name' => 'Produk Lain',
        'initial_price' => 1000,
        'selling_price' => 5000,
        'stock' => 10,
    ]);

    actingAs(User::first())->postJson('/api/transaction/selling', [
        'payed_money' => 30000,
        'friend_price' => false,
        'products' => [
            ['product_id' => $this->product->id, 'qty' => 1, 'note' => 'catatan satu'],
            ['product_id' => $other->id, 'qty' => 1],
        ],
    ])->assertOk();

    expect(SellingDetail::where('product_id', $this->product->id)->first()->note)->toBe('catatan satu');
    expect(SellingDetail::where('product_id', $other->id)->first()->note)->toBeNull();
});

test('the stock check still applies to a line that has a note', function () {
    actingAs(User::first())->postJson('/api/transaction/selling', [
        'payed_money' => 25000,
        'friend_price' => false,
        'products' => [['product_id' => $this->product->id, 'qty' => 21, 'note' => 'banyak']],
    ])->assertUnprocessable()->assertJsonValidationErrors(['products.0.qty']);
});
