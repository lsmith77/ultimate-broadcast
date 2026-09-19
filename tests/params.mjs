/**
 * Every URL parameter the documentation offers is one a page actually reads.
 *
 * This exists because of `?auto=1`. `STUDIO.md` §8 offered it as the way to run
 * a stage with no operator, and `stage.php` had never parsed it — so a
 * tournament following the documentation got whatever the last person left in
 * `conf/show.json`, with nothing to say the switch had been ignored. The
 * documentation was the only place the feature existed.
 *
 * That is a class of defect rather than one mistake: a parameter is a promise
 * made in prose and kept in code, the two are edited months apart, and nothing
 * fails when they drift. Links are checked, sections are checked, and this is
 * the same idea for the third thing docs hand a reader — a URL to type.
 *
 * WHAT IT DOES NOT CHECK
 *
 * That the parameter does what the sentence says. A checker cannot read prose;
 * it can only tell that somebody, somewhere, looked the parameter up. That is
 * the cheap nine-tenths, and the expensive tenth is what browser tests are for.
 *
 *   node tests/params.mjs
 */
import { readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (p) => readFileSync(path.join(ROOT, p), 'utf8');

/**
 * Parameters that belong to somebody else, or to no page at all.
 *
 * Live!'s API is a third-party interface this project only calls, and a few
 * names appear in prose as examples of "any query string" rather than as
 * features. Each entry is a claim that the name is not ours to implement.
 */
const NOT_OURS = new Set([
  'entity', 'id', // Live!'s API: ?view=live/api&entity=games&id=702
  'debug', // an example of an arbitrary query surviving a redirect (app.php)
  'view', // the front controller's own, read everywhere
  'utm_source', // in a URL somebody pasted
]);

const docs = [
  ...readdirSync(ROOT).filter((f) => f.endsWith('.md')),
  ...readdirSync(path.join(ROOT, 'docs')).filter((f) => f.endsWith('.md')).map((f) => `docs/${f}`),
].sort();

const pages = readdirSync(ROOT).filter((f) => f.endsWith('.php'));
const shared = readdirSync(path.join(ROOT, 'shared')).filter((f) => f.endsWith('.php') || f.endsWith('.js'));
const code = [
  ...pages.map((f) => read(f)),
  ...shared.map((f) => read(`shared/${f}`)),
].join('\n');

/**
 * A parameter counts as read if a page asks for it by name, through any of the
 * spellings this project uses — `filter_input`, `$_GET`, the whitelisting
 * helper on the scoreboard, or a browser reading its own URL.
 */
function isRead(name) {
  const patterns = [
    `filter_input(INPUT_GET, '${name}'`,
    `INPUT_GET, '${name}'`,
    `$_GET['${name}']`,
    `overlay_choice('${name}'`,
    `searchParams.get('${name}')`,
  ];
  return patterns.some((p) => code.includes(p));
}

const found = new Map();
for (const doc of docs) {
  const body = read(doc);
  body.split('\n').forEach((line, i) => {
    for (const m of line.matchAll(/[?&]([a-z][a-z0-9_]{1,16})=/g)) {
      const name = m[1];
      if (NOT_OURS.has(name)) continue;
      if (!found.has(name)) found.set(name, `${doc}:${i + 1}`);
    }
  });
}

if (found.size === 0) {
  console.error('No URL parameters were found in the documentation — the matcher is '
    + 'wrong, not the docs.');
  process.exit(1);
}

let missing = 0;
for (const [name, where] of [...found].sort()) {
  if (isRead(name)) continue;
  console.error(`${where}: the docs offer ?${name}= and no page reads it.`);
  missing += 1;
}

if (missing) {
  console.error(`\n${missing} documented URL parameter${missing === 1 ? ' is' : 's are'} `
    + 'not implemented. Either the page lost it or the sentence should go.');
  process.exit(1);
}

console.log(`all ${found.size} URL parameters offered by the documentation are read by a page.`);
