<?php

/**
 * Match control — the score and the clock, on a phone.
 *
 *   /k/702, or ?view=live/overlays/matchcontrol&game=702
 *
 * One job, and deliberately nothing else on the page: two teams with a large
 * press each, the score, an undo, and a clock. Every feature added here
 * competes with the one thing this surface exists to do, and the person doing
 * it is standing at a pitch rather than sitting at a desk.
 *
 * WHY IT IS A PHONE AND NOT A PANEL SOMEWHERE
 *
 * Whoever keeps score is often not broadcast crew — at three people or more it
 * is the person already holding the paper scoresheet — and they are rarely at
 * the laptop. A surface that requires the desk is a surface that gets driven
 * late, and a score that arrives late is the one number every viewer is
 * independently checking. `docs/MATCHCONTROL.md` has the crew reasoning.
 *
 * WHY IT KEEPS WORKING WHEN THE NETWORK DOES NOT
 *
 * Pitches are in parks. Every press is applied on this screen at once and sent
 * afterwards, and an unsent press is kept in an outbox and retried — so the
 * person pressing is never waiting on a bar of signal, and the count in front
 * of them is the count they entered.
 *
 * That is only safe because a goal names the point it completes: the same point
 * sent twice stores one goal (`shared/score.php`). Without that rule an outbox
 * would be a machine for double-counting, which is why the rule came first and
 * this page second.
 */

if (!defined('UO_ROUTED_VIEW')) {
    http_response_code(404);
    exit;
}

if (is_file(__DIR__ . '/../conf/LocalConfig.php')) {
    require_once __DIR__ . '/../conf/LocalConfig.php';
}
require_once __DIR__ . '/shared/mode.php';
require_once __DIR__ . '/shared/score.php';
require_once __DIR__ . '/shared/brand.php';
require_once __DIR__ . '/shared/auth.php';

use Overlays\Mode;
use Overlays\Score;

/**
 * The game id, read from `$_GET` rather than with `filter_input`.
 *
 * Every other page here uses `filter_input(INPUT_GET, ...)`, which reads the
 * ORIGINAL request and ignores anything a router wrote. This page is the one
 * exception, because standalone `app.php` serves it in place at `/k/<game>`
 * rather than redirecting to the long form — and it does that so a service
 * worker can be scoped to `/k/` in both modes, which is what keeps the worker
 * away from the surfaces that reach air. See the note in `app.php`.
 *
 * Validated exactly as the others are: a positive integer or nothing.
 */
$gameId = filter_var($_GET['game'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

$base = rtrim(defined('UO_URL_PREFIX') ? UO_URL_PREFIX : '/', '/');

$assetUrl = static function (string $relative) use ($base): string {
    $relative = ltrim($relative, '/');
    $path = __DIR__ . '/' . $relative;
    $version = is_file($path) ? (string) filemtime($path) : '0';

    return Mode::assetBase($base) . '/' . $relative . '?v=' . $version;
};
$json = static fn ($v): string => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

/**
 * The service worker, and the scope it is allowed.
 *
 * Two modes, two answers, and neither is a choice about caching — they follow
 * from where the file ends up being served.
 *
 * **Standalone** this directory IS the document root, so the worker sits at
 * `/sw.js` and takes the default scope `/`, which is the whole site. That is
 * only acceptable because the site is ours: the worker itself refuses every
 * air-facing URL by name (see `sw.js`), which is the guard that matters, rather
 * than the scope.
 *
 * **Hosted** it is served from `live/overlays/`, which by default would scope
 * it to that directory — and match control lives at `/k/<game>`, which is a
 * rewrite at the ROOT. A worker cannot claim a scope above itself unless the
 * response says so, so `.htaccess` sends `Service-Worker-Allowed: /k/` with
 * this one file. That keeps the claim to the one prefix this page uses, rather
 * than taking the whole origin, which under a Live! install would put a worker
 * in front of UltiOrganizer's own pages.
 *
 * A phone that opens the LONG url (`?view=live/overlays/matchcontrol`) hosted
 * is outside `/k/` and so gets no worker. That is the trade for not claiming
 * the origin, and it is why the instruction is to add `/k/<game>` to a home
 * screen.
 */
$hosted = \Overlays\Auth::isHosted();
/**
 * The cache name lives here, and is handed to the worker in its URL.
 *
 * The page fills the cache and the worker reads it, so both need the name — and
 * the page has to be the one that fills it: a first visit loads before any
 * worker controls it, so a worker-only cache would still be empty after the one
 * visit somebody was told to make while they had signal.
 *
 * Bump the version when what is cached changes shape. A new name is a new
 * worker, and the worker deletes every cache that is not its own.
 */
$swCache = 'uo-matchcontrol-v1';
$swUrl = ($hosted ? (Mode::assetBase($base) . '/sw.js') : ($base . '/sw.js'))
    . '?cache=' . rawurlencode($swCache);
// One scope, both modes: the prefix match control actually lives at. Standalone
// the file sits at the root and may narrow its own scope freely; hosted it is
// served from this directory and is allowed this prefix by a header.
$swScope = $base . '/k/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<!-- No zooming: a mis-pinch mid-game must not move the buttons under a thumb. -->
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="color-scheme" content="light dark">
<title>Match control</title>
<?= \Overlays\Brand::head('score', $base) ?>
<link rel="manifest" href="<?= htmlspecialchars(Mode::viewUrl('manifest', $base), ENT_QUOTES) ?>">
<!-- iOS reads these rather than the manifest on older versions, and the
     manifest on newer ones. Both are cheap and neither is on air. -->
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Score">
<meta name="theme-color" content="#0d1420">
<link rel="stylesheet" href="<?= htmlspecialchars($assetUrl('shared/scoreboard.css'), ENT_QUOTES) ?>">
<style>
    :root {
        --bg: #0d1420; --panel: #16202f; --line: #2a3a52; --ink: #f2f6fb;
        --ink-mute: #9fb0c6;   /* --home / --away: shared/scoreboard.css */
        --ok: #1f7a44; --warn: #8a5a12; --bad: #8c1d1d;
    }
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    html, body { height: 100%; margin: 0; }
    body {
        background: var(--bg); color: var(--ink);
        font: 16px/1.4 system-ui, -apple-system, "Segoe UI", sans-serif;
        display: flex; flex-direction: column;
        /* Safe areas: this runs full-screen on a phone that has a notch. */
        padding: env(safe-area-inset-top) env(safe-area-inset-right)
                 env(safe-area-inset-bottom) env(safe-area-inset-left);
        overscroll-behavior: none;
    }
    /* Sixteen pixels, in a header that is already the quietest thing on the
       page. This surface is two large buttons and everything else competes
       with them — the mark is here to be recognised later, not looked at now.
       The ring is not decoration: match control's tile is a dark slate, chosen
       because it survives every colour-blindness simulation against a LIGHT tab
       strip, and on this page's near-black header its edge disappears entirely.
       One asset cannot be optimal on both grounds, so the dark surface states
       the edge instead. */
    .mark { flex: none; border-radius: 50%;
            box-shadow: 0 0 0 1px rgba(255, 255, 255, .22); }
    header { display: flex; align-items: center; gap: .5rem; padding: .5rem .7rem;
        font-size: .8rem; color: var(--ink-mute); }
    header .grow { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis;
        white-space: nowrap; }
    .state { padding: .18rem .45rem; border-radius: 4px; font-weight: 700;
        font-size: .72rem; white-space: nowrap; }
    .state.ok { background: var(--ok); }
    .state.pending { background: var(--warn); }
    /* The list of games this phone is carrying.
       Shown at /k/ with no game, which is where an icon on a home screen lands
       once somebody has added one. Rows are big enough to hit with a thumb and
       say the one thing that matters in a car park: is anything still owed. */
    /* Somebody else's score won. Loud enough to be read once, quiet enough
       not to be mistaken for the buttons: it is not an error, and the phone is
       still working perfectly. */
    .clash { display: flex; align-items: center; gap: .6rem; margin: 0 .6rem .4rem;
        padding: .55rem .7rem; border-radius: 8px; background: var(--warn);
        font-size: .85rem; font-weight: 600; }
    .clash button { flex: none; font: inherit; font-weight: 700; padding: .25rem .7rem;
        border-radius: 6px; border: 1px solid rgba(255, 255, 255, .35);
        background: rgba(0, 0, 0, .18); color: var(--ink); }
    .games { padding: .4rem .7rem 1rem; overflow-y: auto; }
    .games h1 { font-size: 1rem; margin: .3rem 0 .2rem; }
    .games .hint { color: var(--ink-mute); font-size: .82rem; margin: 0 0 .7rem; }
    .games ul { list-style: none; margin: 0; padding: 0; }
    .games li { display: flex; align-items: stretch; gap: .4rem; margin-bottom: .5rem; }
    .games .drop { flex: none; padding: 0 .7rem; font: inherit; font-size: .76rem;
        border: 1px solid var(--line); border-radius: 10px; background: transparent;
        color: var(--ink-mute); }
    .games .drop:disabled { opacity: .35; }
    /* Both ways in look like ways in.
       The sign-in link was grey 0.85rem text between the code box and a large
       button, under a message that said "ask the operator" — so the person who
       WAS the operator read past it and reported there was no way to sign in.
       It is a button now, and the message names it. */
    .setup .setupbtn { display: block; width: 100%; padding: .85rem; font: inherit;
        font-weight: 700; text-align: center; text-decoration: none;
        border-radius: 10px; border: 1px solid var(--line);
        background: var(--panel); color: var(--ink); }
    .setup .signin { background: transparent; }
    .setup .setupor { margin: .6rem 0; text-align: center; color: var(--ink-mute);
        font-size: .8rem; }
    .setup .hide { display: none; }
    /* The row must shrink, or a long fixture name pushes the score and the
       Remove button off the side of a phone — which is what it did, and which
       a screenshot caught and a passing test did not. `min-width: 0` on both
       flex children is what lets the name's ellipsis do its job. */
    .games li > a { flex: 1; min-width: 0; }
    .games a { display: flex; align-items: center; gap: .6rem; padding: .7rem .8rem;
        border: 1px solid var(--line); border-radius: 10px; background: var(--panel);
        color: var(--ink); text-decoration: none; }
    .games .sc, .games .state { flex: none; }
    .games .who { flex: 1; min-width: 0; }
    .games .who b { display: block; font-size: .95rem; overflow: hidden;
        text-overflow: ellipsis; white-space: nowrap; }
    .games .who span { display: block; color: var(--ink-mute); font-size: .76rem;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .games .sc { font-variant-numeric: tabular-nums; font-weight: 800; font-size: 1.1rem; }
    .gamebar { display: flex; gap: .5rem; margin-top: .8rem; }
    .gamebar button { flex: 1; padding: .7rem; font: inherit; font-weight: 700;
        border-radius: 10px; border: 1px solid var(--line);
        background: var(--panel); color: var(--ink); }
    /* The advanced panel. Deliberately quieter than the score buttons: it is
       read and considered, not thumbed at speed. */
    /* And the panel is what gives. A flex item's default minimum is its
       content, so an open panel taller than the room left simply pushed the
       column past the bottom of a page that does not scroll. It scrolls
       itself instead: it is the part somebody reads, and the part they can
       afford to reach for. */
    .more { padding: 0 .6rem .8rem; min-height: 0; overflow-y: auto; }
    .moretoggle { width: 100%; padding: .6rem; font: inherit; font-size: .85rem;
                  color: var(--ink-mute); background: none; border: 0; }
    .panel section { border-top: 1px solid var(--line); padding: .7rem .1rem .2rem; }
    .panel h2 { margin: 0; font-size: .78rem; text-transform: uppercase;
                letter-spacing: .06em; color: var(--ink-mute); font-weight: 700; }
    .panel .why { margin: .15rem 0 .5rem; font-size: .78rem; color: var(--ink-mute); }
    .panel .row { display: flex; gap: .4rem; }
    .panel .opt { flex: 1; padding: .7rem .4rem; font: inherit; font-weight: 700;
                  font-size: .9rem; border-radius: 8px; border: 1px solid var(--line);
                  background: var(--panel); color: var(--ink); }
    .panel .opt.ghost { flex: 0 0 3rem; font-weight: 400; color: var(--ink-mute); }
    .panel .opt.on { background: var(--home); border-color: var(--home); color: #fff; }
    .panel .opt.warn.on { background: var(--warn); border-color: var(--warn); }
    .panel select { flex: 1; padding: .6rem .4rem; font: inherit; font-size: .9rem;
                    border-radius: 8px; border: 1px solid var(--line);
                    background: var(--panel); color: var(--ink); }
    .state.bad { background: var(--bad); }
    .offairdo { display: block; width: 100%; margin-top: .5rem; padding: .6rem;
        font: inherit; font-weight: 700; border-radius: 8px;
        border: 1px solid rgba(255, 255, 255, .35); background: rgba(0, 0, 0, .18);
        color: var(--ink); }
    .offairdo.hide { display: none; }
    /* The one destructive control on the page. It asks twice: the first press
       says what it is about to do, the second does it, and it goes back to
       asking if nobody answers. A phone in a pocket presses things. */
    .opt.danger { border-color: var(--bad); }
    .opt.danger.armed { background: var(--bad); color: #fff; }
    .offairx { float: right; margin: -.2rem -.2rem 0 .4rem; padding: 0 .45rem;
        font: inherit; font-size: 1.1rem; line-height: 1.4; border: 0;
        border-radius: 6px; background: transparent; color: inherit; opacity: .7; }

    /* The two presses. Everything else on the page is smaller than these. */
    /* `min-height` is a floor under the job, not a nicety. The column is
       height-constrained, `.teams` takes what is left, and what was left went
       to ZERO the first time the More panel grew past the viewport: the two
       presses vanished and the clock row was painted over what remained of
       them, so a tap meant for the home team hit the clock. Measured at
       720px, not guessed at. Whatever else has to give, these do not. */
    /* .teams / .team: shared/scoreboard.css, so the spotter matches. */

    .clock { display: flex; align-items: center; gap: .5rem; padding: .6rem .7rem .2rem; }
    .clock .t { font-size: 1.6rem; font-weight: 700; font-variant-numeric: tabular-nums;
        min-width: 4.6rem; }
    .bar { display: flex; gap: .5rem; padding: .5rem .7rem calc(.7rem + env(safe-area-inset-bottom)); }
    .bar button { flex: 1; padding: .85rem .4rem; font: inherit; font-weight: 700;
        border-radius: 10px; border: 1px solid var(--line); background: var(--panel);
        color: var(--ink); cursor: pointer; }
    .bar button:disabled { opacity: .4; }
    .bar button.wide { flex: 1.4; }
    .setup { padding: 1rem; display: grid; gap: .6rem; }
    .setup input { font: inherit; font-size: 1.4rem; letter-spacing: .2em;
        text-align: center; text-transform: uppercase; padding: .6rem;
        border-radius: 8px; border: 1px solid var(--line);
        background: var(--panel); color: var(--ink); }
    .setup p { margin: 0; color: var(--ink-mute); font-size: .85rem; }

    /**
     * The banner that says this is not what the scoreboard is reading.
     *
     * Loud on purpose, and across the top where it cannot be scrolled past.
     * The failure it prevents is somebody keeping a whole game's score
     * carefully into a store nothing consumes — which looks exactly like
     * working, all the way until somebody watches the broadcast.
     */
    .offair { background: var(--warn); color: #fff; padding: .55rem .7rem;
        font-size: .82rem; font-weight: 700; line-height: 1.35; }
    .offair span { display: block; font-weight: 400; opacity: .92; }
    .hide { display: none !important; }
</style>
</head>
<body>

<header>
    <?= \Overlays\Brand::img('score', $base, 16) ?>
    <span class="grow" id="fixture">Match control</span>
    <span class="state" id="state">…</span>
</header>

<!-- A conflict, which is news rather than a state: it is dismissed by the
     person who reads it, not by the next poll. -->
<div class="clash hide" id="clash">
    <span id="clashText"></span>
    <button id="clashOk" type="button">OK</button>
</div>

<div class="offair hide" id="offair">
    <button class="offairx" id="offairHide" type="button" aria-label="Hide this notice">&times;</button>
    Not on the scoreboard
    <span id="offairWhy">The overlay is still showing the score from upstream. Ask the
        operator to switch the scoreboard to this game's match control.</span>
    <!-- Shown to the operator, who is the person who can act on the sentence
         above rather than pass it on. -->
    <button class="offairdo hide" id="offairDo" type="button">Show this score on the scoreboard</button>
</div>

<section class="games hide" id="pick">
    <h1>Games at this event</h1>
    <p class="hint">Tap one to set it up on this phone. Needs signal, so do it before you go.</p>
    <ul id="pickList"></ul>
</section>

<section class="games hide" id="games">
    <h1>Games on this phone</h1>
    <p class="hint" id="gamesHint"></p>
    <ul id="gameList"></ul>
    <div class="gamebar">
        <button id="exportAll" type="button">Export all</button>
    </div>
</section>

<div class="setup hide" id="setup">
    <p id="setupWhy">Enter the code the operator gave you for this game.</p>
    <input id="code" inputmode="latin" autocapitalize="characters" autocomplete="off"
        maxlength="<?= (int) Score::CODE_LENGTH ?>" aria-label="Scorekeeping code">
    <button class="bar setupbtn" id="useCode" type="button">Use this code</button>
    <p class="setupor hide" id="signinRow">or</p>
    <a class="bar setupbtn signin hide" id="signin">Sign in as the operator</a>
</div>

<main class="teams hide" id="teams">
    <button class="team home" id="homeBtn" type="button">
        <span class="name" id="homeName">Home</span>
        <span class="n" id="homeScore">0</span>
    </button>
    <button class="team away" id="awayBtn" type="button">
        <span class="name" id="awayName">Away</span>
        <span class="n" id="awayScore">0</span>
    </button>
</main>

<div class="clock hide" id="clockRow">
    <span class="t" id="clock">--:--</span>
    <span id="clockNote" style="color:var(--ink-mute);font-size:.8rem"></span>
</div>

<div class="bar hide" id="bar">
    <button id="startBtn" type="button">Start</button>
    <button id="halfBtn" type="button">Half</button>
    <button id="undoBtn" class="wide" type="button">Undo</button>
</div>

<!--
  Everything else that is true about the game and recorded nowhere.

  Behind a toggle because it is not the job. The two big buttons above are the
  job, they are pressed with a thumb while watching play, and a panel of eight
  more controls in front of them is how somebody presses the wrong one at 13-12.
  Whoever wants these opens them once and they stay open — the choice is
  remembered on this phone.
-->
<div class="more hide" id="moreWrap">
    <button class="moretoggle" id="moreBtn" type="button" aria-expanded="false"
        aria-controls="more">More ▾</button>

    <div class="panel hide" id="more">
        <section>
            <h2>Possession</h2>
            <p class="why" id="possWhy">Which side has the disc — the receiving team
                is the offence. Feeds break chance and the clean-hold count on air.</p>
            <div class="row">
                <button class="opt" id="possOff" type="button">Offence</button>
                <button class="opt" id="possDef" type="button">Defence</button>
                <button class="opt ghost" id="possUndo" type="button">↶</button>
            </div>
        </section>

        <section>
            <h2>Timeouts</h2>
            <p class="why">Drawn as ticks under each team on air. Nothing upstream
                records these.</p>
            <div class="row">
                <button class="opt" id="toHome" type="button">Home <span id="toHomeN"></span></button>
                <button class="opt" id="toAway" type="button">Away <span id="toAwayN"></span></button>
                <button class="opt ghost" id="toUndo" type="button">↶</button>
            </div>
        </section>

        <section>
            <h2>Injury stoppage</h2>
            <p class="why">Play has stopped for something the clock does not show.
                Clears itself at the next point.</p>
            <div class="row">
                <button class="opt" id="stopBtn" type="button">Stopped</button>
            </div>
        </section>

        <section>
            <h2>Clock</h2>
            <p class="why">Starting a running clock does not restart it, on purpose —
                a second press is somebody checking. This is how a clock started by
                mistake, or on the wrong game, goes back to nothing.</p>
            <div class="row">
                <button class="opt danger" id="clockReset" type="button">Reset clock</button>
            </div>
        </section>

        <section id="ratioBox">
            <h2>First point</h2>
            <p class="why">The opening gender ratio, and how many a side. Set once;
                everything after it follows the rule.</p>
            <div class="row">
                <select id="ratioSel" aria-label="First point ratio"></select>
                <select id="sizeSel" aria-label="Players per side"></select>
            </div>
        </section>
    </div>
</div>

<script src="<?= htmlspecialchars($assetUrl('shared/provider.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars($assetUrl('shared/score-client.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars($assetUrl('shared/score-archive.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars($assetUrl('shared/possession.js'), ENT_QUOTES) ?>"></script>
<script>
(function () {
    'use strict';

    var CONFIG = {
        gameId: <?= $json($gameId ?: null) ?>,
        scoreUrl: <?= $json(Mode::viewUrl('score', $base)) ?>,
        api: <?= $json($base . '/index.php?view=live/api') ?>,
        captureBase: <?= $json(Mode::captureBase($base)) ?>,
        codeLength: <?= (int) Score::CODE_LENGTH ?>,
        // Possession, the injury stoppage and the first point's ratio live in
        // the possession store rather than this one. They are the same kind of
        // fact — true about the game, recorded nowhere else — and `possession.php`
        // now accepts the SCOREKEEPING code for them, so this phone does not
        // have to carry a second one.
        possessionUrl: <?= $json(Mode::viewUrl('possession', $base)) ?>,
        // Where somebody signs in. An operator keeping their own score needs no
        // code at all — the store lets an administrator write any game — and on
        // a one-person rig the operator and the scorekeeper are one person.
        // Published, and prefilled, only on a demonstration: there is nobody to
        // ask for a code, and a scorekeeper's phone that cannot be pressed
        // demonstrates nothing. See Overlays\Mode::DEMO_CODE.
        demoCode: <?= $json(\Overlays\Mode::isDemo() && !\Overlays\Auth::isAdmin() ? \Overlays\Mode::DEMO_CODE : null) ?>,
        loginUrl: <?= $json(Mode::loginUrl($base)) ?>,
        // A link to one game, with the id to be filled in. From Mode rather
        // than written here: `/k/702` is a rewrite that only exists where the
        // host's snippet was pasted, and the long form always works.
        gameUrl: <?= $json($base . '/k/%GAME%') ?>,
        listUrl: <?= $json($base . '/k/') ?>,
        // Where the worker lives, and the scope it may have. Both differ by
        // mode: standalone this directory IS the document root, so the script
        // sits at /sw.js and may control everything; hosted it is served from
        // this directory and is allowed the /k/ prefix by a header, which is
        // where match control actually lives.
        swUrl: <?= $json($swUrl) ?>,
        swScope: <?= $json($swScope) ?>,
        swCache: <?= $json($swCache) ?>
    };

    /**
     * Register the worker, and never let it be the reason the page fails.
     *
     * Everything on this screen works without one — the outbox, the local
     * score, the list — so a browser that refuses (no support, a private
     * window, an insecure origin, a scope the header did not allow) loses
     * offline RELOADS and nothing else. It is registered after load so it never
     * competes with the first paint on a phone with one bar.
     */
    if ('serviceWorker' in navigator && CONFIG.swUrl) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register(CONFIG.swUrl, { scope: CONFIG.swScope })
                .catch(function () { /* offline reloads only; the score still works */ });
            warmCache();
        });
    }

    /**
     * Put this page and its assets in the cache, from the page.
     *
     * The worker cannot do it on a first visit: the navigation and every asset
     * have already been fetched by the time it activates, so its fetch handler
     * never sees them and the cache stays empty — after exactly the one visit a
     * scorekeeper was told to make while they had signal. The second visit
     * would fix it, which is not an instruction anybody should have to follow.
     *
     * So the page stores its own shell. The worker still owns READING, and
     * still refuses everything that reaches air.
     */
    function warmCache() {
        if (!window.caches) { return; }
        /**
         * This page, its assets, AND the list.
         *
         * The list is the manifest's `start_url` — it is what a home screen
         * icon opens — so a phone that had only ever opened a GAME would tap
         * its own icon in a car park and get a network error. One game visit
         * now caches both, which matches the instruction people are given:
         * open each game once while you have signal.
         */
        var urls = [window.location.href, CONFIG.listUrl];
        var nodes = document.querySelectorAll('script[src], link[href]');
        for (var i = 0; i < nodes.length; i += 1) {
            var u = nodes[i].getAttribute('src') || nodes[i].getAttribute('href');
            // Only this origin's own files, and never the manifest's icons by
            // accident: an absolute URL elsewhere is somebody else's to serve.
            if (u && u.indexOf('//') !== 0 && u.indexOf('http') !== 0) { urls.push(u); }
        }
        caches.open(CONFIG.swCache).then(function (c) {
            // One at a time rather than addAll: addAll rejects the whole set if
            // any single request fails, and a missing icon must not cost the
            // page its offline copy.
            urls.forEach(function (u) { c.add(u).catch(function () { }); });
        }).catch(function () { /* storage blocked; online still works */ });
    }

    var el = function (id) { return document.getElementById(id); };
    var CODE_KEY = 'uo-score-code-' + CONFIG.gameId;

    /**
     * Downloading a file from a page that may be offline.
     *
     * A Blob URL rather than a request: there is nothing to ask a server for,
     * and the whole point is that this works in a car park.
     */
    function download(name, text) {
        var blob = new Blob([text], { type: 'application/json' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = name;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        // Revoked on a turn of the loop: Safari has been known to cancel a
        // download whose URL is revoked in the same tick as the click.
        setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
    }

    function exportName(game) {
        var stamp = new Date().toISOString().slice(0, 10);
        return game ? ('score-' + game + '-' + stamp + '.json')
            : ('scores-' + stamp + '.json');
    }

    /**
     * With no game: the games this phone is carrying.
     *
     * This is where a home-screen icon lands, so it is a real screen rather
     * than an error — and the sentence it has to answer is "have I handed
     * everything over", which is why the pending count is the loudest thing on
     * a row after the score.
     */
    if (!CONFIG.gameId) {
        el('fixture').textContent = 'Match control';
        el('state').classList.add('hide');
        el('games').classList.remove('hide');
        renderList();
        offerGames();
        return;
    }

    /**
     * The event's games, for a phone that is the only device present.
     *
     * The list above is what this phone has already been given. That is no help
     * to somebody setting one up: they would have to know a game id and type a
     * URL, from a laptop they may not have. One person filming their own club's
     * game is the case this project keeps saying it serves, and they were the
     * one audience expected to arrive with a link already in hand.
     *
     * Only with signal, and quietly: a phone at a pitch has neither the network
     * nor the need, and the games it is carrying are listed above regardless.
     */
    function offerGames() {
        if (!window.Provider) { return; }
        var api = window.Provider.fromConfig({ apiBase: CONFIG.api, captureBase: CONFIG.captureBase });
        var known = {};
        (window.ScoreArchive ? window.ScoreArchive.games() : []).forEach(function (g) {
            known[String(g.id)] = true;
        });

        Promise.all([api.games(), api.teams().catch(function () { return null; })])
            .then(function (both) {
                var games = (both[0] && both[0].games) || [];
                /**
                 * `teams` is an OBJECT keyed by team id, not a list.
                 *
                 * Treating it as a list threw inside this promise, which the
                 * catch below then swallowed — so the picker simply never
                 * appeared, with nothing logged and nothing shown. That is the
                 * shape trap `PLAN.md` keeps a list of, and the reason a catch
                 * around a whole block is worth being suspicious of.
                 */
                var teams = (both[1] && both[1].teams) || {};
                var names = {};
                Object.keys(teams).forEach(function (id) {
                    var t = teams[id] || {};
                    names[String(t.team_id || id)] = t.name || t.abbreviation || '';
                });
                if (!games.length) { return; }

                // Live games first, then by kickoff: at a tournament the next
                // thing somebody keeps score for is nearly always one of those.
                games.sort(function (a, b) {
                    var live = (Number(b.isongoing) || 0) - (Number(a.isongoing) || 0);
                    return live || String(a.time || '').localeCompare(String(b.time || ''));
                });

                var ul = el('pickList');
                var shown = 0;
                games.forEach(function (g) {
                    if (shown >= 20 || known[String(g.game_id)]) { return; }
                    shown += 1;
                    var li = document.createElement('li');
                    var a = document.createElement('a');
                    a.href = CONFIG.gameUrl.replace('%GAME%', String(g.game_id));

                    var who = document.createElement('span');
                    who.className = 'who';
                    var b = document.createElement('b');
                    var home = names[String(g.hometeam)] || ('Team ' + g.hometeam);
                    var away = names[String(g.visitorteam)] || ('Team ' + g.visitorteam);
                    b.textContent = home + ' v ' + away;
                    var sub = document.createElement('span');
                    sub.textContent = [g.gamename || '', (g.time || '').slice(0, 16).replace('T', ' ')]
                        .filter(Boolean).join(' \u00b7 ');
                    who.appendChild(b);
                    who.appendChild(sub);

                    var flag = document.createElement('span');
                    flag.className = 'state ' + (Number(g.isongoing) === 1 ? 'pending' : 'ok');
                    flag.textContent = Number(g.isongoing) === 1 ? 'live' : 'set up';

                    a.appendChild(who);
                    a.appendChild(flag);
                    li.appendChild(a);
                    ul.appendChild(li);
                });

                if (shown) { el('pick').classList.remove('hide'); }
            })
            .catch(function () { /* no signal, or no event: the device list stands */ });
    }

    /**
     * Send what every game on this phone is still holding.
     *
     * The list used to be a display, and the documentation said "open the game
     * or the list and it sends" — which was true of the game and false here,
     * because only the game page built a client. Somebody in signal could sit
     * on the screen that exists to answer "have I handed everything over"
     * while it handed nothing over.
     *
     * One client per game with something unsent, drained once. They are the
     * same clients the game page uses, with the same code and the same
     * idempotence, so a press that lands here is a press that does not land
     * twice when the game is next opened.
     */
    function drainAll(list, done) {
        var owedGames = list.filter(function (g) { return g.pending > 0; });
        if (!owedGames.length) { done(false); return; }

        var left = owedGames.length;
        owedGames.forEach(function (g) {
            var stored = '';
            try { stored = window.localStorage.getItem('uo-score-code-' + g.id) || ''; }
            catch (e) { stored = ''; }

            window.ScoreClient.create({
                url: CONFIG.scoreUrl,
                game: g.id,
                code: function () { return stored; },
                // No polling: this is a delivery run, not a screen.
                poll: 0
            }).flush().then(function () {
                left -= 1;
                if (left === 0) { done(true); }
            });
        });
    }

    function renderList() {
        var list = window.ScoreArchive ? window.ScoreArchive.games() : [];
        var owed = window.ScoreArchive ? window.ScoreArchive.owed() : { games: 0, presses: 0 };

        /**
         * Three states, not two.
         *
         * "Nothing left to send" is not "everything got through": a conflict or
         * a refusal empties the queue exactly as a success does. Refused work
         * is counted separately because finding signal cannot fix it — somebody
         * has to look at it.
         */
        el('gamesHint').textContent = !list.length
            ? 'Open a game once while you have signal and it will be listed here, '
                + 'ready to keep offline.'
            : owed.games
                ? (owed.presses + (owed.presses === 1 ? ' press' : ' presses')
                    + ' from ' + owed.games + (owed.games === 1 ? ' game' : ' games')
                    + ' still to send. Sending…')
                : owed.declined
                    ? (owed.declined + (owed.declined === 1 ? ' press was' : ' presses were')
                        + ' not accepted — everything else has been sent.')
                    : 'Everything here has been sent.';

        var ul = el('gameList');
        ul.replaceChildren();
        list.forEach(function (g) {
            var li = document.createElement('li');
            var a = document.createElement('a');
            a.href = CONFIG.gameUrl.replace('%GAME%', String(g.id));

            var who = document.createElement('span');
            who.className = 'who';
            var b = document.createElement('b');
            b.textContent = (g.home && g.away) ? (g.home + ' v ' + g.away)
                : (g.name || ('Game ' + g.id));
            var sub = document.createElement('span');
            // What LANDED, rather than only what is left: the server's own
            // score where it has given one, so "Sent" is a fact rather than an
            // inference from an empty queue.
            var landed = g.sent ? (g.sent.home + '\u2013' + g.sent.away + ' sent') : 'Not sent yet';
            sub.textContent = g.pending
                ? (landed + ' \u00b7 ' + g.pending
                    + (g.pending === 1 ? ' press' : ' presses') + ' to send')
                : g.declined
                    ? (landed + ' \u00b7 ' + g.declined
                        + (g.declined === 1 ? ' press' : ' presses') + ' not accepted')
                    : landed;
            who.appendChild(b);
            who.appendChild(sub);

            var sc = document.createElement('span');
            sc.className = 'sc';
            sc.textContent = g.score.home + '\u2013' + g.score.away;

            var flag = document.createElement('span');
            flag.className = 'state ' + (g.pending ? 'pending' : (g.declined ? 'bad' : 'ok'));
            flag.textContent = g.pending ? String(g.pending)
                : (g.declined ? '!' : 'ok');

            a.appendChild(who);
            a.appendChild(sc);
            a.appendChild(flag);
            li.appendChild(a);

            /**
             * Remove a game from this phone.
             *
             * Refused while anything is unsent — that queue is the only copy of
             * something somebody did — and it takes the scorekeeping code with
             * it, because a phone that has "forgotten" a game and can still
             * write to it has not forgotten it.
             */
            var drop = document.createElement('button');
            drop.type = 'button';
            drop.className = 'drop';
            drop.textContent = 'Remove';
            drop.disabled = g.pending > 0;
            drop.title = g.pending > 0
                ? 'Not while presses are still to send.'
                : (g.declined
                    ? 'Forget this game, including the presses that were not accepted.'
                    : 'Forget this game on this phone, including its code.');
            drop.addEventListener('click', function () {
                if (window.ScoreArchive.forget(null, g.id)) { renderList(); }
            });
            li.appendChild(drop);

            ul.appendChild(li);
        });

        el('exportAll').disabled = list.length === 0;
        el('exportAll').onclick = function () {
            download(exportName(null),
                JSON.stringify(window.ScoreArchive.exportAll(), null, 2));
        };

        // Hand over whatever is still owed, then say so.
        drainAll(list, function (sent) {
            if (sent) { renderList(); }
        });
    }

    var code = '';
    try { code = window.localStorage.getItem(CODE_KEY) || ''; } catch (e) { code = ''; }

    var keeper = window.ScoreClient.create({
        url: CONFIG.scoreUrl,
        game: CONFIG.gameId,
        code: function () { return code; }
    });

    /* ------------------------------------------------------------------
       Painting. The score shown is always what this phone believes, which
       during an outage is ahead of what the server has — that is the point.
       ------------------------------------------------------------------ */

    /* --------------------------------------------------------------
       The advanced panel
       -------------------------------------------------------------- */

    var MORE_KEY = 'uo-score-more-' + CONFIG.gameId;
    var poss = { enabled: false, events: [], stoppage: null, ratio1: null,
        size: null, canTrack: false };

    /** The possession store, addressed with the scorekeeping code. */
    function possess(change) {
        var body = { game: CONFIG.gameId, code: code };
        Object.keys(change).forEach(function (k) { body[k] = change[k]; });

        return fetch(CONFIG.possessionUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
            .then(function (r) {
                return r.json().then(function (b) {
                    if (!r.ok || (b && b.error)) {
                        throw new Error((b && b.error) || ('HTTP ' + r.status));
                    }
                    absorbPossession(b);

                    return b;
                });
            });
    }

    function absorbPossession(b) {
        poss = {
            enabled: Boolean(b.enabled),
            events: b.events || [],
            stoppage: b.stoppage || null,
            ratio1: b.ratio1 || null,
            size: b.size === undefined ? null : b.size,
            canTrack: Boolean(b.canTrack)
        };
        paintMore();
    }

    /**
     * Who has the disc, through `shared/possession.js`.
     *
     * Read from the module rather than from the log directly, for two reasons
     * this page got wrong on its own: an event records the defence as `d`, not
     * as `defence`, so reading the wrong key made every press look like no
     * change at all; and the holder is the last declaration **for the point
     * being played**, not the last in the log, so after a goal the panel would
     * otherwise show whoever had it during the previous point.
     *
     * A point with no events yet is the receiving team's, which is why nothing
     * resets at a goal — the new score simply has no events.
     */
    function defenceHasDisc() {
        var s = keeper.view();

        return window.Possession.defenceHasDisc(poss.events, s.home, s.away);
    }

    function readPossession() {
        return fetch(CONFIG.possessionUrl + '&game=' + encodeURIComponent(CONFIG.gameId)
            + '&code=' + encodeURIComponent(code), { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (b) { if (b) { absorbPossession(b); } })
            .catch(function () { /* the panel simply stays as it was */ });
    }

    /**
     * The score as the possession store keys its events.
     *
     * Every declaration is filed under the score it was made at, so a press
     * recorded for a point that has since ended can be told apart from one
     * about the point being played. `shared/possession.php` explains why.
     */
    function scoreKey() {
        var s = keeper.view();

        return s.home + '-' + s.away;
    }

    function paintMore() {
        var s = keeper.view();

        // OFFENCE and DEFENCE, not home and away — which is what the store
        // records and what the desk's own control says. Whose offence it is
        // changes at every goal and is not a thing anybody has to re-declare,
        // so naming the sides here would have asked for the wrong fact.
        var d = defenceHasDisc();
        el('possOff').classList.toggle('on', !d);
        el('possDef').classList.toggle('on', d);
        el('possWhy').textContent = poss.enabled
            ? 'Which side has the disc — the receiving team is the offence.'
            : 'Possession tracking is off — the operator switches it on in the Studio.';
        ['possOff', 'possDef', 'possUndo'].forEach(function (id) {
            el(id).disabled = !poss.enabled || !s.canWrite;
        });

        var used = { home: 0, away: 0 };
        (s.timeouts || []).forEach(function (t) {
            if (t.home) { used.home += 1; } else { used.away += 1; }
        });
        el('toHomeN').textContent = used.home ? '· ' + used.home : '';
        el('toAwayN').textContent = used.away ? '· ' + used.away : '';
        el('toUndo').disabled = !s.canWrite || (used.home + used.away === 0);

        el('stopBtn').classList.toggle('on', Boolean(poss.stoppage));
        el('stopBtn').textContent = poss.stoppage ? 'Stopped ✓' : 'Stopped';

        if (el('ratioSel').value !== (poss.ratio1 || '')) {
            el('ratioSel').value = poss.ratio1 || '';
        }
        if (el('sizeSel').value !== (poss.size === null ? '' : String(poss.size))) {
            el('sizeSel').value = poss.size === null ? '' : String(poss.size);
        }
        ['stopBtn', 'ratioSel', 'sizeSel', 'toHome', 'toAway', 'clockReset'].forEach(function (id) {
            el(id).disabled = !s.canWrite;
        });
    }

    function wireMore() {
        // Ratios, offered rather than typed: a free text box at a pitch is a
        // typo that fails validation on the server and looks like a broken app.
        var ratios = ['', '4MMP/3FMP', '3MMP/4FMP', '4FMP/3MMP', '3FMP/4MMP',
            '3MMP/3FMP', '2MMP/3FMP', '3MMP/2FMP'];
        ratios.forEach(function (v) {
            var o = document.createElement('option');
            o.value = v;
            o.textContent = v || 'ratio —';
            el('ratioSel').append(o);
        });
        ['', '7', '6', '5', '4'].forEach(function (v) {
            var o = document.createElement('option');
            o.value = v;
            o.textContent = v ? v + ' a side' : 'a side —';
            el('sizeSel').append(o);
        });

        function fail(e) { window.alert(e.message || 'That did not save.'); }

        el('possOff').addEventListener('click', function () {
            possess({ score: scoreKey(), defence: false }).catch(fail);
        });
        el('possDef').addEventListener('click', function () {
            possess({ score: scoreKey(), defence: true }).catch(fail);
        });
        el('possUndo').addEventListener('click', function () {
            possess({ score: scoreKey(), undo: true }).catch(fail);
        });
        el('stopBtn').addEventListener('click', function () {
            possess(poss.stoppage
                ? { stoppage: null }
                : { score: scoreKey(), stoppage: true }).catch(fail);
        });
        el('ratioSel').addEventListener('change', function () {
            possess({ ratio1: el('ratioSel').value }).catch(fail);
        });
        el('sizeSel').addEventListener('change', function () {
            possess({ size: el('sizeSel').value }).catch(fail);
        });

        // Timeouts go to the SCORE store, so they queue and retry like a goal
        // and reach the overlay on the same fast channel.
        el('toHome').addEventListener('click', function () { keeper.timeout(true); });
        el('toAway').addEventListener('click', function () { keeper.timeout(false); });
        el('toUndo').addEventListener('click', function () {
            var s = keeper.view();
            var last = (s.timeouts || [])[(s.timeouts || []).length - 1];
            if (last) { keeper.undoTimeout(Boolean(last.home)); }
        });

        var open = false;
        try { open = window.localStorage.getItem(MORE_KEY) === '1'; } catch (e) { open = false; }

        function show(on) {
            el('more').classList.toggle('hide', !on);
            el('moreBtn').textContent = on ? 'Less ▴' : 'More ▾';
            el('moreBtn').setAttribute('aria-expanded', on ? 'true' : 'false');
            try {
                if (on) { window.localStorage.setItem(MORE_KEY, '1'); }
                else { window.localStorage.removeItem(MORE_KEY); }
            } catch (e) { /* a private window still works, it just forgets */ }
            if (on) { readPossession(); }
        }
        show(open);
        el('moreBtn').addEventListener('click', function () {
            show(el('more').classList.contains('hide'));
        });
    }

    function paint() {
        var s = keeper.view();
        el('homeScore').textContent = s.home;
        el('awayScore').textContent = s.away;

        var st = el('state');
        if (!s.canWrite) {
            st.className = 'state bad';
            st.textContent = 'read only';
        } else if (s.pending > 0) {
            st.className = 'state pending';
            st.textContent = s.pending + ' unsent';
        } else if (s.error) {
            st.className = 'state bad';
            st.textContent = 'offline';
        } else {
            st.className = 'state ok';
            st.textContent = 'saved';
        }

        // A conflict outranks the state chip: the chip says "saved", which is
        // true and is not the thing that just happened.
        el('clash').classList.toggle('hide', !s.notice);
        if (s.notice) { el('clashText').textContent = s.notice; }

        el('startBtn').textContent = s.running ? 'Pause' : (s.timer_start ? 'Resume' : 'Start');
        // Nothing to reset, nothing to confirm.
        if (!s.timer_start && resetArmed) { disarmReset(); }
        el('halfBtn').textContent = s.half_at ? 'Half ✓' : 'Half';
        el('undoBtn').disabled = s.home + s.away === 0;
        [el('homeBtn'), el('awayBtn')].forEach(function (b) { b.disabled = !s.canWrite; });

        /**
         * Said whether or not this phone may write: a scorekeeper who cannot
         * yet write still wants to know which way the switch is set before they
         * start, and one who can wants to know the moment it changes.
         *
         * The wording asks somebody to fetch the operator, which is right for a
         * scorekeeper and wrong for the operator themselves — and on a
         * one-person rig, the person reading it is signed in and has no way to
         * act on their own instruction. So they get the switch instead.
         */
        /**
         * Dismissible, and it comes back.
         *
         * The banner exists to stop somebody keeping a whole game's score into
         * a store nothing reads, which is worth a warning — but there are
         * reasons to keep the board on upstream deliberately, and a notice that
         * cannot be put away is one somebody learns to read past, including on
         * the day it matters.
         *
         * So it hides per game on this device, and the dismissal is cleared the
         * moment the source changes: going on air and coming off again is a new
         * situation rather than the one that was waved away.
         */
        if (s.enabled === true) { rememberOffairDismissed(false); }
        var hushed = s.enabled === false && offairDismissed();

        el('offair').classList.toggle('hide', s.enabled !== false || hushed);
        el('offairDo').classList.toggle('hide', !s.admin);
        el('offairWhy').textContent = s.admin
            ? 'The overlay is still showing the score from upstream.'
            : 'The overlay is still showing the score from upstream. Ask the operator '
                + 'to switch the scoreboard to this game\'s match control.';

        el('setup').classList.toggle('hide', s.canWrite);
        ['teams', 'clockRow', 'bar', 'moreWrap'].forEach(function (id) {
            el(id).classList.toggle('hide', !s.canWrite);
        });
        paintMore();

        /**
         * Why this phone cannot keep score, in the three ways it can happen.
         *
         * The third one is the trap the offline flow walks into: a code typed
         * at a pitch is checked against the store, and with no signal there is
         * nothing to check it against, so the buttons stay disabled and the
         * screen used to repeat "enter the code" at somebody who just had. A
         * phone that was authorised once carries that with it (the client keeps
         * the server's last answer), which is exactly why the code has to be
         * entered while there is still signal — and why saying so here is worth
         * more than saying it in the documentation alone.
         */
        if (!s.canWrite) {
            if (CONFIG.demoCode) {
                /*
                 * A demonstration has no operator to ask and no crew to be
                 * given a code by. This is the surface a visitor most wants to
                 * press, so it publishes the code and fills it in — the score
                 * is invented data about a recorded game, and every press has
                 * an undo.
                 */
                el('setupWhy').textContent =
                    'Demonstration — the code is ' + CONFIG.demoCode + ', already filled '
                    + 'in. Press Use this code and keep score; the scoreboard follows. '
                    + 'At a real event an operator nominates this per game.';
                if (!el('code').value) { el('code').value = CONFIG.demoCode; }
            } else if (s.nominated === false) {
                el('setupWhy').textContent =
                    'No code has been set for this game yet. Sign in if you are running '
                    + 'this yourself, or ask the operator to set one.';
            } else if (s.error) {
                el('setupWhy').textContent =
                    'No signal, so a code cannot be checked here. This phone has to be '
                    + 'set up for this game once where there is signal — after that it '
                    + 'keeps score with no network at all.';
            } else {
                el('setupWhy').textContent =
                    'Enter the code the operator gave you for this game.';
            }

            /**
             * The other way in, for whoever is running the whole thing.
             *
             * An administrator may write any game without a code — the store
             * says so — and on a one-person rig the operator and the
             * scorekeeper are the same person, typing a code they nominated
             * themselves minutes earlier. Offered rather than assumed: it is a
             * link, and anybody who is not the operator cannot use it.
             */
            // Hidden only with no signal, where signing in cannot work either.
            /**
             * Back here afterwards, not to a page whose loudest button signs
             * you out. Hosted, the login belongs to Live! and ignores this.
             */
            var signin = el('signin');
            signin.href = CONFIG.loginUrl
                + (CONFIG.loginUrl.indexOf('?') === -1 ? '?' : '&')
                + 'next=' + encodeURIComponent(window.location.pathname);
            signin.classList.toggle('hide', Boolean(s.error));
            el('signinRow').classList.toggle('hide', Boolean(s.error));
        }
    }

    function tickClock() {
        var s = keeper.view();
        if (!s.timer_start) { el('clock').textContent = '--:--'; return; }
        var now = Math.floor(Date.now() / 1000);
        var elapsed = now - s.timer_start - s.timer_paused_duration;
        if (s.timer_pause_start > 0) { elapsed -= now - s.timer_pause_start; }
        elapsed = Math.max(0, elapsed);
        var m = Math.floor(elapsed / 60);
        var sec = elapsed % 60;
        el('clock').textContent = m + ':' + (sec < 10 ? '0' : '') + sec;
        el('clockNote').textContent = s.half_at ? 'past half' : '';
    }

    wireMore();
    keeper.onChange(paint);
    window.setInterval(tickClock, 500);

    /* ------------------------------------------------------------------
       The presses.
       ------------------------------------------------------------------ */

    el('homeBtn').addEventListener('click', function () { keeper.goal(true); });
    el('awayBtn').addEventListener('click', function () { keeper.goal(false); });

    el('undoBtn').addEventListener('click', function () {
        // Deliberately confirmed. Every other button here adds something a
        // second press would harmlessly repeat; this is the only one that
        // takes something away, and a phone in a pocket presses buttons.
        if (window.confirm('Take back the last point?')) { keeper.undo(); }
    });

    el('startBtn').addEventListener('click', function () {
        keeper.clock(keeper.view().running ? 'pause' : 'start');
    });
    el('halfBtn').addEventListener('click', function () { keeper.clock('half'); });

    el('useCode').addEventListener('click', function () {
        code = (el('code').value || '').toUpperCase().trim();
        try { window.localStorage.setItem(CODE_KEY, code); } catch (e) { /* fine */ }
        keeper.refresh();
    });
    el('code').value = code;

    /* ------------------------------------------------------------------
       Team names, once, from wherever the payload comes from. Cosmetic: the
       page works without them, which matters because this is the one screen
       that must survive the network being gone.
       ------------------------------------------------------------------ */

    /**
     * Note that this phone is keeping this game.
     *
     * Before the names are fetched, not after: a phone opening a game for the
     * first time with no signal still belongs on the list, and a row reading
     * "Game 702" is one somebody can use. The names are filled in the moment
     * they arrive, and kept for the next cold open.
     */
    if (window.ScoreArchive) {
        window.ScoreArchive.remember(null, CONFIG.gameId, {});

        /**
         * The names this phone already knows, before asking anybody.
         *
         * With no signal the payload never arrives, so the two big buttons said
         * "Home" and "Away" — on a phone that has the real names sitting in its
         * own index from the last time it opened this game. The fetch below
         * still runs and still wins; this is what the screen says until it
         * does, and all it says when it cannot.
         */
        var known = window.ScoreArchive.games().filter(function (g) {
            return g.id === Number(CONFIG.gameId);
        })[0];
        if (known && known.home && known.away) {
            el('homeName').textContent = known.home;
            el('awayName').textContent = known.away;
            el('fixture').textContent = known.home + ' v ' + known.away;
        }
    }

    if (window.Provider) {
        window.Provider.fromConfig({ apiBase: CONFIG.api, captureBase: CONFIG.captureBase })
            .game(CONFIG.gameId)
            .then(function (p) {
                var t = (p && p.teams) || {};
                var info = (p && p.game_info) || {};
                var h = (t.hometeam && t.hometeam.name) || 'Home';
                var a = (t.visitorteam && t.visitorteam.name) || 'Away';
                el('homeName').textContent = h;
                el('awayName').textContent = a;
                el('fixture').textContent = h + ' v ' + a;
                if (window.ScoreArchive) {
                    window.ScoreArchive.remember(null, CONFIG.gameId, {
                        home: h, away: a, name: info.gamename || '', at: info.time || ''
                    });
                }
            })
            .catch(function () { /* names are a luxury; the score is not */ });
    }

    var OFFAIR_KEY = 'uo-score-offair-' + CONFIG.gameId;

    function offairDismissed() {
        try { return window.localStorage.getItem(OFFAIR_KEY) === '1'; }
        catch (e) { return false; }
    }

    function rememberOffairDismissed(yes) {
        try {
            if (yes) { window.localStorage.setItem(OFFAIR_KEY, '1'); }
            else { window.localStorage.removeItem(OFFAIR_KEY); }
        } catch (e) { /* blocked; it stays visible, which is the safe way round */ }
    }

    el('offairHide').addEventListener('click', function () {
        rememberOffairDismissed(true);
        paint();
    });

    /**
     * Reset the clock, on the second press.
     *
     * A confirmation rather than a dialog: a modal on a phone at a pitch is
     * something to dismiss while a point is being played, and the two presses
     * are half a second apart for somebody who means it. It disarms itself
     * after a few seconds so a forgotten first press cannot be completed by an
     * unrelated one later.
     */
    var resetArmed = null;

    function disarmReset() {
        if (resetArmed) { clearTimeout(resetArmed); resetArmed = null; }
        el('clockReset').classList.remove('armed');
        el('clockReset').textContent = 'Reset clock';
    }

    el('clockReset').addEventListener('click', function () {
        if (!resetArmed) {
            el('clockReset').classList.add('armed');
            el('clockReset').textContent = 'Tap again to reset';
            resetArmed = setTimeout(disarmReset, 5000);

            return;
        }
        disarmReset();
        keeper.clock('reset');
    });

    el('clashOk').addEventListener('click', function () { keeper.clearNotice(); });

    el('offairDo').addEventListener('click', function () {
        el('offairDo').disabled = true;
        keeper.source(true)
            .catch(function (e) { alert(e.message); })
            .then(function () { el('offairDo').disabled = false; });
    });

    keeper.start();
    paint();
}());
</script>
</body>
</html>
