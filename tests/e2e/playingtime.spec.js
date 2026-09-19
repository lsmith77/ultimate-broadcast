// @ts-check
/**
 * Playing time, from the only place that knows it.
 *
 * Tested directly rather than through a page (AGENTS.md): it is a pure function
 * of one team's recorded lines.
 *
 * The cases that matter are about the gap between what a desk recorded and what
 * happened. A commentary desk confirms the points it kept up with, so every
 * number here is over a partial game — and the defect this is written against
 * is a share presented as though the denominator were the whole match. "Nine of
 * eleven recorded" is true; "82% of points" over eleven recorded points of a
 * fourteen-point game is a claim nobody can support.
 */
const { test } = require('@playwright/test');
const { expect } = require('./helpers');

const PlayingTime = require('../../shared/playingtime.js');

/** Four confirmed points; 7 is on for all of them, 12 comes on late. */
const POINTS = {
  '0-0': [3, 7, 9],
  '1-0': [3, 7, 9],
  '1-1': [7, 9, 12],
  '2-1': [7, 12, 15],
};

test.describe('one player', () => {
  test('counts the points they were on, and says how many were recorded', () => {
    expect(PlayingTime.forPlayer(POINTS, 7)).toEqual({ on: 4, of: 4, run: 4 });
    expect(PlayingTime.forPlayer(POINTS, 3)).toEqual({ on: 2, of: 4, run: 0 });
    expect(PlayingTime.forPlayer(POINTS, 12)).toEqual({ on: 2, of: 4, run: 2 });
  });

  test('a player who has not been on is zero of the recorded points', () => {
    // Zero is right here, and different from null below: these points WERE
    // recorded and this player was not in them.
    expect(PlayingTime.forPlayer(POINTS, 99)).toEqual({ on: 0, of: 4, run: 0 });
  });

  test('nothing recorded is null, not zero', () => {
    // No desk kept up, so nobody has been shown to be off. A row of zeroes
    // would read as a squad that never played.
    expect(PlayingTime.forPlayer({}, 7)).toBeNull();
    expect(PlayingTime.forPlayer(null, 7)).toBeNull();
  });
});

test.describe('the order points are read in', () => {
  test('is the score, not the order they were written', () => {
    // A room has two desks writing into one file, so insertion order is
    // whatever the last writer did. The run has to be the run of PLAY.
    const shuffled = {
      '2-1': [7, 12, 15],
      '0-0': [3, 7, 9],
      '1-1': [7, 9, 12],
      '1-0': [3, 7, 9],
    };
    expect(PlayingTime.recorded(shuffled).map((p) => p.score))
      .toEqual(['0-0', '1-0', '1-1', '2-1']);
    // 3 played the first two points and nothing since: a run of zero, which a
    // reader of insertion order would have called two.
    expect(PlayingTime.forPlayer(shuffled, 3).run).toBe(0);
  });

  test('a junk key is not a point', () => {
    expect(PlayingTime.recorded({ 'not-a-score': [1, 2], '0-0': [3] })
      .map((p) => p.score)).toEqual(['0-0']);
  });

  test('an empty line is not a point nobody played', () => {
    expect(PlayingTime.recorded({ '0-0': [], '1-0': [3] })
      .map((p) => p.score)).toEqual(['1-0']);
  });
});

test.describe('coverage, which every share depends on', () => {
  test('says how much of the game was recorded', () => {
    expect(PlayingTime.coverage(POINTS, 14))
      .toEqual({ recorded: 4, played: 14, complete: false });
  });

  test('is complete only when the desk kept up with every point', () => {
    expect(PlayingTime.coverage(POINTS, 4).complete).toBe(true);
    expect(PlayingTime.coverage(POINTS, 5).complete).toBe(false);
  });

  test('nothing recorded is never complete, whatever was played', () => {
    expect(PlayingTime.coverage({}, 0).complete).toBe(false);
  });

  test('missed points in the MIDDLE still count against coverage', () => {
    // Not a prefix: a desk can confirm points 1, 2 and 6 and miss three in
    // between. Comparing counts rather than assuming a run is what makes that
    // read as 3 of 6 instead of "up to date".
    const gappy = { '0-0': [7], '1-0': [7], '3-2': [7] };
    expect(PlayingTime.coverage(gappy, 6))
      .toEqual({ recorded: 3, played: 6, complete: false });
  });
});

test.describe('putting it into words', () => {
  test('the denominator always travels with the number', () => {
    expect(PlayingTime.label(POINTS, 7)).toBe('4 of 4 points');
    expect(PlayingTime.label(POINTS, 3)).toBe('2 of 4 points');
  });

  test('a player who has not been on is not given a line', () => {
    expect(PlayingTime.label(POINTS, 99)).toBeNull();
  });

  test('too few recorded points says nothing at all', () => {
    // Three points is not a game, and a share of it is not a fact about one.
    expect(PlayingTime.label(POINTS, 7, 6)).toBeNull();
    expect(PlayingTime.label(POINTS, 7, 4)).toBe('4 of 4 points');
  });
});

test.describe('units, and crossing between them', () => {
  /**
   * Six points: this team received 1, 3 and 5 (their O points) and pulled on
   * 2, 4 and 6. Player 7 is on every O point and nothing else; player 20 is on
   * every D point. On the last point, 7 crosses over.
   */
  const POINTS_6 = {
    '0-0': [7, 8, 9],
    '0-1': [20, 21, 22],
    '1-1': [7, 8, 9],
    '1-2': [20, 21, 22],
    '2-2': [7, 8, 9],
    '2-3': [20, 21, 7],
  };
  const RECEIVED = {
    '0-0': true, '0-1': false, '1-1': true, '1-2': false, '2-2': true, '2-3': false,
  };

  test('a player who has only ever been on O points is an O-line player', () => {
    const u = PlayingTime.units(POINTS_6, RECEIVED);
    expect(u[8]).toEqual({ o: 3, d: 0, unit: 'o' });
    expect(u[21]).toEqual({ o: 0, d: 3, unit: 'd' });
  });

  test('a player with points in both units has no unit', () => {
    // 7 has crossed, so nothing about them is a clean O-line label any more.
    // Refusing here is what keeps "crossover" meaningful for the squads that
    // actually split, and silent for the many that do not.
    expect(PlayingTime.units(POINTS_6, RECEIVED)[7].unit).toBeNull();
  });

  test('too few points is no unit yet', () => {
    const early = { '0-0': [7], '0-1': [20] };
    const u = PlayingTime.units(early, { '0-0': true, '0-1': false });
    expect(u[7].unit).toBeNull();
    expect(u[20].unit).toBeNull();
  });

  test('a point whose side is unknown teaches nothing', () => {
    // No entry in `received` for the last point: it cannot be counted as
    // either unit without inventing the thing being measured.
    const partial = Object.assign({}, RECEIVED);
    delete partial['2-3'];
    const u = PlayingTime.units(POINTS_6, partial);
    expect(u[7]).toEqual({ o: 3, d: 0, unit: 'o' });
  });

  test('the crossing itself, with the point it happened in', () => {
    const crossed = PlayingTime.crossovers(POINTS_6, RECEIVED);
    expect(crossed).toEqual([{ id: 7, from: 'o', at: '2-3' }]);
  });

  test('a squad that rotates everybody produces no crossings at all', () => {
    // The case this must stay quiet for: nobody has a unit, so nobody can
    // cross out of one.
    const rotating = {
      '0-0': [1, 2, 3], '0-1': [1, 2, 3], '1-1': [4, 5, 6], '1-2': [1, 4, 5],
    };
    const received = { '0-0': true, '0-1': false, '1-1': true, '1-2': false };
    expect(PlayingTime.crossovers(rotating, received)).toEqual([]);
  });

  test('a player is reported crossing once, not every point after', () => {
    // It is a fact about the crossing. Repeating it for the rest of the game
    // would turn one event into a standing label.
    const stayed = Object.assign({}, POINTS_6, { '3-3': [20, 21, 7] });
    const received = Object.assign({}, RECEIVED, { '3-3': false });
    expect(PlayingTime.crossovers(stayed, received)).toEqual([
      { id: 7, from: 'o', at: '2-3' },
    ]);
  });
});
