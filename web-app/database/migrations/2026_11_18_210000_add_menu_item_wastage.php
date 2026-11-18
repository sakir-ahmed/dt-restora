<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wastages', function (Blueprint $table): void {
            // Menu item wastage: whole portions of a dish, its ingredients
            // deducted by the recipe. Ingredient rows keep both columns.
            $table->foreignId('menu_item_id')->nullable()->after('branch_id')->constrained()->nullOnDelete();
        });

        Schema::table('wastages', function (Blueprint $table): void {
            $table->foreignId('ingredient_id')->nullable()->change();
            $table->foreignId('unit_id')->nullable()->change();
        });

        // The recipe explosion frozen at wastage time, so deleting the entry
        // restores exactly what was taken even if the recipe changes later.
        Schema::create('wastage_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wastage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained();
            $table->foreignId('unit_id')->constrained();
            $table->decimal('qty', 18, 3);
            $table->decimal('base_qty', 18, 6);
            $table->decimal('cost', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wastage_items');

        Schema::table('wastages', function (Blueprint $table): void {
            $table->dropForeign(['menu_item_id']);
            $table->dropColumn('menu_item_id');
        });

        Schema::table('wastages', function (Blueprint $table): void {
            $table->foreignId('ingredient_id')->nullable(false)->change();
            $table->foreignId('unit_id')->nullable(false)->change();
        });
    }
};
