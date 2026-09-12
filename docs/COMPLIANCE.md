# Compliance design

> **Not legal advice.** This platform provides compliance-*supporting*
> controls. Regulations change and vary by jurisdiction and by the nature of the
> message. Every rule in this document is expressed as a *versioned,
> configurable row* in `compliance_rules` precisely so it can be updated without
> a code change. Organisations remain responsible for their own obligations and
> should take their own advice.

## 1. Principles

1. **Consent is a history, not a flag.** `contact_consents` is append-only.
   Nothing updates or deletes a consent row; a change is a new row. Current
   state = latest row per `(contact_id, channel)`.
2. **Default deny.** Unknown consent for a jurisdiction that requires a basis
   results in `BLOCKED`, never "probably fine".
3. **Suppression outranks everything.** List membership, segment membership, a
   fresh import, an API write — none of them re-enable a suppressed address.
4. **Evidence is preserved.** Consent text, privacy-policy version, source,
   source reference, IP and user agent are captured at the moment of consent and
   retained.
5. **Checked twice.** Eligibility is evaluated when the recipient snapshot is
   built *and again* in the worker before the provider call. A stale preview can
   never authorise a send.
6. **Marketing ≠ transactional.** The distinction is structural, and marketing
   content cannot be relabelled transactional to escape suppression (§9).

## 2. `ComplianceService` contract

```php
ComplianceService::canSendMarketingEmail(
    Organisation $org,
    Contact $contact,
    ?Campaign $campaign = null
): ComplianceDecision
```

Returns:

```json
{
  "allowed": false,
  "reason": "AU_CONSENT_UNKNOWN",
  "rules_applied": ["SUPPRESSION_CHECK", "AU_EXPRESS_CONSENT_REQUIRED"],
  "severity": "block",
  "message": "Marketing consent has not been established for this Australian contact."
}
```

Reason codes are stable identifiers, safe to store on
`campaign_recipients.eligibility_reason` and to assert on in tests.

### Evaluation order (fail fast, cheapest first)

| # | Gate                        | Failure reason code              |
|---|-----------------------------|----------------------------------|
| 1 | Valid, parseable address    | `INVALID_EMAIL`                  |
| 2 | Organisation-wide suppression | `SUPPRESSED_<REASON>`          |
| 3 | Explicit withdrawal / denial | `CONSENT_WITHDRAWN`, `CONSENT_DENIED` |
| 4 | Country rule set             | e.g. `AU_CONSENT_UNKNOWN`        |
| 5 | Organisation sending limits / risk hold | `ORG_SENDING_PAUSED`, `ORG_DAILY_LIMIT_REACHED` |
| 6 | Campaign-level validation    | see §5                           |

Transactional mail uses `canSendTransactionalEmail()`, which skips gates 3–4
but **still honours** hard-bounce and invalid-address suppression (there is no
point mailing an address that does not exist) and still refuses complaint
suppression for anything that is not strictly operational.

## 3. Australian rule set (`country = AU`)

Configured as `compliance_rules` rows with `country = 'AU'`.

Acceptable bases recorded in `consent_type`:

- `express` — the contact actively opted in. Always acceptable.
- `legitimate_existing_relationship` — acceptable only when the organisation has
  recorded a qualifying relationship (e.g. an existing customer) **and** the
  configured `relationship_max_age_days` has not elapsed.
- `inferred` — acceptable only when explicitly enabled for the organisation and
  the source is one the rule set permits.
- `transactional` — not a marketing basis. Marketing send blocked.
- `other` / unknown — **blocked**.

If consent status is `unknown`, the send is **blocked** with
`AU_CONSENT_UNKNOWN` and the UI surfaces:

> "Marketing consent has not been established for this Australian contact."

Every commercial marketing email must carry sender/business identity, contact
information and a functional unsubscribe mechanism. These are validated as
campaign preconditions (§5), not left to the template author.

### Import consent declaration (mandatory)

A CSV import touching Australian contacts cannot proceed until the importer
declares how the contacts were obtained:

| Declared source        | Result                                            |
|------------------------|---------------------------------------------------|
| `website_optin`        | accepted → `express`                              |
| `existing_customers`   | accepted → `legitimate_existing_relationship`     |
| `event_registration`   | accepted → `express`                              |
| `phone_consent`        | accepted → `express` (evidence reference required)|
| `offline_consent`      | accepted → `express` (evidence reference required)|
| `crm_migration`        | accepted → `unknown` (marketing blocked until established) |
| `purchased_list`       | **BLOCKED — import refused**                      |
| `scraped_list`         | **BLOCKED — import refused**                      |
| `unknown`              | imported, marketing blocked                       |

Purchased and scraped lists are refused outright: they are not importable, not
importable-but-blocked. Harvested addresses and unknown-source bulk sending are
likewise never sendable.

## 4. United States rule set (`country = US`)

US commercial marketing email is validated at the campaign level. A campaign
cannot leave validation unless:

- a sender identity exists and the from-address is on a verified domain
- a valid, monitored reply path exists
- the organisation's **physical postal address** is present and rendered
- a functional unsubscribe link is present in the body
- the subject line is not deliberately deceptive relative to the content
- opt-out records are honoured via the suppression list

Opt-outs create suppression rows and are never silently expired.

## 5. Campaign-level validation (all jurisdictions)

`CampaignValidator` must return zero blocking findings before a campaign can be
approved or scheduled:

```
sender configured                    domain verified (DKIM at minimum)
subject present                      content present
unsubscribe token present            physical business address present
audience resolves                    estimated recipients > 0
suppression applied                  consent policy applied
organisation not paused              within daily sending limit
```

## 6. Suppression

`suppressions` is keyed on `(organisation_id, email_normalized)`.

| Reason         | Created by                                  | Auto-created |
|----------------|---------------------------------------------|--------------|
| `unsubscribe`  | unsubscribe page / preference centre        | yes          |
| `hard_bounce`  | provider permanent bounce                   | yes          |
| `complaint`    | provider complaint / spam report            | yes          |
| `invalid`      | address validation failure                  | yes          |
| `manual`       | user action                                 | no           |
| `legal`        | legal / regulator request                    | no           |
| `admin_block`  | platform super admin                         | no           |

Soft/transient bounces do **not** create suppression. Repeated soft bounces
raise a deliverability alert and may escalate after a configured threshold.

Suppression is never removed by an import, an API write, or contact deletion.
Removal is a deliberate, audited action limited to `compliance.manage`.

## 7. Unsubscribe

- URL shape: `/unsubscribe/{token}` where the token is an HMAC-signed, opaque
  payload. **Raw contact IDs are never exposed.**
- One-click and `List-Unsubscribe` / `List-Unsubscribe-Post` headers supported.
- The page confirms: *"This email address has been unsubscribed."*
- Optional preference centre with topic granularity: all marketing, promotions,
  newsletter, product updates, events.
- "Unsubscribe from all marketing" creates a global suppression row.
- Every unsubscribe logs time, IP, user agent, campaign, message, email and
  organisation.

## 8. Consent lifecycle example

```
2026-01-04  granted   express      website_form   (ip, ua, policy v3, text stored)
2026-03-11  granted   express      checkout       (re-affirmed, new row)
2026-08-02  withdrawn —            unsubscribe    (campaign 812, ip, ua)
2026-09-01  granted   express      website_form   (re-subscribed via form)
```

Four rows. Nothing overwritten. The 2026-08-02 withdrawal also created a
suppression row; re-subscribing through a verified opt-in form is the only path
that clears it, and that clearance is itself audited.

## 9. Marketing vs transactional

`campaigns.campaign_type` and `email_messages.message_class` both carry the
distinction. `ComplianceService` selects the gate set from `message_class`,
and `message_class` is derived from the campaign/automation action type — it is
**not** a free field a user can set on a promotional blast. Attempting to send
promotional content through the transactional path is rejected at validation and
recorded in the audit log.

## 10. Data subject requests

- **Export** — full contact export including consent history.
- **Deletion / anonymisation** — personal fields are anonymised in place while
  records required for suppression, audit, compliance evidence and financial
  history are retained. A deletion never removes a suppression row; the
  suppression is retained keyed on a hash of the address.

## 11. Versioned rules

```
compliance_rules
  country            'AU' | 'US' | '*'
  rule_code          'AU_EXPRESS_CONSENT_REQUIRED'
  version            3
  effective_from     2026-01-01
  configuration_json { "allow_inferred": false, "relationship_max_age_days": 730 }
```

Decisions record which rule versions were applied, so a historical send can be
explained with the rules that were in force at the time.
