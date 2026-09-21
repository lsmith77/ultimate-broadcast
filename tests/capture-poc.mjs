/**
 * The phase 0 reporter counts what it claims to count.
 *
 * `tools/capture-poc/report.mjs` produces the numbers that decide whether the
 * detailed-statistics direction is worth pursuing at all (`docs/MATCHCONTROL.md`
 * §10a). A reporter that quietly miscounts would not fail — it would answer,
 * and the answer would be acted on.
 *
 * Two of these exist because the first version was wrong in exactly that way:
 * the vocabulary check matched whole words only and reported "huck" and "drop"
 * as never said about a transcript containing "hucks it deep" and "dropped by
 * Keller". Nothing failed; the number was simply untrue.
 *
 *   node tests/capture-poc.mjs
 */
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const TOOL = path.join(ROOT, 'tools', 'capture-poc', 'report.mjs');
const FIXTURE = path.join(ROOT, 'tools', 'capture-poc', 'fixture');

let failed = 0;
const fail = (msg) => { console.error(`  ${msg}`); failed += 1; };
const is = (what, got, want) => {
  if (JSON.stringify(got) !== JSON.stringify(want)) {
    fail(`${what}: got ${JSON.stringify(got)}, expected ${JSON.stringify(want)}`);
  }
};

const run = (args) => JSON.parse(execFileSync('node', [TOOL, ...args, '--json'], {
  cwd: ROOT, encoding: 'utf8',
}));

const report = run([
  path.join(FIXTURE, 'annotations.json'), '--roster', path.join(FIXTURE, 'roster.json'),
]);

is('entries counted', report.entries, 13);
// Three entries carry no event at all. That figure is the point of phase 0 —
// commentary that narrates without naming anything is the thing being measured.
is('entries carrying an event', report.withEvent, 10);

/*
 * Inflections. "hucks" is a huck and "dropped" is a drop, because the question
 * is whether the assumed vocabulary appears in real speech, not whether it
 * appears in its dictionary form.
 */
is('huck counted from "hucks"', report.jargon.huck, 1);
is('drop counted from "dropped"', report.jargon.drop, 1);
is('swing counted twice', report.jargon.swing, 2);
if (report.jargon.callahan) {
  fail('callahan was counted, and the fixture never says it');
}

/*
 * Attribution. Nine events name somebody and one — "and that is a turnover" —
 * names nobody, which is a real thing commentators say and a real limit on
 * what any recogniser could extract.
 */
is('events naming a player', report.named, 9);
is('unattributed events', report.nameForms.none, 1);
is('named by surname', report.nameForms.last, 5);
is('named by shirt number', report.nameForms.number, 1);

/*
 * Ambiguity, measured against the squad rather than assumed. The fixture has
 * two Webers on purpose: "Weber swings it wide" cannot be resolved to a
 * player, and knowing how often that happens is why --roster exists.
 */
is('ambiguous against the squad', report.ambiguous, 3);

// Without a roster the reporter still works, and says less rather than guessing.
const blind = run([path.join(FIXTURE, 'annotations.json')]);
is('still counts events with no roster', blind.withEvent, 10);
is('claims no name forms it cannot check', blind.nameForms.last, 0);
if (blind.ambiguous !== 0) {
  fail('ambiguity was reported without a roster to measure it against');
}

if (failed) {
  console.error(`\n${failed} problem${failed === 1 ? '' : 's'} in the phase 0 reporter.`);
  process.exit(1);
}

console.log('phase 0 reporter counts events, attribution, ambiguity and inflected jargon.');
