# Upgrade: send reliability, rate limiting and session revocation

One migration (`2026_02_01_000001_send_reliability_and_security`) and the code
that depends on it. Read this before running it on a database that has already
sent mail.

## What changes in the data

| Table | Change | Why |
|---|---|---|
| `campaign_recipients` | `send_status` gains `sending` and `outcome_unknown` | The old vocabulary had no way to say "in flight", so a job had to call a recipient `sent` the moment it picked it up |
| `campaign_recipients` | `claim_token`, `claimed_at`, `claim_expires_at` | Only the worker holding the claim may advance the row |
| `campaign_recipients` | `provider_attempted_at` | The one fact that decides whether an abandoned row is safe to retry |
| `campaign_recipients` | `available_at` | Retry backoff, so the scheduler cannot route around the queue's delay |
| `campaign_recipients` | `budget_day` | Which day's allowance this send already holds |
| `users` | `auth_version` | A password change invalidates sessions on devices this request has never seen |
| `rate_limits` | new | Login/API/public throttling that survives the end of a PHP process |
| `send_reservations` | new | Daily allowance as a reservation rather than a retrospective count |

On MySQL and MariaDB the migration is `ADD COLUMN` plus one `MODIFY` on
`send_status`. On a large `campaign_recipients` table the `MODIFY` rewrites it,
so size it before you start:

```sql
SELECT table_rows, ROUND(data_length / 1048576) AS mb
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = 'campaign_recipients';
```

Under a million rows this is seconds. Much beyond that, run it in a maintenance
window.

## Running it

**1. Stop the cron entries first.** Both of them. A scheduler tick or a worker
running against a half-migrated table is the one way to make this worse.

In hPanel → Advanced → Cron Jobs, disable the scheduler and worker lines. Then
confirm nothing is still going:

```
ps -u "$USER" -o pid,etime,cmd | grep -E 'scheduler|worker' | grep -v grep
```

**2. Back up, and check the backup is real.**

```
cd ~/apps/aigrowthhub
bash deploy/hostinger/backup.sh
ls -lh ~/backups | tail -3
gzip -t ~/backups/<the newest file> && echo "readable"
```

A backup you have not tested is a file, not a backup.

**3. Put the application into maintenance mode.**

```
php cron/console.php down
```

**4. Deploy and migrate.**

```
git pull
php cron/console.php migrate
```

**5. Check the schema landed.**

```
php cron/console.php db:check
mysql --defaults-extra-file=~/.my.cnf -e \
  "SHOW COLUMNS FROM campaign_recipients LIKE 'send_status'" <database>
```

The type should read
`enum('pending','queued','sending','sent','failed','skipped','outcome_unknown')`.

**6. Set the new environment variables.** See `deploy/hostinger/env.production.example`:

```
RATE_LIMIT_STORE=database
QUEUE_CLAIM_LEASE_SECONDS=300
```

`RATE_LIMIT_STORE` matters. Without it the limiter falls back to counting in
memory, and on shared hosting — where every request is a fresh process — that
means no login throttling at all. On a production deployment the application now
refuses to boot rather than quietly doing that.

**7. Bring it back up and re-enable the cron entries.**

```
php cron/console.php up
php cron/console.php send:doctor
```

**8. Watch the first scheduler tick.**

```
ls -l storage/framework/*.heartbeat
tail -40 storage/logs/app.log
```

## Existing data

**Nothing is rewritten, and no historic send is retried.** That is deliberate.

Recipients written by the previous release were marked `sent` before the
provider was called, so some of them were never actually sent — and from the
rows alone there is no way to tell which. A bulk retry would mail everybody who
*was* sent to a second time. That is worse than the problem.

`send:doctor` lists them under **"Marked sent with no message behind it"**. To
settle them you need the provider's own record:

1. In the SES console, open **Reporting → Sending statistics** for the period
   the campaign ran, or query your configuration set's event destination if you
   have one.
2. Compare the addresses the doctor lists against what SES actually sent.
3. Sent after all → leave the row alone.
4. No trace → `UPDATE campaign_recipients SET send_status = 'pending',
   email_message_id = NULL, available_at = NULL WHERE id = ...`, one id at a
   time, and let the scheduler pick it up.

For anything the provider's log cannot settle either, the honest answer is to
leave it and say so. Mailing a customer twice costs more than missing one.

## Rolling back

The `down()` drops `rate_limits` and `send_reservations` and leaves everything
on `campaign_recipients` alone. That asymmetry is on purpose: narrowing
`send_status` would mean rewriting every `sending` and `outcome_unknown` row to
some older value, and every available choice is a lie. `pending` would put
already-delivered email back on the queue. `sent` would claim deliveries nobody
can evidence.

So a rollback keeps the wider column. It costs nothing — the previous release
ignores values it does not know — and it is the only version that cannot resend
mail.

Before rolling the code back:

```
# Stop cron, then park anything in flight so the old code cannot pick it up.
UPDATE campaign_recipients SET send_status = 'pending', claim_token = NULL
 WHERE send_status = 'sending' AND provider_attempted_at IS NULL;
```

Leave `outcome_unknown` rows exactly as they are. The old code does not look at
that status, which is precisely what you want: no version of this application
should be re-queueing a send nobody can account for.

## Acceptance checklist

- [ ] `php cron/console.php db:check` — every table present
- [ ] `php deploy/hostinger/preflight.php` — all checks pass
- [ ] `php cron/console.php send:doctor` — read the findings, even if you act on none
- [ ] `curl -s https://<host>/health/ready` — `rate_limiter` is `true`
- [ ] Both heartbeats under `storage/framework` are current
- [ ] Send one small real campaign (two or three addresses) and confirm:
      recipients reach `sent`, the outbox shows them, the campaign completes
- [ ] Pause it mid-send, let a worker run, resume — everybody still gets their email
- [ ] Six bad logins in a row are refused on the sixth, in a fresh browser
