<?php

namespace App\Services\Tenants;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * One constant row (numbering_locks.name = 'sales') that every sale locks first.
 *
 * It serialises everything that numbers or sells: daily order number, "Name (2)", the SELL code, stock
 * read-modify-write. The order of locks across the app is fixed (plan-v3 14.0 R1):
 * sales lock -> open_bills row -> open_bill_items -> cart_items -> products. Never reverse it.
 *
 * Why the lock must be the FIRST statement of the transaction: on MySQL (REPEATABLE READ) the first consistent
 * read of a transaction fixes its snapshot. If anything is read before the lock is granted, the reads after
 * it (max(daily_order_number)) would still see the old snapshot and two sales could get the same number.
 * Why ensure() runs outside the transaction as a plain SELECT: INSERT IGNORE inside a transaction takes a
 * shared lock on the duplicate row and deadlocks with the concurrent FOR UPDATE.
 *
 * Without the table (code deployed before the migration) both calls are no-ops with one warning, which is
 * exactly how sales behaved before this feature existed.
 */
final class NumberingLock
{
    public const SALES = 'sales';

    private const TABLE = 'numbering_locks';

    private static bool $tableKnown = false;

    private static bool $rowKnown = false;

    private static bool $warned = false;

    private function __construct()
    {
    }

    /**
     * Whether the numbering_locks table exists. Only a positive answer is remembered for the process
     * (a negative one may turn positive after the migration runs).
     */
    public static function available(): bool
    {
        if (self::$tableKnown) {
            return true;
        }

        try {
            self::$tableKnown = Schema::hasTable(self::TABLE);
        } catch (Throwable) {
            return false;
        }

        return self::$tableKnown;
    }

    /**
     * Makes sure the 'sales' row exists. Call it OUTSIDE a transaction, right before beginTransaction().
     * A plain autocommit SELECT first; the INSERT only happens when the row is missing (first sale ever).
     */
    public static function ensure(): void
    {
        if (self::$rowKnown) {
            return;
        }

        if (! self::available()) {
            self::warnOnce();

            return;
        }

        if (! DB::table(self::TABLE)->where('name', self::SALES)->exists()) {
            DB::table(self::TABLE)->insertOrIgnore(['name' => self::SALES]);
        }

        self::$rowKnown = true;
    }

    /**
     * Takes the sales lock. Call it INSIDE a transaction as its very first statement; it is released by
     * commit or rollback. A missing row (deleted by hand) is recreated; a missing table is a no-op.
     */
    public static function acquire(): void
    {
        if (! self::available()) {
            self::warnOnce();

            return;
        }

        $locked = fn () => DB::table(self::TABLE)->where('name', self::SALES)->lockForUpdate()->first();

        if ($locked() === null) {
            DB::table(self::TABLE)->insertOrIgnore(['name' => self::SALES]);
            $locked();
        }
    }

    /**
     * True for a deadlock (1213, SQLSTATE 40001) or a lock wait timeout (1205): the sale may be retried once.
     */
    public static function isContention(Throwable $e): bool
    {
        if (! $e instanceof QueryException) {
            return false;
        }

        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return in_array($driverCode, [1213, 1205], true) || (string) $e->getCode() === '40001';
    }

    /**
     * Forgets everything remembered for this process (tests that drop or recreate the table).
     */
    public static function flush(): void
    {
        self::$tableKnown = false;
        self::$rowKnown = false;
        self::$warned = false;
        DailyNumber::flush();
    }

    private static function warnOnce(): void
    {
        if (self::$warned) {
            return;
        }

        self::$warned = true;
        Log::warning('numbering_locks table is missing: sales are not serialised. Run the open bill migrations.');
    }
}
