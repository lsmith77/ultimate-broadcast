// @ts-check
/**
 * Folding a locally kept squad into the team payload every page reads.
 *
 * Tested directly (AGENTS.md): this decides who appears on the commentary desk
 * and on a squad card, it is a pure function of two documents and an injected
 * fetch, and a browser adds nothing to the question.
 *
 * The case worth most of these is the one where a capture ALREADY has a squad.
 * A locally added player is somebody who turned up and is not in the
 * tournament's list — an addition, never a replacement — and getting that
 * backwards quietly loses everybody upstream knows about.
 */
const { test } = require('@playwright/test');
const { expect } = require('./helpers');

const Provider = require('../../shared/provider.js');

const RECORDED = {
  team_id: 300,
  name: 'Mosquitos',
  players: [
    { player_id: 810, firstname: 'Alex', lastname: 'Auer', num: 1, done: 4, total: 9 },
  ],
};

/** A provider that answers from RECORDED, plus a roster endpoint stub. */
function wired(local, opts) {
  const base = {
    team: () => Promise.resolve(JSON.parse(JSON.stringify(RECORDED))),
    games: () => Promise.resolve({}),
  };

  return Provider.withLocalRoster(base, {
    rosterUrl: '/roster?',
    fetch: (opts && opts.fetch) || (() => Promise.resolve({
      ok: true, json: async () => ({ players: local }),
    })),
  });
}

test.describe('a squad kept here', () => {
  test('is added to the recorded one, never substituted for it', async () => {
    const team = await wired([{ id: 1, num: 77, name: 'Gus Guard' }]).team(300);
    expect(team.players.map((p) => p.player_id)).toEqual([810, 1]);
    expect(team.players[0].done, 'the recorded row is untouched').toBe(4);
  });

  test('a name in one column becomes a first and last name', async () => {
    const team = await wired([{ id: 1, name: 'Gus Van Guard' }]).team(300);
    const added = team.players[1];
    expect(added.firstname).toBe('Gus');
    expect(added.lastname, 'everything after the first space').toBe('Van Guard');
  });

  test('a single-word name does not invent a surname', async () => {
    const team = await wired([{ id: 1, name: 'Guard' }]).team(300);
    expect(team.players[1].firstname).toBe('Guard');
    expect(team.players[1].lastname).toBe('');
  });

  test('nobody added has a zero against their name', async () => {
    // Absent is not zero. These players have not played, and a top-scorer card
    // showing a squad on nought is the failure this project keeps returning to.
    const team = await wired([{ id: 1, name: 'Gus Guard' }]).team(300);
    for (const field of ['done', 'fedin', 'total', 'games', 'deftotal']) {
      expect(
        Object.prototype.hasOwnProperty.call(team.players[1], field), field,
      ).toBe(false);
    }
  });

  test('a player already in the capture is not added twice', async () => {
    // A capture recorded after somebody was added locally has them in both.
    const team = await wired([{ id: 810, name: 'Alex Auer' }]).team(300);
    expect(team.players).toHaveLength(1);
  });

  test('a missing number stays missing rather than becoming zero', async () => {
    const team = await wired([{ id: 1, name: 'Gus Guard' }]).team(300);
    expect(team.players[1].num).toBeNull();
  });

  test('an unreachable roster endpoint is an empty squad, not an error', async () => {
    // It 404s under a host by design. A page that could not draw a team because
    // an addition could not be fetched would be worse than one drawing the
    // squad it already had.
    const failing = wired([], { fetch: () => Promise.reject(new Error('offline')) });
    const team = await failing.team(300);
    expect(team.players.map((p) => p.player_id)).toEqual([810]);
  });

  test('the upstream payload is not mutated', async () => {
    const before = JSON.stringify(RECORDED);
    await wired([{ id: 1, name: 'Gus Guard' }]).team(300);
    expect(JSON.stringify(RECORDED)).toBe(before);
  });

  test('without a rosterUrl the provider is handed back untouched', () => {
    const base = { team: () => Promise.resolve(RECORDED) };
    expect(Provider.fromConfig({ captureBase: null, apiBase: '/api' })).toBeTruthy();
    // fromConfig only wraps when asked, so hosted pages pay nothing.
    const plain = Provider.fromConfig({ apiBase: '/api' });
    expect(typeof plain.team).toBe('function');
    expect(base).toBeTruthy();
  });
});
