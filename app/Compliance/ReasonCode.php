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
    public const MY_CONSENT_UNKNOWN = 'MY_CONSENT_UNKNOWN';
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

    /**
     * The explanation a customer reads. Never the stored value.
     *
     * Written for someone who runs a plumbing firm or a dental practice, not for
     * someone who works in email marketing. Each one says what happened and, where
     * there is one, what they can do about it — because the alternative is a
     * support ticket asking what "consent basis" means.
     */
    public static function describe(string $code): string
    {
        return match ($code) {
            self::ALLOWED                 => 'This person can receive marketing email from you.',
            self::INVALID_EMAIL           => 'That is not a working email address.',
            self::MISSING_EMAIL           => 'This contact has no email address saved.',
            self::SUPPRESSED_UNSUBSCRIBE  => 'They unsubscribed, so they are on your do-not-email list.',
            self::SUPPRESSED_HARD_BOUNCE  => 'This address does not exist — mail to it bounced back for good.',
            self::SUPPRESSED_COMPLAINT    => 'They marked one of your emails as spam, so we stopped emailing them.',
            self::SUPPRESSED_MANUAL       => 'Somebody on your team added this address to the do-not-email list.',
            self::SUPPRESSED_LEGAL        => 'This address is on the do-not-email list following a legal request.',
            self::SUPPRESSED_INVALID      => 'This address was rejected as not real, so we stopped emailing it.',
            self::SUPPRESSED_ADMIN_BLOCK  => 'Support has blocked this address.',
            self::CONSENT_WITHDRAWN       => 'They told you to stop sending marketing email.',
            self::CONSENT_DENIED          => 'They were asked and said no to marketing email.',
            self::CONSENT_UNKNOWN         => 'You have no record of this person agreeing to hear from you.',
            self::CONSENT_EXPIRED         => 'Their permission is now too old to rely on.',
            self::CONSENT_TYPE_NOT_ACCEPTABLE
                => 'The way you got this person\'s permission is not enough for marketing email in their country.',
            self::CONSENT_TOPIC_WITHDRAWN => 'They asked to stop hearing about this particular topic.',
            self::RELATIONSHIP_TOO_OLD
                => 'They were a customer, but too long ago to count as agreeing to marketing email now.',
            self::AU_CONSENT_UNKNOWN
                => 'This contact is in Australia and you have no record of them agreeing to hear from you. '
                    . 'Australian law needs that before you can send marketing email.',
            self::MY_CONSENT_UNKNOWN
                => 'This contact is in Malaysia and you have no record of them agreeing to hear from you. '
                    . 'Malaysian law needs that before you can send marketing email.',
            self::US_OPTED_OUT            => 'They asked to stop receiving marketing email.',
            self::ORG_SENDING_PAUSED      => 'Sending is paused for your account right now.',
            self::ORG_SUSPENDED           => 'Your account is suspended, so nothing can be sent.',
            self::ORG_DAILY_LIMIT_REACHED => 'You have hit your daily sending limit. It resets tomorrow.',
            self::NO_SENDER_IDENTITY      => 'You have not set a name and address for your email to come from.',
            self::SENDER_DOMAIN_UNVERIFIED
                => 'Your email address has not been set up yet, so mail would land in spam. '
                    . 'Finish the set-up under Settings.',
            self::NO_SUBJECT              => 'This campaign has no subject line.',
            self::NO_CONTENT              => 'This campaign is empty — there is nothing to send.',
            self::NO_UNSUBSCRIBE          => 'There is no unsubscribe link in the email. Every marketing email needs one.',
            self::NO_POSTAL_ADDRESS
                => 'Your business postal address is missing. The law requires it in every marketing email, '
                    . 'and we print it in the footer for you once you add it.',
            self::NO_BUSINESS_IDENTITY    => 'Your business name and contact details are missing.',
            self::NO_AUDIENCE             => 'You have not chosen who this campaign goes to.',
            self::NO_ELIGIBLE_RECIPIENTS  => 'Nobody in this audience can be emailed right now.',
            self::DECEPTIVE_SUBJECT       => 'This subject line may mislead people about what is inside the email.',
            self::MARKETING_AS_TRANSACTIONAL
                => 'Promotional content cannot be sent as a receipt or a notification.',
            self::IMPORT_PURCHASED_LIST_BLOCKED
                => 'Bought lists cannot be uploaded: these people never agreed to hear from you, '
                    . 'and emailing them would get your address blocked.',
            self::IMPORT_SCRAPED_LIST_BLOCKED
                => 'Addresses collected from websites cannot be uploaded.',
            self::IMPORT_CONSENT_NOT_DECLARED
                => 'Tell us how you got these contacts before you upload them.',
            self::IMPORT_REFERENCE_REQUIRED
                => 'For this way of collecting contacts we need a note of where the proof is kept.',
            self::IMPORT_ROW_LIMIT_EXCEEDED
                => 'This file has more contacts than your account can upload at once.',
            default => 'This email cannot be sent (' . $code . ').',
        };
    }
}
