// @ts-check
/**
 * Keeping score from a phone with no signal.
 *
 * Tested directly with a stub `fetch` (AGENTS.md), because what matters is what
 * the queue does across a network that comes and goes, and a browser adds
 * nothing to that but difficulty in arranging the outage.
 *
 * The property under test is the one the whole design rests on: **a press is
 * applied here first and sent afterwards, and sending it again is not a second
 * goal.** Every other behaviour below is a consequence of that being true.
 */
const { test } = require('@playwright/test');
const { expect } = require('./helpers');

const ScoreClient = require('../../shared/score-client.js');

/**
 * A server that can be switched off.
 *
 * It applies the same rule the real store does — a goal is the point it
 * completes, and one already recorded is not an event — so the queue is tested
 * against the contract it actually talks to rather than against an obliging fake.
 */
function server() {
    const state = { rev: 0, goals: [], timer_start: null, timer_paused_duration: 0,
        timer_pause_start: 0, half_at: null };
    const s = {
        online: true,
        posts: 0,
        state() { return state; },
        body() {
            return {
                rev: state.rev,
                goals: state.goals,
                home: state.goals.filter((g) => g.home).length,
                away: state.goals.filter((g) => !g.home).length,
                timer_start: state.timer_start,
                timer_paused_duration: state.timer_paused_duration,
                timer_pause_start: state.timer_pause_start,
                half_at: state.half_at,
                canWrite: true,
                nominated: true,
            };
        },
        fetch(url, opts) {
            if (!s.online) return Promise.reject(new Error('offline'));
            if (!opts || opts.method !== 'POST') {
                return Promise.resolve({ ok: true, status: 200, json: async () => s.body() });
            }
            s.posts += 1;
            const sent = JSON.parse(opts.body);
            if (sent.goal) {
                const num = sent.goal.num;
                const next = state.goals.length + 1;
                if (state.goals.some((g) => g.num === num)) {
                    // Already recorded: not an event, and not an error.
                    return Promise.resolve({ ok: true, status: 200, json: async () => s.body() });
                }
                if (num !== next) {
                    return Promise.resolve({ ok: false, status: 409,
                        json: async () => ({ error: 'out of step' }) });
                }
                state.goals.push({ num, home: !!sent.goal.home, at: sent.at || 0 });
                state.rev += 1;
            } else if (sent.undo) {
                if (sent.undo.num === state.goals.length) {
                    state.goals.pop();
                    state.rev += 1;
                }
            } else if (sent.clock === 'start') {
                state.timer_start = state.timer_start || sent.at || 1000;
                state.timer_pause_start = 0;
                state.rev += 1;
            } else if (sent.clock === 'pause') {
                state.timer_pause_start = 2000;
                state.rev += 1;
            }
            return Promise.resolve({ ok: true, status: 200, json: async () => s.body() });
        },
    };
    return s;
}

/** A client wired to that server, with the timers off so tests drive it. */
function client(s) {
    return ScoreClient.create({
        url: '/score', game: 702, code: () => 'ABCDE',
        fetch: s.fetch, poll: 0, retry: 0,
    });
}

test.describe('score client', () => {
    test('a press shows immediately, before the server has heard of it', async () => {
        const s = server();
        s.online = false;
        const c = client(s);

        await c.goal(true);
        // The scorekeeper is not made to wait on a bar of signal.
        expect(c.view().home).toBe(1);
        expect(c.view().pending, 'and is told it has not landed').toBe(1);
    });

    test('what was pressed while offline lands when the network returns', async () => {
        const s = server();
        s.online = false;
        const c = client(s);

        await c.goal(true);
        await c.goal(false);
        await c.goal(true);
        expect(c.view()).toMatchObject({ home: 2, away: 1, pending: 3 });

        s.online = true;
        await c._drain();

        expect(c.view()).toMatchObject({ home: 2, away: 1, pending: 0 });
    });

    test('a goal is queued as the point it completes, so a retry cannot double it', async () => {
        // The property everything else depends on. The queue is deliberately
        // sent twice; the score must not move.
        const s = server();
        s.online = false;
        const c = client(s);
        await c.goal(true);
        await c.goal(true);

        const queued = c._outbox().map((i) => i.body.goal.num);
        expect(queued, 'each press names its own point').toEqual([1, 2]);

        s.online = true;
        await c._drain();
        expect(c.view().home).toBe(2);

        // The same two messages again, as a retry after a lost response would.
        for (const num of [1, 2]) {
            await s.fetch('/score', {
                method: 'POST',
                body: JSON.stringify({ game: 702, code: 'ABCDE', goal: { home: true, num } }),
            });
        }
        await c.refresh();
        expect(c.view().home, 'still two').toBe(2);
    });

    test('a press keeps the time it was pressed, not the time it was sent', async () => {
        // The whole reason the queue exists is that sending is late. Timing the
        // press on arrival would put an outage's worth of points on one second
        // — and for the CLOCK it is worse than mis-filed: `timer_start` is
        // absolute, so a start delivered five minutes late runs the rest of the
        // game five minutes short, on air.
        const s = server();
        s.online = false;
        let clock = 1_000_000;
        const c = ScoreClient.create({
            url: '/score', game: 702, code: () => 'ABCDE',
            fetch: s.fetch, poll: 0, retry: 0, storage: null,
            now: () => clock * 1000,
        });

        await c.clock('start');
        clock += 60;
        await c.goal(true);

        clock += 300;              // five minutes of no signal
        s.online = true;
        await c._drain();

        const landed = s.state().goals[0];
        expect(landed.at, 'the goal is timed when it was pressed').toBe(1_000_060);
        expect(s.state().timer_start, 'and so is the clock').toBe(1_000_000);
    });

    test('an unsent press survives the page being reloaded', async () => {
        // A bad connection is also when somebody pulls to refresh to see
        // whether that helps, and a phone that locks can have its tab evicted.
        // Losing the queue there loses exactly the presses it exists to hold.
        const s = server();
        s.online = false;
        const mem = {
            d: {},
            getItem(k) { return this.d[k] ?? null; },
            setItem(k, v) { this.d[k] = v; },
            removeItem(k) { delete this.d[k]; },
        };
        const opts = { url: '/score', game: 702, code: () => 'ABCDE',
            fetch: s.fetch, poll: 0, retry: 0, storage: mem };

        const before = ScoreClient.create(opts);
        await before.goal(true);
        await before.goal(false);
        expect(before.view().pending).toBe(2);

        const after = ScoreClient.create(opts);
        expect(after.view().pending, 'still queued after a reload').toBe(2);

        s.online = true;
        await after._drain();
        expect(after.view()).toMatchObject({ home: 1, away: 1, pending: 0 });
        expect(mem.d['uo-score-outbox-702'], 'and the queue is cleared').toBeUndefined();
    });

    test('the server wins when the two disagree', async () => {
        // The outbox is for delivery, not for authority. Somebody else keeping
        // score on the same game means one of us is wrong, and silently
        // diverging is worse than being corrected.
        const s = server();
        const c = client(s);
        await c.goal(true);
        expect(c.view().home).toBe(1);

        // Another scorekeeper adds two points this phone never saw.
        await s.fetch('/score', { method: 'POST',
            body: JSON.stringify({ goal: { home: false, num: 2 } }) });
        await s.fetch('/score', { method: 'POST',
            body: JSON.stringify({ goal: { home: false, num: 3 } }) });

        await c.refresh();
        expect(c.view()).toMatchObject({ home: 1, away: 2 });
    });

    test('undo takes the point off the side that scored it', async () => {
        const s = server();
        const c = client(s);
        await c.goal(true);
        await c.goal(false);
        expect(c.view()).toMatchObject({ home: 1, away: 1 });

        await c.undo();
        expect(c.view(), 'the away goal was last').toMatchObject({ home: 1, away: 0 });
    });

    test('undo while offline knows which side it was, from the queue', async () => {
        const s = server();
        s.online = false;
        const c = client(s);
        await c.goal(false);
        await c.undo();
        expect(c.view()).toMatchObject({ home: 0, away: 0, pending: 2 });
    });

    test('nothing to undo is not an error', async () => {
        const s = server();
        const c = client(s);
        await c.undo();
        expect(c.view()).toMatchObject({ home: 0, away: 0, pending: 0 });
    });

    test('the clock shows as running before the server confirms it', async () => {
        const s = server();
        s.online = false;
        const c = client(s);

        await c.clock('start');
        expect(c.view().running, 'the person pressed start; the clock is running').toBe(true);

        s.online = true;
        await c._drain();
        expect(c.view().running).toBe(true);
        expect(c.view().pending).toBe(0);
    });

    test('a message the server is out of step with is dropped, not retried for ever', async () => {
        // The head of the queue is numbered from the score when it was queued,
        // and everything ahead of it has landed — so being out of step means
        // somebody else wrote. The server wins, which means re-reading and
        // dropping this rather than renumbering: renumbering would invent a
        // second goal for a point the other scorekeeper may already have.
        //
        // It must leave the queue whatever the reason, or one bad message
        // blocks every good one behind it for the rest of the game.
        const s = server();
        const c = client(s);
        c._outbox().push({
            kind: 'goal', home: true,
            body: { game: 702, code: 'ABCDE', goal: { home: true, num: 99 } },
        });
        await c._drain();

        expect(c._outbox().length, 'dropped').toBe(0);
        expect(c.view().error, 'and not left showing an error').toBeNull();
    });

    test('one stuck message does not block the ones behind it', async () => {
        const s = server();
        const c = client(s);
        // A stale point first, then a good press behind it.
        c._outbox().push({
            kind: 'goal', home: true,
            body: { game: 702, code: 'ABCDE', goal: { home: true, num: 99 } },
        });
        c._outbox().push({
            kind: 'goal', home: false,
            body: { game: 702, code: 'ABCDE', goal: { home: false, num: 1 } },
        });

        await c._drain();
        await c._drain();

        expect(c._outbox().length, 'both left the queue').toBe(0);
        expect(c.view().away, 'and the good one landed').toBe(1);
    });
});

test.describe('a phone that goes home, is closed, and comes back', () => {
  /** A localStorage stand-in that outlives a "reload". */
  const memory = () => ({
    d: {},
    getItem(k) { return this.d[k] ?? null; },
    setItem(k, v) { this.d[k] = v; },
    removeItem(k) { delete this.d[k]; },
  });

  test('the score that already SYNCED survives a reload with no network', async () => {
    /**
     * The difference between "survives a reload" and "works offline".
     *
     * The outbox holds what has not been sent, and an item is dropped the
     * moment it lands. So a phone that syncs a few points, loses signal and is
     * then reloaded used to come back with the server's answer gone — it lived
     * in memory — and only the unsent tail in hand. The score jumped backwards
     * on the one surface whose whole promise is that what you entered is what
     * you see.
     */
    const s = server();
    const mem = memory();
    const opts = { url: '/score', game: 702, code: () => 'ABCDE',
      fetch: s.fetch, poll: 0, retry: 0, storage: mem };

    const first = ScoreClient.create(opts);
    await first.goal(true);
    await first.goal(false);
    await first.goal(true);
    expect(first.view(), 'all three landed').toMatchObject({ home: 2, away: 1, pending: 0 });

    // The signal goes, one more point is scored, and the phone is reloaded.
    s.online = false;
    await first.goal(true);

    const after = ScoreClient.create(opts);
    expect(after.view().home, 'three home goals, not the one unsent').toBe(3);
    expect(after.view().away).toBe(1);
    expect(after.view().pending, 'and the unsent one is still queued').toBe(1);
  });

  test('a whole game kept with no network at all comes back intact', async () => {
    const s = server();
    s.online = false;
    const mem = memory();
    const opts = { url: '/score', game: 703, code: () => 'ABCDE',
      fetch: s.fetch, poll: 0, retry: 0, storage: mem };

    const phone = ScoreClient.create(opts);
    for (let i = 0; i < 5; i += 1) { await phone.goal(i % 2 === 0); }
    expect(phone.view()).toMatchObject({ home: 3, away: 2, pending: 5 });

    const reopened = ScoreClient.create(opts);
    expect(reopened.view()).toMatchObject({ home: 3, away: 2, pending: 5 });

    // Home at last, and the whole game lands in order.
    s.online = true;
    await reopened._drain();
    expect(reopened.view()).toMatchObject({ home: 3, away: 2, pending: 0 });
    expect(s.state().goals.length).toBe(5);
  });

  test('the snapshot is a cache of the server, never an authority over it', async () => {
    // Somebody else kept score while this phone was away. The server wins on
    // the next read, which is the rule the whole client is built on.
    const s = server();
    const mem = memory();
    const opts = { url: '/score', game: 704, code: () => 'ABCDE',
      fetch: s.fetch, poll: 0, retry: 0, storage: mem };

    const phone = ScoreClient.create(opts);
    await phone.goal(true);
    expect(phone.view().home).toBe(1);

    s.state().goals.push({ num: 2, home: false }, { num: 3, home: false });
    const reopened = ScoreClient.create(opts);
    await reopened.refresh();
    expect(reopened.view(), "the other desk's score, not this phone's")
      .toMatchObject({ home: 1, away: 2 });
  });

  test('a device with storage blocked still keeps score in memory', async () => {
    // A private window throws on access rather than returning nothing, and a
    // scorekeeper in one should still be able to press the buttons.
    const s = server();
    s.online = false;
    const hostile = {
      getItem() { throw new Error('blocked'); },
      setItem() { throw new Error('blocked'); },
      removeItem() { throw new Error('blocked'); },
    };
    const phone = ScoreClient.create({ url: '/score', game: 705, code: () => 'ABCDE',
      fetch: s.fetch, poll: 0, retry: 0, storage: hostile });
    await phone.goal(true);
    expect(phone.view()).toMatchObject({ home: 1, pending: 1 });
  });
});

test.describe('when somebody else is also keeping score', () => {
  const memory = () => ({
    d: {},
    getItem(k) { return this.d[k] ?? null; },
    setItem(k, v) { this.d[k] = v; },
    removeItem(k) { delete this.d[k]; },
  });

  test('a press the other desk already recorded says so, rather than vanishing', async () => {
    /**
     * The quietest way this system can be wrong.
     *
     * The queue empties, the score changes underneath somebody who pressed the
     * buttons, and the only visible evidence is a number they did not expect.
     * Delivery succeeded; the press was declined. Those are different things
     * and the person holding the phone is the one who can sort it out.
     */
    const s = server();
    s.online = false;
    const phone = ScoreClient.create({ url: '/score', game: 706, code: () => 'ABCDE',
      fetch: s.fetch, poll: 0, retry: 0, storage: memory() });

    await phone.goal(true);
    expect(phone.view()).toMatchObject({ home: 1, pending: 1, notice: null });

    // While this phone was dark, the other desk recorded point 1.
    s.state().goals.push({ num: 1, home: false });
    s.online = true;
    await phone._drain();

    expect(phone.view().pending, 'delivered').toBe(0);
    expect(phone.view().notice, 'and declined, out loud').toMatch(/already recorded/i);
    expect(phone.view(), "the other desk's score stands")
      .toMatchObject({ home: 0, away: 1 });
  });

  test('a declined press is written down, and survives the page', async () => {
    /**
     * The notice dies with the page; the record must not. "Nothing left to
     * send" and "everything got through" are different answers, and the only
     * evidence for the difference is this.
     */
    const s = server();
    s.online = false;
    const mem = memory();
    const opts = { url: '/score', game: 709, code: () => 'ABCDE',
      fetch: s.fetch, poll: 0, retry: 0, storage: mem };

    const phone = ScoreClient.create(opts);
    await phone.goal(true);
    s.state().goals.push({ num: 1, home: false });
    s.online = true;
    await phone._drain();

    expect(phone.view().pending).toBe(0);
    expect(phone.view().declined, 'and one that never landed').toBe(1);
    expect(phone.declined()[0].why).toMatch(/already recorded/i);

    // Reopened tomorrow, the phone still knows.
    const reopened = ScoreClient.create(opts);
    expect(reopened.view().declined).toBe(1);
  });

  test('a press that lands is not recorded as declined', async () => {
    const s = server();
    const phone = ScoreClient.create({ url: '/score', game: 710, code: () => 'ABCDE',
      fetch: s.fetch, poll: 0, retry: 0, storage: memory() });
    await phone.goal(true);
    expect(phone.view()).toMatchObject({ pending: 0, declined: 0 });
  });

  test('a refusal that is not a conflict is recorded too, with its reason', async () => {
    // Any non-retryable refusal drops the message so it cannot block the queue
    // behind it. Dropped is dropped, whatever the status code said.
    const s = server();
    const phone = ScoreClient.create({ url: '/score', game: 711, code: () => 'ABCDE',
      fetch: () => Promise.resolve({
        ok: false, status: 500, json: async () => ({ error: 'the store is read-only' }),
      }), poll: 0, retry: 0, storage: memory() });

    await phone.goal(true);
    await phone._drain();
    expect(phone.view().declined).toBe(1);
    expect(phone.declined()[0].why).toBe('the store is read-only');
  });

  test('a notice is dismissible, because it is news rather than a state', async () => {
    const s = server();
    s.online = false;
    const phone = ScoreClient.create({ url: '/score', game: 707, code: () => 'ABCDE',
      fetch: s.fetch, poll: 0, retry: 0, storage: memory() });
    await phone.goal(true);
    s.state().goals.push({ num: 1, home: false });
    s.online = true;
    await phone._drain();

    expect(phone.view().notice).not.toBeNull();
    phone.clearNotice();
    expect(phone.view().notice).toBeNull();
  });

  test('an ordinary outage produces no notice at all', () => {
    // Nothing was declined: it has not been sent yet. Crying conflict here
    // would train somebody to ignore the one that matters.
    const s = server();
    s.online = false;
    const phone = ScoreClient.create({ url: '/score', game: 708, code: () => 'ABCDE',
      fetch: s.fetch, poll: 0, retry: 0, storage: memory() });

    return phone.goal(true).then(() => {
      expect(phone.view()).toMatchObject({ pending: 1, notice: null });
    });
  });
});
