<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tables an order sits at, with the seat numbers taken at each. One order can cover several joined tables;
     * orders.restaurant_table_id stays the first one so everything that reads a single table keeps working.
     */
    public function up(): void
    {
        Schema::create('order_tables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_table_id')->constrained()->cascadeOnDelete();
            $table->json('seats')->nullable();
            $table->unique(['order_id', 'restaurant_table_id']);
        });

        DB::statement('INSERT INTO order_tables (order_id, restaurant_table_id)
            SELECT id, restaurant_table_id FROM orders WHERE restaurant_table_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_tables');
    }
};
