<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-row table used as the global sales lock (SELECT ... FOR UPDATE on the row named 'sales').
     * The row itself is not inserted here: NumberingLock::ensure() creates it on first use.
     */
    public function up(): void
    {
        if (! Schema::hasTable('numbering_locks')) {
            Schema::create('numbering_locks', function (Blueprint $table) {
                $table->engine = 'InnoDB';

                $table->string('name', 32)->primary();
            });
        }
    }

    /**
     * Development only, never on production.
     */
    public function down(): void
    {
        Schema::dropIfExists('numbering_locks');
    }
};
