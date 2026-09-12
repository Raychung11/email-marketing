<?php

declare(strict_types=1);

/*
 * Segment field registry.
 *
 * The segment compiler will ONLY build SQL for fields listed here, with
 * operators listed here. A rule referencing anything else is rejected. This is
 * what makes it safe to accept a segment definition produced by the AI or
 * posted by a browser: the payload is data, never SQL, and the field whitelist
 * is the boundary.
 */

return [
    'operators' => [
        'equals'         => '=',
        'not_equals'     => '!=',
        'contains'       => 'like',
        'not_contains'   => 'not like',
        'greater_than'   => '>',
        'less_than'      => '<',
        'gte'            => '>=',
        'lte'            => '<=',
        'before'         => '<',
        'after'          => '>',
        'between'        => 'between',
        'exists'         => 'is not null',
        'not_exists'     => 'is null',
        'in'             => 'in',
        'not_in'         => 'not in',
    ],

    'operators_by_type' => [
        'string'   => ['equals', 'not_equals', 'contains', 'not_contains', 'in', 'not_in', 'exists', 'not_exists'],
        'number'   => ['equals', 'not_equals', 'greater_than', 'less_than', 'gte', 'lte', 'between', 'exists', 'not_exists'],
        'money'    => ['equals', 'not_equals', 'greater_than', 'less_than', 'gte', 'lte', 'between', 'exists', 'not_exists'],
        'date'     => ['before', 'after', 'between', 'exists', 'not_exists'],
        'boolean'  => ['equals'],
        'enum'     => ['equals', 'not_equals', 'in', 'not_in'],
        'relation' => ['in', 'not_in', 'exists', 'not_exists'],
    ],

    /*
     * column = a real column on contacts
     * relation = resolved with a correlated EXISTS subquery (never a join, so
     *            row counts stay correct)
     */
    'fields' => [
        'email'            => ['type' => 'string', 'column' => 'contacts.email_normalized', 'label' => 'Email'],
        'first_name'       => ['type' => 'string', 'column' => 'contacts.first_name',      'label' => 'First name'],
        'last_name'        => ['type' => 'string', 'column' => 'contacts.last_name',       'label' => 'Last name'],
        'company'          => ['type' => 'string', 'column' => 'contacts.company',         'label' => 'Company'],
        'job_title'        => ['type' => 'string', 'column' => 'contacts.job_title',       'label' => 'Job title'],
        'phone'            => ['type' => 'string', 'column' => 'contacts.phone',           'label' => 'Phone'],
        'country'          => ['type' => 'enum',   'column' => 'contacts.country',         'label' => 'Country'],
        'state'            => ['type' => 'string', 'column' => 'contacts.state',           'label' => 'State / region'],
        'city'             => ['type' => 'string', 'column' => 'contacts.city',            'label' => 'City'],
        'postcode'         => ['type' => 'string', 'column' => 'contacts.postcode',        'label' => 'Postcode'],
        'source'           => ['type' => 'enum',   'column' => 'contacts.source',          'label' => 'Source'],
        'customer_status'  => ['type' => 'enum',   'column' => 'contacts.customer_status', 'label' => 'Customer status'],
        'lifecycle_stage'  => ['type' => 'enum',   'column' => 'contacts.lifecycle_stage', 'label' => 'Lifecycle stage'],
        'lead_status'      => ['type' => 'enum',   'column' => 'contacts.lead_status',     'label' => 'Lead status'],
        'lead_score'       => ['type' => 'number', 'column' => 'contacts.lead_score',      'label' => 'Lead score'],
        'customer_value'   => ['type' => 'money',  'column' => 'contacts.customer_value',  'label' => 'Customer value'],
        'total_revenue'    => ['type' => 'money',  'column' => 'contacts.total_revenue',   'label' => 'Total revenue'],
        'purchase_count'   => ['type' => 'number', 'column' => 'contacts.purchase_count',  'label' => 'Purchase count'],
        'last_purchase'    => ['type' => 'date',   'column' => 'contacts.last_purchase_at', 'label' => 'Last purchase'],
        'last_contact'     => ['type' => 'date',   'column' => 'contacts.last_contact_at',  'label' => 'Last contacted'],
        'last_engagement'  => ['type' => 'date',   'column' => 'contacts.last_engagement_at', 'label' => 'Last engagement'],
        'last_email_open'  => ['type' => 'date',   'column' => 'contacts.last_email_open_at', 'label' => 'Last email open'],
        'last_email_click' => ['type' => 'date',   'column' => 'contacts.last_email_click_at', 'label' => 'Last email click'],
        'created_at'       => ['type' => 'date',   'column' => 'contacts.created_at',      'label' => 'Created'],

        'tag' => [
            'type'     => 'relation',
            'label'    => 'Tag',
            'relation' => [
                'table'      => 'contact_tags',
                'foreign'    => 'contact_id',
                'match'      => 'tag_id',
                'tenant_key' => 'organisation_id',
            ],
        ],

        'list' => [
            'type'     => 'relation',
            'label'    => 'List',
            'relation' => [
                'table'      => 'list_contacts',
                'foreign'    => 'contact_id',
                'match'      => 'list_id',
                'tenant_key' => 'organisation_id',
            ],
        ],

        'marketing_consent' => [
            'type'  => 'boolean',
            'label' => 'Marketing consent (email)',
            'special' => 'marketing_consent',
        ],

        'suppressed' => [
            'type'    => 'boolean',
            'label'   => 'Suppressed',
            'special' => 'suppressed',
        ],

        'custom_field' => [
            'type'    => 'string',
            'label'   => 'Custom field',
            'special' => 'custom_field',
        ],
    ],

    'max_rules'        => 50,
    'max_nesting_depth' => 5,

    // Segment counts are expensive on large tables; cached and refreshed by cron.
    'count_cache_ttl'  => 900,
];
