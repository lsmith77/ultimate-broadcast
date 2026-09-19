/**
 * State that belongs to a game is dropped when the game changes.
 *
 * A field-following scoreboard (`/s/field/1/overlay`) swaps the game under
 * itself as the day runs, and everything the page is holding ABOUT that game
 * has to go with it. `resetForNewGame()` in `scoreboard.php` is the list, and
 * this asserts that the things which caused a bug are still on it.
 *
 * WHY THIS IS A SOURCE CHECK RATHER THAN A BROWSER TEST
 *
 * Both, honestly, would be better — but the browser cannot reach it here. The
 * switch needs two live games on ONE field, and a capture is a recording of a
 * real instance: the two games in it are on fields 1 and 3, so a following
 * board never changes game against it. The state is also inside the page's
 * closure, so nothing can read it from outside. The alternative was to leave
 * the fix unverified, and the defect it fixes is the kind this project cares
 * about most: a true sentence about a game that is no longer in front of the
 * camera, still on air.
 *
 * It is deliberately a list of NAMES rather than something clever. A check that
 * tried to infer per-game state automatically would either miss things or cry
 * wolf, and this one fails for exactly one reason: somebody removed a line that
 * a broadcast depends on.
 *
 *   node tests/gamestate.mjs
 */
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = readFileSync(path.join(ROOT, 'scoreboard.php'), 'utf8');

const body = (source.match(/function resetForNewGame\(\)\s*\{([\s\S]*?)\n    \}/) || [])[1];
if (!body) {
  console.error('resetForNewGame() was not found in scoreboard.php — this check is '
    + 'wrong, or the function was renamed and every caller with it.');
  process.exit(1);
}

/** Each entry: the variable, and the defect that put it here. */
const MUST_CLEAR = [
  ['painted', 'a new game would inherit the previous one\'s first-paint state'],
  ['shownScore', 'the score flash would fire against the old game\'s score'],
  ['lastGoalNum', 'the hold/break tab would replay for a goal in another game'],
  ['outcome', 'a HOLD tab would outlive the game it described'],
  ['possession', 'a BREAK CHANCE tab would carry into a different match'],
  ['ribbonContext', 'the ribbon would name the previous fixture'],
  ['statShown', 'the stat strip is held between points by comparing the goal '
    + 'count, so a new game on the same count kept the previous game\'s sentence'],
  ['lastRendered', 'the ~1s channel repaints the strip from it, so it would '
    + 'compute facts about the game that just left the screen'],
];

let missing = 0;
for (const [name, why] of MUST_CLEAR) {
  if (!new RegExp(`\\b${name}\\s*=`).test(body)) {
    console.error(`resetForNewGame() no longer clears \`${name}\` — ${why}.`);
    missing += 1;
  }
}

if (missing) {
  console.error(`\n${missing} piece${missing === 1 ? '' : 's'} of per-game state `
    + 'would survive a change of game on a field-following board.');
  process.exit(1);
}

console.log(`all ${MUST_CLEAR.length} pieces of per-game state are dropped when the `
  + 'game changes.');
