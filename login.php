<?php

/**
 * Standalone administrator login.
 *
 *   app.php?view=login
 *
 * Hosted, this page does not exist as far as anyone is concerned: Live! owns
 * the administrator session and `?view=live/admin` is where you log in. Here
 * there is no Live!, so this is the one door — and what it grants is exactly
 * what `Overlays\Auth::isAdmin()` answers: permission to change what is on air.
 *
 * It grants nothing else. A commentator still needs a room code, which is a
 * separate capability on purpose (`possession.php`, "two doors").
 */

if (!defined('UO_ROUTED_VIEW')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/shared/auth.php';
require_once __DIR__ . '/shared/mode.php';
require_once __DIR__ . '/shared/brand.php';

use Overlays\Auth;

// Hosted installations have a real login elsewhere; this page must not offer a
// second, weaker one beside it.
if (Auth::isHosted()) {
    http_response_code(404);
    exit;
}

/**
 * Where to go once this has worked.
 *
 * Somebody who signed in from a game arrived here, found a large **Sign out**
 * button and a small link to the Studio, and pressed the button — which is a
 * fair reading of a page whose loudest control is the wrong one. A login is a
 * detour, so it should end where it started.
 *
 * Validated rather than trusted: a redirect target from a URL is an open
 * redirect unless it is pinned to this site. A single leading slash, no scheme,
 * no backslash and no `//` leaves nothing that can address another host, and
 * anything failing that is dropped in favour of the Studio rather than
 * reported — a bad `next` is somebody probing, not a person to help.
 */
function safeNext(?string $raw): ?string
{
    $raw = trim((string) $raw);

    if ($raw === '' || strlen($raw) > 200) {
        return null;
    }
    if ($raw[0] !== '/' || str_starts_with($raw, '//')) {
        return null;
    }
    if (preg_match('#^/[A-Za-z0-9/._~=&%?-]*$#', $raw) !== 1) {
        return null;
    }

    return $raw;
}

$next = safeNext(filter_input(INPUT_GET, 'next') ?: (string) ($_POST['next'] ?? ''));

$error = null;
$done = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (isset($_POST['logout'])) {
        Auth::logout();
        $done = 'Logged out.';
    } elseif (!Auth::isConfigured()) {
        // Said plainly rather than as a failed login, because it is not one and
        // the fix is completely different.
        $error = 'No administrator password is set for this installation.';
    } elseif (Auth::attempt((string) ($_POST['password'] ?? ''))) {
        $done = 'Logged in.';

        // Straight back to the page that sent them, before anything on this one
        // can be pressed by mistake.
        if ($next !== null) {
            header('Location: ' . $next, true, 303);

            exit;
        }
    } else {
        // One message for a wrong password, deliberately saying nothing about
        // which part was wrong.
        $error = 'That password was not accepted.';
    }
}

$isAdmin = Auth::isAdmin();
$e = static fn(?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

header('Content-Type: text/html; charset=UTF-8');
// A login form must not be restored from cache after a logout.
header('Cache-Control: no-store, must-revalidate');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in — overlays</title>
<?= \Overlays\Brand::head('studio', '') ?>
<style>
    :root { color-scheme: light dark; }
    body { margin: 0; min-height: 100vh; display: grid; place-items: center;
        font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif;
        background: #f6f7f9; color: #16202e; }
    @media (prefers-color-scheme: dark) {
        body { background: #0f1a30; color: #e8eef7; }
        .card { background: #16233f; border-color: #2a3a5c; }
        input { background: #0f1a30; color: inherit; border-color: #2a3a5c; }
    }
    .card { background: #fff; border: 1px solid #d7dee8; border-radius: 10px;
        padding: 1.4rem 1.5rem; width: min(22rem, calc(100vw - 2rem)); }
    h1 { font-size: 1.1rem; margin: 0 0 .2rem; }
    p.sub { margin: 0 0 1rem; color: #5a6a80; font-size: .85rem; }
    label { display: block; font-size: .8rem; font-weight: 600; margin-bottom: .3rem; }
    input { width: 100%; box-sizing: border-box; padding: .55rem .6rem; font: inherit;
        border: 1px solid #c3ccd9; border-radius: 6px; }
    button { margin-top: .8rem; width: 100%; padding: .55rem; font: inherit;
        font-weight: 600; border: 0; border-radius: 6px; background: #2f6fdb;
        color: #fff; cursor: pointer; }
    .msg { margin: 0 0 .9rem; padding: .5rem .65rem; border-radius: 6px; font-size: .85rem; }
    .bad { background: #fdeaea; color: #8c1d1d; }
    .good { background: #e8f5ec; color: #14532d; }
    @media (prefers-color-scheme: dark) {
        .bad { background: #3b1a1a; color: #f6c8c8; }
        .good { background: #16341f; color: #c7ebd3; }
    }
    a { color: #2f6fdb; font-size: .82rem; }
    /* The mark beside the page's own heading. These three pages are chrome
       rather than surfaces, so they carry the project mark — the Studio's —
       rather than one of their own. */
    .onward { display: block; padding: .8rem; border-radius: 8px; text-align: center;
        font-weight: 700; text-decoration: none; background: #1d4ed8; color: #fff; }
    button.quiet { background: transparent; color: #cbd5e1; border: 1px solid #334155; }
    h1 { display: flex; align-items: center; gap: .55rem; }
    h1 .mark { flex: none; }
</style>
</head>
<body>
<main class="card">
    <h1><?= \Overlays\Brand::img('studio', '', 24) ?>Ultimate Broadcast</h1>
    <p class="sub">Signing in lets this browser change what is on air.</p>

    <?php if ($error !== null) : ?>
        <p class="msg bad"><?= $e($error) ?></p>
    <?php elseif ($done !== null) : ?>
        <p class="msg good"><?= $e($done) ?></p>
    <?php endif; ?>

    <?php if ($isAdmin) : ?>
        <?php
        // Carrying on is the common case and gets the button; signing out is
        // rare, deliberate, and no longer the first thing under a thumb.
        $onward = $next ?? \Overlays\Mode::viewUrl('index');
        $onwardLabel = $next !== null ? 'Back to where you were' : 'Open the Studio';
        ?>
        <p><a class="onward" href="<?= $e($onward) ?>"><?= $e($onwardLabel) ?></a></p>
        <form method="post">
            <?php if ($next !== null) : ?>
                <input type="hidden" name="next" value="<?= $e($next) ?>">
            <?php endif; ?>
            <button class="quiet" type="submit" name="logout" value="1">Sign out</button>
        </form>
    <?php else : ?>
        <?php if (!Auth::isConfigured()) : ?>
            <p class="msg bad">
                No administrator password is set. Run
                <code>php install/make-config.php</code> on the server, which writes
                <code>conf/local-config.php</code> with a hash of the password you give it.
            </p>
        <?php endif; ?>
        <form method="post">
            <?php if ($next !== null) : ?>
                <input type="hidden" name="next" value="<?= $e($next) ?>">
            <?php endif; ?>
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password"
                autofocus required>
            <button type="submit">Sign in</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
