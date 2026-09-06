<?php

/**
 * Who runs this installation, and what it stores about people.
 *
 *   ?view=imprint      (also /s/imprint)
 *
 * **Standalone only, and 404 under a host**, like `login.php`, `roster.php` and
 * `event.php`. An UltiOrganizer installation has its own imprint and its own
 * privacy statement, and a second one here would be a second answer to the same
 * legal question — which is worse than none.
 *
 * WHY THIS EXISTS AT ALL
 *
 * A publicly reachable site run from Switzerland, Germany or Austria has to say
 * who is behind it. That is the half this file cannot write: it is somebody's
 * real name and address, it belongs to the person who put the installation up,
 * and inventing it would be worse than leaving it blank. So it comes from
 * `conf/local-config.php` and the page says plainly when it has not been set.
 *
 * The other half this file CAN write, and it is the half that actually matters
 * here: **what this software stores about named people, where, and for how
 * long.** That is a fact about the code rather than about the operator, it is
 * the same on every installation, and getting it wrong is how a privacy notice
 * becomes a lie. It is read off the stores rather than remembered — the
 * constants below are the ones the code enforces.
 *
 * It is deliberately not legal advice and does not pretend to be a complete
 * privacy statement. It is an accurate inventory, which is the part a person
 * writing one actually needs and cannot get from anywhere else.
 */

if (!defined('UO_ROUTED_VIEW')) {
    http_response_code(404);

    exit;
}

require_once __DIR__ . '/shared/auth.php';
require_once __DIR__ . '/shared/mode.php';
require_once __DIR__ . '/shared/notes.php';

use Overlays\Auth;
use Overlays\Mode;
use Overlays\Notes;

if (Auth::isHosted()) {
    http_response_code(404);

    exit;
}

$imprint = Mode::imprint();
$e = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/** Days, from the constant the store actually prunes by. */
$noteDays = (int) round(Notes::STALE_SECONDS / 86400);

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Imprint — overlays</title>
<style>
    :root { color-scheme: dark; }
    body { margin: 0; padding: 2rem 1.5rem; background: #0b1220; color: #e2e8f0;
           font: 15px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; }
    main { max-width: 44rem; margin: 0 auto; }
    h1 { font-size: 1.4rem; margin: 0 0 1.4rem; }
    h2 { font-size: 1rem; margin: 2rem 0 .5rem; }
    p, li { color: #cbd5e1; }
    dl { margin: 0; }
    dt { font-size: .74rem; text-transform: uppercase; letter-spacing: .05em;
         color: #94a3b8; margin-top: .8rem; }
    dd { margin: .1rem 0 0; }
    .missing { background: #2a1414; border-left: 3px solid #f87171; color: #fecaca;
               padding: .7rem .9rem; border-radius: 4px; }
    table { width: 100%; border-collapse: collapse; margin-top: .5rem; }
    th { text-align: left; font-size: .74rem; text-transform: uppercase;
         letter-spacing: .05em; color: #94a3b8; padding: .3rem .5rem .3rem 0; }
    td { padding: .35rem .5rem .35rem 0; border-top: 1px solid #1e293b;
         vertical-align: top; font-size: .92rem; }
    code { background: #0f1a30; padding: .05rem .3rem; border-radius: 3px;
           font-size: .88em; }
    a { color: #93c5fd; }
    footer { margin-top: 2.5rem; padding-top: 1rem; border-top: 1px solid #1e293b;
             font-size: .88rem; }
</style>
</head>
<body>
<main>
    <h1>Imprint</h1>

    <?php if ($imprint === []) : ?>
        <p class="missing">
            Nobody has been named as responsible for this installation. Whoever runs it
            should add an <code>imprint</code> block to <code>conf/local-config.php</code>
            — see <code>docs/DEPLOY.md</code>.
        </p>
    <?php else : ?>
        <dl>
            <?php foreach ($imprint as $label => $value) : ?>
                <dt><?= $e((string) $label) ?></dt>
                <dd><?php
                    // An email or a URL is worth being clickable; everything
                    // else is printed as given, including line breaks in an
                    // address.
                    $value = (string) $value;
                    if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        echo '<a href="mailto:' . $e($value) . '">' . $e($value) . '</a>';
                    } elseif (preg_match('#^https?://#', $value) === 1) {
                        echo '<a href="' . $e($value) . '" rel="noopener">' . $e($value) . '</a>';
                    } else {
                        echo nl2br($e($value));
                    }
                ?></dd>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>

    <h2>What this installation stores</h2>

    <p>
        This is an inventory of what the software keeps, which is the same on every
        installation because it is a property of the code. It is not a privacy statement
        and it is not legal advice; it is the list somebody writing one would otherwise
        have to read the source for.
    </p>

    <p>
        <strong>No accounts, no tracking, no analytics, no third-party requests.</strong>
        There is one administrator password, and it is stored only as a bcrypt hash.
        Everything else below is operational data about a game.
    </p>

    <table>
        <tr><th scope="col">What</th><th scope="col">Where</th><th scope="col">Kept</th></tr>
        <tr>
            <td><strong>Prepared notes about players</strong> — free text, and where a
                player has filled in their own sheet, a nickname, pronouns and a name
                pronunciation. The only place here that holds one person's words about
                another.</td>
            <td><code>conf/notes/</code>, on this server. Never served over HTTP; read
                only through the commentary desk, which needs the room code.</td>
            <td>Deleted <?= $noteDays ?> days after the last write to that room.</td>
        </tr>
        <tr>
            <td><strong>Squads</strong> — a shirt number and a name per player, when this
                installation keeps its own rather than reading a tournament's.</td>
            <td><code>conf/roster-&lt;team&gt;.json</code>. Readable by anyone who can
                open the site, like the team sheet at a pitch.</td>
            <td>Until an operator removes the player or the event.</td>
        </tr>
        <tr>
            <td><strong>Score, clock, possession and line selections</strong> — about a
                game, naming players only in a line.</td>
            <td><code>conf/</code>, per game.</td>
            <td>Line rooms are pruned; the rest until an operator clears it.</td>
        </tr>
        <tr>
            <td><strong>Your session</strong>, if you sign in as the administrator.</td>
            <td>A cookie holding an identifier, and a session file on this server.</td>
            <td>Until the browser is closed, or you sign out.</td>
        </tr>
        <tr>
            <td><strong>A dismissed introduction</strong>, and a scorekeeper's code on
                their own phone.</td>
            <td>Your browser's local storage. Never sent anywhere.</td>
            <td>Until you clear your browser's data.</td>
        </tr>
    </table>

    <p>
        The web server will also be keeping its own access log, which is the host's
        rather than this software's.
    </p>

    <h2>The software</h2>
    <p>
        Open source, and the questions above can be checked against it:
        <a href="https://github.com/lsmith77/ultimate-broadcast" rel="noopener">github.com/lsmith77/ultimate-broadcast</a>.
        It extends <a href="https://github.com/layoutd/live-by-bula" rel="noopener">Live! by
        BULA</a>, which is separate software under its own terms and is not running here.
    </p>

    <footer>
        <a href="<?= $e(Mode::viewUrl('index')) ?>">Back to the Studio</a>
    </footer>
</main>
</body>
</html>
