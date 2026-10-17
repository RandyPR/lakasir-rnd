<?php

use App\Support\BusinessDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config(['pos.business_timezone' => 'Asia/Jakarta']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| timezone() and today()
|--------------------------------------------------------------------------
*/

test('the default business timezone is Asia/Jakarta and the app timezone stays UTC', function () {
    expect(BusinessDay::timezone())->toBe('Asia/Jakarta');
    expect(config('app.timezone'))->toBe('UTC');
});

test('a configured valid timezone is used', function () {
    config(['pos.business_timezone' => 'Asia/Makassar']);

    expect(BusinessDay::timezone())->toBe('Asia/Makassar');
});

test('an unknown, empty or non string timezone falls back to Asia/Jakarta', function (mixed $configured) {
    config(['pos.business_timezone' => $configured]);

    expect(BusinessDay::timezone())->toBe('Asia/Jakarta');
})->with([
    'unknown identifier' => ['Mars/Olympus_Mons'],
    'empty string' => [''],
    'null' => [null],
    'array' => [['Asia/Jakarta']],
    'number' => [7],
]);

test('today flips to the next business date at 17:00:00 UTC for Asia/Jakarta', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 16:59:59', 'UTC'));
    expect(BusinessDay::today())->toBe('2026-10-15');

    Carbon::setTestNow(Carbon::parse('2026-10-15 17:00:00', 'UTC'));
    expect(BusinessDay::today())->toBe('2026-10-16');

    Carbon::setTestNow(Carbon::parse('2026-10-15 00:00:00', 'UTC'));
    expect(BusinessDay::today())->toBe('2026-10-15');
});

test('a business timezone of Asia/Makassar moves the boundary to 16:00 UTC', function () {
    config(['pos.business_timezone' => 'Asia/Makassar']);

    Carbon::setTestNow(Carbon::parse('2026-10-15 15:59:59', 'UTC'));
    expect(BusinessDay::today())->toBe('2026-10-15');

    Carbon::setTestNow(Carbon::parse('2026-10-15 16:00:00', 'UTC'));
    expect(BusinessDay::today())->toBe('2026-10-16');
});

test('today accepts an explicit moment in any timezone', function () {
    $moment = Carbon::parse('2026-10-15 23:30:00', 'Asia/Jakarta');

    expect(BusinessDay::today($moment))->toBe('2026-10-15');
    expect(BusinessDay::today($moment->copy()->setTimezone('UTC')))->toBe('2026-10-15');
    expect(BusinessDay::today($moment->copy()->addMinutes(30)))->toBe('2026-10-16');
});

/*
|--------------------------------------------------------------------------
| normalize() and dateOf()
|--------------------------------------------------------------------------
*/

test('normalize converts a Carbon to UTC and drops microseconds', function () {
    $moment = Carbon::parse('2026-10-15 10:00:00.987654', 'Asia/Jakarta');

    expect(BusinessDay::normalize($moment))->toBe('2026-10-15 03:00:00');
});

test('normalize accepts any DateTimeInterface', function () {
    $moment = new DateTimeImmutable('2026-10-15 10:00:00', new DateTimeZone('Asia/Jakarta'));

    expect(BusinessDay::normalize($moment))->toBe('2026-10-15 03:00:00');
});

test('normalize honours a Z suffix and a +07:00 offset, then converts to UTC', function () {
    expect(BusinessDay::normalize('2026-10-15T10:00:00Z'))->toBe('2026-10-15 10:00:00');
    expect(BusinessDay::normalize('2026-10-15T10:00:00+07:00'))->toBe('2026-10-15 03:00:00');
    expect(BusinessDay::normalize('2026-10-15 10:00:00+07:00'))->toBe('2026-10-15 03:00:00');
    expect(BusinessDay::normalize('2026-10-15T01:00:00+07:00'))->toBe('2026-10-14 18:00:00');
    expect(BusinessDay::normalize('2026-10-15T10:00:00-05:00'))->toBe('2026-10-15 15:00:00');
});

test('normalize reads a string without a zone as UTC', function () {
    expect(BusinessDay::normalize('2026-10-15 10:00:00'))->toBe('2026-10-15 10:00:00');
    expect(BusinessDay::normalize('2026-10-15T10:00:00'))->toBe('2026-10-15 10:00:00');
    expect(BusinessDay::normalize('2026-10-15 10:00'))->toBe('2026-10-15 10:00:00');
});

test('normalize reads a bare date as 00:00:00 UTC', function () {
    expect(BusinessDay::normalize('2026-10-15'))->toBe('2026-10-15 00:00:00');
});

test('normalize drops microseconds from strings', function () {
    expect(BusinessDay::normalize('2026-10-15T10:00:00.123456Z'))->toBe('2026-10-15 10:00:00');
    expect(BusinessDay::normalize('2026-10-15 10:00:00.999'))->toBe('2026-10-15 10:00:00');
});

test('normalize trims whitespace around the value', function () {
    expect(BusinessDay::normalize("  2026-10-15 10:00:00 \n"))->toBe('2026-10-15 10:00:00');
});

test('normalize turns null, empty and unreadable input into now', function (mixed $input) {
    Carbon::setTestNow(Carbon::parse('2026-10-15 08:30:15.654321', 'UTC'));

    expect(BusinessDay::normalize($input))->toBe('2026-10-15 08:30:15');
})->with([
    'null' => [null],
    'empty string' => [''],
    'blank string' => ['   '],
    'garbage' => ['garbage'],
    'relative word' => ['tomorrow'],
    'relative phrase' => ['next friday'],
    'impossible month' => ['2026-13-45'],
]);

test('normalize of now uses UTC even when the business day differs', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 20:00:00', 'UTC'));

    expect(BusinessDay::normalize(null))->toBe('2026-10-15 20:00:00');
    expect(BusinessDay::dateOf(null))->toBe('2026-10-16');
});

test('dateOf gives the business date of a moment', function () {
    expect(BusinessDay::dateOf('2026-10-15 16:59:59'))->toBe('2026-10-15');
    expect(BusinessDay::dateOf('2026-10-15 17:00:00'))->toBe('2026-10-16');
    expect(BusinessDay::dateOf('2026-10-15T23:59:59+07:00'))->toBe('2026-10-15');
    expect(BusinessDay::dateOf('2026-10-16T00:00:00+07:00'))->toBe('2026-10-16');
    expect(BusinessDay::dateOf('2026-10-15T16:59:59Z'))->toBe('2026-10-15');
    expect(BusinessDay::dateOf('2026-10-15T17:00:00Z'))->toBe('2026-10-16');
});

test('dateOf of a bare date is that same date (00:00 UTC is 07:00 in Jakarta)', function () {
    expect(BusinessDay::dateOf('2026-10-15'))->toBe('2026-10-15');
});

test('dateOf follows the configured timezone', function () {
    config(['pos.business_timezone' => 'Asia/Makassar']);

    expect(BusinessDay::dateOf('2026-10-15 15:59:59'))->toBe('2026-10-15');
    expect(BusinessDay::dateOf('2026-10-15 16:00:00'))->toBe('2026-10-16');
});

/*
|--------------------------------------------------------------------------
| startUtc / endUtc / range
|--------------------------------------------------------------------------
*/

test('start and end of a business date are UTC instants', function () {
    expect(BusinessDay::startUtc('2026-10-15')->format('Y-m-d H:i:s'))->toBe('2026-10-14 17:00:00');
    expect(BusinessDay::endUtc('2026-10-15')->format('Y-m-d H:i:s'))->toBe('2026-10-15 17:00:00');
    expect(BusinessDay::startUtc('2026-10-15')->timezoneName)->toBe('UTC');
    expect(BusinessDay::endUtc('2026-10-15')->timezoneName)->toBe('UTC');
});

test('start and end follow Asia/Makassar', function () {
    config(['pos.business_timezone' => 'Asia/Makassar']);

    expect(BusinessDay::startUtc('2026-10-15')->format('Y-m-d H:i:s'))->toBe('2026-10-14 16:00:00');
    expect(BusinessDay::endUtc('2026-10-15')->format('Y-m-d H:i:s'))->toBe('2026-10-15 16:00:00');
});

test('range returns start and end and defaults to today', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 20:00:00', 'UTC')); // already 16 Oct in Jakarta

    [$start, $end] = BusinessDay::range();
    expect($start->format('Y-m-d H:i:s'))->toBe('2026-10-15 17:00:00');
    expect($end->format('Y-m-d H:i:s'))->toBe('2026-10-16 17:00:00');

    [$start, $end] = BusinessDay::range('2026-10-01');
    expect($start->format('Y-m-d H:i:s'))->toBe('2026-09-30 17:00:00');
    expect($end->format('Y-m-d H:i:s'))->toBe('2026-10-01 17:00:00');
});

/*
|--------------------------------------------------------------------------
| scopeDay() on SQLite
|--------------------------------------------------------------------------
*/

test('scopeDay keeps rows inside [start, end) and binds UTC datetime strings', function () {
    config(['database.connections.business_day_test' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]]);
    DB::purge('business_day_test');
    $connection = DB::connection('business_day_test');

    Schema::connection('business_day_test')->create('moments', function ($table) {
        $table->id();
        $table->string('label');
        $table->dateTime('at')->nullable();
    });

    $rows = [
        ['label' => 'previous day, last second', 'at' => '2026-10-14 16:59:59'],
        ['label' => 'first second', 'at' => '2026-10-14 17:00:00'],
        ['label' => 'noon', 'at' => '2026-10-15 05:00:00'],
        ['label' => 'last second', 'at' => '2026-10-15 16:59:59'],
        ['label' => 'next day, first second', 'at' => '2026-10-15 17:00:00'],
        ['label' => 'no moment', 'at' => null],
    ];
    $connection->table('moments')->insert($rows);

    $query = BusinessDay::scopeDay($connection->table('moments'), 'at', '2026-10-15');

    expect($query->orderBy('id')->pluck('label')->all())->toBe(['first second', 'noon', 'last second']);

    $bindings = BusinessDay::scopeDay($connection->table('moments'), 'at', '2026-10-15')->getBindings();
    expect($bindings)->toBe(['2026-10-14 17:00:00', '2026-10-15 17:00:00']);

    // The column is never wrapped in a function, so an index on it stays usable.
    $sql = BusinessDay::scopeDay($connection->table('moments'), 'at', '2026-10-15')->toSql();
    expect($sql)->toBe('select * from "moments" where "at" >= ? and "at" < ?');

    DB::purge('business_day_test');
});

test('scopeDay follows Asia/Makassar', function () {
    config(['pos.business_timezone' => 'Asia/Makassar']);

    $bindings = BusinessDay::scopeDay(DB::table('sellings'), 'date', '2026-10-15')->getBindings();

    expect($bindings)->toBe(['2026-10-14 16:00:00', '2026-10-15 16:00:00']);
});

/*
|--------------------------------------------------------------------------
| label()
|--------------------------------------------------------------------------
*/

test('label is null for today, KEMARIN for yesterday and d/m otherwise', function () {
    expect(BusinessDay::label('2026-10-16', '2026-10-16'))->toBeNull();
    expect(BusinessDay::label('2026-10-15', '2026-10-16'))->toBe('KEMARIN');
    expect(BusinessDay::label('2026-10-14', '2026-10-16'))->toBe('14/10');
    expect(BusinessDay::label('2026-01-05', '2026-10-16'))->toBe('05/01');
    // A date in the future is not "yesterday".
    expect(BusinessDay::label('2026-10-17', '2026-10-16'))->toBe('17/10');
});

test('label handles month and year boundaries for yesterday', function () {
    expect(BusinessDay::label('2026-09-30', '2026-10-01'))->toBe('KEMARIN');
    expect(BusinessDay::label('2025-12-31', '2026-01-01'))->toBe('KEMARIN');
});

test('label defaults today to the current business date', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 20:00:00', 'UTC')); // 16 Oct in Jakarta

    expect(BusinessDay::label('2026-10-16'))->toBeNull();
    expect(BusinessDay::label('2026-10-15'))->toBe('KEMARIN');
});
