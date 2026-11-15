<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a person has marked as read in the notification bell: alert id => a fingerprint of its text,
     * so an alert that changes (more items low on stock) shows again.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('notifications_read')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('notifications_read');
        });
    }
};
