<?php

use App\Models\Tenants\About;
use App\Models\Tenants\CartItem;
use App\Models\Tenants\Category;
use App\Models\Tenants\OpenBill;
use App\Models\Tenants\OpenBillItem;
use App\Models\Tenants\PaymentMethod;
use App\Models\Tenants\Product;
use App\Models\Tenants\Selling;
use App\Models\Tenants\SellingDetail;
use App\Models\Tenants\Table;
use App\Models\Tenants\User;
use App\Services\Tenants\OpenBillService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;

uses(RefreshDatabaseWithTenant::class);

/*
|--------------------------------------------------------------------------
| The print script of the cashier (partials/receipt-print)
|--------------------------------------------------------------------------
|
| Static checks of the views around it, and a run of the real script with the real Printer class in Node
| (tests/Support/receipt-harness.mjs) on payloads made by the real models and services: a sale, a provisional
| bill and every kind of kitchen ticket, on 58 and 80 mm paper, plus the print queue. The Node part is skipped
| when node is not installed. RECEIPT_HARNESS_DUMP=<directory> writes the preview texts there.
*/

if (! function_exists('rpSources')) {
    /**
     * @param  list<string>  $directories  relative to resources/views
     * @return array<string, string> path => source
     */
    function rpSources(array $directories, string $suffix = '.blade.php'): array
    {
        $sources = [];
        foreach ($directories as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path($directory)));
            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
                    $path = ltrim(str_replace(base_path(), '', $file->getPathname()), '/\\');
                    $sources[str_replace('\\', '/', $path)] = file_get_contents($file->getPathname());
                }
            }
        }
        ksort($sources);

        return $sources;
    }
}

if (! function_exists('rpPartial')) {
    function rpPartial(): string
    {
        return file_get_contents(resource_path('views/filament/tenant/partials/receipt-print.blade.php'));
    }
}

if (! function_exists('rpProduct')) {
    function rpProduct(string $name, Category $category, float $price, ?string $sku = null): Product
    {
        return Product::factory()->create([
            'name' => $name,
            'sku' => $sku,
            'category_id' => $category->id,
            'initial_price' => 1000,
            'selling_price' => $price,
            'stock' => 50,
        ]);
    }
}

if (! function_exists('rpBill')) {
    /**
     * A bill made the way the cashier makes one: the cart of $user becomes the bill.
     *
     * @param  list<array{0:Product,1:float,2?:?string}>  $lines  product, qty, note
     */
    function rpBill(User $user, array $header, array $lines): OpenBill
    {
        foreach ($lines as $line) {
            CartItem::create([
                'user_id' => $user->id,
                'product_id' => $line[0]->id,
                'qty' => $line[1],
                'price' => $line[0]->selling_price * $line[1],
                'note' => $line[2] ?? null,
            ]);
        }

        return app(OpenBillService::class)->createFromCart($user, $header);
    }
}

if (! function_exists('rpItem')) {
    function rpItem(OpenBill $bill, Product $product): OpenBillItem
    {
        return OpenBillItem::query()->where('open_bill_id', $bill->id)->where('product_id', $product->id)->firstOrFail();
    }
}

if (! function_exists('rpKitchen')) {
    /**
     * The kitchen ticket of everything the kitchen was not told yet, as the page gets it, then acknowledged.
     *
     * @return array<string, mixed>
     */
    function rpKitchen(OpenBill $bill, User $user): array
    {
        $service = app(OpenBillService::class);
        $batch = $service->kitchenBatch($service->find($bill->id));
        expect($batch)->not->toBeNull();

        $payload = $service->printPayload($bill, 'kitchen', $batch, false, $user);
        $service->ackKitchenBatch($bill->id, $batch['ack'], $user);

        return $payload;
    }
}

if (! function_exists('rpFixture')) {
    /**
     * Every payload the harness prints, from the real models and services, and the rendered script.
     *
     * @return array<string, mixed>
     */
    function rpFixture(User $owner): array
    {
        $service = app(OpenBillService::class);
        $food = Category::factory()->create(['name' => 'Makanan']);
        $drinks = Category::factory()->create(['name' => 'Minuman Dingin Dan Segar Pilihan Spesial']);
        $table5 = Table::create(['number' => '5']);
        $table7 = Table::create(['number' => '7']);

        $nasi = rpProduct('Nasi Goreng Spesial', $food, 25000);
        $kerupuk = rpProduct('Kerupuk', $food, 3333.33);
        $teriyaki = rpProduct('照り焼き', $food, 30000, 'TERIYAKI-01');
        $teh = rpProduct('Es Teh', $drinks, 5000);
        $air = rpProduct('Air Mineral', $drinks, 4000);

        // A sale, as the success dialog gets it (selling-created). The note with printer commands is a legacy row:
        // notes are cleaned when they are saved now, so it is written past the model.
        $selling = Selling::create([
            'user_id' => $owner->id,
            'payment_method_id' => PaymentMethod::first()->id,
            'table_id' => $table5->id,
            'customer_name' => 'Meja “VIP”',
            'note' => '<p>Tolong cepat</p><p>'.str_repeat('a', 300).'</p>',
            'total_price' => 99000,
            'total_discount_per_item' => 5000,
            'payed_money' => 100000,
            'money_changes' => 6000,
            'total_qty' => 8,
        ]);
        foreach ([
            [$nasi, 2, 50000, 5000, 'pedas, tanpa telur'],
            [$kerupuk, 3, 10000, 0, null],
            [$teriyaki, 1, 30000, 0, '“pedas” ‘sedang’'],
            [$teh, 1, 5000, 0, null],
            [$air, 1, 4000, 0, null],
        ] as [$product, $qty, $price, $discount, $note]) {
            SellingDetail::create([
                'selling_id' => $selling->id,
                'product_id' => $product->id,
                'qty' => $qty,
                'price' => $price,
                'cost' => 0,
                'discount_price' => $discount,
                'note' => $note,
            ]);
        }
        DB::table('selling_details')->where('selling_id', $selling->id)->where('product_id', $air->id)
            ->update(['note' => "\x1b@jangan dipotong\x1dV0"]);
        $receipt = json_decode(json_encode(Selling::find($selling->id)->loadForReceipt()), true);

        // Bill #A at table 5: new order, reprint, addition, cancellation + changed note, move to table 7, void.
        $bill = rpBill($owner, ['table_id' => $table5->id, 'customer_name' => 'Budi'], [
            [$nasi, 2, 'pedas'],
            [$teh, 1],
        ]);
        $kitchenNew = rpKitchen($bill, $owner);
        $kitchenReprint = $service->printPayload(
            $bill,
            'kitchen',
            $service->lastKitchenBatch($service->find($bill->id)),
            true,
            $owner,
        );

        $service->addLine($bill->id, $nasi, 1, null, null, $owner);
        $kitchenAddition = rpKitchen($bill, $owner);

        $service->setLineQty($bill->id, rpItem($bill, $teh)->id, 0, $owner, 'Habis');
        $service->setLineNote($bill->id, rpItem($bill, $nasi)->id, 'tidak pedas', $owner);
        $kitchenCancel = rpKitchen($bill, $owner);

        $fresh = $service->find($bill->id);
        $service->updateHeader($fresh, ['table_id' => $table7->id], (int) $fresh->version, $owner);
        $kitchenMoved = rpKitchen($bill, $owner);

        $fresh = $service->find($bill->id);
        $ticket = $service->void($fresh, $owner, 'Pelanggan pergi', (int) $fresh->version);
        expect($ticket)->not->toBeNull();
        $kitchenVoid = $service->printPayload($service->find($bill->id), 'kitchen', $ticket, false, $owner);

        // Bill #B, takeaway without a name: its first kitchen ticket and its provisional bill.
        $takeaway = rpBill($owner, [], [[$nasi, 2], [$teh, 1]]);
        $kitchenTakeaway = rpKitchen($takeaway, $owner);
        $provisional = $service->printPayload($takeaway, 'bill', null, false, $owner);

        // Too long for one line, even more so twice as wide.
        About::query()->update([
            'shop_name' => 'Warung Makan Sederhana Bu Tini Asli',
            'shop_location' => 'Jl. Merdeka No. 10, Kota Bandung, Jawa Barat 40111',
        ]);

        app()->setLocale('id');
        try {
            $html = view('filament.tenant.partials.receipt-print')->render();
        } finally {
            app()->setLocale(config('app.locale'));
        }
        expect(preg_match('/<script>(.*)<\/script>/s', $html, $script))->toBe(1);

        return [
            'locale' => 'id',
            'script' => $script[1],
            'printer' => file_get_contents(resource_path('js/printer.js')),
            'payloads' => [
                'receipt' => $receipt,
                'bill' => $provisional,
                'kitchenNew' => $kitchenNew,
                'kitchenAddition' => $kitchenAddition,
                'kitchenCancel' => $kitchenCancel,
                'kitchenMoved' => $kitchenMoved,
                'kitchenReprint' => $kitchenReprint,
                'kitchenVoid' => $kitchenVoid,
                'kitchenTakeaway' => $kitchenTakeaway,
            ],
        ];
    }
}

test('the partial renders the printing API with translated labels', function () {
    app()->setLocale('id');
    try {
        $html = view('filament.tenant.partials.receipt-print')->render();
    } finally {
        app()->setLocale(config('app.locale'));
    }

    expect($html)->toContain('window.LakasirReceipt = {')
        ->toContain('version: 2')
        ->toContain('groupDetails: groupDetails')
        ->toContain('window.LakasirReceiptConfig = ')
        ->and(substr_count($html, '<script>'))->toBe(1);

    // @js escapes the config, so the labels are inside a JSON.parse string.
    expect(preg_match("/window\\.LakasirReceiptConfig = JSON\\.parse\\('(.*?)'\\);/s", $html, $config))->toBe(1);
    $decoded = json_decode(json_decode('"'.$config[1].'"'), true);
    expect($decoded['labels']['kitchen'])->toBe('DAPUR')
        ->and($decoded['labels']['notProof'])->toBe('BUKAN BUKTI PEMBAYARAN')
        ->and($decoded['paperWidth'])->toBe('58');
});

test('the print script never writes markup into the page', function () {
    expect(rpPartial())->not->toMatch('/\.(innerHTML|outerHTML)\b|insertAdjacentHTML|document\.write/');
});

test('only the print queue and the printer test page cut the paper', function () {
    $cutting = array_keys(array_filter(
        rpSources(['views']) + rpSources(['js'], '.js'),
        fn (string $source): bool => str_contains($source, '.cut('),
    ));

    expect($cutting)->toBe([
        'resources/views/filament/tenant/pages/printer.blade.php',
        'resources/views/filament/tenant/partials/receipt-print.blade.php',
    ]);
});

test('the print script is rendered once, at the end of the body, by the pages that print', function () {
    foreach (rpSources(['views']) as $file => $source) {
        expect(preg_match('/@include\w*\([^)]*receipt-print/', $source))->toBe(0, $file);
    }

    foreach ([
        app_path('Filament/Tenant/Pages/Cashier.php'),
        app_path('Filament/Tenant/Resources/SellingResource/Pages/ViewSelling.php'),
    ] as $page) {
        expect(file_get_contents($page))
            ->toMatch("/PanelsRenderHook::BODY_END,\s*fn \(\) => view\('filament\.tenant\.partials\.receipt-print'\)/");
    }
});

test('the cashier listens to keydown, never to the old keypress handlers', function () {
    $sources = rpSources(['views/filament/tenant/pages/cashier', 'views/livewire/cashier'])
        + ['cashier' => file_get_contents(resource_path('views/filament/tenant/pages/cashier.blade.php'))];

    foreach ($sources as $file => $source) {
        expect($source)->not->toContain('keypress', $file);
    }
});

test('Livewire keys are stable and the cart lines carry the key of their context', function () {
    foreach (rpSources(['views/filament/tenant/pages/cashier', 'views/livewire/cashier']) as $file => $source) {
        preg_match_all('/wire:key="([^"]*)"/', $source, $keys);
        foreach ($keys[1] as $key) {
            expect($key)->not->toMatch('/rand\(|uniqid\(|Str::random|random_int|microtime|now\(\)/', $file);
        }
    }

    $cashier = resource_path('views/filament/tenant/pages/cashier');
    expect(file_get_contents("{$cashier}/cart-item.blade.php"))->toContain('wire:key="{{ $ctxKey }}-{{ $item->id }}"')
        ->and(file_get_contents("{$cashier}/items.blade.php"))->toContain('wire:key="group-{{ $ctxKey }}-');
});

test('the payment, the bills and the pages around them print only through LakasirReceipt', function () {
    $sources = rpSources(['views/filament/tenant/pages/cashier/payment', 'views/filament/tenant/pages/cashier/bills'])
        + [
            'cashier' => file_get_contents(resource_path('views/filament/tenant/pages/cashier.blade.php')),
            'bootstrap' => file_get_contents(resource_path('views/filament/tenant/pages/cashier/bootstrap.blade.php')),
            'view-selling' => file_get_contents(
                resource_path('views/filament/tenant/resources/sellings/pages/view-selling.blade.php'),
            ),
        ];

    foreach ($sources as $file => $source) {
        expect($source)->not->toMatch('/new\s+\(?\s*(window\.)?Printer\b|handleCashierPrintReceipt|function formatReceiptMoney|\.cut\(/', $file);
    }
});

test('the real print script prints sales, bills and kitchen tickets right on both paper widths', function () {
    $node = (new ExecutableFinder())->find('node');
    if ($node === null) {
        $this->markTestSkipped('node is not installed');
    }

    $owner = User::first();
    actingAs($owner);
    About::query()->update(['business_type' => 'fnb']);

    $fixture = rpFixture($owner);
    $path = tempnam(sys_get_temp_dir(), 'receipt-fixture-');
    file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));

    try {
        $arguments = [$node, base_path('tests/Support/receipt-harness.mjs'), $path];
        if (is_string(getenv('RECEIPT_HARNESS_DUMP')) && getenv('RECEIPT_HARNESS_DUMP') !== '') {
            $arguments[] = getenv('RECEIPT_HARNESS_DUMP');
        }
        $result = Process::timeout(120)->run($arguments);
    } finally {
        @unlink($path);
    }

    $report = json_decode(trim($result->output()), true);
    expect($report)->toBeArray("No report from the harness:\n".$result->output()."\n".$result->errorOutput());
    expect($report['failures'])->toBe([])
        ->and($report['checks'])->toBeGreaterThan(120)
        ->and($result->exitCode())->toBe(0);
});
