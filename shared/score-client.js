/**
 * Keeping score from a phone that may have no signal.
 *
 * Every press is applied HERE first and sent afterwards. What the scorekeeper
 * sees is what they entered, immediately, whatever the network is doing — and
 * an unsent press waits in an outbox and is retried until it lands.
 *
 * WHY AN OUTBOX IS SAFE HERE AND WOULD NOT BE ELSEWHERE
 *
 * Because a goal names the point it completes. Retrying "point 9 was scored by
 * home" is harmless however many times it happens: the store keeps one goal for
 * point 9 and reports the rest as already recorded. An outbox of `+1` messages
 * would instead be a machine for double-counting, and the double count would be
 * indistinguishable afterwards from a real run of points.
 *
 * That is the whole reason the rule exists, and it is why this module can be
 * this small. See `shared/score.php` and `docs/MATCHCONTROL.md` §4.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 *
 * It does not merge. If the server's score disagrees with this phone's, the
 * SERVER wins on the next read, because a disagreement means somebody else is
 * also keeping score and two people silently diverging is worse than one of
 * them being corrected. The outbox is for delivery, not for authority.
 *
 * Loaded both by a browser page and by the test runner; see shared/stoppage.js
 * for why it publishes to `window` and `module.exports` alike.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    if (root) { root.ScoreClient = api; }
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    /**
     * @param opts.url    the score endpoint
     * @param opts.game   game id
     * @param opts.code   function returning the scorekeeping code
     * @param opts.fetch  injected for tests
     * @param opts.poll   how often to re-read, ms (0 disables, for tests)
     * @param opts.retry  how often to drain the outbox, ms
     */
    function create(opts) {
        var url = opts.url;
        var game = opts.game;
        var codeOf = opts.code || function () { return ''; };
        var fetchImpl = opts.fetch
            || (typeof fetch === 'function' ? fetch.bind(null) : null);
        var pollMs = opts.poll === undefined ? 4000 : opts.poll;
        var nowFn = opts.now || function () { return Date.now(); };
        var retryMs = opts.retry === undefined ? 3000 : opts.retry;

        // What the server last told us, and what this phone has done since.
        function now() { return Math.floor(nowFn() / 1000); }

        var server = { rev: 0, goals: [], home: 0, away: 0, canWrite: false,
            nominated: null, enabled: null, timer_start: null,
            timer_paused_duration: 0, timer_pause_start: 0, half_at: null };
        /**
         * The queue, and why it outlives the page.
         *
         * It was a variable, so a phone that locked, or a browser that evicted
         * a backgrounded tab, or a scorekeeper who pulled to refresh because
         * nothing seemed to be happening, lost every unsent press. That is the
         * exact moment the queue exists for — a bad connection is also when
         * somebody reloads to see whether that helps.
         *
         * Replaying a restored queue is safe for the same reason retrying is:
         * a goal names the point it completes, an undo names the point it takes
         * back, and starting a running clock is not a restart. Every item is
         * idempotent, so the worst a stale queue can do is be told the store
         * already knows.
         *
         * Wrapped because a browser with site data blocked throws on access
         * rather than returning nothing, and a scorekeeper in a private window
         * should still be able to keep score.
         */
        var STORE_KEY = 'uo-score-outbox-' + game;
        var store = opts.storage !== undefined
            ? opts.storage
            : (typeof localStorage !== 'undefined' ? localStorage : null);

        function restore() {
            if (!store) { return []; }
            try {
                var raw = store.getItem(STORE_KEY);
                var parsed = raw ? JSON.parse(raw) : [];

                return Array.isArray(parsed) ? parsed : [];
            } catch (e) { return []; }
        }

        function persist() {
            if (!store) { return; }
            try {
                if (outbox.length) { store.setItem(STORE_KEY, JSON.stringify(outbox)); }
                else { store.removeItem(STORE_KEY); }
            } catch (e) { /* full, or blocked; the queue still works in memory */ }
        }

        var outbox = restore();
        var error = null;
        var listeners = [];
        var draining = false;

        function announce() {
            persist();
            listeners.forEach(function (fn) { fn(); });
        }

        /**
         * What the screen should show: the server's answer with this phone's
         * unsent presses applied on top.
         */
        function view() {
            var home = server.home;
            var away = server.away;
            var timeouts = (server.timeouts || []).slice();
            var timerStart = server.timer_start;
            var pauseStart = server.timer_pause_start;
            var halfAt = server.half_at;

            outbox.forEach(function (item) {
                if (item.kind === 'goal') {
                    if (item.home) { home += 1; } else { away += 1; }
                } else if (item.kind === 'undo') {
                    // Which side loses a point is whatever the goal being
                    // undone was, which the outbox recorded when it queued it.
                    if (item.home) { home = Math.max(0, home - 1); }
                    else { away = Math.max(0, away - 1); }
                } else if (item.kind === 'timeout') {
                    timeouts.push({ num: item.num, home: item.home, at: item.at });
                } else if (item.kind === 'untimeout') {
                    for (var t = timeouts.length - 1; t >= 0; t -= 1) {
                        if (Boolean(timeouts[t].home) === item.home) {
                            timeouts.splice(t, 1);
                            break;
                        }
                    }
                } else if (item.kind === 'clock') {
                    if (item.action === 'start') {
                        if (!timerStart) { timerStart = item.at; } else { pauseStart = 0; }
                    } else if (item.action === 'pause' && timerStart) {
                        pauseStart = item.at;
                    } else if (item.action === 'half') {
                        halfAt = halfAt ? null : item.at;
                    }
                }
            });

            return {
                home: home, away: away,
                // With the unsent ones applied, like the score: a timeout
                // pressed at a break must show as taken before it has landed.
                timeouts: timeouts,
                rev: server.rev,
                canWrite: server.canWrite,
                nominated: server.nominated,
                enabled: server.enabled,
                timer_start: timerStart,
                timer_paused_duration: server.timer_paused_duration,
                timer_pause_start: pauseStart,
                half_at: halfAt,
                running: Boolean(timerStart) && !pauseStart,
                pending: outbox.length,
                error: error
            };
        }

        function absorb(body) {
            server = {
                rev: Number(body.rev) || 0,
                goals: body.goals || [],
                timeouts: body.timeouts || [],
                home: Number(body.home) || 0,
                away: Number(body.away) || 0,
                canWrite: Boolean(body.canWrite),
                nominated: body.nominated === undefined ? null : Boolean(body.nominated),
                // null until the server has answered once, so the page can tell
                // "not the source" from "not asked yet" and not flash a warning
                // at somebody while the first request is still in flight.
                enabled: body.enabled === undefined ? null : Boolean(body.enabled),
                timer_start: body.timer_start || null,
                timer_paused_duration: Number(body.timer_paused_duration) || 0,
                timer_pause_start: Number(body.timer_pause_start) || 0,
                half_at: body.half_at || null
            };
        }

        function refresh() {
            var q = url + '&game=' + encodeURIComponent(game)
                + '&code=' + encodeURIComponent(codeOf() || '');

            return fetchImpl(q, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (body) {
                    if (body && body.error) { throw new Error(body.error); }
                    absorb(body);
                    error = null;
                    announce();

                    return body;
                })
                .catch(function (e) {
                    error = e.message || 'offline';
                    announce();
                });
        }

        /** Send the head of the outbox; on success drop it and continue. */
        function drain() {
            if (draining || !outbox.length) { return Promise.resolve(); }
            draining = true;
            var item = outbox[0];

            return fetchImpl(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(item.body)
            })
                .then(function (r) {
                    return r.json().then(function (body) { return { r: r, body: body }; });
                })
                .then(function (res) {
                    // 409 means the point number this message claims is not the
                    // one the server is expecting. The head of the queue is
                    // always numbered from the score at the moment it was
                    // queued, and everything ahead of it has already landed —
                    // so the only way to be out of step is that SOMEBODY ELSE
                    // wrote. The server wins in that case (see the note at the
                    // top of this file), which means re-reading and dropping
                    // this message rather than renumbering it: renumbering
                    // would invent a second goal for a point the other
                    // scorekeeper may already have recorded.
                    if (res.r.status === 409) {
                        outbox.shift();
                        error = null;
                        persist();

                        return refresh();
                    }
                    // Any other refusal is not retryable either, and must leave
                    // the queue or one bad message blocks every good one behind
                    // it forever.
                    if (!res.r.ok) {
                        outbox.shift();
                        error = (res.body && res.body.error) || ('HTTP ' + res.r.status);
                        announce();

                        return;
                    }
                    outbox.shift();
                    absorb(res.body);
                    error = null;
                    announce();
                })
                .catch(function (e) {
                    error = e.message || 'offline';
                    announce();
                })
                .then(function () {
                    draining = false;
                    // Keep going while anything is queued and the last one
                    // succeeded; a failure waits for the retry timer instead.
                    if (outbox.length && !error) { return drain(); }

                    return null;
                });
        }

        function queue(item) {
            outbox.push(item);
            announce();

            return drain();
        }

        return {
            view: view,
            onChange: function (fn) { listeners.push(fn); },
            refresh: refresh,

            /**
             * Record a goal.
             *
             * The point number is worked out here — the score this phone
             * believes, plus one — so the message says which point it is and
             * can be sent again without counting twice.
             */
            goal: function (home) {
                var s = view();

                return queue({
                    kind: 'goal', home: home, at: now(),
                    // `at` is when the button was pressed. It travels with the
                    // press because this queue exists precisely for the case
                    // where sending is late: without it the store times a goal
                    // by when the network came back, and a whole outage's worth
                    // of points land on the same second.
                    body: { game: game, code: codeOf(), at: now(),
                        goal: { home: home, num: s.home + s.away + 1 } }
                });
            },

            undo: function () {
                var s = view();
                var num = s.home + s.away;
                if (num < 1) { return Promise.resolve(); }
                // Remember which side it was, so the optimistic view can take
                // the point off the right team before the server answers.
                var last = null;
                for (var i = outbox.length - 1; i >= 0; i -= 1) {
                    if (outbox[i].kind === 'goal') { last = outbox[i].home; break; }
                }
                if (last === null) {
                    var goals = server.goals || [];
                    last = goals.length ? Boolean(goals[goals.length - 1].home) : true;
                }

                return queue({
                    kind: 'undo', home: last,
                    body: { game: game, code: codeOf(), undo: { num: num } }
                });
            },

            /**
             * A timeout, numbered within its own side.
             *
             * Same rule as a goal and for the same reason — "home's second
             * timeout" written twice is one timeout — which is what lets this
             * be pressed at a break with no signal and sent later.
             */
            timeout: function (home) {
                var mine = view().timeouts.filter(function (t) {
                    return Boolean(t.home) === home;
                }).length;
                var at = now();

                return queue({
                    kind: 'timeout', home: home, num: mine + 1, at: at,
                    body: { game: game, code: codeOf(), at: at,
                        timeout: { home: home, num: mine + 1 } }
                });
            },

            undoTimeout: function (home) {
                return queue({
                    kind: 'untimeout', home: home, at: now(),
                    body: { game: game, code: codeOf(),
                        timeout: { home: home, undo: true } }
                });
            },

            clock: function (action) {
                var at = now();

                return queue({
                    kind: 'clock', action: action, at: at,
                    // The clock needs this more than a goal does. `timer_start`
                    // is absolute, so a start delivered after an outage would
                    // run the rest of the game short by however long the outage
                    // lasted — visibly, on air.
                    body: { game: game, code: codeOf(), clock: action, at: at }
                });
            },

            start: function () {
                refresh();
                if (pollMs) {
                    setInterval(refresh, pollMs);
                }
                if (retryMs) {
                    setInterval(function () { if (outbox.length) { drain(); } }, retryMs);
                }
            },

            /** Test seam: the queue, without waiting for a timer. */
            _drain: drain,
            _outbox: function () { return outbox; }
        };
    }

    return { create: create };
}));
