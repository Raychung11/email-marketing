#!/bin/sh
#
# Cron entry — every minute:
#
#   /home/…/app/deploy/hostinger/cron-scheduler.sh
#
# Starts due campaigns, advances journeys, retries failures, refreshes reports.
# Every job inside takes a named expiring lock, so an overlapping minute cannot
# double-execute anything.

. "$(dirname "$0")/cron-lib.sh"

LOG="$LOG_DIR/scheduler.log"
rotate_if_large "$LOG"

PHP="$(find_php)" || {
    echo "$(date -u '+%Y-%m-%dT%H:%M:%SZ') no PHP 8.3+ found on this server" >> "$LOG"
    exit 1
}

exec "$PHP" "$APP_ROOT/cron/scheduler.php" >> "$LOG" 2>&1
