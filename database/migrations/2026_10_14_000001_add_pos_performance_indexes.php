<?php

use App\Support\SafeMigration;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Indexes for the cashier (POS) product list and cart lookups.
     * SQLite does not index foreign keys automatically, so stocks.product_id was scanned in full
     * for every product on every cashier request.
     *
     * Idempotent: every index is checked first, so a run that stopped half way can simply be repeated.
     * Index names are unchanged from the first version of this migration.
     */
    public function up(): void
    {
        SafeMigration::index('stocks', ['product_id', 'is_ready', 'type', 'stock'], 'stocks_pos_lookup_index');
        SafeMigration::index('cart_items', ['user_id', 'product_id'], 'cart_items_user_product_index');
        SafeMigration::index('products', ['show', 'type', 'deleted_at'], 'products_pos_listing_index');
        SafeMigration::index('products', 'sku', 'products_sku_index');
    }

    /**
     * Development only, never on production. MySQL error 1553: the composite index may be the only index
     * a foreign key can use, so a plain index on the foreign key column is created before it is dropped.
     */
    public function down(): void
    {
        $this->dropCompositeKeepingForeignKeyIndex('stocks', 'product_id', 'stocks_pos_lookup_index', 'stocks_product_id_index');
        $this->dropCompositeKeepingForeignKeyIndex('cart_items', 'user_id', 'cart_items_user_product_index', 'cart_items_user_id_index');

        SafeMigration::dropIndexIfExists('products', 'products_pos_listing_index');
        SafeMigration::dropIndexIfExists('products', 'products_sku_index');
    }

    private function dropCompositeKeepingForeignKeyIndex(
        string $table,
        string $foreignKeyColumn,
        string $composite,
        string $replacement,
    ): void {
        if (! SafeMigration::hasOtherIndexLeading($table, $foreignKeyColumn, $composite)) {
            SafeMigration::index($table, $foreignKeyColumn, $replacement);
        }

        SafeMigration::dropIndexIfExists($table, $composite);
    }
};
