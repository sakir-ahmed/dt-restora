<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The standard restaurant roles and what each may do. These are the defaults a role can be reset to.
 * Roles an owner creates themselves are never touched by a reset.
 */
final class RoleDefaults
{
    /** Roles whose access is always everything: they cannot be edited, so nobody can lock themselves out. */
    public const LOCKED = ['super-admin', 'owner'];

    /**
     * name => the permissions it starts with ('*' is all of them) and whether it works across every branch.
     *
     * @return array<string, array{permissions: array<int, string>|string, all_branches: bool}>
     */
    public static function definitions(): array
    {
        return [
            'super-admin' => ['permissions' => '*', 'all_branches' => true],
            'owner' => ['permissions' => '*', 'all_branches' => true],
            'branch-manager' => ['all_branches' => false, 'permissions' => [
                'admin.access', 'branches.view', 'menu.view', 'menu.manage', 'tables.view', 'tables.manage', 'reservations.view', 'reservations.manage', 'preorders.view', 'preorders.manage', 'media.view', 'media.manage', 'staff.view',
                'orders.view', 'orders.create', 'orders.void', 'orders.approve', 'discounts.override',
                'inventory.view', 'inventory.manage', 'stock.adjust', 'purchases.manage',
                'recipes.manage', 'wastage.log', 'wastage.approve', 'transfers.manage',
                'employees.manage', 'attendance.manage', 'letters.manage', 'shifts.manage', 'payments.manage', 'expenses.manage',
                'pos.access', 'kds.access',
                'reports.view', 'dashboard.view', 'customers.view', 'customers.manage',
                'loyalty.manage', 'delivery.dispatch', 'riders.manage', 'aggregators.manage', 'sync.resolve', 'sms.manage',
            ]],
            'supervisor' => ['all_branches' => false, 'permissions' => [
                'admin.access', 'branches.view', 'menu.view', 'tables.view', 'tables.manage',
                'orders.view', 'orders.create', 'orders.void', 'orders.approve', 'discounts.override', 'payments.manage', 'shifts.manage',
                'pos.access', 'kds.access', 'customers.view', 'dashboard.view',
                'reservations.view', 'reservations.manage', 'preorders.view', 'preorders.manage',
            ]],
            'cashier' => ['all_branches' => false, 'permissions' => [
                'admin.access', 'branches.view', 'menu.view', 'orders.view', 'orders.create',
                'pos.access', 'shifts.manage', 'customers.view', 'loyalty.manage', 'dashboard.view',
                'reservations.view', 'reservations.manage', 'preorders.view', 'preorders.manage',
            ]],
            'waiter' => ['all_branches' => false, 'permissions' => [
                'admin.access', 'branches.view', 'menu.view', 'orders.view', 'orders.create',
                'dashboard.view', 'reservations.view', 'reservations.manage', 'preorders.view', 'preorders.manage',
            ]],
            'host' => ['all_branches' => false, 'permissions' => [
                'admin.access', 'branches.view', 'menu.view', 'tables.view', 'orders.view', 'customers.view', 'dashboard.view',
                'reservations.view', 'reservations.manage', 'preorders.view', 'preorders.manage',
            ]],
            'kitchen-manager' => ['all_branches' => false, 'permissions' => [
                'admin.access', 'branches.view', 'menu.view', 'orders.view', 'kds.access',
                'inventory.view', 'inventory.manage', 'stock.adjust', 'purchases.manage',
                'recipes.manage', 'wastage.log',
            ]],
            'store-keeper' => ['all_branches' => false, 'permissions' => [
                'admin.access', 'branches.view', 'menu.view', 'inventory.view', 'inventory.manage', 'stock.adjust',
                'purchases.manage', 'recipes.manage', 'wastage.log', 'transfers.manage',
            ]],
            'accountant' => ['all_branches' => true, 'permissions' => [
                'admin.access', 'branches.view', 'reports.view', 'dashboard.view',
                'accounting.view', 'journals.post', 'vouchers.manage', 'periods.lock', 'expenses.manage', 'payroll.manage', 'letters.manage',
            ]],
            'hr-manager' => ['all_branches' => false, 'permissions' => [
                'admin.access', 'branches.view', 'dashboard.view', 'staff.view', 'employees.manage',
                'attendance.manage', 'payroll.manage', 'letters.manage', 'careers.manage',
            ]],
            'marketing-manager' => ['all_branches' => true, 'permissions' => [
                'admin.access', 'branches.view', 'menu.view', 'media.view', 'media.manage', 'customers.view',
                'loyalty.manage', 'coupons.manage', 'sms.manage', 'blog.manage', 'dashboard.view', 'reports.view',
            ]],
            'delivery-manager' => ['all_branches' => false, 'permissions' => [
                'admin.access', 'branches.view', 'orders.view', 'customers.view', 'dashboard.view',
                'delivery.dispatch', 'riders.manage', 'aggregators.manage',
            ]],
            'rider' => ['all_branches' => false, 'permissions' => ['admin.access', 'branches.view']],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_keys(self::definitions());
    }

    public static function isDefault(string $name): bool
    {
        return array_key_exists($name, self::definitions());
    }

    public static function isLocked(string $name): bool
    {
        return in_array($name, self::LOCKED, true);
    }

    /**
     * @return array<int, string>
     */
    public static function permissionsFor(string $name): array
    {
        $definition = self::definitions()[$name] ?? null;

        if ($definition === null) {
            return [];
        }

        return $definition['permissions'] === '*' ? PermissionCatalog::all() : $definition['permissions'];
    }

    /**
     * Create every permission, and every standard role that is missing. With $overwrite the permissions of the
     * standard roles are set back to the defaults too (custom roles are never changed).
     */
    public static function install(bool $overwrite = false): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionCatalog::all() as $name) {
            Permission::findOrCreate($name);
        }

        $hasMeta = Schema::hasColumn('roles', 'all_branches');

        foreach (self::definitions() as $name => $definition) {
            $role = Role::query()->where('name', $name)->where('guard_name', 'staff')->first();
            $created = $role === null;
            $role ??= Role::findOrCreate($name);

            if ($created || $overwrite || self::isLocked($name)) {
                $role->syncPermissions(self::permissionsFor($name));

                if ($hasMeta) {
                    $role->forceFill(['all_branches' => $definition['all_branches']])->save();
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Put one standard role back to its default permissions.
     */
    public static function reset(Role $role): void
    {
        $definition = self::definitions()[$role->name] ?? null;

        if ($definition === null) {
            return;
        }

        $role->syncPermissions(self::permissionsFor($role->name));

        if (Schema::hasColumn('roles', 'all_branches')) {
            $role->forceFill(['all_branches' => $definition['all_branches']])->save();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Whether a standard role has been changed from its defaults.
     *
     * @param  array<int, string>  $current
     */
    public static function differsFromDefault(string $name, array $current): bool
    {
        if (! self::isDefault($name)) {
            return false;
        }

        $default = self::permissionsFor($name);

        sort($default);
        sort($current);

        return $default !== $current;
    }
}
