<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sellings', function (Blueprint $table) {
            if (! Schema::hasColumn('sellings', 'customer_name')) {
                $column = $table->string('customer_name')->nullable();
                if (Schema::hasColumn('sellings', 'customer_number')) {
                    $column->after('customer_number');
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sellings', function (Blueprint $table) {
            if (Schema::hasColumn('sellings', 'customer_name')) {
                $table->dropColumn('customer_name');
            }
        });
    }
};
