<?php

declare(strict_types=1);

namespace App\Compliance;

/**
 * Stable reason codes.
 *
 * These are persisted on campaign_recipients.eligibility_reason and asserted on
 * in tests, so they are part of the contract: renaming one is a breaking change,
 * not a refactor.
 */
final class ReasonCode
{
    public const ALLOWED = 'ALLOWED';

    // Address problems
    public const INVALID_EMAIL = 'INVALID_EMAIL';
    public const MISSING_EMAIL = 'MISSING_EMAIL';

    // Suppression (stronger than any list or segment membership)
    public const SUPPRESSED_UNSUBSCRIBE = 'SUPPRESSED_UNSUBSCRIBE';
    public const SUPPRESSED_HARD_BOUNCE = 'SUPPRESSED_HARD_BOUNCE';
    public const SUPPRESSED_COMPLAINT   = 'SUPPRESSED_COMPLAINT';
    public const SUPPRESSED_MANUAL      = 'SUPPRESSED_MANUAL';
    public const SUPPRESSED_LEGAL       = 'SUPPRESSED_LEGAL';
    public const SUPPRESSED_INVALID     = 'SUPPRESSED_INVALID';
    public const SUPPRESSED_ADMIN_BLOCK = 'SUPPRESSED_ADMIN_BLOCK';

    // Consent state
    public const CONSENT_WITHDRAWN       = 'CONSENT_WITHDRAWN';
    public const CONSENT_DENIED          = 'CONSENT_DENIED';
    public const CONSENT_UNKNOWN         = 'CONSENT_UNKNOWN';
    public const CONSENT_EXPIRED         = 'CONSENT_EXPIRED';
    public const CONSENT_TYPE_NOT_ACCEPTABLE = 'CONSENT_TYPE_NOT_ACCEPTABLE';
    public const CONSENT_TOPIC_WITHDRAWN = 'CONSENT_TOPIC_WITHDRAWN';
    public const RELATIONSHIP_TOO_OLD    = 'RELATIONSHIP_TOO_OLD';

    // Country specific
    public const AU_CONSENT_UNKNOWN = 'AU_CONSENT_UNKNOWN';
    public const US_OPTED_OUT       = 'US_OPTED_OUT';

    // Organisation level
    public const ORG_SENDING_PAUSED     = 'ORG_SENDING_PAUSED';
    public const ORG_SUSPENDED          = 'ORG_SUSPENDED';
    public const ORG_DAILY_LIMIT_REACHED = 'ORG_DAILY_LIMIT_REACHED';

    // Campaign validation
    public const NO_SENDER_IDENTITY      = 'NO_SENDER_IDENTITY';
    public const SENDER_DOMAIN_UNVERIFIED = 'SENDER_DOMAIN_UNVERIFIED';
    public const NO_SUBJECT              = 'NO_SUBJECT';
    public const NO_CONTENT              = 'NO_CONTENT';
    public const NO_UNSUBSCRIBE          = 'NO_UNSUBSCRIBE';
    public const NO_POSTAL_ADDRESS       = 'NO_POSTAL_ADDRESS';
    public const NO_BUSINESS_IDENTITY    = 'NO_BUSINESS_IDENTITY';
    public const NO_AUDIENCE             = 'NO_AUDIENCE';
    public const NO_ELIGIBLE_RECIPIENTS  = 'NO_ELIGIBLE_RECIPIENTS';
    public const DECEPTIVE_SUBJECT       = 'DECEPTIVE_SUBJECT';
    public const MARKETING_AS_TRANSACTIONAL = 'MARKETING_AS_TRANSACTIONAL';

    // Import
    public const IMPORT_PURCHASED_LIST_BLOCKED = 'IMPORT_PURCHASED_LIST_BLOCKED';
    public const IMPORT_SCRAPED_LIST_BLOCKED   = 'IMPORT_SCRAPED_LIST_BLOCKED';
    public const IMPORT_CONSENT_NOT_DECLARED   = 'IMPORT_CONSENT_NOT_DECLARED';
    public const IMPORT_REFERENCE_REQUIRED     = 'IMPORT_REFERENCE_REQUIRED';
    public const IMPORT_ROW_LIMIT_EXCEEDED     = 'IMPORT_ROW_LIMIT_EXCEEDED';

    /**
     * Group a blocking reason into the snapshot's eligibility vocabulary.
     *
     * The audience preview, the recipient snapshot and the send-time recheck all
     * have to bucket reasons the same way, or the campaign report contradicts the
     * preview the sender approved. One function, three call sites.
     */
    public static function bucket(string $code): string
    {
        if (str_starts_with($code, 'SUPPRESSED_')) {
            return 'suppressed';
        }

        if (in_array($code, [self::INVALID_EMAIL, self::MISSING_EMAIL], true)) {
            return 'invalid';
        }

        if (str_starts_with($code, 'CONSENT_')
            || str_ends_with($code, 'CONSENT_UNKNOWN')
            || $code === self::RELATIONSHIP_TOO_OLD
            || $code === self::US_OPTED_OUT
        ) {
            return 'no_consent';
        }

        return 'blocked';
    }

    /** Map a suppression reason to its blocking reason code. */
    public static function forSuppression(string $suppressionReason): string
    {
        return match ($suppressionReason) {
            'unsubscribe' => self::SUPPRESSED_UNSUBSCRIBE,
            'hard_bounce' => self::SUPPRESSED_HARD_BOUNCE,
            'complaint'   => self::SUPPRESSED_COMPLAINT,
            'manual'      => self::SUPPRESSED_MANUAL,
            'legal'       => self::SUPPRESSED_LEGAL,
            'invalid'     => self::SUPPRESSED_INVALID,
            'admin_block' => self::SUPPRESSED_ADMIN_BLOCK,
            default       => 'SUPPRESSED_' . strtoupper($suppressionReason),
        };
    }

    /** Human-readable explanation for the UI. Never shown as the stored value. */
    public static function describe(string $code): string
    {
        return match ($code) {
            self::ALLOWED                 => 'Eligible to receive marketing email.',
            self::INVALID_EMAIL           => 'The email address is not valid.',
            self::MISSING_EMAIL           => 'No email address is recorded for this contact.',
            self::SUPPRESSED_UNSUBSCRIBE  => 'This address unsubscribed and is on the suppression list.',
            self::SUPPRESSED_HARD_BOUNCE  => 'This address permanently bounced and is suppressed.',
            self::SUPPRESSED_COMPLAINT    => 'This address reported a previous message as spam and is suppressed.',
            self::SUPPRESSED_MANUAL       => 'This address was manually suppressed.',
            self::SUPPRESSED_LEGAL        => 'This address is suppressed following a legal request.',
            self::SUPPRESSED_INVALID      => 'This address was rejected as invalid and is suppressed.',
            self::SUPPRESSED_ADMIN_BLOCK  => 'This address was blocked by a platform administrator.',
            self::CONSENT_WITHDRAWN       => 'Marketing consent was withdrawn.',
            self::CONSENT_DENIED          => 'Marketing consent was explicitly declined.',
            self::CONSENT_UNKNOWN         => 'Marketing consent has not been established for this contact.',
            self::CONSENT_EXPIRED         => 'The recorded consent has expired.',
            self::CONSENT_TYPE_NOT_ACCEPTABLE
                => 'The recorded consent basis is not acceptable for marketing email in this jurisdiction.',
            self::CONSENT_TOPIC_WITHDRAWN => 'The contact opted out of this topic.',
            self::RELATIONSHIP_TOO_OLD
                => 'The existing customer relationship is older than the configured limit, so it no longer supports marketing consent.',
            self::AU_CONSENT_UNKNOWN
                => 'Marketing consent has not been established for this Australian contact.',
            self::US_OPTED_OUT            => 'This contact opted out of marketing email.',
            self::ORG_SENDING_PAUSED      => 'Sending is currently paused for this organisation.',
            self::ORG_SUSPENDED           => 'This organisation is suspended.',
            self::ORG_DAILY_LIMIT_REACHED => 'The daily sending limit for this organisation has been reached.',
            self::NO_SENDER_IDENTITY      => 'No sender name and address are configured.',
            self::SENDER_DOMAIN_UNVERIFIED => 'The sending domain is not verified.',
            self::NO_SUBJECT              => 'The campaign has no subject line.',
            self::NO_CONTENT              => 'The campaign has no content.',
            self::NO_UNSUBSCRIBE          => 'The content does not contain an unsubscribe link.',
            self::NO_POSTAL_ADDRESS       => 'No physical postal address is configured for the organisation.',
            self::NO_BUSINESS_IDENTITY    => 'No business identity (name and contact details) is configured.',
            self::NO_AUDIENCE             => 'No audience has been selected.',
            self::NO_ELIGIBLE_RECIPIENTS  => 'No recipients are eligible to receive this campaign.',
            self::DECEPTIVE_SUBJECT       => 'The subject line may be misleading about the content of the message.',
            self::MARKETING_AS_TRANSACTIONAL
                => 'Marketing content cannot be sent through the transactional path.',
            self::IMPORT_PURCHASED_LIST_BLOCKED
                => 'Purchased marketing lists cannot be imported: consent cannot be demonstrated for these contacts.',
            self::IMPORT_SCRAPED_LIST_BLOCKED
                => 'Scraped or harvested contacts cannot be imported.',
            self::IMPORT_CONSENT_NOT_DECLARED
                => 'You must declare how these contacts were obtained before importing.',
            self::IMPORT_REFERENCE_REQUIRED
                => 'A reference to the consent evidence is required for this consent source.',
            self::IMPORT_ROW_LIMIT_EXCEEDED
                => 'This import exceeds the row limit for your account trust level.',
            default => 'Sending is not permitted (' . $code . ').',
        };
    }
}
