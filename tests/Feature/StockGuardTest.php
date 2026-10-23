<?php

use App\Models\Tenants\PriceUnit;
use App\Models\Tenants\Product;
use App\Models\Tenants\Setting;
use App\Models\Tenants\User;
use App\Services\Tenants\StockGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;

uses(RefreshDatabaseWithTenant::class);

beforeEach(function () {
    Setting::set('selling_method', 'fifo');
    Cache::clear();
});

function guardProduct(string $name, float $stock, array $attributes = []): Product
{
    return Product::factory()->create(array_merge([
        'name' => $name,
        'initial_price' => 1000,
        'selling_price' => 2000,
        'stock' => $stock,
    ], $attributes));
}

/**
 * @param  array<int|string, array<string, mixed>>  $lines
 * @param  array<string, mixed>  $opts
 * @return array<string, array<int, string>> validation errors (empty when the guard passes)
 */
function guardErrors(array $lines, array $opts = []): array
{
    try {
        StockGuard::assert($lines, $opts);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

test('enough stock passes, an exact fit passes, one more fails', function () {
    $product = guardProduct('Pas', 5);

    expect(guardErrors([['product_id' => $product->id, 'qty' => 4]]))->toBe([]);
    expect(guardErrors([['product_id' => $product->id, 'qty' => 5]]))->toBe([]);
    expect(array_keys(guardErrors([['product_id' => $product->id, 'qty' => 6]])))->toBe(['products.0.qty']);
});

test('the message names the product and what is left', function () {
    $product = guardProduct('Nasi Goreng', 2);

    $errors = guardErrors([['product_id' => $product->id, 'qty' => 3]]);

    expect($errors['products.0.qty'])->toBe(['Stock of Nasi Goreng is not enough (left 2)']);
});

test('the error key uses the index of the line, so the form can show it on that line', function () {
    $ok = guardProduct('Cukup', 10);
    $short = guardProduct('Kurang', 1);

    $errors = guardErrors([
        ['product_id' => $ok->id, 'qty' => 1],
        ['product_id' => $short->id, 'qty' => 2],
    ]);

    expect(array_keys($errors))->toBe(['products.1.qty']);
});

test('the same product on several lines is added up and blamed on its first line', function () {
    $product = guardProduct('Dua Baris', 5);

    $errors = guardErrors([
        ['product_id' => $product->id, 'qty' => 3],
        ['product_id' => guardProduct('Lain', 9)->id, 'qty' => 1],
        ['product_id' => $product->id, 'qty' => 3],
    ]);

    expect(array_keys($errors))->toBe(['products.0.qty']);
    expect(guardErrors([
        ['product_id' => $product->id, 'qty' => 3],
        ['product_id' => $product->id, 'qty' => 2],
    ]))->toBe([]);
});

test('several short products give one error each', function () {
    $a = guardProduct('Satu', 1);
    $b = guardProduct('Dua', 1);

    $errors = guardErrors([
        ['product_id' => $a->id, 'qty' => 2],
        ['product_id' => $b->id, 'qty' => 2],
    ]);

    expect(array_keys($errors))->toBe(['products.0.qty', 'products.1.qty']);
});

test('quantities are compared at three decimals', function () {
    $product = guardProduct('Beras', 1.5);

    expect(guardErrors([['product_id' => $product->id, 'qty' => 1.5]]))->toBe([]);
    expect(guardErrors([['product_id' => $product->id, 'qty' => 1.6]]))->not->toBe([]);

    $thirds = guardProduct('Tiga Per Sepuluh', 0.3);
    expect(guardErrors([
        ['product_id' => $thirds->id, 'qty' => 0.1],
        ['product_id' => $thirds->id, 'qty' => 0.2],
    ]))->toBe([]);
});

test('qty may arrive as a string like in a request', function () {
    $product = guardProduct('Teh', 3);

    expect(guardErrors([['product_id' => (string) $product->id, 'qty' => '3']]))->toBe([]);
    expect(guardErrors([['product_id' => (string) $product->id, 'qty' => '4']]))->not->toBe([]);
});

/*
|--------------------------------------------------------------------------
| price units
|--------------------------------------------------------------------------
*/

test('a price-unit line needs qty times the stock of the unit', function () {
    $product = guardProduct('Air Mineral', 7);
    $box = PriceUnit::create(['product_id' => $product->id, 'unit' => 'dus', 'stock' => 4, 'selling_price' => 40000]);

    $errors = guardErrors([['product_id' => $product->id, 'qty' => 2, 'price_unit_id' => $box->id]]);

    expect($errors['products.0.qty'])->toBe(['Stock of Air Mineral is not enough (left 7)']);

    $product->forceFill(['stock' => 8])->save();
    expect(guardErrors([['product_id' => $product->id, 'qty' => 2, 'price_unit_id' => $box->id]]))->toBe([]);
});

test('plain lines and price-unit lines of one product are added up', function () {
    $product = guardProduct('Air Campur', 8);
    $box = PriceUnit::create(['product_id' => $product->id, 'unit' => 'dus', 'stock' => 4, 'selling_price' => 40000]);

    $lines = [
        ['product_id' => $product->id, 'qty' => 1, 'price_unit_id' => $box->id],
        ['product_id' => $product->id, 'qty' => 5],
    ];

    expect(array_keys(guardErrors($lines)))->toBe(['products.0.qty']);

    $product->forceFill(['stock' => 9])->save();
    expect(guardErrors($lines))->toBe([]);
});

test('an unknown price unit counts as one piece per qty', function () {
    $product = guardProduct('Satuan Hilang', 2);

    expect(guardErrors([['product_id' => $product->id, 'qty' => 2, 'price_unit_id' => 999999]]))->toBe([]);
    expect(guardErrors([['product_id' => $product->id, 'qty' => 3, 'price_unit_id' => 999999]]))->not->toBe([]);
});

test('a null price_unit_id is a plain line', function () {
    $product = guardProduct('Biasa', 2);

    expect(guardErrors([['product_id' => $product->id, 'qty' => 2, 'price_unit_id' => null]]))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| products that are never short, and products that are gone
|--------------------------------------------------------------------------
*/

test('non-stock goods and services are skipped', function () {
    $nonStock = guardProduct('Air Isi Ulang', 0, ['is_non_stock' => true]);
    $service = guardProduct('Potong Rambut', 0, ['type' => 'service']);

    expect(guardErrors([
        ['product_id' => $nonStock->id, 'qty' => 50],
        ['product_id' => $service->id, 'qty' => 50],
    ]))->toBe([]);
});

test('an official override lets the listed products go below zero and no others', function () {
    $served = guardProduct('Sudah Disajikan', 0);
    $other = guardProduct('Belum', 0);

    expect(guardErrors([['product_id' => $served->id, 'qty' => 2]], ['override' => [$served->id]]))->toBe([]);
    expect(array_keys(guardErrors([
        ['product_id' => $served->id, 'qty' => 2],
        ['product_id' => $other->id, 'qty' => 1],
    ], ['override' => [$served->id]])))->toBe(['products.1.qty']);
});

test('override ids may be strings', function () {
    $served = guardProduct('Sudah Disajikan', 0);

    expect(guardErrors([['product_id' => $served->id, 'qty' => 2]], ['override' => [(string) $served->id]]))->toBe([]);
});

test('a deleted product is named in the error, override or not', function () {
    $gone = guardProduct('Menu Lama', 10);
    $gone->delete();

    $errors = guardErrors([['product_id' => $gone->id, 'qty' => 1]]);
    expect($errors['products.0.qty'])->toBe(['Product Menu Lama was deleted']);

    $overridden = guardErrors([['product_id' => $gone->id, 'qty' => 1]], ['override' => [$gone->id]]);
    expect($overridden['products.0.qty'])->toBe(['Product Menu Lama was deleted']);
});

test('a product that does not exist is reported on its line', function () {
    $errors = guardErrors([['product_id' => 987654, 'qty' => 1]]);

    expect($errors['products.0.qty'])->toBe(['The product is not found.']);
});

test('no lines is a no-op that does not even query', function () {
    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    expect(guardErrors([]))->toBe([]);

    $queries = DB::getQueryLog();
    DB::connection()->disableQueryLog();
    expect($queries)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| queries and locking
|--------------------------------------------------------------------------
*/

test('all products are read in one query ordered by id, soft-deleted ones included', function () {
    $a = guardProduct('A', 5);
    $b = guardProduct('B', 5);
    $c = guardProduct('C', 5);

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();
    guardErrors([
        ['product_id' => $c->id, 'qty' => 1],
        ['product_id' => $a->id, 'qty' => 1],
        ['product_id' => $b->id, 'qty' => 1],
    ]);
    $queries = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => normalizeSql($q))->all();
    DB::connection()->disableQueryLog();

    expect($queries)->toHaveCount(1);
    expect($queries[0])->toContain('from "products"');
    expect($queries[0])->toContain('order by "id" asc');
    // withTrashed(): there is no "deleted_at is null" condition.
    expect($queries[0])->not->toContain('deleted_at');
});

test('the price units are read in a second query only when a line has one', function () {
    $product = guardProduct('Satuan', 20);
    $unit = PriceUnit::create(['product_id' => $product->id, 'unit' => 'dus', 'stock' => 2, 'selling_price' => 1]);

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();
    guardErrors([['product_id' => $product->id, 'qty' => 1]]);
    $plain = count(DB::getQueryLog());
    DB::connection()->flushQueryLog();
    guardErrors([['product_id' => $product->id, 'qty' => 1, 'price_unit_id' => $unit->id]]);
    $withUnit = count(DB::getQueryLog());
    DB::connection()->disableQueryLog();

    expect($plain)->toBe(1);
    expect($withUnit)->toBe(2);
});

/*
|--------------------------------------------------------------------------
| same rule as the API validation
|--------------------------------------------------------------------------
*/

test('the API validation and the guard agree on the boundary and the wording', function () {
    $product = guardProduct('Kopi Susu', 3);
    $post = fn (int $qty) => actingAs(User::first())->postJson('/api/transaction/selling', [
        'payed_money' => 100000,
        'friend_price' => false,
        'products' => [['product_id' => $product->id, 'qty' => $qty]],
    ]);

    $rejected = $post(4)->assertUnprocessable()->assertJsonValidationErrors(['products.0.qty']);
    expect($rejected->json('errors')['products.0.qty'][0])->toBe('Stock of Kopi Susu is not enough (left 3)');

    $post(3)->assertOk();
});
