<?php

declare(strict_types=1);

/*
 * Plan architecture. No payment provider is wired up yet by design — the core
 * system comes first — but usage is metered from day one so that turning
 * billing on later is a integration, not a migration.
 */

return [
    'default' => 'starter',

    'plans' => [
        'starter' => [
            'name'        => 'Starter',
            'description' => 'CRM, contacts, campaigns, templates, AI writer and basic analytics.',
            'price'       => ['USD' => 4900, 'AUD' => 7900], // minor units
            'interval'    => 'month',
            'limits'      => [
                'contacts'         => 2_500,
                'emails_per_month' => 15_000,
                'users'            => 3,
                'automations'      => 0,
                'ai_tokens'        => 200_000,
                'sending_domains'  => 1,
                'workspaces'       => 1,
            ],
            'features' => [
                'crm', 'contacts', 'lists', 'tags', 'campaigns', 'templates',
                'ai_writer', 'basic_analytics', 'forms',
            ],
        ],

        'growth' => [
            'name'        => 'Growth',
            'description' => 'Everything in Starter plus automation, advanced segmentation, lead recovery, conversion tracking and A/B testing.',
            'price'       => ['USD' => 14900, 'AUD' => 22900],
            'interval'    => 'month',
            'limits'      => [
                'contacts'         => 25_000,
                'emails_per_month' => 150_000,
                'users'            => 10,
                'automations'      => 25,
                'ai_tokens'        => 1_000_000,
                'sending_domains'  => 3,
                'workspaces'       => 3,
            ],
            'features' => [
                'crm', 'contacts', 'lists', 'tags', 'campaigns', 'templates',
                'ai_writer', 'basic_analytics', 'forms',
                'automation', 'advanced_segmentation', 'lead_recovery',
                'conversion_tracking', 'ab_testing', 'landing_pages',
            ],
        ],

        'ai_growth' => [
            'name'        => 'AI Growth',
            'description' => 'Everything in Growth plus AI segmentation, AI insights, AI recommendations, lead scoring and advanced BI.',
            'price'       => ['USD' => 34900, 'AUD' => 52900],
            'interval'    => 'month',
            'limits'      => [
                'contacts'         => 100_000,
                'emails_per_month' => 750_000,
                'users'            => 25,
                'automations'      => 200,
                'ai_tokens'        => 5_000_000,
                'sending_domains'  => 10,
                'workspaces'       => 10,
            ],
            'features' => [
                'crm', 'contacts', 'lists', 'tags', 'campaigns', 'templates',
                'ai_writer', 'basic_analytics', 'forms',
                'automation', 'advanced_segmentation', 'lead_recovery',
                'conversion_tracking', 'ab_testing', 'landing_pages',
                'ai_segmentation', 'ai_insights', 'ai_recommendations',
                'lead_scoring', 'advanced_bi', 'revenue_attribution',
            ],
        ],
    ],

    // Metered dimensions recorded in usage_records.
    'metered' => ['contacts', 'emails_sent', 'ai_tokens', 'users', 'automations', 'sms', 'whatsapp'],
];
