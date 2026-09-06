// @ts-check
/**
 * Build a tree that genuinely has no UltiOrganizer in it.
 *
 * The first version of the standalone tests ran `php -S` against the working
 * copy and asserted "admin gating without Live!" — which proved nothing, because
 * `live/vendor/autoload.php` is two directories up from `shared/auth.php` in a
 * hosted checkout. `Auth::isHosted()` was returning **true** the whole time, so
 * the tests were exercising the hosted path under a standalone name.
 *
 * So the tree is copied somewhere with no host above it. That is the only way to
 * make the detection in `Auth::isHosted()` answer the way it would on a bare PHP
 * server, and the detection is the thing under test.
 *
 * `conf/` is synthesised rather than copied. The suite has to prove that
 * prepared notes are unreachable, and doing that with real notes would mean
 * copying notes about real people into a temp directory to prove they are
 * private. A fixture says the same thing and says it deterministically.
 */
const {
  mkdtempSync, cpSync, mkdirSync, writeFileSync, readFileSync, rmSync, existsSync,
} = require('node:fs');
const { tmpdir } = require('node:os');
const path = require('node:path');

const SOURCE = path.join(__dirname, '..');

/**
 * Everything a standalone installation actually ships.
 *
 * The page files are READ OUT OF `app.php`'s allow-list rather than listed
 * here. That list is what a standalone installation can serve, so it is also
 * exactly what this tree needs — and keeping a second copy meant a new endpoint
 * was routed, tested and missing from the tree, which fails as
 * "Failed opening required roster.php" rather than as anything to do with the
 * feature. It happened once; deriving it means it cannot happen again.
 */
function routedFiles() {
  const app = readFileSync(path.join(SOURCE, 'app.php'), 'utf8');
  const block = app.slice(app.indexOf('$views = ['), app.indexOf('];', app.indexOf('$views = [')));
  const files = [...block.matchAll(/=>\s*'([^']+\.php)'/g)].map((m) => m[1]);
  if (files.length < 5) {
    throw new Error('could not read the view allow-list out of app.php');
  }

  return files;
}

const RUNTIME = ['app.php', 'shared', 'images', '.htaccess', ...routedFiles()];

/**
 * A capture, recorded from the dev instance by `tests/capture.mjs`.
 *
 * Copied in when one exists so the suite can prove the thing milestone 2 is
 * for: a page rendering real payloads with no UltiOrganizer, no Live!, no
 * database and no network. Without one those tests skip rather than fail —
 * a capture is a recording of somebody's instance, not something the repo
 * carries by default.
 */
const CAPTURE = path.join(SOURCE, 'fixtures', 'payloads', 'dev');

function build() {
  // The tree sits one level down, so the directory ABOVE the installation is
  // ours to control — and the first thing put in it is a decoy.
  const base = mkdtempSync(path.join(tmpdir(), 'overlays-standalone-'));
  const root = path.join(base, 'site');
  mkdirSync(root);

  // A stranger's `vendor/autoload.php` beside the installation.
  //
  // Not hypothetical: the first deployment to shared hosting found one sitting
  // in the document root's parent, left by something else entirely. Hosted mode
  // detects Live! by looking exactly there, so the old test — "is there a
  // vendor/autoload.php" — matched it, and would have `require`d a stranger's
  // autoloader into this process on every auth check. This file fails the run
  // if that ever comes back: it throws on load, so anything that requires it
  // takes the request down loudly rather than quietly running.
  mkdirSync(path.join(base, 'vendor'), { recursive: true });
  writeFileSync(
    path.join(base, 'vendor', 'autoload.php'),
    "<?php\n\nthrow new \\RuntimeException('a foreign autoloader was executed');\n",
  );

  for (const entry of RUNTIME) {
    const from = path.join(SOURCE, entry);
    try {
      cpSync(from, path.join(root, entry), { recursive: true });
    } catch (e) {
      if (e.code !== 'ENOENT') throw e;
    }
  }

  // A conf/ holding one of each kind: the two files the stage is allowed to
  // poll, and three that must never be served.
  mkdirSync(path.join(root, 'conf', 'notes'), { recursive: true });
  mkdirSync(path.join(root, 'conf', 'lines'), { recursive: true });
  const w = (p, body) => writeFileSync(path.join(root, 'conf', p), body);
  // A stage with something ON it, which is what a stage looks like in use.
  // It was `cards: []` — an empty stage, which is a real state but not the
  // interesting one: with nothing mounted, every card is trivially correct and
  // a card pointed at a URL that 404s looks exactly like a card that is off.
  // That is how the scoreboard card's hosted-only URL survived here.
  w('show.json', JSON.stringify({
    rev: 1,
    game: 702,
    logo: '',
    cards: [{ id: 'scoreboard', slot: 'lower-left', visible: true, params: {} }],
  }));
  w('possession-702.json', JSON.stringify({ rev: 1 }));
  w('team-colors.json', JSON.stringify({ 304: { primary: '#123456' } }));
  w('show.json.bak', JSON.stringify({ rev: 0 }));
  w(path.join('notes', 'DDDDD.json'), JSON.stringify({
    players: { 1: { text: 'MUST NOT BE SERVED', by: '', at: 0 } },
  }));
  w(path.join('notes', 'show.json'), JSON.stringify({ decoy: true }));
  w(path.join('lines', 'DDDDD.json'), JSON.stringify({ rev: 1 }));

  // An administrator password, so the login path can actually be exercised.
  // Hosted, those tests skip without ADMIN_PASS and mostly do not run; here the
  // password is ours to set, which makes this the only place `Auth::attempt()`
  // — the one piece of security code this project owns rather than borrows —
  // is tested at all.
  //
  // A real bcrypt hash rather than a stub: the point is that password_verify
  // is being called correctly, and a fake hash would prove the opposite.
  const ADMIN_PASSWORD = 'standalone-test-password';
  const ADMIN_HASH = '$2y$12$qVhSYwhOqZUrw9jEmZK4Cut4.NQmLoh5aCPfTcRqpUGWUC18RX7Ym';

  // The capture, plus the config that makes the pages read it. Both or
  // neither: a config naming a capture that is not there would make every
  // page fail in a way that looks like a routing bug.
  //
  // `demo` is deliberately NOT set here. The suite exercises the writes that
  // demo mode closes — notes, lines — so turning it on for every run would
  // make those tests pass for the wrong reason. The demo tests write the flag
  // themselves and take it away again.
  if (existsSync(CAPTURE)) {
    mkdirSync(path.join(root, 'fixtures', 'payloads'), { recursive: true });
    cpSync(CAPTURE, path.join(root, 'fixtures', 'payloads', 'dev'), { recursive: true });
    writeFileSync(
      path.join(root, 'conf', 'local-config.php'),
      `<?php\n\nreturn [\n  'capture' => 'fixtures/payloads/dev',\n`
      + `  'admin_hash' => '${ADMIN_HASH}',\n];\n`,
    );
  } else {
    writeFileSync(
      path.join(root, 'conf', 'local-config.php'),
      `<?php\n\nreturn ['admin_hash' => '${ADMIN_HASH}'];\n`,
    );
  }

  return root;
}

/** Whether this run can exercise the capture-backed pages at all. */
function hasCapture() {
  return existsSync(CAPTURE);
}

module.exports = {
  build,
  hasCapture,
  ADMIN_PASSWORD: 'standalone-test-password',
  RUNTIME,
  // The installation is `<base>/site`, and the decoy vendor/ is its sibling, so
  // what has to go is the directory holding both.
  cleanup: (root) => rmSync(path.dirname(root), { recursive: true, force: true }),
};
