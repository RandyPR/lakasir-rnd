<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Migrates the single test database once per run, then wraps every test in a
 * transaction seeded with the shop data tests expect (see seedShop()).
 */
trait RefreshDatabaseWithTenant
{
    use RefreshDatabase {
        beginDatabaseTransaction as parentBeginDatabaseTransaction;
    }

    public function beginDatabaseTransaction()
    {
        // MySQL commits the test's transaction before a schema statement, also one that then fails, and that error
        // leaves PDO::inTransaction() saying true. One query refreshes it before RefreshDatabase checks it, so the
        // next test migrates a fresh database instead of finding the rows of this one.
        $this->beforeApplicationDestroyed(fn () => DB::connection()->getPdo()->query('select 1'));

        $this->parentBeginDatabaseTransaction();

        seedShop();
    }
}
