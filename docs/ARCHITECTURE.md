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

## 14. Reporting

Two opinions run through `AnalyticsService`, and both are defended by tests
because both are the kind of thing that quietly decays into a vanity dashboard.

**Clicks over opens.** An open means an image was requested. Apple Mail Privacy
Protection and its equivalents request that image on the recipient's behalf
whether or not anyone looked, so a 60% open rate can mean nothing happened.
A click required a person, a device and an intent. Opens are still reported —
customers expect the number and would distrust a tool that hid it — but they are
labelled as a rough guide, greyed out in tables, and nothing in the product makes
a decision on them.

**A rate is useless without its denominator.** "100% click rate" from four
recipients next to a real campaign's 3.1% invites exactly the wrong conclusion,
so every rate travels with the count it came from, and `highlights()` refuses to
crown a campaign that reached fewer than `analytics.minimum_meaningful_send`
people. When there is not enough data, the answer is "not enough sent yet to
tell" rather than a confident number.

Engagement is measured against *delivered*, not sent: an address that bounced
never had the chance to click. Delivery itself counts an open or a click as
proof of arrival, because a missing delivery notification is a gap in our
knowledge, not evidence the message failed.

The deliverability view breaks results down per mailbox provider, because the
most common shape of a deliverability problem is lopsided — Gmail quietly
junking your mail while Outlook delivers it fine — and a single overall number
hides it completely. Its headline is a sentence, not a percentage: somebody who
has never heard of a complaint rate needs to be told what to do about it.

All of the reporting SQL runs on MySQL and SQLite alike (`SUBSTR` + `INSTR`
rather than `SUBSTRING_INDEX`), so the test suite exercises the same queries
production runs.

## 15. What AI output is treated as

A model's reply is attacker-influenced input. The brief that produced it carries
customer data, and customer data can carry instructions, so everything coming
back from a provider is handled like a proposal from a stranger.

**It becomes blocks, never HTML.** The model picks from five of the thirteen
block types — heading, text, button, divider, spacer — and fills in their
settings. The result then goes through `TemplateService::validateBlocks()` and
`TemplateRenderer::sanitiseRichText()`, the same calls a person's blocks make.
There is no laxer path for AI content, and therefore no route by which a model
can put a script tag, a style attribute or an event handler into an email. The
eight excluded block types are excluded because coupon codes, product prices and
images are commitments a business makes to a customer; a person types those.

**It cannot invent a link.** Any URL in the reply must match the one URL the
brief supplied, compared on host and path. Anything else is replaced with an
empty href, because a plausible URL that 404s gets sent and an empty button gets
fixed.

**It cannot invent a merge field.** Unknown `{{tokens}}` are stripped against
`blocks.merge_fields`, so nobody receives an email addressed to
`{{customer_name}}`.

**Every removal is reported.** Silently dropping a made-up link would leave
somebody looking at a draft with no button and no idea why, so the draft carries
a `warnings` list the screen shows.

**It cannot produce something ready to send.** What the studio creates is a draft,
through the ordinary `CampaignService`, and it goes through validation, review and
approval exactly like one a person wrote. `AiGuard` refuses the send action
outright, and `AiCampaignService::createCampaign()` re-checks that before it
writes anything.

Every call is metered into `ai_requests` against a monthly cap, and only a hash
of the prompt is stored — enough to correlate and de-duplicate, not a permanent
copy of everything a customer has typed about their business. Provider errors go
to the log; the user gets a sentence they can act on.

### Plain English into a smart list

The same idea, and the safety was built two phases ago: the model does not get to
describe a query, it gets to fill in a form. `SegmentCompiler` accepts only
whitelisted field names, only operators valid for that field's type, and binds
every value — so the worst a model can do is name a field that does not exist,
which is rejected with the same error a hand-crafted browser payload gets.

There is no string of SQL on this path, and no code that would start working if
somebody added one. A rule naming `password_hash` is dropped and reported; a
field of `city' OR 1=1 --` never resolves, so nothing is built at all; a *value*
containing SQL is bound as a parameter and matches nobody rather than everybody.
Tests assert each of those.

What the model is genuinely good for is the translation — knowing that "gone
quiet" means `last_engagement` before a date, and "big spenders" means
`customer_value` above a number. It is a phrasebook, not a database client. The
rules it produces land in the ordinary builder where every condition is visible
and editable, with a live count, before anything is saved.

### The model may explain, it may not count

Campaign reviews are the place where fabrication would do the most damage, so
the rule is enforced rather than requested. Every figure comes from
`AnalyticsService` and is handed to the model as fact. What comes back is prose,
and before any of it is shown, **every number in that prose is checked against
the figures we supplied**. A sentence containing a figure we cannot account for
is dropped — not softened, dropped — and the user is told how many sentences went.

The check allows the supplied numbers in the forms a model might write them
(plain, comma-grouped, rounded to 0–2 decimal places), plus integers up to ten,
because "three things to try" and "the first link" are structural rather than
claims, and flagging those would drown the signal. Four-digit years pass too.

If the headline itself does not survive, a measured one replaces it, so the user
still gets a straight answer. Stored reviews are always labelled
`ai_recommendation`, never `calculated` or `observed`: the figures underneath are
measured, the sentence about them is not.

The failure this prevents is not a clumsy sentence. It is a business owner
repeating "that campaign brought in $4,200" to their accountant when nothing of
the sort happened.

## 16. Journeys

A run is a row, not a call stack. Every step reads where the run is, does one
thing, writes down where it went, and returns. Nothing is held in memory between
steps, so a worker dying mid-journey loses at most one step, and a journey that
waits three weeks costs nothing while it waits.

Entering is cheap; stepping is not. `TriggerDispatcher` writes a run row and
returns, so the HTTP request that created a contact never waits on an email, and
importing 40,000 rows does not try to run 40,000 journeys inline. The scheduler
advances runs, in a worker, like everything else that sends.

Four properties, each with a test:

- **A run cannot loop forever.** Steps are counted and capped. A journey wired in
  a circle fails loudly instead of quietly consuming a worker and the
  organisation's sending quota.
- **A contact cannot be re-entered by accident.** Re-entry is off unless the
  author turns it on, and even then there is a cooldown and a lifetime cap. A
  contact re-tagged nightly by an import must not be emailed nightly — and a tag
  re-applied to somebody who already has it is not an event at all.
- **Every step is explainable**, including the ones that did nothing. "Why did my
  customer not get that email" is the question people actually ask, and a silent
  non-send is indistinguishable from a bug.
- **A broken step stops that run, not the journey.** One contact with a deleted
  tag should not stop three hundred others progressing, and a misconfigured
  journey must never roll back the contact record that triggered it.

An automation is the easiest place in a product like this to grow a second,
laxer sending path: it runs unattended, recipients arrive one at a time, and
nobody is watching. So it does not get one. `SendEmailAction` calls the same
`ComplianceService`, at the same moment relative to the provider, as a campaign
does. Journey conditions go through `SegmentCompiler`, so a condition and a smart
list mean the same thing and neither can reach a field the registry never
declared. The action list is short and everything on it is reversible or
visible: nothing deletes a contact, clears a suppression or changes consent,
because 3am unattended is the worst possible place for an irreversible action.

## 17. Attribution, and what it is honest to claim

Attribution is a decision, not a fact, and the honest way to handle a decision is
to record it when it is made and stand by it. The attributed campaign is written
onto the conversion row together with the model and the window that produced it,
so changing the window next month does not silently rewrite last month. A
business that cannot reconcile two printouts of the same quarter stops trusting
the tool.

The default is last click within a configurable window — the least sophisticated
model available, chosen because it is the one a customer can check by hand:
"they pressed the link in Tuesday's email, then booked on Thursday". A model
nobody can verify is a model nobody should believe.

A click always beats an open. An open may mean a mail server fetched an image.

Attributed revenue is always reported *next to* the total, never instead of it.
"£8,400 of £31,000" is an honest claim; "email generated £8,400" invites the
reader to think email did all the work.

Conversions are idempotent on `(organisation, external_id, type)`, because every
payment gateway retries its webhook and the day's takings must not double.

### Website events

Two rules hold throughout, because this is the part of the product most likely
to become a privacy problem:

**Nothing is identified until somebody identifies themselves.** Anonymous
browsing is stored against a random id the visitor's own browser generated.
It becomes a person only when they click a link in an email or fill in a form —
a deliberate act by them, not a fingerprint we assembled. There is no IP or
user-agent matching anywhere on this path. Earlier anonymous events are
backfilled at that point, which is the whole reason for keeping the id.

**Query strings are stripped before storage.** Real websites put session tokens,
password reset links and email addresses in them, and none of that belongs in an
analytics table. UTM values are passed separately and kept on purpose.

The snippet authenticates with a key scoped to `events:write` — it can post
events and read nothing. A key that sits in every page's HTML is a public key
whatever it is called, so it gets the access that assumption deserves.

### Lead scoring

A score is a sorting aid, not a verdict: its only job is to put the enquiry most
worth ringing at the top of somebody's morning. So every lead carries the list of
reasons it scored what it scored, each with its points and a sentence saying why.
A number nobody can account for is a number people ignore, and then they work the
list by date again. "Big job" is measured against that business's own average won
value, so it means something local rather than something invented.

## 18. Where consent actually comes from

Signup forms are the most important part of the compliance story: everything
else in the product defends a permission captured here, and a permission
captured badly cannot be defended at all.

**The consent box is never pre-ticked.** Not configurable, not a setting
somebody can switch on for a better conversion rate. A pre-ticked box is not
consent in any jurisdiction this product targets, and offering it would be
selling a customer a liability dressed as a feature.

**The exact wording is stored with the submission** — the text as it was on
screen at that moment, plus its version, not a reference to the form's current
text. Editing a form bumps its version, so people who submitted yesterday keep
pointing at what they actually read.

**Submitting is not consent.** Somebody filling in "get a quote" asked to be
answered. They agreed to marketing only if they ticked the box saying so, and
the two are recorded separately because they are different things.

**A resubscribe form is the one path that may clear a suppression** — a
deliberate act by the person themselves, audited, and only against an
`unsubscribe`. A hard bounce or a complaint stays put however keen they are.

Bots are handled with a honeypot positioned off-screen rather than
`display:none`, which some form-fillers skip. A caught submission is answered as
if it succeeded and filed as spam: telling a bot it was detected only helps
whoever wrote it, and a false positive is then recoverable rather than a
silently lost customer.

## 19. What counts as a winner

A/B tests have two gates before anything is declared, because the honest answer
for a business with 800 contacts is usually "we cannot tell". Most tools will
call 4.1% a winner over 3.8% on a sample of two hundred, which is noise dressed
as insight — and the customer changes their whole approach on the strength of it.

1. **Enough people.** Below 100 delivered per variant, no result is reported.
2. **Enough difference.** The gap must exceed what chance would produce at this
   sample size, by a two-proportion z-test at 95%.

When neither wins, that is said plainly, with what would have to change. The
sample is split round-robin rather than randomly, because randomising 200 people
can easily give 120/80 and then the comparison is between two different-sized
groups before anyone has opened anything. Clicks decide it, never opens.

## 20. Texts are not email

`contact_consents` has always had a `channel` column, and this is what it was
for. **Agreeing to email is not agreeing to texts.** A contact who ticked a box
about your newsletter has not said you may text them, and treating one
permission as the other is the most likely way a business using this product
gets into trouble — texts are personal, people notice them, and the complaint
goes to a regulator rather than a spam folder. `TextMessageService::canSend()`
checks consent for `sms` specifically, and a journey's `send_sms` action goes
through the same service so there is no looser second path.

Opting out works per channel in both directions: replying STOP suppresses the
number and withdraws SMS consent, and leaves their email subscription alone.

Two things a text needs that an email does not:

**Quiet hours.** Email arriving at 2am is ignored until morning; a text wakes
somebody up, and they blame the business. Marketing texts outside the window are
*held*, not dropped — the message is still worth sending, just not now. The
window is evaluated in the recipient's timezone where we hold one.

**A cost warning.** Email is effectively free per message and texts are not, so
the product says what a send will cost before anybody presses the button, counts
segments rather than characters, and quantifies the saving from trimming a
two-segment message to one. A channel that hides its cost until the invoice is a
channel that loses the customer.

The opt-out instruction is appended in code rather than trusted to whoever wrote
the message — it is the one line nobody remembers to type, and the fastest route
to a complaint when it is missing.

## 21. The assistant

The only place in the product where a model decides what to look at rather than
being handed a fixed set of facts. Three decisions make that safe, and each one
is a thing a product like this is usually tempted to do differently.

**It picks from a menu; it does not write a query.** `AssistantTools` is the
assistant's entire world: seven methods, each answering one fixed question with
a number the product already computes, scoped to the bound tenant by the
services underneath. There is no field name, table, filter or fragment of SQL
anywhere on the path, and no general query tool to be talked into misusing. A
tool the model invents is simply not called; a number of days is coerced into
`1..365` whatever arrives.

**It cannot do anything, only say things.** There is no write tool to argue
about. When it concludes something should happen, it says so and links to the
screen where a person does it — and those links are allow-listed, because a
model writing its own URL is a way to put an arbitrary link in front of somebody
who trusts the product. A test scripts it claiming to have unsubscribed everyone
and sent a campaign, then asserts nothing changed.

**Every number is checked before it is shown**, reusing `verifyProse()` from the
post-mortem rather than growing a second copy — a second copy is how one of them
quietly stops checking. An assistant is the easiest place to produce a
confident, wrong figure, because it sounds like it has been looking things up.

Two rounds at most: ask for what you need, get it, answer. An open-ended loop
would spend somebody's monthly allowance on one question.

## 22. Tracking and what it is worth

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
