<?php

declare(strict_types=1);

/*
 * Sidebar navigation. Each item declares the permission required to see it, so
 * the menu and the router agree by construction.
 *
 * 'phase' marks items whose backend lands in a later phase; they render as
 * disabled with a "Coming in phase N" badge rather than 404-ing.
 */

return [
    [
        'label' => 'Dashboard',
        'icon'  => 'grid',
        'route' => '/dashboard',
        'permission' => null,
    ],
    [
        'label'      => 'CRM',
        'icon'       => 'users',
        'permission' => 'contacts.view',
        'children'   => [
            ['label' => 'Contacts',         'route' => '/contacts',          'permission' => 'contacts.view'],
            ['label' => 'Companies',        'route' => '/companies',         'permission' => 'contacts.view'],
            ['label' => 'Lists',            'route' => '/lists',             'permission' => 'contacts.view'],
            ['label' => 'Segments',         'route' => '/segments',          'permission' => 'contacts.view'],
            ['label' => 'Tags',             'route' => '/tags',              'permission' => 'contacts.view'],
            ['label' => 'Import',           'route' => '/contacts/import',   'permission' => 'contacts.import'],
            ['label' => 'Suppression list', 'route' => '/suppressions',      'permission' => 'compliance.manage'],
        ],
    ],
    [
        'label'      => 'Marketing',
        'icon'       => 'send',
        'permission' => 'campaigns.view',
        'children'   => [
            ['label' => 'Campaigns',         'route' => '/campaigns',        'permission' => 'campaigns.view'],
            ['label' => 'Templates',         'route' => '/templates',        'permission' => 'templates.manage'],
            ['label' => 'AI Campaign Studio', 'route' => '/ai/studio',       'permission' => 'ai.use',           'phase' => 3],
            ['label' => 'Content Library',   'route' => '/content-library',  'permission' => 'templates.manage', 'phase' => 6],
            ['label' => 'A/B Tests',         'route' => '/ab-tests',         'permission' => 'campaigns.view',   'phase' => 6],
        ],
    ],
    [
        'label'      => 'Automation',
        'icon'       => 'repeat',
        'permission' => 'automations.view',
        'children'   => [
            ['label' => 'Journeys',        'route' => '/automations',       'permission' => 'automations.view', 'phase' => 4],
            ['label' => 'Triggers',        'route' => '/automations/triggers', 'permission' => 'automations.view', 'phase' => 4],
            ['label' => 'Automation logs', 'route' => '/automations/logs',  'permission' => 'automations.view', 'phase' => 4],
        ],
    ],
    [
        'label'      => 'Lead management',
        'icon'       => 'target',
        'permission' => 'leads.view',
        'children'   => [
            ['label' => 'Leads',         'route' => '/leads',          'permission' => 'leads.view',   'phase' => 5],
            ['label' => 'Lead pipeline', 'route' => '/leads/pipeline', 'permission' => 'leads.view',   'phase' => 5],
            ['label' => 'Tasks',         'route' => '/tasks',          'permission' => 'leads.view',   'phase' => 5],
            ['label' => 'Lead recovery', 'route' => '/leads/recovery', 'permission' => 'leads.manage', 'phase' => 4],
        ],
    ],
    [
        'label'      => 'Forms',
        'icon'       => 'clipboard',
        'permission' => 'forms.manage',
        'children'   => [
            ['label' => 'Forms',          'route' => '/forms',          'permission' => 'forms.manage', 'phase' => 6],
            ['label' => 'Embedded forms', 'route' => '/forms/embedded', 'permission' => 'forms.manage', 'phase' => 6],
            ['label' => 'Landing pages',  'route' => '/landing-pages',  'permission' => 'forms.manage', 'phase' => 6],
        ],
    ],
    [
        'label'      => 'Analytics',
        'icon'       => 'bar-chart',
        'permission' => 'analytics.view',
        'children'   => [
            ['label' => 'Overview',            'route' => '/analytics',              'permission' => 'analytics.view'],
            ['label' => 'Campaign performance', 'route' => '/analytics/campaigns',   'permission' => 'analytics.view', 'phase' => 2],
            ['label' => 'Customer engagement', 'route' => '/analytics/engagement',   'permission' => 'analytics.view', 'phase' => 2],
            ['label' => 'Conversion',          'route' => '/analytics/conversion',   'permission' => 'analytics.view', 'phase' => 5],
            ['label' => 'Revenue attribution', 'route' => '/analytics/revenue',      'permission' => 'analytics.view', 'phase' => 5],
            ['label' => 'Deliverability',      'route' => '/analytics/deliverability', 'permission' => 'analytics.view', 'phase' => 2],
        ],
    ],
    [
        'label'      => 'AI',
        'icon'       => 'sparkles',
        'permission' => 'ai.use',
        'children'   => [
            ['label' => 'AI Assistant',      'route' => '/ai/assistant',       'permission' => 'ai.use', 'phase' => 3],
            ['label' => 'Recommendations',   'route' => '/ai/recommendations', 'permission' => 'ai.use', 'phase' => 3],
            ['label' => 'Customer segments', 'route' => '/ai/segments',        'permission' => 'ai.use', 'phase' => 3],
            ['label' => 'Campaign insights', 'route' => '/ai/insights',        'permission' => 'ai.use', 'phase' => 3],
        ],
    ],
    [
        'label'      => 'Integrations',
        'icon'       => 'plug',
        'route'      => '/integrations',
        'permission' => 'integrations.manage',
        'phase'      => 6,
    ],
    [
        'label'      => 'Compliance',
        'icon'       => 'shield',
        'route'      => '/compliance',
        'permission' => 'compliance.manage',
    ],
    [
        'label'      => 'Team',
        'icon'       => 'user-plus',
        'route'      => '/team',
        'permission' => 'users.manage',
    ],
    [
        'label'      => 'Settings',
        'icon'       => 'settings',
        'permission' => 'settings.manage',
        'children'   => [
            ['label' => 'General',        'route' => '/settings',                'permission' => 'settings.manage'],
            ['label' => 'Brand profile',  'route' => '/settings/brand',          'permission' => 'settings.manage'],
            ['label' => 'Sending domains', 'route' => '/settings/domains',       'permission' => 'settings.manage'],
            ['label' => 'Custom fields',  'route' => '/settings/custom-fields',  'permission' => 'settings.manage'],
        ],
    ],
    [
        'label'      => 'Billing',
        'icon'       => 'credit-card',
        'route'      => '/billing',
        'permission' => 'billing.manage',
    ],
];
