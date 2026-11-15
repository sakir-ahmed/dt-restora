<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Food that must be ordered ahead. A category can ask for it for all its items; an item can follow
     * its category (null), insist (true) or opt out (false). Lead hours is how far ahead at least.
     */
    public function up(): void
    {
        Schema::table('menu_categories', function (Blueprint $table): void {
            $table->boolean('pre_order_required')->default(false);
            $table->unsignedSmallInteger('pre_order_lead_hours')->nullable();
        });

        Schema::table('menu_items', function (Blueprint $table): void {
            $table->boolean('pre_order_required')->nullable();
            $table->unsignedSmallInteger('pre_order_lead_hours')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', fn (Blueprint $table) => $table->dropColumn(['pre_order_required', 'pre_order_lead_hours']));
        Schema::table('menu_categories', fn (Blueprint $table) => $table->dropColumn(['pre_order_required', 'pre_order_lead_hours']));
    }
};
