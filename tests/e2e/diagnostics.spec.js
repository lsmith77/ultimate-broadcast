// @ts-check
/**
 * The policy that decides whether a broadcast surface may speak, and whether
 * what it is already showing is still true.
 *
 * Tested here rather than through a page because the expensive cases cannot be
 * staged in a browser: an API that goes away mid-broadcast, a board that has
 * been running for four minutes on a payload nothing has confirmed since, a
 * field-following board that changed game and could not load the new one.
 * `standalone.spec.js` keeps the wiring honest; this is the rule itself.
 */
const { test, expect } = require('@playwright/test');
const Diagnostics = require('../../shared/diagnostics.js');

const SECOND = 1000;
const NOW = 1_700_000_000_000;

test.describe('how long a board believes itself', () => {
  test('four polls, and never less than a minute', () => {
    // 30s is the cache life of a live game, which is the server saying when it
    // will have news.
    expect(Diagnostics.windowFor(30)).toBe(120);
    // A fast poll must not make a board twitchy: one slow field should not
    // blank a scoreboard between two goals.
    expect(Diagnostics.windowFor(1)).toBe(60);
    expect(Diagnostics.windowFor(5)).toBe(60);
    // A long cache life is believed.
    expect(Diagnostics.windowFor(300)).toBe(1200);
  });

  test('nonsense falls back to the live-game default', () => {
    // A missing interval is the common case, not an error: the client follows
    // `meta.expires_timestamp` and may not have a payload yet.
    for (const bad of [undefined, null, 0, -5, NaN, 'soon']) {
      expect(Diagnostics.windowFor(bad), String(bad)).toBe(120);
    }
  });
});

test.describe('whether what is on screen is still true', () => {
  test('a recent payload is fresh, an old one is not', () => {
    expect(Diagnostics.stale(NOW, { game: NOW - 30 * SECOND }, 120)).toBe(false);
    expect(Diagnostics.stale(NOW, { game: NOW - 119 * SECOND }, 120)).toBe(false);
    expect(Diagnostics.stale(NOW, { game: NOW - 121 * SECOND }, 120)).toBe(true);
  });

  test('a board that has never had anything confirmed is stale', () => {
    // Not a special case for startup — it is what withdraws a field-following
    // board that changed game and could not load the new one, which would
    // otherwise sit on the previous game's graphic.
    expect(Diagnostics.stale(NOW, {}, 120)).toBe(true);
    expect(Diagnostics.stale(NOW, { game: 0 }, 120)).toBe(true);
  });

  test('a live match-control score keeps the board up when upstream is gone', () => {
    // The score is arriving from this project's own store; the API that has
    // gone away carries the team names, and those do not change during a game.
    const seen = { game: NOW - 10 * 60 * SECOND, score: NOW - 2 * SECOND, scoreActive: true };
    expect(Diagnostics.stale(NOW, seen, 120)).toBe(false);
  });

  test('but only while that score is actually arriving', () => {
    const dead = { game: NOW - 10 * 60 * SECOND, score: NOW - 10 * 60 * SECOND, scoreActive: true };
    expect(Diagnostics.stale(NOW, dead, 120)).toBe(true);
  });

  test('a score channel nobody switched on does not vouch for anything', () => {
    // `conf/score-<game>.json` answers for every game whether or not an
    // operator pointed the board at it. Counting an unswitched store would
    // keep a stale upstream board on air for ever.
    const off = { game: NOW - 10 * 60 * SECOND, score: NOW, scoreActive: false };
    expect(Diagnostics.stale(NOW, off, 120)).toBe(true);
  });
});

test.describe('whether a surface may say anything', () => {
  test('off is the default, and the only default', () => {
    expect(Diagnostics.enabled({ now: NOW })).toBe(false);
    expect(Diagnostics.enabled({ now: NOW, until: 0 })).toBe(false);
    expect(Diagnostics.enabled({})).toBe(false);
  });

  test('an operator turns it on, and it turns itself off', () => {
    const until = NOW / 1000 + 300;
    expect(Diagnostics.enabled({ now: NOW, until })).toBe(true);
    // "Turn it on, fix it, forget to turn it off" is the failure; the
    // consequence is text on air during the next fault.
    expect(Diagnostics.enabled({ now: NOW + 301 * SECOND, until })).toBe(false);
  });

  test('?debug=1 needs nothing served, for the laptop case', () => {
    expect(Diagnostics.enabled({ now: NOW, debug: true })).toBe(true);
    // And it does not expire: it lasts exactly as long as that tab.
    expect(Diagnostics.enabled({ now: NOW + 86400 * SECOND, debug: true })).toBe(true);
  });

  test('a working board says nothing, even with diagnostics on', () => {
    // This is what makes one switch safe for a whole broadcast. Without it,
    // turning diagnostics on to inspect one dead board would put a connection
    // chip on every healthy board on every field.
    const on = { now: NOW, until: NOW / 1000 + 300 };
    expect(Diagnostics.show({ ...on, failing: false })).toBe(false);
    expect(Diagnostics.show({ ...on, failing: true })).toBe(true);
    expect(Diagnostics.show({ now: NOW, debug: true, failing: false })).toBe(false);
  });

  test('and a failing board still says nothing unless asked', () => {
    expect(Diagnostics.show({ now: NOW, failing: true })).toBe(false);
  });
});
