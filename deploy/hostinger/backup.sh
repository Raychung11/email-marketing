#!/bin/sh
#
# Nightly database backup.
#
# Cron entry — once a day:
#
#   /home/…/app/deploy/hostinger/backup.sh
#
# Writes a gzipped dump OUTSIDE the web root, keeps a fortnight, and deletes
# nothing until the new dump has been verified. Restoring is documented in
# docs/DEPLOY_HOSTINGER.md.

. "$(dirname "$0")/cron-lib.sh"

LOG="$LOG_DIR/backup.log"
rotate_if_large "$LOG"

log() {
    echo "$(date -u '+%Y-%m-%dT%H:%M:%SZ') $1" >> "$LOG"
}

# Outside the web root and outside the repository. A database dump under
# public_html is your entire customer list one URL away — worse than no backup,
# because you would not know it had been taken.
BACKUP_DIR="${BACKUP_DIR:-$HOME/backups/aigrowthhub}"
KEEP_DAYS="${KEEP_DAYS:-14}"

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

PHP="$(find_php)" || { log "FAILED: no PHP 8.3+ on this server"; exit 1; }

command -v mysqldump >/dev/null 2>&1 || { log "FAILED: mysqldump is not installed"; exit 1; }

# The credentials go into a 0600 file rather than onto the command line, because
# process arguments are readable by every other account on a shared machine.
CNF="$(mktemp "${TMPDIR:-/tmp}/aigh-backup-XXXXXX.cnf")" || { log "FAILED: could not create a temporary file"; exit 1; }
chmod 600 "$CNF"
trap 'rm -f "$CNF"' EXIT INT TERM

DATABASE="$("$PHP" "$APP_ROOT/cron/console.php" db:credentials "$CNF" 2>>"$LOG")"

if [ -z "$DATABASE" ]; then
    log "FAILED: could not read the database configuration"
    exit 1
fi

STAMP="$(date -u '+%Y%m%d-%H%M%S')"
TARGET="$BACKUP_DIR/${DATABASE}-${STAMP}.sql.gz"

dump() {
    # --single-transaction: consistent without locking anyone out mid-send.
    # --no-tablespaces: shared hosting accounts lack the PROCESS privilege that
    #   newer mysqldump wants for tablespace metadata. Older builds do not know
    #   the option at all, hence the retry below.
    #
    # The exit status of a pipeline is the status of its LAST command, so
    # `mysqldump | gzip` reports gzip's success even when mysqldump died. That is
    # precisely how a backup quietly becomes an empty file nobody notices until
    # they need it. POSIX sh has no pipefail, so mysqldump's own status is passed
    # back out through file descriptor 3.
    status=$( { { mysqldump --defaults-extra-file="$CNF" \
            --single-transaction --quick --skip-lock-tables \
            --routines --events --triggers \
            "$@" "$DATABASE" 2>>"$LOG"; echo $? >&3; } | gzip -9 > "$TARGET"; } 3>&1 )

    return "${status:-1}"
}

if ! dump --no-tablespaces; then
    log "retrying without --no-tablespaces"
    dump || { log "FAILED: mysqldump returned an error"; rm -f "$TARGET"; exit 1; }
fi

chmod 600 "$TARGET"

# Verify before pruning anything. A backup nobody checked is a guess, and the
# moment you find out is the moment you needed it.
if ! gzip -t "$TARGET" 2>>"$LOG"; then
    log "FAILED: the dump is not a valid gzip file, keeping older backups"
    rm -f "$TARGET"
    exit 1
fi

SIZE=$(wc -c < "$TARGET")

# Deliberately not a byte threshold on the compressed file: a small database
# gzips small, and an arbitrary floor rejects perfectly good backups. What
# matters is whether the contents are a real dump.
if [ "$SIZE" -lt 200 ]; then
    log "FAILED: the dump is only ${SIZE} bytes, keeping older backups"
    rm -f "$TARGET"
    exit 1
fi

if ! gzip -dc "$TARGET" 2>/dev/null | grep -q "CREATE TABLE"; then
    log "FAILED: the dump contains no tables, keeping older backups"
    rm -f "$TARGET"
    exit 1
fi

# mysqldump signs off with a completion line, so its absence means the dump was
# cut short — the failure mode that looks fine until you try to restore it.
# Warned rather than fatal, because the exact wording varies between MySQL and
# MariaDB and a working backup should not be thrown away over a phrasing change.
if ! gzip -dc "$TARGET" 2>/dev/null | tail -5 | grep -qi "dump completed"; then
    log "WARNING: no completion marker; the dump may be truncated"
fi

# Only now is it safe to remove old ones.
find "$BACKUP_DIR" -name "${DATABASE}-*.sql.gz" -type f -mtime "+${KEEP_DAYS}" -delete 2>>"$LOG"

COUNT=$(find "$BACKUP_DIR" -name "${DATABASE}-*.sql.gz" -type f | wc -l)

log "OK ${TARGET} (${SIZE} bytes, ${COUNT} kept)"

"$PHP" -r '
    require "'"$APP_ROOT"'/bootstrap/autoload.php";
    (new App\Support\Heartbeat("'"$APP_ROOT"'/storage/framework"))
        ->record("backup", ["bytes" => '"$SIZE"', "kept" => '"$COUNT"']);
' 2>>"$LOG"

exit 0
