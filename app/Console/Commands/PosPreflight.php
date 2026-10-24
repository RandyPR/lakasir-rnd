<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only deploy gate for the Open Bill release. It never writes and never runs a migration.
 *
 *   php artisan pos:preflight --pre   BEFORE migrating: database facts, core tables present, pending migrations
 *                                     must be a subset of the seven files of this release.
 *   php artisan pos:preflight         AFTER migrating: all seven ran, tables/columns/indexes/foreign keys exist.
 *
 * Exit code 0 = PASS (warnings allowed), 1 = FAIL.
 */
class PosPreflight extends Command
{
    /** Migrations of this release, in run order. Anything else pending before deploy is not allowed. */
    public const RELEASE_MIGRATIONS = [
        '2026_10_14_000001_add_pos_performance_indexes',
        '2026_10_15_000001_add_note_to_cart_items_and_selling_details_tables',
        '2026_10_15_000002_create_open_bills_table',
        '2026_10_15_000003_create_open_bill_items_table',
        '2026_10_15_000004_create_open_bill_events_table',
        '2026_10_15_000005_create_numbering_locks_table',
        '2026_10_15_000006_add_date_index_to_sellings_table',
    ];

    /** Optional migration: missing is only a warning (the code is correct without the index). */
    private const OPTIONAL_MIGRATION = '2026_10_15_000006_add_date_index_to_sellings_table';

    /** Tables that must exist before migrating (the seven core tables plus the parents of the new foreign keys). */
    private const REQUIRED_TABLES = [
        'users', 'products', 'stocks', 'sellings', 'selling_details', 'cart_items', 'migrations',
        'tables', 'members', 'price_units',
    ];

    /** Tables the new foreign keys point at: they must be InnoDB or MySQL refuses the constraint (error 1215). */
    private const FOREIGN_KEY_PARENTS = ['users', 'products', 'sellings', 'tables', 'members', 'price_units'];

    private const NEW_TABLES = ['open_bills', 'open_bill_items', 'open_bill_events', 'numbering_locks'];

    /** @var array<string, array<int, string>> table => index names that must exist after migrating */
    private const EXPECTED_INDEXES = [
        'stocks' => ['stocks_pos_lookup_index'],
        'cart_items' => ['cart_items_user_product_index'],
        'products' => ['products_pos_listing_index', 'products_sku_index'],
        'open_bills' => ['open_bills_day_number_unique', 'open_bills_status_day_index', 'open_bills_selling_id_unique'],
        'open_bill_items' => ['open_bill_items_bill_product_unique'],
        'open_bill_events' => ['open_bill_events_bill_index'],
    ];

    /** @var array<string, array<int, string>> table => columns that must exist after migrating */
    private const EXPECTED_COLUMNS = [
        'cart_items' => ['note'],
        'selling_details' => ['note'],
        'open_bills' => [
            'number', 'business_date', 'status', 'version', 'customer_name', 'table_id', 'member_id', 'note',
            'tax_percent', 'discount_price', 'kitchen_batch_count', 'last_kitchen_batch', 'pending_cancel',
            'kitchen_sent_at', 'created_by', 'paid_by', 'voided_by', 'paid_at', 'voided_at', 'void_reason',
            'selling_id',
        ],
        'open_bill_items' => [
            'open_bill_id', 'product_id', 'user_id', 'qty', 'price', 'discount_price', 'price_unit_id', 'note',
            'sent_qty', 'sent_note',
        ],
        'open_bill_events' => [
            'open_bill_id', 'user_id', 'action', 'product_id', 'product_name', 'qty_from', 'qty_to', 'reason',
            'detail', 'created_at',
        ],
        'numbering_locks' => ['name'],
    ];

    /** @var array<int, array{0:string,1:string}> [table, column] pairs that must carry a foreign key */
    private const EXPECTED_FOREIGN_KEYS = [
        ['open_bills', 'table_id'],
        ['open_bills', 'member_id'],
        ['open_bills', 'created_by'],
        ['open_bills', 'paid_by'],
        ['open_bills', 'voided_by'],
        ['open_bills', 'selling_id'],
        ['open_bill_items', 'open_bill_id'],
        ['open_bill_items', 'product_id'],
        ['open_bill_items', 'user_id'],
        ['open_bill_items', 'price_unit_id'],
        ['open_bill_events', 'open_bill_id'],
        ['open_bill_events', 'user_id'],
    ];

    protected $signature = 'pos:preflight {--pre : Check BEFORE running the migrations of this release}';

    protected $description = 'Read-only deploy gate for the Open Bill release (use --pre before migrating)';

    private int $failures = 0;

    private int $warnings = 0;

    public function handle(): int
    {
        $this->failures = 0;
        $this->warnings = 0;
        $pre = (bool) $this->option('pre');

        $this->line($pre
            ? 'pos:preflight --pre (SEBELUM migrasi). Perintah ini hanya membaca; tidak ada yang diubah.'
            : 'pos:preflight (SESUDAH migrasi). Perintah ini hanya membaca; tidak ada yang diubah.');

        try {
            $pre ? $this->checkBeforeMigrating() : $this->checkAfterMigrating();
        } catch (Throwable $e) {
            $this->bad('Pemeriksaan terhenti karena galat: '.$e->getMessage());
        }

        $this->newLine();
        if ($this->failures > 0) {
            $this->error("HASIL: FAIL ({$this->failures} gagal, {$this->warnings} peringatan)");
            if ($pre) {
                $this->error('JANGAN jalankan migrate. Kirim seluruh keluaran ini kepada pengembang.');
            }

            return self::FAILURE;
        }

        $this->info("HASIL: PASS ({$this->warnings} peringatan)");

        return self::SUCCESS;
    }

    private function checkBeforeMigrating(): void
    {
        $this->printDatabaseFacts();

        $missing = array_values(array_filter(self::REQUIRED_TABLES, fn (string $table) => ! Schema::hasTable($table)));
        if ($missing !== []) {
            $this->bad('Tabel wajib tidak ada: '.implode(', ', $missing).'. Ini kemungkinan bukan database yang benar (cek DB_DATABASE di .env).');
        } else {
            $this->ok('Semua tabel inti ada ('.implode(', ', self::REQUIRED_TABLES).').');
        }

        $this->checkParentEngines();

        $pending = $this->pendingMigrations();
        $notAllowed = array_values(array_diff($pending, self::RELEASE_MIGRATIONS));

        if ($notAllowed !== []) {
            $this->bad('Ada migrasi Pending yang BUKAN bagian dari rilis ini ('.count($notAllowed).'). Beberapa migrasi lama menjatuhkan kolom.');
            foreach ($notAllowed as $name) {
                $this->line("       - {$name}");
            }
        } else {
            $this->ok('Migrasi Pending hanya sebagian dari tujuh berkas rilis ini ('.($pending === [] ? 'tidak ada yang Pending' : implode(', ', $pending)).').');
        }

        $beforeRelease = array_values(array_filter(
            $pending,
            fn (string $name) => $name < '2026_10_15_000001' && $name !== self::RELEASE_MIGRATIONS[0],
        ));
        if ($beforeRelease !== []) {
            $this->bad('Migrasi sebelum 2026_10_15_000001 masih Pending (selain 2026_10_14_000001): kode ini tidak berjalan di commit yang Anda kira.');
        }
    }

    private function checkAfterMigrating(): void
    {
        if (! Schema::hasTable('migrations')) {
            $this->bad('Tabel migrations tidak ada.');

            return;
        }

        $pending = $this->pendingMigrations();
        foreach (self::RELEASE_MIGRATIONS as $name) {
            if (! in_array($name, $pending, true)) {
                $this->ok("Ran: {$name}");
            } elseif ($name === self::OPTIONAL_MIGRATION) {
                $this->caution("Belum dijalankan (opsional, kode tetap benar): {$name}");
            } else {
                $this->bad("Belum dijalankan: {$name}");
            }
        }

        foreach (self::NEW_TABLES as $table) {
            Schema::hasTable($table)
                ? $this->ok("Tabel {$table} ada.")
                : $this->bad("Tabel {$table} TIDAK ada.");
        }

        foreach (self::EXPECTED_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $absent = array_values(array_filter($columns, fn (string $column) => ! Schema::hasColumn($table, $column)));
            $absent === []
                ? $this->ok("Kolom {$table}: lengkap (".count($columns).').')
                : $this->bad("Kolom {$table} hilang: ".implode(', ', $absent));
        }

        foreach (self::EXPECTED_INDEXES as $table => $names) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($names as $name) {
                Schema::hasIndex($table, $name)
                    ? $this->ok("Indeks {$table}.{$name} ada.")
                    : $this->bad("Indeks {$table}.{$name} TIDAK ada (jalankan ulang migrasinya; aman diulang).");
            }
        }

        if (Schema::hasTable('numbering_locks')) {
            $hasPrimary = collect(Schema::getIndexes('numbering_locks'))->contains(fn (array $index) => $index['primary'] ?? false);
            $hasPrimary
                ? $this->ok('numbering_locks punya primary key.')
                : $this->bad('numbering_locks tidak punya primary key.');
        }

        $this->checkForeignKeys();
        $this->checkNewTableEngines();

        if (Schema::hasTable('sellings')) {
            Schema::hasIndex('sellings', 'sellings_date_number_index')
                ? $this->ok('Indeks sellings.sellings_date_number_index ada.')
                : $this->caution('Indeks sellings_date_number_index tidak ada (opsional; penomoran tetap benar, hanya sedikit lebih lambat).');
        }
    }

    private function checkForeignKeys(): void
    {
        $byTable = [];
        foreach (self::EXPECTED_FOREIGN_KEYS as [$table, $column]) {
            $byTable[$table][] = $column;
        }

        foreach ($byTable as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = [];
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                foreach ($foreignKey['columns'] ?? [] as $column) {
                    $existing[$column] = true;
                }
            }

            foreach ($columns as $column) {
                isset($existing[$column])
                    ? $this->ok("Foreign key {$table}.{$column} ada.")
                    : $this->bad("Foreign key {$table}.{$column} TIDAK ada (jalankan ulang migrasi tabel ini; aman diulang).");
            }
        }
    }

    private function printDatabaseFacts(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        $this->line('--- Fakta database (kirim bagian ini kepada pengembang) ---');
        $this->line("driver: {$driver}");
        $this->line('database: '.$connection->getDatabaseName());

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->factQuery('VERSION()', 'SELECT VERSION() AS v');
            foreach (['@@system_time_zone', '@@global.time_zone', '@@transaction_isolation', '@@innodb_lock_wait_timeout', '@@innodb_default_row_format', '@@sql_mode'] as $variable) {
                $this->factQuery($variable, "SELECT {$variable} AS v");
            }
        } elseif ($driver === 'sqlite') {
            $this->factQuery('sqlite_version()', 'SELECT sqlite_version() AS v');
        }

        foreach (['sellings', 'selling_details'] as $table) {
            if (Schema::hasTable($table)) {
                $this->line("jumlah baris {$table}: ".DB::table($table)->count());
            } else {
                $this->line("jumlah baris {$table}: (tabel tidak ada)");
            }
        }

        $this->line('CACHE_DRIVER: '.config('cache.default'));

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $odd = $this->nonInnoDbTables();
            if ($odd === []) {
                $this->line('tabel non-InnoDB / row_format bukan Dynamic/Compressed: (kosong, bagus)');
            } else {
                foreach ($odd as $row) {
                    $this->caution("tabel {$row['name']}: engine={$row['engine']} row_format={$row['row_format']}");
                }
            }
        }

        $this->line('-----------------------------------------------------------');
    }

    private function factQuery(string $label, string $sql): void
    {
        try {
            $row = DB::selectOne($sql);
            $this->line($label.': '.($row->v ?? '(kosong)'));
        } catch (Throwable) {
            $this->line($label.': (tidak tersedia di versi ini)');
        }
    }

    /**
     * @return array<int, array{name:string,engine:string,row_format:string}>
     */
    private function nonInnoDbTables(): array
    {
        $rows = DB::select(
            "SELECT TABLE_NAME AS name, ENGINE AS engine, ROW_FORMAT AS row_format
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
               AND (ENGINE <> 'InnoDB' OR ROW_FORMAT NOT IN ('Dynamic', 'Compressed'))"
        );

        return array_map(fn ($row) => [
            'name' => (string) ($row->name ?? $row->NAME ?? ''),
            'engine' => (string) ($row->engine ?? $row->ENGINE ?? ''),
            'row_format' => (string) ($row->row_format ?? $row->ROW_FORMAT ?? ''),
        ], $rows);
    }

    private function checkParentEngines(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $bad = array_filter(
            $this->nonInnoDbTables(),
            fn (array $row) => in_array($row['name'], self::FOREIGN_KEY_PARENTS, true) && strcasecmp($row['engine'], 'InnoDB') !== 0,
        );

        if ($bad !== []) {
            $this->bad('Tabel induk foreign key bukan InnoDB: '.implode(', ', array_column($bad, 'name')).' (MySQL akan menolak foreign key, error 1215).');
        } else {
            $this->ok('Tabel induk foreign key semuanya InnoDB.');
        }
    }

    private function checkNewTableEngines(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count(self::NEW_TABLES), '?'));
        $rows = DB::select(
            "SELECT TABLE_NAME AS name, ENGINE AS engine FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})",
            self::NEW_TABLES,
        );

        foreach ($rows as $row) {
            $name = (string) ($row->name ?? $row->NAME ?? '');
            $engine = (string) ($row->engine ?? $row->ENGINE ?? '');
            strcasecmp($engine, 'InnoDB') === 0
                ? $this->ok("Tabel {$name} adalah InnoDB.")
                : $this->bad("Tabel {$name} bukan InnoDB (engine={$engine}).");
        }
    }

    /**
     * Migration files in database/migrations whose name is not in the migrations table. Runs nothing.
     *
     * @return array<int, string>
     */
    private function pendingMigrations(): array
    {
        $migrator = app('migrator');
        $repository = $migrator->getRepository();

        $ran = $repository->repositoryExists() ? $repository->getRan() : [];
        $files = $migrator->getMigrationFiles(array_values(array_unique(array_merge(
            $migrator->paths(),
            [database_path('migrations')],
        ))));

        return array_values(array_diff(array_keys($files), $ran));
    }

    private function ok(string $message): void
    {
        $this->line("[PASS] {$message}");
    }

    private function caution(string $message): void
    {
        $this->warnings++;
        $this->line("[WARN] {$message}");
    }

    private function bad(string $message): void
    {
        $this->failures++;
        $this->line("[FAIL] {$message}");
    }
}
