<?php

use App\Support\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Open Bill (a saved, not yet paid order). Additive and idempotent: the table is created with columns and
     * primary key only; unique/index/foreign keys are then added one by one, each guarded, so a run that
     * stopped half way on MySQL (DDL is not transactional) is repaired by running the migration again.
     * SQLite cannot ALTER ADD FOREIGN KEY, so there the foreign keys are defined inline.
     */
    public function up(): void
    {
        if (! Schema::hasTable('open_bills')) {
            Schema::create('open_bills', function (Blueprint $table) {
                $table->engine = 'InnoDB';

                $table->id();
                $table->unsignedInteger('number');
                $table->date('business_date');
                $table->string('status', 12)->default('open'); // open|paid|void, deliberately not a MySQL ENUM
                $table->unsignedInteger('version')->default(1);
                $table->string('customer_name', 255)->nullable();
                $table->unsignedBigInteger('table_id')->nullable();
                $table->unsignedBigInteger('member_id')->nullable();
                $table->text('note')->nullable();
                $table->decimal('tax_percent', 6, 3)->default(0); // frozen when the bill is created
                $table->double('discount_price')->default(0);
                $table->unsignedSmallInteger('kitchen_batch_count')->default(0);
                $table->mediumText('last_kitchen_batch')->nullable();
                $table->mediumText('pending_cancel')->nullable();
                $table->dateTime('kitchen_sent_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('paid_by')->nullable();
                $table->unsignedBigInteger('voided_by')->nullable();
                $table->dateTime('paid_at')->nullable();
                $table->dateTime('voided_at')->nullable();
                $table->string('void_reason', 255)->nullable();
                $table->unsignedBigInteger('selling_id')->nullable();
                $table->timestamps();

                if (SafeMigration::isSqlite()) {
                    $table->foreign('table_id')->references('id')->on('tables')->nullOnDelete();
                    $table->foreign('member_id')->references('id')->on('members')->nullOnDelete();
                    $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
                    $table->foreign('paid_by')->references('id')->on('users')->nullOnDelete();
                    $table->foreign('voided_by')->references('id')->on('users')->nullOnDelete();
                    $table->foreign('selling_id')->references('id')->on('sellings')->nullOnDelete();
                }
            });
        }

        SafeMigration::unique('open_bills', ['business_date', 'number'], 'open_bills_day_number_unique');
        SafeMigration::index('open_bills', ['status', 'business_date'], 'open_bills_status_day_index');
        SafeMigration::unique('open_bills', 'selling_id', 'open_bills_selling_id_unique');

        SafeMigration::foreign('open_bills', 'table_id', 'tables');
        SafeMigration::foreign('open_bills', 'member_id', 'members');
        SafeMigration::foreign('open_bills', 'created_by', 'users');
        SafeMigration::foreign('open_bills', 'paid_by', 'users');
        SafeMigration::foreign('open_bills', 'voided_by', 'users');
        SafeMigration::foreign('open_bills', 'selling_id', 'sellings');
    }

    /**
     * Development only, never on production: this deletes every open bill and its audit trail.
     */
    public function down(): void
    {
        Schema::dropIfExists('open_bills');
    }
};
