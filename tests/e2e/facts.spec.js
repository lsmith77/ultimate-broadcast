// @ts-check
/**
 * What is interesting right now — and, much more importantly, what is not.
 *
 * Tested directly rather than through a page (AGENTS.md): it is a pure function
 * of the goal list, the game events and the possession log.
 *
 * Every case that matters here is a REFUSAL. A statistic strip is the purest
 * form of this project's characteristic bug — a graphic quietly asserting
 * something untrue, which looks completely normal in a screenshot and is wrong
 * for as long as it is on air. The three that would do real damage:
 *
 *   - holds and breaks computed without knowing who received the pull, which
 *     inverts every one of them at once
 *   - a run of "clean" points across points nobody was tracking, which is the
 *     overlay inventing a statistic
 *   - a "since half" split computed from goal times a tournament never recorded
 */
const { test } = require('@playwright/test');
const { expect } = require('./helpers');

const Facts = require('../../shared/facts.js');

const NAMES = { home: 'Revolver', away: 'Pony' };

/** A goal, in the payload's shape. */
const goal = (isHome, extra) => Object.assign(
  { num: 0, ishomegoal: isHome ? 1 : 0, time: 0, iscallahan: 0 }, extra || {},
);

/** A list of goals from a string like 'HHVH' — H for home, V for visitor. */
function goals(pattern, times) {
  return pattern.split('').map((c, i) => goal(c === 'H', {
    num: i + 1,
    time: times ? (i + 1) * 60 : 0,
  }));
}

/** A possession press: the score the point started at, and who holds the disc. */
const press = (score, defence) => ({ score, d: defence ? 1 : 0, t: 1 });

/**
 * A tracked point with nobody on defence touching it — the shape of a CLEAN
 * point in the store, and the same positive-evidence rule `wasCleanHold()` in
 * `scoreboard.php` uses: events present, none of them a defence press.
 */
const cleanPoint = (score) => [press(score, false)];

const ctx = (over) => Object.assign({
  goals: [], gameevents: [], pool: {}, declared: null,
  startingOffence: 'home', names: NAMES,
}, over || {});

/** The ids offered, for asserting on presence rather than on wording. */
const ids = (c) => Facts.candidates(c).map((f) => f.id);
const textOf = (c, id) => (Facts.candidates(c).find((f) => f.id === id) || {}).text;

test.describe('nothing to say', () => {
  test('a game with no goals offers nothing', () => {
    expect(Facts.candidates(ctx())).toEqual([]);
    expect(Facts.best(ctx())).toBeNull();
  });

  test('a game trading points quietly offers nothing worth a strip', () => {
    // 1-1, nobody on a run. An empty list is a normal answer: the strip stays
    // blank rather than reaching for something to say.
    expect(ids(ctx({ goals: goals('HV') }))).toEqual([]);
  });
});

test.describe('facts from the goal list alone', () => {
  test('a run of three', () => {
    const c = ctx({ goals: goals('VHHH') });
    expect(ids(c)).toContain('run');
    expect(textOf(c, 'run')).toBe('Revolver: 3 in a row');
  });

  test('two in a row is not a run', () => {
    expect(ids(ctx({ goals: goals('VHH') }))).not.toContain('run');
  });

  test('breaks in a row, which is a different fact from a run', () => {
    // Home receives; every visitor goal here is a break, and the run of goals
    // and the run of breaks are different lengths on purpose.
    const c = ctx({ goals: goals('VVV'), startingOffence: 'home' });
    expect(textOf(c, 'run')).toBe('Pony: 3 in a row');
    expect(textOf(c, 'breakrun')).toBe('Pony: 3 breaks in a row');
  });

  test('a comeback is measured from the worst it got', () => {
    // 0-3 down, back to 3-3.
    const c = ctx({ goals: goals('VVVHHH') });
    expect(textOf(c, 'comeback')).toBe('Revolver: back from 3 down');
  });

  test('a side still behind is not back from anything', () => {
    expect(ids(ctx({ goals: goals('VVVH') }))).not.toContain('comeback');
  });

  test('a callahan outranks everything', () => {
    const list = goals('HHH');
    list[2] = goal(true, { num: 3, iscallahan: 1 });
    const best = Facts.best(ctx({ goals: list }));
    expect(best.id).toBe('callahan');
    expect(best.text).toBe('Callahan — Revolver');
  });
});

test.describe('an unresolved point is not counted', () => {
  test('without the starting offence, only the FIRST point is unknown', () => {
    // Narrower than it sounds, and worth the test because the tempting fix is
    // to refuse everything: whoever concedes receives next, so the chain
    // re-establishes itself from the first goal. Four visitor goals with no
    // recorded offence leave point 1 unresolved and points 2-4 as breaks.
    const c = ctx({ goals: goals('VVVV'), startingOffence: null });
    expect(textOf(c, 'breakrun')).toBe('Pony: 3 breaks in a row');
    expect(textOf(c, 'breaks')).toBe('Breaks: Pony 3, Revolver 0');
  });

  test('with it, that first point is a break too', () => {
    const c = ctx({ goals: goals('VVVV'), startingOffence: 'home' });
    expect(textOf(c, 'breakrun')).toBe('Pony: 4 breaks in a row');
  });

  test('a GAP in the goal numbers breaks the chain rather than inverting it', () => {
    // A missing goal means the next receiver is unknown again. Mirrors
    // classifyPoints(), which counts exactly that point as unresolved -- and
    // the failure it prevents is a confident hold/break for a point whose
    // predecessor nobody recorded.
    const list = [goal(false, { num: 1 }), goal(false, { num: 2 }),
      goal(false, { num: 4 }), goal(false, { num: 5 })];
    const c = ctx({ goals: list, startingOffence: 'home' });
    // Four goals, three breaks: the point numbered 4 follows a goal that is not
    // in the list, so who received it is unknown and it is counted as neither.
    expect(textOf(c, 'breaks')).toBe('Breaks: Pony 3, Revolver 0');
    // And the run is broken rather than being reported as four.
    expect(ids(c)).not.toContain('breakrun');
  });
});

test.describe('clean points, which need positive evidence', () => {
  /**
   * Home holds three times, every one of THEIR points tracked and untouched.
   *
   * The teams trade the whole way — H V H V H — which is the case that matters:
   * "three straight clean O points" is about a team's own offensive points, and
   * counting consecutive GOALS instead would find nothing here. Home receives
   * at 0-0, 1-1 and 2-2; the visitor's points in between are theirs, not home's.
   */
  const threeCleanHolds = {
    goals: [goal(true, { num: 1 }), goal(false, { num: 2 }), goal(true, { num: 3 }),
      goal(false, { num: 4 }), goal(true, { num: 5 })],
    declared: {
      enabled: true,
      events: [].concat(cleanPoint('0-0'), cleanPoint('1-1'), cleanPoint('2-2')),
    },
  };

  test('three straight clean O points, in a game of traded points', () => {
    const c = ctx(threeCleanHolds);
    expect(textOf(c, 'cleanrun')).toBe('Revolver: 3 straight clean O points');
  });

  test('an UNTRACKED point breaks the run rather than extending it', () => {
    // The point at 1-1 has no events: nobody was watching it. That is unknown,
    // not clean, and a run counting it would be the overlay inventing one.
    const c = ctx(Object.assign({}, threeCleanHolds, {
      declared: { enabled: true, events: [].concat(cleanPoint('0-0'), cleanPoint('2-2')) },
    }));
    expect(ids(c)).not.toContain('cleanrun');
  });

  test('a point the defence touched is not clean', () => {
    const c = ctx(Object.assign({}, threeCleanHolds, {
      declared: {
        enabled: true,
        events: [].concat(cleanPoint('0-0'), [press('1-1', true), press('1-1', false)],
          cleanPoint('2-2')),
      },
    }));
    expect(ids(c)).not.toContain('cleanrun');
  });

  test('nothing clean is claimed when tracking is off', () => {
    const c = ctx(Object.assign({}, threeCleanHolds, {
      declared: { enabled: false, events: threeCleanHolds.declared.events },
    }));
    const offered = ids(c);
    expect(offered).not.toContain('cleanrun');
    expect(offered).not.toContain('cleanholds');
    expect(offered).not.toContain('turnoverpoint');
  });

  test('every clean fact declares that it came from tracking', () => {
    // The flag a caller needs to mark a tracked statistic, or to drop all of
    // them where an event did not undertake to record possession.
    const tracked = Facts.candidates(ctx(threeCleanHolds)).filter((f) => f.tracked);
    expect(tracked.map((f) => f.id)).toContain('cleanrun');
  });
});

test.describe('turnovers in the point', () => {
  test('counts the point that just ENDED, not the one being played', () => {
    // Four changes of possession during the point played at 1-1, which the goal
    // at 2-1 completed.
    const c = ctx({
      goals: [goal(true, { num: 1 }), goal(false, { num: 2 }), goal(true, { num: 3 })],
      declared: {
        enabled: true,
        events: [press('1-1', true), press('1-1', false), press('1-1', true),
          press('1-1', false)],
      },
    });
    expect(textOf(c, 'turnoverpoint')).toBe('That point: 4 turnovers');
  });

  test('a scrappy point below the threshold is not worth saying', () => {
    const c = ctx({
      goals: [goal(true, { num: 1 }), goal(false, { num: 2 }), goal(true, { num: 3 })],
      declared: { enabled: true, events: [press('1-1', true), press('1-1', false)] },
    });
    expect(ids(c)).not.toContain('turnoverpoint');
  });
});

test.describe('the second half needs times that were actually recorded', () => {
  const half = { type: 'half_cap', time: 1000, info: 8 };

  test('splits the game at the halftime cap', () => {
    // Six goals at 60..360 are all before the cap; the rest are after.
    const list = goals('HVHVHV').concat(
      goals('HHHV').map((g, i) => goal(g.ishomegoal === 1, { num: 7 + i, time: 1100 + i * 60 })),
    );
    const c = ctx({ goals: list, gameevents: [half] });
    expect(textOf(c, 'sincehalf')).toBe('Revolver: 3-1 since half');
  });

  test('is omitted entirely where no goal times were recorded', () => {
    // Some tournaments record none, and every goal then reads as time 0. A
    // split computed from that would be a whole half attributed to nobody.
    const list = goals('HVHVHVHHHV');
    expect(list.every((g) => g.time === 0)).toBe(true);
    expect(ids(ctx({ goals: list, gameevents: [half] }))).not.toContain('sincehalf');
  });
});

test.describe('timeouts', () => {
  const spent = (isHome) => ({ type: 'timeout', ishome: isHome ? 1 : 0, time: 100 });

  test('a side that has spent them all', () => {
    const c = ctx({
      goals: goals('HVHV'),
      pool: { timeouts: 2, timeoutsper: 'game' },
      gameevents: [spent(true), spent(true)],
    });
    expect(textOf(c, 'notimeouts')).toBe('Revolver: no timeouts left');
  });

  test('both sides out of them is one sentence, not two', () => {
    // Four facts here are asked of BOTH sides, so the list has to hold one
    // entry per id — otherwise the ranking picks whichever side was checked
    // first, which is always home, and a pin on that id follows it.
    const c = ctx({
      goals: goals('HVHV'),
      pool: { timeouts: 1, timeoutsper: 'game' },
      gameevents: [spent(true), spent(false)],
    });
    expect(textOf(c, 'notimeouts')).toBe('Neither team has a timeout left');
    expect(ids(c).filter((id) => id === 'notimeouts').length).toBe(1);
  });

  test('a pool that gives none is not a pool where they ran out', () => {
    // Absent is not zero, and `shared/timeouts.js` answers null for it.
    const c = ctx({ goals: goals('HVHV'), pool: {}, gameevents: [] });
    expect(ids(c)).not.toContain('notimeouts');
  });
});

test.describe('the player who just scored', () => {
  const scored = (num, isHome, scorer) => goal(isHome, {
    num, scorer, scorerfirstname: 'Ari', scorerlastname: 'Ace', scorernum: 8,
  });

  test('their goals in this game, once there are enough to mention', () => {
    const c = ctx({ goals: [scored(1, true, 800), goal(false, { num: 2 }),
      scored(3, true, 800), goal(false, { num: 4 }), scored(5, true, 800)] });
    expect(textOf(c, 'player')).toBe('Ari Ace: 3 goals this game');
  });

  test('assists count towards it and are named separately', () => {
    const list = [scored(1, true, 800), goal(false, { num: 2 }),
      Object.assign(scored(3, true, 801), { assist: 800 }), goal(false, { num: 4 }),
      scored(5, true, 800)];
    // Ace scored 2 and assisted 1 -- four involvements short, so the combined
    // threshold is what carries it.
    expect(textOf(ctx({ goals: list }), 'player')).toBe(undefined);
    expect(textOf(ctx({ goals: list, thresholds: { playerPoints: 3 } }), 'player'))
      .toBe('Ari Ace: 2 goals, 1 assist this game');
  });

  test('nothing is said where the scorer was not recorded', () => {
    // Match control never collects who scored, and plenty of hosted scoresheets
    // are kept without it. Absent is not "nobody".
    const c = ctx({ goals: goals('HVHVH') });
    expect(ids(c)).not.toContain('player');
  });
});

test.describe('thresholds are an installation\'s taste, not a constant', () => {
  test('raising one silences the fact below it', () => {
    const c = ctx({ goals: goals('VHHH') });
    expect(ids(c)).toContain('run');
    expect(ids(Object.assign({}, c, { thresholds: { run: 4 } }))).not.toContain('run');
  });

  test('a threshold of zero is refused, because it would make the fact permanent', () => {
    expect(Facts.thresholds({ run: 0 }).run).toBe(Facts.MIN.run);
    expect(Facts.thresholds({ run: -2 }).run).toBe(Facts.MIN.run);
    expect(Facts.thresholds({ run: 'lots' }).run).toBe(Facts.MIN.run);
  });

  test('an unknown key is dropped rather than stored', () => {
    // A typo in a configuration file must not silently shadow nothing.
    expect(Facts.thresholds({ nosuchfact: 3 }).nosuchfact).toBeUndefined();
  });
});

test.describe('break-chance conversion, and what it inherits', () => {
  const tracked = {
    goals: [goal(true, { num: 1 }), goal(false, { num: 2 }), goal(true, { num: 3 }),
      goal(false, { num: 4 }), goal(true, { num: 5 })],
    declared: {
      enabled: true,
      events: [press('0-0', true), press('0-0', false),
        press('1-1', true), press('1-1', false),
        press('2-2', true), press('2-2', false)],
    },
  };

  test('is offered where somebody tracked the chances', () => {
    expect(textOf(ctx(tracked), 'conversion'))
      .toBe('Pony: 0 from 3 break chances');
  });

  test('is silent without a recorded starting offence, and that is inherited', () => {
    // `Possession.conversion()` needs the first receiver and cannot recover the
    // chain the way the walk here does — with none it returns zeroes. So the
    // fact is absent rather than wrong, which is the right failure, and this
    // test exists so a future change to that module cannot quietly turn it into
    // a claim instead.
    const blind = ctx(Object.assign({}, tracked, { startingOffence: null }));
    expect(ids(blind)).not.toContain('conversion');
  });
});

test.describe('one entry per fact', () => {
  test('the stronger side wins the slot rather than the first one checked', () => {
    // 0-3, then 7-3, then 7-7. Home came back from three down and away from
    // four, both are true at level, and one sentence is offered: the bigger
    // story, rather than home's because home is checked first.
    const c = ctx({ goals: goals('VVVHHHHHHHVVVV'), startingOffence: null });
    expect(ids(c).filter((id) => id === 'comeback').length).toBe(1);
    expect(textOf(c, 'comeback')).toBe('Pony: back from 4 down');
  });
});

test.describe('choosing one to put on air', () => {
  // No starting offence and no tracking, so the only facts here come from the
  // goal list: the comeback and the run.
  const c = ctx({ goals: goals('VVVHHH'), startingOffence: null });

  test('the highest rank wins', () => {
    expect(Facts.best(c).id).toBe('comeback');
  });

  test('a pin holds while it is still true', () => {
    expect(Facts.best(c, 'run').id).toBe('run');
  });

  test('a pin that has stopped being true yields rather than staying on air', () => {
    // The same defect as a tab outliving its point: the operator pinned a fact
    // about a game state that has since passed.
    expect(Facts.best(c, 'callahan').id).toBe('comeback');
    expect(Facts.best(ctx(), 'run')).toBeNull();
  });
});
