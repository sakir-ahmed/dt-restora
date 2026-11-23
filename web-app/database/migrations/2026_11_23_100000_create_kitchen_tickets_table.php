<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One invoice, many kitchen tickets. Every batch of items sent to the kitchen is its own ticket
     * (round 1, 2, 3 ...) with its own step, so items added to a running order reach the kitchen as a
     * fresh ticket instead of changing one that is already being cooked or served.
     */
    public function up(): void
    {
        Schema::create('kitchen_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number')->default(1);
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['order_id', 'number']);
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('kitchen_ticket_id')->nullable()->after('order_id')->constrained('kitchen_tickets')->nullOnDelete();
            // What the cashier gave this line, kept so the whole bill can be priced again when items are added.
            $table->string('line_discount_type', 10)->nullable();
            $table->decimal('line_discount_value', 12, 2)->nullable();
            $table->decimal('line_vat_percent', 5, 2)->nullable();
            $table->decimal('line_extra_charge', 12, 2)->nullable();
        });

        // Every existing order becomes one ticket at its current kitchen step.
        DB::statement("INSERT INTO kitchen_tickets (order_id, number, status, prepared_by, created_at, updated_at)
            SELECT id, 1,
                CASE WHEN kitchen_status IN ('pending', 'preparing', 'ready', 'served') THEN kitchen_status ELSE 'served' END,
                prepared_by, created_at, updated_at
            FROM orders");

        DB::statement('UPDATE order_items SET kitchen_ticket_id = (
            SELECT kitchen_tickets.id FROM kitchen_tickets
            WHERE kitchen_tickets.order_id = order_items.order_id AND kitchen_tickets.number = 1
        )');
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('kitchen_ticket_id');
            $table->dropColumn(['line_discount_type', 'line_discount_value', 'line_vat_percent', 'line_extra_charge']);
        });

        Schema::dropIfExists('kitchen_tickets');
    }
};
