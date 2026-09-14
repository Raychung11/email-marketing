# Deploying to Hostinger over SSH

This is the step-by-step for putting the application on Hostinger shared
hosting, pulling the code with a GitHub deploy key. Allow about half an hour.

For a normal VPS, see [DEPLOYMENT.md](DEPLOYMENT.md) instead — that setup has
Redis and Supervisor, which shared hosting does not.

Values used throughout, taken from what you already have:

| | |
|---|---|
| Site | `https://steelblue-mule-213168.hostingersite.com` |
| Database | `u822252863_smartemail` |
| SSH username | `u822252863` |
| SSH port | `65002` |
| Branch | `claude/brave-johnson-bf6c90` |

> The SSH username above is inferred from the database prefix, which is how
> Hostinger names them. Confirm it, and get the host, in **hPanel → Advanced →
> SSH Access** before you start. That page also has an on/off switch — SSH is
> off by default on some plans.

---

## Before you start: two things that will bite you

**Hostinger runs MariaDB, not MySQL 8.** They are not the same database. MariaDB
has never had the `utf8mb4_0900_ai_ci` collation and refuses any `CREATE TABLE`
that asks for it. The shipped configuration uses `utf8mb4_unicode_ci`, which
both accept. If you change `DB_COLLATION`, the preflight script will tell you
whether the server actually has what you picked.

**The document root is the whole security model here.** Only the `public/`
directory is meant to be reachable from the internet. If the document root ends
up one level too high, `https://your-site/.env` returns your database password
and the key that decrypts every stored API credential. Step 5 sets this up and
step 9 verifies it against the live site. Do not skip step 9.

---

## 1. Connect

```bash
ssh -p 65002 u822252863@<host-from-hpanel>
```

Hostinger asks for your hPanel password (or a key, if you added one). Once in:

```bash
pwd        # /home/u822252863
```

## 2. Choose PHP 8.3

**hPanel → Advanced → PHP Configuration.** Select **8.3** or newer.

Under **PHP extensions** make sure these are ticked: `pdo_mysql`, `mbstring`,
`openssl`, `curl`, `json`.

The command line can run a different PHP version from the website. Check:

```bash
php -v
```

If it reports something older than 8.3, find the right binary and use it
explicitly everywhere below:

```bash
ls /usr/bin/php*        # then use e.g. /usr/bin/php8.3 instead of php
```

## 3. Create a deploy key and give it to GitHub

The key is generated **on the Hostinger server**. The private half never leaves
it; GitHub only ever sees the public half.

```bash
ssh-keygen -t ed25519 -C "hostinger-deploy" -f ~/.ssh/github_deploy -N ""
cat ~/.ssh/github_deploy.pub
```

Copy the whole line that prints, then:

1. Go to **https://github.com/Raychung11/email-marketing/settings/keys**
2. **Add deploy key**
3. Title: `Hostinger`
4. Key: paste the line
5. Leave **Allow write access** unticked — the server only needs to read. A
   read-only key cannot be used to push anything to your repository if the
   hosting account is ever compromised.
6. **Add key**

Tell SSH to use that key for GitHub:

```bash
cat >> ~/.ssh/config <<'EOF'
Host github.com
    HostName github.com
    User git
    IdentityFile ~/.ssh/github_deploy
    IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config ~/.ssh/github_deploy
```

Check it:

```bash
ssh -T git@github.com
```

You want: `Hi Raychung11/email-marketing! You've successfully authenticated,
but GitHub does not provide shell access.` That message is a success, despite
how it reads.

## 4. Clone the code — outside the web root

```bash
cd ~/domains/steelblue-mule-213168.hostingersite.com
git clone git@github.com:Raychung11/email-marketing.git app
cd app
git checkout claude/brave-johnson-bf6c90
```

You now have:

```
~/domains/steelblue-mule-213168.hostingersite.com/
├── app/             ← the code, not reachable from the internet
│   └── public/      ← the only directory that should be
└── public_html/     ← currently the default Hostinger placeholder
```

## 5. Point the web root at `public/`

Replace `public_html` with a link to the application's `public` directory:

```bash
cd ~/domains/steelblue-mule-213168.hostingersite.com
mv public_html public_html.old
ln -s app/public public_html
ls -la public_html            # should show -> app/public
```

This is the arrangement to prefer. Everything except `public/` is then
*physically absent* from the web root, rather than present but hidden behind
rules that all have to keep working.

Once the site is up and you are happy, `rm -rf public_html.old`.

<details>
<summary>If the symlink does not work</summary>

Some configurations refuse to follow symlinked document roots. In that case put
the repository inside `public_html` instead and use the supplied rules:

```bash
cd ~/domains/steelblue-mule-213168.hostingersite.com
rm public_html && mv public_html.old public_html
cd public_html
git clone git@github.com:Raychung11/email-marketing.git .
git checkout claude/brave-johnson-bf6c90
cp deploy/hostinger/public_html.htaccess .htaccess
```

This works, but every protection now depends on that `.htaccess` being read.
Step 9 matters even more if you go this way.
</details>

## 6. Configure the application

```bash
cd ~/domains/steelblue-mule-213168.hostingersite.com/app
cp deploy/hostinger/env.production.example .env
chmod 600 .env
nano .env
```

Two values to fill in, both from **hPanel → Databases → Management**:

- `DB_USERNAME` — the `u822252863_…` database user, **not** your hPanel login
- `DB_PASSWORD` — that user's password

If no database user is attached to `u822252863_smartemail` yet, create one on
that page and grant it access to the database.

Then generate the application key:

```bash
php cron/console.php key:generate
```

> `APP_KEY` encrypts stored credentials — SES keys, Twilio tokens, OpenAI keys.
> Back it up somewhere safe. If you replace it later, everything encrypted with
> the old key is unrecoverable, and the application cannot tell you which
> settings have gone bad.

## 7. Build the database

```bash
php cron/console.php db:check      # reports the connection and which tables exist; changes nothing
php cron/console.php migrate       # ~65 tables
php cron/console.php db:seed       # roles, permissions, plans, compliance rules
php cron/console.php migrate:status
```

`db:seed` is not optional. It loads the permission set the roles refer to and
the per-country compliance rules — without it, nobody can be given access to
anything.

## 8. Create your account

```bash
php cron/console.php org:create "Your Business Name" you@yourdomain.com
```

It prints a temporary password. Sign in at
`https://steelblue-mule-213168.hostingersite.com/login` with that email and
password, then change the password immediately — it was just printed to your
terminal and is sitting in your shell history.

That first account is the owner of the first organisation. Everyone else you add
from **Settings → Team** inside the application.

## 9. Check what the internet can see

```bash
php deploy/hostinger/preflight.php
```

This runs about thirty checks — PHP version and extensions, database
connectivity, whether the server actually supports your collation, whether the
schema is migrated, file permissions, and whether cron has run.

The four that matter most ask your **live site** for `/.env`,
`/composer.json`, `/storage/logs/app.log` and `/config/database.php`. Every one
must come back as something other than `200`.

```
[  ok  ] Not web-reachable: /.env  —  HTTP 403
```

If any of them says `FAIL`, stop. Do not add contacts, do not connect SES. Go
back to step 5 and fix the document root first.

## 10. Cron

Without cron the site loads, looks completely normal, and never sends anything.

**hPanel → Advanced → Cron Jobs.** Choose **Custom**, not PHP — PHP mode locks
the command to `/usr/bin/php`, which is 8.2 on Hostinger and too old for this.

Add the two jobs from [`deploy/hostinger/cron.txt`](../deploy/hostinger/cron.txt),
substituting your real path (run `pwd` in the `app` directory to get it). Set
every dropdown to its `Every (*)` option.

There are exactly two — a scheduler and a worker — and adding a second copy of
either is how a campaign gets sent twice.

Each is a one-line call to a script, because Hostinger caps the command field at
255 characters and the worker command is well past it. The scripts find a PHP
8.3+ binary by checking its version rather than trusting a hardcoded path, so a
host that relocates PHP does not silently stop your sending.

After a couple of minutes:

```bash
php deploy/hostinger/preflight.php | grep -i cron
```

Both `Cron has run the scheduler` and `Cron has run the queue worker` should
pass.

`storage/logs/scheduler.log` and `worker.log` stay **empty** on a healthy
server — both processes log through the application logger, and a production
`LOG_LEVEL` discards routine messages. Empty is the expected state; errors still
land there. Liveness comes from the heartbeat each process writes on every run,
which is what the preflight reads.

## 11. Backups

**hPanel → Advanced → Cron Jobs**, Custom, once a day:

```
/home/u822252863/domains/steelblue-mule-213168.hostingersite.com/app/deploy/hostinger/backup.sh
```

Minute `20`, Hour `18`, everything else `Every (*)` — 02:20 Malaysia time, and
off the hour where every other cron job on a shared server is queueing.

It dumps the database, gzips it, verifies the result, and only then deletes
anything older than a fortnight. Dumps land in `~/backups/aigrowthhub/` at mode
600, **outside the web root** — a database dump under `public_html` is your whole
customer list one URL away.

Two details that matter on shared hosting:

- The password is passed in a `0600` defaults file, never on the command line.
  Process arguments are readable by every other account on the machine.
- `mysqldump | gzip` reports *gzip's* exit status, so a failed dump looks like a
  success. The script passes mysqldump's own status back out through a file
  descriptor. Without that, a broken backup silently overwrites a good one.

Check it:

```bash
php deploy/hostinger/preflight.php | grep -i backed
ls -lh ~/backups/aigrowthhub/
```

### Restoring

```bash
# Look before you leap — this replaces everything.
gzip -dc ~/backups/aigrowthhub/<file>.sql.gz | head -40

php cron/console.php down
php cron/console.php db:credentials /tmp/restore.cnf
gzip -dc ~/backups/aigrowthhub/<file>.sql.gz \
  | mysql --defaults-extra-file=/tmp/restore.cnf u822252863_smartemail
rm -f /tmp/restore.cnf
php cron/console.php up
```

Practise it once on a spare database. A backup nobody has restored is a guess,
and the moment you find out is the moment you needed it.

---

## Deploying changes after the first time

```bash
ssh -p 65002 u822252863@<host> \
  'cd ~/domains/steelblue-mule-213168.hostingersite.com/app && ./deploy/hostinger/deploy.sh'
```

`deploy.sh` puts the site in maintenance mode, pulls, migrates, fixes
permissions, clears caches, brings it back up and runs the preflight. It stops
rather than continuing if the pull is not a clean fast-forward or if someone has
edited files directly on the server.

---

## Before you send to anyone real

The install starts with `MAIL_PROVIDER=log`. Emails are written to
`storage/logs/app.log` and nothing leaves the server. That is deliberate: you
can click through the whole product, build a campaign and press send without
touching your sending reputation.

To actually send:

1. **Use your own domain, not the `.hostingersite.com` one.** Nobody can
   authenticate a shared preview domain, and mail from it will go to spam.
   Point a real domain at the site and update `APP_URL`.
2. Add the domain in the application under **Settings → Sending domains** and
   publish the DKIM, SPF and DMARC records it gives you.
3. Request production access in Amazon SES. New accounts are in a sandbox and
   can only send to addresses they have verified.
4. Set `MAIL_PROVIDER=ses` and the four `AWS_*` values in `.env`.
5. Point the SES configuration set at an SNS topic that POSTs to
   `https://your-site/webhooks/aws/ses`. Bounces and complaints are how suppression
   stays accurate; without it you will keep emailing addresses that are hard
   bouncing, which is the fastest route to being blocked.
6. Send to a list of five colleagues first.

`AI_PROVIDER=null` until you add an OpenAI key. The AI screens degrade to
ordinary forms rather than erroring, so there is no rush.

---

## When something is wrong

| What you see | What it is |
|---|---|
| `Unknown collation: 'utf8mb4_0900_ai_ci'` | `DB_COLLATION` is a MySQL 8 name. Set `utf8mb4_unicode_ci`, drop the half-built database, migrate again. |
| Blank white page | `APP_DEBUG=false` is hiding the error, which is correct. The reason is in `storage/logs/app.log`. |
| `500` on every page | Usually `storage/` not writable (`chmod -R 755 storage`) or `APP_KEY` empty. |
| Site shows a file listing, or `index.php` as text | Document root is wrong, or PHP is not running for this domain. Step 5. |
| Login says the page expired | `SESSION_SECURE=true` while the site is on plain http. Get the certificate working, or set it to false until then. |
| Campaign stuck at "sending" | The worker cron is not running. `php deploy/hostinger/preflight.php \| grep -i worker`. |
| `scheduler.log` is empty | Expected. Routine messages are below the production log level; liveness is the heartbeat, not the log. |
| Nothing ever leaves "scheduled" | The scheduler cron is not running. |
| Emails send but nothing is tracked | `APP_URL` is wrong. Open pixels and click links are built from it. |
| Old code after a deploy | The web server's OPcache. Wait a minute, or restart PHP in hPanel. |

Two commands worth knowing:

```bash
php cron/console.php down      # maintenance page for visitors
php cron/console.php up
```

---

## What shared hosting costs you

Worth knowing before you build a business on it:

- **No Redis**, so the queue runs in MySQL. Fine into the low tens of thousands
  of emails; it is the first thing to move when you outgrow this.
- **Cron granularity.** The worker gets a fresh 55-second shift each minute, so
  a large campaign is sent across several minutes rather than continuously.
- **Process limits.** Shared accounts cap concurrent processes. This is exactly
  why the worker takes a lock and exits when it finds one held, instead of
  letting cron pile up a new worker every minute.
- **No control over the mail server**, which does not matter here — sending goes
  out through SES over HTTPS, not through Hostinger.

None of this needs a code change to escape. Move to a VPS, set
`QUEUE_DRIVER=redis`, run the worker under Supervisor, and follow
[DEPLOYMENT.md](DEPLOYMENT.md).
