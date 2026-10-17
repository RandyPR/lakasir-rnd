<?php

use App\Filament\Tenant\Resources\SellingResource\Pages\ViewSelling;
use App\Models\Tenants\Category;
use App\Models\Tenants\PaymentMethod;
use App\Models\Tenants\Product;
use App\Models\Tenants\Selling;
use App\Models\Tenants\SellingDetail;
use App\Models\Tenants\Setting;
use App\Models\Tenants\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;

uses(RefreshDatabaseWithTenant::class);

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('tenant'));
    Setting::set('selling_method', 'fifo');
    Cache::clear();
    actingAs(User::first());
});

/**
 * A finished sale shown on the "view sale" page.
 *
 * @param  array<int, array{0:string,1:string,2:?string}>  $lines  [product name, category name, line note]
 */
function viewSale(array $lines, ?string $note = null): Selling
{
    $selling = Selling::create([
        'payment_method_id' => PaymentMethod::first()->id,
        'total_price' => 100000,
        'payed_money' => 100000,
        'money_changes' => 0,
        'total_qty' => count($lines),
        'date' => '2026-10-15 05:00:00',
        'note' => $note,
    ]);

    $categories = [];
    foreach ($lines as [$name, $categoryName, $lineNote]) {
        $categoryId = $categories[$categoryName] ??= Category::factory()->create(['name' => $categoryName])->id;
        $product = Product::factory()->create(['name' => $name, 'category_id' => $categoryId, 'stock' => 5]);

        SellingDetail::create([
            'selling_id' => $selling->id,
            'product_id' => $product->id,
            'qty' => 1,
            'price' => 10000,
            'cost' => 0,
            'discount_price' => 0,
            'note' => $lineNote,
        ]);
    }

    return $selling;
}

function viewSaleHtml(Selling $selling): string
{
    return Livewire::test(ViewSelling::class, ['record' => $selling->id])->html();
}

/** Number of category title rows in the line table (they only exist when 2 or more categories are present). */
function viewSaleGroupTitles(string $html): int
{
    return substr_count($html, 'uppercase tracking-wide');
}

test('the order note is shown as plain text, one line per paragraph', function () {
    $selling = viewSale([['Soto', 'Makanan', null]], '<p>Antar ke <b>meja 5</b></p><p>Jangan pedas</p>');

    $html = viewSaleHtml($selling);

    expect($html)->toContain('Order note');
    expect($html)->toContain('Antar ke meja 5');
    expect($html)->toContain('Jangan pedas');
    expect($html)->not->toContain('<b>meja 5</b>');
});

test('the order note is escaped: markup that survives as text never becomes html', function () {
    $selling = viewSale([['Soto', 'Makanan', null]], '&lt;script&gt;alert(1)&lt;/script&gt; &lt;img src=x onerror=alert(2)&gt;');

    $html = viewSaleHtml($selling);

    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
    expect($html)->toContain('&lt;img src=x onerror=alert(2)&gt;');
    expect($html)->not->toContain('<script>alert(1)</script>');
    expect($html)->not->toContain('<img src=x onerror=alert(2)>');
});

test('a script in a legacy html note is dropped before it reaches the page', function () {
    $selling = viewSale([['Soto', 'Makanan', null]], 'aman<script>alert("xss-note")</script>');

    $html = viewSaleHtml($selling);

    // The raw note also travels as JSON data (Livewire snapshot, print payload), which is escaped data and not
    // markup. What matters is the visible note paragraph.
    expect(preg_match('#<p class="whitespace-pre-line break-words">(.*?)</p>#s', $html, $paragraph))->toBe(1);
    expect(trim($paragraph[1]))->toBe('aman');
});

test('the order note block is absent when there is no note', function () {
    $selling = viewSale([['Soto', 'Makanan', null]]);

    expect(viewSaleHtml($selling))->not->toContain('Order note');
});

test('the line note is shown under the product and escaped', function () {
    $selling = viewSale([['Soto', 'Makanan', 'tanpa <b>seledri</b> & "pedas"']]);

    $html = viewSaleHtml($selling);

    expect($html)->toContain('tanpa &lt;b&gt;seledri&lt;/b&gt; &amp; &quot;pedas&quot;');
    expect($html)->not->toContain('<b>seledri</b>');
});

test('the product name is escaped too', function () {
    $selling = viewSale([['Soto <i>spesial</i>', 'Makanan', null]]);

    $html = viewSaleHtml($selling);

    expect($html)->toContain('Soto &lt;i&gt;spesial&lt;/i&gt;');
    expect($html)->not->toContain('<i>spesial</i>');
});

test('group titles appear when there are two or more categories', function () {
    $selling = viewSale([
        ['Soto', 'Makanan', null],
        ['Teh', 'Minuman', null],
    ]);

    $html = viewSaleHtml($selling);

    expect(viewSaleGroupTitles($html))->toBe(2);
    expect($html)->toContain('Makanan');
    expect($html)->toContain('Minuman');
});

test('no group title appears for a single category', function () {
    $selling = viewSale([
        ['Soto', 'Makanan', null],
        ['Bakso', 'Makanan', null],
    ]);

    expect(viewSaleGroupTitles(viewSaleHtml($selling)))->toBe(0);
});

test('lines are listed grouped: all of a category together, categories A to Z', function () {
    $selling = viewSale([
        ['Teh', 'Minuman', null],
        ['Soto', 'Makanan', null],
        ['Air', 'Minuman', null],
        ['Bakso', 'Makanan', null],
    ]);

    $html = viewSaleHtml($selling);

    $positions = array_map(fn (string $needle) => strpos($html, $needle), ['Makanan', 'Bakso', 'Soto', 'Minuman', 'Air', 'Teh']);
    expect(in_array(false, $positions, true))->toBeFalse();
    $sorted = $positions;
    sort($sorted);
    expect($positions)->toBe($sorted);
});

test('the page keeps working for a sale whose product was removed', function () {
    $selling = viewSale([['Menu Lama', 'Makanan', 'catatan lama']]);
    $selling->sellingDetails()->first()->product->delete();

    $html = viewSaleHtml($selling);

    expect($html)->toContain('Menu Lama');
    expect($html)->toContain('catatan lama');
});

test('the receipt payload handed to the print script carries the plain note and the line notes', function () {
    $selling = viewSale([['Soto', 'Makanan', 'tanpa seledri']], '<p>meja 5</p>');

    $html = viewSaleHtml($selling);

    expect($html)->toContain('_lakasirViewSelling');
    expect($html)->toContain('note_plain');
    expect($html)->toContain('tanpa seledri');
});
