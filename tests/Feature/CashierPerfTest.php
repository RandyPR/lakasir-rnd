<?php

// Benchmark halaman kasir. Dijalankan manual: PERF=1 php vendor/bin/pest tests/Feature/CashierPerfTest.php
// (data seed dibuat di dalam transaksi test lalu di-rollback, tidak menyentuh DB dev).

use App\Filament\Tenant\Pages\Cashier;
use App\Models\Tenants\Category;
use App\Models\Tenants\Product;
use App\Models\Tenants\Setting;
use App\Models\Tenants\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;

uses(RefreshDatabaseWithTenant::class);

function perfSeed(int $products): void
{
    $categoryId = Category::query()->value('id') ?? Category::create(['name' => 'Umum'])->id;
    $now = now()->toDateTimeString();
    $today = now()->toDateString();

    foreach (array_chunk(range(1, $products), 500) as $chunk) {
        DB::table('products')->insert(array_map(fn ($i) => [
            'category_id' => $categoryId,
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

function perfMeasure(string $label, Closure $fn): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $t = hrtime(true);
    $c = $fn();
    $ms = (hrtime(true) - $t) / 1e6;
    $log = DB::getQueryLog();
    $dbMs = array_sum(array_column($log, 'time'));
    $row = [
        'step' => $label, 'queries' => count($log), 'wall_ms' => round($ms, 1), 'db_ms' => round($dbMs, 1),
        'html_kb' => round(strlen($c->html() ?? '') / 1024, 1),
        'snapshot_kb' => round(strlen(json_encode($c->snapshot ?? [])) / 1024, 1),
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

test('benchmark cashier', function () {
    $n = (int) (getenv('PERF_PRODUCTS') ?: 5000);
    Setting::set('selling_method', 'fifo');
    perfSeed($n);
    $user = User::first();
    actingAs($user);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('tenant'));

    [, $c] = perfMeasure('mount (initial render)', fn () => Livewire::test(Cashier::class));
    perfMeasure('search "kopi"', fn () => $c->set('tableSearch', 'kopi'));
    perfMeasure('search barcode', fn () => $c->set('tableSearch', '899000000123'));
    $c->set('tableSearch', '');
    $pid = Product::first()->id;
    perfMeasure('addCart', fn () => $c->call('addCart', $pid));
    perfMeasure('addCart again', fn () => $c->call('addCart', $pid));
    perfMeasure('next page', fn () => $c->call('setPage', 2, 'tablePage'));
    expect(true)->toBeTrue();
})->skip(fn () => ! getenv('PERF'), 'set PERF=1 to run');
