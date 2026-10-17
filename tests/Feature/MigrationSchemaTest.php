<?php

use App\Console\Commands\PosPreflight;
use App\Models\Tenants\Product;
use App\Models\Tenants\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\RefreshDatabaseWithTenant;

uses(RefreshDatabaseWithTenant::class);

/** The seven migrations of this release, in run order. */
const SCHEMA_RELEASE = [
    '2026_10_14_000001_add_pos_performance_indexes',
    '2026_10_15_000001_add_note_to_cart_items_and_selling_details_tables',
    '2026_10_15_000002_create_open_bills_table',
    '2026_10_15_000003_create_open_bill_items_table',
    '2026_10_15_000004_create_open_bill_events_table',
    '2026_10_15_000005_create_numbering_locks_table',
    '2026_10_15_000006_add_date_index_to_sellings_table',
];

function schemaMigrationPath(string $name): string
{
    return database_path('migrations/'.$name.'.php');
}

function schemaMigration(string $name): Migration
{
    return require schemaMigrationPath($name);
}

function schemaMigrationSource(string $name): string
{
    return (string) file_get_contents(schemaMigrationPath($name));
}

/** @return array<int, string> */
function schemaIndexNames(string $table): array
{
    return array_map(fn (array $index) => $index['name'], Schema::getIndexes($table));
}

function schemaColumn(string $table, string $column): ?array
{
    return collect(Schema::getColumns($table))->firstWhere('name', $column);
}

/** Runs every release migration's up() on a copy of the schema as it is now. */
function schemaRunAllUp(): void
{
    foreach (SCHEMA_RELEASE as $name) {
        schemaMigration($name)->up();
    }
}

/*
|--------------------------------------------------------------------------
| the migrations as files
|--------------------------------------------------------------------------
*/

test('the release is exactly these seven migration files, in this order', function () {
    foreach (SCHEMA_RELEASE as $name) {
        expect(file_exists(schemaMigrationPath($name)))->toBeTrue();
    }

    expect(PosPreflight::RELEASE_MIGRATIONS)->toBe(SCHEMA_RELEASE);
    $sorted = SCHEMA_RELEASE;
    sort($sorted);
    expect($sorted)->toBe(SCHEMA_RELEASE);
});

test('the dropped products listing-index migration is gone', function () {
    $files = glob(database_path('migrations/2026_10_15_000002_*.php'));

    expect(array_map('basename', $files))->toBe(['2026_10_15_000002_create_open_bills_table.php']);
    expect(glob(database_path('migrations/*add_listing_indexes_to_products_table*')))->toBe([]);
});

test('no new migration uses ->after(): MySQL adds the column without rebuilding the table', function () {
    foreach (SCHEMA_RELEASE as $name) {
        expect(schemaMigrationSource($name))->not->toContain('->after(');
        expect(schemaMigrationSource($name))->not->toContain('after(');
    }
});

test('no new migration rewrites existing columns or data', function () {
    foreach (SCHEMA_RELEASE as $name) {
        $source = schemaMigrationSource($name);

        expect($source)->not->toContain('->change()');
        expect($source)->not->toMatch('/DB::table\([^)]*\)->(insert|update|delete|truncate)/');
        expect($source)->not->toContain('DB::statement');
        expect($source)->not->toContain('Schema::drop(');
    }
});

test('every migration of the release goes through SafeMigration or guards itself', function () {
    expect(schemaMigrationSource('2026_10_14_000001_add_pos_performance_indexes'))->toContain('SafeMigration::index');
    expect(schemaMigrationSource('2026_10_15_000001_add_note_to_cart_items_and_selling_details_tables'))->toContain('SafeMigration::addColumn');
    expect(schemaMigrationSource('2026_10_15_000006_add_date_index_to_sellings_table'))->toContain('SafeMigration::index');

    foreach (['2026_10_15_000002_create_open_bills_table', '2026_10_15_000003_create_open_bill_items_table', '2026_10_15_000004_create_open_bill_events_table', '2026_10_15_000005_create_numbering_locks_table'] as $name) {
        $source = schemaMigrationSource($name);
        expect($source)->toContain('Schema::hasTable(');
        expect($source)->toContain("\$table->engine = 'InnoDB';");
        expect($source)->toContain('Schema::dropIfExists(');
    }
});

test('the foreign keys are added one by one, guarded, on the non-sqlite path (reviewed in the DDL)', function () {
    $expected = [
        '2026_10_15_000002_create_open_bills_table' => [
            "SafeMigration::foreign('open_bills', 'table_id', 'tables')",
            "SafeMigration::foreign('open_bills', 'member_id', 'members')",
            "SafeMigration::foreign('open_bills', 'created_by', 'users')",
            "SafeMigration::foreign('open_bills', 'paid_by', 'users')",
            "SafeMigration::foreign('open_bills', 'voided_by', 'users')",
            "SafeMigration::foreign('open_bills', 'selling_id', 'sellings')",
        ],
        '2026_10_15_000003_create_open_bill_items_table' => [
            "SafeMigration::foreign('open_bill_items', 'open_bill_id', 'open_bills', 'cascade')",
            "SafeMigration::foreign('open_bill_items', 'product_id', 'products', 'restrict')",
            "SafeMigration::foreign('open_bill_items', 'user_id', 'users')",
            "SafeMigration::foreign('open_bill_items', 'price_unit_id', 'price_units')",
        ],
        '2026_10_15_000004_create_open_bill_events_table' => [
            "SafeMigration::foreign('open_bill_events', 'open_bill_id', 'open_bills', 'cascade')",
            "SafeMigration::foreign('open_bill_events', 'user_id', 'users')",
        ],
    ];

    foreach ($expected as $name => $statements) {
        foreach ($statements as $statement) {
            expect(schemaMigrationSource($name))->toContain($statement);
        }
    }

    // The audit trail keeps product_id as a snapshot: no foreign key on it.
    expect(schemaMigrationSource('2026_10_15_000004_create_open_bill_events_table'))->not->toContain("'product_id', 'products'");
});

test('config/app.php keeps the timezone on UTC; only the business day moves', function () {
    expect(config('app.timezone'))->toBe('UTC');
    expect(config('pos.business_timezone'))->toBeString();
});

/*
|--------------------------------------------------------------------------
| the schema after migrating
|--------------------------------------------------------------------------
*/

test('the new tables exist', function () {
    foreach (['open_bills', 'open_bill_items', 'open_bill_events', 'numbering_locks'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});

test('open_bills has every column of the contract', function () {
    $columns = [
        'id', 'number', 'business_date', 'status', 'version', 'customer_name', 'table_id', 'member_id', 'note',
        'tax_percent', 'discount_price', 'kitchen_batch_count', 'last_kitchen_batch', 'pending_cancel',
        'kitchen_sent_at', 'created_by', 'paid_by', 'voided_by', 'paid_at', 'voided_at', 'void_reason',
        'selling_id', 'created_at', 'updated_at',
    ];

    expect(Schema::getColumnListing('open_bills'))->toEqualCanonicalizing($columns);
});

test('open_bill_items and open_bill_events have every column of the contract', function () {
    expect(Schema::getColumnListing('open_bill_items'))->toEqualCanonicalizing([
        'id', 'open_bill_id', 'product_id', 'user_id', 'qty', 'price', 'discount_price', 'price_unit_id', 'note',
        'sent_qty', 'sent_note', 'created_at', 'updated_at',
    ]);
    expect(Schema::getColumnListing('open_bill_events'))->toEqualCanonicalizing([
        'id', 'open_bill_id', 'user_id', 'action', 'product_id', 'product_name', 'qty_from', 'qty_to', 'reason',
        'detail', 'created_at',
    ]);
    expect(Schema::getColumnListing('numbering_locks'))->toBe(['name']);
});

test('the note columns exist and are nullable', function () {
    foreach (['cart_items', 'selling_details'] as $table) {
        $note = schemaColumn($table, 'note');
        expect($note)->not->toBeNull();
        expect($note['nullable'])->toBeTrue();
    }
});

test('required columns are NOT NULL and optional ones are nullable', function () {
    foreach (['number', 'business_date', 'status', 'version', 'tax_percent', 'discount_price', 'kitchen_batch_count'] as $column) {
        expect(schemaColumn('open_bills', $column)['nullable'])->toBeFalse();
    }
    foreach (['customer_name', 'table_id', 'member_id', 'note', 'last_kitchen_batch', 'pending_cancel', 'kitchen_sent_at', 'created_by', 'paid_by', 'voided_by', 'paid_at', 'voided_at', 'void_reason', 'selling_id'] as $column) {
        expect(schemaColumn('open_bills', $column)['nullable'])->toBeTrue();
    }
    foreach (['open_bill_id', 'product_id', 'qty', 'sent_qty'] as $column) {
        expect(schemaColumn('open_bill_items', $column)['nullable'])->toBeFalse();
    }
    foreach (['open_bill_id', 'action'] as $column) {
        expect(schemaColumn('open_bill_events', $column)['nullable'])->toBeFalse();
    }
});

test('the new indexes exist', function () {
    $expected = [
        'stocks' => ['stocks_pos_lookup_index'],
        'cart_items' => ['cart_items_user_product_index'],
        'products' => ['products_pos_listing_index', 'products_sku_index'],
        'open_bills' => ['open_bills_day_number_unique', 'open_bills_status_day_index', 'open_bills_selling_id_unique'],
        'open_bill_items' => ['open_bill_items_bill_product_unique'],
        'open_bill_events' => ['open_bill_events_bill_index'],
        'sellings' => ['sellings_date_number_index'],
    ];

    foreach ($expected as $table => $names) {
        foreach ($names as $name) {
            expect(Schema::hasIndex($table, $name))->toBeTrue();
        }
    }
});

test('the indexes have the contract columns and uniqueness', function () {
    $index = fn (string $table, string $name) => collect(Schema::getIndexes($table))->firstWhere('name', $name);

    expect($index('open_bills', 'open_bills_day_number_unique')['columns'])->toBe(['business_date', 'number']);
    expect($index('open_bills', 'open_bills_day_number_unique')['unique'])->toBeTrue();
    expect($index('open_bills', 'open_bills_status_day_index')['columns'])->toBe(['status', 'business_date']);
    expect($index('open_bills', 'open_bills_status_day_index')['unique'])->toBeFalse();
    expect($index('open_bills', 'open_bills_selling_id_unique')['columns'])->toBe(['selling_id']);
    expect($index('open_bills', 'open_bills_selling_id_unique')['unique'])->toBeTrue();
    expect($index('open_bill_items', 'open_bill_items_bill_product_unique')['columns'])->toBe(['open_bill_id', 'product_id']);
    expect($index('open_bill_items', 'open_bill_items_bill_product_unique')['unique'])->toBeTrue();
    expect($index('open_bill_events', 'open_bill_events_bill_index')['columns'])->toBe(['open_bill_id', 'id']);
    expect($index('sellings', 'sellings_date_number_index')['columns'])->toBe(['date', 'daily_order_number']);
    expect($index('stocks', 'stocks_pos_lookup_index')['columns'])->toBe(['product_id', 'is_ready', 'type', 'stock']);
    expect($index('cart_items', 'cart_items_user_product_index')['columns'])->toBe(['user_id', 'product_id']);
});

test('numbering_locks has a primary key on name', function () {
    $primary = collect(Schema::getIndexes('numbering_locks'))->firstWhere('primary', true);

    expect($primary)->not->toBeNull();
    expect($primary['columns'])->toBe(['name']);
});

test('every index and foreign key name is at most 64 characters (the MySQL limit)', function () {
    $tables = ['stocks', 'cart_items', 'products', 'sellings', 'open_bills', 'open_bill_items', 'open_bill_events', 'numbering_locks'];

    foreach ($tables as $table) {
        foreach (schemaIndexNames($table) as $name) {
            expect(strlen($name))->toBeLessThanOrEqual(64);
        }
    }

    // Foreign key names follow Laravel's {table}_{column}_foreign convention.
    foreach ([
        'open_bills' => ['table_id', 'member_id', 'created_by', 'paid_by', 'voided_by', 'selling_id'],
        'open_bill_items' => ['open_bill_id', 'product_id', 'user_id', 'price_unit_id'],
        'open_bill_events' => ['open_bill_id', 'user_id'],
    ] as $table => $columns) {
        foreach ($columns as $column) {
            expect(strlen("{$table}_{$column}_foreign"))->toBeLessThanOrEqual(64);
        }
    }
});

test('every index name written in the new migrations is at most 64 characters', function () {
    foreach (SCHEMA_RELEASE as $name) {
        preg_match_all("/'([a-z0-9_]+_(?:index|unique|foreign))'/", schemaMigrationSource($name), $matches);

        foreach ($matches[1] as $indexName) {
            expect(strlen($indexName))->toBeLessThanOrEqual(64);
        }
    }
});

test('the foreign keys exist where the contract wants them', function () {
    $expected = [
        'open_bills' => ['table_id', 'member_id', 'created_by', 'paid_by', 'voided_by', 'selling_id'],
        'open_bill_items' => ['open_bill_id', 'product_id', 'user_id', 'price_unit_id'],
        'open_bill_events' => ['open_bill_id', 'user_id'],
    ];

    foreach ($expected as $table => $columns) {
        $existing = collect(Schema::getForeignKeys($table))->flatMap(fn ($fk) => $fk['columns'])->all();

        expect(array_values(array_diff($columns, $existing)))->toBe([]);
    }

    $eventForeign = collect(Schema::getForeignKeys('open_bill_events'))->flatMap(fn ($fk) => $fk['columns'])->all();
    expect($eventForeign)->not->toContain('product_id');
});

test('defaults match the contract', function () {
    $id = DB::table('open_bills')->insertGetId(['number' => 1, 'business_date' => '2026-10-15']);
    $bill = DB::table('open_bills')->find($id);

    expect($bill->status)->toBe('open');
    expect((int) $bill->version)->toBe(1);
    expect((float) $bill->tax_percent)->toBe(0.0);
    expect((float) $bill->discount_price)->toBe(0.0);
    expect((int) $bill->kitchen_batch_count)->toBe(0);
    expect($bill->customer_name)->toBeNull();
    expect($bill->selling_id)->toBeNull();

    $product = Product::factory()->create(['name' => 'Default Item', 'stock' => 1]);
    $itemId = DB::table('open_bill_items')->insertGetId(['open_bill_id' => $id, 'product_id' => $product->id, 'qty' => 2]);
    $item = DB::table('open_bill_items')->find($itemId);
    expect((float) $item->sent_qty)->toBe(0.0);
    expect($item->price)->toBeNull();
    expect($item->sent_note)->toBeNull();
});

test('a day cannot have the same bill number twice, another day can', function () {
    DB::table('open_bills')->insert(['number' => 7, 'business_date' => '2026-10-15']);
    DB::table('open_bills')->insert(['number' => 7, 'business_date' => '2026-10-16']);

    expect(fn () => DB::table('open_bills')->insert(['number' => 7, 'business_date' => '2026-10-15']))
        ->toThrow(QueryException::class);
});

test('a product appears once per bill', function () {
    $billId = DB::table('open_bills')->insertGetId(['number' => 1, 'business_date' => '2026-10-15']);
    $otherBillId = DB::table('open_bills')->insertGetId(['number' => 2, 'business_date' => '2026-10-15']);
    $product = Product::factory()->create(['name' => 'Sekali', 'stock' => 1]);

    DB::table('open_bill_items')->insert(['open_bill_id' => $billId, 'product_id' => $product->id, 'qty' => 1]);
    DB::table('open_bill_items')->insert(['open_bill_id' => $otherBillId, 'product_id' => $product->id, 'qty' => 1]);

    expect(fn () => DB::table('open_bill_items')->insert(['open_bill_id' => $billId, 'product_id' => $product->id, 'qty' => 2]))
        ->toThrow(QueryException::class);
});

test('a sale can belong to only one bill, but many bills may have none', function () {
    DB::table('open_bills')->insert(['number' => 1, 'business_date' => '2026-10-15']);
    DB::table('open_bills')->insert(['number' => 2, 'business_date' => '2026-10-15']);

    expect(DB::table('open_bills')->whereNull('selling_id')->count())->toBe(2);

    $sellingId = DB::table('sellings')->insertGetId([
        'code' => 'SELLBILL1', 'total_price' => 1, 'payed_money' => 1, 'money_changes' => 0, 'total_qty' => 1,
        'date' => '2026-10-15 05:00:00', 'created_at' => '2026-10-15 05:00:00', 'updated_at' => '2026-10-15 05:00:00',
    ]);
    DB::table('open_bills')->where('number', 1)->update(['selling_id' => $sellingId]);

    expect(fn () => DB::table('open_bills')->where('number', 2)->update(['selling_id' => $sellingId]))
        ->toThrow(QueryException::class);
});

test('deleting a bill deletes its lines and its audit trail', function () {
    $billId = DB::table('open_bills')->insertGetId(['number' => 1, 'business_date' => '2026-10-15']);
    $product = Product::factory()->create(['name' => 'Cascade', 'stock' => 1]);
    DB::table('open_bill_items')->insert(['open_bill_id' => $billId, 'product_id' => $product->id, 'qty' => 1]);
    DB::table('open_bill_events')->insert(['open_bill_id' => $billId, 'action' => 'created']);

    DB::table('open_bills')->where('id', $billId)->delete();

    expect(DB::table('open_bill_items')->count())->toBe(0);
    expect(DB::table('open_bill_events')->count())->toBe(0);
});

test('a line cannot outlive its product through a plain delete (restrict, like cart_items)', function () {
    $billId = DB::table('open_bills')->insertGetId(['number' => 1, 'business_date' => '2026-10-15']);
    $product = Product::factory()->create(['name' => 'Restrict', 'stock' => 1]);
    DB::table('open_bill_items')->insert(['open_bill_id' => $billId, 'product_id' => $product->id, 'qty' => 1]);

    expect(fn () => DB::table('products')->where('id', $product->id)->delete())->toThrow(QueryException::class);
});

test('deleting a user keeps the bill and clears who made it', function () {
    $user = User::factory()->create();
    $billId = DB::table('open_bills')->insertGetId(['number' => 1, 'business_date' => '2026-10-15', 'created_by' => $user->id]);

    DB::table('users')->where('id', $user->id)->delete();

    expect(DB::table('open_bills')->find($billId)->created_by)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| idempotent: every migration can run again, also on a half finished schema
|--------------------------------------------------------------------------
*/

test('running every release migration a second time changes nothing and throws nothing', function () {
    $before = [];
    foreach (['stocks', 'cart_items', 'products', 'sellings', 'open_bills', 'open_bill_items', 'open_bill_events', 'numbering_locks'] as $table) {
        $before[$table] = [schemaIndexNames($table), Schema::getColumnListing($table)];
    }

    schemaRunAllUp();
    schemaRunAllUp();

    foreach ($before as $table => [$indexes, $columns]) {
        expect(schemaIndexNames($table))->toEqualCanonicalizing($indexes);
        expect(Schema::getColumnListing($table))->toBe($columns);
    }
});

test('each migration restores an index that a stopped run left out', function (string $migration, string $table, string $index) {
    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($index));
    expect(Schema::hasIndex($table, $index))->toBeFalse();

    schemaMigration($migration)->up();

    expect(Schema::hasIndex($table, $index))->toBeTrue();
})->with([
    'perf: stocks lookup' => ['2026_10_14_000001_add_pos_performance_indexes', 'stocks', 'stocks_pos_lookup_index'],
    'perf: cart user product' => ['2026_10_14_000001_add_pos_performance_indexes', 'cart_items', 'cart_items_user_product_index'],
    'perf: products listing' => ['2026_10_14_000001_add_pos_performance_indexes', 'products', 'products_pos_listing_index'],
    'perf: products sku' => ['2026_10_14_000001_add_pos_performance_indexes', 'products', 'products_sku_index'],
    'bills: day number unique' => ['2026_10_15_000002_create_open_bills_table', 'open_bills', 'open_bills_day_number_unique'],
    'bills: status day' => ['2026_10_15_000002_create_open_bills_table', 'open_bills', 'open_bills_status_day_index'],
    'bills: selling unique' => ['2026_10_15_000002_create_open_bills_table', 'open_bills', 'open_bills_selling_id_unique'],
    'items: bill product unique' => ['2026_10_15_000003_create_open_bill_items_table', 'open_bill_items', 'open_bill_items_bill_product_unique'],
    'events: bill' => ['2026_10_15_000004_create_open_bill_events_table', 'open_bill_events', 'open_bill_events_bill_index'],
    'sellings: date number' => ['2026_10_15_000006_add_date_index_to_sellings_table', 'sellings', 'sellings_date_number_index'],
]);

test('the note migration restores a missing note column and keeps existing notes', function () {
    $product = Product::factory()->create(['name' => 'Catatan', 'stock' => 1]);
    DB::table('cart_items')->insert(['user_id' => User::first()->id, 'product_id' => $product->id, 'qty' => 1, 'price' => 1000]);
    Schema::table('cart_items', fn (Blueprint $table) => $table->dropColumn('note'));
    Schema::table('selling_details', fn (Blueprint $table) => $table->dropColumn('note'));
    expect(Schema::hasColumn('cart_items', 'note'))->toBeFalse();

    $migration = schemaMigration('2026_10_15_000001_add_note_to_cart_items_and_selling_details_tables');
    $migration->up();
    expect(Schema::hasColumn('cart_items', 'note'))->toBeTrue();
    expect(Schema::hasColumn('selling_details', 'note'))->toBeTrue();
    expect(schemaColumn('cart_items', 'note')['nullable'])->toBeTrue();

    DB::table('cart_items')->update(['note' => 'tanpa gula']);
    $migration->up();
    expect(DB::table('cart_items')->value('note'))->toBe('tanpa gula');
});

test('the bill tables are recreated when they are missing and the migrations are then idempotent', function () {
    Schema::drop('open_bill_events');
    Schema::drop('open_bill_items');
    Schema::drop('open_bills');
    Schema::drop('numbering_locks');

    foreach (['2026_10_15_000002_create_open_bills_table', '2026_10_15_000003_create_open_bill_items_table', '2026_10_15_000004_create_open_bill_events_table', '2026_10_15_000005_create_numbering_locks_table'] as $name) {
        schemaMigration($name)->up();
    }

    foreach (['open_bills', 'open_bill_items', 'open_bill_events', 'numbering_locks'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
    expect(Schema::hasIndex('open_bills', 'open_bills_day_number_unique'))->toBeTrue();
    expect(Schema::hasIndex('open_bill_items', 'open_bill_items_bill_product_unique'))->toBeTrue();
    expect(Schema::hasIndex('open_bill_events', 'open_bill_events_bill_index'))->toBeTrue();

    schemaRunAllUp();
    expect(Schema::hasTable('open_bills'))->toBeTrue();
});

test('a table that exists without its indexes (a run that stopped after create) is completed', function () {
    Schema::drop('open_bill_events');
    Schema::drop('open_bill_items');
    Schema::table('open_bills', function (Blueprint $table) {
        $table->dropIndex('open_bills_day_number_unique');
        $table->dropIndex('open_bills_status_day_index');
        $table->dropIndex('open_bills_selling_id_unique');
    });

    schemaMigration('2026_10_15_000002_create_open_bills_table')->up();

    foreach (['open_bills_day_number_unique', 'open_bills_status_day_index', 'open_bills_selling_id_unique'] as $index) {
        expect(Schema::hasIndex('open_bills', $index))->toBeTrue();
    }
});

test('the create migrations never touch rows that already exist', function () {
    $billId = DB::table('open_bills')->insertGetId(['number' => 5, 'business_date' => '2026-10-15', 'customer_name' => 'Tetap']);
    DB::table('numbering_locks')->insert(['name' => 'sales']);

    schemaRunAllUp();

    expect(DB::table('open_bills')->find($billId)->customer_name)->toBe('Tetap');
    expect(DB::table('numbering_locks')->count())->toBe(1);
});

test('the numbering_locks migration does not insert the sales row (ensure() does)', function () {
    Schema::drop('numbering_locks');

    schemaMigration('2026_10_15_000005_create_numbering_locks_table')->up();

    expect(DB::table('numbering_locks')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| perf migration down()
|--------------------------------------------------------------------------
*/

test('the performance migration down() does not throw, also when run twice, and keeps the foreign key indexes', function () {
    $migration = schemaMigration('2026_10_14_000001_add_pos_performance_indexes');

    $migration->down();
    $migration->down();

    expect(Schema::hasIndex('stocks', 'stocks_pos_lookup_index'))->toBeFalse();
    expect(Schema::hasIndex('cart_items', 'cart_items_user_product_index'))->toBeFalse();
    expect(Schema::hasIndex('products', 'products_pos_listing_index'))->toBeFalse();
    expect(Schema::hasIndex('products', 'products_sku_index'))->toBeFalse();
    // A plain index on the foreign key column takes over before the composite is dropped (MySQL error 1553).
    expect(Schema::hasIndex('stocks', 'stocks_product_id_index'))->toBeTrue();
    expect(Schema::hasIndex('cart_items', 'cart_items_user_id_index'))->toBeTrue();
});

test('after down() the performance migration up() works again', function () {
    $migration = schemaMigration('2026_10_14_000001_add_pos_performance_indexes');

    $migration->down();
    $migration->up();

    expect(Schema::hasIndex('stocks', 'stocks_pos_lookup_index'))->toBeTrue();
    expect(Schema::hasIndex('cart_items', 'cart_items_user_product_index'))->toBeTrue();
    expect(Schema::hasIndex('products', 'products_pos_listing_index'))->toBeTrue();
    expect(Schema::hasIndex('products', 'products_sku_index'))->toBeTrue();
});

test('down() on a schema where the composite index was never created does not throw', function () {
    $migration = schemaMigration('2026_10_14_000001_add_pos_performance_indexes');
    $migration->down();
    Schema::table('stocks', fn (Blueprint $table) => $table->dropIndex('stocks_product_id_index'));

    expect(fn () => $migration->down())->not->toThrow(Throwable::class);
});

test('the create migrations down() is documented as development only', function () {
    foreach (['2026_10_15_000002_create_open_bills_table', '2026_10_15_000003_create_open_bill_items_table', '2026_10_15_000004_create_open_bill_events_table', '2026_10_15_000005_create_numbering_locks_table'] as $name) {
        expect(schemaMigrationSource($name))->toMatch('/Development only|dev saja/i');
    }
});
