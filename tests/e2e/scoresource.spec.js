// @ts-check
/**
 * Swapping a locally kept score into the payload the overlays render.
 *
 * Tested directly (AGENTS.md): this decides what a scoreboard shows on air, it
 * is a pure function of two documents, and a browser adds nothing to the
 * question but a game to look at.
 *
 * The case worth most of these tests is the one where the local score knows
 * LESS than upstream — no scorer, no assist — because the wrong way to handle
 * that is to fill the gap with zeroes, and zeroes are indistinguishable from a
 * player who has genuinely not scored.
 */
const { test } = require('@playwright/test');
const { expect } = require('./helpers');

const ScoreSource = require('../../shared/score-source.js');

const UPSTREAM = {
  game_result: {
    homescore: 8, visitorscore: 6, isongoing: 1,
    timer_start: 1000, timer_paused_duration: 30, timer_pause_start: 0,
  },
  goals: [
    { num: 1, ishomegoal: 1, time: 60, scorer: 91, assist: 92 },
    { num: 2, ishomegoal: 0, time: 200, scorer: 81, assist: 82 },
  ],
  teams: { hometeam: { team_id: 300 } },
  seasoninfo: { type: 'outdoor' },
};

const LOCAL = {
  enabled: true,
  goals: [
    { num: 1, home: true, at: 50 },
    { num: 2, home: true, at: 120 },
    { num: 3, home: false, at: 240 },
  ],
  timer_start: 5000, timer_paused_duration: 12, timer_pause_start: 0,
};

test.describe('choosing the scoreboard source', () => {
  test('an unswitched game is left exactly as it was', () => {
    // Not "a copy that happens to match": the same object, so nothing can
    // quietly diverge for games nobody has switched.
    expect(ScoreSource.merge(UPSTREAM, null)).toBe(UPSTREAM);
    expect(ScoreSource.merge(UPSTREAM, { enabled: false, goals: [] })).toBe(UPSTREAM);
    expect(ScoreSource.active({ enabled: true }), 'and goals are required').toBe(false);
  });

  test('a switched game takes its score and clock from the local document', () => {
    const out = ScoreSource.merge(UPSTREAM, LOCAL);
    expect(out.game_result.homescore).toBe(2);
    expect(out.game_result.visitorscore).toBe(1);
    expect(out.game_result.timer_start).toBe(5000);
    expect(out.game_result.timer_paused_duration).toBe(12);
    expect(out.score_source).toBe('local');
  });

  test('everything the local document says nothing about is left alone', () => {
    // A score is not a payload. The rosters, the pool, the season and the
    // status all still come from upstream, and a switch must not lose them.
    const out = ScoreSource.merge(UPSTREAM, LOCAL);
    expect(out.teams).toBe(UPSTREAM.teams);
    expect(out.seasoninfo).toBe(UPSTREAM.seasoninfo);
    expect(out.game_result.isongoing, 'still live').toBe(1);
  });

  test('the upstream payload is not mutated', () => {
    // The caller may still be holding it. Mutating it under them is how a
    // stale roster ends up attached to a fresh score.
    const before = JSON.stringify(UPSTREAM);
    ScoreSource.merge(UPSTREAM, LOCAL);
    expect(JSON.stringify(UPSTREAM)).toBe(before);
  });

  test('goals arrive in the payload\'s own spelling', () => {
    // `home` is this project's word and `ishomegoal` is Live!'s. A renderer
    // reads the second, so this is the one place the two meet.
    const out = ScoreSource.merge(UPSTREAM, LOCAL);
    expect(out.goals.map((g) => [g.num, g.ishomegoal])).toEqual([[1, 1], [2, 1], [3, 0]]);
  });

  test('who scored is absent, never zero', () => {
    // Match control does not ask a scorekeeper to pick two players per point,
    // so it does not know. Absent means "not tracked" everywhere in this
    // project; a zero would mean "nobody scored", and a roster of zeroes is a
    // top-scorer card confidently showing a whole squad on nought.
    const out = ScoreSource.merge(UPSTREAM, LOCAL);
    for (const goal of out.goals) {
      expect(Object.prototype.hasOwnProperty.call(goal, 'scorer'), 'scorer').toBe(false);
      expect(Object.prototype.hasOwnProperty.call(goal, 'assist'), 'assist').toBe(false);
    }
  });

  test('hold and break still derive, because num and side are what they need', () => {
    // The derivation that would have been the real casualty of a thinner goal.
    const { classifyPoints } = require('../../shared/overlay-client.js');
    const out = ScoreSource.merge(UPSTREAM, LOCAL);
    const tally = classifyPoints(out.goals, [{ type: 'offence', ishome: 1, time: 0 }]);
    expect(tally.unresolved, 'every point resolved').toBe(0);
    expect(tally.home.holds + tally.home.breaks + tally.visitor.holds + tally.visitor.breaks)
      .toBe(3);
  });

  test('a timeout kept here becomes the game event the ticks are counted from', () => {
    // shared/timeouts.js derives the allowance from `gameevents`, which is
    // Live!'s shape — so a timeout recorded here has to arrive in that shape or
    // the ticks on air never move however many are called.
    const out = ScoreSource.merge(
      { ...UPSTREAM, gameevents: [{ time: 1260, ishome: 0, type: 'half_cap', info: 8 }] },
      { ...LOCAL, timeouts: [{ num: 1, home: true, at: 600 }] },
    );
    const timeouts = out.gameevents.filter((e) => e.type === 'timeout');
    expect(timeouts).toEqual([{ time: 600, ishome: 1, type: 'timeout', info: null }]);

    // The half and the cap are not ours to drop.
    expect(out.gameevents.some((e) => e.type === 'half_cap'), 'half kept').toBe(true);
  });

  test('switching the source switches the timeouts with it', () => {
    // Otherwise the board shows this project's score beside Live!'s timeouts,
    // which is two answers about one game.
    const upstream = {
      ...UPSTREAM,
      gameevents: [{ time: 10, ishome: 1, type: 'timeout', info: null }],
    };
    const out = ScoreSource.merge(upstream, { ...LOCAL, timeouts: [] });
    expect(out.gameevents.filter((e) => e.type === 'timeout')).toEqual([]);
    expect(upstream.gameevents, 'and upstream is not mutated').toHaveLength(1);
  });

  test('a game with no goals yet is still the local answer', () => {
    // 0-0 from match control is a fact. Falling back to upstream here would put
    // the previous game's score on air at the start of the next one.
    const out = ScoreSource.merge(UPSTREAM, { enabled: true, goals: [], timer_start: null });
    expect(out.game_result.homescore).toBe(0);
    expect(out.game_result.visitorscore).toBe(0);
    expect(out.score_source).toBe('local');
  });
});
