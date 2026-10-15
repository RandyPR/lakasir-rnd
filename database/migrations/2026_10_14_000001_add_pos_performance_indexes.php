<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes for the cashier (POS) product list and cart lookups.
     * SQLite does not index foreign keys automatically, so stocks.product_id was scanned in full
     * for every product on every cashier request.
     */
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->index(['product_id', 'is_ready', 'type', 'stock'], 'stocks_pos_lookup_index');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->index(['user_id', 'product_id'], 'cart_items_user_product_index');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->index(['show', 'type', 'deleted_at'], 'products_pos_listing_index');
            $table->index('sku', 'products_sku_index');
        });
    }

    public function down(): void
    {
        Schema::table('stocks', fn (Blueprint $table) => $table->dropIndex('stocks_pos_lookup_index'));
        Schema::table('cart_items', fn (Blueprint $table) => $table->dropIndex('cart_items_user_product_index'));
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_pos_listing_index');
            $table->dropIndex('products_sku_index');
        });
    }
};
