// @ts-check
/**
 * The shelf of games a phone is carrying.
 *
 * Tested directly with a storage stand-in (AGENTS.md): it is a pure function of
 * what is on the device, and a browser adds nothing to the question except the
 * difficulty of arranging a phone with four games on it and no signal.
 *
 * The question this exists to answer is asked in a car park with one bar of
 * signal: **have I handed everything over?** So the cases that matter are the
 * ones where the honest answer is "no" — a queue that has not drained, a game
 * that was never synced at all, a cap that must not quietly eat either.
 */
const { test } = require('@playwright/test');
const { expect } = require('./helpers');

const Archive = require('../../shared/score-archive.js');

/** A localStorage stand-in. */
const memory = (seed) => ({
  d: Object.assign({}, seed || {}),
  getItem(k) { return this.d[k] ?? null; },
  setItem(k, v) { this.d[k] = String(v); },
  removeItem(k) { delete this.d[k]; },
});

const goal = (num, home) => ({ kind: 'goal', home, at: 100 + num, body: { goal: { num } } });

/** A device with one synced game and one that has never reached a server. */
function device() {
  const m = memory();
  Archive.remember(m, 702, { home: 'Revolver', away: 'Pony', name: 'Semi-final', seen: 10 });
  Archive.remember(m, 703, { home: 'Mosquitos', away: 'Lemmings', name: 'Final', seen: 20 });
  m.setItem('uo-score-server-702', JSON.stringify({ rev: 9, home: 5, away: 4, goals: [] }));
  m.setItem('uo-score-outbox-703', JSON.stringify([goal(1, true), goal(2, false), goal(3, true)]));
  return m;
}

test.describe('what is on the phone', () => {
  test('lists every game, most recently opened first', () => {
    const list = Archive.games(device());
    expect(list.map((g) => g.id)).toEqual([703, 702]);
    expect(list[1].home).toBe('Revolver');
  });

  test('the score is the synced one with the unsent presses on top', () => {
    const list = Archive.games(device());
    // 703 has never reached a server: its whole score is the queue.
    expect(list[0].score).toEqual({ home: 2, away: 1 });
    expect(list[1].score).toEqual({ home: 5, away: 4 });
  });

  test('says which games are still owed, and how much', () => {
    expect(Archive.owed(device())).toEqual({ games: 1, presses: 3, declined: 0 });
    const list = Archive.games(device());
    expect(list.find((g) => g.id === 702).synced).toBe(true);
    expect(list.find((g) => g.id === 703).synced).toBe(false);
  });

  test('a game opened with no signal is still listed, by number', () => {
    // First open offline: an id and nothing else. A list saying "Game 704" is
    // still a list somebody can choose from, and the names arrive later.
    const m = memory();
    Archive.remember(m, 704, {});
    expect(Archive.games(m)[0]).toMatchObject({ id: 704, home: '', away: '' });

    Archive.remember(m, 704, { home: 'Revolver', away: 'Pony' });
    expect(Archive.games(m)[0]).toMatchObject({ home: 'Revolver', away: 'Pony' });
  });

  test('what was learned once is not lost by a colder open', () => {
    // Opening the same game again offline knows less than the first open did.
    const m = memory();
    Archive.remember(m, 704, { home: 'Revolver', away: 'Pony', name: 'Semi-final' });
    Archive.remember(m, 704, {});
    expect(Archive.games(m)[0]).toMatchObject({ home: 'Revolver', name: 'Semi-final' });
  });
});

test.describe('what got through, as opposed to what is left', () => {
  const refused = (num, why) => ({ kind: 'goal', home: true, at: num, num, why });

  test('a declined press is NOT the same as a sent one', () => {
    /**
     * Three paths empty the queue and only one is success: a conflict and a
     * refusal both drop the press so it cannot block the ones behind it. A
     * list counting only the queue would call that game sent while some of
     * what somebody pressed was never stored anywhere.
     */
    const m = device();
    m.setItem('uo-score-declined-702', JSON.stringify([
      refused(4, 'another scorekeeper recorded that point'),
    ]));

    const row = Archive.games(m).find((g) => g.id === 702);
    expect(row.pending, 'nothing left to send').toBe(0);
    expect(row.declined, 'and one press that never landed').toBe(1);
    expect(row.synced, 'so the game is not "sent"').toBe(false);
  });

  test('the totals count refused work apart from unsent work', () => {
    // One can still be fixed by finding signal; the other cannot.
    const m = device();
    m.setItem('uo-score-declined-702', JSON.stringify([refused(4, 'HTTP 500')]));
    expect(Archive.owed(m)).toEqual({ games: 1, presses: 3, declined: 1 });
  });

  test('a row says what the SERVER has, not only what the phone holds', () => {
    const row = Archive.games(device()).find((g) => g.id === 702);
    expect(row.sent, "the server's own answer").toEqual({ home: 5, away: 4 });
    // 703 never reached a server at all, so there is nothing to report.
    expect(Archive.games(device()).find((g) => g.id === 703).sent).toBeNull();
  });

  test('forgetting a game takes the refusals with it', () => {
    const m = device();
    m.setItem('uo-score-declined-702', JSON.stringify([refused(4, 'HTTP 500')]));
    Archive.forget(m, 702, true);
    expect(m.getItem('uo-score-declined-702')).toBeNull();
  });

  test('the exported file carries them, because nothing else records them', () => {
    const m = device();
    m.setItem('uo-score-declined-702', JSON.stringify([refused(4, 'HTTP 500')]));
    const doc = Archive.exportAll(m, 702);
    expect(doc.games[0].declined).toHaveLength(1);
    expect(doc.games[0].declined[0].why).toBe('HTTP 500');
  });
});

test.describe('storage that is not there', () => {
  test('a device with none has no games, rather than an error', () => {
    expect(Archive.games(null)).toEqual([]);
    expect(Archive.owed(null)).toEqual({ games: 0, presses: 0, declined: 0 });
  });

  test('a device that THROWS on access has no games either', () => {
    // A private window throws rather than returning nothing, and this list is
    // on the way to the buttons: it must not be able to take the page down.
    const hostile = {
      getItem() { throw new Error('blocked'); },
      setItem() { throw new Error('blocked'); },
      removeItem() { throw new Error('blocked'); },
    };
    expect(Archive.games(hostile)).toEqual([]);
    expect(() => Archive.remember(hostile, 702, {})).not.toThrow();
  });

  test('junk in the index is not a crash', () => {
    expect(Archive.games(memory({ 'uo-score-games': 'not json' }))).toEqual([]);
    expect(Archive.games(memory({ 'uo-score-games': '[1,2,3]' }))).toEqual([]);
  });
});

test.describe('forgetting a game', () => {
  test('is refused while anything is unsent', () => {
    const m = device();
    expect(Archive.forget(m, 703), 'three presses nobody else has').toBe(false);
    expect(Archive.games(m).map((g) => g.id)).toContain(703);
  });

  test('is allowed once it has drained', () => {
    const m = device();
    expect(Archive.forget(m, 702)).toBe(true);
    expect(Archive.games(m).map((g) => g.id)).not.toContain(702);
    expect(m.getItem('uo-score-server-702')).toBeNull();
  });

  test('takes the scorekeeping code with it', () => {
    // The code is the one thing on the device that GRANTS something. A phone
    // that has "forgotten" a game and can still write to it has not forgotten
    // it, and the word on the button says otherwise.
    const m = device();
    m.setItem('uo-score-code-702', 'ABCDE');
    m.setItem('uo-score-more-702', '1');
    expect(Archive.forget(m, 702)).toBe(true);
    expect(m.getItem('uo-score-code-702')).toBeNull();
    expect(m.getItem('uo-score-more-702')).toBeNull();
  });

  test('can be forced, for a caller that means it', () => {
    const m = device();
    expect(Archive.forget(m, 703, true)).toBe(true);
    expect(m.getItem('uo-score-outbox-703')).toBeNull();
  });
});

test.describe('the cap on a device nobody administers', () => {
  test('drops the oldest game once the list is full', () => {
    const m = memory();
    for (let i = 1; i <= Archive.MAX_GAMES + 3; i += 1) {
      Archive.remember(m, 1000 + i, { seen: i });
    }
    const list = Archive.games(m);
    expect(list.length).toBe(Archive.MAX_GAMES);
    // The three oldest went; the newest stayed.
    expect(list.map((g) => g.id)).not.toContain(1001);
    expect(list.map((g) => g.id)).toContain(1000 + Archive.MAX_GAMES + 3);
  });

  test('NEVER drops a game with work nobody has received', () => {
    // The cap is housekeeping. The unsent queue is the only copy of something
    // somebody did, and housekeeping is not a reason to throw it away.
    const m = memory();
    Archive.remember(m, 999, { seen: 0 });
    m.setItem('uo-score-outbox-999', JSON.stringify([goal(1, true)]));
    for (let i = 1; i <= Archive.MAX_GAMES + 5; i += 1) {
      Archive.remember(m, 2000 + i, { seen: i + 1 });
    }
    const list = Archive.games(m);
    expect(list.map((g) => g.id), 'the oldest game, kept because it is owed')
      .toContain(999);
    expect(m.getItem('uo-score-outbox-999')).not.toBeNull();
  });
});

test.describe('the file somebody takes home', () => {
  test('carries the synced part and the unsent part separately', () => {
    const doc = Archive.exportAll(device());
    expect(doc.format).toBe('ultimate-broadcast/score-export');
    expect(doc.games.length).toBe(2);

    const final = doc.games.find((g) => g.game === 703);
    expect(final.synced.goals).toEqual([]);
    expect(final.unsent.length, 'the three nobody has yet').toBe(3);
    expect(final.score).toEqual({ home: 2, away: 1 });
  });

  test('every unsent press still names the point it completes', () => {
    // What makes the file replayable rather than merely readable: the same rule
    // that makes a retry safe makes an import safe.
    const doc = Archive.exportAll(device(), 703);
    expect(doc.games.length).toBe(1);
    expect(doc.games[0].unsent.map((i) => i.body.goal.num)).toEqual([1, 2, 3]);
  });

  test('one game can be exported on its own', () => {
    expect(Archive.exportAll(device(), 702).games.map((g) => g.game)).toEqual([702]);
  });

  test('a device with nothing on it exports an empty document, not an error', () => {
    const doc = Archive.exportAll(memory());
    expect(doc.games).toEqual([]);
    expect(doc.version).toBe(1);
  });
});
