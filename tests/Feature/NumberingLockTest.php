<?php

use App\Models\Tenants\Product;
use App\Models\Tenants\Setting;
use App\Models\Tenants\User;
use App\Services\Tenants\NumberingLock;
use App\Services\Tenants\SellingService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;

uses(RefreshDatabaseWithTenant::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 05:00:00', 'UTC'));
    Setting::set('selling_method', 'fifo');
    Cache::clear();
    NumberingLock::flush();
});

afterEach(function () {
    Carbon::setTestNow();
    NumberingLock::flush();
});

/** @return array<int, string> the sql of every query run by $run */
function lockQueries(Closure $run): array
{
    $sql = [];
    $listener = function (QueryExecuted $query) use (&$sql) {
        $sql[] = normalizeSql($query->sql);
    };
    Event::listen(QueryExecuted::class, $listener);
    $run();
    Event::forget(QueryExecuted::class);

    return $sql;
}

function lockRowCount(): int
{
    return DB::table('numbering_locks')->where('name', 'sales')->count();
}

/** A QueryException as the MySQL driver raises it: errorInfo = [sqlstate, driver code, message]. */
function lockQueryException(string $sqlState, int $driverCode, string $message): QueryException
{
    $previous = new class($message) extends PDOException
    {
        public function __construct(string $message)
        {
            parent::__construct($message);
        }
    };
    $previous->errorInfo = [$sqlState, $driverCode, $message];
    (new ReflectionProperty(Exception::class, 'code'))->setValue($previous, $sqlState);

    return new QueryException('mysql', 'select 1', [], $previous);
}

/*
|--------------------------------------------------------------------------
| ensure()
|--------------------------------------------------------------------------
*/

test('ensure creates the sales row when it is missing', function () {
    DB::table('numbering_locks')->delete();
    expect(lockRowCount())->toBe(0);

    NumberingLock::ensure();

    expect(lockRowCount())->toBe(1);
});

test('ensure is idempotent: calling it again never adds a second row', function () {
    NumberingLock::ensure();
    NumberingLock::ensure();
    NumberingLock::flush();
    NumberingLock::ensure();

    expect(DB::table('numbering_locks')->count())->toBe(1);
});

test('ensure does not write when the row already exists', function () {
    DB::table('numbering_locks')->insert(['name' => 'sales']);

    $sql = lockQueries(fn () => NumberingLock::ensure());

    expect($sql)->not->toBe([]);
    foreach ($sql as $statement) {
        expect($statement)->toStartWith('select');
    }
});

test('after a positive answer ensure does not query at all', function () {
    NumberingLock::ensure();

    $sql = lockQueries(function () {
        NumberingLock::ensure();
        NumberingLock::ensure();
    });

    expect($sql)->toBe([]);
});

test('ensure only inserts when the row is really missing', function () {
    DB::table('numbering_locks')->delete();

    $sql = lockQueries(fn () => NumberingLock::ensure());

    expect(collect($sql)->filter(fn ($s) => str_starts_with($s, 'insert'))->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| acquire()
|--------------------------------------------------------------------------
*/

test('acquire reads the sales row inside the transaction', function () {
    NumberingLock::ensure();

    DB::beginTransaction();
    $sql = lockQueries(fn () => NumberingLock::acquire());
    DB::rollBack();

    expect($sql)->toHaveCount(1);
    expect($sql[0])->toContain('numbering_locks');
    expect($sql[0])->toStartWith('select');
});

test('acquire recreates a sales row somebody deleted', function () {
    DB::table('numbering_locks')->delete();

    DB::beginTransaction();
    NumberingLock::acquire();
    DB::commit();

    expect(lockRowCount())->toBe(1);
});

test('acquire does not add a row when it exists', function () {
    DB::table('numbering_locks')->insert(['name' => 'sales']);

    DB::beginTransaction();
    NumberingLock::acquire();
    NumberingLock::acquire();
    DB::commit();

    expect(DB::table('numbering_locks')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Without the table (code deployed before the migration)
|--------------------------------------------------------------------------
*/

test('without the numbering_locks table ensure and acquire are silent no-ops and warn only once', function () {
    Schema::drop('numbering_locks');
    NumberingLock::flush();

    Log::shouldReceive('warning')->once();

    NumberingLock::ensure();
    NumberingLock::ensure();
    DB::beginTransaction();
    NumberingLock::acquire();
    NumberingLock::acquire();
    DB::commit();

    expect(NumberingLock::available())->toBeFalse();
});

test('a sale still works without the numbering_locks table', function () {
    actingAs(User::first());
    $product = Product::factory()->create(['name' => 'Tanpa Kunci', 'initial_price' => 10000, 'selling_price' => 25000, 'stock' => 5]);
    Schema::drop('numbering_locks');
    NumberingLock::flush();
    Log::shouldReceive('warning')->atLeast()->once();

    $service = new SellingService;
    $data = ['payed_money' => 25000, 'friend_price' => false, 'products' => [['product_id' => $product->id, 'qty' => 1]]];
    $sale = $service->create(array_merge($data, $service->mapProductRequest($data)));

    expect($sale->daily_order_number)->toBe(1);
    expect($sale->sellingDetails)->toHaveCount(1);
});

test('available remembers only a positive answer', function () {
    Schema::drop('numbering_locks');
    NumberingLock::flush();
    expect(NumberingLock::available())->toBeFalse();

    Schema::create('numbering_locks', function ($table) {
        $table->string('name', 32)->primary();
    });
    expect(NumberingLock::available())->toBeTrue();

    Schema::drop('numbering_locks');
    expect(NumberingLock::available())->toBeTrue(); // remembered for this process
    NumberingLock::flush();
    expect(NumberingLock::available())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| isContention()
|--------------------------------------------------------------------------
*/

test('a deadlock, a lock wait timeout and a serialization failure are contention', function () {
    expect(NumberingLock::isContention(lockQueryException('40001', 1213, 'Deadlock found when trying to get lock')))->toBeTrue();
    expect(NumberingLock::isContention(lockQueryException('HY000', 1205, 'Lock wait timeout exceeded')))->toBeTrue();
    expect(NumberingLock::isContention(lockQueryException('40001', 0, 'could not serialize access')))->toBeTrue();
});

test('other database errors and other exceptions are not contention', function () {
    expect(NumberingLock::isContention(lockQueryException('23000', 1062, 'Duplicate entry')))->toBeFalse();
    expect(NumberingLock::isContention(lockQueryException('42S02', 1146, 'Table doesn\'t exist')))->toBeFalse();
    expect(NumberingLock::isContention(new RuntimeException('Deadlock found')))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Static checks of the lock order (plan-v3 14.0 R1)
|--------------------------------------------------------------------------
*/

/**
 * PHP source with whitespace and comments removed, so statements can be compared as plain text.
 */
function lockCompactSource(string $path): string
{
    $out = '';
    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= $token[1].' ';

            continue;
        }
        $out .= $token;
    }

    return preg_replace('/\s+/', '', $out);
}

/**
 * @return array<string, string> "file::method" => source text of every named method / function under app/
 */
function lockMethodBodies(): array
{
    $bodies = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $tokens = token_get_all((string) file_get_contents($file->getPathname()));
        $count = count($tokens);
        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            // Named function: the next significant token is the name (a closure has '(' or '&(' instead).
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j < $count && $tokens[$j] === '&') {
                $j++;
                while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
            }
            if ($j >= $count || ! is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
                continue;
            }
            $name = $tokens[$j][1];

            // Skip the parameter list and the return type up to the body '{' (or ';' for abstract methods).
            $parens = 0;
            for (; $j < $count; $j++) {
                if ($tokens[$j] === '(') {
                    $parens++;
                } elseif ($tokens[$j] === ')') {
                    $parens--;
                } elseif ($parens === 0 && ($tokens[$j] === '{' || $tokens[$j] === ';')) {
                    break;
                }
            }
            if ($j >= $count || $tokens[$j] === ';') {
                continue;
            }

            $depth = 0;
            $body = '';
            for (; $j < $count; $j++) {
                $token = $tokens[$j];
                $isComment = is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true);
                $text = $isComment ? '' : (is_array($token) ? $token[1] : $token);
                if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;
                }
                $body .= $text;
                if ($depth === 0) {
                    break;
                }
            }

            $bodies[$relative.'::'.$name.'#'.$i] = $body;
        }
    }

    return $bodies;
}

test('SellingService does not use Cache::lock any more', function () {
    $source = (string) file_get_contents(app_path('Services/Tenants/SellingService.php'));

    expect($source)->not->toContain('Cache::lock');
    expect($source)->not->toContain('selling-numbering');
    expect($source)->not->toContain('LockTimeoutException');
});

test('no code under app/ takes a cache lock for numbering', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'selling-numbering')) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});

test('in SellingService::create the sales lock is the first statement after beginTransaction', function () {
    $source = lockCompactSource(app_path('Services/Tenants/SellingService.php'));

    // ensure() sits outside the transaction, right before the retry loop.
    expect($source)->toContain('NumberingLock::ensure();');
    expect(strpos($source, 'NumberingLock::ensure();'))->toBeLessThan(strpos($source, 'DB::beginTransaction();'));
    // acquire() is the first statement inside the try that follows beginTransaction.
    expect($source)->toContain('DB::beginTransaction();try{NumberingLock::acquire();');
    expect(substr_count($source, 'DB::beginTransaction();'))->toBe(1);
    expect(substr_count($source, 'NumberingLock::acquire();'))->toBe(1);
});

test('hooks and the stock check run after the sales lock', function () {
    $source = lockCompactSource(app_path('Services/Tenants/SellingService.php'));

    $acquire = strpos($source, 'NumberingLock::acquire();');
    expect(strpos($source, "\$hooks['before']"))->toBeGreaterThan($acquire);
    expect(strpos($source, 'StockGuard::assert('))->toBeGreaterThan($acquire);
    expect(strpos($source, 'SellingCreated::dispatch'))->toBeGreaterThan($acquire);
});

/**
 * Methods that lock an open_bills row textually BEFORE they take the sales lock (a lock-order inversion).
 *
 * @param  array<string, string>  $bodies  "where" => comment-free method source
 * @return array<int, string>
 */
function lockOrderOffenders(array $bodies): array
{
    $lockOnBills = '/(OpenBill::[^;]*lockForUpdate|table\(\s*[\'"]open_bills[\'"]\s*\)[^;]*lockForUpdate|open_bills[^;]*for update)/is';
    $offenders = [];

    foreach ($bodies as $where => $body) {
        $acquire = strpos($body, 'NumberingLock::acquire');
        if ($acquire === false || ! preg_match($lockOnBills, $body, $match, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        if ($match[0][1] < $acquire) {
            $offenders[] = $where;
        }
    }

    return $offenders;
}

test('the lock order check recognises an inversion and accepts the right order', function () {
    $inverted = [
        'a::eloquent' => '{ DB::beginTransaction(); $bill = OpenBill::query()->whereKey($id)->lockForUpdate()->first(); NumberingLock::acquire(); }',
        'a::builder' => "{ DB::table('open_bills')->where('id', 1)->lockForUpdate()->first(); NumberingLock::acquire(); }",
    ];
    $correct = [
        'b::ok' => '{ DB::beginTransaction(); NumberingLock::acquire(); $bill = OpenBill::query()->whereKey($id)->lockForUpdate()->first(); }',
        'b::line edit' => '{ DB::beginTransaction(); $bill = OpenBill::query()->whereKey($id)->lockForUpdate()->first(); }',
        'b::sales only' => '{ DB::beginTransaction(); NumberingLock::acquire(); }',
    ];

    expect(lockOrderOffenders($inverted))->toBe(['a::eloquent', 'a::builder']);
    expect(lockOrderOffenders($correct))->toBe([]);
});

test('no method locks open_bills before it takes the sales lock', function () {
    expect(lockOrderOffenders(lockMethodBodies()))->toBe([]);
});

test('every method that calls ensure() does so before it begins a transaction', function () {
    $offenders = [];

    foreach (lockMethodBodies() as $where => $body) {
        $ensure = strpos($body, 'NumberingLock::ensure');
        if ($ensure === false) {
            continue;
        }

        $begin = null;
        foreach (['DB::beginTransaction', 'DB::transaction(', '->transaction('] as $needle) {
            $position = strpos($body, $needle);
            if ($position !== false && ($begin === null || $position < $begin)) {
                $begin = $position;
            }
        }

        if ($begin !== null && $begin < $ensure) {
            $offenders[] = $where;
        }
    }

    expect($offenders)->toBe([]);
});

test('only SellingService, Selling and the bill code take the sales lock', function () {
    $callers = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php' || $file->getFilename() === 'NumberingLock.php') {
            continue;
        }
        if (str_contains(lockCompactSource($file->getPathname()), 'NumberingLock::acquire(')) {
            $callers[] = $file->getFilename();
        }
    }
    sort($callers);

    $allowed = ['OpenBillService.php', 'Selling.php', 'SellingService.php'];
    expect(array_diff($callers, $allowed))->toBe([]);
    expect($callers)->toContain('SellingService.php');
});

/*
|--------------------------------------------------------------------------
| Runtime order: the sales lock is the first query of the sale transaction
|--------------------------------------------------------------------------
*/

test('a sale transaction starts with the sales lock query', function () {
    actingAs(User::first());
    $product = Product::factory()->create(['name' => 'Urutan Kunci', 'initial_price' => 10000, 'selling_price' => 25000, 'stock' => 5]);
    $service = new SellingService;
    $data = ['payed_money' => 25000, 'friend_price' => false, 'products' => [['product_id' => $product->id, 'qty' => 1]]];
    $data = array_merge($data, $service->mapProductRequest($data));
    NumberingLock::ensure();

    $events = [];
    Event::listen(TransactionBeginning::class, function () use (&$events) {
        $events[] = 'begin';
    });
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$events) {
        $events[] = strtolower($query->sql);
    });

    $service->create($data);
    Event::forget(TransactionBeginning::class);
    Event::forget(QueryExecuted::class);

    $begin = array_search('begin', $events, true);
    expect($begin)->not->toBeFalse();
    expect($events[$begin + 1])->toContain('numbering_locks');
    // Nothing reads or writes anything before the transaction begins.
    expect(array_slice($events, 0, $begin))->toBe([]);
});

test('numbering reads happen after the lock: the daily number query comes after the lock query', function () {
    actingAs(User::first());
    $product = Product::factory()->create(['name' => 'Urutan Nomor', 'initial_price' => 10000, 'selling_price' => 25000, 'stock' => 5]);
    $service = new SellingService;
    $data = ['payed_money' => 25000, 'friend_price' => false, 'products' => [['product_id' => $product->id, 'qty' => 1]]];
    $data = array_merge($data, $service->mapProductRequest($data));
    NumberingLock::ensure();

    $sql = lockQueries(fn () => $service->create($data));

    $lock = collect($sql)->search(fn ($s) => str_contains($s, 'numbering_locks'));
    $number = collect($sql)->search(fn ($s) => str_contains($s, 'max("daily_order_number")'));
    expect($lock)->toBe(0);
    expect($number)->toBeGreaterThan($lock);
});

/*
|--------------------------------------------------------------------------
| The bill service follows the same lock order
|--------------------------------------------------------------------------
*/

test('OpenBillService takes the sales lock first inside its transaction helper', function () {
    $source = lockCompactSource(app_path('Services/Tenants/OpenBillService.php'));

    expect($source)->toContain('if($salesLock){NumberingLock::ensure();}');
    expect(strpos($source, 'NumberingLock::ensure();'))->toBeLessThan(strpos($source, 'DB::beginTransaction();'));
    expect($source)->toContain('DB::beginTransaction();try{if($salesLock){NumberingLock::acquire();}');
    expect(substr_count($source, 'NumberingLock::acquire();'))->toBe(1);
})->skip(! file_exists(__DIR__.'/../../app/Services/Tenants/OpenBillService.php'), 'OpenBillService is not written yet');

test('creating a bill and renaming a bill ask for the sales lock; plain edits do not', function () {
    $source = lockCompactSource(app_path('Services/Tenants/OpenBillService.php'));

    // createFromCart numbers the bill and its name: salesLock: true.
    expect($source)->toContain('},salesLock:true,retryDuplicate:true);');
    // updateHeader asks for the lock only when the customer name changes.
    expect($source)->toContain("['sales_lock'=>\$renaming]");
    // mutate() passes it on only when the caller asked for it.
    expect($source)->toContain("salesLock:(bool)(\$opts['sales_lock']??false)");
})->skip(! file_exists(__DIR__.'/../../app/Services/Tenants/OpenBillService.php'), 'OpenBillService is not written yet');
