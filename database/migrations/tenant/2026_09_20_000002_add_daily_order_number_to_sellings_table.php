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
            if (! Schema::hasColumn('sellings', 'daily_order_number')) {
                $column = $table->unsignedInteger('daily_order_number')->nullable()->index();
                if (Schema::hasColumn('sellings', 'customer_name')) {
                    $column->after('customer_name');
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
            if (Schema::hasColumn('sellings', 'daily_order_number')) {
                $table->dropIndex(['daily_order_number']);
                $table->dropColumn('daily_order_number');
            }
        });
    }
};
