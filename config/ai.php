<?php

declare(strict_types=1);

return [
    // Providers are interchangeable behind App\AI\AiProviderInterface.
    'provider' => env('AI_PROVIDER', 'openai'),

    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY', ''),
            'model'   => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'timeout' => (int) env('OPENAI_TIMEOUT', 60),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        ],
    ],

    'monthly_token_cap' => (int) env('AI_MONTHLY_TOKEN_CAP', 2_000_000),

    'tones' => [
        'professional'   => 'Professional',
        'friendly'       => 'Friendly',
        'premium'        => 'Premium',
        'urgent'         => 'Urgent',
        'conversational' => 'Conversational',
        'educational'    => 'Educational',
        'short'          => 'Short',
        'sales'          => 'Sales-focused',
    ],

    'campaign_goals' => [
        'promote_offer'       => 'Promote an offer',
        'reconnect_customers' => 'Reconnect with past customers',
        'announce_news'       => 'Announce news',
        'generate_leads'      => 'Generate leads',
        'request_reviews'     => 'Request reviews',
        'seasonal'            => 'Seasonal campaign',
        'educate'             => 'Educate customers',
    ],

    /*
     * Hard safety boundaries. These are enforced in code (see
     * App\AI\AiGuard), not merely requested in a prompt.
     */
    'guardrails' => [
        'may_send_campaign'        => false,
        'may_change_consent'       => false,
        'may_remove_suppression'   => false,
        'may_change_billing'       => false,
        'may_bypass_approval'      => false,
        'may_write_raw_sql'        => false,
        'may_invent_customer_data' => false,
        'may_invent_revenue'       => false,
        'must_label_output'        => true,
    ],

    // Claims the AI must never generate unsupported. Regulated verticals
    // (dental, healthcare, financial, legal) get an extra review gate.
    'restricted_claim_industries' => ['dental', 'healthcare'],
];
