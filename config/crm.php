<?php

declare(strict_types=1);

return [
    'customer_statuses' => [
        'lead'            => 'Lead',
        'prospect'        => 'Prospect',
        'customer'        => 'Customer',
        'repeat_customer' => 'Repeat customer',
        'vip'             => 'VIP',
        'inactive'        => 'Inactive',
        'lost'            => 'Lost',
    ],

    'lifecycle_stages' => [
        'subscriber'         => 'Subscriber',
        'lead'               => 'Lead',
        'marketing_qualified' => 'Marketing qualified',
        'sales_qualified'    => 'Sales qualified',
        'opportunity'        => 'Opportunity',
        'customer'           => 'Customer',
        'repeat_customer'    => 'Repeat customer',
        'inactive'           => 'Inactive',
    ],

    'lead_statuses' => [
        'new'         => 'New',
        'contacted'   => 'Contacted',
        'qualified'   => 'Qualified',
        'quotation'   => 'Quotation',
        'negotiation' => 'Negotiation',
        'won'         => 'Won',
        'lost'        => 'Lost',
    ],

    'default_pipeline_stages' => [
        ['key' => 'new',         'label' => 'New',         'probability' => 10,  'is_won' => false, 'is_lost' => false],
        ['key' => 'contacted',   'label' => 'Contacted',   'probability' => 25,  'is_won' => false, 'is_lost' => false],
        ['key' => 'qualified',   'label' => 'Qualified',   'probability' => 40,  'is_won' => false, 'is_lost' => false],
        ['key' => 'quotation',   'label' => 'Quotation',   'probability' => 60,  'is_won' => false, 'is_lost' => false],
        ['key' => 'negotiation', 'label' => 'Negotiation', 'probability' => 80,  'is_won' => false, 'is_lost' => false],
        ['key' => 'won',         'label' => 'Won',         'probability' => 100, 'is_won' => true,  'is_lost' => false],
        ['key' => 'lost',        'label' => 'Lost',        'probability' => 0,   'is_won' => false, 'is_lost' => true],
    ],

    'contact_sources' => [
        'website_form' => 'Website form',
        'landing_page' => 'Landing page',
        'import'       => 'CSV import',
        'api'          => 'API',
        'manual'       => 'Manual entry',
        'pos'          => 'Point of sale',
        'integration'  => 'Integration',
        'event'        => 'Event',
        'referral'     => 'Referral',
        'google_ads'   => 'Google Ads',
        'meta_ads'     => 'Meta Ads',
        'organic'      => 'Organic search',
        'other'        => 'Other',
    ],

    'custom_field_types' => [
        'text'         => 'Text',
        'number'       => 'Number',
        'date'         => 'Date',
        'boolean'      => 'Yes / No',
        'select'       => 'Select (one)',
        'multi_select' => 'Select (many)',
    ],

    /*
     * V1 lead scoring. Organisations can override every value; predictive
     * scoring arrives in a later phase and will sit alongside, not replace,
     * these observable rules.
     */
    'lead_scoring' => [
        'email_open'          => 1,
        'email_click'         => 3,
        'form_submission'     => 5,
        'website_enquiry'     => 10,
        'purchase'            => 20,
        'recent_activity'     => 5,
        'inactive_90_days'    => -5,
        'unsubscribed'        => 0,   // not negative: it removes eligibility, not worth
    ],

    // Retention segments offered out of the box on a new organisation.
    'retention_segment_templates' => [
        'inactive_30'        => 'Inactive 30 days',
        'inactive_90'        => 'Inactive 90 days',
        'inactive_180'       => 'Inactive 180 days',
        'first_time_buyer'   => 'First-time customer',
        'repeat_customer'    => 'Repeat customer',
        'vip'                => 'VIP',
        'high_value'         => 'High-value customer',
        'at_risk'            => 'At-risk customer',
        'recent_purchaser'   => 'Recent purchaser',
        'clicked_no_purchase' => 'Clicked but did not purchase',
        'opened_no_click'    => 'Opened but did not click',
        'lead_no_response'   => 'Lead with no response',
    ],

    'deduplication' => [
        // Contacts are unique per organisation on normalized email.
        'key'               => 'email_normalized',
        'allow_duplicates'  => false,
        'merge_strategies'  => ['update_existing', 'skip_existing', 'merge_fill_blanks'],
    ],
];
