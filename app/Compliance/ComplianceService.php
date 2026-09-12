<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Core\Clock;
use App\Core\Config;
use App\Repositories\ComplianceRuleRepository;
use App\Repositories\ConsentRepository;
use App\Repositories\SuppressionRepository;
use App\Support\TenantContext;

/**
 * The single gate every marketing send passes through.
 *
 * Called TWICE for every message: once when the audience is previewed and the
 * recipient snapshot is built, and again inside the worker immediately before the
 * provider call. The preview is never treated as authorisation — a contact can
 * unsubscribe, bounce or complain in the minutes between the two, and the second
 * check is what makes that safe.
 */
final class ComplianceService
{
    public function __construct(
        private readonly SuppressionRepository $suppressions,
        private readonly ConsentRepository $consents,
        private readonly ComplianceRuleRepository $rules,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly TenantContext $tenant,
    ) {
    }

    /**
     * @param array<string,mixed>      $organisation
     * @param array<string,mixed>      $contact
     * @param array<string,mixed>|null $campaign
     */
    public function canSendMarketingEmail(array $organisation, array $contact, ?array $campaign = null): ComplianceDecision
    {
        $email     = (string) ($contact['email'] ?? '');
        $contactId = (int) ($contact['id'] ?? 0);

        return $this->decide(
            $organisation,
            $contact,
            $campaign,
            $email === '' ? null : $this->suppressions->findActive($email),
            $contactId > 0 ? $this->consents->current($contactId, 'email') : null
        );
    }

    /**
     * The decision itself, with suppression and consent already resolved.
     *
     * Separating this from the lookups is what lets evaluateBatch() pre-load both
     * for a whole chunk and then decide with zero further queries — the same code
     * path, the same reason codes.
     *
     * @param array<string,mixed>      $organisation
     * @param array<string,mixed>      $contact
     * @param array<string,mixed>|null $campaign
     * @param array<string,mixed>|null $suppression active suppression row, if any
     * @param array<string,mixed>|null $consent     latest email consent row, if any
     */
    private function decide(
        array $organisation,
        array $contact,
        ?array $campaign,
        ?array $suppression,
        ?array $consent,
    ): ComplianceDecision {
        $applied = [];

        // --- 1. Organisation level ------------------------------------------
        if ((string) ($organisation['status'] ?? 'active') === 'suspended') {
            return ComplianceDecision::block(ReasonCode::ORG_SUSPENDED, ['ORG_STATUS_CHECK']);
        }

        if ((int) ($organisation['sending_paused'] ?? 0) === 1) {
            return ComplianceDecision::block(
                ReasonCode::ORG_SENDING_PAUSED,
                ['ORG_SENDING_PAUSE_CHECK'],
                (string) ($organisation['sending_paused_reason'] ?? '') !== ''
                    ? (string) $organisation['sending_paused_reason']
                    : ReasonCode::describe(ReasonCode::ORG_SENDING_PAUSED)
            );
        }

        $applied[] = 'ORG_STATUS_CHECK';

        // --- 2. Address validity --------------------------------------------
        $email = (string) ($contact['email'] ?? '');

        if (trim($email) === '') {
            return ComplianceDecision::block(ReasonCode::MISSING_EMAIL, $applied);
        }

        if (!is_valid_email($email)) {
            return ComplianceDecision::block(ReasonCode::INVALID_EMAIL, $applied);
        }

        $applied[] = 'EMAIL_VALIDITY_CHECK';

        // --- 3. Suppression (stronger than any list or segment) --------------
        $applied[] = 'SUPPRESSION_CHECK';

        if ($suppression !== null) {
            return ComplianceDecision::block(
                ReasonCode::forSuppression((string) $suppression['reason']),
                $applied
            );
        }

        // --- 4. Consent state ------------------------------------------------
        $contactId = (int) ($contact['id'] ?? 0);
        $status    = $consent === null ? 'unknown' : (string) $consent['status'];

        $applied[] = 'CONSENT_STATE_CHECK';

        if ($status === 'withdrawn') {
            return ComplianceDecision::block(ReasonCode::CONSENT_WITHDRAWN, $applied);
        }

        if ($status === 'denied') {
            return ComplianceDecision::block(ReasonCode::CONSENT_DENIED, $applied);
        }

        if ($consent !== null && $this->consentExpired($consent)) {
            return ComplianceDecision::block(ReasonCode::CONSENT_EXPIRED, $applied);
        }

        // Topic-level opt-out, when the campaign declares a topic.
        $topic = $campaign === null ? null : ($campaign['topic'] ?? null);

        if (is_string($topic) && $topic !== '' && $topic !== 'all' && $contactId > 0) {
            $topicConsent = $this->consents->currentForTopic($contactId, $topic, 'email');

            if ($topicConsent !== null
                && (string) ($topicConsent['topic'] ?? '') === $topic
                && in_array((string) $topicConsent['status'], ['withdrawn', 'denied'], true)
            ) {
                return ComplianceDecision::block(ReasonCode::CONSENT_TOPIC_WITHDRAWN, $applied);
            }

            $applied[] = 'CONSENT_TOPIC_CHECK';
        }

        // --- 5. Country rule set ---------------------------------------------
        $country = $this->resolveCountry($contact, $organisation);
        $rule    = $this->ruleFor($country, (int) ($organisation['id'] ?? 0));

        $applied[] = 'COUNTRY_RULE:' . ($rule['rule_code'] ?? 'NONE') . ':v' . ($rule['version'] ?? '0');

        $decision = $this->applyCountryRule($rule, $country, $status, $consent, $contact, $applied);

        if ($decision !== null) {
            return $decision;
        }

        return ComplianceDecision::allow($applied);
    }

    /**
     * Transactional mail skips the consent-basis gates — a password reset must
     * reach someone who never opted into marketing — but it still honours the
     * suppression reasons that mean the address itself is unusable or the
     * recipient reported abuse.
     *
     * @param array<string,mixed> $organisation
     * @param array<string,mixed> $contact
     */
    public function canSendTransactionalEmail(array $organisation, array $contact): ComplianceDecision
    {
        $applied = ['TRANSACTIONAL_PATH'];

        if ((string) ($organisation['status'] ?? 'active') === 'suspended') {
            return ComplianceDecision::block(ReasonCode::ORG_SUSPENDED, $applied);
        }

        $email = (string) ($contact['email'] ?? '');

        if (trim($email) === '') {
            return ComplianceDecision::block(ReasonCode::MISSING_EMAIL, $applied);
        }

        if (!is_valid_email($email)) {
            return ComplianceDecision::block(ReasonCode::INVALID_EMAIL, $applied);
        }

        $applied[] = 'EMAIL_VALIDITY_CHECK';

        $suppression = $this->suppressions->findActive($email);
        $applied[]   = 'SUPPRESSION_CHECK_TRANSACTIONAL';

        if ($suppression !== null) {
            $reason = (string) $suppression['reason'];

            // A marketing unsubscribe does not block operational mail, but an
            // address that hard bounces, is invalid, has complained, or is
            // blocked for legal/admin reasons is not sendable at all.
            if (in_array($reason, ['hard_bounce', 'invalid', 'complaint', 'legal', 'admin_block'], true)) {
                return ComplianceDecision::block(ReasonCode::forSuppression($reason), $applied);
            }
        }

        return ComplianceDecision::allow($applied);
    }

    /**
     * Guard against relabelling marketing content as transactional to escape
     * suppression. Called by campaign validation.
     *
     * @param array<string,mixed> $campaign
     */
    public function assertMessageClassHonest(array $campaign): ComplianceDecision
    {
        $marketingTypes = [
            'newsletter', 'promotion', 'reactivation', 'seasonal', 'product', 'service', 'event',
        ];

        $type         = (string) ($campaign['campaign_type'] ?? 'newsletter');
        $messageClass = (string) ($campaign['message_class'] ?? 'marketing');

        if ($messageClass === 'transactional' && in_array($type, $marketingTypes, true)) {
            return ComplianceDecision::block(
                ReasonCode::MARKETING_AS_TRANSACTIONAL,
                ['MESSAGE_CLASS_HONESTY_CHECK']
            );
        }

        return ComplianceDecision::allow(['MESSAGE_CLASS_HONESTY_CHECK']);
    }

    /**
     * Evaluate the consent state against a country's rule configuration.
     *
     * @param array<string,mixed>      $rule
     * @param array<string,mixed>|null $consent
     * @param array<string,mixed>      $contact
     * @param array<int,string>        $applied
     */
    private function applyCountryRule(
        array $rule,
        string $country,
        string $status,
        ?array $consent,
        array $contact,
        array $applied,
    ): ?ComplianceDecision {
        $configuration = $this->configurationOf($rule);

        $requiresBasis = (bool) ($configuration['require_consent_basis'] ?? true);

        if (!$requiresBasis) {
            // Opt-out jurisdiction (e.g. US): an unknown state is acceptable, but
            // an explicit opt-out was already caught by the consent-state gate
            // and by suppression.
            return null;
        }

        if ($status === 'unknown') {
            // Default deny. For Australia this surfaces as AU_CONSENT_UNKNOWN.
            $reason = (string) ($configuration['block_reason'] ?? ReasonCode::CONSENT_UNKNOWN);

            return ComplianceDecision::block(
                $reason,
                $applied,
                (string) ($configuration['block_message'] ?? '') !== ''
                    ? (string) $configuration['block_message']
                    : ReasonCode::describe($reason)
            );
        }

        /** @var array<int,string> $acceptable */
        $acceptable  = $configuration['acceptable_consent_types'] ?? ['express'];
        $consentType = $consent === null ? 'other' : (string) $consent['consent_type'];

        if ($consentType === 'inferred' && (bool) ($configuration['allow_inferred'] ?? false) === false) {
            return ComplianceDecision::block(ReasonCode::CONSENT_TYPE_NOT_ACCEPTABLE, $applied);
        }

        if (!in_array($consentType, $acceptable, true)) {
            return ComplianceDecision::block(ReasonCode::CONSENT_TYPE_NOT_ACCEPTABLE, $applied);
        }

        // A "legitimate existing relationship" basis has a shelf life: a customer
        // from eight years ago is not a current relationship.
        if ($consentType === 'legitimate_existing_relationship') {
            $maxAgeDays = (int) ($configuration['relationship_max_age_days'] ?? 0);

            if ($maxAgeDays > 0 && !$this->relationshipIsCurrent($contact, $consent, $maxAgeDays)) {
                return ComplianceDecision::block(ReasonCode::RELATIONSHIP_TOO_OLD, $applied);
            }
        }

        return null;
    }

    /**
     * The relationship is current if the most recent evidence of it — a purchase,
     * or failing that the consent record itself — falls inside the window.
     *
     * @param array<string,mixed>      $contact
     * @param array<string,mixed>|null $consent
     */
    private function relationshipIsCurrent(array $contact, ?array $consent, int $maxAgeDays): bool
    {
        $cutoff = $this->clock->now()->modify("-{$maxAgeDays} days")->format('Y-m-d H:i:s');

        foreach ([
            $contact['last_purchase_at'] ?? null,
            $consent['consented_at'] ?? null,
            $consent['created_at'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && $candidate >= $cutoff) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $consent */
    private function consentExpired(array $consent): bool
    {
        $expiresAt = $consent['expires_at'] ?? null;

        return is_string($expiresAt) && $expiresAt !== '' && $expiresAt < $this->clock->nowString();
    }

    /**
     * A contact's own country wins. Where it is unknown we fall back to the
     * organisation's country rather than guessing a permissive default, because
     * guessing wrong in the permissive direction is the expensive mistake.
     *
     * @param array<string,mixed> $contact
     * @param array<string,mixed> $organisation
     */
    private function resolveCountry(array $contact, array $organisation): string
    {
        $country = (string) ($contact['country'] ?? '');

        if ($country !== '') {
            return strtoupper($country);
        }

        $orgCountry = (string) ($organisation['country'] ?? '');

        return $orgCountry !== ''
            ? strtoupper($orgCountry)
            : strtoupper((string) $this->config->get('compliance.default_country', 'US'));
    }

    /**
     * Resolve the rule row for a country, preferring an organisation override,
     * then the platform default for that country, then the wildcard. If the
     * table has not been seeded we fall back to config — and the config
     * fallback is conservative for exactly the same reason.
     *
     * @return array<string,mixed>
     */
    private function ruleFor(string $country, int $organisationId): array
    {
        $row = $this->rules->forCountry($country, $organisationId > 0 ? $organisationId : null);

        if ($row !== null) {
            return $row;
        }

        /** @var array<string,mixed> $configured */
        $configured = $this->config->get('compliance.countries.' . $country)
            ?? $this->config->get('compliance.countries.*', []);

        return [
            'rule_code'          => $configured['rule_code'] ?? 'DEFAULT_MARKETING_REQUIREMENTS',
            'version'            => $configured['version'] ?? 1,
            'configuration_json' => $configured,
        ];
    }

    /**
     * @param array<string,mixed> $rule
     * @return array<string,mixed>
     */
    private function configurationOf(array $rule): array
    {
        $configuration = $rule['configuration_json'] ?? [];

        if (is_string($configuration)) {
            $decoded       = json_decode($configuration, true);
            $configuration = is_array($decoded) ? $decoded : [];
        }

        return is_array($configuration) ? $configuration : [];
    }

    /**
     * Bulk eligibility for a batch of contacts.
     *
     * Used by the audience preview and by the recipient snapshot builder. It
     * pre-loads suppression and consent for the whole chunk so the per-contact
     * decision costs no queries — which is what makes a 100k snapshot feasible.
     *
     * @param array<string,mixed>            $organisation
     * @param array<int,array<string,mixed>> $contacts
     * @param array<string,mixed>|null       $campaign
     * @return array<int,ComplianceDecision> keyed by contact id
     */
    public function evaluateBatch(array $organisation, array $contacts, ?array $campaign = null): array
    {
        if ($contacts === []) {
            return [];
        }

        $emails     = [];
        $contactIds = [];

        foreach ($contacts as $contact) {
            $email = (string) ($contact['email'] ?? '');

            if ($email !== '') {
                $emails[] = $email;
            }

            $contactId = (int) ($contact['id'] ?? 0);

            if ($contactId > 0) {
                $contactIds[] = $contactId;
            }
        }

        // Two queries for the whole chunk instead of two per contact.
        $suppressedReasons = $this->suppressions->suppressedAmong($emails);
        $consentByContact  = $this->consents->currentForMany($contactIds, 'email');

        $decisions = [];

        foreach ($contacts as $contact) {
            $contactId  = (int) ($contact['id'] ?? 0);
            $normalized = normalize_email((string) ($contact['email'] ?? ''));

            $suppression = isset($suppressedReasons[$normalized])
                ? ['reason' => $suppressedReasons[$normalized]]
                : null;

            $decisions[$contactId] = $this->decide(
                $organisation,
                $contact,
                $campaign,
                $suppression,
                $consentByContact[$contactId] ?? null
            );
        }

        return $decisions;
    }
}
