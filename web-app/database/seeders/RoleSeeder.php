<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Rbac\RoleDefaults;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Permissions and the standard restaurant roles live in App\Support\Rbac (the permission catalog and the
     * role defaults), the same lists the Roles screen resets to. super-admin passes every Gate check through
     * Gate::before; it also holds the full list so permission listings show it.
     */
    public function run(): void
    {
        RoleDefaults::install(true);
    }
}
