<?php

use App\Models\Tenants\Barcode;
use App\Models\Tenants\Category;
use App\Models\Tenants\Product;
use App\Models\Tenants\Setting;
use App\Models\Tenants\Stock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseWithTenant;

uses(RefreshDatabaseWithTenant::class);

beforeEach(function () {
    Setting::set('selling_method', 'fifo');
    Cache::clear();
});

/**
 * A product with a known name and SKU (the observer would generate a SKU and a barcode from the name).
 */
function searchProduct(string $name, ?string $sku = null, array $attributes = []): Product
{
    $product = Product::factory()->create(array_merge([
        'name' => $name,
        'stock' => 5,
        'initial_price' => 1000,
        'selling_price' => 2000,
    ], $attributes));

    if ($sku !== null) {
        $product->sku = $sku;
        $product->save();
    }

    return $product;
}

/** @return array<int, string> sorted names found by the search */
function searchNames(string $term): array
{
    return Product::query()->searchInPos($term)->orderBy('name')->pluck('name')->all();
}

test('the term is matched anywhere in the name, ignoring case', function () {
    searchProduct('Nasi Goreng Spesial', 'SKU-A');
    searchProduct('Mie Goreng', 'SKU-B');
    searchProduct('Es Teh', 'SKU-C');

    expect(searchNames('goreng'))->toBe(['Mie Goreng', 'Nasi Goreng Spesial']);
    expect(searchNames('GORENG'))->toBe(['Mie Goreng', 'Nasi Goreng Spesial']);
    expect(searchNames('si gor'))->toBe(['Nasi Goreng Spesial']);
    expect(searchNames('teh'))->toBe(['Es Teh']);
    expect(searchNames('zzz'))->toBe([]);
});

test('the term is matched anywhere in the sku', function () {
    searchProduct('Produk Satu', 'ABC-12345');
    searchProduct('Produk Dua', 'XYZ-99999');

    expect(searchNames('c-123'))->toBe(['Produk Satu']);
    expect(searchNames('xyz'))->toBe(['Produk Dua']);
});

test('the term is matched against active barcodes only', function () {
    $a = searchProduct('Barang A', 'SKU-A');
    $b = searchProduct('Barang B', 'SKU-B');
    Barcode::create(['product_id' => $a->id, 'code' => '8999999000017', 'type' => 'secondary', 'is_active' => true]);
    Barcode::create(['product_id' => $b->id, 'code' => '8999999000024', 'type' => 'secondary', 'is_active' => false]);

    expect(searchNames('8999999000017'))->toBe(['Barang A']);
    expect(searchNames('99999000'))->toBe(['Barang A']);
    expect(searchNames('8999999000024'))->toBe([]);
});

test('a product matched by name and barcode appears once', function () {
    $product = searchProduct('Kopi', 'SKU-K');
    Barcode::create(['product_id' => $product->id, 'code' => 'kopi-2', 'type' => 'secondary', 'is_active' => true]);

    expect(Product::query()->searchInPos('kopi')->count())->toBe(1);
});

test('an empty or blank term does not filter', function () {
    searchProduct('Satu', 'S-1');
    searchProduct('Dua', 'S-2');

    expect(searchNames(''))->toBe(['Dua', 'Satu']);
    expect(searchNames('   '))->toBe(['Dua', 'Satu']);
});

test('the term is trimmed', function () {
    searchProduct('Soto Ayam', 'S-1');

    expect(searchNames('  soto  '))->toBe(['Soto Ayam']);
});

test('percent and underscore are plain characters, not wildcards', function () {
    searchProduct('Diskon 50% Nasi', 'D-1');
    searchProduct('Diskon 50x Nasi', 'D-2');
    searchProduct('Menu a_b', 'D-3');
    searchProduct('Menu axb', 'D-4');

    expect(searchNames('50%'))->toBe(['Diskon 50% Nasi']);
    expect(searchNames('a_b'))->toBe(['Menu a_b']);
    expect(searchNames('%'))->toBe(['Diskon 50% Nasi']);
    expect(searchNames('_'))->toBe(['Menu a_b']);
});

test('the escape character itself is a plain character', function () {
    searchProduct('Wow! Murah', 'E-1');
    searchProduct('Wow Murah', 'E-2');

    expect(searchNames('!'))->toBe(['Wow! Murah']);
    expect(searchNames('w! m'))->toBe(['Wow! Murah']);
});

test('a term that starts with the escape character is found on MySQL 5.7 too, also with a ~ in it', function () {
    searchProduct('!Promo ~ hemat', 'P-1');
    searchProduct('Promo hemat', 'P-2');

    expect(searchNames('!promo'))->toBe(['!Promo ~ hemat']);
    expect(searchNames('!promo ~'))->toBe(['!Promo ~ hemat']);
    expect(searchNames('~'))->toBe(['!Promo ~ hemat']);
});

test('quotes and sql fragments in the term are bound, not executed', function () {
    searchProduct('Normal', 'N-1');

    expect(searchNames("' OR 1=1 --"))->toBe([]);
    expect(searchNames("x'; DROP TABLE products; --"))->toBe([]);
    expect(Product::query()->count())->toBe(1);
});

test('escapeLike escapes percent, underscore and the escape character', function () {
    expect(Product::escapeLike('50%_off!'))->toBe('50!%!_off!!');
    expect(Product::escapeLike('!50%_off~', '~'))->toBe('!50~%~_off~~');
    expect(Product::escapeLike('plain'))->toBe('plain');
    expect(Product::escapeLike(''))->toBe('');
});

test('the search is one query, whatever the number of products', function () {
    foreach (range(1, 12) as $i) {
        searchProduct("Menu {$i}", "M-{$i}");
    }

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();
    $names = Product::query()->searchInPos('menu')->get()->pluck('name');
    $queries = DB::getQueryLog();
    DB::connection()->disableQueryLog();

    expect($names)->toHaveCount(12);
    expect($queries)->toHaveCount(1);
});

test('inCategory without an id does not filter and with an id keeps that category only', function () {
    $food = Category::factory()->create(['name' => 'Cat Food']);
    $drink = Category::factory()->create(['name' => 'Cat Drink']);
    searchProduct('Soto', 'C-1', ['category_id' => $food->id]);
    searchProduct('Teh', 'C-2', ['category_id' => $drink->id]);

    expect(Product::query()->inCategory(null)->count())->toBe(2);
    expect(Product::query()->inCategory($food->id)->pluck('name')->all())->toBe(['Soto']);
    expect(Product::query()->inCategory($drink->id)->pluck('name')->all())->toBe(['Teh']);
    expect(Product::query()->inCategory(999999)->count())->toBe(0);
});

test('inCategory and searchInPos combine', function () {
    $food = Category::factory()->create(['name' => 'Cat Food']);
    $drink = Category::factory()->create(['name' => 'Cat Drink']);
    searchProduct('Es Jeruk', 'C-1', ['category_id' => $drink->id]);
    searchProduct('Es Krim', 'C-2', ['category_id' => $food->id]);

    $names = Product::query()->inCategory($drink->id)->searchInPos('es')->pluck('name')->all();

    expect($names)->toBe(['Es Jeruk']);
});

test('services and non-stock goods are never "out of stock"', function () {
    $service = searchProduct('Potong Rambut', 'SV-1', ['type' => 'service', 'stock' => 0]);
    $nonStock = searchProduct('Air Isi Ulang', 'SV-2', ['is_non_stock' => true, 'stock' => 0]);
    $stocked = searchProduct('Roti', 'SV-3');

    expect($service->fresh()->isStockUnlimited())->toBeTrue();
    expect($nonStock->fresh()->isStockUnlimited())->toBeTrue();
    expect($stocked->fresh()->isStockUnlimited())->toBeFalse();
});

test('sellableInPos keeps shown products with ready stock, non-stock goods and services', function () {
    searchProduct('Ada Stok', 'P-1');
    searchProduct('Habis', 'P-2', ['stock' => 0]);
    searchProduct('Tanpa Stok Tercatat', 'P-3', ['stock' => 0, 'is_non_stock' => true]);
    searchProduct('Jasa', 'P-4', ['type' => 'service', 'stock' => 0]);
    searchProduct('Disembunyikan', 'P-5', ['show' => false]);
    searchProduct('Jasa Disembunyikan', 'P-6', ['type' => 'service', 'show' => false]);

    $names = Product::query()->sellableInPos()->orderBy('name')->pluck('name')->all();

    expect($names)->toBe(['Ada Stok', 'Jasa', 'Tanpa Stok Tercatat']);
});

test('sellableInPos ignores stock rows that are not ready or not incoming', function () {
    $notReady = searchProduct('Belum Siap', 'Q-1', ['stock' => 0]);
    Stock::factory()->createQuietly(['product_id' => $notReady->id, 'stock' => 9, 'is_ready' => false, 'type' => 'in']);
    $outOnly = searchProduct('Hanya Keluar', 'Q-2', ['stock' => 0]);
    Stock::factory()->createQuietly(['product_id' => $outOnly->id, 'stock' => 9, 'is_ready' => true, 'type' => 'out']);
    $ready = searchProduct('Siap', 'Q-3', ['stock' => 0]);
    Stock::factory()->createQuietly(['product_id' => $ready->id, 'stock' => 9, 'is_ready' => true, 'type' => 'in']);

    expect(Product::query()->sellableInPos()->pluck('name')->all())->toBe(['Siap']);
});

test('withStockTotal sums ready incoming stock in one query and skips empty batches unless the method is normal', function () {
    $product = searchProduct('Banyak Batch', 'W-1', ['stock' => 4]);
    Stock::factory()->createQuietly(['product_id' => $product->id, 'stock' => 6, 'is_ready' => true, 'type' => 'in']);
    Stock::factory()->createQuietly(['product_id' => $product->id, 'stock' => 0, 'is_ready' => true, 'type' => 'in']);
    Stock::factory()->createQuietly(['product_id' => $product->id, 'stock' => 100, 'is_ready' => false, 'type' => 'in']);
    Stock::factory()->createQuietly(['product_id' => $product->id, 'stock' => 100, 'is_ready' => true, 'type' => 'out']);
    searchProduct('Kosong', 'W-2', ['stock' => 0]);
    Setting::get('selling_method'); // warm the settings cache: the scope reads the selling method once

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();
    $totals = Product::query()->withStockTotal()->orderBy('name')->get()->pluck('stock_total', 'name')->all();
    $queries = count(DB::getQueryLog());
    DB::connection()->disableQueryLog();

    expect($queries)->toBe(1);
    expect((float) $totals['Banyak Batch'])->toBe(10.0);
    expect((float) $totals['Kosong'])->toBe(0.0);

    // "normal" counts every ready incoming batch, including empty ones (the sum is unchanged here).
    Setting::set('selling_method', 'normal');
    $normal = Product::query()->withStockTotal()->whereKey($product->id)->first();
    expect((float) $normal->stock_total)->toBe(10.0);
});
