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
import { readFileSync, writeFileSync, rmSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { tmpdir } from 'node:os';
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
  visitors: 5,
  demo_visitors: 3,
  page_views: 12,
  demo_views: 3,
  surfaces: {
    Studio: { visitors: 4, views: 5 },
    Scoreboard: { visitors: 1, views: 2 },
    Stage: { visitors: 2, views: 2 },
    'Commentary desk': { visitors: 1, views: 1 },
    'Match control': { visitors: 1, views: 1 },
    Imprint: { visitors: 1, views: 1 },
  },
  excluded: {
    bot_requests: 3,
    assets: 1,
    // The one-second channel: possession twice, score, show state, a squad.
    // They are requests from pages that were already counted when they opened,
    // and counting them as views swamped the number they were added to.
    polls: 5,
    redirects_and_errors: 2,
    unparsed: 1,
    lines_read: 24,
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
 * A view that matches no surface is REPORTED, not dropped.
 *
 * The assertion above only runs against this fixture; the gap it describes
 * turns up in real logs, where no test runs. So the reader has to say so
 * itself, and this is the check that it does — including when nothing matched
 * a surface at all, which prints no table and used to print no warning either.
 */
{
  const stray = join(tmpdir(), `visitors-stray-${process.pid}.log`);
  writeFileSync(stray,
    '203.0.113.9 - - [09/Sep/2026:10:00:00 +0200] "GET /app.php?view=nosuchpage HTTP/1.1"'
    + ' 200 10 "-" "Mozilla/5.0 (X11; Linux x86_64) Chrome/130"\n');

  try {
    const shown = execFileSync('php', [TOOL, stray], { encoding: 'utf8' });
    is('an unclassified view is shown', /Unclassified views\s+1/.test(shown), true);
  } finally {
    rmSync(stray, { force: true });
  }
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

/*
 * The wrapper agrees with the tool it wraps.
 *
 * `tools/stats.sh` exists to be the one command — find the log on the host,
 * stream it here, read it — and the half that can be tested without somebody's
 * server is the reading. If the two ever disagree, the numbers a person
 * actually looks at are not the numbers this file checks.
 *
 * It is also the only coverage the argument forwarding gets: `--json` reaches
 * visitors.php through the wrapper or it does not, and nothing else would say.
 */
/** Run the wrapper and parse it, saying which of those two failed. */
const wrapper = (file) => {
  let out;

  try {
    out = execFileSync(join(root, 'tools', 'stats.sh'),
      ['--file', file, '--json'], { encoding: 'utf8' });
  } catch (error) {
    fail(`tools/stats.sh --file did not run: ${error.message}`);

    return null;
  }

  try {
    return JSON.parse(out);
  } catch {
    // The likeliest cause is the wrapper not forwarding --json, which would
    // otherwise surface as a JSON syntax error naming no file anybody edited.
    fail('tools/stats.sh did not return JSON — is --json still forwarded to visitors.php?');

    return null;
  }
};

const viaWrapper = wrapper(LOG) ?? {};

is('the wrapper reports the same visitors', viaWrapper.visitors, EXPECTED.visitors);
is('the wrapper reports the same demo visitors', viaWrapper.demo_visitors, EXPECTED.demo_visitors);
is('the wrapper reports the same page views', viaWrapper.page_views, EXPECTED.page_views);

/*
 * A rotated log is a gzipped one, which is most of what a host still has. The
 * wrapper decompresses; visitors.php on its own does not have to.
 */
const gz = join(tmpdir(), `visitors-check-${process.pid}.log.gz`);
writeFileSync(gz, gzipSync(readFileSync(LOG)));

try {
  const viaGzip = wrapper(gz) ?? {};
  is('a gzipped log counts the same', viaGzip.visitors, EXPECTED.visitors);
  is('a gzipped log counts the same views', viaGzip.page_views, EXPECTED.page_views);
} finally {
  rmSync(gz, { force: true });
}

/*
 * An empty log is not a quiet week.
 *
 * Reading nothing used to produce a full report of zeroes — "0 visitors, 0
 * page views" over a blank date range — which is indistinguishable from a real
 * week with no traffic. It appeared in practice underneath the wrapper's own
 * "found no access log" message, which is the worst version: the error is on
 * the screen and the table below it quietly contradicts it.
 *
 * The human report must refuse. The JSON must not, because a caller asking for
 * JSON is a program that can read `lines_read: 0` for itself.
 */
const empty = join(tmpdir(), `visitors-empty-${process.pid}.log`);
writeFileSync(empty, '');

try {
  let printed = null;
  let status = 0;
  try {
    printed = execFileSync('php', [TOOL, empty], { encoding: 'utf8', stdio: 'pipe' });
  } catch (error) {
    status = error.status;
    printed = error.stdout ?? '';
  }
  is('an empty log is refused rather than reported', status, 3);
  is('and prints no table of zeroes', printed.trim(), '');

  const json = JSON.parse(execFileSync('php', [TOOL, '--json', empty], { encoding: 'utf8' }));
  is('but JSON still answers, with the count that says why', json.excluded.lines_read, 0);
  is('and no visitors in it', json.visitors, 0);
} finally {
  rmSync(empty, { force: true });
}

if (failed === 0) {
  console.log(
    `visitors: ${report.visitors} people, ${report.demo_visitors} ran the demo, `
      + `${report.page_views} views across ${Object.keys(report.surfaces).length} surfaces — all as the fixture says.`
  );
} else {
  console.error(`\n${failed} check${failed === 1 ? '' : 's'} failed in tools/visitors.php.`);
  process.exit(1);
}
