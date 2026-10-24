<?php

use App\Support\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-item note ("less sugar", "no ice") on the cart line and on the sold line.
     * Nullable varchar(255) without AFTER, so MySQL adds it as a metadata-only change where supported.
     * Idempotent (hasColumn per column) and with a short lock timeout plus retry on busy tables.
     */
    public function up(): void
    {
        SafeMigration::addColumn('cart_items', 'note', fn (Blueprint $table) => $table->string('note', 255)->nullable());
        SafeMigration::addColumn('selling_details', 'note', fn (Blueprint $table) => $table->string('note', 255)->nullable());
    }

    /**
     * Development only, never on production: dropping the columns loses every saved note.
     */
    public function down(): void
    {
        foreach (['selling_details', 'cart_items'] as $tableName) {
            if (Schema::hasColumn($tableName, 'note')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('note'));
            }
        }
    }
};
