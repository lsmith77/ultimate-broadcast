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
