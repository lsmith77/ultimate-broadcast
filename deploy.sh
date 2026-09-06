#!/usr/bin/env bash
# Deploy a standalone installation over rsync/SSH — see docs/DEPLOY.md.
#
# Copy deploy.env.example to deploy.env and fill it in. deploy.env is gitignored
# and never committed.
#
#   ./deploy.sh          deploy
#   ./deploy.sh -n       dry run: exactly what would change, nothing sent
#
# THE TWO THINGS THIS SCRIPT EXISTS TO GET RIGHT
#
# 1. The `.htaccess`. The one at the top of this directory is for HOSTED mode —
#    it rewrites onto UltiOrganizer's front controller and would 404 every URL
#    on a site of its own. So it is excluded, and install/standalone.htaccess is
#    sent in its place. It goes FIRST, so a first deployment is never briefly
#    serving conf/ with no rules in front of it.
#
# 2. `--delete` and the state that only exists on the server. conf/ holds the
#    administrator hash, what is on air, kit colours and the commentary desk's
#    prepared notes about named players; logos/ holds a team's own artwork.
#    Both are gitignored, so neither exists here — and without an exclude,
#    --delete would take the whole installation apart on every deploy. rsync
#    does not delete excluded paths, so excluding them is what protects them.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ENV_FILE="$SCRIPT_DIR/deploy.env"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Error: $ENV_FILE not found. Copy deploy.env.example and fill it in." >&2
  exit 1
fi

# shellcheck source=deploy.env
source "$ENV_FILE"

: "${REMOTE:?deploy.env must define REMOTE}"

# Recent macOS ships openrsync as /usr/bin/rsync, which does not implement every
# flag below. Prefer a real rsync when one is installed; deploy.env can pin it.
if [[ -z "${RSYNC:-}" ]]; then
  if [[ -x /opt/homebrew/bin/rsync ]]; then
    RSYNC=/opt/homebrew/bin/rsync
  else
    RSYNC=rsync
  fi
fi

# A trailing slash means "the contents of", which is what both of these need.
REMOTE="${REMOTE%/}/"

echo "==> $REMOTE"

# ---------------------------------------------------------------------------
# The rules, first and by themselves.
# ---------------------------------------------------------------------------
"$RSYNC" --verbose "$@" \
  "$SCRIPT_DIR/install/standalone.htaccess" "${REMOTE}.htaccess"

# ---------------------------------------------------------------------------
# The installation.
#
# The excludes are the whole content of this script, so each is here on purpose:
#
#   conf/, logos/     server-side state — see the note at the top
#   cgi-bin/,
#   .well-known/,
#   error_log         the HOST's, not ours. Shared hosting creates a cgi-bin in
#                     each document root and an ACME challenge directory during
#                     a certificate renewal, and writes the error log beside
#                     them. --delete would remove all three on every deploy, and
#                     the one that would hurt is a renewal in flight
#   .htaccess         hosted-mode rules; replaced above
#   tests/            the suite is development tooling. selftest.php is not: it
#                     is the switcher diagnostic, a routed page that has to be
#                     loadable by the device it is diagnosing
#   docs/, *.md       1.2 MB of documentation and screenshots that a web server
#                     will not render anyway
#   fixtures/*.sql,
#   fixtures/*.sh     seed a development database; there is no database here.
#                     fixtures/payloads/ IS deployed — it is the capture the
#                     installation serves
#   package*.json,
#   node_modules/     the project has no build step; npm is for the test runner
# ---------------------------------------------------------------------------
"$RSYNC" \
  --archive \
  --verbose \
  --compress \
  --delete \
  --exclude='/conf/' \
  --exclude='/logos/' \
  --exclude='/cgi-bin/' \
  --exclude='/.well-known/' \
  --exclude='/error_log' \
  --exclude='/.htaccess' \
  --exclude='/.git/' \
  --exclude='/.github/' \
  --exclude='/.gitignore' \
  --exclude='.DS_Store' \
  --include='/tests/selftest.php' \
  --exclude='/tests/**' \
  --exclude='/docs/' \
  --exclude='*.md' \
  --exclude='/fixtures/*.sql' \
  --exclude='/fixtures/*.sh' \
  --exclude='/package.json' \
  --exclude='/package-lock.json' \
  --exclude='/node_modules/' \
  --exclude='/test-results/' \
  --exclude='/playwright-report/' \
  --exclude='/deploy.sh' \
  --exclude='/deploy.env' \
  --exclude='/deploy.env.example' \
  "$@" \
  "$SCRIPT_DIR/" "$REMOTE"

cat <<'DONE'

Deployed. On a FIRST deployment, over SSH on the host:

    mkdir -p <install>/conf && chmod 775 <install>/conf
    php <install>/install/make-config.php --capture=fixtures/payloads/dev

Then open / and check the Studio lists games — see docs/DEPLOY.md.
DONE
