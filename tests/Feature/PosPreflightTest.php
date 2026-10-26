<?php

use App\Console\Commands\PosPreflight;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\RefreshDatabaseWithTenant;

uses(RefreshDatabaseWithTenant::class);

const PREFLIGHT_NOTE = '2026_10_15_000001_add_note_to_cart_items_and_selling_details_tables';
const PREFLIGHT_BILLS = '2026_10_15_000002_create_open_bills_table';
const PREFLIGHT_ITEMS = '2026_10_15_000003_create_open_bill_items_table';
const PREFLIGHT_DATE_INDEX = '2026_10_15_000006_add_date_index_to_sellings_table';
const PREFLIGHT_TRASH = '2026_10_26_000001_add_soft_deletes_to_sellings_table';

/** @var array<int, string> temp directories to remove after the test */
$GLOBALS['preflight_temp_dirs'] = [];

afterEach(function () {
    foreach ($GLOBALS['preflight_temp_dirs'] as $dir) {
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
    $GLOBALS['preflight_temp_dirs'] = [];
});

/**
 * @return array{0: int, 1: string} exit code and everything the command printed
 */
function preflight(bool $pre = false): array
{
    $code = Artisan::call('pos:preflight', $pre ? ['--pre' => true] : []);

    return [$code, Artisan::output()];
}

/** Makes a migration file pending by registering an extra migration path that holds it. */
function preflightPendingFile(string $name): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'preflight_'.bin2hex(random_bytes(6));
    mkdir($dir);
    $GLOBALS['preflight_temp_dirs'][] = $dir;

    file_put_contents($dir.DIRECTORY_SEPARATOR.$name.'.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
    }

    public function down(): void
    {
    }
};
PHP);

    app('migrator')->path($dir);

    return $dir;
}

function preflightForget(string ...$names): void
{
    DB::table('migrations')->whereIn('migration', $names)->delete();
}

/*
|--------------------------------------------------------------------------
| --pre (before migrating)
|--------------------------------------------------------------------------
*/

test('--pre passes on a fresh schema and prints the database facts', function () {
    [$code, $output] = preflight(pre: true);

    expect($code)->toBe(0);
    expect($output)->toContain('HASIL: PASS');
    expect($output)->not->toContain('[FAIL]');
    expect($output)->toContain('--- Fakta database');
    expect($output)->toContain('driver: '.DB::getDriverName());
    expect($output)->toContain('database: ');
    expect($output)->toContain('jumlah baris sellings:');
    expect($output)->toContain('jumlah baris selling_details:');
    expect($output)->toContain('CACHE_DRIVER: '.config('cache.default'));
    expect($output)->toContain('Semua tabel inti ada');
});

test('--pre passes when the only pending migrations are files of this release', function () {
    preflightForget(
        '2026_10_14_000001_add_pos_performance_indexes',
        PREFLIGHT_NOTE,
        PREFLIGHT_BILLS,
        PREFLIGHT_ITEMS,
        '2026_10_15_000004_create_open_bill_events_table',
        '2026_10_15_000005_create_numbering_locks_table',
        PREFLIGHT_DATE_INDEX,
    );

    [$code, $output] = preflight(pre: true);

    expect($code)->toBe(0);
    expect($output)->toContain('HASIL: PASS');
    expect($output)->toContain('sebagian dari tujuh berkas rilis ini');
});

test('--pre passes when some of the seven were already run', function () {
    preflightForget(PREFLIGHT_DATE_INDEX);

    [$code] = preflight(pre: true);

    expect($code)->toBe(0);
});

test('--pre passes when the trash migration of the later release is still pending', function () {
    preflightForget(PREFLIGHT_TRASH);

    [$code, $output] = preflight(pre: true);

    expect($code)->toBe(0);
    expect($output)->toContain('HASIL: PASS');
    expect($output)->toContain(PREFLIGHT_TRASH);
});

test('--pre fails and names a pending migration that is not part of the release', function () {
    preflightPendingFile('2026_12_01_000001_drop_something_important');

    [$code, $output] = preflight(pre: true);

    expect($code)->toBe(1);
    expect($output)->toContain('HASIL: FAIL');
    expect($output)->toContain('[FAIL]');
    expect($output)->toContain('2026_12_01_000001_drop_something_important');
    expect($output)->toContain('JANGAN jalankan migrate');
});

test('--pre fails for an old migration that is still pending, and says it is before this release', function () {
    preflightForget('2025_10_03_042817_remove_barcode_from_products_table');

    [$code, $output] = preflight(pre: true);

    expect($code)->toBe(1);
    expect($output)->toContain('2025_10_03_042817_remove_barcode_from_products_table');
    expect($output)->toContain('masih Pending');
    expect($output)->toContain('JANGAN jalankan migrate');
});

test('--pre fails when another old migration is pending next to the performance one', function () {
    preflightForget('2026_10_14_000001_add_pos_performance_indexes', '2026_09_20_000002_add_daily_order_number_to_sellings_table');

    [$code, $output] = preflight(pre: true);

    expect($code)->toBe(1);
    expect($output)->toContain('2026_09_20_000002_add_daily_order_number_to_sellings_table');
});

test('--pre does not mind a pending file of this release even next to a not allowed one, but still fails', function () {
    preflightForget(PREFLIGHT_NOTE);
    preflightPendingFile('2026_11_01_000001_something_else');

    [$code, $output] = preflight(pre: true);

    expect($code)->toBe(1);
    expect($output)->toContain('2026_11_01_000001_something_else');
    expect($output)->not->toContain('- '.PREFLIGHT_NOTE);
});

test('--pre fails when a core table is missing', function () {
    Schema::drop('cart_items');

    [$code, $output] = preflight(pre: true);

    expect($code)->toBe(1);
    expect($output)->toContain('Tabel wajib tidak ada');
    expect($output)->toContain('cart_items');
});

test('--pre fails when the database is not the right one (several core tables missing)', function () {
    Schema::drop('cart_items');
    Schema::drop('stocks');

    [$code, $output] = preflight(pre: true);

    expect($code)->toBe(1);
    expect($output)->toContain('stocks');
    expect($output)->toContain('cart_items');
    expect($output)->toContain('DB_DATABASE');
});

test('--pre fails when the tables the new foreign keys point at are missing', function () {
    Schema::drop('open_bill_events');
    Schema::drop('open_bill_items');
    Schema::drop('open_bills');
    // cart_items and selling_details still point at price_units: MySQL only drops it without the key checks.
    Schema::withoutForeignKeyConstraints(fn () => Schema::drop('price_units'));

    [$code, $output] = preflight(pre: true);

    expect($code)->toBe(1);
    expect($output)->toContain('price_units');
});

/*
|--------------------------------------------------------------------------
| after migrating
|--------------------------------------------------------------------------
*/

test('the post-migration check passes on a fully migrated schema', function () {
    [$code, $output] = preflight();

    expect($code)->toBe(0);
    expect($output)->toContain('HASIL: PASS');
    expect($output)->not->toContain('[FAIL]');
    expect($output)->not->toContain('[WARN]');
    foreach (PosPreflight::RELEASE_MIGRATIONS as $name) {
        expect($output)->toContain("[PASS] Ran: {$name}");
    }
    expect($output)->toContain('Tabel open_bills ada.');
    expect($output)->toContain('Foreign key open_bills.table_id ada.');
    expect($output)->toContain('numbering_locks punya primary key.');
});

test('the post-migration check fails when the trash migration has not run', function () {
    preflightForget(PREFLIGHT_TRASH);

    [$code, $output] = preflight();

    expect($code)->toBe(1);
    expect($output)->toContain('Belum dijalankan: '.PREFLIGHT_TRASH);
});

test('the post-migration check fails when a trash column is missing', function () {
    Schema::table('sellings', fn (Blueprint $table) => $table->dropColumn('delete_reason'));

    [$code, $output] = preflight();

    expect($code)->toBe(1);
    expect($output)->toContain('Kolom sellings hilang: delete_reason');
});

test('the post-migration check fails when a new table is missing', function (string $table) {
    // Drop children first so the foreign keys allow it.
    foreach (['open_bill_events', 'open_bill_items', 'open_bills', 'numbering_locks'] as $candidate) {
        if ($candidate === $table || ($table === 'open_bills' && in_array($candidate, ['open_bill_events', 'open_bill_items'], true))) {
            Schema::drop($candidate);
        }
    }

    [$code, $output] = preflight();

    expect($code)->toBe(1);
    expect($output)->toContain('HASIL: FAIL');
    expect($output)->toContain("Tabel {$table} TIDAK ada.");
})->with(['open_bill_events', 'open_bill_items', 'open_bills', 'numbering_locks']);

test('the post-migration check fails when a column is missing', function () {
    Schema::table('cart_items', fn (Blueprint $table) => $table->dropColumn('note'));

    [$code, $output] = preflight();

    expect($code)->toBe(1);
    expect($output)->toContain('Kolom cart_items hilang: note');
});

test('the post-migration check fails when a new table lacks a column', function () {
    Schema::table('open_bill_items', fn (Blueprint $table) => $table->dropColumn('sent_note'));

    [$code, $output] = preflight();

    expect($code)->toBe(1);
    expect($output)->toContain('Kolom open_bill_items hilang: sent_note');
});

test('the post-migration check fails when an index is missing and says how to repair it', function () {
    Schema::table('open_bills', fn (Blueprint $table) => $table->dropIndex('open_bills_day_number_unique'));

    [$code, $output] = preflight();

    expect($code)->toBe(1);
    expect($output)->toContain('Indeks open_bills.open_bills_day_number_unique TIDAK ada');
    expect($output)->toContain('aman diulang');
});

test('the post-migration check fails when a performance index is missing', function () {
    // The foreign key on stocks.product_id needs an index (MySQL error 1553): before this release it had its own.
    Schema::table('stocks', fn (Blueprint $table) => $table->index('product_id'));
    Schema::table('stocks', fn (Blueprint $table) => $table->dropIndex('stocks_pos_lookup_index'));

    [$code, $output] = preflight();

    expect($code)->toBe(1);
    expect($output)->toContain('Indeks stocks.stocks_pos_lookup_index TIDAK ada');
});

test('the post-migration check fails when a required migration was never run', function () {
    preflightForget(PREFLIGHT_ITEMS);

    [$code, $output] = preflight();

    expect($code)->toBe(1);
    expect($output)->toContain('[FAIL] Belum dijalankan: '.PREFLIGHT_ITEMS);
});

test('the optional date index migration missing is only a warning', function () {
    preflightForget(PREFLIGHT_DATE_INDEX);
    Schema::table('sellings', fn (Blueprint $table) => $table->dropIndex('sellings_date_number_index'));

    [$code, $output] = preflight();

    expect($code)->toBe(0);
    expect($output)->toContain('HASIL: PASS (2 peringatan)');
    expect($output)->toContain('[WARN] Belum dijalankan (opsional');
    expect($output)->toContain('[WARN] Indeks sellings_date_number_index tidak ada');
    expect($output)->not->toContain('[FAIL]');
});

test('the date index missing while its migration ran is a warning too', function () {
    Schema::table('sellings', fn (Blueprint $table) => $table->dropIndex('sellings_date_number_index'));

    [$code, $output] = preflight();

    expect($code)->toBe(0);
    expect($output)->toContain('[WARN] Indeks sellings_date_number_index tidak ada');
});

test('the post-migration check reports every problem, not only the first', function () {
    Schema::table('cart_items', fn (Blueprint $table) => $table->dropColumn('note'));
    Schema::table('open_bills', fn (Blueprint $table) => $table->dropIndex('open_bills_status_day_index'));
    preflightForget(PREFLIGHT_NOTE);

    [$code, $output] = preflight();

    expect($code)->toBe(1);
    expect($output)->toContain('Kolom cart_items hilang: note');
    expect($output)->toContain('open_bills_status_day_index TIDAK ada');
    expect($output)->toContain('Belum dijalankan: '.PREFLIGHT_NOTE);
    expect($output)->toMatch('/HASIL: FAIL \(3 gagal/');
});

/*
|--------------------------------------------------------------------------
| read only
|--------------------------------------------------------------------------
*/

test('the command only reads: no write or schema statement runs, in either mode', function () {
    $statements = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$statements) {
        $statements[] = strtolower(trim($query->sql));
    });

    preflight(pre: true);
    preflight();
    Event::forget(QueryExecuted::class);

    expect($statements)->not->toBe([]);
    foreach ($statements as $sql) {
        expect($sql)->not->toMatch('/^(insert|update|delete|create|alter|drop|replace|truncate)\b/');
    }
});

test('the command leaves the migrations table and the schema untouched', function () {
    $ranBefore = DB::table('migrations')->orderBy('id')->pluck('migration')->all();
    $tablesBefore = collect(Schema::getTables())->pluck('name')->sort()->values()->all();
    preflightPendingFile('2026_12_01_000001_pending_but_must_stay_pending');

    preflight(pre: true);
    preflight();

    expect(DB::table('migrations')->orderBy('id')->pluck('migration')->all())->toBe($ranBefore);
    expect(collect(Schema::getTables())->pluck('name')->sort()->values()->all())->toBe($tablesBefore);
});

test('the pending migration of the check is never run', function () {
    preflightPendingFile('2026_12_01_000002_must_not_run');

    preflight(pre: true);

    expect(DB::table('migrations')->where('migration', '2026_12_01_000002_must_not_run')->exists())->toBeFalse();
});

test('the release list in the command matches the files on disk', function () {
    foreach (PosPreflight::RELEASE_MIGRATIONS as $name) {
        expect(file_exists(database_path("migrations/{$name}.php")))->toBeTrue();
    }
    expect(PosPreflight::RELEASE_MIGRATIONS)->toHaveCount(7);

    foreach (PosPreflight::LATER_MIGRATIONS as $name) {
        expect(file_exists(database_path("migrations/{$name}.php")))->toBeTrue();
    }
});
