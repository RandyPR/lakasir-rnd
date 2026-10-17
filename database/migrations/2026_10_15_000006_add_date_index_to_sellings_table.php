<?php

use App\Support\SafeMigration;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Optional: speeds up "highest daily order number of a business day". Safe to skip; the code is correct
     * without it. Idempotent, with a short lock timeout and retry because sellings already holds data.
     */
    public function up(): void
    {
        SafeMigration::index('sellings', ['date', 'daily_order_number'], 'sellings_date_number_index');
    }

    public function down(): void
    {
        SafeMigration::dropIndexIfExists('sellings', 'sellings_date_number_index');
    }
};
