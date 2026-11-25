<?php

/*
|--------------------------------------------------------------------------
| LBMIS roles, permissions and modules
|--------------------------------------------------------------------------
|
| Roles and permissions are created from this file by
| `php artisan lbmis:sync-permissions`, which every deploy runs.
| To change who can do what, edit this file and deploy.
|
| outlet_scope decides which outlets a role can see:
|   all      - every outlet
|   assigned - only outlets the user is assigned to (Users page)
|   owned    - only outlets of the user's franchisee company
|
*/

return [

    'roles' => [
        'admin' => [
            'label' => 'Admin',
            'outlet_scope' => 'all',
            'permissions' => ['*'],
        ],
        'manager' => [
            'label' => 'Manager',
            'outlet_scope' => 'all',
            'permissions' => [
                'outlets.view', 'outlets.manage',
                'franchisees.view', 'franchisees.manage',
                'subscriptions.manage',
            ],
        ],
        'admin-staff' => [
            'label' => 'Admin staff',
            'outlet_scope' => 'all',
            'permissions' => [
                'outlets.view', 'outlets.manage',
                'franchisees.view', 'franchisees.manage',
            ],
        ],
        'staff' => [
            'label' => 'Staff',
            'outlet_scope' => 'assigned',
            'permissions' => [
                'outlets.view',
            ],
        ],
        'franchise-owner' => [
            'label' => 'Franchise owner',
            'outlet_scope' => 'owned',
            'permissions' => [
                'outlets.view',
                'subscription.view-own',
            ],
        ],
    ],

    /*
    | Every permission the system knows. "*" in a role above means all of these.
    */
    'permissions' => [
        'users.manage' => 'Create, edit and deactivate users',
        'departments.manage' => 'Manage departments',
        'franchisees.view' => 'View franchisee companies',
        'franchisees.manage' => 'Create and edit franchisee companies',
        'outlets.view' => 'View outlets (limited by outlet scope)',
        'outlets.manage' => 'Create and edit outlets',
        'plans.manage' => 'Manage subscription plans',
        'subscriptions.manage' => 'Assign plans to franchisees',
        'subscription.view-own' => 'View own subscription (franchise owners)',
    ],

    /*
    | Modules that can be sold to franchise owners in a plan.
    | subscription => true: franchise owners need an active plan that includes it.
    | Internal roles are never limited by plans, only by their permissions.
    */
    'modules' => [
        'outlets' => ['label' => 'Outlet profile', 'subscription' => false],
        'documents' => ['label' => 'Documents & licence reminders', 'subscription' => true],
        'tickets' => ['label' => 'Maintenance tickets', 'subscription' => true],
        'reports' => ['label' => 'Reports & analytics', 'subscription' => true],
    ],

];
