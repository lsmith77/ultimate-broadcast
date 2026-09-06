<?php

/**
 * Match control — the score and the clock, on a phone.
 *
 *   /m/702, or ?view=live/overlays/matchcontrol&game=702
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

use Overlays\Mode;
use Overlays\Score;

$gameId = filter_input(INPUT_GET, 'game', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

$base = rtrim(defined('UO_URL_PREFIX') ? UO_URL_PREFIX : '/', '/');

$assetUrl = static function (string $relative) use ($base): string {
    $relative = ltrim($relative, '/');
    $path = __DIR__ . '/' . $relative;
    $version = is_file($path) ? (string) filemtime($path) : '0';

    return Mode::assetBase($base) . '/' . $relative . '?v=' . $version;
};
$json = static fn ($v): string => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<!-- No zooming: a mis-pinch mid-game must not move the buttons under a thumb. -->
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="color-scheme" content="light dark">
<title>Match control</title>
<style>
    :root {
        --bg: #0d1420; --panel: #16202f; --line: #2a3a52; --ink: #f2f6fb;
        --ink-mute: #9fb0c6; --home: #2f6fdb; --away: #b8462f;
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
    header { display: flex; align-items: center; gap: .5rem; padding: .5rem .7rem;
        font-size: .8rem; color: var(--ink-mute); }
    header .grow { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis;
        white-space: nowrap; }
    .state { padding: .18rem .45rem; border-radius: 4px; font-weight: 700;
        font-size: .72rem; white-space: nowrap; }
    .state.ok { background: var(--ok); }
    .state.pending { background: var(--warn); }
    /* The advanced panel. Deliberately quieter than the score buttons: it is
       read and considered, not thumbed at speed. */
    .more { padding: 0 .6rem .8rem; }
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

    /* The two presses. Everything else on the page is smaller than these. */
    .teams { flex: 1; display: grid; grid-template-columns: 1fr 1fr; gap: .5rem;
        padding: 0 .5rem; min-height: 0; }
    .team { border: 0; border-radius: 12px; color: #fff; font: inherit;
        display: flex; flex-direction: column; align-items: center;
        justify-content: center; gap: .3rem; padding: .5rem; cursor: pointer;
        min-height: 0; }
    .team.home { background: var(--home); }
    .team.away { background: var(--away); }
    .team:disabled { opacity: .45; }
    .team .name { font-size: .95rem; font-weight: 700; text-align: center;
        overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2;
        -webkit-box-orient: vertical; }
    .team .n { font-size: clamp(3rem, 22vw, 7rem); font-weight: 800;
        line-height: 1; font-variant-numeric: tabular-nums; }
    .team:active { filter: brightness(1.18); }

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
    <span class="grow" id="fixture">Match control</span>
    <span class="state" id="state">…</span>
</header>

<div class="offair hide" id="offair">
    Not on the scoreboard
    <span id="offairWhy">The overlay is still showing the score from upstream. Ask the
        operator to switch the scoreboard to this game's match control.</span>
</div>

<div class="setup hide" id="setup">
    <p id="setupWhy">Enter the code the operator gave you for this game.</p>
    <input id="code" inputmode="latin" autocapitalize="characters" autocomplete="off"
        maxlength="<?= (int) Score::CODE_LENGTH ?>" aria-label="Scorekeeping code">
    <button class="bar" id="useCode" type="button" style="padding:.85rem;font-weight:700;border-radius:10px;border:1px solid var(--line);background:var(--panel);color:var(--ink)">Use this code</button>
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
        possessionUrl: <?= $json(Mode::viewUrl('possession', $base)) ?>
    };

    var el = function (id) { return document.getElementById(id); };
    var CODE_KEY = 'uo-score-code-' + CONFIG.gameId;

    if (!CONFIG.gameId) {
        el('fixture').textContent = 'Add ?game= to the URL.';
        return;
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
        ['stopBtn', 'ratioSel', 'sizeSel', 'toHome', 'toAway'].forEach(function (id) {
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

        el('startBtn').textContent = s.running ? 'Pause' : (s.timer_start ? 'Resume' : 'Start');
        el('halfBtn').textContent = s.half_at ? 'Half ✓' : 'Half';
        el('undoBtn').disabled = s.home + s.away === 0;
        [el('homeBtn'), el('awayBtn')].forEach(function (b) { b.disabled = !s.canWrite; });

        // Said whether or not this phone may write: a scorekeeper who cannot
        // yet write still wants to know which way the switch is set before
        // they start, and one who can wants to know the moment it changes.
        el('offair').classList.toggle('hide', s.enabled !== false);

        el('setup').classList.toggle('hide', s.canWrite);
        ['teams', 'clockRow', 'bar', 'moreWrap'].forEach(function (id) {
            el(id).classList.toggle('hide', !s.canWrite);
        });
        paintMore();
        if (!s.canWrite && s.nominated === false) {
            el('setupWhy').textContent =
                'No code has been set for this game yet. Ask the operator to set one.';
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

    if (window.Provider) {
        window.Provider.fromConfig({ apiBase: CONFIG.api, captureBase: CONFIG.captureBase })
            .game(CONFIG.gameId)
            .then(function (p) {
                var t = (p && p.teams) || {};
                var h = (t.hometeam && t.hometeam.name) || 'Home';
                var a = (t.visitorteam && t.visitorteam.name) || 'Away';
                el('homeName').textContent = h;
                el('awayName').textContent = a;
                el('fixture').textContent = h + ' v ' + a;
            })
            .catch(function () { /* names are a luxury; the score is not */ });
    }

    keeper.start();
    paint();
}());
</script>
</body>
</html>
