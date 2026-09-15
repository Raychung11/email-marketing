<?php

declare(strict_types=1);

return [
    // Product name is configurable so the platform can be rebranded (e.g. to
    // Aiwance) without touching code or templates.
    'name'     => env('APP_NAME', 'AI Growth Hub'),
    'env'      => env('APP_ENV', 'production'),
    'debug'    => (bool) env('APP_DEBUG', false),
    'url'      => rtrim((string) env('APP_URL', 'http://localhost'), '/'),
    'key'      => (string) env('APP_KEY', ''),

    // Storage timezone. Never change this: all persisted timestamps are UTC and
    // conversion happens at the display edge using organisations.timezone.
    'timezone' => 'UTC',
    'locale'   => env('APP_LOCALE', 'en'),

    'force_https' => (bool) env('FORCE_HTTPS', false),

    'supported_currencies' => ['USD', 'AUD', 'NZD', 'GBP', 'EUR', 'CAD'],
    'default_currency'     => 'USD',

    // Launch markets. Additional countries only need a compliance rule set.
    'supported_countries' => [
        'US' => 'United States',
        'AU' => 'Australia',
        'MY' => 'Malaysia',
        'NZ' => 'New Zealand',
        'GB' => 'United Kingdom',
        'CA' => 'Canada',
    ],

    'industries' => [
        'plumbing'              => 'Plumbing',
        'hvac'                  => 'HVAC',
        'electrical'            => 'Electrical contracting',
        'dental'                => 'Dental clinic',
        'healthcare'            => 'Healthcare services',
        'renovation'            => 'Renovation',
        'interior_design'       => 'Interior design',
        'home_services'         => 'Home services',
        'property_services'     => 'Property services',
        'automotive'            => 'Automotive services',
        'tourism'               => 'Tourism',
        'hospitality'           => 'Hospitality',
        'restaurant'            => 'Restaurant',
        'professional_services' => 'Professional services',
        'retail'                => 'Retail',
        'ecommerce'             => 'E-commerce',
        'other'                 => 'Other',
    ],

    'pagination' => ['per_page' => 25, 'max_per_page' => 200],
];
