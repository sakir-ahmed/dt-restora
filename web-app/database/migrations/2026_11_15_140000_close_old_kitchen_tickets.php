<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Finished sales that were loaded into the database without a kitchen step (seeded or imported history)
     * sit at "pending" and crowd the kitchen board. A paid or deleted order more than two days old is not
     * waiting at the kitchen any more, so it is closed as served. Recent orders are left alone: an order paid
     * first can still be on its way through the kitchen.
     */
    public function up(): void
    {
        DB::table('orders')
            ->whereIn('status', ['paid', 'void'])
            ->where('kitchen_status', 'pending')
            ->where('created_at', '<', now()->subDays(2))
            ->update(['kitchen_status' => 'served']);
    }

    public function down(): void
    {
        // The earlier step of a closed ticket is not known, so there is nothing to restore.
    }
};
