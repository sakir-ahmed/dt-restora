<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The is_preorder_only columns came from the armanskitchen branch and were replaced by
     * pre_order_required and pre_order_lead_hours. An install that never ran the branch
     * migrations simply has nothing to drop.
     */
    public function up(): void
    {
        Schema::table('menu_categories', function (Blueprint $table): void {
            if (Schema::hasColumn('menu_categories', 'is_preorder_only')) {
                $table->dropColumn('is_preorder_only');
            }
        });

        Schema::table('menu_items', function (Blueprint $table): void {
            if (Schema::hasColumn('menu_items', 'is_preorder_only')) {
                $table->dropColumn('is_preorder_only');
            }
        });
    }

    public function down(): void
    {
        // The columns are gone for good; pre_order_required replaces them.
    }
};
