/**
 * The spotter surface's arithmetic, driven headlessly.
 *
 * `spotter.php` carries its script inline, with no build step and no module
 * boundary — which is the shape the rest of this project uses and the wrong
 * shape for testing. So the page's script is
 * pulled out and run against a stub DOM. It is not a browser and does not
 * pretend to be: nothing here checks layout, only the numbers.
 *
 * WHY THIS FILE EXISTS
 *
 * Every assertion below is a bug that was actually shipped into the file and
 * found by running it this way: a goal button that stored a player object
 * where every other path stored a string, a point counter incremented twice,
 * a mode switch that wiped the restored session on every reload, and a live
 * clock that counted stoppage time as play. None of them threw. They just
 * produced numbers, and numbers get believed.
 *
 *   node tests/spotter.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const PAGE = path.join(ROOT, 'spotter.php');

let failed = 0;
const fail = (msg) => { console.error(`  ${msg}`); failed += 1; };
const is = (what, got, want) => {
  if (JSON.stringify(got) !== JSON.stringify(want)) {
    fail(`${what}: got ${JSON.stringify(got)}, expected ${JSON.stringify(want)}`);
  }
};

const html = fs.readFileSync(PAGE, 'utf8');
const script = html.match(/<script>\n\(function \(\) \{([\s\S]*?)\}\(\)\);\n<\/script>/);
if (!script) {
  console.error('  the page no longer has one wrapped inline script — adjust this harness');
  process.exit(1);
}

/** Enough DOM to let the page load. Deliberately dumb. */
function stubDom(store, urlMode) {
  const nodes = {};
  const mk = (tag) => ({
    tagName: (tag || 'div').toUpperCase(),
    className: '', textContent: '', innerHTML: '', title: '', value: '',
    dataset: {}, style: {}, children: [], checked: false, files: null,
    append(...k) { this.children.push(...k); },
    replaceChildren(...k) { this.children = k; },
    querySelector: () => mk(), querySelectorAll: () => [],
    addEventListener(t, f) { (this.on ||= {})[t] = f; },
    click() { this.on?.click?.(); },
    classList: { toggle() {}, contains: () => false, add() {}, remove() {} },
  });

  global.document = {
    getElementById: (id) => (nodes[id] ||= mk()),
    createElement: mk,
    addEventListener() {},
    querySelector: () => mk(),
    querySelectorAll: () => [],
    body: Object.assign(mk('body'), { focus() {} }),
    activeElement: { tagName: 'BODY' },
    visibilityState: 'visible',
  };
  global.window = {
    focus() {},
    confirm: () => true,
    location: { href: 'http://local/watch.html' },
    localStorage: {
      getItem: (k) => (k in store ? store[k] : null),
      setItem: (k, v) => { store[k] = v; },
      removeItem: (k) => { delete store[k]; },
    },
  };
  Object.defineProperty(global, 'navigator', {
    value: { mediaDevices: null }, configurable: true,
  });
  global.URL = class { constructor() { this.searchParams = { get: () => urlMode }; } };
  global.URL.createObjectURL = () => '';
  global.URL.revokeObjectURL = () => {};
  global.Blob = function Blob() {};
  global.FileReader = function FileReader() {};
  global.setInterval = () => {};
  global.YT = { Player: function Player() {} };
  global.alert = () => {};
  global.Audio = function Audio() { return { play() {}, pause() {} }; };

  // eslint-disable-next-line no-eval
  eval(`(function(){${script[1]}
    global.__spotter = {
      startPoint, push, setPlay, flag, lastEvent, toggleAfk, setMode, perPlayer,
      coverageNote, playIntervals, liveIn, mmss, SAY, matchWord, setLine, aliasesOf,
      events: () => events, mode: () => MODE, inPlay: () => inPlay,
      resumeBanner: () => document.getElementById('resume').textContent,
      clock: (f) => { at = f; },
    };
  }())`);
  return global.__spotter;
}

const SQUAD = [
  ['Ada', 'Weber', 7], ['Hana', 'Lehner', 11], ['Nico', 'Lang', 6], ['Kai', 'Reiter', 23],
  ['Sky', 'Thaler', 2], ['Jo', 'Moser', 9], ['Val', 'Wagner', 4],
].map(([firstname, lastname, num]) => ({ firstname, lastname, num }));

// ---- the play clock --------------------------------------------------------
{
  const s = stubDom({}, null);
  let now = 0;
  s.clock(() => now);
  const at = (t, f) => { now = t; f(); };

  at(0, () => s.startPoint(SQUAD));
  at(10, () => s.push('throw', { throwType: 'pull', to: 'Nico Lang', from: null }));
  at(15, () => s.push('throw', { to: 'Ada Weber', from: 'Nico Lang' }));
  at(20, () => s.push('call', { call: 'foul', by: 'Ada Weber' }));
  at(50, () => s.push('call', { call: 'check' }));
  at(55, () => s.push('throw', { to: 'Hana Lehner', from: 'Ada Weber' }));
  at(60, () => s.push('goal', { by: 'Hana Lehner' }));
  now = 70;

  // A pull starts play, a foul stops it, a check restarts it, a goal ends it.
  // Thirty seconds of foul discussion are not thirty seconds of Ultimate.
  is('live intervals', s.playIntervals(), [[10, 20], [50, 60]]);
  is('live seconds', s.liveIn([0, 70]).live, 20);

  // The repair: a throw cannot happen while the disc is dead, so seeing one
  // closes a stoppage the spotter forgot to close. Without it a single missed
  // "check" swallows the rest of the game.
  at(80, () => s.push('call', { call: 'timeout' }));
  at(120, () => s.push('throw', { to: 'Kai Reiter', from: 'Ada Weber' }));
  now = 130;
  is('a throw reopens a forgotten stoppage', s.inPlay(), true);
  is('live resumes at the throw, not the timeout', s.playIntervals().slice(-1), [[120, 130]]);
}

// ---- AFK is not a stoppage -------------------------------------------------
{
  const s = stubDom({}, null);
  let now = 0;
  s.clock(() => now);

  now = 0; s.startPoint(SQUAD);
  now = 10; s.push('throw', { throwType: 'pull', to: 'Nico Lang', from: null });
  now = 20; s.toggleAfk();
  now = 40; s.toggleAfk();
  now = 60;

  /*
   * The disc was live for the whole fifty seconds. For twenty of them nobody
   * was watching. Those are different facts and the tool reports both rather
   * than subtracting one from the other: a stoppage means nothing happened, an
   * AFK means nobody knows.
   */
  const seen = s.liveIn([0, 60]);
  is('AFK does not stop the play clock', seen.live, 50);
  is('AFK is reported as unwatched live time', seen.blind, 20);
  is('coverage says so', s.coverageNote().some((n) => n.includes('not being watched')), true);
}

// ---- per-player time follows the line, not the roster ----------------------
{
  const s = stubDom({}, null);
  let now = 0;
  s.clock(() => now);

  now = 0; s.startPoint(SQUAD);
  now = 10; s.push('throw', { throwType: 'pull', to: 'Nico Lang', from: null });
  now = 30; s.push('goal', { by: 'Nico Lang' });

  const second = SQUAD.slice(0, 6).concat([{ firstname: 'Ola', lastname: 'Frey', num: 3 }]);
  now = 60; s.startPoint(second);
  now = 70; s.push('throw', { throwType: 'pull', to: 'Kai Reiter', from: null });
  now = 90; s.push('turnover', { how: 'throwaway', by: 'Kai Reiter' });
  now = 100;

  const by = {};
  s.perPlayer().forEach((w) => { by[w.name] = w; });

  // Wagner played the first point only, Frey the second only. Twenty live
  // seconds each, and not one of the other's.
  is('a player subbed off keeps only their own points', by['Val Wagner'].secs, 20);
  is('a player subbed on gets only theirs', by['Ola Frey'].secs, 30);
  is('a player on both gets both', by['Ada Weber'].secs, 50);
  is('points played', [by['Val Wagner'].points, by['Ada Weber'].points], [1, 2]);

  // Absent is not zero: a player who touched nothing is still on the table,
  // because "no data" and "no involvement" must not look the same.
  is('a player who never touched it still appears', by['Sky Thaler'].caught, 0);

  is('the thrower is charged with the throwaway', by['Kai Reiter'].away, 1);
  is('the scorer is credited', by['Nico Lang'].goals, 1);
}

// ---- the flag --------------------------------------------------------------
{
  const s = stubDom({}, null);
  s.clock(() => 10);
  s.startPoint(SQUAD);
  s.push('throw', { to: 'Ada Weber', from: 'Nico Lang' });

  const e = s.lastEvent();
  is('the flag targets the last real event, not the point marker', e.type, 'throw');

  s.flag(e);
  // Flagging makes it unsettled, which is also what pins its audio in the
  // rolling buffer. That is the whole point: the spotter knows within a
  // second that the recogniser was confidently wrong.
  is('flagging marks it unsettled', [e.flagged, e.certain], [true, false]);

  s.flag(e);
  is('flagging twice undoes a mistap', [e.flagged, e.certain], [undefined, undefined]);
}

// ---- the vocabulary has to be sayable --------------------------------------
{
  const s = stubDom({}, null);
  s.clock(() => 0);
  s.startPoint(SQUAD);

  /*
   * EVERY WORD IN THE GRAMMAR MUST RESOLVE TO ITSELF.
   *
   * A closed vocabulary is only closed if each word in it means one thing.
   * The phonetic key is deliberately coarse, and Ultimate supplies pairs it
   * cannot separate: "break" and "brick" reduce to the same key, so do
   * "pick" and "pause", and "contested" scored 0.82 against "uncontested" —
   * which are opposites that decide who gets the disc. Every one of those
   * came back AMBIGUOUS, meaning a spotter could not say a core throw at all.
   *
   * This is the check that found them, and the one that immediately caught a
   * "stall out" added to the calls when `stall` was already an outcome.
   */
  const unusable = [];
  let aliases = 0;
  for (const [kind, group] of Object.entries(s.SAY)) {
    for (const [value, words] of Object.entries(group)) {
      for (const word of words) {
        aliases += 1;
        const m = s.matchWord(word);
        if (!m) { unusable.push(`"${word}" (${kind}/${value}) matches nothing`); }
        else if (m.ambiguous) { unusable.push(`"${word}" (${kind}/${value}) is ambiguous`); }
        else if (m.hit.value !== value) {
          unusable.push(`"${word}" (${kind}/${value}) resolves to ${m.hit.kind}/${m.hit.value}`);
        }
      }
    }
  }
  is('every grammar word resolves to itself', unusable, []);
  if (aliases < 100) { fail(`only ${aliases} aliases found — is the vocabulary wired up?`); }

  // And the names, which are the other half of the vocabulary. Distinct
  // surnames must resolve; the two-Webers case is allowed to be ambiguous,
  // because an ambiguity detected is the documented right answer.
  const badNames = [];
  for (const p of SQUAD) {
    const m = s.matchWord(p.lastname.toLowerCase());
    if (!m) { badNames.push(`${p.lastname} matches nothing`); }
    else if (!m.ambiguous && m.hit.kind !== 'player') {
      badNames.push(`${p.lastname} resolves to ${m.hit.kind}/${m.hit.value}`);
    }
  }
  is('every surname on the line resolves to a player', badNames, []);
}

// ---- mode, restore, and the clock that must not be mixed -------------------
{
  const store = {};

  let s = stubDom(store, null);
  s.setMode('training');
  s.clock(() => 5);
  s.startPoint(SQUAD);
  s.push('throw', { to: 'Ada Weber', from: null });
  const kept = s.events().length;

  // A reload adopts the mode the session was in. Treating that as a switch
  // wiped the session every single time, silently.
  s = stubDom(store, null);
  is('a reload keeps the mode', s.mode(), 'training');
  is('a reload keeps the events', s.events().length, kept);
  is('and says so', s.resumeBanner().startsWith('resumed'), true);

  // Asking for the other mode IS a switch: video-time stamps cannot be read
  // under a wall clock, so they go rather than being quietly reinterpreted.
  s = stubDom(store, 'live');
  is('an explicit mode switch clears', [s.mode(), s.events().length], ['live', 0]);
}

if (failed) {
  console.error(`\n${failed} spotter check(s) failed.`);
  process.exit(1);
}
console.log('spotter: play clock, AFK, per-player time, the flag and mode restore all hold.');
