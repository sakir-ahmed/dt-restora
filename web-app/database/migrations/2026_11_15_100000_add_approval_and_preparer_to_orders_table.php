<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Orders from guests (table QR, website, online menu) wait for a manager before the kitchen sees them:
     * approval_status is "awaiting" until then. prepared_by is the kitchen user who started the order.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('approval_status', 20)->nullable()->after('kitchen_status')->index();
            $table->foreignId('prepared_by')->nullable()->after('approval_status')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('prepared_by');
            $table->dropColumn('approval_status');
        });
    }
};
