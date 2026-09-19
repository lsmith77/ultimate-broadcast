#!/usr/bin/env bash
#
# Pull the access log from the installation and report visitors and demo runs.
#
#   tools/stats.sh                       # everything the host still has
#   tools/stats.sh --json > counts.json  # the same numbers as a document
#   tools/stats.sh --file access.log     # a log already on this machine
#
# One command for what was three: find the log on the host, stream it here, and
# read it with `tools/visitors.php`. Nothing is written on the server and
# nothing is stored here — the log is piped straight through, so the only thing
# that lands on this machine is the counts.
#
# WHERE THE HOST COMES FROM
#
# `deploy.env`, which already names it for `deploy.sh`. The alternative was a
# second setting holding the same hostname, and two places to change when it
# moves is how one of them ends up wrong. `deploy.env` is gitignored, so no
# hostname enters the repository from here either.
#
# See `docs/ANALYTICS.md`.

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(dirname "$HERE")"
VISITORS="$HERE/visitors.php"

FILES=()
ARGS=()

while [ $# -gt 0 ]; do
    case "$1" in
        --file)
            [ $# -ge 2 ] || { echo "stats: --file needs a path" >&2; exit 2; }
            FILES+=("$2")
            shift 2
            ;;
        --log-dir)
            [ $# -ge 2 ] || { echo "stats: --log-dir needs a path" >&2; exit 2; }
            LOG_DIR="$2"
            shift 2
            ;;
        -h|--help)
            sed -n '3,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            # Anything else is for visitors.php: --json, and whatever it grows.
            ARGS+=("$1")
            shift
            ;;
    esac
done

command -v php >/dev/null 2>&1 || {
    echo "stats: php is not on PATH — it is what reads the log" >&2
    exit 1
}

# ---------------------------------------------------------------------------
# A log already on this machine. Also how this script is tested, since the
# remote half cannot be exercised without somebody's server.
# ---------------------------------------------------------------------------
if [ ${#FILES[@]} -gt 0 ]; then
    for f in "${FILES[@]}"; do
        [ -r "$f" ] || { echo "stats: cannot read $f" >&2; exit 1; }
    done

    # `zcat -f` passes a plain file through unchanged and decompresses a
    # gzipped one, so rotated logs need no special case. macOS ships a zcat
    # that refuses a plain file, hence gzip -cdf, which every platform has.
    gzip -cdf "${FILES[@]}" | php "$VISITORS" "${ARGS[@]+"${ARGS[@]}"}"
    exit $?
fi

# ---------------------------------------------------------------------------
# The host, from deploy.env.
# ---------------------------------------------------------------------------
ENV_FILE="$ROOT/deploy.env"

if [ ! -f "$ENV_FILE" ]; then
    cat >&2 <<MSG
stats: no deploy.env, so there is no host to pull from.

  cp deploy.env.example deploy.env    # then fill in REMOTE

Or read a log you already have:

  tools/stats.sh --file access.log
MSG
    exit 1
fi

# shellcheck disable=SC1090
. "$ENV_FILE"

if [ -z "${REMOTE:-}" ]; then
    # Stated rather than left to `${REMOTE:?...}`, whose message arrives
    # prefixed with the script name and a line number and reads like a crash.
    echo "stats: deploy.env has no REMOTE — see deploy.env.example" >&2
    exit 1
fi

TARGET="${REMOTE%%:*}"        # pooteewe@s006.cyon.net
DOCROOT="${REMOTE#*:}"        # /home/.../ultimate-broadcast.org
DOMAIN="$(basename "$DOCROOT")"

echo "stats: reading ${DOMAIN} on ${TARGET}" >&2

# ---------------------------------------------------------------------------
# One connection: find the log and stream it in the same remote shell.
#
# Two round trips would mean quoting a list of discovered paths back into a
# second ssh command, which is a quoting bug waiting to happen for no gain. The
# remote side reports what it chose on stderr, which ssh passes through, so the
# choice is visible rather than silent — if it picks the wrong file that is the
# line that says so.
#
# Hosts differ in where they put these. The candidates below are the common
# shared-hosting shapes; --log-dir overrides when a host does something else.
# ---------------------------------------------------------------------------
ssh "$TARGET" \
    "DOMAIN=$(printf %q "$DOMAIN") FORCED=$(printf %q "${LOG_DIR:-}") sh -s" <<'REMOTE' \
    | php "$VISITORS" "${ARGS[@]+"${ARGS[@]}"}"
set -eu

if [ -n "${FORCED:-}" ]; then
    DIRS="$FORCED"
else
    # $HOME/access-logs first: on cPanel-style hosting — cyon among them — it is
    # a symlink to the server's own domlogs directory, which holds the CURRENT
    # log for every domain on the account. It is readable over SSH without
    # turning anything on. The panel's "archive raw logs on my webspace" setting
    # is about keeping ROTATED copies afterwards, and costs quota; it is not
    # needed to read today's traffic.
    DIRS="$HOME/access-logs $HOME/logs/$DOMAIN $HOME/logs $HOME/log/$DOMAIN $HOME/log $HOME/var/log"
fi

# The same domain with its dots and hyphens removed.
#
# Those directories name a file after the domain, but not as anybody writes it:
# ultimate-broadcast.org arrives as addon-ultimatebroadcastorg.<account>.tld,
# with a -ssl_log sibling carrying the HTTPS requests — which on a site that
# forces HTTPS is all of the real traffic. Matching only the literal domain
# found the directory and rejected every file in it.
SQUASHED="$(printf %s "$DOMAIN" | tr -d '.-')"

FOUND=""
for d in $DIRS; do
    [ -d "$d" ] || continue
    for f in "$d"/*; do
        [ -f "$f" ] || continue
        case "$f" in
            *error*|*.conf|*.pid) continue ;;
        esac
        # Where a directory holds several domains' logs, take only this one's,
        # by either spelling.
        case "$d" in
            */"$DOMAIN") ;;
            *) case "$f" in
                   *"$DOMAIN"*) ;;
                   *"$SQUASHED"*) ;;
                   *) continue ;;
               esac ;;
        esac
        FOUND="$FOUND $f"
    done
    [ -n "$FOUND" ] && break
done

if [ -z "$FOUND" ]; then
    echo "stats: found no access log for $DOMAIN on this host." >&2
    echo "stats: looked in: $DIRS" >&2
    echo "stats: pass --log-dir <path> once you know where it is. To look:" >&2
    # -name rather than -iname, and no .htaccess: the obvious search returns a
    # screenful of those and nothing else, which is how the real directory was
    # missed the first time.
    echo "       ls -la \$HOME/access-logs/ 2>/dev/null" >&2
    echo "       find \$HOME -maxdepth 3 -name '*access*' -type f ! -name '.htaccess*'" >&2
    exit 3
fi

for f in $FOUND; do
    echo "stats: using $f" >&2
done

# Plain and gzipped in one pass; -f passes a non-gzip file straight through.
gzip -cdf $FOUND
REMOTE
