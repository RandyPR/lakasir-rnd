<?php

use App\Support\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lines of an open bill. Same idempotent pattern as create_open_bills_table:
     * columns + primary key first, then unique/foreign keys one by one.
     */
    public function up(): void
    {
        if (! Schema::hasTable('open_bill_items')) {
            Schema::create('open_bill_items', function (Blueprint $table) {
                $table->engine = 'InnoDB';

                $table->id();
                $table->unsignedBigInteger('open_bill_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('user_id')->nullable(); // who added the line
                $table->double('qty');
                $table->double('price')->nullable(); // line total before discount (cart_items.price semantics)
                $table->double('discount_price')->nullable();
                $table->unsignedBigInteger('price_unit_id')->nullable();
                $table->string('note', 255)->nullable();
                $table->double('sent_qty')->default(0);
                $table->string('sent_note', 255)->nullable();
                $table->timestamps();

                if (SafeMigration::isSqlite()) {
                    $table->foreign('open_bill_id')->references('id')->on('open_bills')->cascadeOnDelete();
                    $table->foreign('product_id')->references('id')->on('products');
                    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                    $table->foreign('price_unit_id')->references('id')->on('price_units')->nullOnDelete();
                }
            });
        }

        SafeMigration::unique('open_bill_items', ['open_bill_id', 'product_id'], 'open_bill_items_bill_product_unique');

        SafeMigration::foreign('open_bill_items', 'open_bill_id', 'open_bills', 'cascade');
        SafeMigration::foreign('open_bill_items', 'product_id', 'products', 'restrict');
        SafeMigration::foreign('open_bill_items', 'user_id', 'users');
        SafeMigration::foreign('open_bill_items', 'price_unit_id', 'price_units');
    }

    /**
     * Development only, never on production: this deletes every open bill line.
     */
    public function down(): void
    {
        Schema::dropIfExists('open_bill_items');
    }
};
