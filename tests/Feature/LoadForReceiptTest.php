<?php

use App\Models\Tenants\Category;
use App\Models\Tenants\Member;
use App\Models\Tenants\PaymentMethod;
use App\Models\Tenants\Product;
use App\Models\Tenants\Selling;
use App\Models\Tenants\SellingDetail;
use App\Models\Tenants\Setting;
use App\Models\Tenants\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;

uses(RefreshDatabaseWithTenant::class);

beforeEach(function () {
    Setting::set('selling_method', 'fifo');
    Cache::clear();
    actingAs(User::first());
});

/**
 * A finished sale with the given lines [product name, category name, qty, price, note|null],
 * created directly (no stock movement) so that only the receipt loading is under test.
 *
 * @param  array<int, array{0:string,1:string,2:int|float,3:int|float,4?:?string}>  $lines
 */
function receiptSale(array $lines, array $sale = []): Selling
{
    $categories = [];
    $selling = Selling::create(array_merge([
        'payment_method_id' => PaymentMethod::first()->id,
        'total_price' => 100000,
        'payed_money' => 100000,
        'money_changes' => 0,
        'total_qty' => count($lines),
        'date' => '2026-10-15 05:00:00',
    ], $sale));

    foreach ($lines as $line) {
        [$name, $categoryName, $qty, $price] = $line;
        $categoryId = $categories[$categoryName] ??= Category::factory()->create(['name' => $categoryName])->id;

        $product = Product::factory()->create([
            'name' => $name,
            'category_id' => $categoryId,
            'stock' => 5,
        ]);

        SellingDetail::create([
            'selling_id' => $selling->id,
            'product_id' => $product->id,
            'qty' => $qty,
            'price' => $price,
            'cost' => 0,
            'discount_price' => 0,
            'note' => $line[4] ?? null,
        ]);
    }

    return Selling::find($selling->id);
}

function receiptCountQueries(Closure $run): int
{
    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();
    $run();
    $count = count(DB::getQueryLog());
    DB::connection()->disableQueryLog();

    return $count;
}

test('lines come back ordered by category, then product name', function () {
    $selling = receiptSale([
        ['Teh Manis', 'Minuman', 1, 5000],
        ['Soto Ayam', 'Makanan', 1, 20000],
        ['Air Putih', 'Minuman', 1, 3000],
        ['Bakso', 'Makanan', 1, 15000],
    ]);

    $names = $selling->loadForReceipt()->sellingDetails->map(fn ($d) => $d->product->name)->all();

    expect($names)->toBe(['Bakso', 'Soto Ayam', 'Air Putih', 'Teh Manis']);
});

test('everything the receipt needs is loaded', function () {
    $member = Member::factory()->create();
    $selling = receiptSale([['Soto Ayam', 'Makanan', 2, 40000, 'tanpa seledri']], [
        'member_id' => $member->id,
    ]);

    $selling->loadForReceipt();

    expect($selling->relationLoaded('paymentMethod'))->toBeTrue();
    expect($selling->relationLoaded('user'))->toBeTrue();
    expect($selling->relationLoaded('member'))->toBeTrue();
    expect($selling->relationLoaded('table'))->toBeTrue();
    expect($selling->sellingDetails)->toHaveCount(1);
    expect($selling->sellingDetails[0]->relationLoaded('product'))->toBeTrue();
    expect($selling->sellingDetails[0]->product->relationLoaded('category'))->toBeTrue();
    expect($selling->sellingDetails[0]->product->category->name)->toBe('Makanan');
    expect($selling->member->id)->toBe($member->id);
    expect($selling->paymentMethod->name)->toBe('Cash');
    expect($selling->user->id)->toBe(User::first()->id);
});

test('a second call neither queries nor sorts again', function () {
    $selling = receiptSale([
        ['Teh', 'Minuman', 1, 5000],
        ['Soto', 'Makanan', 1, 20000],
    ]);

    $first = $selling->loadForReceipt();
    $queries = receiptCountQueries(fn () => $selling->loadForReceipt());

    expect($queries)->toBe(0);
    expect($first)->toBe($selling);
    expect($selling->loadForReceipt())->toBe($selling);
});

test('the number of queries does not grow with the number of lines', function () {
    $small = receiptSale(array_map(fn ($i) => ["Menu A{$i}", $i % 2 ? 'Makanan' : 'Minuman', 1, 1000], range(1, 2)));
    $large = receiptSale(array_map(fn ($i) => ["Menu B{$i}", $i % 3 ? 'Makanan' : 'Minuman', 1, 1000], range(1, 14)));

    $smallQueries = receiptCountQueries(fn () => $small->loadForReceipt());
    $largeQueries = receiptCountQueries(fn () => $large->loadForReceipt());

    expect($largeQueries)->toBe($smallQueries);
    expect($largeQueries)->toBeLessThanOrEqual(10);
});

test('a product removed after the sale still appears with its name and category', function () {
    $selling = receiptSale([['Menu Lama', 'Makanan', 1, 10000]]);
    $selling->sellingDetails->first()->product->delete();

    $loaded = Selling::find($selling->id)->loadForReceipt();

    expect($loaded->sellingDetails)->toHaveCount(1);
    expect($loaded->sellingDetails[0]->product)->not->toBeNull();
    expect($loaded->sellingDetails[0]->product->name)->toBe('Menu Lama');
    expect($loaded->sellingDetails[0]->product->trashed())->toBeTrue();
    expect($loaded->sellingDetails[0]->product->category->name)->toBe('Makanan');
});

test('note_plain is appended and turns the legacy html note into plain text', function () {
    $selling = receiptSale(
        [['Soto', 'Makanan', 1, 10000]],
        ['note' => '<p>Antar ke <strong>meja 5</strong></p><script>alert(1)</script><p>Jangan pedas</p>'],
    );

    $selling->loadForReceipt();

    expect($selling->note_plain)->toBe("Antar ke meja 5\nJangan pedas");
    expect($selling->toArray())->toHaveKey('note_plain', "Antar ke meja 5\nJangan pedas");
});

test('note_plain is null when there is no note', function () {
    $selling = receiptSale([['Soto', 'Makanan', 1, 10000]]);

    expect($selling->loadForReceipt()->note_plain)->toBeNull();
    expect($selling->toArray())->toHaveKey('note_plain');
});

test('a note typed as plain text is not changed', function () {
    $selling = receiptSale([['Soto', 'Makanan', 1, 10000]], ['note' => 'bayar nanti']);

    expect($selling->note_plain)->toBe('bayar nanti');
});

test('line notes are loaded with the lines', function () {
    $selling = receiptSale([['Soto', 'Makanan', 1, 10000, 'tanpa seledri']]);

    expect($selling->loadForReceipt()->sellingDetails[0]->note)->toBe('tanpa seledri');
});

test('loading a sale with no lines works', function () {
    $selling = receiptSale([]);

    $loaded = $selling->loadForReceipt();

    expect($loaded->sellingDetails)->toHaveCount(0);
});
