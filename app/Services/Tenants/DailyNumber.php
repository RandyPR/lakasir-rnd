<?php

namespace App\Services\Tenants;

use App\Models\Tenants\Selling;
use App\Support\BusinessDay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The daily order number: one sequence per business day shared by sales and open bills, so a number printed
 * on a kitchen ticket is the same one printed on the receipt.
 */
final class DailyNumber
{
    private static bool $billsTableKnown = false;

    private function __construct()
    {
    }

    /**
     * The next number of a business day (max + 1 over sellings and open bills).
     *
     * The caller must already hold NumberingLock::acquire(), and that must have been the first statement of the
     * transaction (see NumberingLock); otherwise two sales can read the same max.
     */
    public static function next(string $businessDate): int
    {
        $max = BusinessDay::scopeDay(Selling::query(), 'date', $businessDate)->max('daily_order_number');

        if ($max === null) {
            // Legacy rows without a date: fall back to created_at, and only for those rows.
            $max = BusinessDay::scopeDay(Selling::query()->whereNull('date'), 'created_at', $businessDate)
                ->max('daily_order_number');
        }

        return max((int) $max, self::maxBillNumber($businessDate)) + 1;
    }

    /**
     * Whether the open_bills table exists. Only a positive answer is remembered for the process.
     */
    public static function billsTableAvailable(): bool
    {
        if (self::$billsTableKnown) {
            return true;
        }

        try {
            self::$billsTableKnown = Schema::hasTable('open_bills');
        } catch (Throwable) {
            return false;
        }

        return self::$billsTableKnown;
    }

    public static function flush(): void
    {
        self::$billsTableKnown = false;
    }

    /**
     * The highest bill number of the day, whatever the bill's status (the number stays reserved after pay/void).
     */
    private static function maxBillNumber(string $businessDate): int
    {
        if (! self::billsTableAvailable()) {
            return 0;
        }

        try {
            return (int) DB::table('open_bills')->where('business_date', $businessDate)->max('number');
        } catch (Throwable $e) {
            if (NumberingLock::isContention($e)) {
                throw $e;
            }

            return 0;
        }
    }
}
