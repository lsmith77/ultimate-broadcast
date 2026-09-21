<?php

/**
 * The spotter - per-throw capture, by voice, from the sideline.
 *
 *   ?view=spotter, or /p/<game>
 *
 * WHAT IT IS FOR
 *
 * Everything upstream records the score. Nothing records what happened: who
 * threw to whom, what was called, how long the disc was live. A spotter with
 * a microphone can, at about two words a call, and `docs/MATCHCONTROL.md`
 * SS10a is the reasoning about coverage that makes such data safe to publish.
 *
 * WHY VOICE AND WHY IN THE PAGE
 *
 * Hands are busy and eyes are on the field, so speech is the only input that
 * does not cost attention. Recognition runs in the browser through a WASM
 * build of Vosk, which takes the call grammar as a DECODING CONSTRAINT rather
 * than a filter afterwards - so the vocabulary is closed and the engine
 * cannot return a word that is not a legal call. Nothing is sent anywhere.
 *
 * WHY IT DOES NOT INVENT
 *
 * A grammar-constrained recogniser always returns something legal, so
 * legality is no evidence. The sport is: a pull cannot happen while the disc
 * is live, a team cannot catch its own pull, a pass between opponents is not
 * a pass. Anything impossible is recorded as a QUESTION rather than an
 * observation, and the queue is settled at the next stoppage.
 *
 * THE MODEL IS NOT SHIPPED
 *
 * Kaldi needs about 40MB of acoustic model, which does not belong in a
 * release archive. `spotter/get-model.sh` fetches it into `spotter/`, and
 * without it the page still runs on typed calls and says which engine it has.
 */

if (!defined('UO_ROUTED_VIEW')) {
    http_response_code(404);
    exit;
}

if (is_file(__DIR__ . '/../conf/LocalConfig.php')) {
    require_once __DIR__ . '/../conf/LocalConfig.php';
}
require_once __DIR__ . '/shared/mode.php';
require_once __DIR__ . '/shared/brand.php';
require_once __DIR__ . '/shared/auth.php';

use Overlays\Brand;
use Overlays\Mode;

$gameId = filter_input(INPUT_GET, 'game', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$base = rtrim(defined('UO_URL_PREFIX') ? UO_URL_PREFIX : '/', '/');
$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$assetUrl = static function (string $relative) use ($base): string {
    $relative = ltrim($relative, '/');
    $path = __DIR__ . '/' . $relative;
    $version = is_file($path) ? (string) filemtime($path) : '0';

    return Mode::assetBase($base) . '/' . $relative . '?v=' . $version;
};

/** Present only when somebody has run `spotter/get-model.sh`. */
$json = static fn ($v): string => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

/*
 * A reference game pack, if this installation has one.
 *
 * Not in the repository: a real pack holds a real squad, and the rule here
 * is invented people in anything committed. `spotter/games.example.json`
 * documents the shape; the pack itself is handed to a spotter with the rest
 * of the briefing.
 */
$gamePack = null;
if (is_file(__DIR__ . '/spotter/games.json')) {
    $raw = json_decode((string) file_get_contents(__DIR__ . '/spotter/games.json'), true);
    if (is_array($raw) && !empty($raw['games'])) { $gamePack = $raw['games']; }
}

$hasModel = is_file(__DIR__ . '/spotter/vosk.js') && is_file(__DIR__ . '/spotter/model.tar.gz');

/*
 * Where the model is, put on the document rather than into the script.
 *
 * The library resolves a relative URL inside a blob: worker, whose base is
 * the ORIGIN ROOT rather than this page - so a relative path works only when
 * the page happens to sit at the root, and 404s everywhere else without ever
 * rejecting. It is spelled out absolutely for that reason.
 */

?>
<!DOCTYPE html>
<html lang="en" data-theme="night">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?= Brand::head('spot', $base) ?>
<title>Spotter</title>
<link rel="stylesheet" href="<?= $e($assetUrl('shared/matching.css')) ?>">
<style>
    :root {
        --bg: #0f1a30; --panel: #16203a; --line: #26324e; --ink: #e8eefb;
        --mute: #9fb0c6; --ok: #3fb984; --warn: #e0a33c; --bad: #d4574e;
    }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg); color: var(--ink); padding: 1rem;
        font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
    /*
     * ONE COLUMN FIRST, BECAUSE THE PHONE IS THE REAL DEVICE.
     *
     * A spotter on a sideline has a phone, and possibly has it in a pocket
     * with a lav mic. A tablet or a laptop is the comfortable case, not the
     * assumed one, so extra width ADDS columns rather than the narrow case
     * subtracting them.
     *
     *   phone    one column; the actions sit at the bottom of the screen
     *            where a thumb is, not at the top where a mouse is.
     *   tablet   two columns.
     *   desk     two columns, wider, and in live mode the side column
     *            splits again because there is no video taking the space.
     */
    .wrap { display: grid; gap: 1rem; max-width: 110rem; margin-inline: auto;
            grid-template-columns: 1fr; }
    @media (min-width: 48rem) {
        .wrap { grid-template-columns: minmax(0, 1.2fr) minmax(20rem, 1fr); }
    }

    /*
     * Two views, one at a time. Spotting and reading the numbers are
     * different jobs done at different moments by the same person, so they
     * take turns with the screen instead of splitting it.
     */
    #statsView { display: none; max-width: 70rem; margin-inline: auto; }
    body[data-view="stats"] .wrap { display: none; }
    body[data-view="stats"] #statsView { display: block; }
    .views button.on { background: #2b3d68; border-color: #7fd4ff; color: #cdeeff; }
    .trainonly { display: none; }
    body[data-mode="training"] .trainonly { display: block; }
    body[data-mode="training"] button.trainonly { display: inline-block; }

    .logo { width: 26px; height: 26px; color: var(--ok); flex: none; }
    .bar { display: flex; gap: .6rem; align-items: center; flex-wrap: wrap;
           margin: 0 auto .6rem; max-width: 110rem; }
    .modes { display: flex; }
    .modes button { border-radius: 0; }
    .modes button:first-child { border-radius: 6px 0 0 6px; }
    .modes button:last-child { border-radius: 0 6px 6px 0; margin-left: -1px; }
    .modes button.on { background: #2b3d68; border-color: var(--ok); color: #bff3dc; }

    /* The play clock, which has to be readable at a glance from a metre away. */
    .playstate { font-size: .72rem; font-weight: 800; letter-spacing: .1em;
                 padding: .15rem .5rem; border-radius: 99px; }
    .playstate.live { background: #10382a; color: #7df0bd; }
    .playstate.dead { background: #2a2030; color: var(--mute); }

    /*
     * ON A PHONE THE SIX LIVE ACTIONS GO TO THE THUMB, AND NOTHING ELSE DOES.
     *
     * `position: sticky` was wrong: it pins an element only once the document
     * scrolls past it, and this panel sits near the top, so the bar rendered
     * in place and the thumb zone stayed empty. Fixed is what was meant.
     *
     * Only the six matter down there. The microphone is set once and the
     * secondary row is housekeeping, so both stay in the scroll — a bar deep
     * enough to hold them would eat a third of the screen.
     *
     * The rest is reordered around that. `display: contents` dissolves the
     * two desktop columns so the panels can be sequenced by what a spotter
     * needs to SEE while capturing: who is on, then what is waiting to be
     * settled, then everything else.
     */
    @media (max-width: 48rem) {
        .wrap { display: flex; flex-direction: column; }
        .main, .side { display: contents; }
        /* The video panel has no id and so sits at 0. Setup belongs next to
           it rather than below the log: picking the game is what fills the
           video, and the two were a column apart. */
        #trainPanel { order: -1; }
        #linePanel { order: 1; }
        #reviewPanel { order: 2; }
        #actions { order: 3; }
        #logPanel { order: 5; }

        #actions .row.grid {
            position: fixed; left: 0; right: 0; bottom: 0; z-index: 20;
            display: grid; grid-template-columns: 1fr 1fr 1fr; gap: .3rem;
            /* Clear of the home indicator, which otherwise sits on top of
               the bottom row of buttons on every modern phone. */
            padding: .4rem .4rem calc(.4rem + env(safe-area-inset-bottom, 0px));
            margin: 0; background: var(--panel);
            border-top: 1px solid var(--line);
            box-shadow: 0 -8px 20px rgba(0, 0, 0, .6);
        }
        #actions .row.grid button { font-size: .85rem; padding: .4rem .2rem; }
        body { padding-bottom: calc(8rem + env(safe-area-inset-bottom, 0px)); }
    }
    /* The key hints are for a keyboard, and there is not one. */
    @media (pointer: coarse) { #actions kbd { display: none; } }
    /* An empty device list is a stub nobody can use — it appears once the
       microphone has been granted and the labels exist. */
    #micDevice:empty { display: none; }
    /* Fingers, not pointers: the minimum comfortable target is about 44px. */
    @media (pointer: coarse) {
        button, label.file, select { min-height: 44px; font-size: .95rem; }
        button.big { min-height: 56px; }
        .who { min-height: 52px; }
    }
    .panel { background: var(--panel); border: 1px solid var(--line);
             border-radius: 8px; padding: .8rem; margin-bottom: .8rem; }
    h1 { font-size: 1rem; margin: 0 0 .1rem; }
    .sub { color: var(--mute); font-size: .82rem; margin: 0 0 .8rem; }
    .row { display: flex; gap: .45rem; align-items: center; flex-wrap: wrap; }
    button, label.file { font: inherit; font-size: .82rem; padding: .35rem .7rem;
        border-radius: 6px; border: 1px solid var(--line); background: #1d2a48;
        color: var(--ink); cursor: pointer; }
    button.big { font-size: .95rem; padding: .55rem 1rem; font-weight: 700; }
    button.warn { border-color: var(--warn); color: #ffdca8; }
    button.warn.on { background: #4a3413; }
    button.bad { border-color: var(--bad); color: #ffc9c4; }
    input[type=file] { display: none; }
    input[type=text] { font: inherit; padding: .35rem .5rem; border-radius: 6px;
        border: 1px solid var(--line); background: #0d1628; color: var(--ink); }
    kbd { border: 1px solid var(--line); border-radius: 4px; background: #0d1628;
          padding: .05rem .3rem; font-size: .78rem; }

    #player { width: 100%; aspect-ratio: 16/9; background: #000; border-radius: 6px; }
    .clock { font-variant-numeric: tabular-nums; font-size: 1.3rem; font-weight: 700; }

    /* Six live, one dead — the holder cannot be thrown to. */
    /*
     * The line picker, in the commentary desk's language.
     *
     * Class names, proportions and the number-over-surname chip are taken
     * from `pickPanel` in `../../commentator.php` on purpose: a spotter and a
     * commentator are frequently the same person on different weekends, and
     * two pickers would be two things to learn.
     */
    .pickhead { display: flex; align-items: center; gap: .7rem; flex-wrap: wrap;
                margin: 0 0 .5rem; }
    .pickhead .count { font-weight: 700; font-variant-numeric: tabular-nums;
                       color: var(--warn); }
    .pickhead .count.ok { color: var(--ok); }
    .pickhead .teamcount { font-size: .8rem; color: var(--mute); }
    .pickhead .preset { font-size: .75rem; padding: .22rem .55rem; }
    .pickhead .sizer { font-size: .75rem; padding: .15rem .3rem; max-width: 5rem; }
    /* Which line is out, which decides who starts with the disc. */
    .startpill { font-size: .68rem; font-weight: 800; letter-spacing: .06em;
                 padding: .12rem .45rem; border-radius: 99px; }
    .startpill.o { background: #10382a; color: #7df0bd; }
    .startpill.d { background: #3a2540; color: #e0b3ff; }
    /* Tokens from shared/matching.css so both desks tint alike; the type is
       this page's own. Never colour alone - the tag carries the meaning and
       the tint only lets a line be scanned. */
    .mt { font-size: .6rem; font-weight: 800; letter-spacing: .04em;
          padding: 0 .22rem; border-radius: 3px; display: block;
          margin: .1rem auto 0; width: max-content; }
    .mt.fmp { background: var(--fmp-bg); color: var(--fmp-ink); }
    .mt.mmp { background: var(--mmp-bg); color: var(--mmp-ink); }
    .numrow button.fmp { border-color: var(--fmp-bg); }
    .numrow button.mmp { border-color: var(--mmp-bg); }
    .gcount { font-size: .7rem; color: var(--mute); white-space: nowrap;
              display: inline-flex; gap: .2rem; align-items: center; }
    .gcount .mt { display: inline-block; margin: 0; }
    .gcount.full { color: #7df0bd; }
    .gcount.over { color: var(--warn); font-weight: 700; }
    .mtwarn { margin: .2rem 0 .4rem; font-size: .75rem; color: var(--mute); }
    #peditRisk { display: flex; flex-wrap: wrap; gap: .3rem; align-items: center;
                 color: var(--warn); }
    #peditRisk .pill { font-size: .72rem; padding: .1rem .45rem;
                       border-color: var(--warn); color: #ffdca8; }
    .howto > summary { cursor: pointer; font-size: .8rem; color: var(--mute); }
    .howto ul { margin: .4rem 0 0; padding-left: 1.1rem; font-size: .8rem;
                color: var(--mute); }
    .howto li { margin: .25rem 0; }
    .howto strong { color: var(--ink); font-weight: 700; }
    #pedit { border: 1px solid var(--line); border-radius: .6rem;
             background: var(--panel); color: var(--ink); min-width: 15rem; }
    #pedit::backdrop { background: rgba(0, 0, 0, .55); }
    #pedit form { display: flex; flex-direction: column; gap: .5rem; margin: 0; }
    .peditrow { display: flex; gap: .4rem; align-items: center;
                font-size: .8rem; color: var(--mute); }
    .peditrow input { flex: 1; }
    .peditmt { display: inline-flex; gap: .25rem; }
    .peditmt button[aria-pressed="true"] { border-color: var(--ink); }
    #pedit menu { display: flex; gap: .4rem; justify-content: flex-end;
                  margin: 0; padding: 0; }
    .ratiobox { display: inline-flex; gap: .35rem; align-items: center; }
    .ratiobox label { display: inline-flex; gap: .2rem; align-items: center; }
    .ratiochip { font-size: .68rem; font-weight: 800; letter-spacing: .06em;
                 padding: .1rem .4rem; border-radius: .6rem;
                 background: #123043; color: #8fd8ff; }
    /* "Not said" is a state to notice, not a value to read past. */
    .ratiochip.unset { background: transparent; color: var(--mute);
                       font-weight: 500; letter-spacing: 0; }
    .namerisk { margin: 0 0 .5rem; font-size: .78rem; color: var(--warn);
                border-left: 3px solid var(--warn); padding-left: .55rem; }
    /* Wrap BETWEEN links, never inside one: "Ann Arbor Hybrid roster" broken
       across two lines beside "game stats" reads as four links, not three. */
    .packlinks { display: inline-flex; gap: .45rem; flex-wrap: wrap; }
    .packlinks a { font-size: .8rem; color: #7fd4ff; white-space: nowrap; }
    /* Collapsed by default: with a full tournament roster loaded this is a
       dozen rows of advice about names that may never be called, sitting on
       top of the line picker. The summary keeps the count visible, so it is
       still findable when a name does turn out to be unsayable. */
    .namerisk > summary { cursor: pointer; padding: .12rem 0; }
    /* The rows sit in a wrapper, and the flex lives on THEM rather than on a
       direct child of the <details>: `display` set on a closed details' own
       child overrides the hiding, and the block stayed fully visible with
       `open` false. */
    .namerisk .riskbody > div { padding: .12rem 0; display: flex; gap: .3rem;
                    align-items: baseline; flex-wrap: wrap; }
    .namerisk button { font-size: .72rem; padding: .1rem .45rem;
                       border-color: var(--warn); color: #ffdca8; }
    .grouplabel { font-size: .66rem; letter-spacing: .08em; text-transform: uppercase;
                  color: var(--mute); margin: .5rem 0 .25rem; }
    .numrow { display: flex; flex-wrap: wrap; gap: .35rem; }
    .numrow button { position: relative; min-width: 3.1rem; padding: .5rem .4rem;
                     border-radius: 5px; border: 1px solid var(--line);
                     background: #0d1628; color: var(--mute); font: inherit;
                     font-weight: 700; font-variant-numeric: tabular-nums;
                     cursor: pointer; line-height: 1.1; text-align: center; }
    .numrow button small { display: block; font-size: .62rem; font-weight: 500;
                           color: var(--mute); letter-spacing: .02em;
                           max-width: 4.5rem; overflow: hidden;
                           text-overflow: ellipsis; white-space: nowrap; }
    /* On the line: lit, and carrying the key it answers to. */
    .numrow button.on { border-color: var(--ok); background: #10382a; color: #bff3dc; }
    .numrow button.on small { color: #8fd9bb; }
    .numrow button .kk { position: absolute; top: -.35rem; left: -.35rem;
                         background: var(--ok); color: #05240f; font-size: .6rem;
                         font-weight: 800; border-radius: 3px; padding: 0 .22rem; }
    /* Holding the disc, so they cannot be thrown to. */
    .numrow button.has { opacity: .5; border-style: dashed; }
    /* Heard, not yet committed. A view of the recogniser, not a record. */
    .numrow button.maybe { border-color: #7fd4ff; box-shadow: 0 0 0 2px rgba(127, 212, 255, .25); }
    .numrow button.maybe small { color: #bfe9ff; }

    .stat { display: grid; grid-template-columns: repeat(auto-fit, minmax(5.5rem, 1fr)); gap: .4rem; }
    .stat div { background: #0d1628; border-radius: 7px; padding: .35rem .5rem; }
    .stat span { display: block; font-size: .7rem; color: var(--mute); }
    .stat b { font-size: 1.25rem; font-variant-numeric: tabular-nums; }

    .log { max-height: 26rem; overflow-y: auto; }
    table { width: 100%; border-collapse: collapse; font-size: .83rem; }
    td { padding: .2rem .35rem; border-bottom: 1px solid var(--line); cursor: pointer; }
    tr:hover td { background: #0d1628; }
    tr.at td { background: #16304a; }
    td.t { color: var(--mute); font-variant-numeric: tabular-nums; width: 4.2rem; }
    .gap { color: var(--warn); }
    /* Clock rows are scaffolding, not observations: present, and quiet. */
    .clockrow { color: #6f7f97; font-size: .78rem; }
    /* Understood, and waiting on a decision. Not a failure. */
    .choice { color: #c39bff; }
    .afk { color: var(--warn); font-weight: 700; }
    .turn { color: var(--bad); }
    .goal { color: var(--ok); font-weight: 700; }
    .focuswarn { color: var(--warn); font-size: .8rem; }
    .deaf { font-size: .78rem; color: var(--mute); cursor: help; }
    .deaf.bad { color: var(--warn); font-weight: 600; }
    .heard { font-size: .85rem; color: var(--mute); font-style: italic; }
    .heard.hit { color: #bff3dc; font-style: normal; }
    .heard.no { color: var(--warn); }
    button#mic.on { border-color: var(--ok); background: #10382a; color: #bff3dc; }
    .voice { color: #7fd4ff; }
    /* The grammar, drawn. A spotter can see the shape they are speaking into
       and which slot each word landed in — which is also the fastest way to
       learn that "huck lang" and "lang huck" are the same call. */
    /* The grammar, stated. Quiet enough to live on screen permanently. */
    .structure { margin-top: .6rem; border: 1px solid var(--line); border-radius: 8px;
                 background: #0d1628; padding: .5rem .6rem; }
    .structure > summary { cursor: pointer; font-size: .68rem; letter-spacing: .08em;
                           text-transform: uppercase; color: var(--mute); }
    .structure[open] > summary { margin-bottom: .45rem; }
    .structrow { display: grid; grid-template-columns: 9.5rem minmax(0, 1fr);
                 gap: .1rem .6rem; align-items: baseline; padding: .12rem 0; }
    .structrow code { font-size: .74rem; color: #7f8fa8; white-space: nowrap;
                      overflow: hidden; text-overflow: ellipsis; }
    .structrow b { font-size: .88rem; color: #bff3dc; }
    .structrow span { grid-column: 2; font-size: .72rem; color: var(--mute); }
    .structnote { margin: .45rem 0 0; font-size: .72rem; color: var(--mute); }
    /* The panel holds a reference and a rehearsal now, so it scrolls rather
       than pushing everything below it off the screen. */
    .structure > div { max-height: 26rem; overflow-y: auto; }
    @media (max-width: 48rem) { .structure > div { max-height: 17rem; } }
    .structhead { font-size: .68rem; letter-spacing: .08em; text-transform: uppercase;
                  color: var(--mute); margin: .7rem 0 .3rem; }
    .structscript { margin: 0; padding-left: 1.4rem; }
    .structscript li { padding: .1rem 0; font-size: .85rem; }
    .structscript b { color: #bff3dc; }
    .structscript span { color: var(--mute); font-size: .72rem; margin-left: .45rem; }
    .structscript li.mark { list-style: none; margin-left: -1.4rem; font-size: .68rem;
                            letter-spacing: .07em; text-transform: uppercase;
                            color: #7f8fa8; padding-top: .5rem; }
    .structscript span.no { color: var(--warn); }
    .checkout:empty { display: none; }
    .checkout { margin: .45rem 0 .2rem; }
    .checkok { margin: 0 0 .3rem; color: var(--ok); font-weight: 600; font-size: .85rem; }
    .checkbad { margin: 0 0 .3rem; color: var(--warn); font-weight: 600; font-size: .85rem; }
    .checkrow { display: grid; grid-template-columns: 4.6rem minmax(0, 1fr); gap: .4rem;
                font-size: .82rem; padding: .1rem 0; }
    .checkrow code { color: var(--mute); font-size: .72rem; }
    .checkrow b { color: var(--ink); }
    .report { width: 100%; height: 14rem; font: 12px/1.4 ui-monospace, monospace;
              background: #0b1220; color: var(--ink); border: 1px solid var(--line);
              border-radius: 6px; padding: .5rem; }
    .structrule { margin: 0 0 .45rem; font-size: .78rem; color: #ffdca8; }
    /* On a phone the explanations are what goes: a spotter reading this on a
       sideline needs the shape and one example, not the commentary. */
    @media (max-width: 34rem) {
        .structrow { grid-template-columns: 7.5rem minmax(0, 1fr); padding: .2rem 0; }
        .structrow span { display: none; }
        .structrow b { font-size: .84rem; }
    }
    .slots { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; margin-top: .6rem; }
    .slot { border: 1px dashed var(--line); border-radius: 7px; padding: .4rem .6rem;
            background: #0d1628; }
    .slot span { display: block; font-size: .68rem; letter-spacing: .08em;
                 text-transform: uppercase; color: var(--mute); }
    .slot b { font-size: 1.05rem; }
    .slot.filled { border-style: solid; border-color: var(--ok); }
    .slot.filled b { color: #bff3dc; }
    .slot.empty b { color: #3d4a63; }
    .unres { font-weight: 800; font-size: 1.1rem; color: #3d4a63; }
    .unres.on { color: var(--warn); }
    .flight { margin-top: .5rem; font-size: .85rem; color: var(--warn); min-height: 1.2rem; }
    .legend { margin-top: .5rem; font-size: .78rem; color: var(--mute); }
    .legend summary { cursor: pointer; }
    .legend b { color: var(--ink); font-weight: 600; }
    .card { display: grid; grid-template-columns: repeat(auto-fit, minmax(6rem, 1fr));
            gap: .4rem; margin: .5rem 0; }
    .card div { background: #0d1628; border-radius: 7px; padding: .35rem .5rem; }
    .card span { display: block; font-size: .7rem; color: var(--mute); }
    .card b { font-size: 1.2rem; }
    .miss { font-size: .82rem; padding: .18rem .3rem; border-bottom: 1px solid var(--line);
            cursor: pointer; }
    .miss:hover { background: #0d1628; }
    .miss .w { color: var(--bad); }
    .miss .u { color: var(--warn); }
    .miss .i { color: #c39bff; }
    .miss .f { color: var(--mute); }
    /* The review queue. Quiet while empty; loud the moment play stops, which
       is when there is time to clear it. */
    #reviewPanel.due { border-color: var(--warn); }
    .fix { border-bottom: 1px solid var(--line); padding: .4rem 0; }
    .fix .said { font-size: .85rem; }
    .fix .said b { color: #ffdca8; }
    .fix .opts { display: flex; gap: .25rem; flex-wrap: wrap; margin-top: .3rem; }
    .fix .opts button { font-size: .75rem; padding: .2rem .45rem; }

    /* The flag: one tap for "that one is wrong, ask me later". */
    button.flagbtn { border-color: #c39bff; color: #e3d2ff; }
    td.flagcell { width: 1.8rem; text-align: center; color: #3d4a63; }
    td.flagcell.on { color: #c39bff; }
    tr.flagged td { background: #241a38; }
    tr.flagged td.t::after { content: ' ?'; color: #c39bff; font-weight: 700; }

    /* The coach's table. Dense on purpose: it is read, not operated. */
    table.coach { font-size: .92rem; width: 100%; }
    table.coach th { text-align: right; color: var(--mute); font-weight: 600;
                     font-size: .68rem; text-transform: uppercase;
                     letter-spacing: .04em; padding: .2rem .3rem;
                     border-bottom: 1px solid var(--line); }
    table.coach th:first-child { text-align: left; }
    table.coach td { text-align: right; font-variant-numeric: tabular-nums;
                     cursor: default; }
    table.coach td.nm { text-align: left; white-space: nowrap; }
    table.coach tr.blind td { color: #ffdca8; }
    /* Not "low": unknown. A row the capture cannot speak for. */
    table.coach tr.unknown td { color: #8f7f9e; font-style: italic; }
    .cov { font-size: .78rem; color: var(--warn); margin: 0 0 .5rem; }
    .caveat { margin: .5rem 0 0; font-size: .74rem; }
    details.panel summary { cursor: pointer; list-style: revert; }
    details.panel[open] summary { margin-bottom: .5rem; }
    select { font: inherit; font-size: .82rem; padding: .3rem; border-radius: 6px;
             border: 1px solid var(--line); background: #0d1628; color: var(--ink);
             max-width: 11rem; }

    /* Pocket mode. Black, because the point is that nobody is looking. */
    #pocket { display: none; position: fixed; inset: 0; z-index: 50;
              background: #000; color: var(--mute); place-items: center;
              text-align: center; }
    body.pocket #pocket { display: grid; }
    #pocket b { display: block; font-size: 3rem; font-variant-numeric: tabular-nums;
                color: #1f3350; }
    #pocket p { margin: .3rem 0 1.5rem; font-size: .9rem; }
    #pocket button { padding: 1rem 2rem; font-size: 1rem; }
</style>
<?php
/*
 * Before the page's own script, not after it.
 *
 * The surface reads its rosters at start-up, so `window.Provider` has to
 * exist by then - loaded at the end of the body it did not, and the read was
 * skipped silently every time, which looked exactly like an installation
 * with no event.
 */
?>
<script src="<?= $e($assetUrl('shared/provider.js')) ?>"></script>
<?php
/*
 * The ratio rule and the declared-value shape, not restated here.
 *
 * `shared/ratio.js` exists because the commentator page and the stage card had
 * each written the ABBA pattern out and drifted; a third copy in the spotter
 * would be the same mistake with the same ending. `shared/declared.js` is the
 * local-or-shared reconciliation both desks already use for exactly this value.
 */
?>
<script src="<?= $e($assetUrl('shared/ratio.js')) ?>"></script>
<script src="<?= $e($assetUrl('shared/declared.js')) ?>"></script>
<script src="<?= $e($assetUrl('shared/ratio-ui.js')) ?>"></script>
<script src="<?= $e($assetUrl('shared/lineup.js')) ?>"></script>
<script src="<?= $e($assetUrl('shared/lineup-ui.js')) ?>"></script>
</head>
<body data-mode="live" data-model="<?= $e(Mode::assetBase($base)) ?>/spotter/"
      data-game="<?= $gameId === false || $gameId === null ? '' : (int) $gameId ?>">
<script>
    window.SPOTTER_PACK = <?= $gamePack ? $json($gamePack) : 'null' ?>;
    /*
     * Where the rosters come from, which is the same place every other
     * surface reads: `shared/provider.js` answers for a hosted Live!
     * installation and for a standalone event with one shape, so nothing
     * here knows or cares which mode it is in.
     */
    window.SPOTTER_CONFIG = {
        api: <?= $json($base . '/index.php?view=live/api') ?>,
        captureBase: <?= $json(Mode::captureBase($base)) ?>,
        rosterUrl: <?= $json(\Overlays\Auth::isHosted() ? null : Mode::viewUrl('roster', $base)) ?>,
        notesUrl: <?= $json(Mode::viewUrl('notes', $base)) ?>
    };
</script>


<header class="bar">
  <!--
      Binoculars: this is a page for somebody watching from a distance and
      reporting what they saw. Inline rather than a file, because the tool's
      defining property is that it is one HTML document you can carry on a
      stick, and `currentColor` means it needs no separate dark variant.
  -->
  <svg class="logo" viewBox="0 0 24 24" aria-hidden="true" fill="none"
       stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
    <circle cx="6" cy="16" r="4.2"/>
    <circle cx="18" cy="16" r="4.2"/>
    <path d="M9.9 14.6h4.2"/>
    <path d="M4.1 12.9 5.3 5.2A1.7 1.7 0 0 1 7 3.8h.9a1.7 1.7 0 0 1 1.7 1.7v7.3"/>
    <path d="M19.9 12.9 18.7 5.2A1.7 1.7 0 0 0 17 3.8h-.9a1.7 1.7 0 0 0-1.7 1.7v7.3"/>
  </svg>
  <div class="modes">
    <button id="modeLive" class="on">live</button><button id="modeTrain">training</button>
      </div>
  <span class="clock" id="clock">0:00</span>
      <span class="playstate dead" id="playState">stopped</span>
  <span class="unres" id="unres" title="said, not understood — settle these at the next stoppage"></span>
  <div class="modes views">
    <button id="viewSpot" class="on">spot</button><button id="viewStats">stats</button>
      </div>
  <span class="sub" id="awake"></span>
  <span class="focuswarn" id="focuswarn"></span>
</header>

<div class="wrap">
  <div class="main">
    <p class="sub" id="blurb"></p>
    <div class="panel trainonly" id="trainPanel">
      <div class="row">
        <strong>Training</strong>
<?php if ($gamePack) : ?>
        <label for="packGame">Reference game</label>
        <select id="packGame" title="A reference game, with both squads">
<?php if (count($gamePack) !== 1) : ?>
          <option value="">choose a game…</option>
<?php endif; ?>
<?php foreach ($gamePack as $i => $g) : ?>
          <option value="<?= (int) $i ?>"><?= $e((string) ($g['name'] ?? $g['id'] ?? ('game ' . $i))) ?></option>
<?php endforeach; ?>
        </select>
        <?php
        // Choosing is not loading. Loading wipes the session, so it gets its
        // own button: a spotter who only wants to look a shirt number up
        // should not have to destroy a capture to reach the link.
        ?>
        <button id="packLoad">Load squads and video</button>
        <?php
        // The tournament's own record, one click away: the roster to settle a
        // shirt number mid-point, the statistics to compare a finished capture
        // against. Links, not imports - nothing is fetched and nothing stored.
        ?>
        <span id="packLinks" class="packlinks"></span>
<?php endif; ?>
        <label class="file">Load a tagged game<input id="ref" type="file" accept=".json"></label>
        <button id="scoreme" disabled>Score me</button>
        <span class="sub" id="refinfo">no reference loaded</span>
      </div>
      <?php
      /*
       * Collapsed, because none of it is needed to start.
       *
       * The two mixed steps are OPTIONAL and unlock a check rather than a
       * feature: without them every call is still captured, and with them a
       * called line can be counted against the point's ratio. Presenting them
       * as setup would imply a spotter must do them before they may begin,
       * which is exactly backwards - the tool has to work for somebody who
       * just pressed record.
       */
      ?>
      <details class="howto" id="trainHow">
        <summary>How training mode works</summary>
        <ul>
          <li><strong>Video time is the clock</strong>, so nothing drifts — and two
            people spotting the same video produce captures that line up exactly.
            Click any captured line to jump the video to it.</li>
          <li><strong>Point 1’s gender ratio</strong>, optional. Tick <em>ratio</em>
            beside the line and pick it; every later point follows from it.</li>
          <li><strong>Each player’s MMP/FMP</strong>, optional. Right-click a player
            (long-press on a phone), or enter the commentary desk’s code and press
            <em>Get matchings</em> to inherit what they already typed.</li>
          <li>Neither is required. Together they let the picker count each matching
            against the point’s quota and mark a line that goes over it.</li>
          <li><strong>Spot a few points, not the whole game.</strong> A final runs
            well over an hour; three or four points is a useful contribution and
            a realistic sitting. Stop whenever you like and do not note the
            times: every event carries its video timestamp, so the capture
            already says which passage you covered and two captures that overlap
            can be lined up on it.</li>
          <li><strong>When you stop</strong>, press <em>Save</em> under Captured and
            send the file on. Scoring happens on the collected captures, not here:
            agreement between people who spotted the same passage is the measure,
            and no single capture is the right answer to check the others against.
            <em>Score me</em> stays for checking yourself against a capture you
            already trust, and needs one loaded before it does anything.</li>
        </ul>
      </details>
      <div id="scorecard"></div>
    </div>
    <div class="panel trainonly">
      <div class="row">
        <input id="url" type="text" size="30" placeholder="YouTube URL or id">
        <button id="load">Load video</button>
      </div>
      <div id="player"></div>
    </div>
    <div class="panel actions" id="actions">
      <div class="row grid">
        <button class="big" id="playToggle"><kbd>p</kbd> <span>play on</span></button>
        <button class="big flagbtn" id="flagBtn"><kbd>!</kbd> flag that</button>
        <button class="big warn" id="missed"><kbd>m</kbd> missed</button>
        <button class="big bad" id="turn"><kbd>x</kbd> throwaway</button>
        <button class="big" id="goal"><kbd>g</kbd> goal</button>
        <button class="big" id="newpt"><kbd>n</kbd> new point</button>
      </div>
      <div class="row less">
        <button class="warn" id="afk"><kbd>a</kbd> AFK</button>
        <button id="undo"><kbd>z</kbd> undo</button>
        <button id="cut" class="trainonly" title="drop everything after the playhead, to re-spot">⟲ from here</button>
        <button id="pocketBtn" title="screen off, voice only">📱 pocket</button>
        <button id="wipe" title="forget this session">clear</button>
        <span class="sub" id="resume"></span>
        <span class="sub" id="poss"></span>
        <span class="sub" id="buffer"></span>
      </div>
      <div class="row" style="margin-top:.5rem">
        <button class="big" id="mic">🎙 voice off</button>
        <select id="micDevice" title="which microphone"></select>
        <label class="sub">audio
          <select id="audioMode">
            <option value="off">off</option>
            <option value="buffer" selected>last 90s only</option>
            <option value="keep">keep all</option>
          </select>
        </label>
        <input id="say" type="text" size="18" placeholder="or type a call">
        <span class="sub" id="engine">engine: —</span>
        <span class="deaf" id="deaf" title="The recogniser knows English words and nothing else. An invented nickname (Webbo, Hansi), a bare shirt number, and the sport's loanwords (scoober, thumber, afk) are dropped from its grammar and can never match. Say the surname or first name, and say a number as words: &quot;twenty three&quot;.">⚠ English words only</span>
        <span class="heard" id="heard">—</span>
      </div>
      <details class="structure" id="structure" open><summary>Say it like this</summary>
        <div id="structbody"></div>
      </details>
      <div class="slots" id="slots"></div>
      <div class="flight" id="flight"></div>
      <details class="legend">
        <summary>the call grammar</summary>
        <div id="legend"></div>
      </details>
      <p class="sub" style="margin:.5rem 0 0">
        <kbd>1</kbd>–<kbd>7</kbd> receiver &nbsp; <kbd>q</kbd> huck <kbd>w</kbd> inside
        <kbd>e</kbd> break <kbd>r</kbd> swing <kbd>f</kbd> dump &nbsp;
        <kbd>c</kbd> drop <kbd>v</kbd> blocked &nbsp;
        <kbd>p</kbd> play clock <kbd>!</kbd> flag that &nbsp;
        <kbd>space</kbd> video play/pause
      </p>
    </div>
  </div>

  <div class="side">
    <div class="panel" id="linePanel">
      <div class="row">
        <label class="file">Squad<input id="file" type="file" accept=".json"></label>
        <button id="demoline" title="seven invented players, to try the tool">demo line</button>
        <?php
        // The desk's code, not a game id: shared/notes.php files notes under
        // the code alone, so a spotter handed the commentary desk's code
        // inherits whatever it has already typed about these players.
        ?>
        <input id="deskcode" type="text" size="8" placeholder="desk code"
               title="The commentary desk&#39;s sync code — pulls the matchings they typed">
        <button id="desknotes">Get matchings</button>
        <span class="sub" id="deskinfo"></span>
      </div>
      <div class="line" id="line" style="margin-top:.5rem"></div>
    </div>
    <?php
    // Long-press on a phone raises the same `contextmenu` event a right-click
    // does, so one dialog serves both - and shift-click stays for a keyboard.
    ?>
    <dialog id="pedit">
      <form method="dialog">
        <strong id="peditWho"></strong>
        <label class="peditrow">Call them
          <input id="peditCall" type="text" size="14"
                 placeholder="as spoken"></label>
        <div class="peditrow">Matching
          <span class="peditmt" id="peditMt"></span>
        </div>
        <p class="sub" id="peditRisk"></p>
        <p class="sub" id="peditWhy"></p>
        <menu>
          <button value="cancel">Cancel</button>
          <button id="peditSave" value="save">Save</button>
        </menu>
      </form>
    </dialog>
    <div class="panel" id="reviewPanel">
      <div class="row">
        <strong>To review</strong>
        <span class="sub" id="reviewInfo">nothing waiting</span>
      </div>
      <div id="review"></div>
    </div>
    <div class="panel" id="logPanel">
      <div class="row">
        <strong>Captured</strong>
        <span class="sub" id="info"></span>
        <button id="save">Save</button>
        <label class="file">Open<input id="open" type="file" accept=".json"></label>
      </div>
      <div class="log"><table id="log"><tbody></tbody></table></div>
    </div>
  </div>
</div>

<!--
    The stats view is a SWITCH, not a second panel.

    A coach reading numbers is not calling throws in the same moment - it is
    one person changing task, and on a phone there is no room to pretend
    otherwise. So the numbers get the whole screen when they are wanted and
    none of it when they are not.

    Capture does not pause behind it: the clock runs, the microphone stays
    open, the play state stays in the header. Glancing at the table during a
    timeout must not cost a point of data.
-->
<div id="statsView">
  <div class="panel">
    <div id="coach"></div>
  </div>
  <details class="panel"><summary><strong>Capture quality</strong></summary>
    <div class="stat" id="stats"></div>
  </details>
</div>

<!--
    Pocket mode: the screen is the battery drain and the accidental-tap risk,
    and a spotter working by voice is not looking at it anyway. Black, awake,
    and one big target to come back.
-->
<div id="pocket">
  <div>
    <b id="pocketClock">0:00</b>
    <p id="pocketState">listening</p>
    <button id="pocketOut">tap to come back</button>
  </div>
</div>

<script>
(function () {
    'use strict';

    var THROWS = { q: 'huck', w: 'inside', e: 'break', r: 'swing', f: 'dump' };
    var TURNS = { x: 'throwaway', c: 'drop', v: 'blocked' };

    var player = null;
    /**
     * The squad, of which seven are on.
     *
     * The line used to BE the squad: `setLine` took the first seven of
     * whatever was loaded and threw the rest away, so changing a line between
     * points meant retyping seven names. A game substitutes the whole seven at
     * every point, which made that the most-used control in the tool and the
     * worst one. The picker is the commentary desk's, because a spotter who
     * has seen that screen should not have to learn a second one.
     */
    var squad = [];
    var line = [];
    /**
     * How many are on, which is not always seven.
     *
     * Beach is 5v5, indoor is 5, mixed indoor is 4, and a squad testing the
     * tool has whoever turned up. The commentary desk derives this from the
     * ratio and the season type; here there is no event to ask, so it is a
     * control - and it decides the preset size, the picker's cap, the count
     * and which number keys address the line.
     */
    var lineSize = 7;
    var events = [];
    var holder = null;
    /**
     * The disc, between leaving a hand and being caught.
     *
     * `null` when nothing is in the air. Otherwise the throw type (if it was
     * said) and any deflections since, waiting for the receiver that closes
     * it. This is what lets a call arrive in pieces.
     */
    var inFlight = null;
    var afkFrom = null;
    var point = 1;
    /**
     * Whose disc it is, which the first version could not represent at all.
     *
     * A spotter follows ONE team, and a lot of a game is the other side
     * playing. With no way to say so, every name called during their
     * possession became a throw by ours — a fabricated event, which is the
     * failure this whole design refuses. `us`, `them`, or null between points.
     */
    var possession = 'us';
    /** The line per point, because a game changes it at every one. */
    var lines = {};

    /**
     * THE PLAY CLOCK, AND WHY IT IS NOT THE AFK CLOCK
     *
     * These look alike and mean opposite things, and conflating them would
     * quietly ruin both numbers:
     *
     *   AFK       the game was live, NOBODY WAS CAPTURING. Missing data. The
     *             coverage rule turns it into "these points have no throw
     *             data" rather than "these points had no throws".
     *   stoppage  the game WAS NOT LIVE. Nothing is missing; a stopped disc
     *             is a real state of a game and roughly half of one.
     *
     * So they are separate timelines, and live time during an AFK window is
     * reported as unknown rather than counted either way.
     *
     * WHAT THIS BUYS
     *
     * A denominator that means something. "Throws per point" is hostage to
     * how long points ran; throws per minute of LIVE play is comparable
     * across games. And with the line known per point, it gives time on the
     * field during live play, per player.
     *
     * WHAT IT IS NOT, AND THE UI SAYS SO
     *
     * Time present while the disc was live. NOT work done, distance covered
     * or effort spent — a handler parked in the dump and a cutter running
     * the whole point score identically here. That gap needs trackers or
     * vision, and no amount of spotting closes it.
     */
    var inPlay = false;

    /**
     * Which calls a spotter reads off a signal, and which they infer.
     *
     * Everything not listed here is signalled. These two are learnt from what
     * happens next - the disc goes back, or it does not - so the event says
     * so rather than presenting an inference as an observation.
     */
    var INFERRED_CALL = { contested: 1, uncontested: 1 };

    /** Calls that stop the disc by rule, so the clock can follow the words. */
    var STOPS_PLAY = {
        foul: 1, travel: 1, pick: 1, strip: 1, 'double team': 1, 'disc space': 1,
        'fast count': 1, marking: 1, 'vision blocking': 1, violation: 1,
        offside: 1, timeout: 1, injury: 1, 'out of bounds': 1
    };

    function el(id) { return document.getElementById(id); }

    /**
     * TWO MODES, ONE DIFFERENCE THAT MATTERS: WHAT THE CLOCK IS.
     *
     *   training  a YouTube game is the clock. Every event is stamped with
     *             the player's own time, so a point can be rewound and spotted
     *             again, clicking an event seeks back to it, and a tagged
     *             reference can be scored against. Repeatable, which is what
     *             makes it a rehearsal rather than a performance.
     *   live      the wall clock, from when the session started. Nothing to
     *             seek, nothing to score against, no second chance.
     *
     * Everything else — the grammar, the queue, the play clock, the stats —
     * is identical, which is the point: the rehearsal is the real thing with
     * a video behind it, not a different tool.
     *
     * Mixing the two within one session would put wall-clock and video-time
     * stamps on the same axis, so switching clears rather than converts.
     */
    var MODE = 'live';
    var liveFrom = null;

    function at() {
        if (MODE === 'training') {
            return player && player.getCurrentTime ? player.getCurrentTime() : 0;
        }
        return liveFrom === null ? 0 : (Date.now() - liveFrom) / 1000;
    }

    /** Seeking is a training affordance; live mode has nowhere to go. */
    function seek(t) {
        if (MODE !== 'training') { return; }
        if (player && player.seekTo) { player.seekTo(Math.max(0, t), true); }
    }

    /**
     * Is this a good moment to settle the queue?
     *
     * In training, whether the video is paused. Live, whether the disc is
     * dead — which is what the question always meant, and is now answerable
     * because the play clock exists.
     */
    function atRest() {
        if (MODE === 'training') {
            return !!(player && player.getPlayerState && player.getPlayerState() !== 1);
        }
        return !inPlay;
    }
    /** 1st, 2nd, 3rd - for a count a commentator would say out loud. */
    function nth(n) {
        if (n % 100 >= 11 && n % 100 <= 13) { return 'th'; }
        return ['th', 'st', 'nd', 'rd'][n % 10] || 'th';
    }

    function mmss(s) {
        s = Math.max(0, Math.floor(s || 0));
        return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    }

    /**
     * What the recogniser heard, mapped onto what it is allowed to mean.
     *
     * The recogniser is NOT constrained — Chrome's Web Speech ignores a
     * grammar — so the constraint lives here instead: free text in, one of a
     * handful of legal targets out. That is the layer worth building, because
     * it survives the engine being swapped for a local model later. The six
     * live names, five throw types and a few commands are the entire language.
     *
     * TWO REFUSALS, BOTH DELIBERATE
     *
     * Below the threshold it matches nothing and says what it heard, rather
     * than picking the closest of six and being confidently wrong. And when
     * two candidates score within a hair of each other it reports AMBIGUOUS
     * instead of choosing — the same rule as two Webers on the field
     * (`../../docs/MATCHCONTROL.md` §10a): an ambiguity is detectable, so it
     * gets recorded rather than guessed.
     */
    function distance(a, b) {
        var prev = [];
        var i;
        var j;
        for (j = 0; j <= b.length; j += 1) { prev[j] = j; }
        for (i = 1; i <= a.length; i += 1) {
            var cur = [i];
            for (j = 1; j <= b.length; j += 1) {
                cur[j] = Math.min(
                    prev[j] + 1,
                    cur[j - 1] + 1,
                    prev[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1)
                );
            }
            prev = cur;
        }
        return prev[b.length];
    }

    function similarity(a, b) {
        if (!a || !b) { return 0; }
        var longest = Math.max(a.length, b.length);
        return 1 - (distance(a, b) / longest);
    }

    /**
     * What a word sounds like, as a string of consonant classes.
     *
     * Recognition errors are PHONETIC, not orthographic. A model that has
     * never met these squads returns "lena", "leiner" or "lane her" for
     * Lehner, and character distance rejects all three. Consonant classes
     * survive it: b and v collapse together, vowels and h fall out, order
     * is kept.
     *
     *   lehner -> L56    lena -> L5    weber -> W16    weaver -> W16
     *
     * Soundex's groups without Soundex's four-character truncation, which
     * would throw away the tail that tells six teammates apart.
     */
    var CLASS = { b: 1, f: 1, p: 1, v: 1, c: 2, g: 2, j: 2, k: 2, q: 2, s: 2, x: 2, z: 2,
                  d: 3, t: 3, l: 4, m: 5, n: 5, r: 6 };

    function sounds(word) {
        var w = String(word).toLowerCase().replace(/[^a-z]/g, '');
        if (!w) { return ''; }
        var out = w.charAt(0).toUpperCase();
        var prev = CLASS[w.charAt(0)] || 0;
        for (var i = 1; i < w.length; i += 1) {
            var c = CLASS[w.charAt(i)];
            if (c && c !== prev) { out += c; }
            if (w.charAt(i) !== 'h' && w.charAt(i) !== 'w') { prev = c || 0; }
        }
        return out;
    }

    /** Spelling or sound, whichever agrees more. */
    function score(heard, target) {
        return Math.max(similarity(heard, target), similarity(sounds(heard), sounds(target)));
    }

    /**
     * Everything a spotter may say that is not a name.
     *
     * This list IS the grammar. A local recogniser would be handed it directly
     * to constrain its decoding; Chrome's Web Speech ignores such a thing, so
     * here it constrains the match instead — same list, same effect, one layer
     * further down. Either way it is the file to edit when a squad says
     * something this does not know.
     *
     * Each entry is the stored term and then the ways people say it out loud,
     * including the ways a recogniser predictably mishears them ("hook" for
     * huck, "scuba" for scoober). Aliases of one or two characters are matched
     * exactly rather than fuzzily — "d" is a legitimate call for a block and a
     * terrible thing to fuzzy-match, because at that length it resembles
     * everything.
     */
    var SAY = {
        throw: {
            huck: ['huck', 'hucks', 'hook', 'deep'],
            hammer: ['hammer'],
            scoober: ['scoober', 'scuba'],
            blade: ['blade'],
            inside: ['inside', 'inside out', 'io'],
            'outside in': ['outside in', 'oi'],
            around: ['around'],
            'push pass': ['push pass'],
            thumber: ['thumber'],
            'low release': ['low release'],
            // Not a throw like the others: it starts the point, and nothing
            // in the grammar could say so before.
            pull: ['pull', 'pulled'],
            break: ['break', 'break mark'],
            swing: ['swing', 'swings'],
            dump: ['dump', 'dish', 'reset'],
            'high release': ['high release']
        },
        /*
         * Recorded as finely as it was said, and lumped in analysis.
         *
         * A hand block and a layout D were aliases of one "blocked" term,
         * which threw the distinction away at the only moment it was
         * available. Coarsening later is free; recovering detail never
         * recorded is impossible. So the terms are separate and a spotter who
         * only ever says "D" simply produces `blocked`.
         */
        outcome: {
            goal: ['goal', 'score', 'scores'],
            drop: ['drop', 'dropped', 'drops'],
            throwaway: ['throwaway', 'throw away', 'turfed'],
            blocked: ['block', 'blocked', 'd'],
            'hand block': ['hand block'],
            'foot block': ['foot block'],
            'layout block': ['layout d', 'layout block', 'layout'],
            interception: ['interception', 'intercepted', 'picked off'],
            callahan: ['callahan'],
            greatest: ['greatest'],
            stall: ['stall', 'stalled', 'stall out'],
            brick: ['brick'],
            // Said after a pull, to record that it landed in play. Optional:
            // a spotter who says nothing has not claimed it went out.
            'pull in': ['pull in', 'landed in'],
            /*
             * They scored, which nothing could record.
             *
             * `goal` means OUR goal - the spotter follows one team. A point we
             * lose therefore had no ending at all: the log ran to the next
             * point marker with no reason, goals against were absent, and
             * holds and breaks could not be computed from any of it. Which
             * also meant the O and D lines could not be inferred, because who
             * pulls next depends on who just scored.
             */
            conceded: ['conceded', 'their goal', 'theirs']
        },
        // Recording a call and how it resolved was an idea in §10a with no
        // cheap way to enter it. Saying it is the cheap way.
        /*
         * CALLS ARE WHAT THE SPOTTER CAN SEE, WHICH MEANS HAND SIGNALS.
         *
         * A spotter is on a sideline and cannot hear the players. Whatever is
         * argued on the field reaches them only if somebody signals it - so
         * the vocabulary is the WFDF signal set, and a call with no signal is
         * not an observation, it is a guess about a conversation.
         *
         * This cut the generic "marking infraction", which has no signal of
         * its own: the specific ones below each do, and a spotter reading a
         * mark either saw which it was or saw nothing.
         *
         * `seen: false` marks the two that are read from CONSEQUENCE rather
         * than from a signal - whether a call stood is usually learnt by
         * watching where the disc goes next. They are still worth recording;
         * they are not worth recording as if somebody signalled them, so the
         * event carries how it was known.
         *
         * NOT VERIFIED AGAINST THE APPENDIX. This list is from knowledge of
         * the signal set, not from the WFDF Rules of Ultimate appendix in
         * front of me, and `strip` and generic `violation` are the two worth
         * checking before anybody trains a spotter on it.
         */
        call: {
            foul: ['foul'],
            travel: ['travel'],
            pick: ['pick'],
            strip: ['strip'],
            'double team': ['double team'],
            'disc space': ['disc space'],
            'fast count': ['fast count'],
            straddle: ['straddle'],
            'vision blocking': ['vision blocking'],
            // A stall-out is signalled, but it already exists as an OUTCOME:
            // it is the turnover itself, not a call about one. Adding it here
            // gave the same event two ways to be recorded.
            violation: ['violation'],
            contested: ['contested', 'contest'],
            uncontested: ['uncontested'],
            'out of bounds': ['out of bounds'],
            'in bounds': ['in bounds'],
            down: ['down'],
            offside: ['offside'],
            check: ['check', 'disc in', 'tap in', 'tapped in', 'tapped'],
            timeout: ['timeout', 'time out'],
            injury: ['injury', 'injured']
        },
        /*
         * A throw's ending is not known when it is thrown.
         *
         *     "huck" ........ "tipped lang" ........ "reiter"
         *
         * So a call can arrive in pieces: the type at release, a deflection
         * mid-flight, the receiver at the catch, seconds apart. The disc stays
         * IN FLIGHT between them and the UI says so, rather than the page
         * pretending a throw type spoken alone was a complete thought.
         */
        flight: {
            tipped: ['tipped', 'tip', 'deflected', 'touched', 'bobbled']
        },
        control: {
            // Getting it back, which the model needs a word for now that it
            // knows the other team can have it.
            regain: ['ours', 'our disc', 'we have it'],
            /*
             * Picking it up, which is how a possession actually starts and
             * had no word at all. "Tiny picks up" came back not understood.
             *
             * It has to be said in two words, because "picks" on its own is
             * within a hair of the PICK call and would be recorded as an
             * infraction. Two-word terms beat their own halves, so the phrase
             * resolves and the bare word stays the call.
             */
            pickup: ['picks up', 'pick up', 'picked up', 'picks it up'],
            newpoint: ['new point', 'next point'],
            gap: ['missed', 'miss', 'unknown'],
            afk: ['afk', 'stopping', 'pause'],
            undo: ['undo', 'scratch'],
            // Not a correction: a correction says what the truth was, this
            // only says "that was wrong, ask me later". During play there is
            // rarely time for the first.
            flag: ['flag', 'flag that', 'check that', 'review that'],
            // The play clock, which is a fact about the game rather than
            // about the capture. See `playIntervals`.
            live: ['play on', 'live', 'playing'],
            oline: ['o line', 'offence line'],
            dline: ['d line', 'defence line'],
            stopped: ['play stopped', 'stopped', 'dead']
        },
        /*
         * CORRECTIONS ARE A CALL WITH A WORD IN FRONT OF IT.
         *
         *     no <call>        "no, lang"  ·  "no drop reiter"
         *
         * One structure rather than two: whatever follows the correction word
         * is parsed exactly as a fresh call would be, and replaces the last
         * event instead of adding to it. A spotter who has to learn a second
         * grammar under time pressure will not use it.
         *
         * Deliberately no way to reach further back than the last event by
         * voice. "Three ago, Weber" is a sentence somebody gets wrong at
         * speed, and the review queue at the next stoppage is the place where
         * looking back is safe.
         */
        fix: {
            correct: ['no', 'correct', 'fix', 'wrong']
        },
        /*
         * WHO CALLED IT — and the reason this is its own slot rather than two
         * more outcome words.
         *
         * A foul belongs to somebody. From the sideline that is often not
         * visible: no hand signal is made, or two go up at once. So the side
         * is asked for separately and may be left out, or left open.
         *
         * Spelled out on purpose. "O" and "D" are what people say, and "d" is
         * already a block — the commonest call in the vocabulary colliding
         * with the commonest ambiguity is not a trade worth making.
         */
        /*
         * WHICH SIDE, WHICH A TIMEOUT NEEDS AND A FOUL OFTEN DOES.
         *
         * `ours` and `theirs` sit here rather than in the control group with
         * `regain`, because they answer "whose" rather than "what happened".
         * Two words for the possession word - "ours" alone still means we
         * have the disc back, which is a different sentence entirely.
         */
        /*
         * HOW IT WAS THROWN, WHICH IS NOT WHAT WAS THROWN.
         *
         * Backhand and forehand were throw TYPES, which made "backhand huck"
         * two throws - and once run-on utterances started splitting at every
         * second throw, it became two calls. They are a grip: any shape can
         * be thrown either way, and a spotter who knows which should be able
         * to say so without giving up the shape.
         *
         * So they fill their own slot, attach to whatever throw they are
         * said with, and never split. "flick lang" still works and records a
         * forehand with no shape stated, which is exactly what was said.
         */
        grip: {
            backhand: ['backhand'],
            forehand: ['forehand', 'flick'],
            // The weaker hand, which is worth separating rather than lumping
            // in: a team that throws off-hand under pressure is doing
            // something a coach wants to know about.
            offhand: ['offhand', 'off hand', 'weak hand']
        },
        side: {
            offence: ['offence', 'offense', 'attack'],
            defence: ['defence', 'defense'],
            // Bare adjectives, not "our timeout": a two-word alias swallows
            // the word `timeout` and leaves the utterance with no action at
            // all, so "our timeout" resolved to a side and nothing happened.
            us: ['our'],
            // Not "theirs": that is already how a conceded goal is said, and
            // a word cannot mean two things in a closed vocabulary.
            them: ['their']
        },
        /*
         * UNCERTAINTY IS A THING TO SAY, NOT A THING TO INFER.
         *
         * A spotter frequently knows what happened and not who, or sees the
         * outcome and not the cause. The alternative to saying so is guessing,
         * and a guess recorded as an observation is exactly what this project
         * refuses everywhere else. "maybe lang" stores the throw and marks it
         * unsure; "lang or weber" stores both and resolves later.
         */
        doubt: {
            unsure: ['maybe', 'unsure', 'think', 'probably', 'possibly']
        }
    };

    /**
     * Everything a spotter may legally say, right now.
     *
     * "Right now" is load-bearing: the holder is left out, because nobody
     * throws to themselves, which is what makes the live set six rather than
     * seven and the matcher's job that much easier.
     */
    function vocabulary() {
        var out = [];
        line.forEach(function (p, i) {
            /*
             * Everybody, including whoever has the disc.
             *
             * Excluding the holder tightened the match to six — and made
             * "no, Weber" impossible at the exact moment Weber was holding it,
             * which is when a correction is most likely. Nobody throwing to
             * themselves is enforced where the event is created instead, so a
             * stray press still records nothing while the name stays sayable.
             */
            aliasesOf(p).forEach(function (a) {
                out.push({
                    kind: 'player', index: i, value: i, word: a.alias, form: a.form,
                    // A bare shirt number is short enough to resemble half the
                    // vocabulary, so it has to be said exactly.
                    exact: a.alias.replace(/\s/g, '').length <= 2
                });
            });
        });
        Object.keys(SAY).forEach(function (kind) {
            Object.keys(SAY[kind]).forEach(function (term) {
                SAY[kind][term].forEach(function (alias) {
                    out.push({
                        kind: kind, value: term, word: alias,
                        // Too short to fuzz: at one or two characters almost
                        // anything is a near miss.
                        exact: alias.replace(/\s/g, '').length <= 2
                    });
                });
            });
        });
        return out;
    }

    /*
     * Absolute confidence is the wrong test for a vocabulary this small.
     *
     * A closed set turns "is this Lehner?" into "is this more like Lehner than
     * like any of the other five?" — so a mangled word that beats every rival
     * by a distance is a match even at a middling score, while two plausible
     * rivals are a tie at any score.
     */
    var SURE = 0.78;    // good enough on its own
    var MAYBE = 0.5;    // good enough if nothing else is close
    var CLEAR = 0.2;    // ... this far clear of the nearest rival
    var TIE = 0.08;     // closer than this to a rival is not a choice to make

    /** One spoken word (or two-word window) against the whole vocabulary. */
    /**
     * A name anywhere in the squad, for a line call.
     *
     * `matchWord` only knows the players currently on, which is exactly the
     * wrong set when the point of the utterance is to change who is on.
     */
    function matchInSquad(w) {
        var best = null;
        squad.forEach(function (p) {
            aliasesOf(p).forEach(function (a) {
                var sc = score(w, a.alias.replace(/\s/g, ''));
                if (sc >= SURE && (!best || sc > best.score)) { best = { p: p, score: sc }; }
            });
        });

        return best ? best.p : null;
    }

    function matchWord(w) {
        var ranked = [];
        var flat = w.replace(/\s/g, '');
        vocabulary().forEach(function (v) {
            var target = v.word.replace(/\s/g, '');
            var value = v.exact ? (flat === target ? 1 : 0) : score(flat, target);
            if (value > 0) { ranked.push({ score: value, hit: v }); }
        });
        ranked.sort(function (a, b) { return b.score - a.score; });

        /*
         * AN EXACT WORD BEATS A WORD THAT MERELY SOUNDS LIKE IT.
         *
         * The phonetic key is deliberately coarse, and Ultimate's vocabulary
         * has pairs it cannot separate: "break" and "brick" reduce to the
         * same key, as do "pick" and "pause", "flick" and "flag". Scoring
         * spelling and sound and taking the better of the two meant a perfect
         * spelling match tied with a perfect phonetic one, so the tie rule
         * fired and BREAK - a core throw - could not be said at all.
         *
         * "contested" against "uncontested" is the same failure with worse
         * consequences: they are opposites, and which one it was decides who
         * gets the disc.
         *
         * So an exact match is treated as the different class of evidence it
         * is. It wins outright, and only another exact match can rival it.
         * This matters more with a local recogniser than it would otherwise,
         * because a grammar-constrained decoder emits vocabulary words: exact
         * is the normal case, and near-miss is the exception it was built for.
         */
        var exact = ranked.filter(function (r) {
            return r.hit.word.replace(/\s/g, '') === flat;
        });
        if (exact.length) { ranked = exact; }

        var best = ranked[0];
        if (!best) { return null; }
        var rival = 0;
        for (var j = 1; j < ranked.length; j += 1) {
            var r = ranked[j].hit;
            if (!(r.kind === best.hit.kind && r.value === best.hit.value)) {
                rival = ranked[j].score;
                break;
            }
        }
        if (best.score >= SURE && best.score - rival < TIE) {
            return { ambiguous: true, score: best.score };
        }
        if (best.score >= SURE || (best.score >= MAYBE && best.score - rival >= CLEAR)) {
            return { hit: best.hit, score: best.score };
        }
        return null;
    }

    /**
     * THE CALL GRAMMAR — one utterance is one event, in two slots.
     *
     *     ACTION   WHO
     *     ──────   ───
     *     [throw]  receiver        "huck lang"  ·  "lang"
     *     outcome  [player]        "drop reiter"  ·  "goal"
     *     call     [player]        "foul weber"
     *     control                  "missed"  ·  "undo"
     *
     * Defining it is what makes the rest tractable. Word-spotting asked "does
     * any word here resemble anything I know", which matched ordinary speech
     * and invented events; parsing asks "does this utterance fit the shape a
     * call has", and an utterance that does not fit is a question for the
     * spotter rather than a guess. The slots are also what the UI draws, so
     * the person speaking can see the structure they are speaking into.
     *
     * Order is not enforced. "lang huck" and "huck lang" are the same call,
     * because a spotter under pressure will say them in whichever order the
     * play produced, and the two slots take different kinds of word anyway.
     */
    var MAX_WORDS = 5;
    /** Loose, because filler is free; only prose gets this long. */
    var MAX_SPOKEN = 8;

    /** Not calls in their own right: how one that was made turned out. */
    var RESOLUTION = { contested: 1, uncontested: 1 };

    /*
     * Words a spotter says to be understood by a person, which this does not
     * need: "tipped BY Lang, caught BY Reiter". Dropped before parsing rather
     * than counted as unresolved, because a leftover list full of "by" teaches
     * nobody anything — and the length limit should measure the call, not the
     * politeness around it.
     */
    /*
     * `up` came out: it is the whole second half of "picks up", and dropping
     * it left "picks", which is the PICK call with a different ending. The
     * phrase it was there for - "caught up" - is already covered by ignoring
     * "caught".
     */
    var IGNORE = ['to', 'by', 'the', 'and', 'a', 'it', 'is', 'was', 'then',
                  'caught', 'catches', 'catch',
                  // Said without meaning anything by them. Nothing here can
                  // be part of a term: "in" stays out because of "disc in",
                  // "pull in" and "in bounds".
                  'that', 'there', 'now', 'please', 'ok', 'okay', 'goes', 'went'];

    /** Not ignored: it is the whole of the option grammar. */
    var OR = 'or';

    function parseCall(said) {
        var spoken = String(said).toLowerCase().replace(/[^a-zà-ÿ0-9\s]/g, ' ')
            .split(/\s+/).filter(Boolean);
        /*
         * "TWO" BEFORE A NAME IS THE WORD "TO".
         *
         * English hands us this one: "hammer to mo" comes back as "hammer
         * two mo", and two is a shirt number, so the call named two players
         * and was refused for it. The disambiguation is positional - a shirt
         * number is the thing being thrown TO, so it does not itself precede
         * a name. Only dropped when a name follows, so "huck two" still
         * means the player wearing 2.
         */
        spoken = spoken.filter(function (w, i) {
            if (w !== 'two' || i + 1 >= spoken.length) { return true; }
            return !matchInSquad(spoken[i + 1]);
        });
        var words = spoken.filter(function (w) { return IGNORE.indexOf(w) === -1; });
        var out = { heard: said, action: null, who: null, side: null,
                    options: {}, unresolved: [], ambiguous: false };
        var hasOr = words.indexOf(OR) !== -1;
        words = words.filter(function (w) { return w !== OR; });

        if (!words.length) { return out; }

        /*
         * "line ace hawk speedy bear rocket" - read before the length guard.
         *
         * A line call is the one utterance that is legitimately as long as
         * the line is, so the guard that keeps prose out would refuse every
         * one of them. It is safe to exempt because it is unambiguous: the
         * keyword is first, and everything after it can only be a name.
         */
        /*
         * Which side is being named, said however the spotter says it.
         *
         * "o line", "offence line", "team o line" - and the same three for
         * defence. A neutral spotter calls two lines at every point, so the
         * side is not optional; without it the second call would silently
         * replace the first.
         */
        var named = null;
        var from = 0;
        /*
         * SLOTS ARE FIXED, OFFENCE AND DEFENCE ARE NOT.
         *
         * `o` and `d` name the two teams and never move. `offence` and
         * `defence` name a ROLE that swaps at every point, so they resolve
         * through `starting` - and mapping them to the slots of the same
         * letter, as this did, made "offence line" mean Team O for ever.
         * It refused the defending team's own line on every other point.
         */
        var SIDE_WORDS = { o: 'O', d: 'D',
                           offence: starting, offense: starting,
                           defence: other(starting), defense: other(starting) };
        if (words[0] === 'team' && words.length > 1) { words = words.slice(1); }
        if (SIDE_WORDS[words[0]] && /^(line|lines)$/.test(words[1] || '')) {
            named = SIDE_WORDS[words[0]];
            from = 2;
        } else if (/^(line|lines)$/.test(words[0])) {
            from = 1;
        } else if (SIDE_WORDS[words[0]] && words.length > 1
            && words.slice(1).every(function (w) { return Boolean(matchInSquad(w)); })) {
            /*
             * The side word without "line", which is how it usually arrives.
             *
             * "o line ace hawk speedy" comes back as "o tiny" often enough
             * that it is the normal case rather than the exception - and with
             * no keyword it read as a throw to Tiny, which is a fabricated
             * event at the top of a point and poisons everything after it.
             *
             * Safe because of what follows: a side word trailed by nothing
             * but squad names is a line being named. A side word followed by
             * anything else still is not.
             */
            named = SIDE_WORDS[words[0]];
            from = 1;
        }

        /*
         * A run of squad names with no keyword is a line call.
         *
         * "tiny mo brandy kovac flash" arrived with the "d line" lost, and
         * every name in it belonged to somebody off the field - which is
         * true, and describes a line being called rather than a mistake.
         * Three is the floor: two names is a throw and a correction, five is
         * unmistakable.
         */
        /*
         * ONE PLAYER ON OR OFF, WHICH IS WHAT A SUBSTITUTION ACTUALLY IS.
         *
         * Calling a whole line is five names and four pauses, and the
         * recogniser loses the keyword often enough that "o line ace hawk..."
         * comes back as "o tiny" - which looks exactly like a throw. A line
         * that is nearly right does not need saying again; it needs one word.
         *
         *     bear on          put Bear on, for the side Bear belongs to
         *     wags off         take Wags off
         *     bear for wags    both at once
         *
         * Read before everything else because the words are otherwise
         * ordinary: "on" and "off" mean nothing on their own, and a name
         * beside them is unambiguous in a way a bare name never is.
         */
        if (words.length >= 2 && words.length <= 4) {
            var subOn = null;
            var subOff = null;
            if (/^(on|in)$/.test(words[words.length - 1])) {
                subOn = matchInSquad(words[words.length - 2]);
            } else if (/^(off|out)$/.test(words[words.length - 1])) {
                subOff = matchInSquad(words[words.length - 2]);
            } else if (/^(on|in)$/.test(words[0])) {
                /*
                 * Said the other way round, which is how it comes out.
                 *
                 * "tiny on" and "on tiny" are the same sentence in a hurry,
                 * and only the first was accepted - so the second was read
                 * as a bare name and refused for being on the other side,
                 * which is both true and beside the point.
                 */
                subOn = matchInSquad(words[1]);
            } else if (/^(off|out)$/.test(words[0])) {
                subOff = matchInSquad(words[1]);
            } else if (words.length >= 3 && words[1] === 'for') {
                subOn = matchInSquad(words[0]);
                subOff = matchInSquad(words[2]);
            }
            if (subOn || subOff) {
                out.subOn = subOn;
                out.subOff = subOff;
                out.ok = true;
                return out;
            }
        }

        if (!from && words.length >= 3) {
            var run = [];
            var allNames = words.every(function (w) {
                var p = matchInSquad(w);
                if (p && run.indexOf(p) === -1) { run.push(p); }
                return Boolean(p);
            });
            var offField = allNames && run.every(function (p) {
                return !line.some(function (q) { return q.label === p.label; });
            });
            if (allNames && offField && run.length >= 3) {
                out.lineCall = run.slice(0, lineSize);
                out.lineSide = null;
                out.ok = true;
                return out;
            }
        }

        if (from) {
            out.lineCall = [];
            out.lineSide = named;
            words.slice(from, from + lineSize).forEach(function (w) {
                var p = matchInSquad(w);
                if (p && out.lineCall.indexOf(p) === -1) { out.lineCall.push(p); }
                else if (!p) { out.unresolved.push(w); }
            });
            // A keyword with no names is still a line call - it is the
            // start of one, and the names follow in the next breath.
            out.ok = out.lineCall.length > 0 || named !== null;
            if (!out.ok) { out.why = 'no names understood in that line'; }
            return out;
        }
        /*
         * MEASURED ON THE WHOLE SENTENCE, NOT ON WHAT SURVIVED THE FILTER.
         *
         * The length guard ran after the ignore list, so "Weber picks up the
         * disc takes it to the brick" - nine words - came down to five and
         * was applied. It recorded a DUMP TO WEBER, because "disc" and the
         * dump alias "dish" reduce to the same consonant key and scored a
         * perfect match. A fabricated pass from a sentence nobody meant as a
         * call, which is the one failure this whole design refuses.
         *
         * A spotter's call is two or three words. A sentence is not a call,
         * however many of its words are ignorable.
         *
         * TWO LIMITS, BECAUSE FILLER MUST BE FREE.
         *
         * Counting the raw sentence against the same limit made "to" and
         * "the" cost something, so saying "huck it to the weber" - which is
         * how people actually speak - could be refused for length. Filler is
         * meant to be harmless. So the MEANINGFUL words carry the tight
         * limit, and the raw sentence carries a loose one that only a real
         * sentence can exceed.
         */
        if (spoken.length > MAX_SPOKEN || words.length > MAX_WORDS) {
            out.why = 'too long to apply unasked';
            out.unresolved = words;
            return out;
        }

        /*
         * Two-word windows first, so a name the recogniser split ("lane her")
         * is read before its halves are mistaken for something else — but only
         * when the window explains those words BETTER THAN THEY EXPLAIN
         * THEMSELVES.
         *
         * Without that test a window eats its own words: "huck lang" sounded
         * enough like the player Nico Lang to swallow the throw type, and
         * "no lang" lost the correction the same way. Both words already had
         * a perfect single-word reading, so the window has to beat them.
         */
        var taken = {};
        var singles = words.map(function (w) {
            var m = matchWord(w);
            return m && m.hit ? m.score : 0;
        });
        var tries = [];
        // Longest first, down to single words: "out of bounds" is three, and
        // a two-word-only pass left every longer term unreachable.
        [3, 2].forEach(function (width) {
            for (var i = 0; i + width <= words.length; i += 1) {
                var span = [];
                var beat = 0;
                for (var k = 0; k < width; k += 1) {
                    span.push(i + k);
                    beat = Math.max(beat, singles[i + k]);
                }
                tries.push({ text: words.slice(i, i + width).join(' '), span: span, beat: beat });
            }
        });
        words.forEach(function (w, i) { tries.push({ text: w, span: [i], beat: 0 }); });

        tries.forEach(function (t) {
            if (t.span.some(function (i) { return taken[i]; })) { return; }
            var m = matchWord(t.text);
            if (!m) { return; }
            /*
             * A two-word window has to be convincing on its own, because it
             * consumes both words and the relative rule is too generous when
             * the rivals are single words it would otherwise have matched
             * separately: "huck lang" scored well enough against the player
             * "Nico Lang" to swallow the throw type whole. A genuine split
             * name clears this easily — "lane her" sounds exactly like
             * Lehner.
             */
            // At least as good, not strictly better: a two-word term explains
            // more of the utterance than its halves do, so "hand block" should
            // win a tie against the "block" inside it. Strictly-better made
            // every multi-word term unreachable.
            if (t.span.length > 1 && !(m.score >= SURE && m.score >= t.beat)) { return; }
            if (m.ambiguous) {
                /*
                 * An ambiguous WINDOW does not get to eat certain words.
                 *
                 * "foul hawk" has no exact match as a pair, so it fell to the
                 * phonetic score, tied, and consumed both halves - losing a
                 * call and a player that each resolve perfectly on their own.
                 * A window only earns its words by explaining them better
                 * than they explain themselves, and an ambiguity explains
                 * nothing.
                 */
                if (t.span.length > 1 && t.beat > 0) { return; }
                out.ambiguous = true;
                t.span.forEach(function (i) { taken[i] = true; });
                return;
            }

            if (m.hit.kind === 'fix') {
                out.correct = true;
                t.span.forEach(function (i) { taken[i] = true; });
                return;
            }
            if (m.hit.kind === 'doubt') {
                out.unsure = true;
                t.span.forEach(function (i) { taken[i] = true; });
                return;
            }

            if (m.hit.kind === 'grip') {
                out.grip = m.hit.value;
                t.span.forEach(function (i) { taken[i] = true; });
                return;
            }

            var slot = m.hit.kind === 'player' ? 'who'
                : m.hit.kind === 'side' ? 'side' : 'action';
            var entry = { kind: m.hit.kind, value: m.hit.value, word: m.hit.word,
                          form: m.hit.form, score: m.score };

            if (out[slot]) {
                /*
                 * A SECOND NAME ON A CALL IS WHO IT IS AGAINST.
                 *
                 * "foul weber lang" - Weber called it, on Lang. Optional
                 * everywhere, because a spotter frequently sees the signal
                 * and not the pair, and a call with one name or none is
                 * still worth having.
                 *
                 * It is also the only thing that makes a call countable
                 * between two people, which is what turns a list of
                 * infractions into something worth saying out loud: the
                 * third one between the same two players in a point is a
                 * story, and three unrelated fouls are not.
                 */
                if (slot === 'who' && out.action && out.action.kind === 'call'
                    && !out.against) {
                    out.against = entry;
                    t.span.forEach(function (i) { taken[i] = true; });
                    return;
                }
                /*
                 * A THROW AND AN OUTCOME ARE ONE EVENT, NOT A COLLISION.
                 *
                 * "huck weber drop" is an ordinary thing to say - a huck to
                 * Weber that Weber put down - and it filled the action slot
                 * with the huck and then silently discarded the drop. The
                 * turnover simply never happened in the data.
                 *
                 * The outcome is what the event IS; the throw is how it was
                 * delivered, so it moves to its own field and both survive.
                 */
                if (slot === 'action' && entry.kind === 'outcome' && out.action.kind === 'throw') {
                    out.throwType = out.action.value;
                    out.action = entry;
                    t.span.forEach(function (i) { taken[i] = true; });
                    return;
                }
                if (slot === 'action' && entry.kind === 'throw' && out.action.kind === 'outcome') {
                    out.throwType = entry.value;
                    t.span.forEach(function (i) { taken[i] = true; });
                    return;
                }
                /*
                 * A CALL AND HOW IT RESOLVED, SAID TOGETHER.
                 *
                 * "pick call uncontested" is one thing that happened, and it
                 * filled the slot with the pick and discarded the resolution
                 * - so the log said a pick was called and never said whether
                 * it stood. Contested and uncontested are resolutions rather
                 * than calls of their own, so they attach.
                 */
                if (slot === 'action' && entry.kind === 'call' && out.action.kind === 'call') {
                    var a = out.action.value;
                    var bv = entry.value;
                    if (RESOLUTION[bv] && !RESOLUTION[a]) {
                        out.resolved = bv;
                        t.span.forEach(function (i) { taken[i] = true; });
                        return;
                    }
                    if (RESOLUTION[a] && !RESOLUTION[bv]) {
                        out.resolved = a;
                        out.action = entry;
                        t.span.forEach(function (i) { taken[i] = true; });
                        return;
                    }
                }
                // A slot is filled once — unless the spotter offered a choice,
                // in which case both answers are kept and neither is picked.
                if (hasOr) { out.options[slot] = (out.options[slot] || [out[slot]]).concat([entry]); }
                t.span.forEach(function (i) { taken[i] = true; });
                return;
            }
            out[slot] = entry;
            t.span.forEach(function (i) { taken[i] = true; });
        });

        words.forEach(function (w, i) { if (!taken[i]) { out.unresolved.push(w); } });

        /*
         * A name the page knows, belonging to somebody who is not on.
         *
         * Only asked of words nothing else claimed, and only when the line is
         * a real subset of the squad. It turns "no word understood" - true
         * and useless - into the two things it actually means: a substitution
         * nobody recorded, or the wrong name heard.
         */
        if (out.who === null && out.unresolved.length && squad.length > line.length) {
            var off = squad.filter(function (p) {
                return !line.some(function (q) { return q.label === p.label; });
            });
            out.unresolved.forEach(function (w) {
                if (out.offLine) { return; }
                off.forEach(function (p) {
                    if (out.offLine) { return; }
                    aliasesOf(p).forEach(function (a) {
                        if (!out.offLine && score(w, a.alias.replace(/\s/g, '')) >= SURE) {
                            out.offLine = p.label;
                        }
                    });
                });
            });
        }

        out.ok = Boolean(out.action || out.who !== null || out.grip);
        out.open = Object.keys(out.options).length > 0;

        return out;
    }

    /**
     * An attempt against a reference — which is training and measurement at
     * once, and that is not a coincidence.
     *
     * Somebody tags a game carefully, with no clock running: pause, rewind,
     * argue about it. That capture becomes ground truth. A spotter then
     * narrates the same footage live and this says what they got wrong —
     * which is the only honest way to learn a grammar, and simultaneously the
     * per-class accuracy figure §10a demands before any of this reaches air.
     * The trainee's mistakes and the system's error rate are the same list.
     *
     * FOUR OUTCOMES, AND THE THIRD IS THE ONE THAT MATTERS
     *
     *   matched    right event, right player, within the window
     *   wrongWho   right moment, wrong name — the attribution failure
     *   missed     in the reference, absent from the attempt
     *   invented   in the attempt, absent from the reference
     *
     * A miss the spotter FLAGGED — pressed "missed", said so — is counted
     * apart from one they never noticed. Knowing you missed something is a
     * different skill from not missing it, and the coverage rules depend on
     * the first far more than the second.
     *
     * Lag is reported because it is the throughput question made numeric: not
     * "did they get it" but "how far behind the play were they".
     */
    var TOL = 3;   // seconds either side; a call is not simultaneous with a catch

    /**
     * Two names for the same person.
     *
     * A reference tagged "Hana Lehner" against a line typed as "Lehner" scored
     * every single event as the wrong player — a comparison measuring
     * transcription conventions rather than spotting.
     */
    function sameWho(a, b) {
        var norm = function (x) { return String(x || '').toLowerCase().trim(); };
        if (norm(a) === norm(b)) { return true; }
        var last = function (x) { return norm(x).split(/\s+/).pop(); };
        return Boolean(norm(a) && norm(b) && last(a) === last(b));
    }

    function scoreAttempt(reference, attempt) {
        var real = function (e) {
            return e.type === 'throw' || e.type === 'turnover' || e.type === 'goal';
        };
        var ref = reference.filter(real).slice().sort(function (a, b) { return a.at - b.at; });
        var mine = attempt.filter(real).slice().sort(function (a, b) { return a.at - b.at; });
        var flags = attempt.filter(function (e) { return e.type === 'gap'; });
        /*
         * Declared blind spots. An event inside a window the spotter marked
         * AFK is not something they missed — it is something nobody was
         * watching for, and the coverage rules turn that into "no data here"
         * rather than "nothing happened". Scoring it as a miss would punish
         * the honesty the whole design depends on.
         */
        var blind = attempt.filter(function (e) {
            return e.type === 'afk' && e.until;
        }).map(function (e) { return [e.at, e.until]; });
        var unwatched = function (t) {
            return blind.some(function (w) { return t >= w[0] && t <= w[1]; });
        };

        var used = {};
        var out = { matched: [], wrongWho: [], wrongType: [], missed: [],
                    invented: [], uncovered: [], lags: [] };

        ref.forEach(function (r) {
            if (unwatched(r.at)) { out.uncovered.push({ at: r.at, what: r.type }); return; }

            var pick = null;
            var bestGap = TOL + 1;
            mine.forEach(function (m, i) {
                // Nearest in time of ANY kind: recording a throw as a turnover
                // is one mistake, and counting it as a miss plus an invention
                // punished it twice and named it wrongly.
                if (used[i]) { return; }
                var gap = Math.abs(m.at - r.at);
                if (gap < bestGap) { bestGap = gap; pick = { m: m, i: i }; }
            });
            if (!pick) {
                var flagged = flags.some(function (f) { return Math.abs(f.at - r.at) <= TOL; });
                out.missed.push({ at: r.at, what: r.type, who: r.to || r.by, flagged: flagged });
                return;
            }
            used[pick.i] = true;
            out.lags.push(pick.m.at - r.at);
            var entry = {
                at: r.at, what: r.type, got: pick.m.to || pick.m.by,
                expected: r.to || r.by, gotType: pick.m.type,
                lag: Math.round((pick.m.at - r.at) * 10) / 10
            };
            if (pick.m.type !== r.type) { out.wrongType.push(entry); return; }
            (sameWho(r.to || r.by, pick.m.to || pick.m.by) ? out.matched : out.wrongWho).push(entry);
        });

        mine.forEach(function (m, i) {
            if (!used[i] && !unwatched(m.at)) {
                out.invented.push({ at: m.at, what: m.type, who: m.to || m.by });
            }
        });

        var sorted = out.lags.slice().sort(function (a, b) { return a - b; });
        out.medianLag = sorted.length
            ? Math.round(sorted[Math.floor(sorted.length / 2)] * 10) / 10 : null;
        out.total = ref.length;
        // Scored against what was watchable, not against what happened: a
        // spotter who declared themselves away is not marked down for it.
        out.scored = ref.length - out.uncovered.length;
        out.accuracy = out.scored
            ? Math.round((out.matched.length / out.scored) * 100) : null;

        return out;
    }

    /** Client-generated, as §10a settled: safe to retry from anywhere. */
    function id() { return Date.now().toString(36) + Math.random().toString(36).slice(2, 8); }

    /**
     * IS THIS EVEN POSSIBLE? ASKED OF EVERY EVENT BEFORE IT IS BELIEVED.
     *
     * A grammar-constrained recogniser always returns something legal, so
     * legality is no evidence at all. What IS evidence is the sport: a pull
     * cannot happen while the disc is live, nobody throws it away when
     * nobody is holding it, a goal cannot be scored at a dead disc. Each of
     * those is a rule the data can check against itself, and each one turns
     * a whole class of mis-recognition from invisible into a question.
     *
     * Nothing is refused here - the event is recorded and MARKED. A refusal
     * loses what the spotter said; a mark keeps it and admits the doubt,
     * which is the same trade the review queue makes everywhere else.
     *
     * Kept deliberately shy. A rule that fires on a legitimate sequence
     * teaches a spotter to ignore the queue, and an ignored queue is worth
     * less than no queue.
     */
    function implausible(type, e) {
        if (type === 'pull' && inPlay) {
            return 'a pull while the disc was already live';
        }
        if (type === 'goal' && !inPlay && events.length) {
            return 'a goal while the disc was dead';
        }
        if (type === 'turnover' && holder === null && possession === 'us'
            && e.how !== 'uncalled') {
            return 'a turnover with nobody holding the disc';
        }
        if (type === 'conceded' && possession === 'us' && holder !== null) {
            return 'they scored while we had the disc';
        }
        if (type === 'throw' && e.to && e.from && e.to === e.from) {
            return 'thrown to the player who threw it';
        }
        return null;
    }

    function push(type, extra) {
        var e = { id: id(), point: point, at: Math.round(at() * 10) / 10, type: type,
                  source: 'key', audioAt: heardAt ? heardAt.start : audioOffset() };
        if (heardAt) {
            e.audioEnd = heardAt.end;
            if (heardAt.conf !== undefined) { e.conf = Math.round(heardAt.conf * 100) / 100; }
        }
        Object.keys(extra || {}).forEach(function (k) { e[k] = extra[k]; });

        // Checked after the caller's fields are on, because most of the rules
        // are about them, and before the event is believed by anything else.
        var doubt = implausible(type, e);
        if (doubt && e.certain !== false) {
            e.certain = false;
            e.why = doubt;
            e.implausible = true;
        }
        /*
         * Insertion order, deliberately. Sorting by video time meant that
         * rewinding to re-spot a point interleaved the new events with the old
         * ones, and undo then removed whatever happened to be chronologically
         * last rather than the thing just entered. Display sorts; the log
         * remembers what happened in what order.
         */
        events.push(e);
        playFromEvent(e);
        render();
    }

    // ---- the video ---------------------------------------------------------
    function videoId(s) {
        var m = /(?:v=|\/live\/|youtu\.be\/|\/embed\/)([A-Za-z0-9_-]{6,})/.exec(s);
        return m ? m[1] : s.trim();
    }

    window.onYouTubeIframeAPIReady = function () { /* set up on demand */ };

    el('load').addEventListener('click', function () {
        var v = videoId(el('url').value);
        if (!v) { return; }
        if (player && player.loadVideoById) { player.loadVideoById(v); return; }
        player = new YT.Player('player', {
            videoId: v,
            playerVars: { rel: 0, modestbranding: 1 },
            events: {
                // Focus back to the page: with focus inside the iframe every
                // keystroke goes to YouTube's own shortcuts and nothing is
                // captured, which looks exactly like a person who cannot keep up.
                onStateChange: function () { window.focus(); document.body.focus(); }
            }
        });
    });

    // ---- modes -------------------------------------------------------------
    function setMode(m, adopt) {
        // Anything else came from a URL somebody typed.
        if (m !== 'training') { m = 'live'; }
        /*
         * `adopt` is the load path taking on the mode a restored session was
         * already in. That is not a switch and must not clear: the initial
         * value of MODE is a default nobody chose, and treating the restored
         * mode as a change from it threw the session away on every reload.
         */
        if (adopt === true) {
            MODE = m;
            document.body.dataset.mode = m;
            el('modeLive').className = m === 'live' ? 'on' : '';
            el('modeTrain').className = m === 'training' ? 'on' : '';
            if (m === 'live' && liveFrom === null) { liveFrom = Date.now(); }
            el('blurb').textContent = BLURB[m];
            el('blurb').hidden = !BLURB[m];
            render();
            return;
        }
        if (m === MODE) { return; }
        var real = events.filter(function (e) { return e.type !== 'point'; }).length;
        if (real && !window.confirm(
            'Switching clocks cannot convert ' + real + ' captured events.\n\n'
            + 'Save first if you want them. Switch and clear?'
        )) { return; }
        var changed = true;
        MODE = m;
        document.body.dataset.mode = m;
        el('modeLive').className = m === 'live' ? 'on' : '';
        el('modeTrain').className = m === 'training' ? 'on' : '';
        // After MODE is set, so a fresh clock belongs to the mode being
        // switched TO rather than the one being left.
        if (changed) { wipe(true); }
        if (m === 'live' && liveFrom === null) { liveFrom = Date.now(); }
        // `|| BLURB.live` would resurrect the live line for training, whose
        // text is deliberately empty now that it lives in the instructions.
        // Only an UNKNOWN mode falls back.
        el('blurb').textContent = BLURB[m] === undefined ? BLURB.live : BLURB[m];
        el('blurb').hidden = !el('blurb').textContent;
        /*
         * The grammar folds away in training, because there the video is what
         * needs the height and the spotter is watching it rather than reading
         * the shapes. Only when the spotter has not said otherwise: a panel
         * that reopens itself every time you switch is worse than one that
         * takes a tap.
         */
        var struct = el('structure');
        if (struct && !structTouched) {
            structSetting = true;
            struct.open = m !== 'training';
            structSetting = false;
        }
        render();
    }

    /**
     * A phone that went to sleep did not stop the game.
     *
     * Backgrounding a tab suspends it — reliably on iOS, sometimes on Android
     * even with the microphone open — and the failure is silent: the clock
     * jumps, no audio was recognised, and the gap looks exactly like a
     * stretch where nothing happened. That is the one reading this project
     * refuses, so a suspension is recorded as AFK: not missing minutes, but
     * DECLARED missing minutes.
     *
     * The threshold is generous because a brief hide (a notification, a
     * glance) does not cost anything worth declaring.
     */
    var SUSPEND_FLOOR = 4;
    var hidAt = null;

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') { hidAt = at(); return; }
        keepAwake();
        if (hidAt === null) { return; }
        var gap = at() - hidAt;
        var from = hidAt;
        hidAt = null;
        if (gap < SUSPEND_FLOOR || MODE !== 'live') { return; }
        events.push({ id: id(), point: point, at: Math.round(from * 10) / 10,
                      type: 'afk', until: Math.round(at() * 10) / 10,
                      why: 'the page was in the background' });
        render();
    });

    /**
     * Keep the page alive, which is the whole prerequisite for pocket use.
     *
     * Not supported everywhere and not a guarantee anywhere — the banner in
     * `#awake` reports what actually happened rather than what was asked for,
     * because a spotter needs to know before the half, not after.
     */
    var wakeLock = null;

    function keepAwake() {
        if (!navigator.wakeLock || wakeLock) { return; }
        navigator.wakeLock.request('screen').then(function (l) {
            wakeLock = l;
            l.addEventListener('release', function () { wakeLock = null; });
            el('awake').textContent = 'screen held awake';
        }, function () {
            el('awake').textContent = 'screen may sleep — keep the page in front';
        });
    }

    setInterval(function () {
        el('clock').textContent = mmss(at());
        el('focuswarn').textContent = MODE === 'training' && document.activeElement
            && document.activeElement.tagName === 'IFRAME'
            ? 'click outside the video — keys are going to YouTube' : '';
        var p = el('playState');
        p.textContent = inPlay ? 'LIVE' : 'stopped';
        p.className = 'playstate ' + (inPlay ? 'live' : 'dead');
        el('playToggle').querySelector('span').textContent = inPlay ? 'stop clock' : 'play on';

    }, 250);

    // ---- the line ----------------------------------------------------------
    /**
     * A player is four names and a number, not a string.
     *
     * A spotter says whichever is shortest and unambiguous in the moment —
     * a surname, a first name, a nickname, or a shirt number — and which one
     * that is changes with who else is on. So every form is in the vocabulary
     * and the collisions are resolved from what is already known: only seven
     * are on, one of them is holding the disc, and the other team's possession
     * is a different set entirely. What survives that is offered as an option
     * to settle rather than guessed.
     */
    var DIGIT = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven',
                 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen',
                 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen', 'twenty'];

    var TENS = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty',
                'seventy', 'eighty', 'ninety'];

    function spokenNumber(n) {
        var v = Number(n);
        if (!isFinite(v) || v < 0) { return []; }
        if (v <= 20) { return [String(v), DIGIT[v]]; }
        // Both spellings, because both are said: "twenty three" and, at a
        // sideline, the quicker "two three".
        var tens = Math.floor(v / 10);
        var ones = v % 10;
        var out = [String(v)];
        if (TENS[tens]) {
            out.push(ones ? TENS[tens] + ' ' + DIGIT[ones] : TENS[tens]);
        }
        if (DIGIT[tens] && DIGIT[ones]) { out.push(DIGIT[tens] + ' ' + DIGIT[ones]); }
        return out;
    }

    function asPlayer(entry) {
        if (entry && typeof entry === 'object') {
            var first = entry.firstname || entry.first || '';
            var last = entry.lastname || entry.last || '';
            var whole = (entry.name || (first + ' ' + last)).trim();
            return {
                label: whole || last || first,
                first: first, last: last,
                nick: entry.nickname || entry.nick || '',
                num: entry.num === 0 || entry.num ? String(entry.num) : '',
                // O line, D line, or neither stated. Squads are built this way
                // and the line that goes out depends on which it is, so a
                // picker that cannot express it makes the spotter do the
                // sorting seven times a point.
                role: /^[od]$/i.test(String(entry.role || entry.line || ''))
                    ? String(entry.role || entry.line).toUpperCase() : '',
                /*
                 * UltiOrganizer's own player id, when the squad came from
                 * somewhere that knows it.
                 *
                 * It is the ONLY key the commentary desk's notes can be joined
                 * on - `shared/notes.php` files everything under a player id -
                 * so a squad without ids cannot inherit anything the desk
                 * typed, however well the names match. Number-and-name joins
                 * were not attempted: two teams share most numbers.
                 */
                id: entry.id === 0 || entry.id ? String(entry.id) : '',
                // Mixed only, and nothing upstream records it, so it survives
                // only if whatever built the squad said it. See
                // MATCHCONTROL.md section 10a.
                matching: /^(MMP|FMP)$/i.test(String(entry.matching || ''))
                    ? String(entry.matching).toUpperCase() : ''
            };
        }
        // "8 Ari Ace" or "Ari Ace" typed by hand.
        var text = String(entry || '').trim();
        var m = /^(\d{1,2})\s+(.*)$/.exec(text);
        var num = m ? m[1] : '';
        var words = (m ? m[2] : text).split(/\s+/).filter(Boolean);
        return {
            label: (m ? m[2] : text),
            first: words[0] || '',
            last: words.slice(1).join(' '),
            nick: '',
            num: num
        };
    }

    /**
     * Load a squad. Two operations, and merging them was a bug.
     *
     * `setSquad` replaces who is AVAILABLE and keeps whoever is already on
     * the field if they are still in it, so importing a fuller roster
     * mid-game does not silently change the line.
     *
     * `setLine` replaces who is ON, which is what starting a point does. The
     * first version did the keep-whoever-is-on merge for both, so a new point
     * whose line dropped one player and added another kept the six survivors
     * and never put the newcomer on. The test that caught it is the one about
     * a player subbed on getting their own minutes.
     */
    function setSquad(entries) {
        var all = entries.map(asPlayer);
        var keep = line.filter(function (p) {
            return all.some(function (q) { return q.label === p.label; });
        });
        squad = all;
        // The first seven of a squad is four from one line and three from the
        // other, which is neither. Prefer the group that matches the point.
        var preset = all.filter(function (p) { return p.role === starting; });
        line = keep.length ? keep
            : (preset.length ? preset.slice(0, lineSize) : all.slice(0, lineSize));
        holder = null;
        render();
        rebuildGrammar();
    }

    /**
     * Who is on, per side, with `line` the union of both.
     *
     * A single spotter covering both teams needs every name on the field in
     * the vocabulary at once, and `line` is what the vocabulary and the keys
     * are built from - so it holds all ten, and each side is tracked
     * separately so a line call can replace one without touching the other.
     */
    var sides = { O: [], D: [] };

    function rebuildLine() {
        line = sides.O.concat(sides.D);
        holder = null;
        render();
        rebuildGrammar();
    }

    /** One side's five, named. The other side is left exactly as it was. */
    function setSide(which, entries) {
        sides[which] = entries.slice(0, lineSize).map(asPlayer);
        sides[which].forEach(function (p) {
            if (!p.role) { p.role = which; }
            if (!squad.some(function (q) { return q.label === p.label; })) { squad.push(p); }
        });
        rebuildLine();
    }

    function setLine(entries) {
        /*
         * Routed through `sides` when the players say which side they are.
         *
         * This assigned `line` directly, so the two went out of step: a point
         * preset filled `line` with one side, then the next line call rebuilt
         * `line` from `sides` and wiped it. With a neutral spotter that meant
         * the other team was simply not on the field, and "pull tiny" came
         * out as "pull by -".
         */
        var players = entries.slice(0, lineSize).map(asPlayer);
        var votes = { O: 0, D: 0 };
        players.forEach(function (p) { if (p.role) { votes[p.role] += 1; } });
        if (votes.O !== votes.D) {
            setSide(votes.O > votes.D ? 'O' : 'D', players);
            return;
        }
        line = players;
        // Anybody named for a line is in the squad by definition, so the
        // picker keeps showing them rather than a line with no bench.
        line.forEach(function (p) {
            if (!squad.some(function (q) { return q.label === p.label; })) { squad.push(p); }
        });
        holder = null;
        render();
        rebuildGrammar();
    }

    /**
     * On or off the line, which is the picker's whole job.
     *
     * Order is preserved rather than re-sorted: the keys 1-7 address the line
     * by position, and a substitution that renumbered everybody would move
     * every key under the spotter's fingers mid-game.
     */
    function toggleInLine(who) {
        var at = -1;
        line.forEach(function (p, i) { if (p.label === who.label) { at = i; } });
        if (at !== -1) {
            line.splice(at, 1);
            if (holder !== null) { holder = null; }
        } else {
            if (line.length >= lineSize) { return; }
            line.push(who);
        }
        render();
        rebuildGrammar();
    }

    /**
     * Every way somebody on the line might be named, tagged with which way.
     *
     * The form is carried through to the tally, because a spotter who can see
     * that surnames are failing and numbers are landing can simply switch —
     * every form is always live, so changing strategy needs no setting, only
     * the evidence that it is worth doing.
     */
    function aliasesOf(p) {
        var out = [];
        var add = function (a, form) {
            if (!a || String(a).trim().length < 2) { return; }
            out.push({ alias: String(a).toLowerCase().trim(), form: form });
        };
        add(p.label, 'full');
        add(p.first, 'first');
        add(p.last, 'last');
        add(p.nick, 'nickname');
        /*
         * The name this page was told to call them, which has to be sayable.
         *
         * A calling name that only changes the chip is worse than none: the
         * spotter reads it, says it, and the grammar has never heard of it.
         * It goes in the vocabulary like every other form.
         */
        add(p.call, 'called');
        spokenNumber(p.num).forEach(function (a) { out.push({ alias: a, form: 'number' }); });

        var seen = {};
        return out.filter(function (e) {
            if (seen[e.alias]) { return false; }
            seen[e.alias] = true;
            return true;
        });
    }

    /*
     * The typed line went with the picker.
     *
     * "seven names, comma separated" was how a squad got in when this was a
     * single file with no installation behind it. The rosters come from the
     * event now, the picker puts them on, and a line can be called by voice -
     * so the box was a fourth way to do a thing that already had three, and
     * the only one that could not express a nickname or a number.
     */

    el('file').addEventListener('change', function (ev) {
        var f = ev.target.files && ev.target.files[0];
        if (!f) { return; }
        var r = new FileReader();
        r.onload = function () {
            try {
                var doc = JSON.parse(String(r.result));
                var ps = Array.isArray(doc) ? doc : (doc.players || []);
                /*
                 * Objects to `setLine`, not names to the text box.
                 *
                 * This used to flatten each player to "First Last" and set the
                 * text box, which silently discarded the nickname and the
                 * shirt number - two of the five forms the grammar accepts -
                 * so a squad loaded from a file was harder to call than one
                 * typed by hand. The box is gone; this goes straight in.
                 */
                setSquad(ps);
            } catch (e) { alert('Not a squad file: ' + e.message); }
        };
        r.readAsText(f);
    });

    el('open').addEventListener('change', function (ev) {
        var f = ev.target.files && ev.target.files[0];
        if (!f) { return; }
        var r = new FileReader();
        r.onload = function () {
            try {
                var doc = JSON.parse(String(r.result));
                // A capture on the other clock cannot be read here: wall
                // seconds and video seconds look identical and are not.
                if (doc.mode && doc.mode !== MODE) {
                    if (!window.confirm('That capture was made in ' + doc.mode
                        + ' mode and this page is in ' + MODE + '.\n\n'
                        + 'Switch to ' + doc.mode + ' and open it?')) { return; }
                    setMode(doc.mode);
                }
                events = doc.events || [];
                lines = doc.lines || {};
                if (doc.line) { setLine(doc.line); }
                // Restore where the game had got to, or the page carries on
                // from point 1 with nobody holding the disc while the log
                // plainly says otherwise.
                point = doc.point || events.reduce(function (n, e) {
                    return Math.max(n, e.point || 1);
                }, 1);
                rebuildHolder();
                render();
            } catch (e) { alert('Not a capture: ' + e.message); }
        };
        r.readAsText(f);
    });

    el('save').addEventListener('click', function () {
        /*
         * The clock has to be recorded, not assumed.
         *
         * This wrote `clock: 'video'` unconditionally, which was true when
         * there was only one clock. A live capture then arrived claiming
         * timestamps that could be seeked against, and a video id that was
         * whatever happened to be in the box. A reader cannot tell wall
         * seconds from video seconds by looking at them.
         */
        var doc = {
            mode: MODE,
            clock: MODE === 'training' ? 'video' : 'wall',
            audio: audioMode(),
            point: point,
            line: line,
            lines: lines,
            events: events
        };
        if (MODE === 'training') { doc.video = videoId(el('url').value); }
        var blob = new Blob([JSON.stringify(doc, null, 2) + '\n'],
            { type: 'application/json' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'spotted.json';
        a.click();
    });

    // ---- rendering ---------------------------------------------------------
    /**
     * The line, picked the way the commentary desk picks one.
     *
     * Same shape as `pickPanel` in `commentator.php`: a count against the
     * line size, then a chip per player with the shirt NUMBER large and the
     * surname under it - the number is what is on the shirt, the name is what
     * gets said. A spotter and a commentator are often the same person on
     * different weekends, and two pickers would be two things to learn.
     *
     * What this one adds is the key hint. The spotter addresses the line by
     * position, 1 to 7, so a chip that is on shows which key it answers to.
     */
    /**
     * WHAT THE RECOGNISER HAS HEARD SO FAR, WHILE IT IS STILL HEARING IT.
     *
     * A line call is five names and takes several seconds to say, and the
     * picker sat unchanged for all of them - so the spotter had no idea
     * whether the first three had landed until the last one did, which is
     * exactly when it is too late to repeat one.
     *
     * Vosk emits a running partial before it commits, so the names it has
     * picked up are lit as provisional. Nothing is recorded from a partial:
     * it is a view of the recogniser's mind, not an observation, and it is
     * cleared the moment the real result arrives.
     */
    var provisional = [];

    function onPartial(text) {
        var words = String(text || '').toLowerCase().split(/\s+/).filter(Boolean);
        var found = [];
        if (/^(team|o|d|offence|offense|defence|defense|line|lines|on)$/.test(words[0] || '')) {
            words.forEach(function (w) {
                var p = matchInSquad(w);
                if (p && found.indexOf(p.label) === -1) { found.push(p.label); }
            });
        }
        if (found.join('|') === provisional.join('|')) { return; }
        provisional = found;
        renderLine();
    }

    /**
     * WHICH NAMES ARE UNSAFE TO SAY, CHECKED AGAINST THIS LINE.
     *
     * The matcher is phonetic, so a name is only usable if nothing else in
     * the vocabulary sounds like it - and whether that holds depends entirely
     * on who is on the field and what the sport calls things. "Hawk" is a
     * perfectly good nickname and identical to "huck" under the consonant
     * key; it shipped in this file's own demo squad and was found by a
     * spotter saying it, which is the expensive way.
     *
     * So it is checked instead, on the line as it stands, and the answer is
     * a suggestion rather than a refusal: a player nearly always has another
     * form that is safe - a surname, a first name, a shirt number - and the
     * spotter needs to be told WHICH, not that something is wrong.
     */
    /**
     * WHAT THE SPOTTER CALLS THIS PLAYER, WHICH IS NOT WHO THEY ARE.
     *
     * A name the matcher cannot separate is useless however correct it is,
     * so the spotter picks a different one - and then has to remember, on a
     * sideline, that Storm is the one they must call Sandor. Putting the
     * chosen form on the chip removes the remembering.
     *
     * Local to this page and this device. It is a label for saying somebody
     * out loud, not a correction to the roster: the event still records the
     * player's real identity, the commentary desk is untouched, and nothing
     * is written back to the installation.
     */
    function callName(p) { return p.call || p.nick || p.last || p.label; }

    function setCallName(p, name) {
        [squad, line, sides.O, sides.D].forEach(function (list) {
            list.forEach(function (q) { if (q.label === p.label) { q.call = name || ''; } });
        });
        render();
        // Kaldi takes its vocabulary at construction, so a name added after
        // the recogniser was built cannot be decoded until it is replaced.
        rebuildGrammar();
    }

    /** Both spotter-local fields in one pass, so the page renders once. */
    function setPlayerFields(p, call, matching) {
        [squad, line, sides.O, sides.D].forEach(function (list) {
            list.forEach(function (q) {
                if (q.label !== p.label) { return; }
                q.call = call || '';
                q.matching = matching || '';
            });
        });
        render();
        rebuildGrammar();
    }

    /*
     * One editor for the two things a spotter learns too late.
     *
     * A calling name, because a roster name turns out to be unsayable or to
     * collide with another; and a matching, because the source the squad came
     * from usually has none - tournament results sites do not publish it. Both
     * are THIS PAGE'S: the calling name never reaches the commentary desk, and
     * a matching typed here fills a gap rather than overruling the desk, which
     * owns that field and is only ever read from.
     */
    function openPlayerEdit(p) {
        var dlg = el('pedit');
        if (!dlg || !dlg.showModal) { return; }
        var chosen = matchingOf(p);

        el('peditWho').textContent = (p.num ? p.num + ' ' : '') + p.label;
        el('peditCall').value = callName(p) === p.label ? '' : callName(p);
        el('peditWhy').textContent = 'Both are kept on this page only.';

        /*
         * The clashes, where the fix is - not in a panel somewhere else.
         *
         * A spotter discovers a name is unsayable at the moment it is misheard,
         * and the thing they want then is a different word, immediately. The
         * folded warning list says WHICH names collide; this says it about the
         * player already open and turns each safe alternative into one tap.
         */
        var why = el('peditRisk');
        why.replaceChildren();
        var state = riskFor(p);
        if (!state) {
            why.append(document.createTextNode(
                'Clashes are only known for players on the field \u2014 the '
                + 'recogniser\u2019s vocabulary is the line.'));
        } else if (!state.risk) {
            why.append(document.createTextNode(
                'Nothing on this line sounds like them.'));
        } else {
            var r = state.risk;
            why.append(document.createTextNode('\u201c' + r.bad[0].alias
                + '\u201d sounds like \u201c' + r.bad[0].with + '\u201d. Safer:'));
            // Shortest first: under time pressure a spotter says the short
            // form or none at all.
            r.good.slice().sort(function (x, y) { return x.length - y.length; })
                .slice(0, 4).forEach(function (safe) {
                    var pill = document.createElement('button');
                    pill.type = 'button';
                    pill.className = 'pill';
                    pill.textContent = safe;
                    pill.title = 'Call them \u201c' + safe + '\u201d on this page';
                    pill.addEventListener('click', function () {
                        el('peditCall').value = safe;
                    });
                    why.append(pill);
                });
        }

        var mt = el('peditMt');
        mt.replaceChildren();
        [['MMP', 'MMP'], ['FMP', 'FMP'], ['', 'not set']].forEach(function (o) {
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = o[1];
            if (o[0]) { b.className = 'mt ' + o[0].toLowerCase(); }
            b.setAttribute('aria-pressed', chosen === o[0] ? 'true' : 'false');
            b.addEventListener('click', function () {
                chosen = o[0];
                [].forEach.call(mt.children, function (c) {
                    c.setAttribute('aria-pressed', c === b ? 'true' : 'false');
                });
            });
            mt.append(b);
        });

        el('peditSave').onclick = function () {
            setPlayerFields(p, (el('peditCall').value || '').trim(), chosen);
        };
        dlg.showModal();
    }

    function nameRisks() {
        var out = [];
        var vocab = vocabulary();

        line.forEach(function (p, self) {
            var forms = aliasesOf(p);
            var bad = [];
            var good = [];
            forms.forEach(function (f) {
                var flat = f.alias.replace(/\s/g, '');
                if (/^\d+$/.test(f.alias)) { return; }
                var clash = null;
                vocab.forEach(function (v) {
                    if (clash) { return; }
                    /*
                     * A player's own other forms are not a clash.
                     *
                     * The vocabulary keys a player by their POSITION on the
                     * line, not by name, so comparing against the label
                     * matched nothing and every player collided with
                     * themselves - "kovac sounds like kova", which is one
                     * person and two ways of saying so.
                     */
                    var mine = v.kind === 'player' && v.value === self;
                    if (mine || v.word.replace(/\s/g, '') === flat) { return; }
                    if (score(flat, v.word.replace(/\s/g, '')) >= SURE) { clash = v.word; }
                });
                if (clash) { bad.push({ alias: f.alias, with: clash }); }
                else { good.push(f.alias); }
            });
            if (bad.length) {
                out.push({ who: callName(p), player: p, bad: bad, good: good,
                           num: p.num });
            }
        });

        return out;
    }

    /**
     * What the recogniser is likely to confuse THIS player with.
     *
     * Only ever answerable for somebody on the field: the decoding grammar
     * contains the line and nothing else, so a player off it has no word in
     * the vocabulary and therefore nothing to collide with. Saying "no
     * clashes" about them would be a promise the grammar has not made.
     */
    function riskFor(p) {
        var on = line.some(function (q) { return q.label === p.label; });
        if (!on) { return null; }
        var found = null;
        nameRisks().forEach(function (r) {
            if (!found && r.player.label === p.label) { found = r; }
        });
        return { on: true, risk: found };
    }

    function renderLine() {
        var box = el('line');
        box.replaceChildren();

        var head = document.createElement('div');
        head.className = 'pickhead';
        var title = document.createElement('strong');
        title.textContent = 'On the field';
        var count = document.createElement('span');
        count.className = 'count' + (line.length === lineSize ? ' ok' : '');
        count.textContent = line.length + ' / ' + lineSize;
        head.append(title, count);

        var sizer = document.createElement('select');
        sizer.className = 'sizer';
        sizer.title = 'How many are on the field';
        [4, 5, 6, 7].forEach(function (n) {
            var o = document.createElement('option');
            o.value = n;
            o.textContent = n + 'v' + n;
            if (n === lineSize) { o.selected = true; }
            sizer.append(o);
        });
        sizer.addEventListener('change', function () {
            lineSize = Number(sizer.value) || 7;
            // Shrinking takes the last on off rather than asking, because the
            // alternative is a line that is illegal and silent about it.
            if (line.length > lineSize) { line = line.slice(0, lineSize); }
            holder = null;
            render();
            rebuildGrammar();
        });
        head.append(sizer);

        var pill = document.createElement('span');
        pill.className = 'startpill ' + (starting === 'D' ? 'd' : 'o');
        pill.textContent = starting === 'D' ? 'D point \u2014 we pull' : 'O point \u2014 we receive';
        head.append(pill);

        renderRatio(head);

        // One tap fills the line from a tagged group, which is the press this
        // whole panel exists to save: seven chips, every point.
        ['O', 'D'].forEach(function (which) {
            if (!roleGroup(which).length) { return; }
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'preset';
            b.textContent = teamName(which);
            b.title = 'Put ' + teamName(which) + ' on the field';
            b.addEventListener('click', function () { setLine(roleGroup(which)); });
            head.append(b);
        });

        // Emptying the line by hand is seven taps, and it is what a spotter
        // does whenever the next line is not a preset.
        if (line.length) {
            var none = document.createElement('button');
            none.type = 'button';
            none.className = 'preset';
            none.textContent = 'clear';
            none.title = 'Take everybody off the line';
            none.addEventListener('click', function () {
                // Through `sides`, like everything else. Emptying `line`
                // alone left both fives still recorded as on, so the next
                // line call rebuilt the field from them and the clear
                // appeared to undo itself.
                sides = { O: [], D: [] };
                pendingLine = null;
                line = [];
                holder = null;
                render();
                rebuildGrammar();
            });
            head.append(none);
        }

        /*
         * Counted per side, not as one squad.
         *
         * "10 in the squad" described a roster that does not exist: these are
         * two teams of five, and the number that matters is how many each
         * side has, not how many people are in the building.
         */
        var oN = roleGroup('O').length;
        var dN = roleGroup('D').length;
        var loose = squad.length - oN - dN;
        if (oN || dN) {
            var rest = document.createElement('span');
            rest.className = 'teamcount';
            rest.textContent = teamName('O') + ' ' + oN + ' \u00b7 ' + teamName('D') + ' ' + dN
                + (loose ? ' \u00b7 ' + loose + ' unassigned' : '');
            head.append(rest);
        } else if (squad.length > line.length) {
            var only = document.createElement('span');
            only.className = 'teamcount';
            only.textContent = squad.length + ' players, no sides set';
            head.append(only);
        }
        box.append(head);

        /*
         * Shown where the line is picked, because that is when it can be
         * acted on - a spotter who learns mid-point that a name is unsayable
         * has already lost the call it was needed for.
         */
        var risks = nameRisks();
        if (risks.length) {
            var warn = document.createElement('details');
            warn.className = 'namerisk';
            var sum = document.createElement('summary');
            sum.textContent = risks.length === 1
                ? '1 name sounds like something else'
                : risks.length + ' names sound like something else';
            warn.append(sum);
            var riskBody = document.createElement('div');
            riskBody.className = 'riskbody';
            // No cap now that it is folded away: the four-row limit existed to
            // stop this swamping the picker, and a spotter who opens it wants
            // the name they are stuck on, which may not be in the first four.
            risks.forEach(function (r) {
                var d = document.createElement('div');
                var said = document.createElement('span');
                said.textContent = '\u201c' + r.bad[0].alias + '\u201d sounds like \u201c'
                    + r.bad[0].with + '\u201d \u2014 call ' + r.who;
                d.append(said);
                // Shortest first, because a spotter under time pressure says
                // the short form or none at all.
                r.good.slice().sort(function (x, y) { return x.length - y.length; })
                    .slice(0, 3).forEach(function (safe) {
                        var pick = document.createElement('button');
                        pick.type = 'button';
                        pick.textContent = safe;
                        pick.title = 'Call ' + r.who + ' \u201c' + safe + '\u201d on this page';
                        pick.addEventListener('click', function () { setCallName(r.player, safe); });
                        d.append(pick);
                    });
                riskBody.append(d);
            });
            warn.append(riskBody);
            box.append(warn);
        }

        if (!squad.length) {
            var none = document.createElement('p');
            none.className = 'sub';
            none.textContent = squad.length
                ? 'Nobody on \u2014 call both lines.'
                : 'Load a squad, or press demo line.';
            box.append(none);
            return;
        }

        /*
         * A row per line, because that is how a squad is organised.
         *
         * Same reasoning as the desk grouping a mixed picker by matching: the
         * grouping is something the eye lands on rather than something it has
         * to infer. Players with no role stated always get their own row and
         * are never hidden - absent is not a role.
         */
        var groups = [];
        if (roleGroup('O').length || roleGroup('D').length) {
            // Named as the sides they are. The team on offence this point is
            // marked, because which one it is changes every point and the
            // picker is where a spotter looks to find out.
            groups.push([teamName('O') + (starting === 'O' ? ' \u2014 on offence' : ''), roleGroup('O'), 'O']);
            groups.push([teamName('D') + (starting === 'D' ? ' \u2014 on offence' : ''), roleGroup('D'), 'D']);
            groups.push(['unassigned', squad.filter(function (p) { return !p.role; })]);
        } else {
            groups.push(['', squad]);
        }

        /*
         * A mixed game with no matchings says so, whether or not the ratio was
         * taken.
         *
         * This was gated behind the ratio opt-in, which meant the common case -
         * a mixed game loaded from a pack, ratio left to the desk - showed
         * neither tints nor any word about why. Silence reads as "this tool
         * does not do that", when the truth is that nobody supplied the data.
         */
        if (isMixedGame() && squad.length && !anyMatching() && window.LineupUI) {
            box.append(window.LineupUI.missingNote({
                className: 'mtwarn',
                remedy: 'Tournament results sites rarely publish it, so add '
                    + '"matching": "MMP" or "FMP" per player to the game pack.'
            }));
        }

        groups.forEach(function (g) {
            if (!g[1].length) { return; }
            if (g[0]) {
                var lbl = document.createElement('div');
                lbl.className = 'grouplabel';
                lbl.textContent = g[0];
                // The same "MMP 2 of 4" the desk shows, from shared/lineup-ui.js.
                if (g[2] && window.LineupUI) {
                    var gs = matchingGroups(g[2]);
                    lbl.append(window.LineupUI.counts({ groups: gs && gs.groups }));
                }
                box.append(lbl);
            }
            box.append(chipRow(g[1]));
        });
    }

    /** Only ever what the squad was told; absent is absent, never guessed. */
    function matchingOf(p) {
        return /^(MMP|FMP)$/.test(String(p.matching || '')) ? p.matching : '';
    }

    function anyMatching() {
        return squad.some(function (p) { return matchingOf(p); });
    }

    /**
     * Whether this game is mixed, and so has matchings worth showing.
     *
     * The pack may say so outright; failing that the game's own name usually
     * does, which is the same test the scoresheet applies. Taking the ratio is
     * also an answer: nobody opts into declaring a ratio for a game that has
     * none.
     */
    function isMixedGame() {
        if (ratioOn) { return true; }
        if (!packGame || !window.Ratio) { return false; }
        return window.Ratio.isMixed(packGame.division || packGame.name);
    }

    /**
     * This point's quotas against who is on, or null when it cannot be known.
     *
     * Needs BOTH a declared ratio and matchings: without the ratio there are
     * no quotas to count against, and without matchings there is nothing to
     * count. Either missing means the picker says so rather than showing
     * "0 of 4" at a full line.
     */
    function matchingGroups(which) {
        if (!window.Lineup || !window.Ratio || !ratioOn || !anyMatching()) { return null; }
        var quotas = window.Ratio.counts(ratioValue() && ratioValue().get()
            ? window.Ratio.forPoint(ratioValue().get(), lineSize, point) : null);
        if (!quotas) { return null; }
        var on = line.map(function (q) { return q.label; });
        return window.Lineup.groups(
            roleGroup(which).map(function (p) {
                return { id: p.label, matching: matchingOf(p) };
            }), quotas, on);
    }

    function chipRow(players) {
        var row = document.createElement('div');
        row.className = 'numrow';
        players.forEach(function (p) {
            var at = -1;
            line.forEach(function (q, i) { if (q.label === p.label) { at = i; } });
            var b = document.createElement('button');
            b.type = 'button';
            var matching = matchingOf(p);
            b.className = (at !== -1 ? 'on' : '')
                + (at !== -1 && holder === at ? ' has' : '')
                + (matching ? ' ' + matching.toLowerCase() : '')
                + (provisional.indexOf(p.label) !== -1 ? ' maybe' : '');
            b.textContent = p.num || '\u2013';
            var nm = document.createElement('small');
            nm.textContent = callName(p);
            b.append(nm);
            if (matching) {
                var mt = document.createElement('span');
                mt.className = 'mt ' + matching.toLowerCase();
                mt.textContent = matching;
                b.append(mt);
            }
            if (at !== -1) {
                var key = document.createElement('span');
                key.className = 'kk';
                key.textContent = at + 1;
                b.append(key);
            }
            b.title = p.label + (at !== -1 ? ' \u2014 on, key ' + (at + 1) : ' \u2014 off')
                + (at !== -1 && holder === at ? ' \u00b7 has the disc' : '')
                + ' \u00b7 right-click or shift-click to edit';
            b.addEventListener('click', function (ev) {
                // Shift renames rather than substitutes, so the ordinary
                // click keeps doing the ordinary thing.
                if (ev.shiftKey) { openPlayerEdit(p); return; }
                toggleInLine(p);
            });
            // Right-click on a desk, long-press on a phone.
            b.addEventListener('contextmenu', function (ev) {
                ev.preventDefault();
                openPlayerEdit(p);
            });
            row.append(b);
        });

        return row;
    }

    /**
     * The coach's table: per player, with the denominator attached.
     *
     * Every rate here is shown as "n/m" rather than a bare percentage,
     * because a 100% completion rate over two throws and over sixty are not
     * the same claim and a percentage hides which one you are looking at.
     *
     * WHAT A MISSED TOUCH DOES TO THIS, AND WHY IT IS COUNTED
     *
     * A `gap` is a touch nobody named. It means the true touch and throw
     * counts are HIGHER than these, never lower — so the numbers are floors,
     * the gap count is shown beside them, and nothing here is presented as a
     * complete account of the game.
     */
    function perPlayer() {
        var who = {};
        var get = function (name) {
            if (!name) { return null; }
            if (!who[name]) {
                who[name] = { name: name, points: 0, secs: 0, blind: 0, caught: 0,
                              threw: 0, away: 0, dropped: 0, goals: 0, assists: 0, blocks: 0 };
            }
            return who[name];
        };

        // Time first: it comes from the lines, not from the events, so a
        // player who touched nothing still appears. Absent is not zero, and a
        // player who never got the disc is a finding rather than a blank.
        var play = playIntervals();
        var afk = afkIntervals();
        pointWindows().forEach(function (pw) {
            var seen = liveIn(pw.window, play, afk);
            (lines[pw.point] || []).forEach(function (p) {
                var w = get(p.label);
                if (!w) { return; }
                w.points += 1;
                w.secs += seen.live;
                w.blind += seen.blind;
            });
        });

        var sorted = events.slice().sort(function (a, b) { return a.at - b.at; });
        sorted.forEach(function (e, i) {
            if (e.type === 'throw') {
                if (get(e.to)) { get(e.to).caught += 1; }
                if (get(e.from)) { get(e.from).threw += 1; }
            }
            if (e.type === 'goal') {
                if (get(e.by)) { get(e.by).goals += 1; }
                // An assist is the throw before the goal, which is the only
                // way this data can know one.
                var prev = sorted[i - 1];
                if (prev && prev.type === 'throw' && get(prev.from)) { get(prev.from).assists += 1; }
            }
            if (e.type === 'turnover') {
                if (e.how === 'drop' && get(e.by)) { get(e.by).dropped += 1; }
                if (e.how === 'throwaway' && get(e.by)) { get(e.by).away += 1; }
                if (e.how === 'blocked' && get(e.by)) { get(e.by).away += 1; }
            }
            if (e.type === 'block' && get(e.by)) { get(e.by).blocks += 1; }
        });

        return Object.keys(who).map(function (k) { return who[k]; })
            .sort(function (a, b) { return b.secs - a.secs || b.threw - a.threw; });
    }

    /**
     * How far this table can be trusted, said out loud above it.
     *
     * A derived number inherits the worst coverage of its inputs, so the
     * banner names each input that is weak rather than averaging them into a
     * single reassuring score.
     */
    /**
     * Holds and breaks, which is what O and D lines are for.
     *
     * A hold is an O point converted; a break is a D point taken off the
     * other team. Both need the point's starting line AND how it ended, so
     * neither was computable before `conceded` existed - a point we lost had
     * no ending in the log at all.
     *
     * Reported as n/m, never as a percentage on its own: three holds from
     * four and thirty from forty are not the same claim.
     */
    function holdsAndBreaks() {
        var out = { hold: 0, onO: 0, brk: 0, onD: 0 };
        var sorted = events.slice().sort(function (a, b) { return a.at - b.at; });
        var which = null;
        sorted.forEach(function (e) {
            if (e.type === 'point') { which = e.starting || null; return; }
            if (!which) { return; }
            if (e.type === 'goal') {
                if (which === 'O') { out.onO += 1; out.hold += 1; } else { out.onD += 1; out.brk += 1; }
                which = null;
            }
            if (e.type === 'conceded') {
                if (which === 'O') { out.onO += 1; } else { out.onD += 1; }
                which = null;
            }
        });

        return out;
    }

    function coverageNote() {
        var notes = [];
        var pts = pointWindows().length;
        var gaps = events.filter(function (e) { return e.type === 'gap'; }).length;
        var afkS = 0;
        afkIntervals().forEach(function (a) { afkS += a[1] - a[0]; });
        var moves = events.filter(function (e) { return e.type === 'play'; });
        var said = moves.filter(function (e) { return e.how === 'said'; }).length;

        if (!moves.length) {
            notes.push('the play clock was never started, so field time is not reported');
        } else if (said === 0) {
            notes.push('every play-clock change was inferred from calls, not declared');
        }
        var ended = events.filter(function (e) {
            return e.type === 'goal' || e.type === 'conceded';
        }).length;
        if (pts > 1 && ended < pts - 1) {
            notes.push((pts - 1 - ended) + ' point(s) have no ending recorded, '
                + 'so holds and breaks are incomplete');
        }
        if (gaps) {
            notes.push(gaps + (gaps === 1 ? ' touch went' : ' touches went')
                + ' unnamed, so throw counts are floors');
        }
        if (afkS > 1) { notes.push(mmss(afkS) + ' was not being watched'); }
        if (!pts) { notes.push('no points recorded'); }
        return notes;
    }

    var COACH_COLS = [
        ['player', function (w) { return w.name; }],
        // Not "pts": beside a goals column that reads as points SCORED, and
        // these are points PLAYED.
        ['played', function (w) { return w.points; }],
        ['live time', function (w) {
            if (!w.secs) { return '—'; }
            /*
             * Refused when most of it was not watched.
             *
             * A derived number inherits the worst coverage of its inputs, and
             * this one can inherit a great deal: a session left open over
             * lunch bills the whole break as live play. Reporting 2:01:00
             * with a footnote is how a figure gets quoted without its
             * footnote, so past half unwatched the cell declines to answer
             * and the row says why.
             */
            return w.blind > w.secs / 2 ? '—' : mmss(w.secs);
        }],
        ['caught', function (w) { return w.caught; }],
        ['completed', function (w) {
            var att = w.threw + w.away;
            return att ? w.threw + '/' + att : '—';
        }],
        ['throwaway', function (w) { return w.away || '—'; }],
        ['drop', function (w) { return w.dropped || '—'; }],
        ['G', function (w) { return w.goals || '—'; }],
        ['A', function (w) { return w.assists || '—'; }]
    ];

    function renderCoach() {
        var box = el('coach');
        if (!box) { return; }
        box.replaceChildren();

        var rows = perPlayer();
        var notes = coverageNote();
        var note = document.createElement('p');
        note.className = 'cov';
        note.textContent = notes.length
            ? 'Read with care: ' + notes.join('; ') + '.'
            : 'Every point has a line, a play clock and no blind spots.';
        box.append(note);

        if (!rows.length) {
            var none = document.createElement('p');
            none.className = 'sub';
            none.textContent = 'Set a line and start a point.';
            box.append(none);
            return;
        }

        var tbl = document.createElement('table');
        tbl.className = 'coach';
        var head = document.createElement('tr');
        COACH_COLS.forEach(function (c) {
            var th = document.createElement('th');
            th.textContent = c[0];
            head.append(th);
        });
        tbl.append(head);
        rows.forEach(function (w) {
            var tr = document.createElement('tr');
            COACH_COLS.forEach(function (c, i) {
                var td = document.createElement('td');
                td.textContent = c[1](w);
                if (i === 0) { td.className = 'nm'; }
                tr.append(td);
            });
            // Time that nobody was watching is marked on the row it weakens,
            // not only in the banner.
            if (w.blind > 1) {
                var most = w.blind > w.secs / 2;
                tr.title = mmss(w.blind) + ' of this player\'s live time was not watched'
                    + (most ? ' — too much of it to report a figure' : '');
                tr.className = most ? 'unknown' : 'blind';
            }
            tbl.append(tr);
        });
        box.append(tbl);

        var foot = document.createElement('p');
        foot.className = 'sub caveat';
        foot.textContent = 'Live time is time on the field while the disc was live. '
            + 'It is presence, not work: a handler standing in the dump and a cutter '
            + 'running the point score the same here. A dash means most of that '
            + 'time was not watched, so there is no figure to give.';
        box.append(foot);
    }

    function renderStats() {
        var t = events.filter(function (e) { return e.type === 'throw'; }).length;
        var turns = events.filter(function (e) { return e.type === 'turnover'; }).length;
        var goals = events.filter(function (e) { return e.type === 'goal'; }).length;
        var gaps = events.filter(function (e) { return e.type === 'gap'; }).length;
        var afk = events.filter(function (e) { return e.type === 'afk'; });
        var afkSecs = 0;
        afk.forEach(function (e) { afkSecs += (e.until || e.at) - e.at; });

        var cells = [
            ['throws', t], ['turnovers', turns], ['goals', goals],
            ['missed', gaps],
            ['throws/point', goals ? (t / goals).toFixed(1) : '—'],
            // Coverage, stated rather than assumed: what the capture cannot
            // speak for is as much a result as what it can.
            ['afk', mmss(afkSecs)],
            ['voice heard', tally.heard],
            ['names by', Object.keys(tally.byForm).length
                ? Object.keys(tally.byForm).sort(function (a, b) {
                    return tally.byForm[b] - tally.byForm[a];
                }).map(function (f) { return f + ' ' + tally.byForm[f]; }).join(' · ')
                : '—'],
            ['voice matched', tally.heard ? Math.round((tally.matched / tally.heard) * 100) + '%' : '—']
        ];
        /*
         * The official score, checked against what was captured.
         *
         * The one piece of ground truth that costs nothing: if the
         * tournament says 15-13 and the capture holds 13 goals, two were
         * missed, and that is known without anybody tagging a single event.
         */
        if (packGame && packGame.score) {
            var ours = events.filter(function (e) { return e.type === 'goal'; }).length;
            var theirs = events.filter(function (e) { return e.type === 'conceded'; }).length;
            var want = Number(packGame.score.home || 0) + Number(packGame.score.away || 0);
            var got = ours + theirs;
            cells.push(['goals captured', got + '/' + want
                + (got === want ? '' : ' \u26a0')]);
        }

        var hb = holdsAndBreaks();
        if (hb.onO || hb.onD) {
            cells.push(['holds', hb.onO ? hb.hold + '/' + hb.onO : '\u2014']);
            cells.push(['breaks', hb.onD ? hb.brk + '/' + hb.onD : '\u2014']);
        }
        var whole = liveIn([0, at()]);
        if (whole.live > 0) {
            cells.splice(5, 0, ['live play', mmss(whole.live)]);
            // A ratio worth knowing on its own: most of a game is not live.
            cells.splice(6, 0, ['live share', at() > 1
                ? Math.round((whole.live / at()) * 100) + '%' : '—']);
            cells.splice(7, 0, ['throws/live min', whole.live > 30
                ? (t / (whole.live / 60)).toFixed(1) : '—']);
        }
        var box = el('stats');
        box.replaceChildren();
        cells.forEach(function (c) {
            var d = document.createElement('div');
            var s = document.createElement('span');
            s.textContent = c[0];
            var b = document.createElement('b');
            b.textContent = c[1];
            d.append(s, b);
            box.append(d);
        });
    }

    function label(e) {
        if (e.type === 'throw') {
            return (e.grip ? e.grip + ' ' : '')
                + (e.throwType ? e.throwType + ' → ' : '→ ') + e.to
                + (e.from ? '  (' + e.from + ')' : '');
        }
        if (e.type === 'sub') {
            return (e.on ? e.on + ' on' : '') + (e.on && e.off ? ', ' : '')
                + (e.off ? e.off + ' off' : '');
        }
        if (e.type === 'point') { return 'point ' + e.point; }
        if (e.type === 'gap') { return '— a touch nobody named —'; }
        if (e.type === 'afk') { return 'AFK' + (e.until ? ' until ' + mmss(e.until) : ' — running'); }
        if (e.type === 'unmatched') {
            /*
             * An offered choice is not a failure to understand.
             *
             * "lang or lehner" is parsed perfectly and recorded with both
             * answers, waiting for one tap - and the log called it "heard,
             * not understood", which reads as the recogniser failing. It
             * misled the person who wrote it into hunting a bug in a feature
             * that works, and it would tell a spotter their grammar is broken
             * at the exact moment they used it correctly.
             */
            if (e.open && e.options) {
                var offered = [];
                Object.keys(e.options).forEach(function (k) {
                    (e.options[k] || []).forEach(function (o) {
                        offered.push(typeof o === 'string' ? o : (o.word || o.value));
                    });
                });
                return 'a choice to settle: ' + offered.join(' or ');
            }
            return 'heard, not understood: ' + e.heard
                + (e.why ? ' \u2014 ' + e.why : '');
        }
        if (e.type === 'regain') {
            if (e.open) { return 'who put it into play?'; }
            return e.by ? e.by + ' picks it up' : 'ours';
        }
        if (e.type === 'call') {
            if (e.call === 'timeout') {
                return 'timeout'
                    + (e.team ? ' — ' + (e.team === 'us' ? 'ours' : 'theirs') : '')
                    + (e.by ? ' (' + e.by + ')' : '');
            }
            return 'call: ' + e.call
                + (e.by ? ' \u2014 ' + e.by : '')
                + (e.against ? ' on ' + e.against : '')
                + (e.pair ? ' (' + e.pair + nth(e.pair) + ' between them this point)' : '')
                + (e.resolved ? ', ' + e.resolved : '') + (e.seen === 'inferred' ? ' (from what followed)' : '');
        }
        if (e.type === 'play') {
            return (e.live ? 'play on' : 'clock stopped')
                + (e.why ? ' \u2014 ' + e.why : '')
                + (e.how === 'auto' ? ' (inferred)' : '');
        }
        if (e.type === 'turnover') {
            return (e.throwType ? e.throwType + ' — ' : '') + e.how
                + (e.by ? ' by ' + e.by : '');
        }
        if (e.type === 'goal') { return 'GOAL' + (e.by ? ' — ' + e.by : ''); }
        if (e.type === 'pull') {
            return 'pull by ' + (e.by || '—')
                + (e.landed ? ' (' + e.landed + ')' : '');
        }
        if (e.type === 'conceded') { return 'they scored' + (e.how ? ' (' + e.how + ')' : ''); }
        if (e.type === 'block') { return (e.how || 'block') + ' by ' + (e.by || '—'); }
        return e.type;
    }

    var LOG_ROWS = 60;

    function renderLog() {
        var body = el('log').querySelector('tbody');
        body.replaceChildren();
        // A game is several hundred events and this redraws on every one of
        // them. The recent end is the part anybody reads; the rest is in the
        // file. Sorted for display only — the log itself keeps entry order so
        // that undo means what it says.
        var shown = events.slice().sort(function (a, b) { return a.at - b.at; }).slice(-LOG_ROWS);
        shown.forEach(function (e) {
            var tr = document.createElement('tr');
            tr.dataset.at = e.at;
            var t = document.createElement('td');
            t.className = 't';
            t.textContent = mmss(e.at);
            var w = document.createElement('td');
            w.className = e.type === 'unmatched' && e.open ? 'choice'
                : e.type === 'gap' ? 'gap' : e.type === 'afk' ? 'afk'
                : e.type === 'turnover' ? 'turn' : e.type === 'goal' ? 'goal'
                : e.type === 'play' ? 'clockrow' : '';
            w.textContent = label(e);
            // One tap on any line says "that one is wrong". It does not ask
            // what the truth was — during play there is no time for that, and
            // the whole value is that it takes a moment.
            var f = document.createElement('td');
            f.className = 'flagcell' + (e.flagged ? ' on' : '');
            f.textContent = '\u2691';
            f.title = e.flagged ? 'flagged \u2014 tap to unflag' : 'flag for review';
            f.addEventListener('click', function (ev) {
                ev.stopPropagation();
                flag(e);
            });
            tr.append(t, w, f);
            // Anything the queue is holding is marked in the log too, or a
            // row that is waiting on a decision looks exactly like a settled
            // observation - which is the confusion the whole queue exists to
            // prevent.
            if (e.flagged || e.open || e.certain === false) { tr.dataset.flagged = '1'; }
            // Every captured claim is checkable: click it and the footage goes
            // to the moment it says it happened.
            tr.addEventListener('click', function () {
                seek(e.at - 3);
            });
            body.append(tr);
        });
        el('info').textContent = events.length + ' events'
            + (events.length > LOG_ROWS ? ' (last ' + LOG_ROWS + ' shown)' : '')
            + (holder === null || !line[holder] ? '' : ' · ' + line[holder].label + ' has it');
    }

    function highlight() {
        var now = at();
        var rows = el('log').querySelectorAll('tr');
        var best = null;
        rows.forEach(function (r) {
            r.className = r.dataset.flagged ? 'flagged' : '';
            if (Number(r.dataset.at) <= now) { best = r; }
        });
        if (best) { best.className += ' at'; }
    }

    /** The grammar, and where the last utterance's words landed in it. */
    /**
     * The sentence structure, on screen, with the names actually on the line.
     *
     * The slots below show what the LAST utterance filled, which tells a
     * spotter how they did and nothing about what to say next. A grammar you
     * have to remember is a grammar people speak around, and the whole design
     * rests on them not doing that.
     *
     * Examples are built from the current line rather than written out,
     * because "huck lehner" is a thing you can say right now and "huck
     * <receiver>" is a thing you have to translate first.
     */
    /**
     * The rehearsal script, as data.
     *
     * Built once and used twice: the panel draws it, and the checker replays
     * it through the same parser to work out what reading it aloud SHOULD
     * have produced. Sharing the source is the point - an expected result
     * maintained by hand is a second thing to get wrong.
     */
    function buildScript() {
        var oTeam = roleGroup('O').map(function (p) { return p.nick || p.last || p.label; });
        var dTeam = roleGroup('D').map(function (p) { return p.nick || p.last || p.label; });
        var onNow = line.map(function (p) { return p.nick || p.last || p.label; });
        var O = oTeam.length ? oTeam : onNow;
        var D = dTeam.length ? dTeam : onNow;
        var pick = function (side, i, fallback) { return side[i] || side[0] || fallback; };
        var o1 = pick(O, 0, 'weber');
        var o2 = pick(O, 1, 'lehner');
        var o3 = pick(O, 2, 'lang');
        var o4 = pick(O, 3, 'reiter');
        var d1 = pick(D, 0, 'thaler');
        var d2 = pick(D, 1, 'moser');
        var d3 = pick(D, 2, 'wagner');
        var shirt = null;
        roleGroup('O').forEach(function (p) { if (!shirt && p.num) { shirt = p.num; } });

        return [
            ['POINT 1', 'O receives, D pulls'],
            ['o line ' + [o1, o2, o3, o4, pick(O, 4, 'rocket')].join(' '), 'both lines, every point'],
            ['d line ' + [d1, d2, d3, pick(D, 3, 'brandt'), pick(D, 4, 'kovac')].join(' '), ''],
            ['pull ' + d1, d1 + ' pulls \u2014 you name the puller'],
            ['brick', 'it went out \u2014 attaches to the pull'],
            [o1 + ' picks up', 'who put it into play'],
            ['backhand inside to ' + o2, 'grip first, and "to" is ignored'],
            ['huck', 'still in the air \u2014 no receiver yet'],
            ['tipped by ' + o4, 'deflected, still up'],
            ['' + o3, 'caught \u2014 that closes the throw'],
            ['no ' + o4, 'wrong name \u2014 replaces it, no new row'],
            ['foul uncontested', 'the call and how it resolved'],
            ['tapped in', 'play restarts'],
            ['and then a huck to ' + (shirt ? spokenNumber(shirt)[1] : 'seven'),
                'filler and a number said as words'],
            ['goal', 'a hold \u2014 bare, so it credits whoever has it'],

            ['POINT 2', 'D receives, O pulls'],
            ['new point', ''],
            ['offence line ' + [d1, d2, d3, pick(D, 3, 'brandt'), pick(D, 4, 'kovac')].join(' '),
                'said the other way round this time'],
            ['defence line ' + [o1, o2, o3, o4, pick(O, 4, 'rocket')].join(' '), ''],
            ['pull ' + o1, ''],
            [d1 + ' picks it up', ''],
            ['forehand hammer to ' + d2, ''],
            ['layout d ' + o2, 'a block \u2014 the other side gets it'],
            ['hammer ' + o3 + ' offhand', 'grip after the receiver works too'],
            ['maybe ' + o4, 'records it and marks it unsure'],
            ['throwaway', ''],
            [d2 + ' picks up', ''],
            ['huck ' + d3, ''],
            ['goal', 'a break'],

            ['POINT 3', 'O receives again'],
            ['new point', ''],
            ['team o line ' + [o1, o2, o3, pick(O, 4, 'rocket'), pick(O, 5, 'storm')].join(' '),
                'by team name \u2014 and the bench comes on'],
            ['team d line ' + [d1, d2, pick(D, 3, 'brandt'), pick(D, 4, 'kovac'), pick(D, 5, 'flash')].join(' '), ''],
            ['pull ' + d1, 'nobody says who picks it up \u2014 it becomes a question'],
            [pick(O, 5, 'storm') + ' for ' + o4, 'a substitution \u2014 no need to recall the line'],
            ['missed', 'a touch you could not name'],
            [o2 + ' or ' + o3, 'a choice \u2014 both kept, one tap settles it'],
            ['offence timeout', 'named by side, not by "ours"'],
            ['tapped in', ''],
            ['stalled', 'a stall-out'],
            ['flag', 'that last one landed wrong \u2014 no new row, it marks one'],
            [o4, 'REFUSED \u2014 on the bench this point'],
            ['pull ' + o2, 'FLAGGED \u2014 the disc was already live'],
            [d1 + ' picks up', ''],
            ['goal', '']
        ];
    }

    /**
     * WHAT SHOULD READING IT ALOUD HAVE PRODUCED?
     *
     * Answered by doing it: the script is replayed through the same parser
     * and the same handlers, on a scratch copy of the state, and whatever
     * comes out is the expectation. Writing the expected log by hand would
     * be a second thing to keep correct, and it would agree with the code
     * right up until the moment it mattered.
     *
     * Everything the handlers touch is saved and put back. Rendering is
     * suppressed throughout, because a replay that redraws the page thirty
     * times is both slow and alarming to watch.
     */
    var replaying = false;

    function dryRun(steps) {
        /*
         * Everything the handlers touch, and that list grows.
         *
         * `sides` and `discWith` arrived after this was written and were not
         * in it, so a replay left the live session holding the scratch copy's
         * lines and its idea of who had the disc. The check corrupted the
         * thing it was checking - and then disagreed with it.
         */
        var saved = {
            events: events, line: line.slice(), holder: holder, inFlight: inFlight,
            possession: possession, point: point, lines: lines, starting: starting,
            inPlay: inPlay, afkFrom: afkFrom, lastCall: lastCall, heardAt: heardAt,
            discWith: discWith, sides: { O: sides.O.slice(), D: sides.D.slice() },
            squad: squad.slice(), tally: JSON.parse(JSON.stringify(tally))
        };

        replaying = true;
        events = [];
        point = 1;
        holder = null;
        inFlight = null;
        possession = 'us';
        lines = {};
        inPlay = false;
        afkFrom = null;
        starting = 'O';
        discWith = null;
        sides = { O: sides.O.slice(), D: sides.D.slice() };
        try {
            startPoint();
            steps.forEach(function (text) { applyCall(parseCall(text)); });
        } catch (e) {
            /* A broken script should not take the page with it. */
        }
        var out = events;

        events = saved.events;
        discWith = saved.discWith;
        sides = saved.sides;
        squad = saved.squad;
        line = saved.line;
        holder = saved.holder;
        inFlight = saved.inFlight;
        possession = saved.possession;
        point = saved.point;
        lines = saved.lines;
        starting = saved.starting;
        inPlay = saved.inPlay;
        afkFrom = saved.afkFrom;
        lastCall = saved.lastCall;
        heardAt = saved.heardAt;
        tally = saved.tally;
        replaying = false;

        return out;
    }

    /** Bookkeeping is not an observation, so it is not compared. */
    function observations(list) {
        return list.filter(function (e) {
            return !BOOKKEEPING[e.type] && e.type !== 'unmatched';
        });
    }

    /** One line per event, which is what a person compares. */
    function shape(e) {
        return label(e).replace(/\s+/g, ' ').trim();
    }

    /**
     * Did the run match the script?
     *
     * Compared in order, on the rendered description of each event - which
     * is what a person is actually checking, and forgiving of the fields
     * they do not care about. The first divergence is the useful part: after
     * one extra or missing event everything downstream is shifted and
     * reporting all of it as wrong would bury the one line worth reading.
     */
    function checkRun() {
        var want = observations(dryRun(buildScript().filter(function (step) {
            return !/^POINT /.test(step[0]);
        }).map(function (step) { return step[0]; })));
        var got = observations(events.slice().sort(function (a, b) { return a.at - b.at; }));

        var same = 0;
        while (same < want.length && same < got.length
            && shape(want[same]) === shape(got[same])) { same += 1; }

        return {
            want: want, got: got, same: same,
            done: same === want.length && want.length === got.length
        };
    }

    /**
     * EVERYTHING NEEDED TO DIAGNOSE A RUN, ON THE CLIPBOARD.
     *
     * The useful report is three things together - what the check said, what
     * is still unanswered, and the log itself - and copying them out of the
     * page by hand means selecting across three panels and losing the
     * reasons, which live in tooltips and hidden rows.
     *
     * Plain text on purpose: it is going into a message to somebody, and the
     * thing being reported is frequently that the page is behaving oddly, so
     * the format should be the one that survives that.
     */
    function buildReport() {
        var out = [];
        var eng = (el('engine').textContent || '').trim();
        out.push('UO SPOTTER \u2014 run report');
        out.push(MODE + ' \u00b7 ' + lineSize + 'v' + lineSize + ' \u00b7 ' + eng
            + ' \u00b7 clock ' + (inPlay ? 'LIVE' : 'stopped')
            + ' \u00b7 point ' + point
            + ' \u00b7 disc with ' + (discSide() || '\u2014'));

        ['O', 'D'].forEach(function (w) {
            var group = roleGroup(w);
            if (!group.length) { return; }
            out.push(teamName(w) + ': ' + group.map(function (p) {
                var on = line.some(function (q) { return q.label === p.label; });
                return (on ? '[' : '') + (p.num ? p.num + ' ' : '')
                    + (p.nick || p.last || p.label) + (on ? ']' : '');
            }).join('  ') + '   ([on the field])');
        });

        var r = checkRun();
        out.push('');
        out.push('CHECK: ' + (r.done
            ? 'all ' + r.want.length + ' events match'
            : r.same + ' of ' + r.want.length + ' match, then they diverge'));
        if (!r.done) {
            out.push('  expected  ' + (r.same < r.want.length ? shape(r.want[r.same]) : '(nothing more)'));
            out.push('  got       ' + (r.same < r.got.length ? shape(r.got[r.same]) : '(nothing)'));
        }

        var open = events.filter(function (e) {
            return e.type === 'unmatched' || e.open || e.certain === false;
        });
        out.push('');
        out.push('QUEUE (' + open.length + ')');
        open.forEach(function (e) {
            out.push('  ' + mmss(e.at) + '  ' + (e.heard ? '"' + e.heard + '"' : e.type)
                + (e.why ? '  \u2014 ' + e.why : ''));
        });

        out.push('');
        out.push('LOG (' + events.length + ')');
        events.slice().sort(function (a, b) { return a.at - b.at; }).forEach(function (e) {
            out.push('  ' + mmss(e.at) + '  ' + shape(e)
                + (e.certain === false || e.open ? '   [?]' : ''));
        });

        return out.join('\n');
    }

    function showReport(text) {
        var box = el('checkOut');
        box.replaceChildren();
        var ta = document.createElement('textarea');
        ta.className = 'report';
        ta.readOnly = true;
        ta.value = text;
        box.append(ta);
        ta.focus();
        ta.select();
    }

    function renderCheck() {
        var box = el('checkOut');
        if (!box) { return; }
        box.replaceChildren();
        var r = checkRun();

        var head = document.createElement('p');
        head.className = r.done ? 'checkok' : 'checkbad';
        head.textContent = r.done
            ? 'All ' + r.want.length + ' events match the script.'
            : (r.same === r.want.length
                ? 'The script matches, but there are ' + (r.got.length - r.want.length)
                    + ' extra event(s) after it.'
                : r.same + ' of ' + r.want.length + ' match, then they diverge.');
        box.append(head);

        if (r.done) { return; }

        var rows = [];
        if (r.same < r.want.length) {
            rows.push(['expected', shape(r.want[r.same])]);
        } else {
            rows.push(['expected', 'nothing more']);
        }
        rows.push(['got', r.same < r.got.length ? shape(r.got[r.same]) : 'nothing \u2014 the run stopped short']);

        rows.forEach(function (row) {
            var d = document.createElement('div');
            d.className = 'checkrow';
            var k = document.createElement('code');
            k.textContent = row[0];
            var v = document.createElement('b');
            v.textContent = row[1];
            d.append(k, v);
            box.append(d);
        });

        var at = document.createElement('p');
        at.className = 'sub';
        at.textContent = 'Step ' + (r.same + 1) + ' of ' + r.want.length
            + '. Everything after a missing or extra event is shifted, so this '
            + 'is the one line worth reading.';
        box.append(at);
    }

    function renderStructure() {
        var box = el('structbody');
        if (!box) { return; }
        box.replaceChildren();

        // Two different people, so an example never reads as one name twice.
        var a = line[0] ? (line[0].last || line[0].label) : 'lang';
        var b = line[1] ? (line[1].last || line[1].label) : 'weber';
        var nick = null;
        var num = null;
        line.forEach(function (p) {
            if (!nick && p.nick) { nick = p.nick; }
            if (!num && p.num) { num = p.num; }
        });

        var puller = null;
        line.forEach(function (p) { if (!puller) { puller = p.nick || p.last || p.label; } });
        // Somebody from the other side, for the examples that need an opponent.
        var mine = line[0] ? line[0].role : null;
        var rival = null;
        line.forEach(function (p) {
            if (!rival && mine && p.role && p.role !== mine) {
                rival = p.nick || p.last || p.label;
            }
        });
        var rows = [
            starting === 'D'
                ? ['pull puller', 'pull ' + (puller || 'tiny'), 'we are pulling \u2014 name ours']
                : ['pull receiver', 'pull ' + a, 'they pulled \u2014 name who picked it up'],
            ['brick', 'brick', 'the pull went out'],
            ['[throw] receiver', (line.length ? 'huck ' + a : 'huck lang'),
                'you name who CAUGHT it \u2014 never the thrower'],
            ['[grip] [throw] who', 'backhand huck ' + a,
                'backhand, forehand or offhand \u2014 either side of the throw'],
            ['drop receiver', 'drop ' + b, 'who put it down'],
            ['throwaway', 'throwaway', 'no name: the thrower is already known'],
            ['call [who] [on who]', 'foul ' + b + (rival ? ' ' + rival : ''),
                'who called it, on whom \u2014 both optional'],
            ['call [player|side]', 'foul offence', 'only what you saw signalled'],
            ['throw \u2026 then who', 'huck \u2026 tipped ' + a + ' \u2026 ' + b,
                'the disc stays in flight until somebody catches it'],
            ['conceded', 'conceded', 'the other team scored'],
            ['who on / off', (puller || a) + ' off', 'a substitution, one word'],
            ['who for who', b + ' for ' + (puller || a), 'both at once'],
            ['no \u2026', 'no ' + b, 'replaces the last one'],
            ['flag', 'flag', 'that one was wrong \u2014 settle it later'],
            ['unsure \u2026 or \u2026', 'unsure ' + a + ' or ' + b, 'records both, picks neither']
        ];
        // Attached to the THROW row, not wherever index 1 happens to be: the
        // pull now leads, so a fixed index hung "by nickname" off the pull and
        // the list read as if only pulls could be called that way.
        var throwAt = 0;
        rows.forEach(function (r, i) { if (r[0] === '[throw] receiver') { throwAt = i; } });
        var extra = [];
        if (nick) { extra.push(['\u2026 by nickname', 'huck ' + nick.toLowerCase(), 'same person']); }
        if (num) { extra.push(['\u2026 by number', 'huck ' + num, 'or "' + spokenNumber(num)[1] + '"']); }
        rows.splice.apply(rows, [throwAt + 1, 0].concat(extra));

        /*
         * The rule the whole grammar rests on, stated before the shapes.
         *
         * A spotter names the RECEIVER and never the thrower, because whoever
         * caught the last pass is holding the disc and the chain rebuilds
         * itself. Halving the words is the difference between keeping up and
         * not - and the slot label "who" hid it, which is worse than not
         * labelling it at all.
         */
        var rule = document.createElement('p');
        rule.className = 'structrule';
        rule.textContent = holder !== null && line[holder]
            ? (line[holder].nick || line[holder].last || line[holder].label)
                + ' has it \u2014 name only who catches it next.'
            : 'Name only who CATCHES it. The thrower is whoever caught the last pass.';
        box.append(rule);

        rows.forEach(function (r) {
            var d = document.createElement('div');
            d.className = 'structrow';
            var shape = document.createElement('code');
            shape.textContent = r[0];
            var ex = document.createElement('b');
            ex.textContent = r[1];
            var why = document.createElement('span');
            why.textContent = r[2];
            d.append(shape, ex, why);
            box.append(d);
        });

        /*
         * A WHOLE POINT, IN ORDER, IN THE NAMES ON THE FIELD.
         *
         * The shapes above are a reference; this is a rehearsal. It is the
         * test script run through the grammar, built from whoever is actually
         * on so a spotter can read it aloud as a warm-up and watch the log
         * fill - which is also the fastest way to find out that the engine
         * cannot hear one of the nicknames.
         */
        /*
         * THREE POINTS, OUT LOUD, WITH THE AWKWARD PARTS IN THEM.
         *
         * A separate list of edge cases taught the shapes and not the job: a
         * spotter who has only rehearsed "huck weber" still freezes the first
         * time a disc is tipped mid-flight, because the hard part is doing it
         * in sequence under a clock rather than knowing the form exists.
         *
         * So the tricky ones live inside a run that plays like a game - a
         * hold with a deflection and a correction, a break conceded off a
         * turnover, and a point with a choice and a timeout in it. Two steps
         * are SUPPOSED to end in a question rather than an event, which is
         * the thing most worth having seen happen once before it matters.
         *
         * Names come from whichever side is on for that point, because the
         * line swaps at every one and an example nobody can say is worse than
         * no example.
         */
        var script = buildScript();

        var sh = document.createElement('div');
        sh.className = 'structhead';
        sh.textContent = 'Three points, out loud';
        box.append(sh);

        // The whole value of a rehearsal is finding out whether it worked.
        var checkRow = document.createElement('div');
        checkRow.className = 'row';
        /*
         * Start clean, or the check compares against a half-played game.
         *
         * A rehearsal run on top of an existing session diverges on its first
         * line and says so, which is true and unhelpful - the spotter did
         * nothing wrong. One button puts the page in the state the script
         * assumes.
         */
        var go = document.createElement('button');
        go.textContent = '\u25b6 Start rehearsal';
        go.title = 'Clear the session, put the demo squad on and begin point 1';
        go.addEventListener('click', function () {
            wipe(true);
            lineSize = 5;
            sides = { O: [], D: [] };
            setSquad(DEMO_LINE);
            starting = 'O';
            startPoint(null, 'O');
            el('checkOut').replaceChildren();
            el('say').focus();
        });
        checkRow.append(go);

        var cp = document.createElement('button');
        cp.textContent = '\u29c9 Copy report';
        cp.title = 'The check, the queue and the whole log, as text';
        cp.addEventListener('click', function () {
            var text = buildReport();
            var done = function () {
                cp.textContent = '\u2713 copied';
                setTimeout(function () { cp.textContent = '\u29c9 Copy report'; }, 1800);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function () { showReport(text); });
            } else {
                // No clipboard permission: put it somewhere selectable rather
                // than failing silently, which is the one outcome that wastes
                // the run it was meant to report.
                showReport(text);
            }
        });
        checkRow.append(cp);

        var chk = document.createElement('button');
        chk.textContent = 'Check my run';
        chk.title = 'Replay the script through the parser and compare it with what you captured';
        chk.addEventListener('click', renderCheck);
        var out = document.createElement('div');
        out.id = 'checkOut';
        out.className = 'checkout';
        checkRow.append(chk);
        box.append(checkRow, out);

        var list = document.createElement('ol');
        list.className = 'structscript';
        script.forEach(function (step) {
            var li = document.createElement('li');
            if (/^POINT /.test(step[0])) {
                li.className = 'mark';
                li.textContent = step[0] + ' \u2014 ' + step[1];
                list.append(li);
                return;
            }
            var said = document.createElement('b');
            said.textContent = step[0];
            li.append(said);
            if (step[1]) {
                var why = document.createElement('span');
                why.textContent = step[1];
                if (/^REFUSED|^FLAGGED/.test(step[1])) { why.className = 'no'; }
                li.append(why);
            }
            list.append(li);
        });
        box.append(list);

        // Order is not part of the structure, and that is worth one line
        // rather than a paragraph nobody reads.
        var note = document.createElement('p');
        note.className = 'structnote';
        note.textContent = 'Order does not matter \u2014 "huck ' + a + '" and "' + a
            + ' huck" are the same call. Never say a word you do not mean: '
            + 'anything that does not land becomes a question.';
        box.append(note);
    }

    function renderSlots() {
        var box = el('slots');
        if (!box) { return; }
        box.replaceChildren();
        [
            ['action', lastCall && lastCall.action
                ? lastCall.action.value : null, 'throw · outcome · call'],
            ['who', lastCall && lastCall.who ? lastCall.who.word : null, 'the six on the line']
        ].forEach(function (pair) {
            var d = document.createElement('div');
            d.className = 'slot ' + (pair[1] ? 'filled' : 'empty');
            var s1 = document.createElement('span');
            s1.textContent = pair[0];
            var b = document.createElement('b');
            b.textContent = pair[1] || pair[2];
            d.append(s1, b);
            box.append(d);
        });

        var unresolved = events.filter(function (e) {
            return e.type === 'unmatched' || e.open || e.certain === false;
        }).length;
        var q = el('unres');
        if (q) {
            /*
             * A spotter says nothing without purpose, so anything that did not
             * land cleanly is a question to answer, not noise to bury in a
             * panel. It shows while play continues; the panel below is where
             * it gets settled at the next stoppage.
             */
            q.textContent = unresolved ? '? ' + unresolved : '';
            q.className = unresolved ? 'unres on' : 'unres';
        }

        var bf = el('buffer');
        if (bf) {
            var span = audioChunks.length > 1
                ? Math.round((audioChunks[audioChunks.length - 1].at - audioChunks[1].at))
                : 0;
            bf.textContent = audioMode() === 'off' || !span ? ''
                : 'audio held: ' + span + 's'
                  + (span > BUFFER_SECONDS + 5 ? ' (held open for the queue)' : '');
        }

        var pz = el('poss');
        if (pz) {
            pz.textContent = 'point ' + point + ' · '
                + (possession === 'them' ? 'THEY have it' : 'our disc');
        }

        var f = el('flight');
        if (inFlight) {
            f.textContent = 'in the air: ' + (inFlight.type || 'a throw')
                + (inFlight.tips.length ? ', tipped by ' + inFlight.tips.join(', ') : '')
                + ' — waiting for the catch';
        } else {
            f.textContent = '';
        }

        var leg = el('legend');
        if (leg && !leg.dataset.done) {
            leg.dataset.done = '1';
            var lines = [
                '<b>[throw] who</b> — "huck lang", or just "lang" when the type was already said',
                '<b>outcome [who]</b> — "drop reiter", "goal", "throwaway"',
                '<b>call [who]</b> — "foul weber", "travel"',
                '<b>tipped [who]</b> — the disc is still up',
                '<b>no …</b> — replaces the last event with whatever follows',
                '<b>missed · afk · undo</b>'
            ];
            Object.keys(SAY).forEach(function (kind) {
                lines.push('<b>' + kind + ':</b> ' + Object.keys(SAY[kind]).join(', '));
            });
            leg.innerHTML = lines.join('<br>');
        }
    }

    /**
     * What could not be read, waiting for a pause.
     *
     * A spotter does not say things with no purpose, so every one of these is
     * a call that failed — not chatter. They are worth more at a stoppage than
     * mid-point, which is why this shouts only when the video is paused.
     */
    function renderReview() {
        var box = el('review');
        if (!box) { return; }
        // Three kinds of unfinished business, one queue: nothing understood,
        // a choice nobody made, and something the spotter flagged as a guess.
        var open = events.filter(function (e) {
            return e.type === 'unmatched' || e.open || e.certain === false;
        });
        box.replaceChildren();
        el('reviewInfo').textContent = open.length
            ? open.length + ' waiting' : 'nothing waiting';
        el('reviewPanel').className = 'panel' + (open.length && atRest() ? ' due' : '');

        el('reviewInfo').textContent = open.length
            ? (open.length > 8 ? 'showing 8 of ' + open.length : open.length + ' waiting')
            : 'nothing waiting';
        open.slice(0, 8).forEach(function (e) {
            var d = document.createElement('div');
            d.className = 'fix';
            var said = document.createElement('div');
            said.className = 'said';
            said.innerHTML = mmss(e.at) + ' heard <b>' + (e.heard || e.type) + '</b> — '
                + (e.why || (e.open ? 'a choice to settle' : 'flagged unsure'));
            var opts = document.createElement('div');
            opts.className = 'opts';

            // A choice the spotter offered: the options are the only buttons
            // that make sense, because they are what they actually saw.
            if (e.open && e.options) {
                Object.keys(e.options).forEach(function (slot) {
                    e.options[slot].forEach(function (word) {
                        var b = document.createElement('button');
                        b.textContent = word;
                        b.addEventListener('click', function () {
                            if (slot === 'side') { e.side = word; }
                            if (slot === 'who') {
                                if (e.type === 'regain') {
                                    e.by = word;
                                    // The throw that followed had no thrower
                                    // because this was unanswered. Now it has.
                                    var at = events.indexOf(e);
                                    for (var k = at + 1; k < events.length; k += 1) {
                                        if (events[k].type === 'throw') {
                                            if (!events[k].from) { events[k].from = word; }
                                            break;
                                        }
                                        if (events[k].type === 'point') { break; }
                                    }
                                } else { e.to = word; }
                            }
                            if (slot === 'action') { e.how = word; }
                            if (slot === 'team') {
                                if (word === 'turnover I missed') {
                                    e.type = 'turnover';
                                    e.how = 'uncalled';
                                    e.why = 'confirmed by the spotter after the fact';
                                    possession = possession === 'us' ? 'them' : 'us';
                                    holder = null;
                                } else if (word === 'wrong name') {
                                    events = events.filter(function (x) { return x !== e; });
                                } else {
                                    e.team = word;
                                }
                            }
                            delete e.open;
                            delete e.options;
                            e.corrected = true;
                            render();
                        });
                        opts.append(b);
                    });
                });
                d.append(said, opts);
                box.append(d);
                return;
            }

            var held = audioChunks.length > 1 ? audioChunks[1].at : 0;
            var haveAudio = audioChunks.length && e.audioAt !== null
                && e.audioAt !== undefined && e.audioAt >= held - 1;
            if (!haveAudio && e.audioAt !== null && e.audioAt !== undefined
                && audioMode() !== 'off') {
                // Say so rather than offering a button that plays silence.
                var gone = document.createElement('span');
                gone.className = 'f';
                gone.textContent = ' (audio aged out) ';
                opts.append(gone);
            }
            if (haveAudio) {
                // The word itself, and the words round it when one is not
                // enough to tell what was meant.
                var word = document.createElement('button');
                word.textContent = '▶';
                word.title = e.audioEnd ? 'hear the word' : 'hear roughly that moment';
                word.addEventListener('click', function () {
                    // Exact when the engine gave word timings, a second either
                    // side when it did not.
                    playMoment(e.audioAt, e.audioEnd ? (e.audioEnd - e.audioAt) / 2 + 0.15 : 1.2,
                        e.audioEnd ? (e.audioAt + e.audioEnd) / 2 : e.audioAt);
                });
                var ctx2 = document.createElement('button');
                ctx2.textContent = '▶▶';
                ctx2.title = 'hear it in context';
                ctx2.addEventListener('click', function () { playMoment(e.audioAt, 4); });
                opts.append(word, ctx2);
            }

            // Flagged unsure but otherwise complete: confirming is one tap.
            if (e.certain === false && e.type !== 'unmatched') {
                var yes = document.createElement('button');
                yes.textContent = '✓ confirm';
                yes.addEventListener('click', function () { delete e.certain; render(); });
                opts.append(yes);
            }

            line.forEach(function (p, i) {
                var b = document.createElement('button');
                b.textContent = p.nick || p.first || p.label;
                b.addEventListener('click', function () {
                    e.type = 'throw';
                    e.to = line[i];
                    e.corrected = true;
                    render();
                });
                opts.append(b);
            });
            ['drop', 'throwaway', 'blocked', 'goal'].forEach(function (what) {
                var b = document.createElement('button');
                b.textContent = what;
                b.addEventListener('click', function () {
                    e.type = what === 'goal' ? 'goal' : 'turnover';
                    e.how = what === 'goal' ? undefined : what;
                    e.corrected = true;
                    render();
                });
                opts.append(b);
            });
            var drop = document.createElement('button');
            drop.textContent = '✕';
            drop.title = 'nothing — discard it';
            drop.addEventListener('click', function () {
                events = events.filter(function (x) { return x !== e; });
                render();
            });
            opts.append(drop);

            d.append(said, opts);
            box.append(d);
        });
    }

    /**
     * Ninety minutes in a browser tab, with nothing written down.
     *
     * A reload, a stray Cmd-W or a crashed tab lost the entire session. So
     * every change is kept in this browser and picked up again on the next
     * load. Local to this machine like everything else here, and clearable —
     * it holds player names.
     */
    var STORE = 'uo-spotter-session';

    function remember() {
        try {
            window.localStorage.setItem(STORE, JSON.stringify({
                saved: Date.now(), video: el('url').value, mode: MODE, liveFrom: liveFrom,
                squad: squad, lineSize: lineSize,
                line: line, lines: lines, point: point, events: events
            }));
        } catch (e) { /* private window, or full; the session still works */ }
    }

    var restoredMode = null;
    /** Longer than this away and the gap is declared rather than absorbed. */
    var RELOAD_GAP = 60;

    function restore() {
        var raw;
        try { raw = window.localStorage.getItem(STORE); } catch (e) { return; }
        if (!raw) { return; }
        try {
            var doc = JSON.parse(raw);
            if (!doc || !doc.events || !doc.events.length) { return; }
            events = doc.events;
            lines = doc.lines || {};
            point = doc.point || 1;
            restoredMode = doc.mode || null;
            if (doc.lineSize) { lineSize = doc.lineSize; }
            if (doc.squad) { squad = doc.squad.map(asPlayer); }
            /*
             * The wall clock resumes where it was, rather than restarting at
             * zero. Without this a reload mid-game would stamp the next event
             * before every event already captured, and the log would fold in
             * on itself.
             */
            if (doc.liveFrom) { liveFrom = doc.liveFrom; }

            /*
             * A LONG GAP IS UNWATCHED TIME, NOT PLAY.
             *
             * The live clock runs on the wall, so a session resumed the next
             * morning comes back with `at()` a day further on - and any
             * interval left open, or the last point's window, silently
             * stretches across the whole night. Every figure derived from it
             * inflates: a player's live time, the live share, the rates.
             *
             * It is the same failure as a backgrounded tab and gets the same
             * answer: declare it. Nobody was watching, so it is missing time
             * rather than quiet time, and it is visible in the log as such.
             */
            if ((doc.mode || 'live') === 'live' && doc.saved) {
                var gapFrom = (doc.saved - liveFrom) / 1000;
                var gap = (Date.now() - doc.saved) / 1000;
                if (gap > RELOAD_GAP && gapFrom > 0) {
                    events.push({ id: id(), point: doc.point || 1,
                                  at: Math.round(gapFrom * 10) / 10,
                                  until: Math.round((gapFrom + gap) * 10) / 10,
                                  type: 'afk', why: 'the session was closed' });
                }
            }
            if (doc.line) { line = doc.line.map(asPlayer); }
            if (doc.video && !el('url').value) { el('url').value = doc.video; }
            rebuildHolder();
            el('resume').textContent = 'resumed ' + events.length + ' events from '
                + new Date(doc.saved).toLocaleTimeString();
        } catch (e) { /* unreadable; start clean rather than half-loaded */ }
    }

    function render() {
        if (replaying) { return; }
        renderLine();
        renderLog();
        if (document.body.dataset.view === 'stats') { renderStats(); renderCoach(); }
        renderStructure();
        renderSlots();
        renderReview();
        remember();
    }

    // ---- input -------------------------------------------------------------
    function missed() { push('gap', {}); holder = null; }

    /**
     * "That one is wrong" — the marker for what nothing else catches.
     *
     * The review queue fills itself from doubt: a word that matched nothing,
     * a choice the spotter offered, a word the engine scored low. All three
     * are cases where the MACHINE knew something was off.
     *
     * The expensive error is the other kind. The grammar decoded cleanly, the
     * confidence was high, the name went in — and it was the wrong Weber. No
     * question gets raised, so the audio ages out of the buffer on schedule
     * and the evidence is gone by the time anyone looks.
     *
     * One tap, one key, one word puts it in the queue instead. And because
     * the retention rule already holds audio for anything unsettled, flagging
     * pins the recording of it for free — which is the point: the spotter
     * knows within a second, and can settle it at the next stoppage.
     */
    function flag(e, why) {
        if (!e) { return; }
        if (e.certain === false && e.flagged) {
            // Flagging twice means "no, it was fine" — otherwise a mistap
            // would be a queue entry nobody can clear.
            delete e.certain;
            delete e.flagged;
            delete e.why;
        } else {
            e.certain = false;
            e.flagged = true;
            e.why = why || 'the spotter marked this';
        }
        render();
    }

    /**
     * Remove the last observation, with the clock markers it caused.
     *
     * Undo popped one row, and the play clock writes a row after the event
     * that moved it — so undoing a throw took the marker and left the throw,
     * and a second press was needed for what the spotter thought was one
     * mistake. Corrections had the same problem. One rule for both.
     */
    function popObservation() {
        var undone = [];
        while (events.length && events[events.length - 1].type === 'play') {
            undone.push(events.pop());
        }
        var gone = events.pop() || null;
        rebuildHolder();
        return { event: gone, markers: undone };
    }

    /**
     * The last thing OBSERVED, which is what an unqualified flag means.
     *
     * Not simply the last entry: a throw is followed by the play-clock marker
     * it triggered, and a point by the one that stopped the clock. Flagging
     * "the last event" would have queued a bookkeeping row every time, while
     * the throw the spotter was objecting to stayed unflagged and its audio
     * aged out on schedule.
     */
    var BOOKKEEPING = { point: 1, play: 1, afk: 1, sub: 1 };

    function lastEvent() {
        for (var i = events.length - 1; i >= 0; i -= 1) {
            if (!BOOKKEEPING[events[i].type]) { return events[i]; }
        }
        return null;
    }

    function toggleAfk() {
        if (afkFrom === null) {
            afkFrom = at();
            push('afk', {});
            el('afk').className = 'big warn on';
        } else {
            // Close the interval on the event that opened it, so a capture
            // carries "nobody was watching from here to here" rather than a
            // bare marker somebody has to interpret later.
            for (var i = events.length - 1; i >= 0; i -= 1) {
                if (events[i].type === 'afk' && !events[i].until) {
                    /*
                     * Rounded like the opening stamp, and never before it.
                     *
                     * `push` rounds `at` to a tenth and this did not, so a
                     * brief AFK stored 0.4 -> 0.368 and the interval ran
                     * backwards. Anything summing it then subtracted time
                     * that was never spent.
                     */
                    events[i].until = Math.max(events[i].at, Math.round(at() * 10) / 10);
                    break;
                }
            }
            afkFrom = null;
            el('afk').className = 'big warn';
            render();
        }
    }

    // ---- voice -------------------------------------------------------------
    /**
     * Speech in, one of a handful of legal targets out.
     *
     * **Chrome only, and the audio leaves the machine.** Web Speech streams to
     * Google, which is why this is a prototype for answering "does voice work
     * at all" and not something an installation would ship — `§10a` says a
     * deployed version runs a local model. What survives that swap is the
     * matcher above: the engine is replaceable, the constraint is the design.
     *
     * The tally is the experiment. Heard, matched, refused — per class — is
     * exactly the per-class accuracy bar the decisions require before any of
     * this may reach air.
     */
    var recog = null;
    var voiceOn = false;
    var tally = { heard: 0, matched: 0, refused: 0, ambiguous: 0, byForm: {} };
    var lastCall = null;
    /** The timings of the utterance being applied, when the engine gives them. */
    var heardAt = null;

    /** Replay this point to work out who has the disc, after any edit. */
    /** Replay THIS point to work out who has the disc, after any edit. */
    /**
     * The play clock, rebuilt from the whole log rather than the point.
     *
     * `inPlay` is derived state and every other piece of derived state here is
     * recomputed after an undo or a correction. This one was not, so popping
     * the marker that opened a live period left the flag still reading "live"
     * — and the next `setPlay(true)` did nothing, because nothing had
     * changed. The interval then had no opening and vanished from the
     * accounting entirely: a live period silently worth zero seconds.
     *
     * Across all events, not just the current point, because a stoppage can
     * outlive the point that began it.
     */
    function rebuildPlay() {
        inPlay = false;
        events.forEach(function (e) { if (e.type === 'play') { inPlay = e.live; } });
    }

    function rebuildHolder() {
        rebuildPlay();
        holder = null;
        inFlight = null;
        possession = 'us';
        events.filter(function (e) { return e.point === point; }).forEach(function (e) {
            if (e.type === 'throw') {
                holder = -1;
                line.forEach(function (p, i) { if (p.label === e.to) { holder = i; } });
                if (holder === -1) { holder = null; }
            }
            if (e.type === 'turnover') {
                holder = null;
                possession = 'them';
                discWith = discWith ? other(discWith) : null;
            }
            if (e.type === 'block') {
                possession = 'us';
                holder = null;
                discWith = e.side || (discWith ? other(discWith) : null);
            }
            if (e.type === 'pull') {
                possession = e.ours ? 'them' : 'us';
                holder = null;
                discWith = e.receiving || null;
            }
            if (e.type === 'regain') { holder = null; possession = 'us'; }
            if (e.type === 'goal' || e.type === 'gap' || e.type === 'conceded') { holder = null; }
        });
    }

    /**
     * A point begins, and the line it is played by is recorded with it.
     *
     * The line was one global list, set once. A game substitutes the whole
     * seven between points, so from the second point every key was attached to
     * whoever happened to be in that position at the start — silently, and the
     * recogniser's vocabulary never learned the new names at all.
     */
    /**
     * Move the play clock, recording HOW we came to believe it moved.
     *
     * `how` is 'said' when the spotter said so and 'auto' when it followed
     * from something else they said. The distinction is reported rather than
     * smoothed over: a clock built mostly from inference deserves less trust
     * than one a person drove, and the stats panel says which this was.
     */
    function setPlay(live, how, why) {
        if (live === inPlay) { return; }
        inPlay = live;
        var e = { id: id(), point: point, at: Math.round(at() * 10) / 10,
                  type: 'play', live: live, how: how, why: why || null };
        events.push(e);
        if (how === 'said') { render(); }
    }

    /**
     * Play-clock consequences of an ordinary call.
     *
     * Three of these are rules, not guesses: a pull starts play, a goal ends
     * it, a foul stops it. The fourth is the repair that keeps the clock
     * honest when a spotter forgets to say "check" — a THROW cannot happen
     * while the disc is dead, so seeing one means play resumed. Without that
     * a single unclosed stoppage would swallow the rest of the point.
     */
    function playFromEvent(e) {
        if (e.type === 'play' || e.type === 'afk') { return; }
        if (e.type === 'goal' || e.type === 'conceded') {
            setPlay(false, 'auto', e.type === 'goal' ? 'goal' : 'they scored');
            return;
        }
        if (e.type === 'call') {
            if (e.call === 'check') { setPlay(true, 'auto', 'check'); return; }
            if (STOPS_PLAY[e.call]) { setPlay(false, 'auto', e.call); }
            return;
        }
        if (e.type === 'pull') { setPlay(true, 'auto', 'pull'); return; }
        if (e.type === 'throw') { setPlay(true, 'auto', 'a throw'); }
    }

    /**
     * The live intervals, rebuilt from the log rather than accumulated.
     *
     * Accumulating a running total cannot survive undo, re-spotting a point,
     * or opening a saved file. Replaying the transitions can, and costs
     * nothing at this scale.
     */
    function playIntervals() {
        var out = [];
        var open = null;
        events.slice().sort(function (a, b) { return a.at - b.at; })
            .forEach(function (e) {
                if (e.type !== 'play') { return; }
                if (e.live && open === null) { open = e.at; }
                if (!e.live && open !== null) { out.push([open, e.at]); open = null; }
            });
        /*
         * An interval still open ends "now" - except that in training, now
         * can be EARLIER than it began, because the operator seeked backwards
         * to re-spot a point. A backwards interval is not a short one; it is
         * a malformed one, and it would travel into every figure derived from
         * it. Clamped to zero length instead.
         */
        if (open !== null) { out.push([open, Math.max(open, at())]); }
        return out;
    }

    /** AFK windows, which make live time unknowable rather than zero. */
    function afkIntervals() {
        return events.filter(function (e) { return e.type === 'afk'; })
            .map(function (e) { return [e.at, e.until === undefined ? at() : e.until]; });
    }

    function overlap(a, b) {
        return Math.max(0, Math.min(a[1], b[1]) - Math.max(a[0], b[0]));
    }

    /**
     * Live seconds within a window, and how many of them nobody was watching.
     *
     * Two numbers, never one. A window that is 80% AFK has a live figure that
     * should not be quoted on its own, and the caller cannot know that unless
     * it travels alongside.
     */
    function liveIn(window, play, afk) {
        // Both lists sort the whole log, and this is called once per point.
        // A caller looping over points passes them in; a one-off caller does
        // not have to care.
        var ivs = play || playIntervals();
        var away = afk || afkIntervals();
        var live = 0;
        var blind = 0;
        ivs.forEach(function (iv) {
            var seen = overlap(iv, window);
            if (!seen) { return; }
            live += seen;
            var clipped = [Math.max(iv[0], window[0]), Math.min(iv[1], window[1])];
            away.forEach(function (a) { blind += overlap(clipped, a); });
        });
        return { live: live, blind: blind };
    }

    /** Each point as [from, to], which is what per-player time is summed over. */
    function pointWindows() {
        var marks = events.filter(function (e) { return e.type === 'point'; })
            .slice().sort(function (a, b) { return a.at - b.at; });
        return marks.map(function (m, i) {
            return { point: m.point, window: [m.at, i + 1 < marks.length ? marks[i + 1].at : at()] };
        });
    }

    /**
     * Which line is out, and therefore who starts with the disc.
     *
     * `possession` was hardcoded to `us` at every point, which is only true
     * of an O point. On a D point our team PULLS, so the other side has the
     * disc from the moment play starts, and every name called during their
     * possession was being recorded as a throw by ours - the fabricated event
     * this whole design refuses, happening on roughly half of all points.
     */
    var starting = 'O';

    /**
     * WHICH TEAM HAS THE DISC, WHEN THE TWO LINES ARE TWO TEAMS.
     *
     * The roles started as one squad's O and D lines. They are more useful
     * read as the two SIDES of a point - the team that receives and the team
     * that pulls - because then every player name carries a team with it, and
     * the data can check itself.
     *
     * The rule that makes it worth doing: a team cannot catch its own pull,
     * and cannot complete a pass to the other team. So a name from the wrong
     * side is either a mis-recognition or a turnover nobody called - and
     * either way it is a QUESTION, not an event. Recording it would invent a
     * pass between opponents, which is the failure this design refuses.
     *
     * DERIVED, NEVER STORED.
     *
     * It was a variable set at the pull, and nothing updated it when the disc
     * changed hands - so after a block won it back, the check still believed
     * the other side had it and refused our own players by name. A stored
     * copy of something that moves is a bug with a delay on it.
     */
    /**
     * WHICH SIDE HAS THE DISC, WITH BOTH TEAMS ON THE FIELD.
     *
     * With one team on, "us" and "them" were enough and the side could be
     * worked out from who was picked. A single spotter covering both teams
     * has no "us": both fives are on, both sets of names are sayable, and
     * the question "whose disc is it" has to be answered about the GAME
     * rather than about the spotter.
     *
     * Authoritative from the holder when somebody is holding it - a player
     * carries their side with them, so that cannot go stale. Stored only
     * across the gap between losing it and the next catch, which is the one
     * stretch when nobody is holding anything.
     */
    var discWith = null;

    function discSide() {
        if (holder !== null && line[holder]) { return sideOf(line[holder]) || discWith; }
        return discWith;
    }

    /** Which side a named player is on, when the squad says. */
    function sideOf(p) { return p && p.role ? p.role : null; }

    /**
     * Is this name possible right now?
     *
     * Only asked when both sides are tagged and somebody has the disc -
     * without that there is nothing to contradict, and a squad with no roles
     * must not start refusing names.
     */
    function wrongSide(p) {
        var has = discSide();
        if (!has || !sideOf(p)) { return false; }
        if (!roleGroup('O').length || !roleGroup('D').length) { return false; }
        return sideOf(p) !== has;
    }

    /**
     * What the next point should be, from what just happened.
     *
     * Whoever scores pulls next. So our goal means we are on D, and conceding
     * means we receive. Inferred rather than asked, and overridable, because
     * it is right every time except the first point of a half.
     */
    function nextStart() {
        for (var i = events.length - 1; i >= 0; i -= 1) {
            if (events[i].type === 'goal') { return 'D'; }
            if (events[i].type === 'conceded') { return 'O'; }
        }
        return starting;
    }

    function startPoint(names, which) {
        if (events.length) { point += 1; }
        starting = which === 'O' || which === 'D' ? which : nextStart();
        /*
         * The line that matches the point goes on by itself.
         *
         * Without this, starting a point left whoever happened to be on -
         * after a squad load that is the first seven, which is four from one
         * line and three from the other and therefore neither. The spotter
         * saw "one line" that was not a line at all.
         *
         * Only when the squad says which is which, and only as a starting
         * point: the picker is right there and a real line is never exactly
         * the roster group.
         */
        /*
         * BOTH SIDES GO ON, BECAUSE BOTH SIDES ARE PLAYING.
         *
         * Only the receiving side was put out, which was right when a spotter
         * followed one team and is wrong now: the other five are on the
         * field, their names have to be sayable, and without them a pull by
         * the pulling side names nobody.
         */
        /*
         * THE FIELD EMPTIES AT EVERY POINT.
         *
         * A line carried over models something that rarely happens: teams
         * change most of the seven between points, so keeping the last line
         * meant the picker was usually wrong and quietly so - every name
         * accepted, every minute of playing time credited, to players who
         * had walked off.
         *
         * Empty is the honest state, and it makes calling the lines the
         * first act of a point rather than an optional correction. A point
         * nobody names a line for records no line, which the coverage note
         * reports rather than inventing one.
         *
         * The first point of a session is the exception: there is nothing to
         * have changed from, so the roster groups go on as a starting point.
         */
        if (!names || !names.length) {
            var first = !Object.keys(lines).some(function (k) {
                return Number(k) < point && (lines[k] || []).length;
            });
            if (first) {
                ['O', 'D'].forEach(function (w) {
                    var preset = roleGroup(w);
                    /*
                     * Only when the group IS a line, not when it merely
                     * contains one.
                     *
                     * This took the first `lineSize` of the group, which was
                     * right while a squad was seven people typed in to try the
                     * tool. A reference pack carries a tournament roster of
                     * twenty-six, ordered by whatever the source listed first
                     * - for the WFDF stats table, by scoring - so the "line"
                     * it put out was the seven highest scorers, a line nobody
                     * played, presented as though somebody had called it.
                     */
                    if (preset.length && preset.length <= lineSize
                        && !sides[w].length) {
                        sides[w] = preset.slice(0, lineSize);
                    }
                });
            } else {
                sides = { O: [], D: [] };
                pendingLine = null;
            }
            rebuildLine();
        }
        if (names && names.length) { setLine(names); }
        lines[point] = line.slice();
        holder = null;
        inFlight = null;
        possession = starting === 'D' ? 'them' : 'us';
        push('point', { line: line.slice(), starting: starting });
        setPlay(false, 'auto', 'between points');
        rebuildGrammar();
    }

    function other(side) { return side === 'O' ? 'D' : 'O'; }


    /** Everyone tagged for one line, which is what a preset fills from. */
    /*
     * The gender ratio, as a spotter responsibility - optional, because it is
     * one somebody else may already be holding.
     *
     * MATCHCONTROL.md section 11 left open whether the ratio moves to whoever
     * is watching closely enough to press a button per point. That is this
     * person. But taking it is a CHOICE: two desks declaring the same value is
     * worse than one declaring it, and a spotter on a single-gender game has
     * nothing to declare at all.
     *
     * No rule is restated here. `Ratio` owns which points repeat point one's
     * ratio and what the pair at a given size even is; `Declared` owns local
     * versus shared. This function does no arithmetic, which is the whole
     * reason both files exist.
     */
    var ratioOn = (function () {
        try { return window.localStorage.getItem('uo-spot-ratio-on') === '1'; }
        catch (e) { return false; }      // private mode: off, and settable
    }());
    var firstRatio = null;

    function ratioValue() {
        if (!window.Declared || !window.Ratio) { return null; }
        if (!firstRatio) {
            firstRatio = window.Declared.value({
                key: 'uo-spot-ratio1-' + (videoId(el('url').value) || 'session'),
                /*
                 * Nothing to ride yet: this page has no possession store, so
                 * there is no shared value to read and nowhere to push one.
                 * When the spotter gains the desk's code, `read` becomes the
                 * store and `canShare` its capability - and Declared already
                 * knows how to let an arriving shared value displace a local
                 * one, with the note that says so.
                 */
                read: function () { return null; },
                parse: function (raw) {
                    var v = String(raw || '');
                    return (window.Ratio.pairForSize(lineSize) || []).indexOf(v) !== -1
                        ? v : null;
                },
                push: function () { return Promise.resolve(null); },
                canShare: function () { return false; }
            });
        }
        return firstRatio;
    }

    function renderRatio(head) {
        if (!window.Ratio || !window.Declared || !window.RatioUI) { return; }

        var wrap = document.createElement('span');
        wrap.className = 'ratiobox';

        var opt = document.createElement('label');
        opt.className = 'sub';
        var box = document.createElement('input');
        box.type = 'checkbox';
        box.checked = ratioOn;
        box.title = 'Call the gender ratio too \u2014 leave it off if a desk holds it';
        box.addEventListener('change', function () {
            ratioOn = box.checked;
            try {
                window.localStorage.setItem('uo-spot-ratio-on', ratioOn ? '1' : '');
            } catch (e) { /* private mode: the choice lasts this session */ }
            render();
        });
        opt.append(box, document.createTextNode(' ratio'));
        wrap.append(opt);

        if (ratioOn) {
            var v = ratioValue();
            // The SAME picker the commentary desk shows, from shared/ratio-ui.js.
            var sel = window.RatioUI.select({
                size: lineSize,
                current: v.get(),
                canShare: false,
                className: 'ratiosel',
                onChange: function (value) { v.set(value); render(); }
            });
            if (sel) { wrap.append(sel); }

            var chip = window.RatioUI.chip({
                size: lineSize,
                first: v.get(),
                point: point
            });
            if (chip) { wrap.append(chip); }
        }

        head.append(wrap);
    }

    /*
     * Matchings the commentary desk already typed.
     *
     * `shared/notes.php` is keyed by CODE rather than by game - a desk that
     * keeps its code keeps its notes for the whole tournament - so a spotter
     * given that code inherits them with nobody typing a roster twice. This
     * is the answer to "who supplies the matchings": not the spotter.
     *
     * READ ONLY. The desk owns this material; a spotter quietly rewriting a
     * commentator's note is not a trade worth making, and the one field taken
     * here is the one the picker needs.
     */
    function pullNotes() {
        var code = (el('deskcode').value || '').trim();
        var say = function (t) { el('deskinfo').textContent = t; };
        if (!code) { say('enter the desk\u2019s code'); return; }
        if (!SPOTTER_CONFIG.notesUrl) { say('no notes store on this install'); return; }
        if (!squad.some(function (p) { return p.id; })) {
            // Said plainly rather than reported as "nothing found": a squad
            // typed by hand or built from a pack has no ids, and no code will
            // ever make this work until it does.
            say('this squad has no player ids, so notes cannot be matched to it');
            return;
        }
        say('asking\u2026');
        fetch(SPOTTER_CONFIG.notesUrl + '&code=' + encodeURIComponent(code),
            { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .catch(function () { return null; })
            .then(function (body) {
                var players = body && body.players;
                if (!players) { say('no room with that code'); return; }
                var filled = 0;
                squad.forEach(function (p) {
                    var note = p.id ? players[p.id] : null;
                    var m = note ? String(note.matching || '').toUpperCase() : '';
                    if ((m === 'MMP' || m === 'FMP') && p.matching !== m) {
                        p.matching = m;
                        filled += 1;
                    }
                });
                say(filled ? filled + ' matchings from the desk'
                    : 'that room has no matchings for this squad');
                rebuildGrammar();
                render();
            });
    }

    function roleGroup(which) {
        return squad.filter(function (p) { return p.role === which; });
    }

    /**
     * Confidence low enough to doubt, from the recogniser rather than from us.
     *
     * A word the engine itself was unsure of is exactly the kind of thing the
     * grammar would otherwise wave through: it decodes to something legal
     * because it must, and a legal answer looks like a confident one.
     */
    var CONF_DOUBT = 0.65;

    /**
     * ONE BREATH IS NOT ONE CALL.
     *
     * A spotter narrating live does not pause between calls, so the
     * recogniser hands over whatever it heard between silences - "hammer two
     * mo layout d hawk", "bear pull hawk tiny picks up tiny goal". Every one
     * of those was refused whole as too long, which threw away four correct
     * calls because they arrived together.
     *
     * Split at ACTION boundaries: a second throw, outcome, call or command
     * means a second call began, because the grammar allows exactly one of
     * those per utterance. Windows are tried before single words so that
     * "layout d", "picks up" and "tapped in" are not cut down the middle.
     *
     * Only attempted when the whole thing does not parse, so an ordinary
     * short call is never taken apart on suspicion.
     */
    var SPLITS = { throw: 1, outcome: 1, call: 1, control: 1, fix: 1 };

    function splitUtterance(said) {
        var words = String(said).toLowerCase().replace(/[^a-z\u00e0-\u00ff0-9\s]/g, ' ')
            .split(/\s+/).filter(Boolean);
        var out = [];
        var cur = [];
        var armed = false;
        var i = 0;

        while (i < words.length) {
            var took = 0;
            var kind = null;
            var word = null;
            [3, 2, 1].forEach(function (n) {
                if (took || i + n > words.length) { return; }
                var m = matchWord(words.slice(i, i + n).join(' '));
                if (m && m.hit && (n === 1 || SPLITS[m.hit.kind])) {
                    took = n;
                    kind = m.hit.kind;
                    word = m.hit.value;
                }
            });
            if (!took) { took = 1; }

            /*
             * WHICH SECOND ACTION MEANS A SECOND CALL.
             *
             * Not all of them. "huck weber drop" is one event - a huck that
             * was dropped - and the grammar joins those deliberately, as it
             * joins "foul uncontested". Splitting on any second action would
             * take both apart.
             *
             * A second THROW is always a second call; nobody throws twice in
             * one breath. A goal is too: "huck rocket goal" is a pass and
             * then a score, which is two events, where "huck rocket drop" is
             * one failed pass. And a control word - new point, tapped in,
             * picks up - always starts afresh.
             */
            var pairs = armed === 'throw' && kind === 'outcome' && word !== 'goal';
            var resolves = armed === 'call' && kind === 'call' && RESOLUTION[word];
            if (kind && SPLITS[kind] && armed && cur.length && !pairs && !resolves) {
                out.push(cur.join(' '));
                cur = [];
                armed = false;
            }
            if (kind && SPLITS[kind]) { armed = kind; }
            cur = cur.concat(words.slice(i, i + took));
            i += took;
        }
        if (cur.length) { out.push(cur.join(' ')); }

        return out;
    }

    /**
     * A LINE CALL ARRIVES IN PIECES, BECAUSE PEOPLE PAUSE BETWEEN NAMES.
     *
     * The recogniser segments on silence, and naming five players has four
     * silences in it - so "d line tiny mo wags brandy kova" comes back as
     * "d line", then "tiny and mo is", then "wags brandy kovac". Each piece
     * was judged on its own: the keyword had no names, the names were not on
     * the field, and the line was never set.
     *
     * So a keyword with too few names opens a window. While it is open, an
     * utterance that is NOTHING BUT squad names is read as more of the same
     * line rather than as a call. Anything else closes it, which is the
     * safeguard: one real call ends the collecting, so a forgotten window
     * cannot swallow the game.
     */
    var pendingLine = null;
    var LINE_WINDOW = 12000;

    function lineWindowOpen() {
        if (!pendingLine) { return false; }
        if (Date.now() - pendingLine.since > LINE_WINDOW) { pendingLine = null; return false; }
        return true;
    }

    /** Every resolved word is a squad name and nothing else. */
    function onlyNames(said) {
        var words = String(said).toLowerCase().replace(/[^a-z\u00e0-\u00ff0-9\s]/g, ' ')
            .split(/\s+/).filter(Boolean)
            .filter(function (w) { return IGNORE.indexOf(w) === -1 && w !== OR; });
        if (!words.length) { return null; }
        var found = [];
        var ok = words.every(function (w) {
            var p = matchInSquad(w);
            if (p) { found.push(p); return true; }
            // A word nothing in the squad answers to ends the collecting.
            return false;
        });

        return ok && found.length ? found : null;
    }

    function commitPending() {
        if (!pendingLine || !pendingLine.players.length) { pendingLine = null; return; }
        setSide(pendingLine.side, pendingLine.players);
        lines[point] = line.slice();
        pendingLine = null;
        render();
    }

    function applyCall(c, timing) {
        if (provisional.length) { provisional = []; }
        tally.heard += 1;
        lastCall = c;
        heardAt = timing || null;
        if (timing && timing.conf !== undefined && timing.conf < CONF_DOUBT) {
            c.unsure = true;
        }

        /*
         * "no …" replaces the last event rather than adding one. Dropping it
         * first and then applying the rest as an ordinary call means there is
         * one grammar to learn, not two — and the holder is rebuilt by replay
         * rather than guessed, because undoing a throw moves the disc back.
         */
        var replaced = null;
        var undone = [];
        if (c.correct) {
            /*
             * Pop the last OBSERVATION, not the last row.
             *
             * The play clock writes a marker after the event that moved it, so
             * a plain pop took the marker and left the wrong throw standing -
             * "no, lehner" added a second throw instead of replacing the
             * first. The markers are consequences of the event being removed,
             * so they come off with it, and are put back if the correction is
             * then refused.
             */
            var popped = popObservation();
            replaced = popped.event;
            undone = popped.markers;
            if (!c.action && c.who === null) { render(); return; }

            /*
             * "NO GOAL" CANCELS THE GOAL. IT DOES NOT SCORE ANOTHER ONE.
             *
             * A goal gets called back - a foul on the play, the catch was
             * out - and the spotter says so with the obvious two words. The
             * correction grammar read it as "replace the last event with a
             * goal", which popped the goal, popped the throw before it, and
             * scored a fresh goal in their place: the one utterance that
             * means STOP left the score unchanged and the log worse.
             *
             * Naming the same thing again after "no" can only mean "not
             * that". With a player named it is still a correction - "no drop
             * reiter" fixes who dropped it - so the retraction needs the
             * action alone.
             */
            var sameThing = replaced && c.who === null && c.action
                && (c.action.value === replaced.type || c.action.value === replaced.how
                    || c.action.value === replaced.call);
            if (sameThing) {
                rebuildHolder();
                render();
                return;
            }
        }

        /**
         * A refusal must not cost the spotter the event they were correcting.
         *
         * "No, eleven" pops the throw it is replacing before the replacement
         * is checked — and if the replacement turns out to be impossible
         * (naming the thrower as their own receiver, say) the original was
         * already gone. Losing data to a rejected correction is worse than
         * the mistake being corrected.
         */
        function refuse(why, options) {
            if (replaced) {
                events.push(replaced);
                // Reversed: they came off the end, so they go back in the
                // order they were in.
                undone.reverse().forEach(function (e) { events.push(e); });
                rebuildHolder();
            }
            tally.matched -= 1;
            tally.refused += 1;
            var e = { source: 'voice', heard: c.heard, why: why };
            if (options) { e.open = true; e.options = options; }
            push('unmatched', e);
            render();
        }

        /*
         * A line call is never split.
         *
         * It is the one utterance that is deliberately long, and the splitter
         * happily cut "o line ace hawk speedy bear rocket" into pieces - so
         * the line was never set, the first piece landed as a gap, and every
         * name afterwards belonged to somebody not on the field.
         */
        if (lineWindowOpen() && !c.lineCall) {
            var more = onlyNames(c.heard);
            if (more) {
                more.forEach(function (p) {
                    if (pendingLine.players.length < lineSize
                        && !pendingLine.players.some(function (q) { return q.label === p.label; })) {
                        pendingLine.players.push(p);
                    }
                });
                provisional = pendingLine.players.map(function (p) { return p.label; });
                if (pendingLine.players.length >= lineSize) { commitPending(); }
                else { render(); }
                return;
            }
            // Anything that is not just names is a real call: the line stands
            // as far as it got, and the game carries on.
            commitPending();
        }

        if (!c.noSplit && !c.lineCall) {
            /*
             * Tried on every utterance, not only on the ones that fail.
             *
             * Gating it on failure missed the worse case: "inside hawk huck
             * speedy" is short enough to parse, so it was accepted as one
             * call and the second half was silently thrown away. A refusal
             * is visible; a half-read sentence is not.
             *
             * Marked `noSplit` on the way back in so a piece that still does
             * not parse is reported rather than split for ever.
             */
            var pieces = splitUtterance(c.heard);
            if (pieces.length > 1) {
                tally.heard -= 1;
                pieces.forEach(function (piece) {
                    var sub = parseCall(piece);
                    sub.noSplit = true;
                    applyCall(sub, timing);
                });
                return;
            }
        }

        if (!c.ok) {
            tally[c.ambiguous ? 'ambiguous' : 'refused'] += 1;
            // Kept, never dropped: a spotter does not say things with no
            // purpose, so an utterance nobody could read is a miss to review
            // at the next stoppage rather than noise to forget.
            push('unmatched', { source: 'voice', heard: c.heard,
                why: c.offLine
                    ? c.offLine + ' is in the squad but not on the field'
                    : (c.why || (c.ambiguous ? 'ambiguous' : 'no word understood')) });
            render();
            return;
        }

        tally.matched += 1;
        if (c.who && c.who.form) {
            tally.byForm[c.who.form] = (tally.byForm[c.who.form] || 0) + 1;
        }
        var voiced = {
            source: 'voice', heard: c.heard,
            score: c.action ? c.action.score : (c.who ? c.who.score : 0)
        };
        // Said out loud, so stored: an unsure observation is still an
        // observation, and one presented as certain would be a lie.
        if (c.unsure) { voiced.certain = false; }
        if (c.side) { voiced.side = c.side.value; }
        if (c.open) {
            voiced.open = true;
            voiced.options = {};
            Object.keys(c.options).forEach(function (slot) {
                voiced.options[slot] = c.options[slot].map(function (o) { return o.word; });
            });
        }
        // Words that fit no slot travel with the event rather than being
        // silently ignored: they are how a grammar learns it is too narrow.
        if (c.unresolved.length) { voiced.leftover = c.unresolved.join(' '); }

        var who = c.who ? c.who.value : null;
        var nameOf = function (i) { return i === null || !line[i] ? null : line[i].label; };
        var act = c.action;

        function land(type, extra) {
            /*
             * A THROW TO THE OTHER SIDE IS NOT A THROW.
             *
             * The check ran only on a bare name, so naming a throw type
             * skipped it entirely: "huck tiny" while Team O had the disc
             * recorded a completed pass between opponents. Every rule about
             * possession was intact and the one path most likely to be used
             * walked straight past them.
             *
             * Same answer as the bare name: refused and asked about, with the
             * two real explanations offered, because inventing a pass between
             * teams is worse than any question.
             */
            if (who !== null && wrongSide(line[who])) {
                refuse('that player is on the other side \u2014 say how the disc changed hands',
                    { team: ['turnover I missed', 'wrong name'] });
                return;
            }
            var e = Object.assign({
                to: nameOf(who), from: nameOf(holder), throwType: type,
                grip: c.grip || undefined
            }, extra || {}, voiced);
            if (inFlight && inFlight.tips.length) { e.tipped = inFlight.tips.slice(); }
            /*
             * "No, Weber" means the same throw reached somebody else — not a
             * bare throw with its type and deflections forgotten. Whatever the
             * correction did not restate is inherited from what it replaced.
             */
            if (replaced && replaced.type === 'throw') {
                if (!e.throwType) { e.throwType = replaced.throwType; }
                if (!e.tipped && replaced.tipped) { e.tipped = replaced.tipped; }
                if (!e.from) { e.from = replaced.from; }
                e.corrected = true;
            }
            push('throw', e);
            inFlight = null;
            holder = who;
            render();
        }

        /*
         * THE PULL, WHICH IS NOT A THROW TO A RECEIVER.
         *
         * Handled before every guard below, because none of them apply: it
         * starts the point, so there is no holder to throw to themselves and
         * no possession to contradict.
         *
         * It was a throw type, so "pull Tiny" meant Tiny CAUGHT the pull -
         * true on an O point, exactly backwards on a D point where our player
         * is the one pulling and nobody of ours receives it. Whose action it
         * is follows the point, which the grammar already knows.
         */
        if (c.subOn || c.subOff) {
            /*
             * The side comes from the players, not from the spotter.
             *
             * Somebody coming on belongs to a team already, and somebody
             * going off is on a line already - so asking which side would be
             * asking a question the squad has answered.
             */
            var slot = (c.subOn && c.subOn.role) || (c.subOff && c.subOff.role) || null;
            if (!slot) {
                refuse('which side is that \u2014 the squad does not say');
                return;
            }
            var now = sides[slot].slice();
            if (c.subOff) {
                now = now.filter(function (q) { return q.label !== c.subOff.label; });
            }
            if (c.subOn && !now.some(function (q) { return q.label === c.subOn.label; })) {
                if (now.length >= lineSize && !c.subOff) {
                    refuse(teamName(slot) + ' already has ' + lineSize
                        + ' on \u2014 say who comes off');
                    return;
                }
                now.push(c.subOn);
            }
            sides[slot] = now.slice(0, lineSize);
            push('sub', { on: c.subOn ? c.subOn.label : null,
                          off: c.subOff ? c.subOff.label : null, team: slot });
            rebuildLine();
            return;
        }

        if (c.lineCall) {
            // Unqualified, the side is whichever the named players belong to
            // - which is right far more often than guessing, and says so when
            // they disagree.
            /*
             * Named one side, listed the other's players.
             *
             * "o tiny" says offence and names a defender. One of the two was
             * misheard and the data cannot tell which, so it is a question -
             * which is the whole reason the side is worth saying at all.
             */
            if (c.lineSide && c.lineCall.length) {
                var strays = c.lineCall.filter(function (q) {
                    return q.role && q.role !== c.lineSide;
                });
                if (strays.length === c.lineCall.length) {
                    refuse('that is ' + teamName(c.lineSide) + '\u2019s line, but '
                        + strays.map(function (q) { return q.nick || q.last || q.label; }).join(', ')
                        + ' plays for ' + teamName(strays[0].role));
                    return;
                }
            }

            var which = c.lineSide;
            if (!which) {
                var votes = { O: 0, D: 0 };
                c.lineCall.forEach(function (p) { if (p.role) { votes[p.role] += 1; } });
                which = votes.O === votes.D ? null : (votes.O > votes.D ? 'O' : 'D');
            }
            if (which && c.lineCall.length < lineSize) {
                // Too few to be the whole line: hold it open for the rest,
                // which are on their way in the next breath.
                pendingLine = { side: which, players: c.lineCall.slice(), since: Date.now() };
                provisional = pendingLine.players.map(function (p) { return p.label; });
                render();
                return;
            }
            if (!which) {
                setLine(c.lineCall);
            } else {
                setSide(which, c.lineCall);
            }
            lines[point] = line.slice();
            render();
            return;
        }

        /*
         * "pull tiny" NAMES THE PULLER. Always.
         *
         * It used to mean two different things depending on the point: the
         * puller when we were pulling, and whoever picked it up when they
         * were - because a one-team spotter can only name their own side and
         * the useful half changes. A neutral spotter can name either, so the
         * ambiguity is gone and with it the guessing: the person who pulls is
         * the person you say. Whoever picks it up is the next name.
         *
         * The receiving side follows for free, which is the check that a
         * team cannot catch its own pull - now structural rather than a rule
         * to enforce.
         */
        if (act && act.value === 'pull') {
            var puller = who !== null ? line[who] : null;
            push('pull', Object.assign({
                by: nameOf(who),
                receiving: puller && sideOf(puller) ? other(sideOf(puller)) : null
            }, voiced));
            discWith = puller && sideOf(puller) ? other(sideOf(puller)) : null;

            /*
             * SOMEBODY PUT IT INTO PLAY. WE JUST DO NOT KNOW WHO YET.
             *
             * A pull always ends with a player picking the disc up, so the
             * absence of a name is missing information rather than an absent
             * event - and the difference matters, because a blank thrower
             * looks like a throw from nobody while a question looks like
             * what it is. Recorded open, with the receiving side offered, so
             * it can be filled at the next stoppage or from a replay.
             *
             * Saying "ace picks up" settles it instead of adding a second
             * event, which is the common case and should cost nothing.
             */
            if (discWith && sides[discWith] && sides[discWith].length) {
                push('regain', { open: true, why: 'who put it into play',
                    options: { who: sides[discWith].map(function (q) {
                        return q.nick || q.last || q.label;
                    }) } });
            }
            // The receiving side has it, and a neutral spotter can name them.
            // `possession` is the one-team word for "somebody I may name has
            // the disc"; which side that is now lives in `discWith`.
            possession = 'us';
            holder = null;
            inFlight = null;
            render();
            return;
        }

        if (who !== null && who === holder && (!act || act.kind === 'throw')) {
            // Nobody throws to themselves. Enforced here rather than by hiding
            // the name, so the name stays sayable for a correction.
            refuse('that player already has the disc');
            return;
        }

        /*
         * A NAME FROM THE OTHER SIDE DOES NOT MOVE THE DISC.
         *
         * The tempting alternative is to treat it as an unsaid turnover -
         * they have it now, so one must have happened. That is wrong in the
         * way that matters: a single misheard name would fabricate a turnover
         * that never happened, and if the following name is back on the
         * original side, a SECOND one. One bad word, two invented events,
         * both indistinguishable from real data afterwards.
         *
         * Requiring the cause costs nothing, because in Ultimate a possession
         * never changes without one - a throwaway, a drop, a block, a stall,
         * a goal - and those are the loudest things on the field and the
         * first thing any spotter says.
         *
         * So the name is refused and asked about. If it really was a turnover
         * nobody called, one tap says so; if it was the wrong name, one tap
         * discards it. Either way nothing is invented on the spotter's
         * behalf.
         */
        if (who !== null && act === null && wrongSide(line[who])) {
            refuse('that player is on the other side \u2014 say how the disc changed hands',
                { team: ['turnover I missed', 'wrong name'] });
            return;
        }

        /*
         * Superseded by the side check above.
         *
         * This refused any bare name while `possession` said "them", which
         * was the only way a one-team spotter could express "not ours". With
         * both teams on the field the question is which SIDE has it, and
         * `wrongSide` answers that from the named player - so this fired on
         * legitimate names the moment a pull handed the disc to the other
         * side, which is every point.
         */
        if (possession === 'them' && act === null && who !== null
            && !roleGroup('O').length) {
            refuse('the other team has the disc');
            return;
        }

        // A name on its own closes whatever is in the air — which is the
        // common case, because the type was said when it was thrown.
        if (!act && who !== null) {
            land(inFlight ? inFlight.type : null);
            return;
        }

        if (act.kind === 'throw') {
            if (who === null) {
                // Thrown, not yet arrived. The page waits and says so.
                inFlight = { type: act.value, tips: [], since: at() };
                render();
                return;
            }
            land(act.value);
            return;
        }

        if (act.kind === 'flight') {
            // A deflection does not end the possession and does not name a
            // receiver: the disc is still up, now with a touch against it.
            if (!inFlight) { inFlight = { type: null, tips: [], since: at() }; }
            inFlight.tips.push(who === null ? 'unknown' : nameOf(who));
            render();
            return;
        }

        /*
         * A TIMEOUT IS CALLED BY SOMEBODY, AND THE RULES SAY WHO.
         *
         * During play only the team in possession may call one, and only the
         * thrower - so when we have the disc the player is already known and
         * asking would be asking a question the log can answer. When they
         * have it, it is theirs and the player is not ours to name.
         *
         * Between points either team may call one, and from a sideline it is
         * frequently unclear which. So an unqualified timeout at a dead disc
         * is recorded as a QUESTION with both answers offered, rather than
         * attributed to whoever happened to be holding last. One tap settles
         * it at the next stoppage, which is where the spotter already is.
         */
        if (act && act.value === 'timeout') {
            var side = c.side ? c.side.value : null;
            var e = { call: 'timeout', seen: 'signal' };
            /*
             * Named by side, because a spotter covering both teams has no
             * "our" - and `offence` and `defence` are already in the
             * vocabulary, so nothing new has to be said or learnt. Adding
             * "o" and "d" for them was caught by the resolves-to-itself
             * check: bare "d" is how a block is called.
             *
             * Which side that is changes every point and the grammar knows:
             * the receiving side is on offence.
             */
            if (side === 'offence') { side = starting; }
            if (side === 'defence') { side = other(starting); }
            if (side === 'O' || side === 'D' || side === 'us' || side === 'them') {
                e.team = side;
            } else if (inPlay && discSide()) {
                // During play only the side in possession may call one, and
                // only the thrower - so both are already known.
                e.team = discSide();
                e.by = nameOf(who !== null ? who : holder);
            } else if (inPlay && possession === 'us') {
                e.team = 'us';
                e.by = nameOf(who !== null ? who : holder);
            } else if (inPlay && possession === 'them') {
                e.team = 'them';
            } else if (who !== null) {
                e.team = 'us';
                e.by = nameOf(who);
            } else {
                // Dead disc, nobody named: either team could have called it.
                e.open = true;
                e.why = 'which team called it';
                e.options = { team: ['us', 'them'] };
            }
            push('call', Object.assign(e, voiced));
            render();
            return;
        }

        if (act.kind === 'call') {
            // What was called, and not how it resolved — that is a second
            // utterance, and inventing it here would be a guess.
            var against = c.against ? nameOf(c.against.value) : null;
            /*
             * How many times these two have been at it this point.
             *
             * Counted at the moment it happens rather than worked out
             * afterwards, because the number is only interesting while the
             * point is still on.
             */
            var pair = null;
            if (against && nameOf(who)) {
                pair = 1;
                events.forEach(function (x) {
                    if (x.type !== 'call' || x.point !== point) { return; }
                    if ((x.by === nameOf(who) && x.against === against)
                        || (x.by === against && x.against === nameOf(who))) { pair += 1; }
                });
            }
            push('call', Object.assign({ call: act.value, by: nameOf(who),
                against: against || undefined, pair: pair && pair > 1 ? pair : undefined,
                resolved: c.resolved || undefined,
                seen: INFERRED_CALL[act.value] ? 'inferred' : 'signal' }, voiced));
            render();
            return;
        }

        if (act.kind === 'control') {
            if (act.value === 'undo') { popObservation(); render(); return; }
            if (act.value === 'afk') { toggleAfk(); return; }
            if (act.value === 'regain') {
                push('regain', voiced);
                possession = 'us';
                holder = null;
                render();
                return;
            }
            if (act.value === 'pickup') {
                // Settle the question the pull left open, rather than
                // recording a second pickup beside it.
                var waiting = null;
                for (var pu = events.length - 1; pu >= 0; pu -= 1) {
                    if (events[pu].type === 'point') { break; }
                    if (events[pu].type === 'regain' && events[pu].open) {
                        waiting = events[pu];
                        break;
                    }
                }
                if (waiting) {
                    waiting.by = nameOf(who);
                    delete waiting.open;
                    delete waiting.options;
                    delete waiting.why;
                } else {
                    push('regain', Object.assign({ by: nameOf(who) }, voiced));
                }
                possession = 'us';
                holder = who;
                render();
                return;
            }
            if (act.value === 'newpoint') { startPoint(); return; }
            if (act.value === 'oline') { setLine(roleGroup('O')); return; }
            if (act.value === 'dline') { setLine(roleGroup('D')); return; }
            if (act.value === 'flag') { flag(lastEvent(), 'the spotter said so'); return; }
            if (act.value === 'live') { setPlay(true, 'said', null); return; }
            if (act.value === 'stopped') { setPlay(false, 'said', null); return; }
            push('gap', voiced);
            inFlight = null;
            holder = null;
            render();
            return;
        }

        // An outcome. A drop belongs to whoever should have caught it, so the
        // slot is used when it is there; everything else belongs to whoever
        // had the disc.
        // Not a turnover and not our goal: the point is simply over, the other
        // way round. Routed before the turnover fall-through, which would
        // otherwise have recorded it as one of our players losing the disc.
        /*
         * How the pull landed, said after it.
         *
         * A brick is not a turnover - nobody lost the disc, the pull went out
         * and play restarts at the brick mark. Recording it as one charged a
         * throwaway to whoever happened to be holding, which on a D point was
         * our puller and on an O point was nobody.
         */
        if (act.value === 'brick' || act.value === 'pull in') {
            var lastPull = null;
            for (var pi = events.length - 1; pi >= 0; pi -= 1) {
                if (events[pi].type === 'pull') { lastPull = events[pi]; break; }
                if (events[pi].type === 'point') { break; }
            }
            if (lastPull) {
                lastPull.landed = act.value === 'brick' ? 'out' : 'in';
                // A brick means our side starts at the mark, whatever the
                // pull did on the way.
                if (act.value === 'brick' && starting === 'O') {
                    possession = 'us';
                    holder = null;
                }
                render();
                return;
            }
            // No pull to attach it to: keep it rather than guess.
            push('unmatched', { source: 'voice', heard: c.heard,
                why: 'said after no pull' });
            render();
            return;
        }

        if (act.value === 'conceded') {
            inFlight = null;
            holder = null;
            possession = null;
            push('conceded', voiced);
            render();
            return;
        }
        if (act.value === 'goal') {
            /*
             * A GOAL NAMES THE RECEIVER, LIKE EVERY OTHER THROW.
             *
             * So naming the player already holding it says they threw the
             * scoring pass to themselves. The self-throw guard covered
             * ordinary throws and not this one, so "huck wags" then "goal
             * wags" recorded a goal nobody could have scored - when it is
             * either a name called wrong, or a pass or a tip in between that
             * went unsaid.
             *
             * "goal" on its own credits whoever has it, which is what that
             * sequence meant.
             */
            if (who !== null && who === holder) {
                refuse('that player already has the disc \u2014 say "goal" on its own, '
                    + 'or name who caught the scoring pass');
                return;
            }
            push('goal', Object.assign({ by: nameOf(who !== null ? who : holder) }, voiced));
            inFlight = null;
            holder = null;
            // The point is over; startPoint() numbers the next one. Both
            // incrementing meant point 2 never existed.
            possession = null;
            render();
            return;
        }
        inFlight = null;

        /*
         * WHOSE EVENT IS THIS? EVERY OUTCOME HAS TWO READINGS.
         *
         * The fall-through recorded every outcome as OUR side losing the
         * disc, which is only true while we have it. The spotter follows one
         * team and a lot of a game is the other side playing, so the same
         * word means opposite things depending on who is in possession:
         *
         *   ours    a block, an interception, a Callahan BY US - a positive
         *           event for one of our players, and we get the disc
         *   theirs  the same words against us - our thrower lost it
         *
         * A block by our own team had no representation at all: `perPlayer`
         * has counted a `block` event since it was written and nothing ever
         * pushed one, so the column was permanently zero.
         */
        var BLOCKS = { blocked: 1, interception: 1, 'hand block': 1,
                       'foot block': 1, 'layout block': 1 };

        if (act.value === 'callahan') {
            /*
             * A Callahan is a goal, which is the part this got wrong.
             *
             * Intercepted in the endzone, so it scores immediately. Ours ends
             * the point in our favour; theirs is a turnover AND a goal
             * against, and recording only the turnover left the point with no
             * ending - which holds and breaks then silently mis-counted.
             */
            if (possession === 'them') {
                push('block', { how: 'callahan', by: nameOf(who) });
                push('goal', Object.assign({ by: nameOf(who), how: 'callahan' }, voiced));
            } else {
                push('turnover', { how: 'callahan', by: nameOf(holder) });
                push('conceded', Object.assign({ how: 'callahan' }, voiced));
            }
            holder = null;
            possession = null;
            render();
            return;
        }

        if (act.value === 'greatest') {
            // A greatest is a SAVE, not a turnover: the disc is caught and
            // thrown back in before landing, and play continues. Recording it
            // as a turnover handed the disc to the other team every time
            // somebody did the most spectacular thing in the sport.
            push('throw', Object.assign({ throwType: 'greatest',
                to: nameOf(who), from: nameOf(holder) }, voiced));
            if (who !== null) { holder = who; }
            possession = 'us';
            render();
            return;
        }

        /*
         * A block is made by the side that does NOT have the disc.
         *
         * Keyed off `possession === 'them'`, which was the one-team way of
         * saying "not ours" and is never true for a neutral spotter - so
         * "layout d hawk" was filed as a turnover by the offence and the
         * player who actually made the play vanished from their own column.
         *
         * Reading it off the named player is both correct and self-checking:
         * a block by somebody on the side already holding the disc is not a
         * block, and falls through to be questioned.
         */
        var blocker = who !== null ? line[who] : null;
        var defending = blocker && sideOf(blocker) && discSide()
            ? sideOf(blocker) !== discSide()
            : possession === 'them';
        if (BLOCKS[act.value] && defending) {
            push('block', Object.assign({ how: act.value, by: nameOf(who),
                side: blocker ? sideOf(blocker) : null }, voiced));
            possession = 'us';
            if (blocker && sideOf(blocker)) { discWith = sideOf(blocker); }
            holder = who !== null ? who : null;
            render();
            return;
        }

        possession = 'them';
        push('turnover', Object.assign({
            how: act.value,
            by: nameOf(act.value === 'drop' && who !== null ? who : holder),
            // "huck weber drop" says HOW it was delivered as well as how it
            // ended. Carried through, or the throw type is lost on exactly
            // the passes it is most interesting on.
            throwType: c.throwType || (inFlight ? inFlight.type : null),
            from: act.value === 'drop' ? nameOf(holder) : undefined
        }, voiced));
        holder = null;
        render();
    }

    /**
     * The grammar, handed to the recogniser rather than applied after it.
     *
     * This is the reason for Vosk over a general model: Kaldi takes a word
     * list and will not decode outside it, so "lehner" cannot come back as
     * "lena" in the first place. `[unk]` stays in the list deliberately —
     * without it the recogniser must force everything it hears into the
     * vocabulary, which would turn every cough into a call. With it, anything
     * unrecognised arrives as unknown and lands in the review queue, which is
     * where a spotter's unreadable utterance belongs.
     */
    /**
     * WHAT THE MODEL CANNOT HEAR, WHICH IT WILL NOT TELL YOU.
     *
     * Kaldi can only decode words in its lexicon. Anything else is dropped
     * from the grammar with a warning on the console and no effect anywhere a
     * spotter would look - so the word simply never matches, for the whole
     * game, and looks like a person who mumbles.
     *
     * With a small English model that is: bare digits (a shirt number has to
     * be SAID, "twenty three" rather than "23"), invented nicknames, and the
     * sport's own loanwords - scoober, thumber. An alias nobody can say is a
     * coverage gap, so it is collected and shown.
     */
    /**
     * WHAT THE MODEL CANNOT HEAR, WHICH IT WILL NOT TELL YOU.
     *
     * Kaldi decodes only words in its lexicon. Anything else is dropped from
     * the grammar with a warning and no effect anywhere a spotter would look,
     * so the word never matches for the whole game and looks like a person
     * who mumbles. Measured against the small English model with the demo
     * squad, ten words went: the shirt numbers as digits, the invented
     * nicknames, `scoober`, `thumber` and `afk`.
     *
     * This is NOT detected at runtime, and the failed attempt is worth
     * recording: the warnings come from inside the Vosk worker, which has its
     * own console, so patching the page's console catches nothing. A detector
     * that detects nothing is worse than an honest note, so the rule is
     * stated instead - and the one part that can be fixed, is.
     */
    function grammarWords() {
        var words = {};
        vocabulary().forEach(function (v) { words[v.word] = true; });
        /*
         * The whole squad, not just the seven on.
         *
         * A line is called before the line exists, so the names in it are by
         * definition not on the field yet - and a recogniser built from the
         * current line literally cannot decode the substitute being brought
         * on. The cost is a slightly larger grammar; the alternative is that
         * calling a line only works for players already in it.
         */
        (squad.length ? squad : line).forEach(function (p) {
            aliasesOf(p).forEach(function (a) { words[a.alias] = true; });
        });
        IGNORE.concat([OR]).forEach(function (w) { words[w] = true; });

        /*
         * Bare digits come out, at the end.
         *
         * No English lexicon has "23" in it, so offering one buys a warning
         * and nothing else - the spoken forms, "twenty three" and "two
         * three", are what carry a shirt number. Filtered here rather than
         * where the aliases are added, because `vocabulary()` already
         * contains them and a filter on the second pass caught nothing.
         */
        return Object.keys(words)
            .filter(function (w) { return !/^\d+$/.test(w); })
            .concat(['[unk]']);
    }

    var engine = null;
    var activeRecognizer = null;

    /**
     * The recogniser's word list is rebuilt whenever the line changes.
     *
     * Kaldi takes the vocabulary at construction, so a recogniser made for
     * point one literally cannot decode a name that came on at point two. It
     * is cheap to replace and catastrophic to forget.
     */
    function rebuildGrammar() {
        if (!activeRecognizer || !activeRecognizer.model) { return; }
        try {
            if (activeRecognizer.rec && activeRecognizer.rec.remove) { activeRecognizer.rec.remove(); }
            var rec = new activeRecognizer.model.KaldiRecognizer(
                activeRecognizer.rate, JSON.stringify(grammarWords())
            );
            rec.setWords(true);
            rec.on('result', activeRecognizer.onResult);
            rec.on('partialresult', function (message) {
                onPartial(message && message.result && message.result.partial);
            });
            activeRecognizer.rec = rec;
        } catch (e) { /* the mic is off, or mid-teardown */ }
    }

    /**
     * Audio, in three settings, and the middle one is the point.
     *
     * Replaying what was actually said is the only way to settle an utterance
     * the recogniser could not read — a transcript of a misheard word is no
     * help at all. But a spotter's voice is a recording of a person, and
     * keeping ninety minutes of it to resolve six words is a poor trade.
     *
     * So the default is a ROLLING BUFFER: the last minute and a half, held in
     * memory, continuously discarded, never written anywhere. Enough to replay
     * something from the point just played; gone long before the game ends.
     * "Keep all" exists for post-game review and is a deliberate choice.
     */
    var BUFFER_SECONDS = 90;
    /*
     * A ceiling, because "keep whatever the questions need" is unbounded if
     * the questions are never answered. Fifteen minutes of held audio is
     * already well past a stoppage; beyond it the oldest goes and the entry
     * says its audio is gone rather than offering a button that plays silence.
     */
    var BUFFER_CEILING = 900;
    var audioChunks = [];      // { blob, at } — `at` is seconds since start
    var recorder = null;
    var recordedFrom = null;
    var recordedUrl = null;

    function audioMode() {
        var sel = el('audioMode');
        return sel && sel.value ? sel.value : 'off';
    }

    /** Seconds into the recording, so a clip can be found again. */
    function audioOffset() {
        return recordedFrom === null ? null
            : Math.round((Date.now() - recordedFrom) / 100) / 10;
    }

    /**
     * The spotter's own voice, kept on this machine.
     *
     * A transcript says what the machine thought it heard; the audio says what
     * was said, which is the difference between making a correction and
     * guessing at one. Opt-in, never uploaded, and gone when the tab closes —
     * it is a recording of a person, and this project's rule about that is in
     * `AGENTS.md`.
     */
    function startRecording(stream) {
        if (audioMode() === 'off' || typeof MediaRecorder === 'undefined') { return; }
        audioChunks = [];
        recorder = new MediaRecorder(stream);
        recordedFrom = Date.now();
        recorder.ondataavailable = function (ev) {
            if (!ev.data || !ev.data.size) { return; }
            audioChunks.push({ blob: ev.data, at: (Date.now() - recordedFrom) / 1000 });
            if (audioMode() !== 'buffer') { return; }
            /*
             * Drop what has aged out — but never the first chunk, which
             * carries the container header, and never audio an unanswered
             * question still needs.
             *
             * Ninety seconds is the right window while play continues and the
             * wrong one the moment a spotter starts clearing the review queue:
             * the utterances they are working through are by definition older
             * than the ones still arriving, and a buffer that keeps rolling
             * deletes the evidence while they read the question. So the window
             * stretches back to the oldest thing still unsettled and shrinks
             * again as they are resolved.
             */
            var now = (Date.now() - recordedFrom) / 1000;
            var cutoff = now - BUFFER_SECONDS;
            var oldestOpen = null;
            events.forEach(function (e) {
                var unsettled = e.type === 'unmatched' || e.open || e.certain === false;
                if (!unsettled || e.audioAt === null || e.audioAt === undefined) { return; }
                if (oldestOpen === null || e.audioAt < oldestOpen) { oldestOpen = e.audioAt; }
            });
            if (oldestOpen !== null) { cutoff = Math.min(cutoff, oldestOpen - 2); }
            cutoff = Math.max(cutoff, now - BUFFER_CEILING);

            audioChunks = audioChunks.filter(function (c, i) {
                return i === 0 || c.at >= cutoff;
            });
        };
        recorder.start(1000);
    }

    /**
     * Play back roughly what was said at this moment.
     *
     * Assembled from the retained chunks each time, because the buffer moves.
     * `pad` widens the window when one word is not enough context — the
     * difference between "was that Lang or Lehner" and "what were they even
     * talking about".
     *
     * Word-exact when the local engine gave timings: the window is the word's
     * own span, so the button plays the word and nothing else. The chunks are
     * still a second each, so the surrounding second gets decoded and skipped
     * past rather than trimmed — near enough, and no re-encoding.
     *
     * Approximate under the browser's own recogniser, which reports no word
     * timings at all. There the window is the utterance plus a second either
     * side, which is the older behaviour and the reason it is a fallback.
     */
    function playMoment(offset, pad, centre) {
        if (!audioChunks.length || offset === null || offset === undefined) { return; }
        var mid = centre === undefined ? offset : centre;
        var from = Math.max(0, mid - pad);
        var to = mid + pad;
        var keep = audioChunks.filter(function (c, i) {
            return i === 0 || (c.at >= from - 1 && c.at <= to + 1);
        });
        var base = keep.length > 1 ? keep[1].at : 0;
        var url = URL.createObjectURL(new Blob(keep.map(function (c) { return c.blob; }),
            { type: recorder ? recorder.mimeType : 'audio/webm' }));
        var a = new Audio(url);
        a.currentTime = Math.max(0, from - base);
        a.play();
        setTimeout(function () {
            a.pause();
            URL.revokeObjectURL(url);
        }, (pad * 2 + 0.5) * 1000);
    }

    /**
     * Recognition that runs in the page.
     *
     * Chrome's Web Speech streams audio to Google, which fails a pitch twice
     * over: no reliable network, and a round trip too slow for a call that has
     * to keep pace with play. This needs `get-model.sh` to have been run and
     * the page served over HTTP, because the library spawns a Worker and
     * browsers refuse those from file://.
     */
    /**
     * Where the model actually is, spelled out in full.
     *
     * This passed a RELATIVE path, and the library resolves it inside a blob:
     * worker whose base is the ORIGIN ROOT rather than this page's directory.
     * Served the way the README says - `php -S -t tools/capture-poc`, page at
     * `/` - the two happen to agree and it works. Reached at
     * `/tools/capture-poc/watch.html`, as the Studio's link does, it fetched
     * `/vendor/model.tar.gz`, got a 404, and the promise never settled: the
     * button sat on "loading model" for ever with no error anywhere a person
     * would look.
     */
    /*
     * From the document, not interpolated into the script.
     *
     * The script stays pure JavaScript so the headless harness can run it
     * directly. A PHP short echo tag in the middle of the script is a
     * syntax error to everything except PHP - and, written out in a comment
     * as this one nearly was, it opens a PHP tag right here in the file.
     */
    var MODEL_BASE = document.body.dataset.model || 'spotter/';
    var MODEL_URL = new URL(MODEL_BASE + 'model.tar.gz', window.location.href).href;

    /**
     * A load that cannot finish has to say so.
     *
     * `createModel` does not reject on a failed fetch - the worker throws
     * somewhere inside itself and the promise is simply never settled. Forty
     * megabytes is slow enough that a spotter cannot tell "still working"
     * from "never going to work", so an unsettled load becomes an error with
     * a URL in it, and the mic handler's existing failure path takes over and
     * falls back.
     */
    var MODEL_TIMEOUT = 120000;

    function startLocal() {
        var loading = Vosk.createModel(MODEL_URL);
        var watchdog = new Promise(function (_, reject) {
            setTimeout(function () {
                reject(new Error('the model did not load within '
                    + (MODEL_TIMEOUT / 1000) + 's.\n\n' + MODEL_URL
                    + '\n\nRun tools/capture-poc/get-model.sh if vendor/ is empty.'));
            }, MODEL_TIMEOUT);
        });

        return Promise.race([loading, watchdog]).then(function (model) {
            /**
             * Word timings, which are worth asking for.
             *
             * With `setWords(true)` each result carries every word's start,
             * end and confidence, measured against the audio rather than the
             * wall clock. Three things follow: replay can play the word and
             * nothing else, the event is stamped at the moment the NAME was
             * said instead of when the recogniser finished thinking, and a
             * word the recogniser itself was unsure of can be flagged as
             * unsure rather than presented as an observation.
             *
             * Local engine only — the browser's own recogniser offers no such
             * thing, which is one more reason the fallback is a fallback.
             */
            var onResult = function (message) {
                var res = message && message.result;
                var text = res && res.text;
                if (!text || !text.trim()) { return; }
                var words = (res.result || []).filter(function (w) { return w && w.word; });
                applyCall(parseCall(text), words.length ? {
                    start: words[0].start,
                    end: words[words.length - 1].end,
                    words: words,
                    conf: words.reduce(function (lo, w) {
                        return w.conf === undefined ? lo : Math.min(lo, w.conf);
                    }, 1)
                } : null);
            };

            return navigator.mediaDevices.getUserMedia({
                audio: { echoCancellation: true, noiseSuppression: true, channelCount: 1 }
            }).then(function (stream) {
                startRecording(stream);
                /*
                 * Ask for 16k, accept what the hardware gives.
                 * `new AudioContext({sampleRate: 16000})` throws outright on
                 * some browsers, and a recogniser told 16000 while being fed
                 * 48000 transcribes gibberish — so the rate is read back and
                 * the recogniser is built from it.
                 */
                var Ctx = window.AudioContext || window.webkitAudioContext;
                var ctx;
                try { ctx = new Ctx({ sampleRate: 16000 }); } catch (e) { ctx = new Ctx(); }
                var rec = new model.KaldiRecognizer(ctx.sampleRate, JSON.stringify(grammarWords()));
                rec.setWords(true);
                rec.on('result', onResult);
                rec.on('partialresult', function (message) {
                    onPartial(message && message.result && message.result.partial);
                });
                // Held so the word list can be rebuilt when the line changes.
                activeRecognizer = { model: model, rec: rec, rate: ctx.sampleRate, onResult: onResult };

                var source = ctx.createMediaStreamSource(stream);
                var node = ctx.createScriptProcessor(4096, 1, 1);
                node.onaudioprocess = function (ev) {
                    try {
                        activeRecognizer.rec.acceptWaveform(ev.inputBuffer);
                    } catch (e) { /* mid-teardown, or mid-rebuild */ }
                };
                source.connect(node);
                node.connect(ctx.destination);
                engine = {
                    kind: 'local',
                    stop: function () {
                        node.disconnect();
                        source.disconnect();
                        ctx.close();
                        stream.getTracks().forEach(function (t) { t.stop(); });
                        if (recorder && recorder.state === 'recording') { recorder.stop(); }
                        activeRecognizer = null;
                        model.terminate();
                    }
                };
                el('engine').textContent = 'engine: local (Vosk, grammar-constrained, '
                    + Math.round(ctx.sampleRate / 1000) + 'kHz)';
            });
        });
    }

    function toggleVoice() {
        if (voiceOn) {
            voiceOn = false;
            if (engine && engine.stop) { engine.stop(); }
            engine = null;
            if (recog) { recog.stop(); }
            el('mic').className = 'big';
            el('mic').textContent = '🎙 voice off';
            return;
        }

        // Local first, always: it is the one that works at a pitch.
        if (window.Vosk && !window.__noVosk) {
            voiceOn = true;
            el('mic').className = 'big on';
            el('mic').textContent = '🎙 loading model…';
            startLocal().then(function () {
                el('mic').textContent = '🎙 listening';
            }, function (e) {
                voiceOn = false;
                el('mic').className = 'big';
                el('mic').textContent = '🎙 voice off';
                alert('Local recogniser failed: ' + e.message
                    + '\n\nServe the page over HTTP — a Worker cannot start from file://.');
            });
            return;
        }

        var Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!Recognition) {
            alert('No recogniser. Run get-model.sh for the local one, or use Chrome.');
            return;
        }
        el('engine').textContent = 'engine: browser (audio leaves this machine)';
        recog = new Recognition();
        recog.continuous = true;
        recog.interimResults = false;
        recog.lang = 'en-US';
        recog.onresult = function (ev) {
            for (var i = ev.resultIndex; i < ev.results.length; i += 1) {
                if (ev.results[i].isFinal) {
                    applyCall(parseCall(ev.results[i][0].transcript));
                }
            }
        };
        // Continuous recognition stops itself on a pause; without this the mic
        // dies silently a minute in and the result reads as a spotter who went
        // quiet.
        recog.onend = function () { if (voiceOn) { try { recog.start(); } catch (e) { /* already going */ } } };
        recog.onerror = function (e) {
            el('heard').className = 'heard no';
            el('heard').textContent = 'recogniser error: ' + e.error;
        };
        voiceOn = true;
        el('mic').className = 'big on';
        el('mic').textContent = '🎙 listening';
        recog.start();
    }

    // ---- training ----------------------------------------------------------
    var reference = null;

    /**
     * A reference game, loaded whole: video, both squads, the line size.
     *
     * The setup a recruit would otherwise do by hand is the step that loses
     * them - two rosters typed in before they have said a word. One choice
     * does it, and everybody spotting the same game gets the same names in
     * the same forms, which is what makes their captures comparable at all.
     *
     * Training mode on purpose: it stamps events with VIDEO time, so two
     * people spotting the same footage on different days align exactly. Wall
     * clock captures cannot be compared.
     */
    var packGame = null;

    if (el('packGame')) {
        // What the select points at, which is not yet what is loaded.
        var packPick = function () {
            var pack = window.SPOTTER_PACK || [];
            return pack[Number(el('packGame').value)] || null;
        };

        var showPackLinks = function (g) {
            var box = el('packLinks');
            if (!box) { return; }
            box.replaceChildren();

            var add = function (l, prefix) {
                if (!l || !l.url) { return; }
                var what = ((prefix ? prefix + ' ' : '') + (l.label || 'link')).trim();
                var a = document.createElement('a');
                a.href = l.url;
                a.target = '_blank';
                a.rel = 'noopener';
                a.textContent = what;
                a.title = 'The tournament’s own ' + what + ', in a new tab';
                box.append(a);
            };

            ((g && g.links) || []).forEach(function (l) { add(l, ''); });
            // A roster belongs to a team, not to the game, so it is stored on
            // the team and named after it - two links both labelled "roster"
            // would tell a spotter nothing about which one to open.
            ((g && g.teams) || []).forEach(function (t) {
                (t.links || []).forEach(function (l) { add(l, t.name || ''); });
            });
        };

        el('packGame').addEventListener('change', function () {
            showPackLinks(packPick());
        });
        showPackLinks(packPick());

        el('packLoad').addEventListener('click', function () {
            var g = packPick();
            if (!g) { return; }
            // Loading throws the session away, so say so while it can still
            // be kept.
            if (events.length && !confirm('Load ' + (g.name || 'the game')
                + '? This clears ' + events.length + ' captured events.')) {
                return;
            }
            packGame = g;

            wipe(true);
            if (g.size) { lineSize = Number(g.size) || lineSize; }
            sides = { O: [], D: [] };
            squad = [];
            (g.teams || []).forEach(function (t, i) {
                var slot = t.side === 'D' || i === 1 ? 'D' : 'O';
                teamNames[slot] = t.name || teamName(slot);
                (t.players || []).forEach(function (q) {
                    var pl = asPlayer({ firstname: q.firstname, lastname: q.lastname,
                                        nickname: q.nickname, num: q.num, role: slot,
                                        matching: q.matching, id: q.id });
                    if (!squad.some(function (r) { return r.label === pl.label; })) { squad.push(pl); }
                });
            });
            if (MODE !== 'training') { setMode('training'); }
            if (g.video) {
                el('url').value = g.video;
                el('load').click();
            }
            startPoint(null, 'O');
            render();
        });
    }

    el('ref').addEventListener('change', function (ev) {
        var f = ev.target.files && ev.target.files[0];
        if (!f) { return; }
        var r = new FileReader();
        r.onload = function () {
            try {
                var doc = JSON.parse(String(r.result));
                reference = doc.events || [];
                el('refinfo').textContent = reference.length + ' tagged events'
                    + (doc.video ? ' · video ' + doc.video : '');
                // Any valid JSON used to enable it, so a file that was
                // not a capture scored an empty reference against a real one
                // and called every event a miss. Nothing to compare is not a
                // zero.
                el('scoreme').disabled = reference.length === 0;
                // Load the same footage, or the timestamps compare nothing.
                if (doc.video && !el('url').value) {
                    el('url').value = doc.video;
                }
            } catch (e) { alert('Not a capture: ' + e.message); }
        };
        r.readAsText(f);
    });

    el('scoreme').addEventListener('click', function () {
        if (!reference) { return; }
        var r = scoreAttempt(reference, events);
        var card = el('scorecard');
        card.replaceChildren();

        var top = document.createElement('div');
        top.className = 'card';
        [['right', r.matched.length + ' / ' + r.scored],
         ['accuracy', r.accuracy === null ? '—' : r.accuracy + '%'],
         ['median lag', r.medianLag === null ? '—' : r.medianLag + 's'],
         ['wrong player', r.wrongWho.length],
         ['wrong kind', r.wrongType.length],
         ['missed', r.missed.length],
         ['invented', r.invented.length],
         ['not watched', r.uncovered.length]].forEach(function (c) {
            var d = document.createElement('div');
            var sp = document.createElement('span');
            sp.textContent = c[0];
            var b = document.createElement('b');
            b.textContent = c[1];
            d.append(sp, b);
            top.append(d);
        });
        card.append(top);

        function row(cls, at, text) {
            var d = document.createElement('div');
            d.className = 'miss';
            d.innerHTML = '<span class="' + cls + '">' + mmss(at) + ' · ' + text + '</span>';
            // Every mistake is checkable against the footage that produced it.
            d.addEventListener('click', function () {
                seek(at - 4);
            });
            card.append(d);
        }

        r.wrongWho.forEach(function (x) {
            row('w', x.at, 'said ' + (x.got || '—') + ', it was ' + (x.expected || '—'));
        });
        r.wrongType.forEach(function (x) {
            row('w', x.at, 'recorded a ' + x.gotType + ', it was a ' + x.what);
        });
        r.missed.forEach(function (x) {
            row(x.flagged ? 'f' : 'u', x.at, (x.flagged ? 'missed, and you knew: ' : 'missed: ')
                + x.what + ' ' + (x.who || ''));
        });
        r.invented.forEach(function (x) {
            row('i', x.at, 'nothing there: ' + x.what + ' ' + (x.who || ''));
        });

        if (!r.wrongWho.length && !r.missed.length && !r.invented.length) {
            var ok = document.createElement('div');
            ok.className = 'miss';
            ok.textContent = 'nothing wrong — on this footage, at this pace.';
            card.append(ok);
        }
    });

    el('mic').addEventListener('click', toggleVoice);
    el('missed').addEventListener('click', missed);
    el('afk').addEventListener('click', toggleAfk);
    el('undo').addEventListener('click', function () { popObservation(); render(); });
    el('newpt').addEventListener('click', function () { startPoint(); });

    function wipe(quiet) {
        if (!quiet && !window.confirm('Forget this session? It holds player names.')) { return; }
        events = [];
        lines = {};
        point = 1;
        holder = null;
        possession = 'us';
        inPlay = false;
        liveFrom = MODE === 'live' ? Date.now() : null;
        try { window.localStorage.removeItem(STORE); } catch (e) { /* nothing to remove */ }
        el('resume').textContent = '';
        render();
    }

    el('wipe').addEventListener('click', function () { wipe(false); });

    /**
     * Re-spotting: drop what was recorded after the playhead.
     *
     * Rewinding and going again was advertised and did not work — the second
     * attempt simply added a duplicate set of events alongside the first. This
     * makes it explicit, because silently discarding somebody's work on a
     * seek would be worse than not offering it.
     */
    el('cut').addEventListener('click', function () {
        var t = at();
        var doomed = events.filter(function (e) { return e.at > t; }).length;
        if (!doomed) { return; }
        if (!window.confirm('Drop ' + doomed + ' event(s) recorded after ' + mmss(t) + '?')) { return; }
        events = events.filter(function (e) { return e.at <= t; });
        rebuildHolder();
        render();
    });
    /*
     * Settle the disc BEFORE pushing, not after.
     *
     * `push` renders, so clearing the holder on the next line painted the log
     * with the thrower still holding a disc they had just thrown away. Every
     * one of these reads the holder first, then moves it, then records.
     */
    el('turn').addEventListener('click', function () {
        var by = holder === null ? null : line[holder].label;
        holder = null;
        possession = 'them';
        push('turnover', { how: 'throwaway', by: by });
    });
    el('goal').addEventListener('click', function () {
        // A label, like every other path stores — this one kept the player
        // object, so the log read "GOAL - [object Object]" and no per-player
        // tally could ever find the scorer. And the point number belongs to
        // `startPoint`; incrementing here too skipped one every time.
        var by = holder === null ? null : line[holder].label;
        holder = null;
        possession = null;
        push('goal', { by: by });
    });

    document.addEventListener('keydown', function (ev) {
        /*
         * A SHORTCUT WITH A MODIFIER IS SOMEBODY ELSE'S SHORTCUT.
         *
         * Every single-letter key here was claimed unconditionally, so the
         * ordinary editing gestures did something else entirely: Cmd+C
         * recorded a DROP, Cmd+V a block, Cmd+A toggled AFK, and each one
         * called preventDefault so the copy never happened. Reported as
         * "copy and paste does not work", which it did not - it recorded a
         * turnover instead.
         */
        if (ev.metaKey || ev.ctrlKey || ev.altKey) { return; }
        var tag = ev.target.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT'
            || ev.target.isContentEditable) { return; }
        var k = ev.key.toLowerCase();

        if (k === ' ') {
            ev.preventDefault();
            if (!player) { return; }
            if (player.getPlayerState && player.getPlayerState() === 1) { player.pauseVideo(); }
            else { player.playVideo(); }
            return;
        }
        if (k === 'z') { ev.preventDefault(); popObservation(); render(); return; }
        if (k === 'm') { ev.preventDefault(); missed(); return; }
        if (k === 'n') { ev.preventDefault(); startPoint(); return; }
        if (k === 'a') { ev.preventDefault(); toggleAfk(); return; }
        if (THROWS[k]) { ev.preventDefault(); inFlight = { type: THROWS[k], tips: [], since: at() }; return; }

        if (k >= '1' && k <= String(lineSize)) {
            ev.preventDefault();
            var i = Number(k) - 1;
            if (i >= line.length || i === holder) { return; }   // nobody throws to themselves
            push('throw', { to: line[i].label, from: holder === null ? null : line[holder].label,
                throwType: inFlight ? inFlight.type : null,
                tipped: inFlight && inFlight.tips.length ? inFlight.tips.slice() : undefined });
            inFlight = null;
            holder = i;
            render();
            return;
        }
        if (TURNS[k]) {
            ev.preventDefault();
            var turnBy = holder === null ? null : line[holder].label;
            holder = null;
            possession = 'them';
            push('turnover', { how: TURNS[k], by: turnBy });
            return;
        }
        if (k === 'g') {
            ev.preventDefault();
            var goalBy = holder === null ? null : line[holder].label;
            holder = null;
            possession = null;
            push('goal', { by: goalBy });
            return;
        }
        if (k === 'p') {
            ev.preventDefault();
            setPlay(!inPlay, 'said', null);
            render();
            return;
        }
        if (k === '!' || k === '?') {
            ev.preventDefault();
            flag(lastEvent(), 'the spotter marked this');
        }
    });

    // ---- mode, play clock, flag, pocket ------------------------------------
    var BLURB = {
        live: 'Wall clock. Say the call; flag anything that lands wrong and settle it at the next stoppage.',
        // Training's line moved into the "How training mode works" block, so
        // the one sentence is not on screen twice.
        training: ''
    };

    /**
     * Switching view never touches capture.
     *
     * The microphone stays open, the clock keeps running and the play state
     * stays in the header, so a coach can read the table through a timeout
     * and lose nothing. Only the rendering changes.
     */
    function setView(v) {
        document.body.dataset.view = v;
        el('viewSpot').className = v === 'spot' ? 'on' : '';
        el('viewStats').className = v === 'stats' ? 'on' : '';
        if (v === 'stats') { renderCoach(); renderStats(); }
    }

    /**
     * Once the spotter opens or closes it by hand, that is their choice.
     *
     * `toggle` fires for a programmatic change too, so setting `.open` from
     * `setMode` marked it as touched on the very first load and the fold
     * never happened again.
     */
    var structTouched = false;
    var structSetting = false;

    if (el('structure')) {
        el('structure').addEventListener('toggle', function () {
            if (!structSetting) { structTouched = true; }
        });
    }

    el('viewSpot').addEventListener('click', function () { setView('spot'); });
    el('viewStats').addEventListener('click', function () { setView('stats'); });

    /**
     * Typing a call, which is not only a test hook.
     *
     * The microphone is the fast path and it is not the only one. A mic that
     * failed, a sideline too loud to speak over, a correction that is quicker
     * typed than argued with, a spotter who would rather not talk — all of
     * them want the same grammar through a different door. It runs the
     * identical parser, so anything typed here behaves exactly as it would
     * spoken, including landing in the review queue when it does not resolve.
     */
    el('say').addEventListener('keydown', function (ev) {
        if (ev.key !== 'Enter') { return; }
        ev.stopPropagation();
        var text = el('say').value.trim();
        el('say').value = '';
        if (!text) { return; }
        applyCall(parseCall(text));
    });

    /**
     * Seven invented players, because the first minute should not be typing.
     *
     * Names nobody has: this repository does not ship real people, and a
     * demo squad is exactly the place that rule gets broken by accident.
     */
    /**
     * A squad with a real O line and a real D line, and nothing else.
     *
     * Six a side for a five-a-side game, so every point starts with a choice
     * about who is on rather than the whole team walking out - which is what
     * makes calling the line worth rehearsing at all. Invented people, because
     * this repository does not ship real players and a demo squad is exactly
     * where that rule gets broken by accident.
     *
     * Nicknames and numbers on every one of them, because those are three of
     * the five ways a spotter can name somebody and a squad without them
     * cannot test the matching at all.
     *
     * The squad is checked against its own vocabulary, which is not a
     * formality: "Hawk" and "huck" are identical under the consonant key,
     * "Rocket" is within a hair of the dump alias "reset", and both shipped
     * here before `nameRisks` existed to say so.
     *
     * Every nickname is a real English word, which is not decoration. The
     * recogniser decodes only what is in its lexicon, so "Webbo" and "Hansi"
     * were dropped from the grammar and could never be said - a demo squad
     * whose examples cannot be spoken is worse than no demo squad.
     */
    /**
     * THE TWO TEAMS, NAMED, FROM THE EVENT.
     *
     * `O` and `D` are SLOTS rather than roles - the two teams playing this
     * game - and which of them is on offence changes every point, which is
     * what `starting` says. The letters are historical: they began as one
     * squad's lines and stayed as identifiers when the model grew a second
     * team. Everything a person reads goes through these names instead.
     */
    var teamNames = { O: 'Team O', D: 'Team D' };

    function teamName(slot) { return teamNames[slot] || ('Team ' + slot); }

    /**
     * Rosters from the installation, through the shared provider.
     *
     * The same reader every other surface uses, which answers for a hosted
     * Live! installation and for a standalone event with one shape - so
     * nothing here has to know which mode it is in, and a roster loaded for
     * the commentary desk is the roster the spotter gets.
     *
     * Quiet on failure. A spotter with no network still has the picker, the
     * demo squad and a typed line, and a page that refused to start because
     * an API was unreachable would be useless at exactly the moment it is
     * needed - a pitch in a park.
     */
    function loadFromEvent(gameId) {
        var cfg = window.SPOTTER_CONFIG;
        if (!gameId || !cfg || !window.Provider) { return; }
        var api = window.Provider.fromConfig(cfg);

        api.games().then(function (payload) {
            var game = (payload.games || []).filter(function (g) {
                return String(g.game_id) === String(gameId);
            })[0];
            if (!game) { return; }
            var pair = [[game.hometeam, 'O'], [game.visitorteam, 'D']];
            pair.forEach(function (entry) {
                if (!entry[0]) { return; }
                api.team(entry[0]).then(function (t) {
                    var team = t.teams ? (t.teams[entry[0]] || t.teams) : t;
                    var players = team.players || [];
                    if (team.name) { teamNames[entry[1]] = team.name; }
                    if (players.length) {
                        var squadded = players.map(function (q) {
                            return { firstname: q.firstname, lastname: q.lastname,
                                     nickname: q.nickname, num: q.number || q.num,
                                     role: entry[1] };
                        });
                        squadded.forEach(function (q) {
                            var p = asPlayer(q);
                            if (!squad.some(function (r) { return r.label === p.label; })) {
                                squad.push(p);
                            }
                        });
                        if (!sides[entry[1]].length) {
                            sides[entry[1]] = squad.filter(function (r) {
                                return r.role === entry[1];
                            }).slice(0, lineSize);
                        }
                    }
                    rebuildLine();
                }, function () { /* no roster for that team; the picker still works */ });
            });
        }, function () { /* offline, or no event: the demo squad is still there */ });
    }

    // Matchings included, so the mixed grouping is exercisable without a real
    // roster: these are invented people, and a real one's is never guessed.
    var DEMO_LINE = [
        { firstname: 'Ada', lastname: 'Weber', matching: 'FMP', nickname: 'Ace', role: 'O', num: 7 },
        { firstname: 'Hana', lastname: 'Lehner', matching: 'FMP', nickname: 'Robin', role: 'O', num: 11 },
        { firstname: 'Nico', lastname: 'Lang', matching: 'MMP', nickname: 'Speedy', role: 'O', num: 6 },
        { firstname: 'Kai', lastname: 'Reiter', matching: 'MMP', nickname: 'Bear', role: 'O', num: 23 },
        { firstname: 'Rina', lastname: 'Okafor', matching: 'FMP', nickname: 'Comet', role: 'O', num: 15 },
        { firstname: 'Nora', lastname: 'Sandor', matching: 'MMP', nickname: 'Storm', role: 'O', num: 18 },

        { firstname: 'Sky', lastname: 'Thaler', matching: 'FMP', nickname: 'Tiny', role: 'D', num: 2 },
        { firstname: 'Jo', lastname: 'Moser', matching: 'MMP', nickname: 'Mo', role: 'D', num: 9 },
        { firstname: 'Val', lastname: 'Wagner', matching: 'FMP', nickname: 'Wags', role: 'D', num: 4 },
        { firstname: 'Elias', lastname: 'Brandt', matching: 'MMP', nickname: 'Brandy', role: 'D', num: 8 },
        { firstname: 'Mira', lastname: 'Kovac', matching: 'FMP', nickname: 'Kova', role: 'D', num: 12 },
        { firstname: 'Emil', lastname: 'Roth', matching: 'MMP', nickname: 'Piper', role: 'D', num: 14 }
    ];

    el('desknotes').addEventListener('click', pullNotes);

    el('demoline').addEventListener('click', function () {
        // The squad is five and five, so the size comes with it - otherwise
        // one tap produces a line that reads 5 of 7 and looks broken.
        lineSize = 5;
        // Straight to `setSquad`, not through the text box: typing names as
        // text throws away the nickname and the number, and those are two of
        // the five ways a spotter can name somebody.
        setSquad(DEMO_LINE);
    });

    el('modeLive').addEventListener('click', function () { setMode('live'); });
    el('modeTrain').addEventListener('click', function () { setMode('training'); });

    el('playToggle').addEventListener('click', function () {
        setPlay(!inPlay, 'said', null);
        render();
    });

    el('flagBtn').addEventListener('click', function () {
        flag(lastEvent(), 'the spotter marked this');
    });

    /**
     * Pocket mode: the screen stops being a cost.
     *
     * Black kills most of the battery draw on an OLED phone and, more to the
     * point, stops a pocket from pressing things. The clock stays on so a
     * glance answers "is this still running" without unlocking anything.
     */
    function pocket(on) {
        document.body.classList.toggle('pocket', on);
        if (on) { keepAwake(); }
    }

    el('pocketBtn').addEventListener('click', function () { pocket(true); });
    el('pocketOut').addEventListener('click', function () { pocket(false); });

    setInterval(function () {
        if (!document.body.classList.contains('pocket')) { return; }
        el('pocketClock').textContent = mmss(at());
        var open = events.filter(function (e) {
            return e.type === 'unmatched' || e.open || e.certain === false;
        }).length;
        el('pocketState').textContent = (voiceOn ? 'listening' : 'NOT LISTENING')
            + (open ? ' \u00b7 ' + open + ' to review' : '');
    }, 1000);

    /**
     * Which microphone, which matters more than it looks.
     *
     * A phone in a pocket with a lav mic clipped to a collar will happily
     * record from the built-in one instead — muffled by the pocket, and the
     * session is worthless before it starts. Labels only appear after
     * permission is granted, so the list fills in once the mic is on.
     */
    function listMics() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) { return; }
        navigator.mediaDevices.enumerateDevices().then(function (all) {
            var sel = el('micDevice');
            var chosen = sel.value;
            sel.replaceChildren();
            var auto = document.createElement('option');
            auto.value = '';
            auto.textContent = 'default mic';
            sel.append(auto);
            all.filter(function (d) { return d.kind === 'audioinput'; })
                .forEach(function (d, i) {
                    var o = document.createElement('option');
                    o.value = d.deviceId;
                    o.textContent = d.label || ('microphone ' + (i + 1));
                    sel.append(o);
                });
            sel.value = chosen;
        }, function () { /* no permission yet, nothing to list */ });
    }

    el('micDevice').addEventListener('change', function () {
        if (voiceOn) { el('mic').click(); el('mic').click(); }
    });
    if (navigator.mediaDevices) {
        navigator.mediaDevices.addEventListener('devicechange', listMics);
    }
    listMics();

    /*
     * Restore BEFORE choosing the mode. `setMode` persists, so running it
     * first wrote an empty session over the stored one and the resume feature
     * quietly stopped working. The saved mode is also the default, so a
     * reload comes back to whichever job was in progress.
     */
    restore();
    loadFromEvent(document.body.dataset.game);
    setView('spot');
    var asked = new URL(window.location.href).searchParams.get('mode');
    // Adopt what the session was in, then honour an explicit ?mode= as the
    // switch it is — confirm and all. Adopting the URL's mode directly would
    // put a restored session's video-time stamps under a wall clock.
    setMode(restoredMode || asked || 'live', true);
    if (asked && asked !== MODE) { setMode(asked); }
    render();
}());
</script>
<!-- The local recogniser, if it has been fetched. Absent is fine: the page
     falls back to the browser's own (cloud) recognition and says which one it
     is using. tools/capture-poc/get-model.sh puts it here. -->
<script src="<?= $e($assetUrl('spotter/vosk.js')) ?>" onerror="window.__noVosk = true"></script>
<script src="https://www.youtube.com/iframe_api"></script>

<?php if ($hasModel) : ?>
<script src="<?= $e($assetUrl('spotter/vosk.js')) ?>" onerror="window.__noVosk = true"></script>
<?php else : ?>
<script>window.__noVosk = true;</script>
<?php endif; ?>
<script src="https://www.youtube.com/iframe_api"></script>
</body>
</html>
