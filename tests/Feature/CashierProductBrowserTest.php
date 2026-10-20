<?php

use App\Filament\Tenant\Pages\Cashier;
use App\Livewire\Cashier\ProductBrowser;
use App\Models\Tenants\Barcode;
use App\Models\Tenants\CartItem;
use App\Models\Tenants\Category;
use App\Models\Tenants\Product;
use App\Models\Tenants\Setting;
use App\Models\Tenants\User;
use App\Services\Tenants\OpenBillService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;

uses(RefreshDatabaseWithTenant::class);

/*
|--------------------------------------------------------------------------
| The product list of the cashier (App\Livewire\Cashier\ProductBrowser) with its real view
|--------------------------------------------------------------------------
|
| The search scopes themselves are tested in ProductSearchScopeTest; here the component: what is listed and in
| which order, search, categories, "load more", Enter on a code, open bills, and a query count that does not grow
| with the number of products (also for the cart actions of the parent page).
*/

if (! function_exists('pbProduct')) {
    /** A product with a known name and SKU (the observer would generate a SKU and a barcode from the name). */
    function pbProduct(string $name, Category $category, array $attributes = []): Product
    {
        $product = Product::factory()->create(array_merge([
            'name' => $name,
            'category_id' => $category->id,
            'stock' => 5,
            'initial_price' => 1000,
            'selling_price' => 2000,
        ], $attributes));

        $product->sku = $attributes['sku'] ?? 'PB-'.$product->id;
        $product->save();

        return $product;
    }
}

if (! function_exists('pbBulk')) {
    /** $count non-stock products (always sellable) inserted at once, named "<prefix> 001", "<prefix> 002", ... */
    function pbBulk(int $count, Category $category, string $prefix = 'Menu'): void
    {
        $now = now()->toDateTimeString();

        DB::table('products')->insert(array_map(fn (int $i): array => [
            'category_id' => $category->id,
            'name' => $prefix.' '.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'sku' => strtoupper($prefix).'-'.$i,
            'initial_price' => 1000,
            'selling_price' => 2000,
            'unit' => 'PCS',
            'type' => 'product',
            'show' => true,
            'is_non_stock' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], range(1, $count)));
    }
}

if (! function_exists('pbCards')) {
    /**
     * The product ids of the cards, in the order they are drawn.
     *
     * @return list<int>
     */
    function pbCards(string $html): array
    {
        preg_match_all('/data-testid="product-card-(\d+)"/', $html, $matches);

        return array_map('intval', $matches[1]);
    }
}

if (! function_exists('pbQueries')) {
    function pbQueries(Closure $run): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}

beforeEach(function () {
    Setting::set('selling_method', 'fifo');
    Cache::clear();
    Filament::setCurrentPanel(Filament::getPanel('tenant'));
    $this->owner = User::first();
    actingAs($this->owner);

    $this->food = Category::factory()->create(['name' => 'Makanan']);
    $this->drinks = Category::factory()->create(['name' => 'minuman']);
});

test('the list shows what can be sold, A to Z: goods in stock, non-stock goods and services', function () {
    $bakso = pbProduct('Bakso', $this->food);
    $air = pbProduct('Air Mineral', $this->drinks);
    $ice = pbProduct('Es Batu', $this->drinks, ['stock' => 0, 'is_non_stock' => true]);
    $delivery = pbProduct('Ongkir', $this->food, ['stock' => 0, 'type' => 'service']);
    pbProduct('Habis', $this->food, ['stock' => 0]);
    pbProduct('Disembunyikan', $this->food, ['show' => false]);

    $page = Livewire::test(ProductBrowser::class);

    expect(pbCards($page->html()))->toBe([$air->id, $bakso->id, $ice->id, $delivery->id]);
    $page->assertSeeHtml('data-testid="product-stock-'.$bakso->id.'"')
        ->assertDontSeeHtml('data-testid="product-stock-'.$ice->id.'"')
        ->assertDontSeeHtml('data-testid="product-load-more"');
});

test('the search finds a name, a SKU or an active barcode anywhere in it, and resets "load more"', function () {
    $bakso = pbProduct('Bakso Urat', $this->food, ['sku' => 'BKS-0042']);
    $juice = pbProduct('100% Juice', $this->drinks);
    $other = pbProduct('1000 Juice', $this->drinks);
    Barcode::create(['product_id' => $juice->id, 'code' => '8991234500017', 'type' => 'secondary', 'is_active' => true]);
    Barcode::create(['product_id' => $other->id, 'code' => '8991234500024', 'type' => 'secondary', 'is_active' => false]);

    $page = Livewire::test(ProductBrowser::class)->call('loadMore')->assertSet('limit', 48);

    $page->set('search', 'urat')->assertSet('limit', ProductBrowser::PAGE_SIZE);
    expect(pbCards($page->html()))->toBe([$bakso->id]);

    $page->set('search', 's-004');
    expect(pbCards($page->html()))->toBe([$bakso->id]);

    $page->set('search', '12345000');
    expect(pbCards($page->html()))->toBe([$juice->id]);

    // % is a character, not a wildcard.
    $page->set('search', '100%');
    expect(pbCards($page->html()))->toBe([$juice->id]);

    $page->set('search', 'tidak ada');
    expect(pbCards($page->html()))->toBe([]);
    $page->assertSeeHtml('data-testid="product-empty"');
});

test('category chips are A to Z with their counts; a chip filters, also together with the search', function () {
    $soto = pbProduct('Soto', $this->food);
    $bakso = pbProduct('Bakso', $this->food);
    $teh = pbProduct('Teh Manis', $this->drinks);
    $empty = Category::factory()->create(['name' => 'Kosong']);
    pbProduct('Habis', $empty, ['stock' => 0]);

    $page = Livewire::test(ProductBrowser::class);

    expect($page->get('chips'))->toBe([
        ['id' => $this->food->id, 'name' => 'Makanan', 'count' => 2],
        ['id' => $this->drinks->id, 'name' => 'minuman', 'count' => 1],
    ]);

    $page->call('selectCategory', $this->food->id)->assertSet('categoryId', $this->food->id);
    expect(pbCards($page->html()))->toBe([$bakso->id, $soto->id]);
    $page->assertSeeHtml('aria-pressed="true"');

    $page->set('search', 'sot');
    expect(pbCards($page->html()))->toBe([$soto->id]);

    $page->set('search', 'teh');
    expect(pbCards($page->html()))->toBe([]);
    $page->assertSee(__('No products match'));

    // A category without products to sell (or one sent straight from the browser) is not a filter.
    $page->set('search', '')->call('selectCategory', $empty->id)->assertSet('categoryId', null);
    $page->call('selectCategory', 999999)->assertSet('categoryId', null);
    expect(pbCards($page->html()))->toBe([$bakso->id, $soto->id, $teh->id]);
});

test('"load more" adds 24 at a time up to 96, and a limit sent from the browser cannot ask for more', function () {
    pbBulk(100, $this->food);

    $page = Livewire::test(ProductBrowser::class);
    expect(pbCards($page->html()))->toHaveCount(24);
    $page->assertSeeHtml('data-testid="product-load-more"');

    $page->call('loadMore')->call('loadMore')->call('loadMore')->assertSet('limit', ProductBrowser::MAX);
    expect(pbCards($page->html()))->toHaveCount(96);
    $page->assertDontSeeHtml('data-testid="product-load-more"');

    $page->call('loadMore')->assertSet('limit', ProductBrowser::MAX);

    $page->set('limit', 100000);
    expect(pbCards($page->html()))->toHaveCount(96);

    $page->set('limit', -5);
    expect(pbCards($page->html()))->toHaveCount(24);

    $page->call('loadMore')->call('selectCategory', $this->food->id)->assertSet('limit', ProductBrowser::PAGE_SIZE);
});

test('Enter on an exact barcode or SKU sends it to the cart and clears the search; other text stays a search', function () {
    $kopi = pbProduct('Kopi Susu', $this->drinks, ['sku' => 'KOPI-01']);

    Livewire::test(ProductBrowser::class)
        ->set('search', 'KOPI-01')
        ->call('searchEnter', 'KOPI-01')
        ->assertDispatched('product-scanned', code: 'KOPI-01')
        ->assertDispatched('search-cleared')
        ->assertSet('search', '');

    Livewire::test(ProductBrowser::class)
        ->set('search', 'kopi')
        ->call('searchEnter', 'kopi')
        ->assertNotDispatched('product-scanned')
        ->assertSet('search', 'kopi');

    Livewire::test(ProductBrowser::class)->call('searchEnter', '   ')->assertNotDispatched('product-scanned');

    // The cashier page adds a scanned product, one more on each scan.
    $cashier = Livewire::test(Cashier::class)
        ->dispatch('product-scanned', code: 'KOPI-01')
        ->dispatch('product-scanned', code: 'KOPI-01');
    expect((float) CartItem::where('product_id', $kopi->id)->value('qty'))->toBe(2.0);

    $cashier->dispatch('product-scanned', code: 'TIDAK-ADA');
    expect(CartItem::count())->toBe(1);
});

test('a card says how much is left once the open bills are counted', function () {
    $rendang = pbProduct('Rendang', $this->food, ['stock' => 5]);
    $teh = pbProduct('Es Teh', $this->drinks, ['stock' => 0, 'is_non_stock' => true]);

    CartItem::create(['user_id' => $this->owner->id, 'product_id' => $rendang->id, 'qty' => 3, 'price' => 6000]);
    CartItem::create(['user_id' => $this->owner->id, 'product_id' => $teh->id, 'qty' => 2, 'price' => 4000]);
    app(OpenBillService::class)->createFromCart($this->owner, []);

    Livewire::test(ProductBrowser::class)
        ->assertViewHas('committed', fn (array $committed): bool => $committed[$rendang->id] === 3.0)
        ->assertSeeHtml('data-testid="product-hold-'.$rendang->id.'"')
        ->assertSeeHtml('data-left="2"')
        // Non-stock goods have nothing to hold back.
        ->assertDontSeeHtml('data-testid="product-hold-'.$teh->id.'"');
});

test('"products-refresh" reloads the chips and drops a category that has nothing left to sell', function () {
    $bakso = pbProduct('Bakso', $this->food);
    pbProduct('Teh', $this->drinks);

    $page = Livewire::test(ProductBrowser::class)->call('selectCategory', $this->food->id);

    $bakso->update(['show' => false]);
    $page->dispatch('products-refresh')->assertSet('categoryId', null);

    expect(collect($page->get('chips'))->pluck('id')->all())->toBe([$this->drinks->id]);
});

test('the number of queries does not grow with the number of products, for the list and for the cart', function () {
    pbBulk(24, $this->food, 'Kecil');
    $first = Product::query()->orderBy('id')->first();

    // Settings and the profile are cached after the first request.
    Livewire::test(ProductBrowser::class);
    Livewire::test(Cashier::class);

    // Each cart measurement starts from the same cart (empty), so only the number of products differs.
    $listSmall = pbQueries(fn () => Livewire::test(ProductBrowser::class)->set('search', 'kecil'));
    CartItem::query()->delete();
    $cashier = Livewire::test(Cashier::class);
    $addSmall = pbQueries(fn () => $cashier->call('addCart', $first->id));

    pbBulk(100, $this->drinks, 'Besar');

    $listLarge = pbQueries(fn () => Livewire::test(ProductBrowser::class)->set('search', 'besar'));
    CartItem::query()->delete();
    $cashier = Livewire::test(Cashier::class);
    $addLarge = pbQueries(fn () => $cashier->call('addCart', $first->id));

    expect($listLarge)->toBe($listSmall)
        ->and($addLarge)->toBe($addSmall);

    // The page shows the list when it opens, but a cart action re-renders it without the list (its own component).
    expect(Livewire::test(Cashier::class)->html())->toContain('data-pv-card')
        ->and($cashier->html())->not->toContain('data-pv-card');
});

test('a cart action sends neither the product list nor the dialog scripts; the page load has each script once', function () {
    pbProduct('Bakso', $this->food);

    // The full page (render hooks included). Without Vite: CI has no built assets.
    $this->withoutVite();
    $page = $this->get(Cashier::getUrl())->assertOk()->getContent();

    foreach (['window.paymentFlow = ', 'window.paymentSuccess = ', "Alpine.data('lakasirBillPreview'"] as $script) {
        expect(substr_count($page, $script))->toBe(1, $script);
    }

    $cashier = Livewire::test(Cashier::class)->call('addCart', Product::query()->value('id'));

    expect($cashier->html())->not->toContain('<script')
        ->not->toContain('data-pv-card');
});
