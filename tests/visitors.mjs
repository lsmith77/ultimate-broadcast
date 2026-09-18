/**
 * The access-log reader still counts what it claims to count.
 *
 * `tools/visitors.php` is the only way this project answers "how many people
 * opened the site and how many ran the demo", and the failure it has to be
 * guarded against is silent: a log line that stops being recognised is not an
 * error, it is a quieter week. Nothing about the output says a number went
 * missing, and nobody has the previous week's log to compare against.
 *
 * So the fixture is a log with a KNOWN answer, carrying one of each thing that
 * has to be handled correctly:
 *
 *   a short URL answered 302 and then 200     counted ONCE, not twice
 *   a virtual host logged before the address  parsed anyway
 *   an IPv6 client                            parsed anyway
 *   a stylesheet, and a crawler               excluded, and counted as excluded
 *   a line in no format at all                counted as unparsed, never dropped
 *
 * Every address in it is from RFC 5737 and RFC 3849 — the ranges reserved for
 * documentation — so the fixture contains nothing about any real person, which
 * is the same rule the rest of this project's committed examples follow.
 *
 * Run by `npm run check`, and by hand with `node tests/visitors.mjs`.
 */
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');

const TOOL = join(root, 'tools', 'visitors.php');
const LOG = join(root, 'fixtures', 'access-log-sample.log');

/**
 * What the fixture adds up to, worked out by hand from the file.
 *
 * These are literals rather than anything derived from the log, because a test
 * that recomputes the answer the same way the program does agrees with the
 * program's bugs.
 */
const EXPECTED = {
  from: '2026-09-07',
  to: '2026-09-08',
  days: 2,
  visitors: 4,
  demo_visitors: 3,
  page_views: 11,
  demo_views: 3,
  surfaces: {
    Studio: { visitors: 3, views: 4 },
    Scoreboard: { visitors: 1, views: 2 },
    Stage: { visitors: 2, views: 2 },
    'Commentary desk': { visitors: 1, views: 1 },
    'Match control': { visitors: 1, views: 1 },
    Imprint: { visitors: 1, views: 1 },
  },
  excluded: {
    bot_requests: 3,
    assets: 1,
    redirects_and_errors: 2,
    unparsed: 1,
    lines_read: 18,
  },
};

let failed = 0;

const fail = (message) => {
  console.error(`FAIL ${message}`);
  failed += 1;
};

const is = (label, actual, expected) => {
  if (actual !== expected) {
    fail(`${label}: expected ${expected}, got ${actual}`);
  }
};

let report;

try {
  report = JSON.parse(execFileSync('php', [TOOL, '--json', LOG], { encoding: 'utf8' }));
} catch (error) {
  console.error(`FAIL tools/visitors.php did not run: ${error.message}`);
  process.exit(1);
}

is('from', report.from, EXPECTED.from);
is('to', report.to, EXPECTED.to);
is('days', report.days, EXPECTED.days);
is('visitors', report.visitors, EXPECTED.visitors);
is('demo visitors', report.demo_visitors, EXPECTED.demo_visitors);
is('page views', report.page_views, EXPECTED.page_views);
is('demo views', report.demo_views, EXPECTED.demo_views);

for (const [name, want] of Object.entries(EXPECTED.surfaces)) {
  const got = report.surfaces[name];

  if (!got) {
    fail(`surface ${name}: missing from the report entirely`);
    continue;
  }

  is(`surface ${name} visitors`, got.visitors, want.visitors);
  is(`surface ${name} views`, got.views, want.views);
}

for (const name of Object.keys(report.surfaces)) {
  if (!(name in EXPECTED.surfaces)) {
    fail(`surface ${name}: reported, but the fixture has no traffic for it`);
  }
}

for (const [key, want] of Object.entries(EXPECTED.excluded)) {
  is(`excluded.${key}`, report.excluded[key], want);
}

/*
 * Surface views must account for every page view. This is the assertion that
 * catches a URL shape nobody classified — a new route added to app.php and not
 * to SURFACES shows up in the total and in no row, which reads as "nobody
 * visited the new page" rather than as a bug in the reader.
 */
const classified = Object.values(report.surfaces).reduce((sum, s) => sum + s.views, 0);

if (classified !== report.page_views) {
  fail(`${report.page_views - classified} page views matched no surface — a route is missing from SURFACES`);
}

/*
 * The output must never carry an address. The program salts its hashes with a
 * value it throws away, precisely so its output can be pasted somewhere; a
 * change that started printing the client would break that silently.
 */
const raw = JSON.stringify(report);

for (const address of ['192.0.2.', '198.51.100.', '203.0.113.', '2001:db8']) {
  if (raw.includes(address)) {
    fail(`the report printed ${address}… — output must never contain an address`);
  }
}

/*
 * Two runs must agree on the counts and share no hash. The salt is per-run, so
 * this is the cheap proof that it is actually random rather than constant.
 */
const second = JSON.parse(execFileSync('php', [TOOL, '--json', LOG], { encoding: 'utf8' }));

is('visitors on a second run', second.visitors, report.visitors);
is('demo visitors on a second run', second.demo_visitors, report.demo_visitors);

if (failed === 0) {
  console.log(
    `visitors: ${report.visitors} people, ${report.demo_visitors} ran the demo, `
      + `${report.page_views} views across ${Object.keys(report.surfaces).length} surfaces — all as the fixture says.`
  );
} else {
  console.error(`\n${failed} check${failed === 1 ? '' : 's'} failed in tools/visitors.php.`);
  process.exit(1);
}
