/**
 * `deploy.sh --version` sends a version, and leaves nothing behind.
 *
 * Deploying a tag used to mean checking it out by hand, which leaves a detached
 * HEAD that the NEXT deploy silently ships again. The script now builds a
 * temporary git worktree instead — and a temporary worktree is exactly the kind
 * of thing that survives a failure and is then deployed months later, or that
 * gets written into the directory it is meant to feed.
 *
 * So this asserts the two properties that keep that safe: the version resolved
 * is the version asked for, and afterwards this checkout is as it was.
 *
 * WHAT IT DOES NOT CHECK
 *
 * That a deploy works. That needs somebody's server, and a check that needed
 * one would not run — `--show` stops after deciding what to send, which is the
 * part that can be tested anywhere, including in CI with no deploy.env.
 *
 *   node tests/deploy.mjs
 */
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const SCRIPT = path.join(ROOT, 'deploy.sh');

let failed = 0;
const fail = (msg) => { console.error(`  ${msg}`); failed += 1; };

/** Run the script, returning its output and status rather than throwing. */
function deploy(args) {
  try {
    const opts = { cwd: ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] };

    return { code: 0, out: execFileSync(SCRIPT, args, opts) };
  } catch (e) {
    return { code: e.status ?? 1, out: `${e.stdout || ''}${e.stderr || ''}` };
  }
}

const git = (...args) => execFileSync('git', args, { cwd: ROOT, encoding: 'utf8' });

const worktreesBefore = git('worktree', 'list').trim().split('\n').length;
const versionBefore = existsSync(path.join(ROOT, 'version.json'))
  ? readFileSync(path.join(ROOT, 'version.json'), 'utf8')
  : null;

/*
 * HEAD rather than a tag: a shallow CI checkout may carry no tags at all, and
 * a check that passes only on a full clone is a check that reports on the
 * clone rather than on the code.
 */
const head = git('rev-parse', 'HEAD').trim();
const shown = deploy(['--version', 'HEAD', '--show']);

if (shown.code !== 0) {
  fail(`--version HEAD --show exited ${shown.code}:\n${shown.out}`);
} else {
  const json = shown.out.slice(shown.out.indexOf('{'));
  let state = {};
  try {
    state = JSON.parse(json);
  } catch {
    fail(`--show printed no readable version.json:\n${shown.out}`);
  }

  if (state.commit !== head) {
    fail(`--show named ${state.commit}, and HEAD is ${head}`);
  }
  // The worktree is built from a commit, so it cannot carry edits — and a
  // version that claimed otherwise would be the one lie version.json exists
  // to prevent.
  if (state.dirty !== false) {
    fail(`a version built from a commit reported dirty: ${state.dirty}`);
  }
  if (!('release' in state)) {
    fail('--show printed no release field');
  }
}

/*
 * The worktree's own mode, which took the live site down.
 *
 * `mktemp -d` makes a 0700 directory and `rsync --archive` copies the SOURCE
 * directory's mode to the destination — so a deploy from a worktree set the
 * document root to 0700, the web server could no longer traverse it, and every
 * URL answered 404 with every file present and correct. Deploying from the
 * working copy never showed it, because a checkout is 0755.
 */
const shown2 = deploy(['--version', 'HEAD', '--show']);
const mode = /^==> source:.*\(mode (\d+)\)/m.exec(shown2.out);
if (!mode) {
  fail('--show no longer reports the source directory mode, so this cannot be checked');
} else if (mode[1] !== '755') {
  fail(`the worktree is mode ${mode[1]}, and rsync copies that onto the document `
    + 'root — it must be 755 or every URL on the site 404s');
}

/*
 * And what the worktree carries that a checkout does not. A worktree's `.git`
 * is a FILE, so an exclude written as `/.git/` — matching directories only —
 * let it through, and a deploy published a file naming a path on the deploying
 * machine.
 */
const excludes = readFileSync(path.join(ROOT, 'deploy.sh'), 'utf8');
if (!/--exclude='\/\.git'/.test(excludes)) {
  fail("deploy.sh must exclude '/.git' without a trailing slash: a worktree's .git is a file");
}

const bogus = deploy(['--version', 'no-such-version-exists', '--show']);
if (bogus.code === 0) {
  fail('a version that does not exist was accepted');
} else if (!/no such version/i.test(bogus.out)) {
  fail(`an unknown version failed without saying why:\n${bogus.out}`);
}

const worktreesAfter = git('worktree', 'list').trim().split('\n').length;
if (worktreesAfter !== worktreesBefore) {
  fail(`${worktreesAfter - worktreesBefore} worktree(s) left behind — see `
    + `\`git worktree list\``);
}

const versionAfter = existsSync(path.join(ROOT, 'version.json'))
  ? readFileSync(path.join(ROOT, 'version.json'), 'utf8')
  : null;
if (versionAfter !== versionBefore) {
  fail('looking at a version rewrote this checkout\'s own version.json, which '
    + 'is the record of the last real deploy');
}

if (failed) {
  console.error(`\n${failed} problem${failed === 1 ? '' : 's'} with deploy.sh --version.`);
  process.exit(1);
}

console.log('deploy.sh --version resolves a version, reports it, and leaves no worktree behind.');
