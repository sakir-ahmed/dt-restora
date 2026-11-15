<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A menu-book pre-order that staff turned into a real order: the order keeps the
     * link, like reservation_id does for a reservation meal.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('preorder_id')->nullable()->after('reservation_id')->constrained('preorders')->nullOnDelete();
            $table->index('preorder_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('preorder_id');
        });
    }
};
