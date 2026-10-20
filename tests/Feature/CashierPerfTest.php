<?php

// Benchmark halaman kasir. Dijalankan manual: PERF=1 php vendor/bin/pest tests/Feature/CashierPerfTest.php
// (data seed dibuat di dalam transaksi test lalu di-rollback, tidak menyentuh DB dev).
// PERF_PRODUCTS mengatur jumlah produk (bawaan 5000), PERF_BUDGET_MS batas waktu cari/tambah (bawaan 200 ms).

use App\Filament\Tenant\Pages\Cashier;
use App\Livewire\Cashier\ProductBrowser;
use App\Models\Tenants\Category;
use App\Models\Tenants\Product;
use App\Models\Tenants\Setting;
use App\Models\Tenants\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;

uses(RefreshDatabaseWithTenant::class);

if (! function_exists('perfSeed')) {
    function perfSeed(int $products): void
    {
        $categoryIds = collect(['Makanan', 'Minuman', 'Snack', 'Lain-lain'])
            ->map(fn (string $name): int => Category::query()->firstOrCreate(['name' => $name])->id)
            ->all();
        $now = now()->toDateTimeString();
        $today = now()->toDateString();

        foreach (array_chunk(range(1, $products), 500) as $chunk) {
            DB::table('products')->insert(array_map(fn ($i) => [
                'category_id' => $categoryIds[$i % count($categoryIds)],
                'name' => 'Produk '.($i % 7 === 0 ? 'Kopi ' : '').'Item '.$i,
                'sku' => 'SKU'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'initial_price' => 10000, 'selling_price' => 15000 + $i, 'unit' => 'PCS',
                'type' => 'product', 'show' => true, 'is_non_stock' => $i % 20 === 0,
                'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }
        $ids = DB::table('products')->pluck('id')->all();
        foreach (array_chunk($ids, 300) as $chunk) {
            DB::table('stocks')->insert(array_merge(...array_map(fn ($id) => [
                ['product_id' => $id, 'stock' => 20, 'init_stock' => 20, 'initial_price' => 10000, 'selling_price' => 16000,
                    'type' => 'in', 'is_ready' => 1, 'date' => $today, 'created_at' => $now, 'updated_at' => $now],
                ['product_id' => $id, 'stock' => 15, 'init_stock' => 15, 'initial_price' => 10000, 'selling_price' => 16500,
                    'type' => 'in', 'is_ready' => 1, 'date' => $today, 'created_at' => $now, 'updated_at' => $now],
            ], $chunk)));
            DB::table('barcodes')->insert(array_map(fn ($id) => [
                'product_id' => $id, 'code' => '899'.str_pad((string) $id, 10, '0', STR_PAD_LEFT),
                'type' => 'primary', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }
    }
}

if (! function_exists('perfMeasure')) {
    /**
     * Runs one step and prints what it cost: queries, wall and database time, the size of the HTML and the snapshot
     * of the component, the most repeated queries (N+1) and the slowest ones.
     *
     * @return array{0: array<string, mixed>, 1: Testable}
     */
    function perfMeasure(string $label, Closure $fn): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $t = hrtime(true);
        $c = $fn();
        $ms = (hrtime(true) - $t) / 1e6;
        $log = DB::getQueryLog();
        DB::disableQueryLog();
        $dbMs = array_sum(array_column($log, 'time'));
        $html = $c->html() ?? '';
        $row = [
            'step' => $label, 'queries' => count($log), 'wall_ms' => round($ms, 1), 'db_ms' => round($dbMs, 1),
            'html_kb' => round(strlen($html) / 1024, 1),
            'snapshot_kb' => round(strlen(json_encode($c->snapshot ?? [])) / 1024, 1),
            'cards' => substr_count($html, 'data-pv-card'),
        ];
        // Query paling sering muncul (indikasi N+1)
        $counts = [];
        foreach ($log as $q) {
            $k = preg_replace('/\d+/', 'N', substr($q['query'], 0, 110));
            $counts[$k] = ($counts[$k] ?? 0) + 1;
        }
        arsort($counts);
        $row['top'] = array_slice($counts, 0, 4, true);
        usort($log, fn ($a, $b) => $b['time'] <=> $a['time']);
        $row['slowest'] = array_map(fn ($q) => round($q['time'], 1).'ms '.substr($q['query'], 0, 230), array_slice($log, 0, 4));
        fwrite(STDERR, json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return [$row, $c];
    }
}

test('benchmark cashier', function () {
    $n = (int) (getenv('PERF_PRODUCTS') ?: 5000);
    $budget = (float) (getenv('PERF_BUDGET_MS') ?: 200);
    Setting::set('selling_method', 'fifo');
    Cache::clear();
    perfSeed($n);
    $user = User::first();
    actingAs($user);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('tenant'));

    // Settings, the profile and the shop are cached by the first request, as in a running shop.
    Livewire::test(Cashier::class);

    [$mount] = perfMeasure('cashier: mount (initial render, list included)', fn () => Livewire::test(Cashier::class));

    // The product list is its own component: these requests do not touch the cart page.
    [$list] = perfMeasure('list: first page, no search', fn () => Livewire::test(ProductBrowser::class));
    $browser = Livewire::test(ProductBrowser::class);
    [$kopi] = perfMeasure('list: search "kopi"', fn () => $browser->set('search', 'kopi'));
    [$barcode] = perfMeasure('list: search part of a barcode', fn () => $browser->set('search', '899000000123'));
    $browser->set('search', '')->call('loadMore')->call('loadMore');
    [$max] = perfMeasure('list: load more up to '.ProductBrowser::MAX, fn () => $browser->call('loadMore'));

    // A cart action renders the cart page only, never the product list.
    $page = Livewire::test(Cashier::class);
    $pid = Product::query()->orderBy('id')->value('id');
    [$add] = perfMeasure('cart: addCart', fn () => $page->call('addCart', $pid));
    [$again] = perfMeasure('cart: addCart again', fn () => $page->call('addCart', $pid));

    expect($mount['cards'])->toBeGreaterThan(0)
        ->and($list['cards'])->toBe(ProductBrowser::PAGE_SIZE)
        ->and($kopi['cards'])->toBeGreaterThan(0)
        ->and($barcode['cards'])->toBeGreaterThan(0)
        ->and($max['cards'])->toBe(ProductBrowser::MAX)
        ->and($add['cards'])->toBe(0)
        ->and($again['cards'])->toBe(0);

    // The budget (plan: search on 5000 products in about 0.2 s, addCart no slower than the baseline). If the search
    // is over it, report it; do not raise the budget quietly.
    expect($kopi['wall_ms'])->toBeLessThan($budget, 'search "kopi" over budget')
        ->and($max['wall_ms'])->toBeLessThan($budget, 'load more over budget')
        ->and($add['wall_ms'])->toBeLessThan($budget, 'addCart over budget')
        ->and($again['wall_ms'])->toBeLessThan($budget, 'addCart over budget');
})->skip(fn () => ! getenv('PERF'), 'set PERF=1 to run');
