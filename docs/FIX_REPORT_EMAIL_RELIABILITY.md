# Email reliability and security fixes

Branch `fix/email-reliability-security`, from the review baseline
`1b7d1022c5ca2be86beee79183fdf1257f686f31`.

Eight commits. Native PHP throughout, PHP 8.3+ as `composer.json` requires, no
new dependencies, MySQL/MariaDB on shared hosting with the database queue and
cron. No production database was touched, no email was sent, and no paid service
was called.

---

## 1. An email was reported as sent before anything was sent

**P1 — silent data loss.** `e0426ed`

### Reproduction

`CampaignRecipientRepository::claimForSending()` set `send_status = 'sent'`
before rendering, before the message row existed, and before the provider had
been called at all. `markSent()` also ran before the provider call.

Anything that threw in between — a template error, a link-rewrite failure, a
dead database connection, the worker being killed by the shared host — left a
row claiming an email had been sent that nobody had tried to send. Because the
claim only accepts `pending` or `queued`, the redelivered job could never pick
it up again. The send was lost in silence and the campaign reported success.

`attempt_count` was the constant `1`, so "how many times have we tried this"
had no answer.

### Root cause

One write was doing two jobs: taking the work, and recording the outcome. Those
are different facts, separated by a network call to a third party, and the state
machine had no value for "in progress".

### What changed

`send_status` gains `sending` and `outcome_unknown`, and `sent` is written in
exactly one place — after the provider has accepted the message. *Accepted*, not
delivered; delivery is a separate fact that arrives later by webhook.

The row moves to `sending` under an ownership token with a lease. Every write
after the claim carries that token, so a worker whose lease lapsed cannot stamp
its stale conclusion over a row somebody else has since taken and finished.

`provider_attempted_at` is stamped immediately before the provider call, and it
is the single fact that makes a dead worker's row interpretable:

| `provider_attempted_at` | What is certain | What happens |
|---|---|---|
| `NULL` | the request never left this process | back on the queue, safely |
| set | it may be in somebody's inbox | `outcome_unknown`, held for a person |

A provider call that does not come back is recorded as `outcome_unknown` and
`PermanentFailure` stops the queue retrying it — a blind retry there is how one
person receives the same email twice.

### Files

- `database/migrations/2026_02_01_000001_campaign_send_reliability.php`
- `app/Repositories/CampaignRecipientRepository.php`
- `app/Jobs/SendCampaignEmail.php`
- `app/Services/CampaignDispatcher.php`

### Trade-offs

**`send_status` stops being an ENUM on MySQL and becomes `VARCHAR(24)`.** Every
new state would otherwise be another `ALTER` on a table that grows without
bound, and the application validates these values anyway. SQLite keeps a CHECK
constraint, because there the column has to be rebuilt either way and the
constraint is free.

**No `needs_reconciliation` campaign status.** It would mean rebuilding a
58-column table on SQLite for a flag that is already derivable. Instead
`remaining()` counts `sending` and `outcome_unknown`, so a campaign cannot
report itself complete with work in flight or with a message nobody can account
for. A campaign held open by unknowns looks stuck because it is.

**On acceptance the recipient is closed first and the message row second.** If
the second write fails the campaign has a reporting inconsistency, not a live
risk of sending again.

**One extra single-row UPDATE per send** (`provider_attempted_at`), by primary
key. That is the price of never guessing about a message that may already have
been delivered.

---

## 2. A paused campaign could not resume, and lost the people it had queued

**P1 — silent data loss.** `e0426ed`

### Reproduction

Queue a campaign (recipients → `queued`), pause it. The job reaches
`SendCampaignEmail`, sees `status = 'paused'` and returns. The worker
acknowledges the job away — the job row is deleted. The recipient stays
`queued`, which is a state the dispatcher never looks at, because it only ever
re-queues `pending`.

On resume there was no job and no row anybody would look at. Those people were
never written to and nothing said so. `remaining()` counted them, so the
campaign also never completed.

### Root cause

`queued` means "a job exists for this". Once the job was acknowledged away that
was no longer true, and nothing reconciled the two.

### What changed

`CampaignService::pause()` sets the campaign status **first**, so nothing new
can claim, then returns the queued rows to the pool.

Only `queued`. A row a worker is holding right now is left alone: taking it back
mid-flight is how pause causes the duplicate it was pressed to prevent. The job
also abandons the send outright if it cannot stamp `provider_attempted_at`,
which is the case where its lease has been taken from it.

`cancel()` gets its own path rather than sharing pause's. It closes the
outstanding rows permanently with `CAMPAIGN_CANCELLED`, so no resume,
redelivered job or scheduler tick can send them.

### Files

- `app/Services/CampaignService.php`
- `app/Repositories/CampaignRecipientRepository.php` (`releaseForPause`,
  `skipForCancellation`)
- `app/Compliance/ReasonCode.php`
- `app/Jobs/SendCampaignEmail.php`

### Trade-off

A paused campaign's in-flight rows are left to the lease sweep rather than
reclaimed immediately. Pause is therefore not instantaneous for the handful of
sends already past the provider call — correct, because those have already left.

---

## 3. Retries and the scheduler disagreed, and retries never ran out

**P1.** `cd0bad4`

### Reproduction

A transient provider failure put the recipient straight back to `pending` and
pushed a retry job with a backoff. The scheduler re-queues `pending` every
tick. So the retry queue waited politely for two hours while the scheduler
hammered the same recipient once a minute: the delay the provider asked for only
ever applied to one of the two things acting on it.

Separately, each tick created a brand new **first-attempt** job. The queue's
ceiling of four attempts therefore applied to individual jobs that the scheduler
kept replacing, never to a recipient. A permanently failing recipient was
retried for ever.

### What changed

The recipient carries `retry_after`, set to the same backoff the queue is about
to apply, and the candidate scan skips rows still waiting it out. Both read the
same clock.

Recipients that have used every attempt are closed as `failed` before the scan,
and the scan itself will not hand out a row at or over the ceiling.

Expired leases are swept once per tick, split on `provider_attempted_at` as in
§1.

### Files

- `app/Services/CampaignDispatcher.php`
- `app/Repositories/CampaignRecipientRepository.php` (`pendingBatches`,
  `failExhausted`, `sweepExpiredLeases`, `release`)

---

## 4. Rate limiting was switched off in production

**P1 — security.** `268ce29`

### Reproduction

`Application` bound `RateLimitStore` from `queue.driver`. Anything other than
Redis — which on Hostinger means `database`, the only driver shared hosting can
run — fell through to `ArrayRateLimitStore`, a PHP array.

Every HTTP request is a fresh process, so every request started with an empty
array. Login throttling counted to one and reset. The API-key bucket never
bound. The public endpoints could be hit as fast as a client could open
connections. The protection existed in code and did nothing.

Demonstrated, after the fix, across seven separate PHP processes:

```
pid 2978  store=DatabaseRateLimitStore  outcome=rejected (401)   counter=1
pid 2979  store=DatabaseRateLimitStore  outcome=rejected (401)   counter=2
pid 2980  store=DatabaseRateLimitStore  outcome=rejected (401)   counter=3
pid 2981  store=DatabaseRateLimitStore  outcome=rejected (401)   counter=4
pid 2982  store=DatabaseRateLimitStore  outcome=rejected (401)   counter=5
pid 2983  store=DatabaseRateLimitStore  outcome=throttled (429)  counter=5
pid 2984  store=DatabaseRateLimitStore  outcome=throttled (429)  counter=5
```

Before the fix every line would read `counter=1`.

### What changed

Rate limiting has its own configuration instead of inheriting the queue's:

```
RATE_LIMIT_STORE=database|redis|array|auto
```

`auto` and `redis` try Redis and fall back to the database with a warning;
anything else uses the database. `array` is honoured only under the testing
environment or with `RATE_LIMIT_ALLOW_INSECURE=1` — otherwise it logs an error
and uses the database, so a mis-set variable cannot silently disable throttling.

`DatabaseRateLimitStore` counts in a `rate_limits` table through a single atomic
upsert per hit — `ON CONFLICT` on SQLite, `ON DUPLICATE KEY` on MySQL — whose
`CASE`/`IF` resets the counter when the stored window has already expired. Two
concurrent FPM workers can neither lose an increment nor revive a dead window.

The scheduler prunes expired rows every 60 seconds. `/health` reports rate
limiting as unready when the in-memory store is active outside tests, and
`deploy/hostinger/preflight.php` fails on `RATE_LIMIT_STORE=array`.

### Files

- `app/Core/DatabaseRateLimitStore.php` (new)
- `database/migrations/2026_02_01_000002_persistent_rate_limits.php`
- `app/Core/Application.php`, `config/security.php`
- `cron/scheduler.php`, `app/Controllers/HealthController.php`
- `deploy/hostinger/env.production.example`, `deploy/hostinger/preflight.php`

### Privacy

Every key reaching the table is already a SHA-256 hash
(`login:email:<hash>`, `login:ip:<hash>`, `throttle:<hash>`, `api:<id>`), so no
address or IP is stored in plaintext — verified on the live table. The store
logs no key material at all.

### Trade-offs

**A table rather than APCu or a file.** Shared hosting gives no shared memory
worth relying on: APCu is per-process-pool and empty after a restart, and a file
needs its own locking to be atomic. The database is already there, already
shared by every request, and already has the one primitive this needs.

**Not tenant-scoped.** A login attempt and an unauthenticated public endpoint
both happen before anybody knows which organisation is involved, and a limiter
that needs a tenant is a limiter that is off for exactly the requests that
matter. The callers already namespace their keys.

**One extra write per rate-limited request.** A single-row upsert on a unique
index, against a table that is swept and stays small.

---

## 5. A password reset did not sign out the stolen session

**P1 — security.** `47b7e04`

### Reproduction

`completePasswordReset()` ended with `$this->auth->logout()`. On the reset flow
the browser making that request belongs to an anonymous visitor who was never
signed in, so this destroyed nothing. Every session already open elsewhere
survived.

Somebody holding a stolen session cookie kept it: the victim changes their
password, is told their account is secure, and the attacker stays signed in
until the cookie ages out a day later. `changePassword()` revoked nothing at
all.

The token was also not single-use under concurrency: the row was read, judged
unused, and only then marked, so two requests arriving together both read
"unused" and both set a password, the second silently overwriting the first.

### What changed

`users.session_version`. The session records the version it was opened under,
every authenticated request compares the two, and incrementing the column
invalidates every session for that account at once. The increment is done in
SQL, so two revocations arriving together both move it forward.

A session that fails the comparison is destroyed rather than merely refused, and
a session carrying no version at all is not accepted — there is nothing to
compare, and a check any session can opt out of by not answering it is not a
check.

A voluntary password change revokes everything too — the usual reason to change
a password is suspecting somebody else has it — but re-stamps the session that
performed it. `sessions_revoked_at` records when, for whoever has to answer
"did my reset actually sign the other device out?".

Redemption is now one conditional UPDATE with the expiry in the same predicate,
inside the transaction that sets the password: the database decides, exactly one
request affects a row, and a failure returns the token rather than burning it.
The password-policy check stays in front of all of it, so a rejected password
does not cost somebody their one valid link. Any other link outstanding for the
account is spent at the same time, and a completed reset clears a lockout.

### Files

- `database/migrations/2026_02_01_000003_session_revocation.php`
- `app/Services/AuthService.php`, `app/Services/AuthManager.php`
- `app/Repositories/UserRepository.php`

### Audit trail

`password_reset_completed` and `password_changed` record that sessions were
revoked, and to whom. No token, no token hash, no password — asserted by a test.

### Trade-offs

**A version column, not a session table.** Sessions here are PHP's own, files on
disk, keyed by session id with the user id inside the serialised payload. There
is no way to enumerate one account's sessions and delete them, and scanning a
shared host's session directory is not something to build a security control on.
Moving the platform to database-backed sessions would also solve it and is a far
larger change than this brief allows.

**Everybody signs in once more after deploy.** Sessions opened before the
migration carry no recorded version, so they cannot be verified and are treated
as stale. That is the fail-closed direction. It is in the deploy checklist
below.

**API keys are deliberately not revoked** by a password reset. They are separate
credentials with their own lifecycle, and silently breaking a customer's
integration is not something to do without asking. If you want that, it is a
small addition — say so and it goes in.

---

## 6. The daily send budget was handed out again every tick

**P2, with a P1 inside it.** `144bcf9`

### Reproduction

`budgetFor()` was the daily limit minus the messages created today. The
dispatcher does not create messages — it enqueues jobs, and the worker writes
the row some queue latency later. So each tick read a counter that had barely
moved and handed out the whole allowance again.

Measured before the fix, with `daily_send_limit = 2` and five recipients:

```
tick 1 queued 2 · tick 2 queued 2 · jobs on the queue: 4
```

The scheduler runs every fifteen minutes, so a tenant capped at 500 a day could
accumulate tens of thousands of jobs, and the workers would send every one. The
limit bounded one tick rather than one day.

The question also had two different answers. `CampaignValidator` counted from
`now()->format('Y-m-d 00:00:00')` — midnight **UTC**, because the clock is UTC —
while the dispatcher used midnight in the **organisation's** timezone. Eight
hours apart for a tenant in +08: the validator could refuse a campaign the
dispatcher would have sent, or clear one it then stalled on, and the number in
the error message matched neither.

### The P1 inside it: a temporary hold was permanent

Suspension and an administrator's sending pause were only ever checked **before**
enqueueing. Jobs already on the queue ran on, reached the per-contact compliance
check — which does test both conditions — and marked their recipient `skipped`
**permanently**, as though each individual contact had been found ineligible.

So an hour's deliverability pause destroyed the outstanding audience:
`remaining()` fell to zero, the campaign reported itself **completed**, and
thousands of people were never written to. Lifting the pause brought none of
them back, because nothing looks at `skipped` again. Observed before the fix:

```
pause-after-queue: skipped/ORG_SENDING_PAUSED ×3 · remaining=0
```

### What changed

`App\Services\SendBudget` is the only thing that answers "how much of today's
allowance is left", for both the dispatcher and the validator. It spends the
allowance when a send is **committed**, not when it is used: a recipient in
`queued` has a job that will send it, and that counts.

The job now checks for an organisation-wide hold **before claiming** and returns
the recipient to the pool with a delay instead — before claiming, so no attempt
is burned either. The dispatcher will not re-enqueue while the hold stands, so
the rows simply wait for it to lift. The same reason arriving from the
compliance check mid-flight takes the same path: what the reason is decides the
treatment, not where it was raised.

### Files

- `app/Services/SendBudget.php` (new)
- `app/Services/CampaignDispatcher.php`, `app/Compliance/CampaignValidator.php`
- `app/Repositories/EmailMessageRepository.php` (`marketingSentSince`)
- `app/Repositories/CampaignRecipientRepository.php` (`committedSends`,
  `deferUnclaimed`)
- `app/Jobs/SendCampaignEmail.php`, `config/mail.php`

### Trade-offs, both towards sending less

**A `sending` recipient is counted by its message row, not as a commitment.** The
worker writes the message row before calling the provider, so counting both
would double-count. Between claiming a recipient and writing that row it is
counted by neither: milliseconds per in-flight send, bounded by worker
concurrency.

**Outstanding commitments are counted whatever day they were made on.** Work
enqueued yesterday and still queued is still going to be sent, and the honest
question is "how much more may I commit right now". A queue backed up past a
whole day's allowance stops new work, which is the correct response to it.

**A failed handoff still counts against the limit.** It is an anti-abuse ceiling
on attempts; counting only successes lets a tenant with a failing provider retry
straight past it. This is also why a transient failure leaves two
`email_messages` rows for one recipient — pre-existing, intentional behaviour
that keeps "we tried twice" answerable, with `sent_count` reporting one.

---

## 7. Migrations

Three, all additive, all applied and rolled back on MariaDB 10.11.

| Migration | What it does |
|---|---|
| `2026_02_01_000001_campaign_send_reliability` | widens `send_status`; adds `lock_token`, `claimed_at`, `lease_expires_at`, `last_attempt_at`, `provider_attempted_at`, `retry_after`; adds `idx_campaign_recipients_lease` |
| `2026_02_01_000002_persistent_rate_limits` | creates `rate_limits` |
| `2026_02_01_000003_session_revocation` | adds `users.session_version`, `users.sessions_revoked_at` |

Rendered MySQL DDL (`php cron/console.php schema:sql mysql`), verified against
the live schema afterwards:

```sql
ALTER TABLE `campaign_recipients` ADD COLUMN `lock_token` VARCHAR(64) NULL;
ALTER TABLE `campaign_recipients` MODIFY COLUMN `send_status`
  VARCHAR(24) NOT NULL DEFAULT 'pending';
CREATE INDEX `idx_campaign_recipients_lease`
  ON `campaign_recipients` (`send_status`, `lease_expires_at`);
ALTER TABLE `users` ADD COLUMN `session_version` INT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `users` ADD COLUMN `sessions_revoked_at` DATETIME NULL;
```

### No `hasColumn()` guards, and that is not an oversight

`SchemaDumper` renders every migration through a MySQL grammar over an empty
SQLite handle, capturing statements and never executing them. A guard is a
*query*, not a statement, so it escapes that capture and hits a database with no
schema in it — which is exactly how the first draft of migration `000001` broke
`SchemaCollationTest`. Migrations run once, tracked in the `migrations` table.

### Rollback is deliberately narrow

`down()` drops the added columns but does **not** narrow `send_status` back.
Rows may legitimately be `sending` or `outcome_unknown` and there is no honest
value to put them in; a rollback that rewrote those to `pending` would re-queue
mail that may already have been delivered. Verified: after rolling back batch 2,
`send_status` remains `varchar(24)` and the six added columns, the index and the
`rate_limits` table are gone.

### Historical data is NOT rewritten

Existing `sent` rows are left exactly as they are. Some of them are the §1 bug's
output and were never really sent. **This migration will not guess which, and
nothing in the application will resend them.** A real send and a phantom send
are indistinguishable from the database.

Find them after deploying:

```
php cron/console.php send:doctor
```

The first finding, *"Recipients marked sent with no message record"*, is exactly
that set. For each one, check the provider's own log and then either leave it
(it was sent) or set it to `pending` so the next tick sends it. There is no bulk
operation for this on purpose.

---

## 8. The reconciliation report

```
php cron/console.php send:doctor          # for a person
php cron/console.php send:doctor --json   # for a script
```

Read-only, always. Every state it reports is one where sending again might put a
second copy of an email in somebody's inbox, so the decision belongs to a person
with the provider's records in front of them. The last line of the report says
it changed nothing; a test asserts that two consecutive runs leave every row
byte-identical.

It opens with when the scheduler and the worker last ran, because almost every
finding means something different depending on the answer, then reports: sent
with no message record · outcome unknown · lapsed leases · queued work on a
campaign that is not sending · eligible work not being enqueued · past the
attempt ceiling · a job in `failed_jobs` whose recipient still looks live ·
campaigns sending with nothing ready · unpruned rate-limit rows. Each finding
carries what to do about it.

**Email addresses are masked, with no flag to turn it off.** The row id is
printed instead, which is what somebody entitled to see the address uses to look
it up through the application. A diagnostic that dumps a tenant's address list
into a terminal, a CI log or a support ticket is waiting for somebody to paste
it. Asserted by a test that the plaintext address appears nowhere in the output,
in any form.

---

## 9. What was run

Everything below was run in this session. Exact commands and exact results.

### Static analysis

```
$ for f in $(find app config cron database routes tests workers public bootstrap -name '*.php'); \
    do php -l "$f"; done
ok: 302 files, 0 failures
```

PHP 8.4.19. `composer.json` requires `>=8.3`; no 8.4-only syntax was used.

### The suite

```
$ php tests/run.php
OK — 518 tests, 1587 assertions in 31.58s
```

Green at the baseline (482 tests, 1472 assertions) and after every commit. 36
tests and 115 assertions added:

| File | Covers |
|---|---|
| `tests/Security/SessionRevocationTest.php` (9) | §5 — revocation, the kept session, the unversioned session, single-use links, lockout clearing, the audit record |
| `tests/Feature/DatabaseQueueSendTest.php` (7) | §1–§3 on the real database queue |
| `tests/Feature/SendDiagnosticsTest.php` (12) | §8 — each finding, masking, and that nothing changes |
| `tests/Feature/CampaignSendTest.php` (+7) | §6 — the budget across ticks, the org day boundary, the hold |
| `tests/Feature/CampaignValidationTest.php` (+1) | §6 — validator and dispatcher agreeing |

Each new test was checked against the bug it describes by temporarily restoring
the old behaviour. With `AuthManager`'s version check disabled, four session
tests fail. With `releaseForPause()` returning 0, the database-queue pause test
fails with four recipients stranded in `queued`. A test that passes either way
proves nothing, so this was checked rather than assumed.

### MySQL / MariaDB

Against MariaDB 10.11.14 in this container — never a production database.

```
$ php cron/console.php migrate            # 11 migrations, clean
$ php cron/console.php migrate:rollback   # batch 2 only, clean
$ php cron/console.php migrate            # re-applied, clean
$ php tests/mysql/verify.php
OK — 27 checks passed on mysql
```

`tests/mysql/verify.php` is new and committed, so this is repeatable rather than
a claim. It covers the statements the SQLite suite can never execute: the
`ON DUPLICATE KEY` upsert and its window-expiry reset, `DELETE ... LIMIT` in the
prune, the raw UPDATEs carrying the send pipeline's concurrency control (claim,
lose a claim, token-guarded writes, both branches of the lease sweep,
`deferUnclaimed`, `committedSends`), the session-version increment, and the
password-reset redemption UPDATE affecting one row and then none.

### Full end-to-end on the production stack

MariaDB + `QUEUE_DRIVER=database` + the real `cron/scheduler.php` and
`workers/worker.php` binaries + `MAIL_PROVIDER=log`, so nothing left the server.

```
scheduler tick   → campaign sending, 4 job rows, 4 recipients queued
pause            → campaign paused
worker drain     → 0 jobs, 4 recipients PENDING, 0 messages, 0 failed_jobs
resume           → campaign sending
scheduler tick   → re-enqueued
worker drain     → 4 recipients sent
scheduler tick   → campaign COMPLETED, sent_count 4, 4 messages, 0 failed_jobs
send:doctor      → "Nothing needs attention."
```

This is §2's fix on the stack that actually broke. Before it, the four
recipients after the drain would be stranded in `queued` with no job.

### NOT RUN

**`tests/run.php` against MySQL.** The harness pins SQLite in
`Application::bootForTesting()` and `TestCase::migrate()` replays the schema per
test from `dump('sqlite')`. Retargeting it means rewriting the harness —
outside this brief, and 518 full-schema rebuilds on MySQL would be minutes per
run. Covered instead by `tests/mysql/verify.php` and the end-to-end run above.
If you want the whole suite MySQL-capable, that is its own piece of work.

**Redis-backed rate limiting.** `ext-redis` is loaded here but no server runs.
The fallback path was exercised — `auto` tried Redis, failed and used the
database with a warning, which is the designed behaviour and what Hostinger will
do. `RedisRateLimitStore` itself is untouched by this change.

**Amazon SES.** No paid service was called, per the brief. `MAIL_PROVIDER=log`
throughout. The provider interface is unchanged.

**Concurrency under real contention.** The atomic UPDATEs were verified by
sequence (first call affects one row, second affects none), which is what the
predicate guarantees, not by two processes racing. Proving the race would need a
load harness this brief does not ask for.

**Anything through a browser.** No UI was changed. `CampaignValidator`'s daily-
limit message gained ", N already queued"; that renders through the existing
findings list.

---

## 10. Deploying to Hostinger

### Before

1. **Back up the database.** `sh deploy/hostinger/backup.sh` from the
   application root. The migrations
   are additive but `send_status` changes type on a table that may be large.
2. **Check how long migration `000001` will take.**
   `SELECT COUNT(*) FROM campaign_recipients;` Under ~100k rows it is seconds.
   Above that, the `MODIFY COLUMN` rewrites the table — do it in a maintenance
   window.
3. **No campaign in flight.** `php cron/console.php send:doctor` should show
   `Recipients committed 0` and `Jobs waiting 0`. Pause anything sending and let
   the workers drain first. Migrating mid-campaign is safe but it means
   reconciling by hand afterwards.
4. **Tell people they will be signed out.** Everyone signs in once more (§5).

### Deploy

```bash
cd ~/apps/aigrowthhub                           # where DEPLOY_HOSTINGER.md
                                                # puts it: outside any domain
                                                # folder, so deleting a domain
                                                # cannot delete the APP_KEY
php cron/console.php down                       # maintenance mode

git fetch origin && git checkout fix/email-reliability-security

# add to .env:
#   RATE_LIMIT_STORE=database
# and confirm QUEUE_DRIVER=database

php deploy/hostinger/preflight.php              # must pass, including the
                                                # new "Rate limits survive
                                                # the request" check
php cron/console.php migrate
php cron/console.php up
```

### Acceptance

```bash
php cron/console.php db:check                   # tables present
php cron/console.php send:doctor                # read the whole report
```

1. **Sign in.** You will be asked to, even if you were signed in before. That
   is §5 working.
2. **Change your password** under Profile. You stay signed in on that tab.
3. **Reset a password** by email on a second browser, then reload the first.
   The first is signed out. That is the fix.
4. **Five bad logins** on one account give a 429 on the sixth, and
   `SELECT COUNT(*) FROM rate_limits;` is non-zero. Before this change the
   counter never reached two.
5. **Send a small campaign** — five contacts. Watch `campaign_recipients`:
   `pending → queued → sending → sent`. `sent` must never appear before a row
   exists in `email_messages`.
6. **Pause one mid-send**, let the workers drain, and check nobody is left in
   `queued`. Resume, and check everybody is written to exactly once.
7. **Check the daily limit** holds across ticks: set a low
   `daily_send_limit`, run the scheduler twice without the worker, and count
   the `jobs` rows. It must equal the limit, not twice it.
8. **Confirm the scheduler is pruning:**
   `SELECT COUNT(*) FROM rate_limits WHERE expires_at <= UTC_TIMESTAMP();`
   should stay near zero.

### Rolling back

```bash
php cron/console.php down
php cron/console.php migrate:rollback   # drops the three migrations' changes
git checkout <previous-ref>
php cron/console.php up
```

`send_status` stays `VARCHAR(24)` on purpose, as above. **Before rolling back,
settle every `sending` and `outcome_unknown` row** — the old code has no meaning
for either, and will treat them as not-sent. `send:doctor` lists them; do that
first or the rollback re-sends mail.

---

## 11. What still needs a person

### Reconciling the past

`send:doctor` will report historical rows marked `sent` with no message record.
Each one is either a real send or the §1 bug's output, and nothing can tell them
apart from here. **Check the provider's log before resending anything.**
Resending a campaign to somebody who already received it is worse than the
original bug.

### Unknown outcomes, from now on

When a provider call does not come back, the row becomes `outcome_unknown` and
holds its campaign open. That is deliberate: the campaign is not finished,
because nobody knows whether it is. Each one needs looking up in the provider's
log by `provider_attempted_at` and setting to `sent` or `pending`. There is no
automatic path and there should not be.

A busy provider incident could produce a lot of these at once. They are a flat
list in the report; if that becomes routine, a screen for it is worth building.

### Residual risks

- **The claim-to-message-row window.** A worker killed in the few milliseconds
  between claiming a recipient and writing its message row leaves a send counted
  by neither budget term. Bounded by worker concurrency, and it under-sends
  rather than over-sends.
- **Two message rows per retried recipient.** Pre-existing and intentional; both
  count against the daily allowance, so a tenant with a flapping provider
  reaches their limit on fewer delivered emails.
- **`QueueRunner` mirrors the worker's loop** rather than sharing code with it.
  Extracting that loop is a refactor of production code to suit a test, which
  this brief does not allow. If `workers/worker.php` changes its error handling,
  `tests/Support/QueueRunner.php` has to change with it; both say so.
- **The queue-depth check in `send:doctor` is approximate.** It compares two
  counts rather than joining a recipient to its job, because matching means
  reading inside a JSON payload. Small differences are normal; react to a large
  one.
- **Sessions are still PHP files on disk.** `session_version` revokes them
  correctly, but the session store itself is unchanged. Database-backed sessions
  would be a real improvement and a much larger change.
