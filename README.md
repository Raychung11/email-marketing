# AI Growth Hub

**AI Customer Growth & Retention Platform** — a multi-tenant SaaS for SMEs,
agencies and service businesses to capture leads, organise customers, segment
them, generate campaigns with AI, automate journeys, recover lost leads,
reactivate inactive customers and attribute revenue.

Launch markets: **United States** and **Australia**.

> The product name is configurable (`APP_NAME`) and is not hardcoded anywhere, so
> the platform can later ship under Aiwance or another brand.

This is deliberately **not** a Mailchimp clone. Email is one channel inside a
larger loop, and the product's success metric is attributed revenue, recovered
leads and reactivated customers — not open rate.

```
Website / Forms / POS / CRM / CSV / API → Customer Data Hub → AI Segmentation
→ Campaign / Automation Engine → Email / SMS / WhatsApp → Customer Behaviour
→ Conversion / Booking / Purchase → AI Follow-up → Revenue Attribution + BI
```

---

## Status

**All seven phases are built and tested.** See
[`docs/ROADMAP.md`](docs/ROADMAP.md) for the item-by-item state, including the
few things still marked TODO (landing pages, the content library, agency
multi-brand UI — schema exists for all three).

| Shipped | |
|---|---|
| Multi-tenancy | organisations, workspaces/brands, `TenantContext`, verified membership on every request |
| RBAC | 8 roles, 26 permissions, permission-based checks (never role-name checks) |
| Auth | registration, login with dual-axis throttling, password reset, session hardening |
| CRM | contacts, companies, tags, lists, custom fields, Customer 360, merge, anonymise |
| Smart lists | nested AND/OR engine with a whitelisted field registry and relative dates |
| Compliance | append-only consent history, suppression, AU + US rule sets, campaign validation |
| Import | 8-step CSV wizard with a mandatory consent declaration that refuses purchased/scraped lists |
| Unsubscribe | HMAC-signed tokens, preference centre, guaranteed footer |
| Sending | domain verification (DKIM/SPF/DMARC), block-based email builder, approval workflow |
| Send pipeline | recipient snapshot, chunked dispatch, compliance re-checked before the provider call, live-quota throttling |
| Tracking | open pixel and click redirect that cannot become an open redirect, UTM builder |
| Provider events | SES over SNS with full signature verification, bounce/complaint → suppression |
| Reporting | campaign funnel, top links, per-mailbox-provider inbox health, revenue attribution |
| AI | campaign studio, subject lines, smart lists from plain English, post-mortems, a read-only assistant |
| Journeys | triggers, conditions, actions, timers, re-entry control, run logs |
| Enquiries | pipeline, explainable lead scoring, unanswered-enquiry tracking |
| Website | tracking snippet, `/api/v1/events`, conversion API, attribution |
| Forms | hosted and embedded signup forms with versioned consent evidence |
| A/B tests | with a significance gate that refuses to call a winner it cannot justify |
| Text messages | SMS and WhatsApp behind the channel interface, per-channel consent |
| API | `/api/v1` with hashed keys, scopes and per-key rate limits |
| Tests | 426 tests / 1,304 assertions |

### The opinions this codebase holds

Most of what is distinctive here is what the product refuses to do:

- **It will not send to somebody who did not agree.** Compliance is re-checked
  in the worker immediately before the provider call, not just at preview.
- **It will not let AI act.** Every AI feature produces a draft or a suggestion
  for a person to accept. The assistant has no write tool at all.
- **It will not invent a figure.** Every number in AI-written prose is checked
  against the facts it was given; a sentence quoting anything else is dropped.
- **It will not pre-tick a consent box.** Not a setting.
- **It will not call an A/B test it cannot justify** — "too close to call" is a
  legitimate answer, and usually the honest one for a list of 800.
- **It will not treat email consent as permission to text.**
- **It will not show a rate without the number underneath it.**

---

## Architecture at a glance

| Layer | Choice |
|---|---|
| Language | PHP 8.3+ (developed and tested on 8.4) |
| Architecture | lightweight modular PHP — no heavy framework |
| Data access | PDO, prepared statements only |
| Database | MySQL 8+ (canonical); SQLite for the test suite |
| Cache / queue | Redis |
| Frontend | HTML5, a small custom CSS system (see the note below), vanilla JS, Chart.js |
| Web server | Nginx (or Apache) + PHP-FPM |
| Workers | `workers/worker.php` under Supervisor |
| Scheduler | `cron/scheduler.php` every minute, with advisory locks |
| Email | Amazon SES, behind `EmailProviderInterface` |
| AI | OpenAI, behind `AiProviderInterface` |

The core has **no runtime package dependencies**. A PSR-4 fallback autoloader
means migrations, the console and the whole test suite work on a fresh clone
before `composer install` has run.

Full detail: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

### Project structure

```
app/
  Core/          container, router, request/response, session, CSRF, validator, view, logger
  Database/      connection, query builder, dual-grammar schema builder, migrator
  Models/        thin entities
  Repositories/  all SQL, tenant-scoped by construction
  Services/      business rules
  Compliance/    ComplianceService, CampaignValidator, ReasonCode
  Mail/          EmailProviderInterface, SES + log providers, renderer
  AI/            AiProviderInterface, OpenAI + null providers, AiGuard
  Queue/         queue abstraction and drivers
  Middleware/    cross-cutting request concerns
  Controllers/   HTTP entry points
config/          app, database, security, rbac, compliance, segments, plans, …
database/        migrations/, seeds/
public/          index.php (the only executable), assets/
resources/views/ layouts, partials and screens
routes/          web.php, api.php
storage/         logs/, uploads/, framework/
workers/         worker.php
cron/            console.php, scheduler.php
tests/           Unit/, Feature/, Security/, run.php
docs/            ARCHITECTURE, DATABASE, COMPLIANCE, ROADMAP, DEPLOYMENT
```

### One deliberate deviation from the brief

The brief specifies Bootstrap 5. The UI instead ships a ~16 KB hand-written CSS
system in `public/assets/css/app.css`, for three reasons:

1. The brief also asks for a UI that feels like *modern, clean, minimal,
   premium B2B SaaS* and explicitly warns against *overly colourful admin
   templates*. Reaching that with Bootstrap means overriding most of its visual
   layer anyway.
2. The content security policy is strict. One fewer CDN dependency is one fewer
   thing to allowlist and one fewer external failure mode.
3. It is smaller than the Bootstrap override file it would otherwise need.

Adopting Bootstrap 5 is still straightforward if you want it: add the CDN link
to `resources/views/layouts/{app,auth,public}.php` (already permitted by the
CSP), and rename the colliding class names in `app.css` — `.btn`, `.card`,
`.badge`, `.alert`, `.table`. Nothing in PHP depends on the class names.

Chart.js is used as specified, loaded from the allowlisted CDN.

---

## Requirements

- PHP 8.3+ with `pdo_mysql`, `mbstring`, `openssl`, `json`, `curl`
- MySQL 8.0+
- Redis 6+ (queue, rate limiting, scheduler locks) — optional in development
- Composer (for dev tooling and the AWS SDK)
- Nginx or Apache, Supervisor, Cron

---

## Installation

```bash
git clone <repo> ai-growth-hub && cd ai-growth-hub
composer install                 # optional for the core; required for SES
cp .env.example .env
php cron/console.php key:generate
```

Create the database:

```sql
CREATE DATABASE ai_growth_hub CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'ai_growth_hub'@'localhost' IDENTIFIED BY '<strong-password>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES
  ON ai_growth_hub.* TO 'ai_growth_hub'@'localhost';
```

Then:

```bash
php cron/console.php migrate     # 66 tables
php cron/console.php db:seed     # roles, permissions, plans, compliance rules
php -S localhost:8000 -t public  # development server
```

Open <http://localhost:8000/register>.

Or create an organisation from the command line:

```bash
php cron/console.php org:create "Perth Plumbing Co" owner@example.com
```

### Environment

Every variable is documented inline in [`.env.example`](.env.example). The ones
that matter most:

| Variable | Notes |
|---|---|
| `APP_KEY` | **required**. Signs unsubscribe tokens and encrypts stored secrets. `key:generate` writes it. |
| `APP_DEBUG` | **must be `false` in production** — it is the only thing that renders a stack trace. |
| `MAIL_PROVIDER` | `ses` \| `log`. `log` writes messages to a file instead of sending. |
| `AI_PROVIDER` | `openai` \| `null`. Without a key the AI refuses rather than fabricating. |
| `QUEUE_DRIVER` | `redis` \| `database` \| `sync`. `sync` is test-only. |
| `SESSION_SECURE`, `FORCE_HTTPS` | `true` in production. |

`.env` is gitignored and must never be committed.

---

## Console

```bash
php cron/console.php migrate            # apply migrations
php cron/console.php migrate:status     # what is applied
php cron/console.php migrate:rollback   # undo the last batch
php cron/console.php db:seed            # idempotent seeders
php cron/console.php db:check           # verify core tables
php cron/console.php key:generate       # write APP_KEY
php cron/console.php schema:sql mysql   # print the full DDL without a database
php cron/console.php route:list         # every route and its handler
php cron/console.php org:create "Name" owner@example.com
php cron/console.php down | up          # maintenance mode
```

---

## Workers, scheduler and Redis

Bulk email is **never** sent inside an HTTP request.

```bash
# Queue worker (Supervisor keeps these alive in production)
php workers/worker.php --queue=email_high_priority,email_transactional --sleep=1
php workers/worker.php --queue=email_marketing --sleep=1 --max-jobs=500
php workers/worker.php --queue=email_retry --sleep=5
```

Queues drain strictly left to right, so a password reset never waits behind a
100k blast. Workers exit voluntarily after `--max-jobs` or a memory ceiling and
are restarted by Supervisor; they also shut down gracefully on `SIGTERM`,
finishing the job in hand.

Retry backoff is 1m → 5m → 30m → 2h, then dead-letter. Permanent failures are not
retried at all.

One cron entry:

```cron
* * * * * cd /var/www/aigrowthhub && php cron/scheduler.php >> storage/logs/scheduler.log 2>&1
```

Every scheduled job takes a named, expiring lock, so an overlapping minute cannot
double-run anything.

Supervisor configuration: [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).

---

## Amazon SES and SNS

1. Verify your sending domain and publish the three DKIM CNAMEs, plus SPF and
   DMARC. The in-app wizard shows the exact records.
2. Create a configuration set → `AWS_SES_CONFIGURATION_SET`.
3. Create an SNS topic and subscribe it (HTTPS) to `/webhooks/aws/ses`. Enable
   `SEND`, `DELIVERY`, `OPEN`, `CLICK`, `BOUNCE`, `COMPLAINT`, `REJECT`,
   `RENDERING_FAILURE`.
4. Request production access. **Sandbox accounts are rate- and
   recipient-restricted, and quotas differ per account and Region** — the
   application reads the live quota via `getQuota()` and throttles to it rather
   than assuming a number.
5. IAM: `ses:SendEmail`, `ses:SendRawEmail`, `ses:GetSendQuota`, `ses:GetAccount`,
   `ses:GetEmailIdentity`. Prefer an instance role over static keys.

Inbound SNS messages are signature-verified. Provider events are idempotent on
`(provider, provider_event_id)`, so a redelivery is a no-op.

---

## OpenAI

Set `OPENAI_API_KEY` in the environment only. It is never stored in the database
and never reaches the browser. Every call is recorded in `ai_requests` with token
usage, and capped per organisation per month.

---

## Development

```bash
php -S localhost:8000 -t public
```

For development without MySQL, set `DB_DRIVER=sqlite` and
`DB_SQLITE_PATH=storage/dev.sqlite`. The same migrations render both dialects.

---

## Testing

```bash
php tests/run.php                # everything
php tests/run.php Compliance     # filter by class name
```

The suite runs on in-memory SQLite with a frozen clock, needs no services, and
takes a few seconds. The schema comes from the real migration files, so a test
schema cannot drift from production.

```
OK — 426 tests, 1304 assertions
```

What is covered, and why each one exists:

| Area | What it proves |
|---|---|
| Tenant isolation | a contact, tag, segment or suppression from another organisation is *not found*, not forbidden — a 403 would confirm it exists |
| Permissions | a marketer cannot approve their own campaign; an admin cannot mint an owner; the last owner cannot be removed |
| Australian consent | `country = AU` + `consent = unknown` → **blocked**, `AU_CONSENT_UNKNOWN` (§82) |
| US requirements | a campaign without a physical postal address, a verified domain or an honest subject line cannot be approved |
| Suppression | survives re-import (§83); hard bounce suppresses (§84); complaint suppresses immediately (§85); a transient bounce does **not** |
| Consent history | every change appends; evidence is retained; nothing overwrites |
| Import | purchased and scraped lists are refused before a single row is written |
| Unsubscribe | signed tokens, no ids in URLs, a GET never unsubscribes anyone |
| Provider events | a redelivered event is ignored; tenancy comes from our record, not the payload |
| Queue | backoff schedule, single-worker reservation, stalled-job reclaim, dead-lettering |
| Security | SQL injection, CSRF, IDOR, privilege escalation, session fixation, CSV formula injection, credential redaction |
| AI safety | the guard refuses to send, change consent, clear suppression or write SQL — in code, not in a prompt |

PHPUnit is declared in `require-dev` and is compatible; `tests/run.php` is what CI
uses today because it has no dependencies.

---

## Deployment

Full runbook — Nginx, Supervisor, cron, SES, backups, monitoring and a production
security checklist — in [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).

### Backups

| What | How | Retention |
|---|---|---|
| MySQL | nightly `mysqldump --single-transaction`, encrypted, off-box | 30 daily, 12 monthly |
| Binlogs | enabled and shipped for point-in-time recovery | 7 days |
| `storage/uploads` | nightly sync | 30 days |
| `.env` | secret manager — **never** in git or a DB backup | — |

Rehearse a restore quarterly. A backup that has never been restored is not a
backup.

---

## Security checklist

- [ ] `APP_DEBUG=false`, `APP_ENV=production`
- [ ] `APP_KEY` generated and unique per environment
- [ ] TLS enforced, HSTS on, `SESSION_SECURE=true`
- [ ] Database user has no `SUPER` / `FILE` / `PROCESS` privileges
- [ ] Redis bound to localhost or authenticated
- [ ] SES IAM scoped to send + read-quota only
- [ ] Backups running **and a restore rehearsed**
- [ ] New organisations on conservative sending limits
- [ ] Suppression list exported and verified before the first production send
- [ ] `audit_logs` write path tested

Implemented throughout: `password_hash`/`password_verify`, CSRF on every
state-changing request, prepared statements everywhere, output escaping by
default, session regeneration on privilege change, secure + HttpOnly + SameSite
cookies, login throttling by address *and* IP, expiring single-use password-reset
tokens, hashed API keys, HMAC-signed unsubscribe tokens, a strict CSP, and an
append-only audit log that redacts credentials.

---

## Compliance

The platform provides compliance-**supporting** controls. It does not provide
legal advice, and rules are versioned and configurable because regulation
changes. Read [`docs/COMPLIANCE.md`](docs/COMPLIANCE.md).

The design commitments, in short:

1. **Consent is a history, not a flag.** `contact_consents` is append-only.
2. **Default deny.** Unknown consent where a basis is required means blocked.
3. **Suppression outranks everything** — list membership, segments, re-import.
4. **Evidence is preserved**: wording, source, policy version, IP, user agent.
5. **Checked twice** — at audience preview and again in the worker before the
   provider call. A stale preview never authorises a send.
6. **Marketing ≠ transactional**, structurally, and relabelling is rejected.

---

## What this codebase will not do

Recorded here because they are the mistakes this design exists to prevent:

- Send bulk email inside an HTTP request, or with BCC.
- Trust imported consent, or let an import clear a suppression.
- Let AI send a campaign, change consent, clear a suppression or write SQL.
- Mix tenant data, or accept an `organisation_id` from a request.
- Put an API key or an AWS credential in JavaScript, the database or a log.
- Build a query string from user input, or a 100k-row array in memory.
- Treat email opens as truth.
- Hardcode US-only rules, a single currency, or a single timezone.

---

## Licence

Proprietary. All rights reserved.
