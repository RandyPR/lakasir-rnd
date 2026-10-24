<?php

use App\Features\Discount;
use App\Filament\Tenant\Pages\Cashier;
use App\Models\Tenants\CartItem;
use App\Models\Tenants\Category;
use App\Models\Tenants\PaymentMethod;
use App\Models\Tenants\PriceUnit;
use App\Models\Tenants\Product;
use App\Models\Tenants\Selling;
use App\Models\Tenants\SellingDetail;
use App\Models\Tenants\Setting;
use App\Models\Tenants\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;

uses(RefreshDatabaseWithTenant::class);

/*
|--------------------------------------------------------------------------
| The cart of the cashier page with its real view: its order and its quantities
|--------------------------------------------------------------------------
|
| Lines are drawn per category (A to Z, natural, case-insensitive), then by product name, whatever order they were
| added in, and keep that order through the name, the payment dialog and the order details. The sale records its
| lines in the same order. Drawing the cart costs the same number of queries for 5 lines and for 20. The sorter
| itself is tested in tests/Unit/OrderLineSorterTest.php.
*/

if (! function_exists('csProduct')) {
    function csProduct(string $name, Category $category, array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'name' => $name,
            'category_id' => $category->id,
            'stock' => 50,
            'initial_price' => 1000,
            'selling_price' => 2000,
        ], $attributes));
    }
}

if (! function_exists('csDrawn')) {
    /**
     * The cart as drawn: the group keys and the product names of the lines, in page order.
     *
     * @return array{groups: list<string>, names: list<string>}
     */
    function csDrawn(Testable $page): array
    {
        $html = $page->html();
        preg_match_all('/data-testid="cart-group-([a-z0-9]+)"/', $html, $groups);
        preg_match_all('/data-testid="cart-item-(\d+)"/', $html, $lines);

        $names = CartItem::query()->with('product')->get()->mapWithKeys(
            fn (CartItem $line): array => [$line->id => $line->product->name]
        );

        return [
            'groups' => $groups[1],
            'names' => array_map(fn (string $id): string => $names[(int) $id], $lines[1]),
        ];
    }
}

if (! function_exists('csQueries')) {
    function csQueries(Closure $run): int
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
    Carbon::setTestNow(Carbon::parse('2026-10-15 05:00:00', 'UTC')); // 12:00 WIB
    Setting::set('selling_method', 'fifo');
    Setting::set('default_tax', 0);
    Setting::set('cash_drawer_enabled', false);
    Cache::clear();
    Filament::setCurrentPanel(Filament::getPanel('tenant'));

    $this->owner = User::first();
    actingAs($this->owner);

    $this->drinks = Category::factory()->create(['name' => 'minuman']);
    $this->food = Category::factory()->create(['name' => 'Makanan']);
    $this->snack10 = Category::factory()->create(['name' => 'Snack 10']);
    $this->snack2 = Category::factory()->create(['name' => 'Snack 2']);

    // Created and added in an order that is neither alphabetical nor by category.
    $this->products = [
        csProduct('Es Teh', $this->drinks),
        csProduct('Keripik', $this->snack10),
        csProduct('Nasi Goreng', $this->food),
        csProduct('Item 10', $this->food),
        csProduct('Kacang', $this->snack2),
        csProduct('Air Mineral', $this->drinks),
        csProduct('bakso', $this->food),
        csProduct('Item 2', $this->food),
    ];

    $this->groups = ['c'.$this->food->id, 'c'.$this->drinks->id, 'c'.$this->snack2->id, 'c'.$this->snack10->id];
    $this->names = ['bakso', 'Item 2', 'Item 10', 'Nasi Goreng', 'Air Mineral', 'Es Teh', 'Kacang', 'Keripik'];
});

afterEach(function () {
    Carbon::setTestNow();
});

test('the cart is drawn per category A to Z, then by name, whatever order the products were added in', function () {
    $page = Livewire::test(Cashier::class);
    foreach ($this->products as $product) {
        $page->call('addCart', $product->id);
    }

    expect(csDrawn($page))->toBe(['groups' => $this->groups, 'names' => $this->names]);

    // A fresh page (a reload) draws the same.
    expect(csDrawn(Livewire::test(Cashier::class)))->toBe(['groups' => $this->groups, 'names' => $this->names]);
});

test('the order stays put when a quantity changes, and after the name, the payment dialog and the order details', function () {
    $page = Livewire::test(Cashier::class);
    foreach ($this->products as $product) {
        $page->call('addCart', $product->id);
    }
    $expected = ['groups' => $this->groups, 'names' => $this->names];

    // The line changed last does not jump to the end (or the top).
    $page->call('addCart', $this->products[5]->id)->call('addCart', $this->products[0]->id, ['amount' => '3']);
    expect(csDrawn($page))->toBe($expected);

    $page->call('reduceCart', $this->products[5]->id);
    expect(csDrawn($page))->toBe($expected);

    $page->call('commitCustomerName', 'Budi');
    expect(csDrawn($page))->toBe($expected);

    $page->call('syncCartForPayment');
    expect(csDrawn($page))->toBe($expected);

    $page->set('cartDetail.note', 'Bungkus terpisah')->call('storeCart');
    expect(csDrawn($page))->toBe($expected);
});

test('the sale records its lines in the order of the cart', function () {
    $page = Livewire::test(Cashier::class);
    foreach ($this->products as $product) {
        $page->call('addCart', $product->id);
    }

    $page->call('syncCartForPayment')
        ->set('cartDetail.payment_method_id', PaymentMethod::where('name', 'Cash')->value('id'))
        ->set('cartDetail.payed_money', '100000')
        ->call('proceedThePayment')
        ->assertHasNoErrors();

    $sold = SellingDetail::query()
        ->where('selling_id', Selling::sole()->id)
        ->orderBy('id')
        ->with('product')
        ->get()
        ->map(fn (SellingDetail $line): string => $line->product->name)
        ->all();

    expect($sold)->toBe($this->names)
        ->and(Selling::sole()->loadForReceipt()->sellingDetails->map(fn ($line) => $line->product->name)->all())
        ->toBe($this->names);
});

test('drawing the cart costs the same number of queries for 5 lines and for 20', function () {
    $more = collect(range(1, 12))->map(fn (int $i): Product => csProduct('Menu '.$i, $i % 2 ? $this->food : $this->drinks));

    $fill = function (int $count) use ($more): void {
        CartItem::query()->delete();
        foreach ($more->merge($this->products)->take($count) as $product) {
            CartItem::create([
                'user_id' => $this->owner->id,
                'product_id' => $product->id,
                'qty' => 1,
                'price' => $product->selling_price,
                'note' => 'catatan',
            ]);
        }
    };

    // Settings, the profile and the shop are cached by the first page.
    $fill(5);
    Livewire::test(Cashier::class)->call('addCart', $this->products[0]->id);

    $fill(5);
    $openSmall = csQueries(fn () => Livewire::test(Cashier::class));
    $page = Livewire::test(Cashier::class);
    $tapSmall = csQueries(fn () => $page->call('addCart', $this->products[0]->id));

    $fill(20);
    $openLarge = csQueries(fn () => Livewire::test(Cashier::class));
    $page = Livewire::test(Cashier::class);
    $tapLarge = csQueries(fn () => $page->call('addCart', $this->products[0]->id));

    expect(substr_count($page->html(), 'data-testid="cart-item-'))->toBeGreaterThanOrEqual(20)
        ->and($openLarge)->toBe($openSmall)
        ->and($tapLarge)->toBe($tapSmall);
});

test('a typed quantity: 12,5 and 12.5 are accepted, 0 removes the line, a negative or a word changes nothing', function () {
    $kopi = csProduct('Kopi', $this->drinks);
    $page = Livewire::test(Cashier::class);

    $page->call('addCart', $kopi->id, ['amount' => '12,5']);
    expect((float) CartItem::sole()->qty)->toBe(12.5)
        ->and((float) CartItem::sole()->price)->toBe(25000.0);

    $page->call('addCart', $kopi->id, ['amount' => 3.5]);
    expect((float) CartItem::sole()->qty)->toBe(3.5);

    $page->call('addCart', $kopi->id, ['amount' => '-1']);
    expect((float) CartItem::sole()->qty)->toBe(3.5);

    $page->call('addCart', $kopi->id, ['amount' => 'dua']);
    expect((float) CartItem::sole()->qty)->toBe(3.5);

    // More than the stock: refused, the line keeps what it had.
    $page->call('addCart', $kopi->id, ['amount' => '51']);
    expect((float) CartItem::sole()->qty)->toBe(3.5);

    $page->call('addCart', $kopi->id, ['amount' => '0'])->assertDispatched('cart-item-removed');
    expect(CartItem::count())->toBe(0);

    // 0 on a product that is not in the cart adds nothing.
    $page->call('addCart', $kopi->id, ['amount' => '0']);
    expect(CartItem::count())->toBe(0);
});

test('half a unit is sold directly as it is on a bill', function () {
    $sugar = csProduct('Gula', $this->food, ['selling_price' => 20000]);

    Livewire::test(Cashier::class)
        ->call('addCart', $sugar->id, ['amount' => '0,5'])
        ->call('syncCartForPayment')
        ->set('cartDetail.payment_method_id', PaymentMethod::where('name', 'Cash')->value('id'))
        ->set('cartDetail.payed_money', '10000')
        ->call('proceedThePayment')
        ->assertHasNoErrors();

    $line = SellingDetail::query()->where('selling_id', Selling::sole()->id)->sole();
    expect((float) $line->qty)->toBe(0.5)
        ->and((float) Selling::sole()->total_price)->toBe(10000.0);
});

test('a line offers "more options" only when it has some: the line discount or unit prices', function () {
    $kopi = csProduct('Kopi', $this->drinks);
    $teh = csProduct('Teh', $this->drinks);
    Feature::deactivate(Discount::class);

    $page = Livewire::test(Cashier::class)->call('addCart', $kopi->id)->call('addCart', $teh->id);
    $page->assertDontSeeHtml('data-testid="cart-item-more-toggle-');

    // Unit prices on one product: only its line gets the button.
    PriceUnit::create(['product_id' => $teh->id, 'unit' => 'teko', 'stock' => 10, 'selling_price' => 15000]);
    $html = Livewire::test(Cashier::class)->html();
    expect(substr_count($html, 'data-testid="cart-item-more-toggle-'))->toBe(1);

    // The line discount is on: every line has it.
    Feature::activate(Discount::class);
    $html = Livewire::test(Cashier::class)->html();
    expect(substr_count($html, 'data-testid="cart-item-more-toggle-'))->toBe(2)
        ->and(substr_count($html, 'data-testid="cart-item-discount-input-'))->toBe(2);
});
