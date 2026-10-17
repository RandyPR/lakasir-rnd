<?php

use App\Support\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit trail of an open bill. product_id has no foreign key on purpose: the row is a snapshot
     * (product_name) that must survive the product being deleted.
     */
    public function up(): void
    {
        if (! Schema::hasTable('open_bill_events')) {
            Schema::create('open_bill_events', function (Blueprint $table) {
                $table->engine = 'InnoDB';

                $table->id();
                $table->unsignedBigInteger('open_bill_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('action', 24);
                $table->unsignedBigInteger('product_id')->nullable();
                $table->string('product_name', 255)->nullable();
                $table->double('qty_from')->nullable();
                $table->double('qty_to')->nullable();
                $table->string('reason', 255)->nullable();
                $table->text('detail')->nullable(); // small JSON
                $table->timestamp('created_at')->nullable();

                if (SafeMigration::isSqlite()) {
                    $table->foreign('open_bill_id')->references('id')->on('open_bills')->cascadeOnDelete();
                    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                }
            });
        }

        SafeMigration::index('open_bill_events', ['open_bill_id', 'id'], 'open_bill_events_bill_index');

        SafeMigration::foreign('open_bill_events', 'open_bill_id', 'open_bills', 'cascade');
        SafeMigration::foreign('open_bill_events', 'user_id', 'users');
    }

    /**
     * Development only, never on production: this deletes the audit trail.
     */
    public function down(): void
    {
        Schema::dropIfExists('open_bill_events');
    }
};
