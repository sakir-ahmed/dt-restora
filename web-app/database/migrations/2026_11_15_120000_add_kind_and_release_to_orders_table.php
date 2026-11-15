<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Pre-orders and reservation meals are orders: kind says which, reservation_id links a meal to its
     * booking, and released_at is when the kitchen was handed the order (by hand, or by the clock).
     * Approving guest orders gets its own permission, given to every role that may already delete orders.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('kind', 20)->default('regular')->after('channel')->index();
            $table->foreignId('reservation_id')->nullable()->after('kind')->constrained('reservations')->nullOnDelete();
            $table->timestamp('released_at')->nullable()->after('approval_status');
        });

        if (Schema::hasTable('permissions') && Schema::hasTable('roles')) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $approve = Permission::findOrCreate('orders.approve');

            Role::query()->get()
                ->filter(fn (Role $role): bool => $role->name === 'super-admin' || $role->hasPermissionTo('orders.void'))
                ->each(fn (Role $role) => $role->givePermissionTo($approve));
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reservation_id');
            $table->dropColumn(['kind', 'released_at']);
        });

        Permission::query()->where('name', 'orders.approve')->delete();
    }
};
