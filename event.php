<?php

/**
 * The event editor — teams and games, in a browser.
 *
 *   ?view=event      (also /s/event)
 *
 * **Standalone only, and 404 under a host**, like `login.php` and `roster.php`
 * and for the same reason: hosted, an event belongs to UltiOrganizer. It is
 * scheduled there, its teams are registered there, and a second copy authored
 * here would disagree with it silently on the surface that reaches air.
 *
 * WHY A PAGE AS WELL AS A SCRIPT
 *
 * `install/make-event.php` came first and does the same job from a shell. It is
 * the wrong tool for the person this mode is actually for: somebody running a
 * club's stream, who has a browser and a laptop and no reason to have SSH into
 * anything. Both surfaces call `Overlays\Event`, so the payload shape — the one
 * thing in this project that must not exist twice — exists once.
 *
 * WHAT IT EDITS, AND WHAT IT POINTEDLY DOES NOT
 *
 * The part nobody touches once the day starts: the event's name, the teams'
 * names, the games and their fields. Not the **squads**, which arrive at the
 * commentary desk from the team's own sheet (`shared/roster.php`), and not the
 * **score or the clock**, which are match control's (`shared/score.php`).
 *
 * That division is not tidiness. Those two are written *during* a game by
 * somebody else on another device, and an operator fixing a misspelt team name
 * at half time must not be able to overwrite either. So this page does not hold
 * them and cannot lose them: a save rebuilds the capture, and the capture never
 * contained a squad or a score in the first place.
 */

if (!defined('UO_ROUTED_VIEW')) {
    http_response_code(404);

    exit;
}

require_once __DIR__ . '/shared/auth.php';
require_once __DIR__ . '/shared/mode.php';
require_once __DIR__ . '/shared/event.php';

use Overlays\Auth;
use Overlays\Event;
use Overlays\Mode;

if (Auth::isHosted()) {
    http_response_code(404);

    exit;
}

$isAdmin = Auth::isAdmin();
$root = __DIR__;

/**
 * Saving.
 *
 * Answered as JSON to the page's own fetch rather than as a form post, because
 * the whole event is one document: a form field per game would mean deciding
 * what a half-submitted schedule means, and the answer is that there is no such
 * thing.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, must-revalidate');

    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['error' => 'Sign in at ' . Mode::loginUrl() . ' to edit the event.']);

        exit;
    }

    $raw = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($raw)) {
        http_response_code(400);
        echo json_encode(['error' => 'Expected a JSON object.']);

        exit;
    }

    // Every problem, not the first one. Somebody who has just typed a schedule
    // wants to fix it in one pass, and the same list the CLI prints.
    $checked = Event::validate($raw);
    if ($checked['problems'] !== []) {
        http_response_code(400);
        echo json_encode(['problems' => $checked['problems']]);

        exit;
    }
    $spec = $checked['spec'];

    $out = 'events/' . Event::slug($spec);
    $built = Event::build($spec, $root . '/' . $out);
    if (!$built['ok']) {
        http_response_code(500);
        echo json_encode(['error' => (string) ($built['error'] ?? 'Could not write the capture.')]);

        exit;
    }
    if (!Event::save($spec)) {
        http_response_code(500);
        echo json_encode(['error' => 'The capture was written but conf/event.json was not.']);

        exit;
    }

    /**
     * Point the installation at what was just written.
     *
     * Done here rather than left as a step, because an editor that saves an
     * event the installation is not serving is an editor that appears not to
     * work. Only the one key is touched; the password hash and everything else
     * is carried through.
     */
    $configPath = $root . '/conf/local-config.php';
    $pointed = false;
    if (is_file($configPath)) {
        $config = require $configPath;
        if (is_array($config) && ($config['capture'] ?? null) !== $out) {
            $config['capture'] = $out;
            $pointed = Mode::saveLocalConfig($config);
        }
    }

    echo json_encode([
        'ok' => true,
        'capture' => $out,
        'files' => count($built['files']),
        'pointed' => $pointed,
        'event' => Event::forStorage($spec),
    ], JSON_UNESCAPED_SLASHES);

    exit;
}

$stored = Event::load();
$writable = is_writable($root . '/conf');
$assetBase = Mode::assetBase();
$version = @filemtime(__FILE__) ?: time();
$json = static fn ($v): string => json_encode(
    $v,
    JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
);
$e = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Event — overlays</title>
<style>
    :root { color-scheme: dark; --bg: #0b1220; --panel: #0f1a30; --line: #1e293b;
            --ink: #e2e8f0; --mute: #94a3b8; --accent: #1d4ed8; --bad: #f87171;
            --ok: #4ade80; }
    * { box-sizing: border-box; }
    body { margin: 0; padding: 1.5rem; background: var(--bg); color: var(--ink);
           font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
    main { max-width: 1000px; margin: 0 auto; }
    h1 { font-size: 1.3rem; margin: 0 0 .2rem; }
    h2 { font-size: .95rem; margin: 1.6rem 0 .6rem; text-transform: uppercase;
         letter-spacing: .06em; color: var(--mute); }
    p.sub { margin: 0 0 1rem; color: var(--mute); font-size: .88rem; }
    fieldset { border: 1px solid var(--line); border-radius: 6px; padding: .9rem 1rem;
               margin: 0 0 .8rem; background: var(--panel); }
    legend { padding: 0 .4rem; font-size: .78rem; text-transform: uppercase;
             letter-spacing: .06em; color: var(--mute); }
    .grid { display: grid; gap: .6rem 1rem;
            grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); }
    label { display: block; font-size: .74rem; text-transform: uppercase;
            letter-spacing: .05em; color: var(--mute); margin-bottom: .2rem; }
    input, select { width: 100%; font: inherit; font-size: .9rem; padding: .4rem .5rem;
                    background: var(--bg); color: var(--ink);
                    border: 1px solid var(--line); border-radius: 4px; }
    input:disabled, select:disabled { opacity: .5; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-size: .72rem; text-transform: uppercase;
         letter-spacing: .05em; color: var(--mute); padding: .3rem .4rem;
         font-weight: 600; }
    td { padding: .25rem .4rem; vertical-align: middle; }
    td.id { color: var(--mute); font-size: .8rem; width: 2.5rem; }
    button { font: inherit; font-size: .85rem; padding: .4rem .9rem; border-radius: 4px;
             border: 1px solid var(--line); background: var(--panel); color: var(--ink);
             cursor: pointer; }
    button:hover:not(:disabled) { border-color: #475569; }
    button:disabled { opacity: .45; cursor: default; }
    button.primary { background: var(--accent); border-color: var(--accent); color: #fff;
                     font-weight: 600; }
    button.drop { border: 0; background: none; color: var(--mute); padding: .2rem .4rem; }
    button.drop:hover:not(:disabled) { color: var(--bad); }
    .bar { display: flex; gap: .6rem; align-items: center; flex-wrap: wrap;
           margin-top: 1.4rem; padding-top: 1rem; border-top: 1px solid var(--line); }
    .msg { margin: 1rem 0 0; padding: .6rem .8rem; border-radius: 4px; font-size: .88rem;
           border-left: 3px solid; }
    .msg.bad { background: #2a1414; border-color: var(--bad); color: #fecaca; }
    .msg.good { background: #10231c; border-color: var(--ok); color: #bbf7d0; }
    .msg ul { margin: .4rem 0 0; padding-left: 1.1rem; }
    .note { color: var(--mute); font-size: .84rem; margin-top: .5rem; }
    a { color: #93c5fd; }
</style>
</head>
<body>
<main>
    <h1>Event</h1>
    <p class="sub">
        The part that does not change once the day starts. Squads arrive at the
        <a href="<?= $e(Mode::viewUrl('commentator')) ?>">commentary desk</a>; the
        score and clock are match control's.
    </p>

    <?php if (!$isAdmin) : ?>
        <p class="msg bad">
            Read-only — <a href="<?= $e(Mode::loginUrl()) ?>">sign in</a> to edit the event.
        </p>
    <?php elseif (!$writable) : ?>
        <p class="msg bad">
            <code>conf/</code> is not writable by the web server, so nothing can be saved.
        </p>
    <?php endif; ?>

    <div id="form"></div>

    <div class="bar">
        <button type="button" class="primary" id="save"
            <?= $isAdmin && $writable ? '' : 'disabled' ?>>Save event</button>
        <a href="<?= $e(Mode::viewUrl('index')) ?>">Back to the Studio</a>
    </div>

    <!--
      The save outcome. A live region, because it is the answer to something the
      reader just asked for and it appears below the button they pressed — off
      screen for anybody who cannot see the whole form at once.
    -->
    <div id="msg" role="status" aria-live="polite"></div>
</main>

<script>
(function () {
    'use strict';

    var CAN_EDIT = <?= $isAdmin && $writable ? 'true' : 'false' ?>;

    /**
     * The event being edited.
     *
     * Either what is stored, or a starting point with two teams and one game in
     * it. An empty form is a worse blank page than a filled one: the shape of
     * what is wanted is most of the instruction.
     */
    var event = <?= $json($stored ?: [
        'event' => '',
        'season' => '',
        'place' => '',
        'type' => 'open',
        'organizer' => '',
        'logo' => '',
        'pool' => ['name' => 'Pool A', 'winningscore' => 15, 'halftime' => 8,
            'timecap' => null, 'timeouts' => 2, 'timeoutsper' => 'half'],
        'teams' => [['id' => 1, 'name' => '', 'short' => ''],
            ['id' => 2, 'name' => '', 'short' => '']],
        'games' => [['id' => 1, 'home' => 1, 'visitor' => 2, 'field' => '1',
            'time' => '', 'name' => '', 'status' => 'scheduled']],
    ]) ?>;

    event.pool = event.pool || {};
    event.teams = event.teams || [];
    event.games = event.games || [];

    var form = document.getElementById('form');
    var msg = document.getElementById('msg');

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = text; }
        return n;
    }

    /**
     * A labelled input bound to one key of one object.
     *
     * `label` is the visible one, for the stacked fieldsets. In a TABLE the
     * column heading is the label, so passing an empty string used to render an
     * empty `<label for=…>` — which is worse than no label at all: the control
     * ends up with no accessible name, and a screen reader announces "edit
     * text, blank" for every cell in the schedule. Found by walking the pages
     * looking for controls with no name.
     *
     * So an empty label renders no element and the name comes from `opts.name`,
     * which every table caller supplies.
     */
    function field(label, obj, key, opts) {
        opts = opts || {};
        var wrap = el('div');
        var id = 'f-' + Math.random().toString(36).slice(2, 9);
        if (label) {
            var lab = el('label', null, label);
            lab.setAttribute('for', id);
            wrap.append(lab);
        }

        var toValue = opts.asInput || function (v) { return v; };
        var fromValue = opts.fromInput || function (v) { return v; };

        var input;
        if (opts.options) {
            input = document.createElement('select');
            opts.options.forEach(function (o) {
                var option = document.createElement('option');
                option.value = o.value;
                option.textContent = o.label;
                input.append(option);
            });
            input.value = obj[key] === null || obj[key] === undefined ? '' : String(obj[key]);
        } else {
            input = document.createElement('input');
            input.type = opts.type || 'text';
            input.value = obj[key] === null || obj[key] === undefined
                ? '' : toValue(String(obj[key]));
            if (opts.placeholder) { input.placeholder = opts.placeholder; }
        }
        input.id = id;
        input.disabled = !CAN_EDIT;
        if (!label) { input.setAttribute('aria-label', opts.name || key); }
        if (opts.title) { input.title = opts.title; }
        function absorb() {
            obj[key] = fromValue(input.value);
            if (opts.onChange) { opts.onChange(); }
        }
        input.addEventListener('input', absorb);
        input.addEventListener('change', absorb);
        wrap.append(input);

        return wrap;
    }

    function toLocal(stored) {
        return stored ? stored.replace(' ', 'T').slice(0, 16) : '';
    }

    function fromLocal(entered) {
        return entered ? entered.replace('T', ' ') : '';
    }

    /** The next free id in a list, so an added row never reuses a departed one. */
    function nextId(list) {
        var max = 0;
        list.forEach(function (row) {
            var n = Number(row.id);
            if (isFinite(n) && n > max) { max = n; }
        });
        return max + 1;
    }

    function render() {
        form.replaceChildren();

        // -- the event itself --
        var about = el('fieldset');
        about.append(el('legend', null, 'Event'));
        var g1 = el('div', 'grid');
        g1.append(field('Name', event, 'event', { placeholder: 'Spring Showcase' }));
        g1.append(field('Season id', event, 'season', {
            placeholder: 'from the name',
            title: 'Appears in URLs and filenames. A-Z, 0-9, _ and - only.'
        }));
        g1.append(field('Place', event, 'place', { placeholder: 'Riverside Fields' }));
        g1.append(field('Division', event, 'type', {
            options: ['open', 'women', 'mixed', 'masters'].map(function (v) {
                return { value: v, label: v };
            })
        }));
        g1.append(field('Organiser', event, 'organizer'));
        g1.append(field('Logo URL', event, 'logo', {
            title: 'Shown on the stage. Leave empty for none — a recorded capture '
                + 'points at Live!’s own path, which is why this is here.'
        }));
        about.append(g1);
        form.append(about);

        // -- the pool, which is where every rule of the game lives --
        var pool = el('fieldset');
        pool.append(el('legend', null, 'Rules'));
        var g2 = el('div', 'grid');
        g2.append(field('Pool name', event.pool, 'name'));
        g2.append(field('Game to', event.pool, 'winningscore', { type: 'number' }));
        g2.append(field('Half at (score)', event.pool, 'halftime', {
            type: 'number',
            title: 'A SCORE, not a duration — the point the break falls at. '
                + 'Live! spells it the same way and it catches people out.'
        }));
        g2.append(field('Time cap (min)', event.pool, 'timecap', {
            type: 'number',
            title: 'Set it and the clock counts DOWN to it and the cap states '
                + 'appear. Empty and the clock counts up.'
        }));
        g2.append(field('Timeouts', event.pool, 'timeouts', { type: 'number' }));
        g2.append(field('Counted per', event.pool, 'timeoutsper', {
            options: [{ value: 'half', label: 'half' }, { value: 'game', label: 'game' }]
        }));
        pool.append(g2);
        form.append(pool);

        // -- teams --
        var teams = el('fieldset');
        teams.append(el('legend', null, 'Teams'));
        var tt = document.createElement('table');
        var thead = document.createElement('tr');
        ['#', 'Name', 'Short', ''].forEach(function (h) {
            var th = el('th', null, h);
            th.setAttribute('scope', 'col');
            thead.append(th);
        });
        tt.append(thead);
        event.teams.forEach(function (team) {
            var tr = document.createElement('tr');
            tr.append(el('td', 'id', String(team.id)));
            var name = document.createElement('td');
            name.append(field('', team, 'name', {
                placeholder: 'Team name', name: 'Team ' + team.id + ' name'
            }));
            tr.append(name);
            var short = document.createElement('td');
            short.append(field('', team, 'short', {
                placeholder: 'auto',
                name: 'Team ' + team.id + ' short name',
                title: 'Used only where there is no room for the full name.'
            }));
            tr.append(short);
            var act = document.createElement('td');
            var drop = el('button', 'drop', '×');
            drop.type = 'button';
            drop.disabled = !CAN_EDIT;
            // Named for the row it acts on. A column of buttons all called "×"
            // is a list of identical controls to anybody not looking at it.
            drop.setAttribute('aria-label', 'Remove ' + (team.name || ('team ' + team.id)));
            drop.title = 'Remove this team. Their squad and their prepared notes '
                + 'stay where they are, filed under this id.';
            drop.addEventListener('click', function () {
                event.teams = event.teams.filter(function (t) { return t !== team; });
                // A game that has lost a side is not a game. Dropped rather than
                // left half-defined, because a schedule with a blank in it is
                // the sort of thing nobody notices until the morning.
                event.games = event.games.filter(function (g) {
                    return String(g.home) !== String(team.id)
                        && String(g.visitor) !== String(team.id);
                });
                render();
            });
            act.append(drop);
            tr.append(act);
            tt.append(tr);
        });
        teams.append(tt);
        var addTeam = el('button', null, '+ Team');
        addTeam.type = 'button';
        addTeam.disabled = !CAN_EDIT;
        addTeam.style.marginTop = '.5rem';
        addTeam.addEventListener('click', function () {
            event.teams.push({ id: nextId(event.teams), name: '', short: '' });
            render();
        });
        teams.append(addTeam);
        teams.append(el('p', 'note', 'Squads are not here. Add players at the '
            + 'commentary desk — by hand, or from the team’s own sheet.'));
        form.append(teams);

        // -- games --
        var games = el('fieldset');
        games.append(el('legend', null, 'Games'));
        var gt = document.createElement('table');
        var ghead = document.createElement('tr');
        ['#', 'Name', 'Home', 'Away', 'Field', 'Time', 'Status', ''].forEach(function (h) {
            var th = el('th', null, h);
            th.setAttribute('scope', 'col');
            ghead.append(th);
        });
        gt.append(ghead);

        var teamOptions = event.teams.map(function (t) {
            return { value: String(t.id), label: t.name || ('Team ' + t.id) };
        });

        event.games.forEach(function (game) {
            var tr = document.createElement('tr');
            tr.append(el('td', 'id', String(game.id)));
            var per = 'Game ' + game.id + ' ';
            [
                ['name', { placeholder: 'Semi-final', name: per + 'name' }],
                ['home', { options: teamOptions, name: per + 'home team' }],
                ['visitor', { options: teamOptions, name: per + 'away team' }],
                ['field', { placeholder: '1', name: per + 'field' }],
                // The store keeps "2027-05-01 10:00:00"; the input wants
                // "2027-05-01T10:00". Converted here rather than in the store,
                // because the stored spelling is the one the payload uses and
                // the browser's is a detail of this one widget.
                ['time', { type: 'datetime-local', name: per + 'start time',
                    asInput: toLocal, fromInput: fromLocal }],
                ['status', { name: per + 'status',
                    options: ['scheduled', 'ongoing', 'completed'].map(function (v) {
                        return { value: v, label: v };
                    }) }]
            ].forEach(function (spec) {
                var td = document.createElement('td');
                td.append(field('', game, spec[0], spec[1]));
                tr.append(td);
            });
            var act = document.createElement('td');
            var drop = el('button', 'drop', '×');
            drop.type = 'button';
            drop.disabled = !CAN_EDIT;
            drop.setAttribute('aria-label', 'Remove ' + (game.name || ('game ' + game.id)));
            drop.title = 'Remove this game. Any score kept for it stays on the '
                + 'server, filed under this id.';
            drop.addEventListener('click', function () {
                event.games = event.games.filter(function (g) { return g !== game; });
                render();
            });
            act.append(drop);
            tr.append(act);
            gt.append(tr);
        });
        games.append(gt);
        var addGame = el('button', null, '+ Game');
        addGame.type = 'button';
        addGame.disabled = !CAN_EDIT || event.teams.length < 2;
        addGame.style.marginTop = '.5rem';
        addGame.addEventListener('click', function () {
            event.games.push({
                id: nextId(event.games),
                home: event.teams[0] ? event.teams[0].id : null,
                visitor: event.teams[1] ? event.teams[1].id : null,
                field: '1', time: '', name: '', status: 'scheduled'
            });
            render();
        });
        games.append(addGame);
        form.append(games);
    }

    function say(kind, text, list) {
        msg.replaceChildren();
        var box = el('p', 'msg ' + kind, text);
        if (list && list.length) {
            var ul = document.createElement('ul');
            list.forEach(function (item) { ul.append(el('li', null, item)); });
            box.append(ul);
        }
        msg.append(box);
    }

    document.getElementById('save').addEventListener('click', function () {
        var button = this;
        button.disabled = true;
        button.textContent = 'Saving…';

        fetch(window.location.href, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(event)
        })
            .then(function (r) {
                return r.json().then(function (body) { return { r: r, body: body }; });
            })
            .then(function (res) {
                if (res.body.problems) {
                    say('bad', 'Not saved:', res.body.problems);
                    return;
                }
                if (!res.r.ok) {
                    say('bad', res.body.error || ('HTTP ' + res.r.status));
                    return;
                }
                // The ids the server settled on, so a row added here stops being
                // a new row and the next save edits it rather than adding again.
                event.teams = res.body.event.teams;
                event.games = res.body.event.games;
                render();
                say('good', 'Saved to ' + res.body.capture + '. '
                    + (res.body.pointed
                        ? 'This installation now serves it.'
                        : 'This installation was already serving it.'));
            })
            .catch(function (e) { say('bad', e.message || 'Could not save.'); })
            .then(function () {
                button.disabled = !CAN_EDIT;
                button.textContent = 'Save event';
            });
    });

    render();
}());
</script>
</body>
</html>
