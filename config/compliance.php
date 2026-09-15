<?php

declare(strict_types=1);

/*
 * Country compliance rule sets.
 *
 * These are seeded into the versioned `compliance_rules` table. The service
 * reads the table, not this file, at runtime — this is only the default
 * configuration. Rules are versioned because regulation changes and a
 * historical send must be explainable with the rules in force at the time.
 *
 * This is compliance-supporting configuration. It is not legal advice.
 */

return [
    'default_country' => 'US',

    // What happens to a contact whose consent state is 'unknown'.
    'unknown_consent_default' => 'block',

    'countries' => [
        'AU' => [
            'label'       => 'Australia',
            'rule_code'   => 'AU_MARKETING_CONSENT',
            'version'     => 1,
            'require_consent_basis' => true,
            'acceptable_consent_types' => [
                'express',
                'legitimate_existing_relationship',
            ],
            'allow_inferred'            => false,
            'relationship_max_age_days' => 730,
            'block_reason'              => 'AU_CONSENT_UNKNOWN',
            'block_message'             => 'Marketing consent has not been established for this Australian contact.',
            'require_sender_identity'   => true,
            'require_contact_details'   => true,
            'require_unsubscribe'       => true,
            'require_postal_address'    => false,
        ],

        /*
         * Malaysia — Personal Data Protection Act 2010.
         *
         * Consent is the basis for processing personal data (s.6), and s.43 gives
         * a person the right to require you to stop processing their data for
         * direct marketing. So: permission before sending, and an opt-out that is
         * honoured absolutely. There is no equivalent of the US postal-address
         * requirement, so that stays a warning rather than a block.
         *
         * This encodes a careful reading, not legal advice. If you operate at
         * scale in Malaysia, have a lawyer check it.
         */
        'MY' => [
            'label'       => 'Malaysia',
            'rule_code'   => 'MY_PDPA_MARKETING',
            'version'     => 1,
            'require_consent_basis' => true,
            'acceptable_consent_types' => [
                'express',
                'legitimate_existing_relationship',
            ],
            'allow_inferred'            => false,
            'relationship_max_age_days' => 730,
            'block_reason'              => 'MY_CONSENT_UNKNOWN',
            'block_message'             => 'Marketing consent has not been established for this Malaysian contact.',
            'require_sender_identity'   => true,
            'require_contact_details'   => true,
            'require_unsubscribe'       => true,
            'require_postal_address'    => false,
        ],

        'US' => [
            'label'       => 'United States',
            'rule_code'   => 'US_MARKETING_REQUIREMENTS',
            'version'     => 1,
            // The US model is opt-out: consent is not a precondition, but
            // honouring opt-outs and identifying the sender are.
            'require_consent_basis'     => false,
            'acceptable_consent_types'  => ['express', 'inferred', 'legitimate_existing_relationship', 'other'],
            'allow_inferred'            => true,
            'block_reason'              => 'US_OPTED_OUT',
            'require_sender_identity'   => true,
            'require_contact_details'   => true,
            'require_unsubscribe'       => true,
            'require_postal_address'    => true,
            'require_honest_subject'    => true,
        ],

        // Fallback for any country without its own rule set. Conservative on
        // purpose: we would rather block a send than create a complaint.
        '*' => [
            'label'                     => 'Default',
            'rule_code'                 => 'DEFAULT_MARKETING_REQUIREMENTS',
            'version'                   => 1,
            'require_consent_basis'     => true,
            'acceptable_consent_types'  => ['express', 'legitimate_existing_relationship'],
            'allow_inferred'            => false,
            'relationship_max_age_days' => 730,
            'block_reason'              => 'CONSENT_UNKNOWN',
            'block_message'             => 'Marketing consent has not been established for this contact.',
            'require_sender_identity'   => true,
            'require_contact_details'   => true,
            'require_unsubscribe'       => true,
            'require_postal_address'    => false,
        ],
    ],

    /*
     * Import source declarations. `blocked` sources are refused outright — they
     * are not "imported but suppressed", the import does not happen.
     */
    'import_sources' => [
        'website_optin' => [
            'label'        => 'Website opt-in',
            'consent_type' => 'express',
            'status'       => 'granted',
            'blocked'      => false,
        ],
        'existing_customers' => [
            'label'        => 'Existing customers',
            'consent_type' => 'legitimate_existing_relationship',
            'status'       => 'granted',
            'blocked'      => false,
        ],
        'event_registration' => [
            'label'        => 'Event registration',
            'consent_type' => 'express',
            'status'       => 'granted',
            'blocked'      => false,
        ],
        'phone_consent' => [
            'label'             => 'Phone consent',
            'consent_type'      => 'express',
            'status'            => 'granted',
            'blocked'           => false,
            'require_reference' => true,
        ],
        'offline_consent' => [
            'label'             => 'Offline / paper consent',
            'consent_type'      => 'express',
            'status'            => 'granted',
            'blocked'           => false,
            'require_reference' => true,
        ],
        'crm_migration' => [
            'label'        => 'CRM migration',
            'consent_type' => 'other',
            'status'       => 'unknown',
            'blocked'      => false,
            'warning'      => 'Contacts migrated from another CRM start with unknown consent. Marketing sending will be blocked for jurisdictions that require a consent basis until consent is established.',
        ],
        'purchased_list' => [
            'label'   => 'Purchased list',
            'blocked' => true,
            'reason'  => 'IMPORT_PURCHASED_LIST_BLOCKED',
            'message' => 'Purchased marketing lists cannot be imported: consent cannot be demonstrated for these contacts.',
        ],
        'scraped_list' => [
            'label'   => 'Scraped or harvested list',
            'blocked' => true,
            'reason'  => 'IMPORT_SCRAPED_LIST_BLOCKED',
            'message' => 'Scraped or harvested contacts cannot be imported.',
        ],
        'unknown' => [
            'label'        => 'Unknown source',
            'consent_type' => 'other',
            'status'       => 'unknown',
            'blocked'      => false,
            'warning'      => 'Contacts with an unknown source cannot be sent marketing email in jurisdictions that require a consent basis.',
        ],
    ],

    'consent_channels' => ['email', 'sms', 'whatsapp'],
    'consent_statuses' => ['granted', 'denied', 'withdrawn', 'unknown'],
    'consent_types'    => ['express', 'inferred', 'transactional', 'legitimate_existing_relationship', 'other'],
    'consent_sources'  => [
        'website_form', 'checkout', 'crm', 'manual', 'api', 'event',
        'phone', 'paper', 'import', 'preference_centre', 'unsubscribe',
    ],

    // What the customer sees on a badge or in a filter. Plain words: somebody
    // running a cafe should not have to learn what a "hard bounce" is to
    // understand why an address stopped receiving their newsletter.
    'suppression_reasons' => [
        'unsubscribe' => 'Unsubscribed',
        'hard_bounce' => 'Address does not exist',
        'complaint'   => 'Marked as spam',
        'manual'      => 'Added by your team',
        'legal'       => 'Legal request',
        'invalid'     => 'Not a real address',
        'admin_block' => 'Blocked by support',
    ],

    // Soft bounces never suppress on their own; this is the escalation point.
    'soft_bounce_threshold' => 5,

    // Shown to the recipient in the preference centre, so these are the plainest
    // words of all — the person reading them is a customer, not a user.
    'preference_topics' => [
        'all'             => 'Everything',
        'promotions'      => 'Special offers and discounts',
        'newsletter'      => 'News and tips',
        'product_updates' => 'Updates about what we do',
        'events'          => 'Events and open days',
    ],

    'privacy_policy_version' => env('PRIVACY_POLICY_VERSION', '1.0'),
    'terms_version'          => env('TERMS_VERSION', '1.0'),
];
