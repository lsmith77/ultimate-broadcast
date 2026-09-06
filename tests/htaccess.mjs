/**
 * The three copies of the `conf/` allow-list still say the same thing.
 *
 * `conf/` is closed by default and opened one file at a time, because it holds
 * the commentary desk's prepared notes — notes about named people — alongside
 * the administrator hash and everything the operator has set. Three files are
 * opened by name, because the stage polls them as static assets about once a
 * second and a PHP bootstrap per second per surface is the one place the poll
 * budget genuinely matters.
 *
 * That list exists three times, and it has to, because each copy is read by a
 * different thing:
 *
 *   .htaccess                    hosted, under Apache
 *   install/standalone.htaccess  a site of its own, under Apache
 *   app.php                      `php -S`, which reads no .htaccess at all
 *
 * None of them can be derived from the others at runtime. So the failure this
 * guards is drift: a fourth pollable file added to two of the three — which is
 * exactly what happened when match control's `score-<game>.json` arrived — and
 * the copy that was forgotten either serves the notes or breaks the stage,
 * silently, on one deployment shape only.
 *
 * Run by `.github/workflows/ci.yml` through `npm run check`, and by hand with
 * `node tests/htaccess.mjs`.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');

const SOURCES = [
  ['.htaccess', 'hosted Apache rules'],
  ['install/standalone.htaccess', 'standalone Apache rules'],
  ['app.php', "the built-in server's router"],
];

/**
 * The filenames a source opens, as it spells them.
 *
 * Matched on the ESCAPED dot, which is what makes this safe to run over files
 * whose comments discuss the same names in prose: `show\.json` is a pattern and
 * `show.json` in a sentence is not. AGENTS.md's warning about trusting your own
 * regex is the reason for the emptiness check below rather than for a cleverer
 * pattern — if this stops matching, it must fail rather than compare two empty
 * sets and congratulate itself.
 */
const NAME = /[A-Za-z0-9_]+(?:-\[0-9\]\+)?\\\.json/g;

let failed = 0;
const found = new Map();

for (const [file, what] of SOURCES) {
  let text;
  try {
    text = readFileSync(join(root, file), 'utf8');
  } catch {
    console.error(`FAIL ${file}: missing — ${what} is part of a deployment`);
    failed += 1;
    continue;
  }
  const names = [...new Set(text.match(NAME) ?? [])].sort();
  if (names.length === 0) {
    console.error(
      `FAIL ${file}: no conf/ allow-list found. Either the rule was removed — `
      + 'which would serve the desk\'s notes — or it is spelled in a way this '
      + 'check no longer recognises. Both need a person.',
    );
    failed += 1;
    continue;
  }
  found.set(file, names);
  console.log(`ok   ${file}: ${names.join(', ')}`);
}

if (!failed) {
  const [first, ...rest] = [...found.entries()];
  for (const [file, names] of rest) {
    if (names.join('|') !== first[1].join('|')) {
      console.error(
        `\nFAIL ${file} and ${first[0]} disagree about what conf/ serves:\n`
        + `  ${first[0]}: ${first[1].join(', ')}\n`
        + `  ${file}: ${names.join(', ')}`,
      );
      failed += 1;
    }
  }
}

/**
 * The standalone rules must name `app.php` as the index.
 *
 * Without it the server reaches for `index.php`, which is the Studio page — a
 * file that refuses to run unrouted — so the site's own front page answers 404
 * while every other URL works. A deployment failure that looks like a routing
 * bug, on the one URL a visitor tries first.
 */
const standalone = join(root, 'install/standalone.htaccess');
try {
  if (!/^\s*DirectoryIndex\s+app\.php\s*$/m.test(readFileSync(standalone, 'utf8'))) {
    console.error('\nFAIL install/standalone.htaccess: no "DirectoryIndex app.php" — '
      + 'the front page would 404.');
    failed += 1;
  }
} catch {
  // Already reported above as a missing source.
}

if (failed) {
  console.error(`\n${failed} problem${failed === 1 ? '' : 's'} with the conf/ rules.`);
  process.exit(1);
}
console.log(`\nall ${SOURCES.length} copies of the conf/ allow-list agree.`);
