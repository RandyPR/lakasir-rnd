<?php

namespace App\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Helpers that make additive migrations idempotent per statement and gentle on a live MySQL database.
 *
 * MySQL DDL is not transactional: a migration that fails half way leaves the objects it already created.
 * Every helper checks first (hasColumn / hasIndex / getForeignKeys), so running the same migration again
 * repairs a half-finished state instead of failing with "duplicate column/key". On tables that already hold
 * data, ALTERs run with a short lock_wait_timeout and are retried on lock wait timeout (1205) and deadlock (1213).
 */
final class SafeMigration
{
    private const RETRYABLE_ERRNOS = [1205, 1213];

    public static function isSqlite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }

    private static function isMysqlFamily(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * MySQL / MariaDB only: make this session give up quickly on a metadata lock instead of queueing
     * every other query behind a blocked ALTER for the default 50 seconds.
     */
    public static function lockTimeout(int $seconds = 10): void
    {
        if (! self::isMysqlFamily()) {
            return;
        }

        DB::statement('SET SESSION lock_wait_timeout = '.max(1, $seconds));
    }

    /**
     * Run $fn, retrying on MySQL lock wait timeout (1205) and deadlock (1213).
     * The last failure is rethrown. $sleepSeconds <= 0 skips the pause (tests).
     */
    public static function retryOnLock(Closure $fn, int $attempts = 3, int $sleepSeconds = 3): mixed
    {
        $attempts = max(1, $attempts);

        for ($attempt = 1; ; $attempt++) {
            try {
                return $fn();
            } catch (QueryException $e) {
                if ($attempt >= $attempts || ! self::isLockError($e)) {
                    throw $e;
                }

                if ($sleepSeconds > 0) {
                    sleep($sleepSeconds);
                }
            }
        }
    }

    /**
     * Add a column unless it already exists. $define receives the Blueprint, e.g.
     * fn (Blueprint $t) => $t->string('note', 255)->nullable()  (never ->after()).
     */
    public static function addColumn(string $table, string $column, Closure $define): void
    {
        if (Schema::hasColumn($table, $column)) {
            return;
        }

        self::alter($table, $define);
    }

    /**
     * @param  array<int, string>|string  $columns
     */
    public static function index(string $table, array|string $columns, string $name): void
    {
        if (Schema::hasIndex($table, $name)) {
            return;
        }

        self::alter($table, fn (Blueprint $t) => $t->index($columns, $name));
    }

    /**
     * @param  array<int, string>|string  $columns
     */
    public static function unique(string $table, array|string $columns, string $name): void
    {
        if (Schema::hasIndex($table, $name)) {
            return;
        }

        self::alter($table, fn (Blueprint $t) => $t->unique($columns, $name));
    }

    /**
     * Add a foreign key on $table.$column unless one on that column already exists (any constraint name).
     * SQLite cannot ALTER ADD FOREIGN KEY: there it is a no-op (the create-table migration defines it inline).
     */
    public static function foreign(
        string $table,
        string $column,
        string $refTable,
        string $onDelete = 'set null',
        string $refColumn = 'id',
    ): void {
        if (self::isSqlite()) {
            return;
        }

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (($foreignKey['columns'] ?? []) === [$column]) {
                return;
            }
        }

        self::alter($table, fn (Blueprint $t) => $t->foreign($column)
            ->references($refColumn)
            ->on($refTable)
            ->onDelete($onDelete));
    }

    public static function dropIndexIfExists(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $name)) {
            return;
        }

        self::alter($table, fn (Blueprint $t) => $t->dropIndex($name));
    }

    /**
     * True when an index other than $exceptIndex has $column as its FIRST column. MySQL refuses to drop the
     * only index a foreign key relies on (error 1553), so down() must create a replacement first.
     */
    public static function hasOtherIndexLeading(string $table, string $column, string $exceptIndex): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['name'] === $exceptIndex) {
                continue;
            }

            if (($index['columns'][0] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    private static function isLockError(QueryException $e): bool
    {
        if (in_array((int) ($e->errorInfo[1] ?? 0), self::RETRYABLE_ERRNOS, true)) {
            return true;
        }

        return str_contains($e->getMessage(), 'Lock wait timeout exceeded')
            || str_contains($e->getMessage(), 'Deadlock found');
    }

    private static function alter(string $table, Closure $define): void
    {
        self::lockTimeout();

        self::retryOnLock(fn () => Schema::table($table, $define));
    }
}
