#!/usr/bin/env bash
# Deploy a standalone installation over rsync/SSH — see docs/DEPLOY.md.
#
# Copy deploy.env.example to deploy.env and fill it in. deploy.env is gitignored
# and never committed.
#
#   ./deploy.sh                    deploy this directory, as it stands
#   ./deploy.sh -n                 dry run: exactly what would change, nothing sent
#   ./deploy.sh --version v0.7.0   deploy that tag (or any commit-ish)
#   ./deploy.sh --latest           deploy the newest tag, fetching first
#   ./deploy.sh --version v0.7.0 --show   print what that would send, and stop
#
# WHY A VERSION IS NOT "CHECK IT OUT FIRST"
#
# Because `git checkout v0.7.0 && ./deploy.sh` leaves a detached HEAD behind,
# and the next deploy from that state silently ships the old tag again — with
# no symptom until somebody asks why a fix is not live. It also cannot be done
# with edits in progress without stashing them, and a tag deployed from a dirty
# tree is not the tag it claims to be.
#
# So a version is deployed from a temporary git worktree instead: this
# directory is never touched, `dirty` is honestly false, and the worktree is
# removed afterwards however the script exits.
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

# ---------------------------------------------------------------------------
# Which version, and where its files come from.
#
# Everything not understood here is passed through to rsync, which is how -n
# and --dry-run have always worked.
# ---------------------------------------------------------------------------
REF=""
SHOW=false
ARGS=()

while [[ $# -gt 0 ]]; do
  case "$1" in
    --version)
      [[ $# -ge 2 ]] || { echo "deploy: --version needs a tag or commit" >&2; exit 2; }
      REF="$2"
      shift 2
      ;;
    --latest)
      REF="latest"
      shift
      ;;
    --show)
      SHOW=true
      shift
      ;;
    -h|--help)
      sed -n '2,25p' "$0" | sed 's/^# \{0,1\}//'
      exit 0
      ;;
    *)
      ARGS+=("$1")
      shift
      ;;
  esac
done
set -- "${ARGS[@]+"${ARGS[@]}"}"

# The directory whose contents get sent. This one, unless a version was asked
# for, in which case a worktree replaces it below.
SRC="$SCRIPT_DIR"
WORKTREE=""

cleanup() {
  [[ -n "${SCRATCH_VERSION:-}" ]] && rm -f "$SCRATCH_VERSION"
  if [[ -n "$WORKTREE" ]]; then
    git -C "$SCRIPT_DIR" worktree remove --force "$WORKTREE" >/dev/null 2>&1 || rm -rf "$WORKTREE"
  fi
}
trap cleanup EXIT

if [[ -n "$REF" ]]; then
  git -C "$SCRIPT_DIR" rev-parse --git-dir >/dev/null 2>&1 || {
    echo "deploy: --version needs a git checkout; this is not one" >&2
    exit 1
  }

  if [[ "$REF" == "latest" ]]; then
    # Tags somebody else pushed are not here yet. A fetch that fails (no
    # network, no remote) is not fatal — it just means "newest I know of",
    # which is said out loud rather than assumed.
    git -C "$SCRIPT_DIR" fetch --tags --quiet 2>/dev/null \
      || echo "deploy: could not fetch tags; using the ones already here" >&2
    REF="$(git -C "$SCRIPT_DIR" tag --sort=-v:refname | head -n 1)"
    [[ -n "$REF" ]] || { echo "deploy: --latest, but this checkout has no tags" >&2; exit 1; }
    echo "==> latest tag is $REF"
  fi

  git -C "$SCRIPT_DIR" rev-parse --verify --quiet "${REF}^{commit}" >/dev/null || {
    echo "deploy: no such version: $REF" >&2
    exit 1
  }

  # Outside this directory on purpose: a worktree nested inside it would be
  # copied to the server by the very rsync it exists to feed.
  WORKTREE="$(mktemp -d "${TMPDIR:-/tmp}/uo-deploy.XXXXXX")"
  git -C "$SCRIPT_DIR" worktree add --detach --quiet "$WORKTREE" "$REF"

  # `mktemp -d` makes a 0700 directory, and `rsync --archive` faithfully copies
  # the SOURCE directory's mode onto the destination — so deploying from one
  # set the live document root to 0700 and the web server, which is not this
  # user, stopped being able to traverse it. Every URL on the site answered 404
  # while every file sat there correctly. Deploying from the working copy never
  # showed this because a checkout is 0755.
  chmod 755 "$WORKTREE"

  SRC="$WORKTREE"
fi

if [[ ! -f "$ENV_FILE" ]] && [[ "$SHOW" == true ]]; then
  ENV_FILE=""   # --show contacts nothing, so it needs no destination
elif [[ ! -f "$ENV_FILE" ]]; then
  echo "Error: $ENV_FILE not found. Copy deploy.env.example and fill it in." >&2
  exit 1
fi

if [[ -n "$ENV_FILE" ]]; then
  # shellcheck source=deploy.env
  source "$ENV_FILE"
  : "${REMOTE:?deploy.env must define REMOTE}"
else
  REMOTE="(nowhere — --show)"
fi

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
# What is about to be deployed, written down where it can be read back.
#
# "Is my fix live?" was being answered by fetching a page and grepping it for a
# string that ought to be there, which works until the answer is no for a
# reason nobody guessed — a stale worker cache, a deploy that half ran, the
# wrong host in deploy.env. One file with the commit in it answers it directly:
#
#   curl -s https://<site>/version.json
#
# Generated rather than committed, because it describes an act rather than the
# code: it is gitignored, and a checkout has no version.json until it is
# deployed from. `dirty` is the flag that matters most — a deploy from a tree
# with uncommitted changes is not the commit it names, and saying so here is
# the only place that would ever be noticed.
#
# `release` is `git describe`: a deploy from a tagged commit reads v0.7.0 and
# one from three commits later reads v0.7.0-3-g9eca010. The suffix is the point
# — most deployments are not releases, and this says so rather than rounding
# down to the last tag. See docs/RELEASES.md.
# ---------------------------------------------------------------------------
VERSION_FILE="$SRC/version.json"

# --show without a version would otherwise rewrite this checkout's own
# version.json, which is the record of the last real deploy. Looking is not
# deploying, so it writes somewhere it can throw away.
if [[ "$SHOW" == true ]] && [[ -z "$WORKTREE" ]]; then
    VERSION_FILE="$(mktemp "${TMPDIR:-/tmp}/uo-version.XXXXXX")"
    SCRATCH_VERSION="$VERSION_FILE"
fi

if command -v git >/dev/null 2>&1 && git -C "$SRC" rev-parse --git-dir >/dev/null 2>&1; then
    COMMIT="$(git -C "$SRC" rev-parse HEAD)"
    SHORT="$(git -C "$SRC" rev-parse --short HEAD)"
    COMMITTED="$(git -C "$SRC" log -1 --format=%cI)"
    SUBJECT="$(git -C "$SRC" log -1 --format=%s)"
    BRANCH="$(git -C "$SRC" rev-parse --abbrev-ref HEAD)"
    # --always so a checkout with no tags at all still says something.
    RELEASE="$(git -C "$SRC" describe --tags --always 2>/dev/null || echo '')"
    # A worktree is detached, so `branch` would read HEAD and say nothing. The
    # version asked for is the true answer to "what was deployed from".
    if [ "$BRANCH" = "HEAD" ] && [ -n "$REF" ]; then BRANCH="$REF"; fi
    if [ -n "$(git -C "$SRC" status --porcelain)" ]; then DIRTY=true; else DIRTY=false; fi
else
    COMMIT=""; SHORT="unknown"; COMMITTED=""; SUBJECT=""; BRANCH=""; RELEASE=""; DIRTY=false
fi

python3 - "$VERSION_FILE" "$COMMIT" "$SHORT" "$COMMITTED" "$SUBJECT" "$BRANCH" "$DIRTY" "$RELEASE" <<'PYEOF' ||     printf '{"commit":"%s","short":"%s","dirty":%s}\n' "$COMMIT" "$SHORT" "$DIRTY" > "$VERSION_FILE"
import json, sys, datetime
path, commit, short, committed, subject, branch, dirty, release = sys.argv[1:9]
json.dump({
    'commit': commit,
    'short': short,
    'release': release,
    'committed': committed,
    'subject': subject,
    'branch': branch,
    'dirty': dirty == 'true',
    'deployed': datetime.datetime.now(datetime.timezone.utc).isoformat(timespec='seconds'),
}, open(path, 'w'), indent=2)
open(path, 'a').write('\n')
PYEOF

echo "==> deploying ${RELEASE:-$SHORT}$([ "$DIRTY" = true ] && echo ' (WITH UNCOMMITTED CHANGES)')"

if [[ "$SHOW" == true ]]; then
    # Everything decided, nothing sent. This is the answer to "what would
    # --latest actually deploy", which was otherwise only knowable by doing it.
    # The mode as well as the path: rsync copies the SOURCE directory's mode to
    # the destination, and a 0700 source is what took the site down once.
    #
    # GNU first, BSD second, and the order is not cosmetic. `stat -f` means
    # "filesystem status" to GNU, which SUCCEEDS on a directory and prints the
    # format string back — so BSD-first never fell through on Linux and the
    # mode was reported as literal `%OLp`. It passed on macOS and failed in CI.
    SRC_MODE="$(stat -c '%a' "$SRC" 2>/dev/null || stat -f '%OLp' "$SRC" 2>/dev/null || echo '?')"
    echo "==> source:  $SRC (mode $SRC_MODE)"
    cat "$VERSION_FILE"
    exit 0
fi

# ---------------------------------------------------------------------------
# The rules, first and by themselves.
# ---------------------------------------------------------------------------
"$RSYNC" --verbose "$@" \
  "$SRC/install/standalone.htaccess" "${REMOTE}.htaccess"

# ---------------------------------------------------------------------------
# The installation.
#
# The excludes are the whole content of this script, so each is here on purpose:
#
#   conf/, logos/,
#   events/           server-side state — see the note at the top. events/ holds
#                     what install/make-event.php wrote ON THE SERVER, which is
#                     somebody's tournament and exists nowhere else
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
#   tools/            run on a laptop against a log downloaded from the host,
#                     never on the server. visitors.php refuses to run under a
#                     web server anyway; this is the second lock
#   fixtures/*.log    the sample access log is for tests/visitors.mjs
#
# robots.txt IS deployed, and only works here: a crawler reads it from the
# domain root, which standalone is this directory. See docs/ANALYTICS.md.
# ---------------------------------------------------------------------------
"$RSYNC" \
  --archive \
  --verbose \
  --compress \
  --delete \
  --exclude='/conf/' \
  --exclude='/logos/' \
  --exclude='/events/' \
  --exclude='/cgi-bin/' \
  --exclude='/.well-known/' \
  --exclude='/error_log' \
  --exclude='/.htaccess' \
  # No trailing slash: a worktree's .git is a FILE, not a directory, and the
  # pattern with one matched only the directory — so a deploy from a worktree
  # published a .git naming a path on the deploying machine.
  --exclude='/.git' \
  --exclude='/.github/' \
  --exclude='/.gitignore' \
  --exclude='.DS_Store' \
  --include='/tests/selftest.php' \
  --exclude='/tests/**' \
  --exclude='/docs/' \
  --exclude='*.md' \
  --exclude='/fixtures/*.sql' \
  --exclude='/fixtures/*.sh' \
  --exclude='/fixtures/*.log' \
  --exclude='/tools/' \
  --exclude='/package.json' \
  --exclude='/package-lock.json' \
  --exclude='/node_modules/' \
  --exclude='/test-results/' \
  --exclude='/playwright-report/' \
  --exclude='/deploy.sh' \
  --exclude='/deploy.env' \
  --exclude='/deploy.env.example' \
  "$@" \
  "$SRC/" "$REMOTE"

cat <<'DONE'

Deployed. On a FIRST deployment, over SSH on the host:

    mkdir -p <install>/conf && chmod 775 <install>/conf
    php <install>/install/make-config.php --capture=fixtures/payloads/dev

Then open / and check the Studio lists games — see docs/DEPLOY.md.
DONE

echo "==> live version: curl -s https://<site>/version.json"
