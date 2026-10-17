<?php

use App\Models\Tenants\OpenBill;
use App\Models\Tenants\Product;
use App\Models\Tenants\Selling;
use App\Models\Tenants\Setting;
use App\Models\Tenants\User;
use App\Services\Tenants\CustomerNameService;
use App\Services\Tenants\DailyNumber;
use App\Services\Tenants\NumberingLock;
use App\Services\Tenants\SellingService;
use App\Support\BusinessDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\RefreshDatabaseWithTenant;

use function Pest\Laravel\actingAs;

uses(RefreshDatabaseWithTenant::class);

/*
 * Business day: Asia/Jakarta, so 2026-10-15 runs from 2026-10-14 17:00:00 UTC (inclusive) to
 * 2026-10-15 17:00:00 UTC (exclusive). Dates below are UTC unless they carry an offset.
 */

beforeEach(function () {
    config(['pos.business_timezone' => 'Asia/Jakarta']);
    Carbon::setTestNow(Carbon::parse('2026-10-15 05:00:00', 'UTC'));
    Setting::set('selling_method', 'fifo');
    Cache::clear();
    NumberingLock::flush();
    actingAs(User::first());
});

afterEach(function () {
    Carbon::setTestNow();
    NumberingLock::flush();
});

/**
 * A minimal sale created straight through the model (the fallback numbering path of Selling::creating).
 */
function numberedSale(string $date, array $extra = []): Selling
{
    return Selling::create(array_merge([
        'total_price' => 25000,
        'payed_money' => 25000,
        'money_changes' => 0,
        'total_qty' => 1,
        'date' => $date,
    ], $extra));
}

/**
 * An open bill row of a business day (status open unless told otherwise).
 */
function numberedBill(string $businessDate, int $number, array $extra = []): OpenBill
{
    return OpenBill::create(array_merge([
        'number' => $number,
        'business_date' => $businessDate,
        'status' => 'open',
        'customer_name' => null,
    ], $extra));
}

/*
|--------------------------------------------------------------------------
| The sequence resets at local midnight, not at UTC midnight
|--------------------------------------------------------------------------
*/

test('two sales before the boundary are 1 and 2', function () {
    $first = numberedSale('2026-10-15 05:00:00');
    $second = numberedSale('2026-10-15 16:59:58');

    expect($first->daily_order_number)->toBe(1);
    expect($second->daily_order_number)->toBe(2);
});

test('a sale right after the boundary starts a new day at 1', function () {
    numberedSale('2026-10-15 05:00:00');
    numberedSale('2026-10-15 16:59:59');

    $after = numberedSale('2026-10-15 17:00:00');

    expect($after->daily_order_number)->toBe(1);
});

test('the day before keeps counting after the next day has started', function () {
    numberedSale('2026-10-15 16:00:00');
    numberedSale('2026-10-15 17:30:00');
    $late = numberedSale('2026-10-15 16:59:59');

    expect($late->daily_order_number)->toBe(2);
    expect(numberedSale('2026-10-15 20:00:00')->daily_order_number)->toBe(2);
});

test('the first second of the day is the first sale of that day', function () {
    numberedSale('2026-10-14 16:59:59'); // 23:59:59 on the 14th in Jakarta

    expect(numberedSale('2026-10-14 17:00:00')->daily_order_number)->toBe(1); // 00:00:00 on the 15th
});

test('a Makassar shop moves the boundary to 16:00 UTC', function () {
    config(['pos.business_timezone' => 'Asia/Makassar']);

    numberedSale('2026-10-15 15:59:59');

    expect(numberedSale('2026-10-15 16:00:00')->daily_order_number)->toBe(1);
    expect(numberedSale('2026-10-15 16:30:00')->daily_order_number)->toBe(2);
});

test('a sale dated with a bare date belongs to that business date', function () {
    $a = numberedSale('2026-10-15');
    $b = numberedSale('2026-10-15');
    $c = numberedSale('2026-10-16');

    expect([$a->daily_order_number, $b->daily_order_number, $c->daily_order_number])->toBe([1, 2, 1]);
});

test('the old fixtures still work: a sale dated today by date only gets 1, 2, 3', function () {
    $numbers = [];
    foreach (range(1, 3) as $ignored) {
        $numbers[] = numberedSale(now()->toDateString())->daily_order_number;
    }

    expect($numbers)->toBe([1, 2, 3]);
});

test('a sale without any date is dated now and numbered in the current business day', function () {
    $sale = Selling::create(['total_price' => 1000, 'payed_money' => 1000, 'money_changes' => 0, 'total_qty' => 1]);

    expect($sale->daily_order_number)->toBe(1);
    expect($sale->fresh()->date)->not->toBeNull();
    expect(BusinessDay::dateOf((string) $sale->fresh()->date))->toBe('2026-10-15');
});

test('an explicit daily_order_number is kept and the next sale continues after it', function () {
    $manual = numberedSale('2026-10-15 05:00:00', ['daily_order_number' => 77]);
    $next = numberedSale('2026-10-15 06:00:00');

    expect($manual->daily_order_number)->toBe(77);
    expect($next->daily_order_number)->toBe(78);
});

test('sales are numbered without gaps and without repeats', function () {
    $numbers = [];
    foreach (range(1, 12) as $i) {
        $numbers[] = numberedSale('2026-10-15 05:00:00')->daily_order_number;
    }

    expect($numbers)->toBe(range(1, 12));
    expect(count(array_unique($numbers)))->toBe(12);
});

test('the formatted number is zero padded', function () {
    $sale = numberedSale('2026-10-15 05:00:00', ['daily_order_number' => 12]);

    expect($sale->formatted_daily_order_number)->toBe('Order #012');
});

/*
|--------------------------------------------------------------------------
| Bills and sales share one sequence
|--------------------------------------------------------------------------
*/

test('DailyNumber::next of an empty day is 1', function () {
    expect(DailyNumber::next('2026-10-15'))->toBe(1);
});

test('a bill number is reserved: the next sale continues after it', function () {
    numberedBill('2026-10-15', 3);

    expect(numberedSale('2026-10-15 05:00:00')->daily_order_number)->toBe(4);
});

test('sales and bills count together, whichever is higher', function () {
    numberedSale('2026-10-15 05:00:00');                       // 1
    numberedBill('2026-10-15', 2);
    numberedSale('2026-10-15 06:00:00');                       // 3
    numberedBill('2026-10-15', 4);
    $sale = numberedSale('2026-10-15 07:00:00');               // 5

    expect($sale->daily_order_number)->toBe(5);
    expect(DailyNumber::next('2026-10-15'))->toBe(6);
});

test('a paid or void bill keeps its number reserved', function () {
    numberedBill('2026-10-15', 5, ['status' => 'paid']);
    numberedBill('2026-10-15', 6, ['status' => 'void']);

    expect(DailyNumber::next('2026-10-15'))->toBe(7);
});

test('bills of another business day do not count', function () {
    numberedBill('2026-10-14', 9);
    numberedBill('2026-10-16', 9);

    expect(DailyNumber::next('2026-10-15'))->toBe(1);
    expect(DailyNumber::next('2026-10-14'))->toBe(10);
});

test('a sale of the next business day starts again at 1 even when bills of this day are high', function () {
    numberedBill('2026-10-15', 40);

    expect(numberedSale('2026-10-15 17:00:00')->daily_order_number)->toBe(1);
});

test('without the open_bills table numbering still works', function () {
    Schema::drop('open_bill_events');
    Schema::drop('open_bill_items');
    Schema::drop('open_bills');
    DailyNumber::flush();

    expect(numberedSale('2026-10-15 05:00:00')->daily_order_number)->toBe(1);
    expect(numberedSale('2026-10-15 06:00:00')->daily_order_number)->toBe(2);
    expect(DailyNumber::next('2026-10-15'))->toBe(3);
});

/*
|--------------------------------------------------------------------------
| Rows without a date (legacy) use created_at, and only those rows
|--------------------------------------------------------------------------
*/

/**
 * A legacy row: no `date`, the day comes from created_at. sellings.date is NOT NULL in every schema this app has
 * shipped, so the column is made nullable inside the test transaction (rolled back with it) to be able to
 * exercise the defensive created_at fallback.
 */
function numberedLegacySale(string $createdAt, int $number, ?string $name = null): int
{
    $dateColumn = collect(Schema::getColumns('sellings'))->firstWhere('name', 'date');
    if (! ($dateColumn['nullable'] ?? false)) {
        Schema::table('sellings', fn ($table) => $table->dateTimeTz('date')->nullable()->change());
    }

    return DB::table('sellings')->insertGetId([
        'code' => 'LEGACY'.$number.str_replace([' ', ':', '-'], '', $createdAt),
        'total_price' => 1000,
        'payed_money' => 1000,
        'money_changes' => 0,
        'total_qty' => 1,
        'date' => null,
        'daily_order_number' => $number,
        'customer_name' => $name,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

test('legacy rows without a date are counted by created_at when the day has no dated sale', function () {
    numberedLegacySale('2026-10-15 03:00:00', 6);
    numberedLegacySale('2026-10-15 04:00:00', 7);

    expect(DailyNumber::next('2026-10-15'))->toBe(8);
});

test('legacy rows of another day are not counted', function () {
    numberedLegacySale('2026-10-14 16:59:59', 30);
    numberedLegacySale('2026-10-15 17:00:00', 31);

    expect(DailyNumber::next('2026-10-15'))->toBe(1);
});

test('a dated sale is placed by its date, not by created_at', function () {
    // created_at is "now" (15 Oct, midday) but the sale was dated the 16th (offline sync of a later day).
    numberedSale('2026-10-15 20:00:00', ['daily_order_number' => 9]);

    expect(DailyNumber::next('2026-10-15'))->toBe(1);
    expect(DailyNumber::next('2026-10-16'))->toBe(10);
});

/*
|--------------------------------------------------------------------------
| Through SellingService: one moment decides number, name and column
|--------------------------------------------------------------------------
*/

/**
 * A real sale through the service, with the same data shape the FormRequest builds.
 */
function numberedServiceSale(Product $product, array $data = []): Selling
{
    $service = new SellingService;
    $data = array_merge([
        'payed_money' => 25000,
        'friend_price' => false,
        'products' => [['product_id' => $product->id, 'qty' => 1]],
    ], $data);

    return $service->create(array_merge($data, $service->mapProductRequest($data)));
}

function numberedProduct(): Product
{
    return Product::factory()->create([
        'name' => 'Produk Nomor',
        'initial_price' => 10000,
        'selling_price' => 25000,
        'stock' => 50,
    ]);
}

test('a sale at 23:59 WIB and one at 00:10 WIB get 1 and 1 of different days', function () {
    $product = numberedProduct();

    $late = numberedServiceSale($product, ['date' => '2026-10-15T23:59:00+07:00']);
    $early = numberedServiceSale($product, ['date' => '2026-10-16T00:10:00+07:00']);
    $later = numberedServiceSale($product, ['date' => '2026-10-16T08:00:00+07:00']);

    expect($late->daily_order_number)->toBe(1);
    expect($early->daily_order_number)->toBe(1);
    expect($later->daily_order_number)->toBe(2);
    expect($late->fresh()->date)->toBe('2026-10-15 16:59:00');
    expect($early->fresh()->date)->toBe('2026-10-15 17:10:00');
});

test('the service stores the date as UTC whatever format it came in', function () {
    $product = numberedProduct();

    expect(numberedServiceSale($product, ['date' => '2026-10-15T10:00:00+07:00'])->fresh()->date)->toBe('2026-10-15 03:00:00');
    expect(numberedServiceSale($product, ['date' => '2026-10-15T10:00:00Z'])->fresh()->date)->toBe('2026-10-15 10:00:00');
    expect(numberedServiceSale($product, ['date' => '2026-10-15'])->fresh()->date)->toBe('2026-10-15 00:00:00');
});

test('a sale without a date is dated now', function () {
    $sale = numberedServiceSale(numberedProduct());

    expect($sale->fresh()->date)->toBe('2026-10-15 05:00:00');
    expect($sale->daily_order_number)->toBe(1);
});

test('the service continues after a bill number', function () {
    numberedBill('2026-10-15', 4);

    $sale = numberedServiceSale(numberedProduct(), ['date' => '2026-10-15T10:00:00+07:00']);

    expect($sale->daily_order_number)->toBe(5);
});

/*
|--------------------------------------------------------------------------
| Duplicate customer names follow the business day too
|--------------------------------------------------------------------------
*/

test('Budi twice before the boundary is Budi and Budi (2); after it, Budi again', function () {
    $first = numberedSale('2026-10-15 05:00:00', ['customer_name' => 'Budi']);
    $second = numberedSale('2026-10-15 16:59:59', ['customer_name' => 'Budi']);
    $next = numberedSale('2026-10-15 17:00:00', ['customer_name' => 'Budi']);

    expect($first->customer_name)->toBe('Budi');
    expect($second->customer_name)->toBe('Budi (2)');
    expect($next->customer_name)->toBe('Budi');
});

test('the service numbers the name by the business day of the sale date', function () {
    $product = numberedProduct();

    $a = numberedServiceSale($product, ['customer_name' => 'Budi', 'date' => '2026-10-15T23:00:00+07:00']);
    $b = numberedServiceSale($product, ['customer_name' => 'budi', 'date' => '2026-10-15T23:59:00+07:00']);
    $c = numberedServiceSale($product, ['customer_name' => 'Budi', 'date' => '2026-10-16T00:01:00+07:00']);

    expect([$a->customer_name, $b->customer_name, $c->customer_name])->toBe(['Budi', 'budi (2)', 'Budi']);
});

test('the name service takes a business date or a full datetime', function () {
    numberedSale('2026-10-15 05:00:00', ['customer_name' => 'Siti']);

    expect(CustomerNameService::generateDailyCustomerName('Siti', '2026-10-15'))->toBe('Siti (2)');
    expect(CustomerNameService::generateDailyCustomerName('Siti', '2026-10-15 16:59:59'))->toBe('Siti (2)');
    expect(CustomerNameService::generateDailyCustomerName('Siti', '2026-10-15 17:00:00'))->toBe('Siti');
    expect(CustomerNameService::generateDailyCustomerName('Siti', '2026-10-16'))->toBe('Siti');
    expect(CustomerNameService::generateDailyCustomerName('Siti'))->toBe('Siti (2)'); // today = 15 Oct
});

test('a name that ends in a plain number is not mistaken for a counter', function () {
    numberedSale('2026-10-15 05:00:00', ['customer_name' => 'Meja 5']);

    expect(CustomerNameService::generateDailyCustomerName('Meja 5', '2026-10-15'))->toBe('Meja 5 (2)');
    expect(CustomerNameService::generateDailyCustomerName('Meja', '2026-10-15'))->toBe('Meja');
});

test('an open bill with the same name counts, a paid or void one does not', function () {
    numberedBill('2026-10-15', 1, ['customer_name' => 'Andi']);
    numberedBill('2026-10-15', 2, ['customer_name' => 'Rina', 'status' => 'paid']);
    numberedBill('2026-10-15', 3, ['customer_name' => 'Tono', 'status' => 'void']);

    expect(CustomerNameService::generateDailyCustomerName('Andi', '2026-10-15'))->toBe('Andi (2)');
    expect(CustomerNameService::generateDailyCustomerName('Rina', '2026-10-15'))->toBe('Rina');
    expect(CustomerNameService::generateDailyCustomerName('Tono', '2026-10-15'))->toBe('Tono');
});

test('sales and open bills are counted together for the name', function () {
    numberedSale('2026-10-15 05:00:00', ['customer_name' => 'Andi']);
    numberedBill('2026-10-15', 1, ['customer_name' => 'Andi (2)']);

    expect(CustomerNameService::generateDailyCustomerName('Andi', '2026-10-15'))->toBe('Andi (3)');
});

test('an open bill of another business day does not count', function () {
    numberedBill('2026-10-14', 1, ['customer_name' => 'Andi']);

    expect(CustomerNameService::generateDailyCustomerName('Andi', '2026-10-15'))->toBe('Andi');
    expect(CustomerNameService::generateDailyCustomerName('Andi', '2026-10-14'))->toBe('Andi (2)');
});

test('excludeBillId keeps a bill from counting against itself (renaming)', function () {
    $bill = numberedBill('2026-10-15', 1, ['customer_name' => 'Andi']);
    numberedBill('2026-10-15', 2, ['customer_name' => 'Rina']);

    expect(CustomerNameService::generateDailyCustomerName('Andi', '2026-10-15'))->toBe('Andi (2)');
    expect(CustomerNameService::generateDailyCustomerName('Andi', '2026-10-15', null, $bill->id))->toBe('Andi');
    // The other bills still count.
    expect(CustomerNameService::generateDailyCustomerName('Rina', '2026-10-15', null, $bill->id))->toBe('Rina (2)');
});

test('excludeSellingId keeps a sale from counting against itself', function () {
    $sale = numberedSale('2026-10-15 05:00:00', ['customer_name' => 'Andi']);

    expect(CustomerNameService::generateDailyCustomerName('Andi', '2026-10-15'))->toBe('Andi (2)');
    expect(CustomerNameService::generateDailyCustomerName('Andi', '2026-10-15', $sale->id))->toBe('Andi');
});

test('legacy rows without a date count for the name by created_at', function () {
    numberedLegacySale('2026-10-15 03:00:00', 1, 'Dewi');
    numberedLegacySale('2026-10-14 03:00:00', 2, 'Eko');

    expect(CustomerNameService::generateDailyCustomerName('Dewi', '2026-10-15'))->toBe('Dewi (2)');
    expect(CustomerNameService::generateDailyCustomerName('Eko', '2026-10-15'))->toBe('Eko');
});

test('a blank or null name stays null', function () {
    expect(CustomerNameService::generateDailyCustomerName(null))->toBeNull();
    expect(CustomerNameService::generateDailyCustomerName(''))->toBeNull();
    expect(CustomerNameService::generateDailyCustomerName('   '))->toBeNull();
});

test('names are numbered without the open_bills table', function () {
    Schema::drop('open_bill_events');
    Schema::drop('open_bill_items');
    Schema::drop('open_bills');
    DailyNumber::flush();
    numberedSale('2026-10-15 05:00:00', ['customer_name' => 'Gita']);

    expect(CustomerNameService::generateDailyCustomerName('Gita', '2026-10-15'))->toBe('Gita (2)');
});
