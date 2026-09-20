/**
 * What twenty minutes of real commentary actually contains.
 *
 * Phase 0 of the detailed-statistics experiment (`docs/MATCHCONTROL.md` §10a),
 * and the one that can end it cheapest: if natural commentary does not carry
 * the events, no recogniser rescues it, and nobody needs to evaluate a model.
 *
 *   node report.mjs annotations.json --roster roster.json
 *   node report.mjs annotations.json --roster roster.json --json
 *
 * Input is what a person wrote down while listening — see `annotate.html`.
 * This does no recognition and downloads nothing.
 *
 * WHAT IT DELIBERATELY DOES NOT SAY
 *
 * How much was missed. This measures what was SAID, against a human
 * transcript; what actually happened on the field needs somebody who can
 * identify players from video, and broadcast footage rarely shows a shirt
 * number clearly enough. That is a different experiment with a different
 * method, and conflating the two is how a coverage figure gets invented.
 */
import { readFileSync } from 'node:fs';

const args = process.argv.slice(2);
let asJson = false;
let source = null;
/**
 * Repeatable, because a game has two squads.
 *
 * With one, every name from the other team reads as nobody — which
 * understates attribution and, worse, reports zero ambiguity for a surname
 * that is only ambiguous across the two teams. Both squads or the numbers
 * describe half a game.
 */
const rosterPaths = [];

for (let i = 0; i < args.length; i += 1) {
  if (args[i] === '--json') {
    asJson = true;
  } else if (args[i] === '--roster') {
    i += 1;
    if (!args[i]) {
      console.error('--roster needs a path');
      process.exit(2);
    }
    rosterPaths.push(args[i]);
  } else if (!args[i].startsWith('--') && source === null) {
    source = args[i];
  }
}

if (!source) {
  console.error('usage: node report.mjs <annotations.json> '
    + '[--roster <squad.json> [--roster <squad.json>]] [--json]');
  console.error('  a squad is {"players":[{"firstname","lastname","nickname"}]} — which is');
  console.error('  also the shape UltiOrganizer answers with for entity=teams, so its own');
  console.error('  payload can be passed straight in.');
  process.exit(2);
}

const read = (p) => JSON.parse(readFileSync(p, 'utf8'));
const doc = read(source);
const entries = Array.isArray(doc.entries) ? doc.entries : [];

if (!entries.length) {
  console.error(`${source}: no entries. Annotate some commentary first — see README.md.`);
  process.exit(3);
}

/**
 * The jargon §10a assumes: a closed set of English loanwords, said the same
 * way wherever the sport is played. Phase 0 is where that assumption meets a
 * real broadcast, so the list is checked against rather than trusted — words
 * that never appear are as informative as words that do.
 */
const JARGON = [
  'huck', 'swing', 'dump', 'break', 'hammer', 'scoober', 'blade', 'flick',
  'backhand', 'block', 'callahan', 'drop', 'throwaway', 'turn', 'turnover',
  'stall', 'foul', 'pull', 'zone', 'poach', 'layout', 'score', 'goal', 'assist',
];

/** Words that a keyword-only call format would have to do without. */
const FUNCTION_WORDS = ['to', 'from', 'catches', 'and', 'the', 'for', 'with', 'it'];

const NUMBER_WORDS = [
  'zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight',
  'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen',
  'sixteen', 'seventeen', 'eighteen', 'nineteen', 'twenty',
];

const words = (s) => String(s || '').toLowerCase().match(/[a-zà-ÿ']+/g) || [];

/**
 * Jargon, in the shapes people actually say it.
 *
 * The first version matched exactly, and reported "huck" and "drop" as never
 * said about a transcript containing "hucks it deep" and "dropped by Keller".
 * That is the wrong answer to the question being asked — whether the assumed
 * vocabulary appears — so the inflections are enumerated rather than stemmed,
 * because an explicit list is readable and a stemmer is a dependency.
 */
function inflections(word) {
  const last = word.slice(-1);
  return new Set([
    word, `${word}s`, `${word}es`, `${word}d`, `${word}ed`, `${word}ing`,
    `${word}${last}ed`, `${word}${last}ing`,
  ]);
}

const JARGON_FORMS = new Map(JARGON.map((w) => [w, inflections(w)]));

/** The jargon word this spoken word is, or null. */
function jargonOf(spoken) {
  for (const [word, forms] of JARGON_FORMS) {
    if (forms.has(spoken)) { return word; }
  }

  return null;
}

/** The squads, if any were supplied: names in every form somebody might say. */
function rosterIndex(paths) {
  if (!paths.length) { return null; }
  const players = paths.flatMap((p) => {
    const raw = read(p);

    return Array.isArray(raw) ? raw : (raw.players || []);
  });
  const forms = new Map();   // spoken form -> [player, …]

  const add = (form, player) => {
    const key = String(form || '').toLowerCase().trim();
    if (key.length < 2) { return; }
    if (!forms.has(key)) { forms.set(key, []); }
    if (!forms.get(key).includes(player)) { forms.get(key).push(player); }
  };

  players.forEach((p) => {
    const first = p.firstname || (String(p.name || '').split(' ')[0]);
    const last = p.lastname || String(p.name || '').split(' ').slice(1).join(' ');
    const who = `${first} ${last}`.trim();
    add(first, who);
    add(last, who);
    if (p.nickname) { add(p.nickname, who); }
  });

  return { players, forms };
}

const roster = rosterIndex(rosterPaths);

const tally = {
  entries: entries.length,
  minutes: Math.round((doc.duration || 0) / 60) || null,
  classes: {},
  withEvent: 0,
  named: 0,
  nameForms: { first: 0, last: 0, nickname: 0, number: 0, none: 0 },
  ambiguous: 0,
  jargon: {},
  functionWords: 0,
};

for (const entry of entries) {
  const classes = Array.isArray(entry.classes) ? entry.classes : [];
  const carries = classes.filter((c) => c !== 'none');
  classes.forEach((c) => { tally.classes[c] = (tally.classes[c] || 0) + 1; });
  if (carries.length) { tally.withEvent += 1; }

  const said = words(entry.words);
  said.forEach((w) => {
    const j = jargonOf(w);
    if (j) { tally.jargon[j] = (tally.jargon[j] || 0) + 1; }
  });
  if (said.some((w) => FUNCTION_WORDS.includes(w))) { tally.functionWords += 1; }

  // Only events need an actor: "great play by the Herons" attributes nothing
  // and is not supposed to.
  if (!carries.length) { continue; }

  let form = 'none';
  let ambiguous = false;

  if (said.some((w) => NUMBER_WORDS.includes(w) || /^\d+$/.test(w))) {
    form = 'number';
  }

  if (roster) {
    for (const w of said) {
      const hit = roster.forms.get(w);
      if (!hit) { continue; }
      const player = hit[0];
      const bits = player.toLowerCase().split(' ');
      if (form === 'none' || form === 'number') {
        form = bits[0] === w ? 'first' : (bits.slice(1).join(' ') === w ? 'last' : 'nickname');
      }
      if (hit.length > 1) { ambiguous = true; }
    }
  }

  tally.nameForms[form] += 1;
  if (form !== 'none') { tally.named += 1; }
  if (ambiguous) { tally.ambiguous += 1; }
}

if (asJson) {
  console.log(JSON.stringify(tally, null, 2));
  process.exit(0);
}

const pct = (n, of) => (of ? `${Math.round((n / of) * 100)}%` : '—');
const line = (label, value, note) =>
  console.log(`  ${label.padEnd(34)}${String(value).padStart(6)}${note ? '   ' + note : ''}`);

console.log(`\ncommentary: ${doc.source || source}` + (tally.minutes ? ` (${tally.minutes} min)` : ''));
console.log('\nWHAT WAS SAID');
line('entries marked', tally.entries);
line('carrying an event', tally.withEvent, pct(tally.withEvent, tally.entries));
if (tally.minutes) {
  line('events per minute', (tally.withEvent / tally.minutes).toFixed(1));
}

console.log('\nBY CLASS');
Object.entries(tally.classes)
  .sort((a, b) => b[1] - a[1])
  .forEach(([id, n]) => line(id, n, pct(n, tally.entries)));

console.log('\nWHO DID IT');
line('events naming a player', tally.named, pct(tally.named, tally.withEvent));
if (!roster) {
  console.log('    (no --roster given, so name forms are guessed from numbers alone)');
}
Object.entries(tally.nameForms).forEach(([form, n]) => {
  if (n) { line(`  by ${form}`, n, pct(n, tally.withEvent)); }
});
if (roster) {
  line('ambiguous against the squad', tally.ambiguous, pct(tally.ambiguous, tally.withEvent));
}

console.log('\nVOCABULARY');
const used = Object.entries(tally.jargon).sort((a, b) => b[1] - a[1]);
if (used.length) {
  console.log('    used: ' + used.map(([w, n]) => `${w} (${n})`).join(', '));
} else {
  console.log('    none of the assumed jargon appeared at all');
}
const unused = JARGON.filter((w) => !tally.jargon[w]);
if (unused.length) {
  console.log('    never said: ' + unused.join(', '));
}
line('entries using function words', tally.functionWords, pct(tally.functionWords, tally.entries));

console.log(`
WHAT THIS IS WORTH

  A floor. These commentators did not know a machine was listening, so a
  deployed system would collect more — and §10a records why that is also a
  risk to the broadcast rather than only a gain for the data.

  It says nothing about what was missed. That needs somebody who can identify
  players from video, which is phase 2 and a different method.
`);
