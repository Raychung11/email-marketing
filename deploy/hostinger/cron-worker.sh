#!/bin/sh
#
# Cron entry — every minute:
#
#   /home/…/app/deploy/hostinger/cron-worker.sh
#
# Does the actual sending. --max-seconds ends the shift before the next minute
# begins; --lock makes an overlapping run exit immediately rather than start a
# second worker beside the first.
#
# On a five-minute cron schedule, set MAX_SECONDS=290 in the environment or edit
# the default below.

. "$(dirname "$0")/cron-lib.sh"

LOG="$LOG_DIR/worker.log"
rotate_if_large "$LOG"

# Strict priority: drained left to right, so a password reset never waits behind
# a 50,000-recipient campaign.
QUEUES="email_high_priority,email_transactional,email_marketing,email_retry,automation,webhooks,imports,analytics"

MAX_SECONDS="${MAX_SECONDS:-55}"
MAX_JOBS="${MAX_JOBS:-400}"

PHP="$(find_php)" || {
    echo "$(date -u '+%Y-%m-%dT%H:%M:%SZ') no PHP 8.3+ found on this server" >> "$LOG"
    exit 1
}

exec "$PHP" "$APP_ROOT/workers/worker.php" \
    --queue="$QUEUES" \
    --max-seconds="$MAX_SECONDS" \
    --max-jobs="$MAX_JOBS" \
    --lock=worker_default >> "$LOG" 2>&1
