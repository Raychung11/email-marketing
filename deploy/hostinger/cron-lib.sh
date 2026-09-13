#!/bin/sh
#
# Shared by cron-scheduler.sh and cron-worker.sh.
#
# Hostinger's cron field caps at 255 characters, which the worker command alone
# is comfortably past. So cron calls a script instead, and the script holds the
# real command — which is the better arrangement regardless: the queue list and
# the flags live in version control where they can be reviewed, rather than in a
# text box in a hosting panel that nobody looks at again.

# The application root, whichever directory cron happened to start us in.
APP_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
LOG_DIR="$APP_ROOT/storage/logs"

mkdir -p "$LOG_DIR"

# Find a PHP that is new enough. Hardcoding /opt/alt/php83 works until the host
# moves it, and a cron job that silently stops running is the worst way to find
# that out — so try the likely locations and check the version rather than
# trusting the path.
find_php() {
    for candidate in \
        "$PHP_BIN" \
        /opt/alt/php84/usr/bin/php \
        /opt/alt/php83/usr/bin/php \
        /usr/local/bin/php8.3 \
        /usr/bin/php8.3 \
        /usr/bin/php \
        php
    do
        [ -n "$candidate" ] || continue
        command -v "$candidate" >/dev/null 2>&1 || [ -x "$candidate" ] || continue

        if "$candidate" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' >/dev/null 2>&1; then
            echo "$candidate"
            return 0
        fi
    done

    return 1
}

# Shared hosting has no logrotate, and these append every minute for ever.
# One rotation at 10MB keeps the recent history without filling the account.
rotate_if_large() {
    log="$1"

    [ -f "$log" ] || return 0

    size=$(wc -c < "$log" 2>/dev/null || echo 0)

    if [ "$size" -gt 10485760 ]; then
        mv -f "$log" "$log.1"
    fi
}
