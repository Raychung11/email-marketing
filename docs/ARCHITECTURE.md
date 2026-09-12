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

## 8. Sending pipeline (Phase 2 target, interfaces in place now)

```
campaign scheduled
  → scheduler picks it up (locked)
  → recipient SNAPSHOT written to campaign_recipients
        (segment is NOT re-evaluated once sending starts)
  → each eligible recipient enqueued to Redis: email_marketing
  → worker: re-run ComplianceService  →  render  →  provider->send()
  → provider_message_id stored on email_messages
  → SNS/webhook events → email_events (idempotent on provider_event_id)
  → hard bounce / complaint → suppression
  → analytics rollups → AI post-campaign analysis
```

Queues: `email_high_priority`, `email_transactional`, `email_marketing`,
`email_retry`. Retry backoff 1m → 5m → 30m → 2h, then dead-letter. Permanent
failures are not retried.

**Never** send bulk email inside an HTTP request. **Never** load a full
recipient set into memory — repositories expose chunked cursors.

## 9. Data volume assumptions

Designed to reach 1M contacts, tens of millions of email events and 100k+
recipient campaigns per organisation without redesign:

- composite indexes always lead with `organisation_id`
- `email_normalized` unique per organisation for deduplication
- event tables are append-only and partition-ready on `event_at`
- all batch work is chunked (`ChunkedCursor`), never `fetchAll()`
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
