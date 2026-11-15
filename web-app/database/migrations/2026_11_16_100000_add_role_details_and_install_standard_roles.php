<?php

declare(strict_types=1);

use App\Support\Rbac\RoleDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roles get a name people can read, a note, and a switch for working across every branch. The standard
     * restaurant roles that are missing are created (existing roles keep the permissions they have), and the
     * new "roles.manage" permission goes to the owner and super-admin.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->string('label')->nullable();
            $table->string('label_bn')->nullable();
            $table->string('description', 255)->nullable();
            $table->boolean('all_branches')->default(false);
        });

        if (Schema::hasTable('permissions')) {
            RoleDefaults::install(false);

            // Which roles worked across every branch used to be fixed in code; keep it that way for existing roles.
            foreach (RoleDefaults::definitions() as $name => $definition) {
                DB::table('roles')->where('name', $name)->update(['all_branches' => $definition['all_branches']]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn(['label', 'label_bn', 'description', 'all_branches']);
        });
    }
};
