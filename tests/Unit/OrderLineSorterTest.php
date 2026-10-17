<?php

use App\Models\Tenants\CartItem;
use App\Models\Tenants\Category;
use App\Models\Tenants\OpenBillItem;
use App\Models\Tenants\Product;
use App\Models\Tenants\SellingDetail;
use App\Services\Tenants\OrderLineSorter;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A line of any class with its product (and category) already "loaded", so nothing needs the database.
 *
 * @template T of \Illuminate\Database\Eloquent\Model
 *
 * @param  class-string<T>  $class
 * @return T
 */
function sorterLine(string $class, int $id, ?string $productName, ?array $category = null, bool $loadCategory = true)
{
    $line = new $class;
    $line->forceFill(['id' => $id]);

    if ($productName === null) {
        $line->setRelation('product', null);

        return $line;
    }

    $product = new Product;
    $product->forceFill(['id' => 100 + $id, 'name' => $productName]);
    if ($loadCategory) {
        $categoryModel = null;
        if ($category !== null) {
            $categoryModel = new Category;
            $categoryModel->forceFill(['id' => $category[0], 'name' => $category[1]]);
        }
        $product->setRelation('category', $categoryModel);
    }
    $line->setRelation('product', $product);

    return $line;
}

/** @return array<int, int> line ids in order */
function sorterIds(iterable $lines): array
{
    return collect($lines)->map(fn ($line) => $line->id)->values()->all();
}

beforeEach(function () {
    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();
});

afterEach(function () {
    DB::connection()->disableQueryLog();
});

test('lines are ordered by category then product name, A to Z, case-insensitive', function () {
    $lines = collect([
        sorterLine(CartItem::class, 1, 'teh manis', [2, 'minuman']),
        sorterLine(CartItem::class, 2, 'Soto Ayam', [1, 'Makanan']),
        sorterLine(CartItem::class, 3, 'Air Putih', [2, 'Minuman']),
        sorterLine(CartItem::class, 4, 'bakso', [1, 'makanan']),
    ]);

    // Makanan: bakso, Soto Ayam. Minuman: Air Putih, teh manis (the case of a name never matters).
    expect(sorterIds(OrderLineSorter::sort($lines)))->toBe([4, 2, 3, 1]);
});

test('names are compared naturally, so "2" comes before "10"', function () {
    $lines = collect([
        sorterLine(CartItem::class, 1, 'Paket 10', [1, 'Paket']),
        sorterLine(CartItem::class, 2, 'Paket 2', [1, 'Paket']),
        sorterLine(CartItem::class, 3, 'Paket 1', [1, 'Paket']),
    ]);

    expect(sorterIds(OrderLineSorter::sort($lines)))->toBe([3, 2, 1]);
});

test('categories are compared naturally too', function () {
    $lines = collect([
        sorterLine(CartItem::class, 1, 'a', [1, 'Menu 10']),
        sorterLine(CartItem::class, 2, 'a', [2, 'Menu 9']),
    ]);

    expect(sorterIds(OrderLineSorter::sort($lines)))->toBe([2, 1]);
});

test('lines without a category come last', function () {
    $lines = collect([
        sorterLine(CartItem::class, 1, 'Aaa', null),
        sorterLine(CartItem::class, 2, 'Zzz', [7, 'Zebra']),
        sorterLine(CartItem::class, 3, 'Bbb', [1, 'Alpha']),
    ]);

    expect(sorterIds(OrderLineSorter::sort($lines)))->toBe([3, 2, 1]);
});

test('a category named with an empty string is still a category and sorts first', function () {
    $lines = collect([
        sorterLine(CartItem::class, 1, 'Aaa', null),
        sorterLine(CartItem::class, 2, 'Bbb', [3, '']),
    ]);

    expect(sorterIds(OrderLineSorter::sort($lines)))->toBe([2, 1]);
});

test('a line without a product is safe, has no category and sorts before named lines of the same group', function () {
    $lines = collect([
        sorterLine(CartItem::class, 1, null),
        sorterLine(CartItem::class, 2, 'Soto', [1, 'Makanan']),
        sorterLine(CartItem::class, 3, 'Roti', null),
    ]);

    expect(sorterIds(OrderLineSorter::sort($lines)))->toBe([2, 1, 3]);
});

test('lines whose relations were never loaded count as having no category and no name, and cause no query', function () {
    $loaded = sorterLine(CartItem::class, 1, 'Soto', [1, 'Makanan']);
    $noProductLoaded = new CartItem;
    $noProductLoaded->forceFill(['id' => 2, 'product_id' => 55]);
    $noCategoryLoaded = sorterLine(CartItem::class, 3, 'Nasi', [1, 'Makanan'], loadCategory: false);

    $sorted = OrderLineSorter::sort(collect([$noProductLoaded, $noCategoryLoaded, $loaded]));

    // Only the fully loaded line has a category; the other two are "no category", the nameless one first.
    expect(sorterIds($sorted))->toBe([1, 2, 3]);
    expect(DB::getQueryLog())->toBe([]);
});

test('ties on category and name fall back to the line id', function () {
    $lines = collect([
        sorterLine(CartItem::class, 9, 'Teh', [1, 'Minuman']),
        sorterLine(CartItem::class, 4, 'Teh', [1, 'Minuman']),
        sorterLine(CartItem::class, 6, 'Teh', [1, 'Minuman']),
    ]);

    expect(sorterIds(OrderLineSorter::sort($lines)))->toBe([4, 6, 9]);
});

test('two categories with the same name are ordered by category id', function () {
    $lines = collect([
        sorterLine(CartItem::class, 1, 'Roti', [9, 'Snack']),
        sorterLine(CartItem::class, 2, 'Keripik', [5, 'Snack']),
    ]);

    expect(sorterIds(OrderLineSorter::sort($lines)))->toBe([2, 1]);
});

test('sort resets the keys and keeps the collection class', function () {
    $eloquent = new EloquentCollection([
        sorterLine(CartItem::class, 1, 'b', [1, 'x']),
        sorterLine(CartItem::class, 2, 'a', [1, 'x']),
    ]);

    $sorted = OrderLineSorter::sort($eloquent);

    expect($sorted)->toBeInstanceOf(EloquentCollection::class);
    expect($sorted->keys()->all())->toBe([0, 1]);
    expect(sorterIds($sorted))->toBe([2, 1]);

    $plain = OrderLineSorter::sort(collect([
        'x' => sorterLine(CartItem::class, 1, 'b', [1, 'x']),
        'y' => sorterLine(CartItem::class, 2, 'a', [1, 'x']),
    ]));
    expect($plain)->toBeInstanceOf(Collection::class);
    expect($plain->keys()->all())->toBe([0, 1]);
});

test('sorting an empty collection works', function () {
    expect(OrderLineSorter::sort(collect())->all())->toBe([]);
    expect(OrderLineSorter::group(collect())->all())->toBe([]);
});

test('the sorter does not change its input', function () {
    $lines = collect([
        sorterLine(CartItem::class, 1, 'b', [1, 'x']),
        sorterLine(CartItem::class, 2, 'a', [1, 'x']),
    ]);

    OrderLineSorter::sort($lines);

    expect(sorterIds($lines))->toBe([1, 2]);
});

test('open bill lines and sold lines are accepted, also mixed', function () {
    $bill = collect([
        sorterLine(OpenBillItem::class, 1, 'Teh', [2, 'Minuman']),
        sorterLine(OpenBillItem::class, 2, 'Soto', [1, 'Makanan']),
    ]);
    $sold = collect([
        sorterLine(SellingDetail::class, 1, 'Teh', [2, 'Minuman']),
        sorterLine(SellingDetail::class, 2, 'Soto', [1, 'Makanan']),
    ]);

    expect(sorterIds(OrderLineSorter::sort($bill)))->toBe([2, 1]);
    expect(sorterIds(OrderLineSorter::sort($sold)))->toBe([2, 1]);

    $mixed = OrderLineSorter::sort(collect([
        sorterLine(OpenBillItem::class, 1, 'Teh', [2, 'Minuman']),
        sorterLine(SellingDetail::class, 2, 'Soto', [1, 'Makanan']),
        sorterLine(CartItem::class, 3, 'Roti', null),
    ]));
    expect(sorterIds($mixed))->toBe([2, 1, 3]);
    expect(DB::getQueryLog())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| group()
|--------------------------------------------------------------------------
*/

test('group splits the sorted lines per category and keeps the order', function () {
    $lines = collect([
        sorterLine(CartItem::class, 1, 'Teh', [2, 'Minuman']),
        sorterLine(CartItem::class, 2, 'Soto', [1, 'Makanan']),
        sorterLine(CartItem::class, 3, 'Bakso', [1, 'Makanan']),
        sorterLine(CartItem::class, 4, 'Roti', null),
    ]);

    $groups = OrderLineSorter::group($lines);

    expect($groups)->toHaveCount(3);
    expect($groups->pluck('key')->all())->toBe(['c1', 'c2', 'none']);
    expect($groups->pluck('category')->all())->toBe(['Makanan', 'Minuman', null]);
    expect($groups->pluck('category_id')->all())->toBe([1, 2, null]);
    expect(sorterIds($groups[0]['lines']))->toBe([3, 2]);
    expect(sorterIds($groups[1]['lines']))->toBe([1]);
    expect(sorterIds($groups[2]['lines']))->toBe([4]);
});

test('two categories with the same name stay separate groups, split by category id', function () {
    $lines = collect([
        sorterLine(CartItem::class, 1, 'Roti', [9, 'Snack']),
        sorterLine(CartItem::class, 2, 'Keripik', [5, 'Snack']),
        sorterLine(CartItem::class, 3, 'Kacang', [9, 'Snack']),
    ]);

    $groups = OrderLineSorter::group($lines);

    expect($groups)->toHaveCount(2);
    expect($groups->pluck('key')->all())->toBe(['c5', 'c9']);
    expect($groups->pluck('category')->all())->toBe(['Snack', 'Snack']);
    expect(sorterIds($groups[0]['lines']))->toBe([2]);
    expect(sorterIds($groups[1]['lines']))->toBe([3, 1]);
});

test('lines without a product or a category share the "none" group', function () {
    $groups = OrderLineSorter::group(collect([
        sorterLine(CartItem::class, 1, null),
        sorterLine(CartItem::class, 2, 'Roti', null),
    ]));

    expect($groups)->toHaveCount(1);
    expect($groups[0]['key'])->toBe('none');
    expect($groups[0]['category'])->toBeNull();
    expect($groups[0]['category_id'])->toBeNull();
    expect(sorterIds($groups[0]['lines']))->toBe([1, 2]);
});

test('group keeps the collection class of the input in each group', function () {
    $groups = OrderLineSorter::group(new EloquentCollection([
        sorterLine(CartItem::class, 1, 'a', [1, 'x']),
        sorterLine(CartItem::class, 2, 'b', [2, 'y']),
    ]));

    expect($groups[0]['lines'])->toBeInstanceOf(EloquentCollection::class);
    expect($groups[1]['lines'])->toBeInstanceOf(EloquentCollection::class);
});

test('a single category gives a single group (the view then shows no group title)', function () {
    $groups = OrderLineSorter::group(collect([
        sorterLine(SellingDetail::class, 1, 'a', [1, 'x']),
        sorterLine(SellingDetail::class, 2, 'b', [1, 'x']),
    ]));

    expect($groups)->toHaveCount(1);
});

test('group runs no query', function () {
    OrderLineSorter::group(collect([
        sorterLine(OpenBillItem::class, 1, 'a', [1, 'x']),
        sorterLine(OpenBillItem::class, 2, null),
        sorterLine(OpenBillItem::class, 3, 'c', [2, 'y'], loadCategory: false),
    ]));

    expect(DB::getQueryLog())->toBe([]);
});
