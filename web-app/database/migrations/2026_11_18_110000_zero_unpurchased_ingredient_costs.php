<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Stock cost is real money spent on purchases. Two old paths left costs behind without any:
     * the ArmansKitchen seeder wrote the owner's price list into the stock rows, and deleting a
     * purchase kept its average cost after the stock went back to zero. Every stock row whose
     * ingredient has no line on a live purchase of the same branch goes back to zero cost.
     */
    public function up(): void
    {
        DB::table('ingredient_stocks')
            ->where(function ($query): void {
                $query->where('avg_cost', '>', 0)->orWhere('last_cost', '>', 0);
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw(1)
                    ->from('purchase_items')
                    ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
                    ->whereColumn('purchase_items.ingredient_id', 'ingredient_stocks.ingredient_id')
                    ->whereColumn('purchases.branch_id', 'ingredient_stocks.branch_id')
                    ->whereNull('purchases.deleted_at');
            })
            ->update(['avg_cost' => 0, 'last_cost' => 0]);
    }

    public function down(): void
    {
        // The old figures were fabricated, not history; there is nothing to restore.
    }
};
