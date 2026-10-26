<?php

use App\Support\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trash for sales: a trashed sale keeps its row, its lines and its number, only `deleted_at` says it is gone.
     * Three nullable columns, none with a default and none with AFTER, so MySQL/MariaDB add them without rewriting
     * the table. Old code ignores them, so this can run BEFORE the new code is deployed.
     * Idempotent (hasColumn / hasIndex per object) and with a short lock timeout plus retry on busy tables.
     */
    public function up(): void
    {
        SafeMigration::addColumn('sellings', 'deleted_at', fn (Blueprint $table) => $table->softDeletes());
        SafeMigration::addColumn('sellings', 'deleted_by', fn (Blueprint $table) => $table->unsignedBigInteger('deleted_by')->nullable());
        SafeMigration::addColumn('sellings', 'delete_reason', fn (Blueprint $table) => $table->string('delete_reason', 255)->nullable());
        SafeMigration::index('sellings', 'deleted_at', 'sellings_deleted_at_index');
    }

    /**
     * Development only, never on production: dropping the columns forgets which sales are in the trash,
     * and they would show up in every report again.
     */
    public function down(): void
    {
        SafeMigration::dropIndexIfExists('sellings', 'sellings_deleted_at_index');

        foreach (['delete_reason', 'deleted_by', 'deleted_at'] as $column) {
            if (Schema::hasColumn('sellings', $column)) {
                Schema::table('sellings', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
