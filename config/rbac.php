<?php

declare(strict_types=1);

/*
 * Role/permission matrix.
 *
 * Application code NEVER branches on a role name. It asks
 * $auth->can('campaigns.send'). Roles are rows in the database seeded from this
 * file, so an organisation can later be given a custom role without a code
 * change.
 */

return [
    'platform_roles' => [
        'SUPER_ADMIN' => 'Platform super administrator',
    ],

    'roles' => [
        'OWNER'             => 'Owner',
        'ADMIN'             => 'Administrator',
        'MARKETING_MANAGER' => 'Marketing manager',
        'MARKETER'          => 'Marketer',
        'SALES'             => 'Sales',
        'APPROVER'          => 'Approver',
        'ANALYST'           => 'Analyst',
        'VIEWER'            => 'Viewer',
    ],

    'permissions' => [
        // CRM
        'contacts.view'        => 'View contacts',
        'contacts.create'      => 'Create contacts',
        'contacts.edit'        => 'Edit contacts',
        'contacts.delete'      => 'Delete contacts',
        'contacts.import'      => 'Import contacts',
        'contacts.export'      => 'Export contacts',
        // Marketing
        'campaigns.view'       => 'View campaigns',
        'campaigns.create'     => 'Create campaigns',
        'campaigns.edit'       => 'Edit campaigns',
        'campaigns.approve'    => 'Approve campaigns',
        'campaigns.send'       => 'Send campaigns',
        'templates.manage'     => 'Manage templates',
        // Automation
        'automations.view'     => 'View automations',
        'automations.create'   => 'Create automations',
        'automations.edit'     => 'Edit automations',
        'automations.activate' => 'Activate automations',
        // Leads
        'leads.view'           => 'View leads',
        'leads.manage'         => 'Manage leads',
        // Insight
        'analytics.view'       => 'View analytics',
        'ai.use'               => 'Use AI features',
        // Administration
        'settings.manage'      => 'Manage settings',
        'billing.manage'       => 'Manage billing',
        'users.manage'         => 'Manage team members',
        'integrations.manage'  => 'Manage integrations',
        'compliance.manage'    => 'Manage compliance and suppression',
        'forms.manage'         => 'Manage forms and landing pages',
    ],

    /*
     * '*' means every permission. Note what is deliberately withheld:
     *  - MARKETER can create and edit campaigns but cannot approve or send.
     *  - APPROVER can approve but cannot author, which keeps the review real.
     *  - Only OWNER/ADMIN touch compliance and suppression.
     */
    'role_permissions' => [
        'OWNER' => ['*'],

        'ADMIN' => [
            'contacts.view', 'contacts.create', 'contacts.edit', 'contacts.delete',
            'contacts.import', 'contacts.export',
            'campaigns.view', 'campaigns.create', 'campaigns.edit', 'campaigns.approve', 'campaigns.send',
            'templates.manage',
            'automations.view', 'automations.create', 'automations.edit', 'automations.activate',
            'leads.view', 'leads.manage',
            'analytics.view', 'ai.use',
            'settings.manage', 'users.manage', 'integrations.manage', 'compliance.manage',
            'forms.manage',
        ],

        'MARKETING_MANAGER' => [
            'contacts.view', 'contacts.create', 'contacts.edit', 'contacts.import', 'contacts.export',
            'campaigns.view', 'campaigns.create', 'campaigns.edit', 'campaigns.approve', 'campaigns.send',
            'templates.manage',
            'automations.view', 'automations.create', 'automations.edit', 'automations.activate',
            'leads.view', 'leads.manage',
            'analytics.view', 'ai.use',
            'forms.manage',
        ],

        'MARKETER' => [
            'contacts.view', 'contacts.create', 'contacts.edit', 'contacts.import',
            'campaigns.view', 'campaigns.create', 'campaigns.edit',
            'templates.manage',
            'automations.view', 'automations.create', 'automations.edit',
            'analytics.view', 'ai.use',
            'forms.manage',
        ],

        'SALES' => [
            'contacts.view', 'contacts.create', 'contacts.edit',
            'leads.view', 'leads.manage',
            'campaigns.view',
            'analytics.view',
        ],

        'APPROVER' => [
            'contacts.view',
            'campaigns.view', 'campaigns.approve',
            'analytics.view',
        ],

        'ANALYST' => [
            'contacts.view', 'contacts.export',
            'campaigns.view',
            'automations.view',
            'leads.view',
            'analytics.view', 'ai.use',
        ],

        'VIEWER' => [
            'contacts.view',
            'campaigns.view',
            'automations.view',
            'leads.view',
            'analytics.view',
        ],
    ],
];
