<?php

declare(strict_types=1);

namespace App\Mail;

use App\Core\Config;
use App\Services\UnsubscribeService;

/**
 * Renders a message body for one recipient.
 *
 * Two responsibilities, and the second is the important one:
 *
 *  1. Substitute merge variables ({{first_name}}, {{unsubscribe_url}}, …).
 *  2. Guarantee the compliance footer. A marketing message is NEVER rendered
 *     without sender identity, contact details, the physical postal address and a
 *     working unsubscribe link — if the template author forgot them, they are
 *     appended here rather than the message going out without them.
 *
 * Every substituted value is HTML-escaped: a contact's own name or company is
 * attacker-supplied data as far as the rendered email is concerned.
 */
final class EmailRenderer
{
    public function __construct(
        private readonly UnsubscribeService $unsubscribe,
        private readonly Config $config,
    ) {
    }

    /**
     * @param array<string,mixed> $organisation
     * @param array<string,mixed> $contact
     * @return array{html:string,text:string,unsubscribe_url:string,preferences_url:string}
     */
    public function render(
        string $html,
        string $text,
        array $organisation,
        array $contact,
        ?int $campaignId = null,
        ?int $messageId = null,
        string $messageClass = 'marketing',
    ): array {
        $organisationId = (int) $organisation['id'];
        $contactUuid    = (string) ($contact['uuid'] ?? '');

        $unsubscribeUrl = $contactUuid === ''
            ? ''
            : $this->unsubscribe->urlFor($organisationId, $contactUuid, $campaignId, $messageId);

        $preferencesUrl = $contactUuid === ''
            ? ''
            : $this->unsubscribe->preferencesUrlFor($organisationId, $contactUuid, $campaignId, $messageId);

        $variables = $this->variablesFor($organisation, $contact, $unsubscribeUrl, $preferencesUrl);

        $html = $this->substitute($html, $variables, true);
        $text = $this->substitute($text, $variables, false);

        if ($messageClass === 'marketing') {
            $html = $this->ensureHtmlFooter($html, $organisation, $unsubscribeUrl, $preferencesUrl);
            $text = $this->ensureTextFooter($text, $organisation, $unsubscribeUrl);
        }

        return [
            'html'            => $html,
            'text'            => $text !== '' ? $text : $this->htmlToText($html),
            'unsubscribe_url' => $unsubscribeUrl,
            'preferences_url' => $preferencesUrl,
        ];
    }

    /**
     * @param array<string,mixed> $organisation
     * @param array<string,mixed> $contact
     * @return array<string,string>
     */
    public function variablesFor(
        array $organisation,
        array $contact,
        string $unsubscribeUrl = '',
        string $preferencesUrl = '',
    ): array {
        $currency = (string) ($contact['currency'] ?? $organisation['currency'] ?? 'USD');

        return [
            // Contact fields
            'first_name'     => (string) ($contact['first_name'] ?? ''),
            'last_name'      => (string) ($contact['last_name'] ?? ''),
            'full_name'      => trim((string) ($contact['first_name'] ?? '') . ' ' . (string) ($contact['last_name'] ?? '')),
            'email'          => (string) ($contact['email'] ?? ''),
            'company'        => (string) ($contact['company'] ?? ''),
            'job_title'      => (string) ($contact['job_title'] ?? ''),
            'city'           => (string) ($contact['city'] ?? ''),
            'state'          => (string) ($contact['state'] ?? ''),
            'country'        => (string) ($contact['country'] ?? ''),
            'customer_value' => money((float) ($contact['customer_value'] ?? 0), $currency),
            'total_revenue'  => money((float) ($contact['total_revenue'] ?? 0), $currency),

            // System variables
            'unsubscribe_url'  => $unsubscribeUrl,
            'preferences_url'  => $preferencesUrl,
            'business_name'    => (string) ($organisation['name'] ?? ''),
            'business_address' => $this->formatAddress($organisation),
            'business_phone'   => (string) ($organisation['contact_phone'] ?? ''),
            'business_email'   => (string) ($organisation['contact_email'] ?? ''),
            'business_website' => (string) ($organisation['website'] ?? ''),
            'current_year'     => gmdate('Y'),
        ];
    }

    /**
     * @param array<string,string> $variables
     */
    public function substitute(string $body, array $variables, bool $escape): string
    {
        if ($body === '') {
            return '';
        }

        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}/i',
            static function (array $matches) use ($variables, $escape): string {
                $key = strtolower($matches[1]);

                if (!array_key_exists($key, $variables)) {
                    // An unknown variable renders as nothing rather than leaking
                    // the raw {{token}} into a customer's inbox.
                    return '';
                }

                $value = $variables[$key];

                // URLs are already safe and must not be entity-encoded into
                // uselessness; everything else is escaped.
                if (str_ends_with($key, '_url')) {
                    return $value;
                }

                return $escape
                    ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    : $value;
            },
            $body
        );
    }

    /** Does this body already contain a working unsubscribe mechanism? */
    public function hasUnsubscribeLink(string $body): bool
    {
        return str_contains($body, '{{unsubscribe_url}}')
            || str_contains($body, '{{ unsubscribe_url }}')
            || preg_match('#/unsubscribe/#i', $body) === 1;
    }

    /** @param array<string,mixed> $organisation */
    public function formatAddress(array $organisation): string
    {
        $parts = array_filter([
            (string) ($organisation['address_line1'] ?? ''),
            (string) ($organisation['address_line2'] ?? ''),
            (string) ($organisation['address_city'] ?? ''),
            trim((string) ($organisation['address_state'] ?? '') . ' ' . (string) ($organisation['address_postcode'] ?? '')),
            (string) ($organisation['address_country'] ?? ''),
        ], static fn (string $part): bool => trim($part) !== '');

        return implode(', ', array_map('trim', $parts));
    }

    /** @param array<string,mixed> $organisation */
    private function ensureHtmlFooter(
        string $html,
        array $organisation,
        string $unsubscribeUrl,
        string $preferencesUrl,
    ): string {
        $businessName = trim((string) ($organisation['name'] ?? ''));

        // Only skip the generated footer when the template demonstrably carries
        // both an unsubscribe mechanism and the sender's identity. An empty
        // business name must never satisfy the identity half.
        if ($businessName !== ''
            && $this->hasUnsubscribeLink($html)
            && str_contains($html, $businessName)
        ) {
            return $html;
        }

        $name    = htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8');
        $address = htmlspecialchars($this->formatAddress($organisation), ENT_QUOTES, 'UTF-8');
        $phone   = htmlspecialchars((string) ($organisation['contact_phone'] ?? ''), ENT_QUOTES, 'UTF-8');
        $email   = htmlspecialchars((string) ($organisation['contact_email'] ?? ''), ENT_QUOTES, 'UTF-8');

        $contactLine = trim(implode(' &middot; ', array_filter([$phone, $email])));

        $footer = '<div style="margin-top:32px;padding-top:16px;border-top:1px solid #e5e7eb;'
            . 'font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;'
            . 'font-size:12px;line-height:1.6;color:#6b7280;text-align:center">'
            . '<p style="margin:0 0 6px"><strong>' . $name . '</strong></p>'
            . ($address !== '' ? '<p style="margin:0 0 6px">' . $address . '</p>' : '')
            . ($contactLine !== '' ? '<p style="margin:0 0 6px">' . $contactLine . '</p>' : '')
            . '<p style="margin:0">'
            . '<a href="' . $unsubscribeUrl . '" style="color:#6b7280;text-decoration:underline">Unsubscribe</a>'
            . ($preferencesUrl !== ''
                ? ' &middot; <a href="' . $preferencesUrl . '" style="color:#6b7280;text-decoration:underline">Email preferences</a>'
                : '')
            . '</p>'
            . '</div>';

        // Insert before </body> when there is one, so the footer stays inside the
        // rendered document.
        if (stripos($html, '</body>') !== false) {
            return (string) preg_replace('#</body>#i', $footer . '</body>', $html, 1);
        }

        return $html . $footer;
    }

    /** @param array<string,mixed> $organisation */
    private function ensureTextFooter(string $text, array $organisation, string $unsubscribeUrl): string
    {
        if ($text === '') {
            return '';
        }

        if (str_contains($text, '/unsubscribe/')) {
            return $text;
        }

        $identity = array_values(array_filter([
            (string) ($organisation['name'] ?? ''),
            $this->formatAddress($organisation),
            (string) ($organisation['contact_phone'] ?? ''),
        ], static fn (string $line): bool => trim($line) !== ''));

        $footer = array_merge(['', '---'], $identity, ['', 'Unsubscribe: ' . $unsubscribeUrl]);

        return $text . "\n" . implode("\n", $footer);
    }

    private function htmlToText(string $html): string
    {
        $text = (string) preg_replace('#<(br|/p|/div|/h[1-6])[^>]*>#i', "\n", $html);
        $text = (string) preg_replace('#<a[^>]+href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}
