#!/usr/bin/env bash
#
# Deploy the current branch on Hostinger shared hosting.
#
#   ssh -p 65002 u822252863@<host>
#   cd ~/domains/<your-domain>/app && ./deploy/hostinger/deploy.sh
#
# Or in one line from your laptop:
#
#   ssh -p 65002 u822252863@<host> 'cd ~/domains/<your-domain>/app && ./deploy/hostinger/deploy.sh'
#
# Safe to run repeatedly. It refuses to do anything it cannot do cleanly rather
# than leaving the site half-updated.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."
APP_ROOT="$(pwd)"

say() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }
die() { printf '\n\033[31mDeploy stopped: %s\033[0m\n\n' "$1" >&2; exit 1; }

[ -f .env ] || die ".env is missing. Copy deploy/hostinger/env.production.example to .env and fill it in."

PHP_BIN="${PHP_BIN:-php}"
"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' \
  || die "$("$PHP_BIN" -v | head -1) is too old. This needs PHP 8.3+. Try PHP_BIN=/usr/bin/php8.3 $0"

say "Deploying in $APP_ROOT"

# ---------------------------------------------------------------------------
# Maintenance mode goes on first. A visitor who loads a page halfway through a
# migration gets an error they cannot make sense of; this gives them a page that
# says what is happening.
# ---------------------------------------------------------------------------
say "Maintenance mode on"
"$PHP_BIN" cron/console.php down || true

restore() {
  "$PHP_BIN" cron/console.php up >/dev/null 2>&1 || true
}
trap restore EXIT

# ---------------------------------------------------------------------------
# Code
# ---------------------------------------------------------------------------
say "Fetching code"
BRANCH="$(git rev-parse --abbrev-ref HEAD)"
echo "Branch: $BRANCH"

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  git status --short
  die "there are uncommitted changes on the server. Commit or discard them before deploying — a pull would either fail or silently overwrite them."
fi

git pull --ff-only origin "$BRANCH" \
  || die "the pull was not a fast-forward. The server has commits the remote does not, or the branches have diverged."

echo "Now at: $(git log --oneline -1)"

# ---------------------------------------------------------------------------
# Database
# ---------------------------------------------------------------------------
say "Applying migrations"
"$PHP_BIN" cron/console.php migrate

# ---------------------------------------------------------------------------
# Permissions — storage is the only writable part of the tree, and nothing
# under public/ is writable at all.
# ---------------------------------------------------------------------------
say "Setting permissions"
mkdir -p storage/logs storage/uploads/imports storage/framework/cache storage/tmp
find storage -type d -exec chmod 755 {} \;
chmod 600 .env
chmod +x deploy/hostinger/deploy.sh

# ---------------------------------------------------------------------------
# Caches
# ---------------------------------------------------------------------------
say "Clearing caches"
find storage/framework/cache -type f ! -name '.gitkeep' -delete 2>/dev/null || true

# Shared hosting keeps a compiled copy of every PHP file. Without this, the
# site can serve the previous release for minutes after a successful deploy.
if "$PHP_BIN" -r 'exit(function_exists("opcache_reset") ? 0 : 1);' 2>/dev/null; then
  "$PHP_BIN" -r 'opcache_reset();' 2>/dev/null || true
  echo "OPcache reset (command line)."
  echo "Note: the web server has its own OPcache. If the site still serves old"
  echo "code, wait for revalidation or restart PHP in hPanel."
fi

say "Maintenance mode off"
restore
trap - EXIT

# ---------------------------------------------------------------------------
# Tell the operator what state they are actually in.
# ---------------------------------------------------------------------------
say "Preflight"
if "$PHP_BIN" deploy/hostinger/preflight.php; then
  printf '\n\033[32mDeployed, and every check passed.\033[0m\n\n'
else
  # The deploy itself worked — the code is live and the migrations ran. What
  # failed is a check on the surrounding setup, which is a different problem
  # and needs saying differently, or it looks like the deploy broke.
  printf '\n\033[33mDeployed, but the checks above found problems. The new code is live; fix the failures before using the site.\033[0m\n\n'
  exit 1
fi
