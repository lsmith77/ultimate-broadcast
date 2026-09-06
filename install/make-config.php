<?php

/**
 * Create `conf/local-config.php` — the two settings a standalone install needs.
 *
 *   php install/make-config.php
 *   php install/make-config.php --capture=fixtures/payloads/dev
 *   echo 'hunter2' | php install/make-config.php --password-stdin --capture=...
 *
 * Until this existed, a new standalone installation had no administrator until
 * somebody hand-wrote a PHP file containing a bcrypt hash they had generated
 * themselves with a one-liner from the documentation. It was the first thing
 * anyone hit and it was listed as the first gap in `docs/STANDALONE.md`, ahead
 * of everything else, because a mode nobody can configure is a mode nobody has.
 *
 * WHY IT IS A SCRIPT AND NOT A SETUP PAGE
 *
 * A setup page has to decide who is allowed to use it, and at that moment there
 * is by definition nobody to ask — which is why installers of this shape are a
 * recurring source of "the install wizard was still reachable in production".
 * Somebody who can run this already has shell on the host and does not need to
 * be authorised. It is also the smaller thing to get right.
 *
 * WHY IT WRITES PHP RATHER THAN JSON
 *
 * `conf/` is denied over HTTP by the shipped rules, but that denial lives in a
 * `.htaccess` and therefore in a file a misconfigured server can ignore. A
 * `.php` file read directly outputs nothing, so the hash survives the rules
 * being wrong. `Overlays\Auth` and `Overlays\Mode` both `require` it and expect
 * an array back.
 */

declare(strict_types=1);

/**
 * Never over the web.
 *
 * This writes the administrator credential, so a copy of it reachable over HTTP
 * would be a way to replace that credential. `install/` is denied by the shipped
 * rules as well; this is the lock that does not depend on the deployment being
 * configured correctly.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);

    exit;
}

$root = dirname(__DIR__);
$target = $root . '/conf/local-config.php';

$opts = getopt('', ['capture::', 'event::', 'demo', 'password-stdin', 'force', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, <<<TEXT
    Create conf/local-config.php for a standalone installation.

      --capture=PATH     where the recorded payloads live, relative to this
                         directory (e.g. fixtures/payloads/dev). Omit to read a
                         live Live! instead — which only works when there is one.
      --event=NAME       names this installation in the session key, so two
                         installs on one domain cannot share a login. Default
                         "standalone".
      --demo             a public demonstration: the two stores that take
                         unauthenticated writes (prepared notes, shared lines)
                         stop taking them from anyone but an administrator.
                         Reads are untouched, so every surface still works.
      --password-stdin   read the administrator password from stdin instead of
                         prompting, for a scripted install.
      --force            overwrite an existing config. It holds the password
                         hash and the operator's settings, so this is off by
                         default.

    TEXT);

    exit(0);
}

/** Report and stop. Anything half-written here leaves an install that cannot log in. */
function fail(string $message): never
{
    fwrite(STDERR, 'make-config: ' . $message . "\n");

    exit(1);
}

// Hosted mode answers the auth question through Live! and never reads this
// file, so writing one there would create a credential that does nothing —
// worth saying out loud rather than leaving somebody to discover.
//
// The same two-file test `Overlays\Auth` uses, and for the same reason: a lone
// `vendor/` beside the installation is somebody else's. A shared-hosting
// document root had one, and the first version of this warned about it.
if (is_file($root . '/../vendor/autoload.php') && is_file($root . '/../api.php')) {
    fwrite(STDERR, "make-config: warning — this looks like a hosted install "
        . "(Live! appears to be the directory above).\n"
        . "             Hosted mode authenticates through Live! and ignores this file.\n\n");
}

if (is_file($target) && !isset($opts['force'])) {
    fail("conf/local-config.php already exists. Pass --force to replace it "
        . '(it holds the password hash).');
}

$confDir = $root . '/conf';
if (!is_dir($confDir) && !@mkdir($confDir, 0o775, true) && !is_dir($confDir)) {
    fail('cannot create ' . $confDir . ' — create it and make it writable by the web server.');
}
if (!is_writable($confDir)) {
    fail($confDir . ' is not writable.');
}

/**
 * The capture directory, checked rather than taken on trust.
 *
 * A path with a typo in it produces pages that load and then say "Loading…"
 * for ever, because every payload read 404s — a failure that looks like a
 * network problem and is not. `tests/capture-check.mjs` is the deeper check;
 * this is the one that catches the wrong path at the moment it is entered.
 */
$capture = isset($opts['capture']) ? trim((string) $opts['capture']) : '';
if ($capture !== '') {
    $captureDir = $root . '/' . ltrim($capture, '/');
    if (!is_dir($captureDir)) {
        fail('no such capture directory: ' . $capture);
    }
    if (!is_file($captureDir . '/games.json')) {
        fail($capture . ' has no games.json — record one with '
            . 'node tests/capture.mjs --out ' . $capture);
    }
}

$event = isset($opts['event']) && trim((string) $opts['event']) !== ''
    ? trim((string) $opts['event'])
    : 'standalone';

/**
 * The password, without echoing it.
 *
 * `stty -echo` is what every interactive password prompt on a POSIX host does,
 * and the `finally` matters more than it looks: leaving a terminal with echo
 * off after an interrupt is a shell somebody has to blindly type `reset` into.
 */
function prompt(string $label): string
{
    fwrite(STDOUT, $label);
    $hasStty = PHP_OS_FAMILY !== 'Windows' && shell_exec('command -v stty') !== null;
    if ($hasStty) {
        shell_exec('stty -echo');
    }

    try {
        $line = fgets(STDIN);
    } finally {
        if ($hasStty) {
            shell_exec('stty echo');
        }
        fwrite(STDOUT, "\n");
    }

    return rtrim((string) $line, "\r\n");
}

/**
 * Short enough to guess is the same as no password on a page that decides what
 * goes on air. Not a policy, just a floor — checked before the repeat prompt so
 * that a password which was never going to be accepted is not typed twice first.
 */
function requireLength(string $password): void
{
    if (strlen($password) < 12) {
        fail('the password must be at least 12 characters.');
    }
}

if (isset($opts['password-stdin'])) {
    $password = rtrim((string) stream_get_contents(STDIN), "\r\n");
    requireLength($password);
} else {
    $password = prompt('Administrator password: ');
    requireLength($password);
    if (prompt('Repeat: ') !== $password) {
        fail('the two passwords do not match.');
    }
}

$hash = password_hash($password, PASSWORD_BCRYPT);
if (!is_string($hash)) {
    fail('password_hash() failed.');
}

$settings = ['event' => $event];
if ($capture !== '') {
    $settings['capture'] = $capture;
}
if (isset($opts['demo'])) {
    $settings['demo'] = true;
}
$settings['admin_hash'] = $hash;

// One writer for this file, shared with the event editor — see
// Overlays\Mode::saveLocalConfig(). It writes 0640 and moves it into place, so
// the hash is never briefly world-readable, and it invalidates the compiled
// copy so the next request reads what was just written.
require_once __DIR__ . '/../shared/mode.php';
if (!\Overlays\Mode::saveLocalConfig($settings, $target)) {
    fail('cannot write ' . $target);
}

fwrite(STDOUT, "Wrote " . $target . "\n"
    . '  event:   ' . $event . "\n"
    . '  capture: ' . ($capture !== '' ? $capture : '(none — reads a live Live!)') . "\n"
    . (isset($opts['demo']) ? "  demo:    yes — notes and lines are read-only\n" : '')
    . "\nSign in at /app.php?view=login\n");
