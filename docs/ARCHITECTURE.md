# Architecture

> Internal working name: **AI Growth Hub**. The product name is configurable
> (`APP_NAME`) and is never hardcoded, so the platform can later ship under
> Aiwance or any other brand. See `config/app.php` → `name`.

## 1. Positioning

This is **not** a Mailchimp clone. It is an *AI Customer Growth & Retention
Platform*. Email is one channel inside a larger loop:

```
Website / Forms / POS / CRM / CSV / API
        │
        ▼
  Customer Data Hub  ──────────────┐
        │                          │
        ▼                          │
  AI Segmentation                  │
        │                          │
        ▼                          │
  Campaign / Automation Engine     │
        │                          │
        ▼                          │
  Email / SMS / WhatsApp           │
        │                          │
        ▼                          │
  Customer Behaviour ──────────────┘
        │
        ▼
  Conversion / Booking / Purchase
        │
        ▼
  AI Follow-up
        │
        ▼
  Revenue Attribution + BI
        │
        ▼
  AI Recommendations (next best action)
```

The product's success metric is **attributed revenue, recovered leads and
reactivated customers** — not open rate.

## 2. Stack

| Layer         | Choice                                                      |
|---------------|-------------------------------------------------------------|
| Language      | PHP 8.3+ (developed and tested on 8.4)                      |
| Architecture  | Lightweight modular PHP — no heavy framework                |
| Data access   | PDO, prepared statements only                               |
| Database      | MySQL 8+ (canonical). SQLite supported for the test suite   |
| Cache / queue | Redis                                                       |
| Frontend      | HTML5, Bootstrap 5, vanilla JS + `fetch`, Chart.js          |
| Web server    | Nginx (or Apache) + PHP-FPM                                 |
| Workers       | `workers/worker.php` under Supervisor                       |
| Scheduler     | `cron/scheduler.php` every minute, Redis/DB advisory locks  |
| Email         | Amazon SES (primary), behind `EmailProviderInterface`       |
| AI            | OpenAI, behind `AiProviderInterface`                        |
| Storage       | Local disk now, `StorageInterface` keeps S3 a drop-in       |

### Why no heavy framework

The workload is dominated by (a) very large batch jobs that must stream rather
than hydrate, and (b) strict multi-tenant + compliance invariants that we want
enforced in *one* obvious place. A thin, explicit core keeps the hot paths free
of ORM magic and makes the tenancy/compliance guarantees auditable. Everything
that a framework would have given us and that we actually need — routing, DI,
migrations, validation, views, queues — is implemented in `app/Core` and
`app/Database` in a few hundred lines each, with no hidden global state.

## 3. Tenancy model

```
Platform
└── Organisation            (the billing + compliance boundary)
    └── Workspace / Brand   (schema-ready; one default row per org in V1)
        ├── Users (via organisation_users, many-to-many)
        ├── Contacts
        ├── Campaigns
        ├── Automations
        └── Analytics
```

**Invariants**

1. Every tenant-scoped table carries `organisation_id`, indexed first in every
   composite index.
2. `organisation_id` is **never** read from a URL, query string or form field.
   It is derived in `TenantMiddleware` from the authenticated session's active
   membership (`organisation_users`) and injected into `TenantContext`.
3. Repositories take the `organisation_id` from `TenantContext`, not from
   callers, and every `WHERE` includes it. Cross-tenant reads are therefore a
   compile-time-visible mistake rather than a runtime one.
4. Switching organisation is an explicit POST to `/organisations/switch` that
   re-validates membership and regenerates the session ID.
5. `workspace_id` exists on brand-scoped tables from day one so agencies with
   multiple brands do not require a migration later (§55).

## 4. Request lifecycle

```
public/index.php
  └── bootstrap/app.php         load env, config, container, error handler
      └── Router::dispatch()
          ├── global middleware:  SecurityHeaders → Session → Csrf
          ├── route middleware:   Auth → Tenant → Permission('contacts.view')
          └── Controller
              └── Service  (all business rules live here)
                  └── Repository (all SQL lives here)
```

Controllers do no business logic. They validate input, call one service, and
render. This is enforced by review and by the fact that repositories require a
`TenantContext` that controllers do not construct.

## 5. Layer responsibilities

| Directory            | Responsibility                                              |
|----------------------|-------------------------------------------------------------|
| `app/Core`           | Container, Config, Env, Router, Request/Response, Session, CSRF, Validator, View, Logger, Hash, Encryption, RateLimiter |
| `app/Database`       | Connection, QueryBuilder, Schema builder with MySQL/SQLite grammars, Migrator |
| `app/Models`         | Thin entities / value objects. No persistence.              |
| `app/Repositories`   | All SQL. Tenant-scoped by construction.                     |
| `app/Services`       | Business rules (`ContactService`, `ConsentService`, …)       |
| `app/Compliance`     | `ComplianceService` + per-country rule engines               |
| `app/Mail`           | `EmailProviderInterface` + provider implementations          |
| `app/AI`             | `AiProviderInterface`, prompt builders, structured decoders  |
| `app/Jobs`           | Queueable units of work                                      |
| `app/Middleware`     | Cross-cutting request concerns                               |
| `app/Policies`       | Permission checks for entities                               |
| `app/Controllers`    | HTTP entry points                                            |

## 6. Authorisation

RBAC with **permission-based** checks. Code asks
`$auth->can('campaigns.send')`, never `if ($role === 'ADMIN')`. Roles are rows,
so an organisation can later get custom roles without touching code.

Platform role: `SUPER_ADMIN`.
Organisation roles: `OWNER`, `ADMIN`, `MARKETING_MANAGER`, `MARKETER`, `SALES`,
`APPROVER`, `ANALYST`, `VIEWER`.

See `docs/DATABASE.md` for the `roles` / `permissions` / `role_permissions`
tables and `database/seeds/RbacSeeder.php` for the default matrix.

## 7. Compliance is an architectural concern, not a feature

Consent is **never** a boolean column on `contacts`. It is an append-only
history in `contact_consents` (channel, status, type, source, evidence, IP, UA,
policy version, timestamps). Rows are never updated or deleted; a change is a
new row. The current state is the latest row per `(contact, channel)`.

Two independent gates protect every marketing send:

1. **Suppression** (`suppressions`) — organisation-wide, email-keyed, stronger
   than any list membership. Survives re-import. Never auto-deleted.
2. **Consent basis** (`ComplianceService`) — country rules decide whether the
   recorded consent state is an acceptable basis for *marketing* mail.

`ComplianceService::canSendMarketingEmail()` returns
`{allowed, reason, rules_applied}` and is called **twice**: at audience
preview/snapshot time *and* again inside the worker immediately before handing
the message to the provider. The preview is never trusted as authorisation.

Australian contacts with `unknown` consent are **blocked by default**
(`AU_CONSENT_UNKNOWN`). US sends are validated for sender identity, physical
postal address and a working unsubscribe mechanism. Rules are versioned rows
(`compliance_rules`) because regulation changes; the code reads configuration,
it does not hardcode jurisdictions.

> The platform provides compliance-*supporting* controls. It does not provide
> legal advice. See `docs/COMPLIANCE.md`.

## 8. Sending pipeline

```
campaign scheduled
  → scheduler picks it up (named advisory lock: campaigns.dispatch)
  → recipient SNAPSHOT written to campaign_recipients, chunked
        → snapshot_completed_at stamped only when the build finishes
  → status: scheduled → sending
  → each eligible recipient enqueued to email_marketing (one job per recipient)
  → worker: re-run ComplianceService → render → rewrite links → provider->send()
  → provider_message_id stored on email_messages
  → SNS/webhook events → email_events (idempotent on provider_event_id)
  → hard bounce / complaint → suppression
  → queue drains → status: sending → completed, counters recomputed
```

`CampaignDispatcher` owns everything left of the queue; `SendCampaignEmail`
owns everything right of it. Four properties hold across the boundary:

**The snapshot is taken once.** A segment is a live query. If it were re-run
mid-send, a contact added afterwards would receive a campaign whose audience
nobody reviewed, and one who stopped matching would be half-sent.
`snapshot_completed_at` is what separates "finished" from "interrupted": a build
that crashes leaves it null and resumes from the highest `contact_id` already
written, because the snapshot is built in contact-id order.

**The snapshot records who was *not* sent to, and why.** Ineligible contacts get
a row with a `ReasonCode` rather than being filtered out. "We sent to 1,327 of
1,482, and here is why the other 155 were held back" is a compliance record;
sending to 1,327 silently is just a number. The campaign page reads this rather
than re-running the segment, so a campaign sent last week still reports the
audience it actually had.

**Compliance is checked twice.** Once when the snapshot is built, and again in
the worker immediately before `provider->send()`. Hours can pass between them,
and in that time a contact can unsubscribe, bounce or complain. The preview never
authorises a send.

**A job that runs twice sends once.** `claimForSending()` is a conditional
UPDATE from `pending|queued` to `sent`; a redelivered message claims nothing.
That guarantee is what lets the dispatcher enqueue *before* marking the row
queued — a duplicate job that sends nothing is a far better failure than a
recipient silently lost between the two writes.

Throttling takes the lower of two ceilings per scheduler tick: the tenant's
`daily_send_limit` (a calendar day in *their* timezone, because that is what
their plan says) and the provider's live quota read from `getQuota()` — not a
configured guess, because exceeding an SES rate limit produces throttling errors
that look like bounces and damage every tenant's reputation, not just the one
that caused them.

Queues: `email_high_priority`, `email_transactional`, `email_marketing`,
`email_retry`. Retry backoff 1m → 5m → 30m → 2h, then dead-letter. Permanent
failures are not retried.

**Never** send bulk email inside an HTTP request — the dispatcher refuses to run
on the sync queue driver outside the test environment. **Never** load a full
recipient set into memory — the snapshot is written and read through keyset
generators, so a 100k campaign never holds more than one chunk at a time.

## 9. Data volume assumptions

Designed to reach 1M contacts, tens of millions of email events and 100k+
recipient campaigns per organisation without redesign:

- composite indexes always lead with `organisation_id`
- `email_normalized` unique per organisation for deduplication
- event tables are append-only and partition-ready on `event_at`
- all batch work is chunked — `QueryBuilder::cursor()`, `chunkById()` and
  keyset generators such as `CampaignRecipientRepository::pendingBatches()` —
  never `fetchAll()` over a recipient set
- counts that are expensive (segment size) are cached and refreshed by cron

## 10. Channel abstraction

`ChannelProviderInterface` sits above `EmailProviderInterface` so that
`SmsProvider` and `WhatsAppProvider` can be added in Phase 7 without touching
the automation engine. Automation actions call `CommunicationService`, which
resolves a channel — they never call an email provider directly.

## 11. AI safety boundaries

AI is a *suggestion engine operating on approved tools*, never an actor:

- It cannot send a campaign, change consent, remove suppression, alter billing,
  bypass approvals, or invent customer data or revenue.
- It never writes raw SQL from a user prompt. It emits a **structured segment
  definition** that goes through the same validated `SegmentCompiler` a human
  would use, and the user must approve it before it is saved.
- Output is always labelled: *observed data*, *calculated data*, or
  *AI recommendation*.
- Every AI call is recorded in `ai_requests` with token usage for billing and
  abuse control.

## 12. Security posture

`password_hash`/`password_verify` (bcrypt, cost tuned), CSRF tokens on every
state-changing request, prepared statements everywhere, output escaping by
default in the view layer, session ID regeneration on privilege change, secure
+ HttpOnly + SameSite cookies, TLS enforcement, login throttling, expiring
password-reset tokens, hashed API keys (prefix + hash, plaintext shown once),
HMAC-signed outbound webhooks, signed unsubscribe tokens that never expose
contact IDs, and an append-only `audit_logs` table separate from operational
`activity_logs`.

Secrets live in the environment, never in the database or in JavaScript.

**Click tracking is not an open redirect.** `/track/click/{token}` takes a signed
token carrying a `campaign_links` row id — never a destination. The URL is read
from that row, which an authenticated user put into a campaign, and re-checked to
be `http(s)` before it reaches a `Location` header. A forged token fails the
signature; a genuine one has nothing in it to substitute. The tracking endpoints
are also the only place tenancy comes from a URL, and only because the payload is
our own HMAC and is verified before a single field is read from it.

## 13. Inbound provider events

`POST /webhooks/aws/ses` is a public, unauthenticated URL — it has to be, because
Amazon has no credentials of ours to present — so everything rests on two checks
in order:

1. **The SNS signature must verify.** Forging one would let an anonymous caller
   suppress a rival's entire mailing list, or clear bounces off their own. Within
   that, the check that matters most is the one that is easiest to leave out: the
   *signing certificate URL must be on an Amazon SNS host*, anchored at both ends
   of the hostname. A pattern that merely contains `amazonaws.com` would accept
   `sns.amazonaws.com.evil.test`, and an attacker would then sign their forgery
   with their own key and hand us the matching certificate. The timestamp is
   checked too: a valid signature stays valid for ever, so without a freshness
   window a captured message could be replayed a year later.

2. **The topic must be ours.** A valid Amazon signature only proves *Amazon* sent
   it. Anyone with an AWS account can publish to their own topic and point it at
   our URL.

Tenancy is never taken from the payload. The organisation is resolved from our
own `email_messages` row via the provider's message id, so a hostile payload
naming another organisation changes nothing — the field is not read.

One SES notification can name several recipients; it is split into one event per
address, because recording it as a single event would leave the other addresses
still receiving mail. Each gets a stable id derived from the SNS message id and
the address, so a redelivery collides with the unique index and becomes a no-op.

The response is 200 for anything we decide to ignore. SNS retries non-2xx
responses for hours, and a payload we have already rejected on its merits will
not become acceptable on the twentieth attempt. The single exception is a failed
signature, which answers 403: that caller is not Amazon, so there is no retry
storm to cause, and an operator reading their logs should see it plainly.

## 14. Tracking and what it is worth

Opens are recorded because customers expect the number, and are treated as weak
evidence everywhere they are reported: mail privacy proxies pre-fetch images, so
an "open" can mean a server in another country requested a pixel and the
recipient never saw the message. Repeated fetches inside the same minute are
collapsed through the same unique index that makes provider redeliveries
idempotent, and `opened_at` is written once so unique opens stay unique.

Clicks are the strongest first-party signal the system has — they required a
person, a device and an intent — and every piece of analysis weights them above
opens.

Neither ever changes `email_messages.status`. That column records what the
provider did with the message; engagement lives in its own timestamps and
counters, so a missed delivery notification cannot be papered over by a pixel
fetch. Campaign counters are recomputed from `email_messages` rather than
incremented, so a replayed or missed event cannot skew them permanently.
