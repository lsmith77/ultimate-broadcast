// @ts-check
/**
 * What the game is played to, and whether this point decides it.
 *
 * Tested directly rather than through a page (AGENTS.md): it is a pure function
 * of the pool, the score and the cap events, and a browser adds nothing to the
 * question except the difficulty of getting a fixture to 14-14.
 *
 * The cases worth the tests are the ones that put something FALSE on air, and
 * they all look like a working scoreboard:
 *
 *   - "universe point" while one side leads. It can be held off, and the board
 *     would be wrong for as long as the game continued.
 *   - "universe point" against the scheduled target after a cap has replaced it.
 *   - a decider announced at all on an installation that never recorded a game
 *     target, which is the state `winningscore` is in more often than not.
 *   - "universe point" left on a finished 14-14 draw, forever.
 */
const { test } = require('@playwright/test');
const { expect } = require('./helpers');

const Target = require('../../shared/target.js');

/** Game to 15, half unrecorded — the common case in a real installation. */
const TO_15 = { winningscore: 15 };
/** Game to 15 with the break recorded at 8. */
const TO_15_HALF_8 = { winningscore: 15, halftimescore: 8 };

const live = (home, away) => ({ homescore: home, visitorscore: away, status: 'ongoing' });
const cap = (type, info, time) => ({ type, info, time: time || 1000 });

test.describe('the game target', () => {
  test('is the pool\'s winning score', () => {
    expect(Target.game(TO_15, [])).toEqual({ value: 15, capped: false });
  });

  test('is NULL when the pool never recorded one', () => {
    // Absent is not zero, and an unrecorded target is not a target of nothing:
    // every caller has to be able to say nothing rather than guess a number
    // the tournament never agreed.
    for (const pool of [{}, { winningscore: 0 }, { winningscore: null }, null]) {
      expect(Target.game(pool, []), JSON.stringify(pool)).toBeNull();
    }
  });

  test('a called cap REPLACES it', () => {
    // UO's wording: "Time cap 6.45 - new point cap 4". A board still saying
    // "to 15" during a game now playing to 9 is the defect this prevents.
    expect(Target.game(TO_15, [cap('time_cap', 9)])).toEqual({ value: 9, capped: true });
    expect(Target.game(TO_15, [cap('half_cap', 8)])).toEqual({ value: 8, capped: true });
  });

  test('a time cap supersedes a halftime cap whenever it was called', () => {
    const events = [cap('time_cap', 9, 500), cap('half_cap', 8, 900)];
    expect(Target.game(TO_15, events).value).toBe(9);
  });

  test('a cap with no point cap recorded leaves no target at all', () => {
    // The scheduled target is no longer in force and what replaced it was not
    // written down. Saying 15 there would be worse than saying nothing.
    expect(Target.game(TO_15, [cap('time_cap', null)])).toBeNull();
  });
});

test.describe('the half target', () => {
  test('is halftimescore when the pool recorded one', () => {
    expect(Target.half(TO_15_HALF_8, [])).toEqual({ value: 8, derived: false });
  });

  test('is floor(target / 2) + 1 when it did not, and says so', () => {
    expect(Target.half(TO_15, [])).toEqual({ value: 8, derived: true });
    // NOT half rounded up: an even game total breaks one point later than that,
    // and the two rules disagree on exactly those targets.
    expect(Target.half({ winningscore: 14 }, [])).toEqual({ value: 8, derived: true });
    expect(Target.half({ winningscore: 13 }, [])).toEqual({ value: 7, derived: true });
  });

  test('is NULL once any cap has been called', () => {
    // Half is then taken at the end of the current point by the clock, so there
    // is no half score left to be one point short of.
    expect(Target.half(TO_15_HALF_8, [cap('half_cap', 8)])).toBeNull();
    expect(Target.half(TO_15_HALF_8, [cap('time_cap', 9)])).toBeNull();
  });

  test('is NULL when there is no game target to derive it from', () => {
    expect(Target.half({}, [])).toBeNull();
  });
});

test.describe('the decider', () => {
  test('universe point is both sides one short of the target', () => {
    expect(Target.decider(live(14, 14), TO_15, []))
      .toEqual({ kind: 'universe', target: 15, derived: false });
    expect(Target.label(Target.decider(live(14, 14), TO_15, []))).toBe('Universe point');
  });

  test('galaxy point is both sides one short of the half', () => {
    expect(Target.decider(live(7, 7), TO_15_HALF_8, []))
      .toEqual({ kind: 'galaxy', target: 8, derived: false });
    // Derived from game to 15, which is the state most installations are in.
    expect(Target.decider(live(7, 7), TO_15, []))
      .toEqual({ kind: 'galaxy', target: 8, derived: true });
    expect(Target.label(Target.decider(live(7, 7), TO_15, []))).toBe('Galaxy point');
  });

  test('game point for ONE side is not a decider', () => {
    // The whole point of the badge: the next point ends it. At 14-12 it does
    // not, and a board claiming otherwise is wrong until someone scores twice.
    expect(Target.decider(live(14, 12), TO_15, [])).toBeNull();
    expect(Target.decider(live(12, 14), TO_15, [])).toBeNull();
    expect(Target.decider(live(7, 5), TO_15, [])).toBeNull();
  });

  test('a level score anywhere else is not a decider', () => {
    for (const n of [0, 1, 6, 8, 13]) {
      expect(Target.decider(live(n, n), TO_15, []), `${n}-${n}`).toBeNull();
    }
  });

  test('nothing is claimed without a recorded game target', () => {
    // The instruction this exists to hold: no game-ending point value, no
    // badge — and that takes galaxy with it, because the half fallback is
    // derived from the game target.
    expect(Target.decider(live(14, 14), {}, [])).toBeNull();
    expect(Target.decider(live(7, 7), {}, [])).toBeNull();
    // Not even with a half target of its own: a half is a checkpoint in a game
    // whose end nobody recorded.
    expect(Target.decider(live(7, 7), { halftimescore: 8 }, [])).toBeNull();
  });

  test('it follows the cap, not the schedule', () => {
    const capped = [cap('time_cap', 9)];
    // 8-8 playing to 9 IS universe point; 14-14 under the same cap is not a
    // state that can be reached, but 14-14 against the scheduled 15 must not
    // be claimed once the cap has moved the target.
    expect(Target.decider(live(8, 8), TO_15, capped))
      .toEqual({ kind: 'universe', target: 9, derived: false });
    // And the half decider is gone with it: 7-7 under a cap is just 7-7.
    expect(Target.decider(live(7, 7), TO_15_HALF_8, capped)).toBeNull();
  });

  test('a finished game is never a decider', () => {
    // A capped game where draws are allowed can genuinely end level. Without
    // this the board would announce universe point on that result for as long
    // as it stayed on air.
    const done = { homescore: 14, visitorscore: 14, status: 'completed' };
    expect(Target.decider(done, TO_15, [])).toBeNull();
  });

  test('a target of 1 does not make 0-0 the decider', () => {
    // True, and useless: it would be on the board before the pull.
    expect(Target.decider(live(0, 0), { winningscore: 1 }, [])).toBeNull();
  });

  test('missing scores are not zero', () => {
    expect(Target.decider({ status: 'ongoing' }, TO_15, [])).toBeNull();
    expect(Target.decider(null, TO_15, [])).toBeNull();
  });
});
