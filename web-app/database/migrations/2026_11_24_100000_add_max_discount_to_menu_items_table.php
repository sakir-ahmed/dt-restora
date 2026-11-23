<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The most a dish may be discounted, per unit: a percent of its price or a fixed amount. Empty means no limit;
     * zero means the dish is never discounted (a bill discount skips it).
     */
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table): void {
            $table->string('max_discount_type', 10)->nullable();
            $table->decimal('max_discount_value', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table): void {
            $table->dropColumn(['max_discount_type', 'max_discount_value']);
        });
    }
};
