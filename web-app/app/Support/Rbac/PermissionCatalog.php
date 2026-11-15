<?php

declare(strict_types=1);

namespace App\Support\Rbac;

/**
 * Every permission the system checks, grouped by area of the restaurant. The role screen draws its
 * checkboxes from this list, and a role can only be given permissions that appear here.
 */
final class PermissionCatalog
{
    /** Needed to sign in to the admin panel at all, so every role keeps it. */
    public const REQUIRED = 'admin.access';

    /**
     * @return array<string, array<int, string>>
     */
    public static function groups(): array
    {
        return [
            'access' => ['admin.access'],
            'sales' => ['pos.access', 'orders.view', 'orders.create', 'orders.void', 'orders.approve', 'discounts.override', 'payments.manage', 'shifts.manage'],
            'kitchen' => ['kds.access'],
            'menu' => ['menu.view', 'menu.manage', 'media.view', 'media.manage'],
            'floor' => ['tables.view', 'tables.manage', 'reservations.view', 'reservations.manage', 'preorders.view', 'preorders.manage'],
            'customers' => ['customers.view', 'customers.manage', 'loyalty.manage', 'coupons.manage', 'sms.manage'],
            'inventory' => ['inventory.view', 'inventory.manage', 'stock.adjust', 'purchases.manage', 'recipes.manage', 'wastage.log', 'wastage.approve', 'transfers.manage'],
            'delivery' => ['delivery.dispatch', 'riders.manage', 'aggregators.manage'],
            'people' => ['staff.view', 'staff.manage', 'employees.manage', 'attendance.manage', 'payroll.manage', 'letters.manage', 'careers.manage'],
            'finance' => ['accounting.view', 'journals.post', 'vouchers.manage', 'periods.lock', 'expenses.manage'],
            'reports' => ['reports.view', 'dashboard.view', 'activity.view'],
            'website' => ['blog.manage'],
            'system' => ['branches.view', 'branches.manage', 'settings.manage', 'sync.resolve', 'roles.manage'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_values(array_merge(...array_values(self::groups())));
    }

    public static function isKnown(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }
}
