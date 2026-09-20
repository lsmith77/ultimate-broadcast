#!/usr/bin/env bash
#
# Fetch the local recogniser: a WASM build of Vosk and an English model.
#
#   ./get-model.sh
#
# WHY THIS IS A SCRIPT AND NOT COMMITTED FILES
#
# The model is about 40MB and is NOT in the release archive, which is the whole
# reason this script exists. Git keeps every blob for ever, and this
# repository's defining property is that the directory IS the installation:
# 1.3MB of files anybody can read. A binary nobody can rebuild from source is
# the one thing that does not belong in it. So the bytes live here, ignored,
# and this script is the reproducible way to get them.
#
# WHY LOCAL RECOGNITION AT ALL
#
# The prototype used Chrome's Web Speech API, which is not in-browser at all:
# it streams the microphone to Google. That fails twice for this job — a pitch
# has no reliable network, and the round trip is too slow for calls that have
# to keep pace with play. Vosk runs in the page, and takes the call grammar as
# a decoding constraint rather than a post-hoc filter, which is the whole
# reason to prefer it over a general model.
#
# WHAT YOU NEED AFTERWARDS
#
# A web server, because the library spawns a Web Worker and browsers refuse
# those from file://. This project already uses PHP's built-in one everywhere:
#
#   php -S 0.0.0.0:8080 -t . app.php
#   open http://localhost:8080/app.php?view=spotter
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VENDOR="$HERE"

# Pinned rather than "latest": a prototype whose recogniser changes underneath
# it produces accuracy figures nobody can compare.
VOSK_VERSION="0.0.8"
VOSK_TARBALL="https://registry.npmjs.org/vosk-browser/-/vosk-browser-${VOSK_VERSION}.tgz"
MODEL_NAME="vosk-model-small-en-us-0.15"
MODEL_ZIP="https://alphacephei.com/vosk/models/${MODEL_NAME}.zip"

mkdir -p "$VENDOR"
cd "$VENDOR"

if [ -f vosk.js ]; then
    echo "vosk.js already here"
else
    echo "==> vosk-browser ${VOSK_VERSION} (Apache-2.0)"
    curl -sL -o vosk.tgz "$VOSK_TARBALL"
    tar xzf vosk.tgz package/dist/vosk.js
    mv package/dist/vosk.js vosk.js
    rm -rf package vosk.tgz
fi

if [ -f model.tar.gz ]; then
    echo "model.tar.gz already here"
else
    echo "==> ${MODEL_NAME} (about 40MB, this takes a minute)"
    curl -L --progress-bar -o model.zip "$MODEL_ZIP"
    # The published models are zips; vosk-browser wants a tar.gz, so repack.
    unzip -q model.zip
    tar czf model.tar.gz "$MODEL_NAME"
    rm -rf model.zip "$MODEL_NAME"
fi

printf '\n%s\n' "spotter/ is ready:"
ls -lh "$VENDOR" | awk 'NR>1 {printf "  %-16s %s\n", $9, $5}'

cat <<'DONE'

The page needs a server — the library spawns a Web Worker, and browsers
refuse those from file://:

    php -S 0.0.0.0:8080 -t . app.php
    open http://localhost:8080/app.php?view=spotter

Nothing here is committed, and nothing leaves the machine once it is
downloaded: recognition runs in the page.
DONE
