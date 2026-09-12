# Deployment

Target: a single Linux VPS to start, horizontally splittable later (web nodes,
worker nodes, managed MySQL, managed Redis) with no code change.

## 1. Server requirements

- Ubuntu 22.04/24.04 or Debian 12
- Nginx (or Apache) + PHP-FPM 8.3+
- PHP extensions: `pdo_mysql`, `mbstring`, `openssl`, `json`, `curl`, `redis`,
  `intl`, `zip`
- MySQL 8.0+
- Redis 6+
- Supervisor
- Cron
- Certbot / TLS certificate

```bash
sudo apt update
sudo apt install -y nginx php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-curl \
  php8.3-intl php8.3-zip php8.3-redis mysql-server redis-server supervisor \
  git unzip certbot python3-certbot-nginx
```

## 2. Application

```bash
sudo mkdir -p /var/www/aigrowthhub && sudo chown -R deploy:www-data /var/www/aigrowthhub
cd /var/www/aigrowthhub
git clone <repo> .
composer install --no-dev --optimize-autoloader
cp .env.example .env
php cron/console.php key:generate      # writes APP_KEY
```

Edit `.env`. For production set at minimum:

```
APP_ENV=production
APP_DEBUG=false
SESSION_SECURE=true
FORCE_HTTPS=true
```

Permissions — only `storage/` is writable, and nothing under `public/` is:

```bash
sudo chown -R deploy:www-data /var/www/aigrowthhub
sudo find /var/www/aigrowthhub -type d -exec chmod 755 {} \;
sudo find /var/www/aigrowthhub -type f -exec chmod 644 {} \;
sudo chmod -R 775 /var/www/aigrowthhub/storage
```

## 3. Database

```sql
CREATE DATABASE ai_growth_hub CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'ai_growth_hub'@'localhost' IDENTIFIED BY '<strong-password>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES
  ON ai_growth_hub.* TO 'ai_growth_hub'@'localhost';
FLUSH PRIVILEGES;
```

```bash
php cron/console.php migrate          # apply schema
php cron/console.php db:seed          # roles, permissions, plans, compliance rules
php cron/console.php migrate:status   # verify
```

## 4. Nginx

```nginx
server {
    listen 80;
    server_name app.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name app.example.com;

    ssl_certificate     /etc/letsencrypt/live/app.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/app.example.com/privkey.pem;

    root /var/www/aigrowthhub/public;
    index index.php;

    client_max_body_size 32M;           # CSV imports

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 120;
    }

    # Never serve application internals
    location ~ ^/(app|config|database|resources|routes|storage|tests|vendor|workers|cron)/ {
        deny all;
    }
    location ~ /\.(env|git) { deny all; }

    location ~* \.(css|js|png|jpg|jpeg|svg|woff2?)$ {
        expires 30d;
        access_log off;
    }
}
```

> Apache equivalent: point `DocumentRoot` at `public/`, enable `mod_rewrite`,
> and use the shipped `public/.htaccess`.

## 5. Workers (Supervisor)

`/etc/supervisor/conf.d/aigrowthhub-worker.conf`:

```ini
[program:aigh-marketing]
command=php /var/www/aigrowthhub/workers/worker.php --queue=email_marketing --sleep=1 --max-jobs=500
directory=/var/www/aigrowthhub
user=www-data
autostart=true
autorestart=true
numprocs=4
process_name=%(program_name)s_%(process_num)02d
stopwaitsecs=60
stdout_logfile=/var/www/aigrowthhub/storage/logs/worker-marketing.log
stderr_logfile=/var/www/aigrowthhub/storage/logs/worker-marketing.err.log

[program:aigh-transactional]
command=php /var/www/aigrowthhub/workers/worker.php --queue=email_high_priority,email_transactional --sleep=1
directory=/var/www/aigrowthhub
user=www-data
autostart=true
autorestart=true
numprocs=2
process_name=%(program_name)s_%(process_num)02d
stdout_logfile=/var/www/aigrowthhub/storage/logs/worker-transactional.log

[program:aigh-retry]
command=php /var/www/aigrowthhub/workers/worker.php --queue=email_retry --sleep=5
directory=/var/www/aigrowthhub
user=www-data
autostart=true
autorestart=true
numprocs=1
stdout_logfile=/var/www/aigrowthhub/storage/logs/worker-retry.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status
```

Workers exit voluntarily after `--max-jobs` so PHP memory is reclaimed;
Supervisor restarts them. Concurrency is `numprocs` and must be tuned against
the **current** SES rate limit for the account and Region, not a fixed number.

## 6. Cron

```cron
* * * * * cd /var/www/aigrowthhub && php cron/scheduler.php >> storage/logs/scheduler.log 2>&1
```

One entry only. `scheduler.php` acquires a named lock per job, so overlapping
minutes cannot double-run a job. It dispatches: scheduled campaign activation,
automation timers, webhook retries, segment count refresh, deliverability
monitors, usage rollups, and import cleanup.

## 7. Amazon SES

1. Verify the sending domain (SES → Verified identities → Domain).
2. Publish the DKIM CNAMEs SES gives you (3 records), plus SPF and a DMARC
   policy. The in-app wizard shows exactly these records per organisation.
3. Create a **configuration set** and set `AWS_SES_CONFIGURATION_SET`.
4. Create an SNS topic for event publishing and subscribe it (HTTPS) to
   `https://app.example.com/webhooks/aws/ses`. Enable: `SEND`, `DELIVERY`,
   `OPEN`, `CLICK`, `BOUNCE`, `COMPLAINT`, `REJECT`, `RENDERING_FAILURE`.
   Then **set `AWS_SES_SNS_TOPIC_ARN` to that topic's ARN**. Leaving it empty
   makes the endpoint accept any topic whose signature checks out, which is
   convenient while wiring things up and wrong in production: a valid Amazon
   signature only proves Amazon sent the message, and anyone with an AWS account
   can publish to their own topic and aim it at your URL. SNS confirms the
   subscription by POSTing a `SubscriptionConfirmation`; the endpoint completes
   the handshake itself, so no manual step is needed.
5. Request production access — **sandbox accounts are rate- and
   recipient-restricted**, and quotas differ per account and Region. The app
   reads the live quota via `getQuota()` and throttles to it; it never assumes a
   fixed number.
6. IAM: a dedicated user/role with only `ses:SendEmail`, `ses:SendRawEmail`,
   `ses:GetSendQuota`, `ses:GetAccount`, `ses:GetIdentityVerificationAttributes`.
   Prefer an instance role over static keys.

The SNS endpoint verifies every message's signature against the certificate
Amazon names, having first checked that certificate is served from an Amazon SNS
host, and rejects anything older than an hour so a captured message cannot be
replayed. Unsigned or unverifiable payloads are refused with a 403; anything else
it decides to ignore answers 200, because SNS retries non-2xx responses for hours
and a payload rejected on its merits will not improve on the twentieth attempt.

Outbound HTTPS from the application servers must be able to reach
`sns.<region>.amazonaws.com` — the certificate fetch and the subscription
handshake both go there. If that is blocked, every event is rejected and the
do-not-email list silently stops filling.

## 8. OpenAI

Set `OPENAI_API_KEY` in the environment only. It is never stored in the
database and never reaches the browser. All AI calls go server-side through
`App\AI\OpenAiProvider`, are recorded in `ai_requests`, and are capped by
`AI_MONTHLY_TOKEN_CAP` per organisation.

## 9. Backups

| What | How | Retention |
|------|-----|-----------|
| MySQL | nightly `mysqldump --single-transaction --routines` to object storage, encrypted | 30 daily, 12 monthly |
| Binlogs | enable and ship for point-in-time recovery | 7 days |
| `storage/uploads` | nightly sync | 30 days |
| `.env` | stored in the secret manager, **never** in git or the DB backup | — |

Restores must be rehearsed quarterly. A backup that has never been restored is
not a backup.

## 10. Zero-downtime-ish deploy

```bash
cd /var/www/aigrowthhub
php cron/console.php down                  # maintenance flag (queue keeps draining)
git pull --ff-only
composer install --no-dev --optimize-autoloader
php cron/console.php migrate
sudo supervisorctl restart all             # workers pick up new code
sudo systemctl reload php8.3-fpm
php cron/console.php up
```

Migrations must be backward compatible for one release (add columns, backfill,
then remove in the next release) so a rollback does not strand the schema.

## 11. Monitoring

- `/health` — liveness (no DB), `/health/ready` — DB + Redis reachable.
- Alert on: worker queue depth, oldest job age, bounce rate > 5 %,
  complaint rate > 0.1 %, SES quota utilisation > 80 %, failed scheduler runs,
   5xx rate, and `audit_logs` writes failing.
- Application errors go to `storage/logs/app.log` with a correlation ID that is
  also shown on the user-facing error page. Stack traces are never rendered to
  end users when `APP_DEBUG=false`.

## 12. Production security checklist

- [ ] `APP_DEBUG=false`, `APP_ENV=production`
- [ ] `APP_KEY` generated, unique per environment
- [ ] TLS enforced, HSTS on, `SESSION_SECURE=true`
- [ ] `.env` not readable by the web server user beyond what PHP needs
- [ ] Database user has no `SUPER`/`FILE`/`PROCESS` privileges
- [ ] Redis bound to localhost or authenticated, not publicly reachable
- [ ] SES IAM scoped to send + read-quota only
- [ ] Backups running **and a restore rehearsed**
- [ ] Super admin accounts on MFA (roadmap: enforce)
- [ ] New organisations start on conservative sending limits
- [ ] Suppression export verified before first production send
- [ ] `audit_logs` write path tested
