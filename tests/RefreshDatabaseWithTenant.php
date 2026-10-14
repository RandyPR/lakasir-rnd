<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

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
        $this->parentBeginDatabaseTransaction();

        seedShop();
    }
}
