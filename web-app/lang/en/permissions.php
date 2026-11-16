<?php

declare(strict_types=1);

return [
    'groups' => [
        'access' => 'Panel access',
        'sales' => 'Sales and POS',
        'kitchen' => 'Kitchen',
        'menu' => 'Menu and media',
        'floor' => 'Tables, reservations and pre-orders',
        'customers' => 'Customers and marketing',
        'inventory' => 'Inventory and purchasing',
        'delivery' => 'Delivery',
        'people' => 'Staff and HR',
        'finance' => 'Accounting',
        'reports' => 'Reports',
        'website' => 'Website content',
        'system' => 'System',
    ],
    'items' => [
        'admin' => [
            'access' => 'Sign in to the admin panel',
        ],
        'pos' => [
            'access' => 'Use the POS',
        ],
        'orders' => [
            'view' => 'View orders',
            'create' => 'Create and edit orders',
            'void' => 'Delete or void orders',
            'approve' => 'Approve guest orders and send pre-orders to the kitchen',
        ],
        'discounts' => [
            'override' => 'Give discounts above the limit',
        ],
        'payments' => [
            'manage' => 'Verify and manage payments',
        ],
        'shifts' => [
            'manage' => 'Open and close cash shifts',
        ],
        'kds' => [
            'access' => 'Use the kitchen display',
        ],
        'menu' => [
            'view' => 'View the menu',
            'manage' => 'Edit the menu',
        ],
        'media' => [
            'view' => 'View photos and videos',
            'manage' => 'Upload and edit photos and videos',
        ],
        'tables' => [
            'view' => 'View tables',
            'manage' => 'Edit tables and QR codes',
        ],
        'reservations' => [
            'view' => 'View reservations',
            'manage' => 'Take and change reservations',
        ],
        'preorders' => [
            'view' => 'View pre-orders',
            'manage' => 'Take and change pre-orders',
        ],
        'customers' => [
            'view' => 'View customers',
            'manage' => 'Edit customers',
        ],
        'loyalty' => [
            'manage' => 'Manage loyalty points',
        ],
        'coupons' => [
            'manage' => 'Manage coupons',
        ],
        'sms' => [
            'manage' => 'Send and manage SMS',
        ],
        'inventory' => [
            'view' => 'View stock',
            'manage' => 'Edit ingredients and suppliers',
        ],
        'stock' => [
            'adjust' => 'Adjust stock and count stock',
        ],
        'purchases' => [
            'manage' => 'Record purchases',
        ],
        'recipes' => [
            'manage' => 'Edit recipes',
        ],
        'wastage' => [
            'log' => 'Log wastage',
            'approve' => 'Approve wastage',
        ],
        'transfers' => [
            'manage' => 'Move stock between branches',
        ],
        'delivery' => [
            'dispatch' => 'Dispatch deliveries',
        ],
        'riders' => [
            'manage' => 'Manage riders',
        ],
        'aggregators' => [
            'manage' => 'Manage delivery apps',
        ],
        'staff' => [
            'view' => 'View staff',
            'manage' => 'Create and edit staff accounts',
        ],
        'employees' => [
            'manage' => 'Manage employees',
        ],
        'attendance' => [
            'manage' => 'Manage attendance',
        ],
        'payroll' => [
            'manage' => 'Run payroll and advances',
        ],
        'letters' => [
            'manage' => 'Write letters',
        ],
        'careers' => [
            'manage' => 'Manage job openings',
        ],
        'accounting' => [
            'view' => 'View the books',
        ],
        'journals' => [
            'post' => 'Post journal entries',
        ],
        'vouchers' => [
            'manage' => 'Manage vouchers',
        ],
        'periods' => [
            'lock' => 'Lock accounting periods',
        ],
        'expenses' => [
            'manage' => 'Record expenses',
        ],
        'reports' => [
            'view' => 'View reports',
        ],
        'dashboard' => [
            'view' => 'View dashboard sales figures',
        ],
        'activity' => [
            'view' => 'View the activity log',
        ],
        'blog' => [
            'manage' => 'Write blog posts',
        ],
        'branches' => [
            'view' => 'View branches',
            'manage' => 'Edit branches',
        ],
        'settings' => [
            'manage' => 'Change settings',
        ],
        'sync' => [
            'resolve' => 'Resolve sync conflicts',
        ],
        'roles' => [
            'manage' => 'Manage roles and permissions',
        ],
    ],
    'roles' => [
        'super-admin' => [
            'name' => 'Super admin',
            'description' => 'Full access to everything, always. Cannot be changed.',
        ],
        'owner' => [
            'name' => 'Owner',
            'description' => 'Full access to every branch and setting. Cannot be changed.',
        ],
        'branch-manager' => [
            'name' => 'Branch manager',
            'description' => 'Runs one branch: orders, menu, stock, staff, approvals and reports.',
        ],
        'supervisor' => [
            'name' => 'Supervisor',
            'description' => 'Floor or shift lead: POS, approvals, voids, discounts and reservations.',
        ],
        'cashier' => [
            'name' => 'Cashier',
            'description' => 'Takes orders and payments at the POS and runs the cash shift.',
        ],
        'waiter' => [
            'name' => 'Waiter',
            'description' => 'Takes table orders and reservations.',
        ],
        'host' => [
            'name' => 'Host',
            'description' => 'Front desk: reservations, pre-orders and seating.',
        ],
        'kitchen-manager' => [
            'name' => 'Kitchen manager',
            'description' => 'Head chef: kitchen display, recipes, stock and purchasing.',
        ],
        'store-keeper' => [
            'name' => 'Store keeper',
            'description' => 'Stock, purchases, recipes, wastage and transfers.',
        ],
        'accountant' => [
            'name' => 'Accountant',
            'description' => 'Books, vouchers, expenses, payroll and reports for every branch.',
        ],
        'hr-manager' => [
            'name' => 'HR manager',
            'description' => 'Employees, attendance, payroll, letters and job openings.',
        ],
        'marketing-manager' => [
            'name' => 'Marketing manager',
            'description' => 'Coupons, loyalty, SMS, photos and blog for every branch.',
        ],
        'delivery-manager' => [
            'name' => 'Delivery manager',
            'description' => 'Dispatch, riders and delivery apps.',
        ],
        'rider' => [
            'name' => 'Rider',
            'description' => 'Delivery rider. Sees only their own deliveries.',
        ],
    ],
];
