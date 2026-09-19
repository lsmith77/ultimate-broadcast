/**
 * Every game this phone has kept, and what is still owed to a server.
 *
 * `shared/score-client.js` is one game: what is on screen and what is queued.
 * This is the shelf they all sit on — the list a scorekeeper sees before
 * choosing one, and the file they hand over afterwards.
 *
 * WHY A SEPARATE INDEX AND NOT A SCAN
 *
 * The per-game keys are enough to FIND the games (`uo-score-outbox-<id>`,
 * `uo-score-server-<id>`), and for a while this did exactly that. It is wrong
 * for one reason worth keeping: a phone whose queue has fully drained has no
 * outbox key and a snapshot that says nothing about which game it was — no
 * names, no kickoff, nothing a person could pick from a list. The index is
 * where the human-readable part lives, written when a game is opened, and it
 * is what makes a list of games rather than a list of numbers.
 *
 * WHAT IT IS NOT
 *
 * Not a second copy of the score. Everything here is read from the same two
 * keys the client already owns, composed rather than stored again — two records
 * of one score is how they come to disagree, and the one on the client is the
 * one a scorekeeper is looking at.
 *
 * STORAGE CAN FAIL, AND MUST NOT TAKE THE PAGE WITH IT
 *
 * A private window throws on access rather than returning nothing, and site
 * data can be cleared under a phone at any moment. Every read is wrapped and
 * every failure degrades to "no games on this device", which is a true answer
 * and a harmless one.
 *
 * Loaded both by a browser page and by the test runner; see shared/stoppage.js
 * for why it publishes to `window` and `module.exports` alike.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    if (root) { root.ScoreArchive = api; }
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    var INDEX_KEY = 'uo-score-games';
    var OUTBOX_PREFIX = 'uo-score-outbox-';
    var SERVER_PREFIX = 'uo-score-server-';
    var DECLINED_PREFIX = 'uo-score-declined-';

    /**
     * Games kept on the device before the oldest is forgotten.
     *
     * A tournament weekend is a dozen games at the outside. The cap is here
     * because this is a list that only ever grows otherwise, on a device nobody
     * administers — and a game is only dropped once it has nothing unsent, so
     * the cap can never discard work that has not reached a server.
     */
    var MAX_GAMES = 40;

    function storageOf(store) {
        if (store !== undefined && store !== null) { return store; }
        try {
            return typeof localStorage !== 'undefined' ? localStorage : null;
        } catch (e) { return null; }
    }

    function read(store, key, fallback) {
        var s = storageOf(store);
        if (!s) { return fallback; }
        try {
            var raw = s.getItem(key);
            if (!raw) { return fallback; }
            var parsed = JSON.parse(raw);

            return parsed === null || parsed === undefined ? fallback : parsed;
        } catch (e) { return fallback; }
    }

    function write(store, key, value) {
        var s = storageOf(store);
        if (!s) { return false; }
        try {
            s.setItem(key, JSON.stringify(value));

            return true;
        } catch (e) { return false; }
    }

    /** The raw index: game id -> what a person needs to recognise it. */
    function index(store) {
        var raw = read(store, INDEX_KEY, {});

        return (raw && typeof raw === 'object' && !Array.isArray(raw)) ? raw : {};
    }

    /**
     * Note that this device is keeping this game.
     *
     * Called when match control opens a game, with whatever the payload knew.
     * Names are optional on purpose: a phone that opened a game for the first
     * time with no signal has an id and nothing else, and a list saying "Game
     * 702" is still a list somebody can use.
     */
    function remember(store, game, about) {
        var id = Number(game);
        if (!isFinite(id) || id <= 0) { return index(store); }

        var all = index(store);
        var was = all[String(id)] || {};
        var info = about || {};
        all[String(id)] = {
            id: id,
            // Kept from before when this open knew less: a phone that first saw
            // the game offline learns the names later, and must not lose them
            // the next time it opens cold.
            home: info.home || was.home || '',
            away: info.away || was.away || '',
            name: info.name || was.name || '',
            at: info.at || was.at || '',
            seen: Number(info.seen) || Math.floor(Date.now() / 1000)
        };

        prune(store, all);

        return all;
    }

    /**
     * Drop the least recently seen games that have nothing unsent.
     *
     * Never a game with pending presses, however old: that is work somebody did
     * that no server has yet, and a cap is not a reason to throw it away.
     */
    function prune(store, all) {
        var ids = Object.keys(all);
        if (ids.length <= MAX_GAMES) {
            write(store, INDEX_KEY, all);

            return all;
        }

        var droppable = ids.filter(function (id) {
            return pending(store, id).length === 0;
        }).sort(function (a, b) {
            return (all[a].seen || 0) - (all[b].seen || 0);
        });

        while (Object.keys(all).length > MAX_GAMES && droppable.length) {
            var id = droppable.shift();
            delete all[id];
            forget(store, id, true);
        }
        write(store, INDEX_KEY, all);

        return all;
    }

    /** The unsent presses for one game. */
    function pending(store, game) {
        var raw = read(store, OUTBOX_PREFIX + Number(game), []);

        return Array.isArray(raw) ? raw : [];
    }

    /**
     * Presses that left the queue without being recorded, for one game.
     *
     * The difference between "nothing left to send" and "everything got
     * through". A conflict or a refusal empties the queue exactly as a success
     * does, so a list that counted only the queue would report a game as sent
     * while some of what somebody pressed was never stored anywhere.
     */
    function declined(store, game) {
        var raw = read(store, DECLINED_PREFIX + Number(game), []);

        return Array.isArray(raw) ? raw : [];
    }

    /** The last answer a server gave for one game, or null. */
    function snapshot(store, game) {
        var raw = read(store, SERVER_PREFIX + Number(game), null);

        return raw && typeof raw === 'object' ? raw : null;
    }

    /**
     * The score this device believes for one game.
     *
     * The same composition `score-client.js` does on screen — the last known
     * server answer with the unsent presses applied — so the list cannot show
     * one number while the game shows another. Goals only: the list needs a
     * score and a count of what is owed, not a clock.
     */
    function scoreOf(store, game) {
        var server = snapshot(store, game) || {};
        var home = Number(server.home) || 0;
        var away = Number(server.away) || 0;

        pending(store, game).forEach(function (item) {
            if (!item) { return; }
            if (item.kind === 'goal') {
                if (item.home) { home += 1; } else { away += 1; }
            } else if (item.kind === 'undo') {
                if (item.home) { home = Math.max(0, home - 1); }
                else { away = Math.max(0, away - 1); }
            }
        });

        return { home: home, away: away };
    }

    /**
     * Every game on this device, most recently seen first.
     *
     * @return [{id, home, away, name, at, seen, score, pending, synced}]
     *
     * `synced` is the one a scorekeeper actually reads: false means this phone
     * is still holding something no server has. It is the reason the list
     * exists — "have I handed everything over" is the question somebody asks in
     * a car park, and counting pending presses is how they answer it.
     */
    function games(store) {
        var all = index(store);

        return Object.keys(all).map(function (id) {
            var entry = all[id];
            var owed = pending(store, id).length;
            var lost = declined(store, id);

            return {
                id: Number(id),
                home: entry.home || '',
                away: entry.away || '',
                name: entry.name || '',
                at: entry.at || '',
                seen: Number(entry.seen) || 0,
                score: scoreOf(store, id),
                pending: owed,
                declined: lost.length,
                // The server's own answer, so a row can say what LANDED rather
                // than only what is left. Null until a server has answered once.
                sent: (function () {
                    var server = snapshot(store, id);
                    if (!server) { return null; }

                    return { home: Number(server.home) || 0, away: Number(server.away) || 0 };
                }()),
                synced: owed === 0 && lost.length === 0
            };
        }).sort(function (a, b) { return b.seen - a.seen; });
    }

    /**
     * What is still owed, and what was refused.
     *
     * @return {games, presses, declined} — the first two are work a server has
     *         not seen, the third is work it will never see. They are counted
     *         apart because only one of them can still be fixed by finding
     *         signal.
     */
    function owed(store) {
        var all = games(store);
        var list = all.filter(function (g) { return g.pending > 0; });

        return {
            games: list.length,
            presses: list.reduce(function (n, g) { return n + g.pending; }, 0),
            declined: all.reduce(function (n, g) { return n + g.declined; }, 0)
        };
    }

    /**
     * Everything this device holds, as a document somebody can keep.
     *
     * Deliberately the RAW material — the last server answer and the unsent
     * queue, per game, side by side — rather than a tidy summary. A summary is
     * a second implementation of the score that can disagree with the phone;
     * this is what the phone actually has, and anything downstream can derive
     * the same numbers the same way.
     *
     * `goals` carries the point number each one completes, which is what makes
     * the file replayable into a store rather than merely readable: the same
     * rule that makes a retry safe makes an import safe.
     */
    function exportAll(store, only) {
        var wanted = games(store).filter(function (g) {
            return !only || Number(only) === g.id;
        });

        return {
            format: 'ultimate-broadcast/score-export',
            version: 1,
            exported: new Date().toISOString(),
            games: wanted.map(function (g) {
                var server = snapshot(store, g.id) || {};

                return {
                    game: g.id,
                    home: g.home,
                    away: g.away,
                    name: g.name,
                    time: g.at,
                    score: g.score,
                    // What a server has already been told.
                    synced: {
                        rev: Number(server.rev) || 0,
                        goals: server.goals || [],
                        timeouts: server.timeouts || [],
                        timer_start: server.timer_start || null,
                        timer_paused_duration: Number(server.timer_paused_duration) || 0,
                        timer_pause_start: Number(server.timer_pause_start) || 0,
                        half_at: server.half_at || null
                    },
                    // And what it has not. Replayable in order, and idempotent.
                    unsent: pending(store, g.id),
                    // And what it refused. In the file because a person
                    // reconciling afterwards needs to know a press existed and
                    // did not land — it is the only record that it ever did.
                    declined: declined(store, g.id)
                };
            })
        };
    }

    /**
     * Forget a game on this device.
     *
     * Refuses while anything is unsent unless told twice, because the button is
     * one tap from a list and the work it would discard is the only copy. A
     * caller that means it passes `force`.
     */
    function forget(store, game, force) {
        var id = Number(game);
        if (!force && pending(store, id).length > 0) { return false; }

        var s = storageOf(store);
        if (s) {
            try {
                s.removeItem(OUTBOX_PREFIX + id);
                s.removeItem(SERVER_PREFIX + id);
                // The scorekeeping code too. It is the one thing on this device
                // that grants anything — leaving it behind means a phone that
                // has "forgotten" a game can still write to it, which is not
                // what the word means.
                s.removeItem(DECLINED_PREFIX + id);
                s.removeItem('uo-score-code-' + id);
                s.removeItem('uo-score-more-' + id);
            } catch (e) { /* blocked; the index entry still goes */ }
        }
        var all = index(store);
        delete all[String(id)];
        write(store, INDEX_KEY, all);

        return true;
    }

    return {
        INDEX_KEY: INDEX_KEY,
        MAX_GAMES: MAX_GAMES,
        index: index,
        remember: remember,
        pending: pending,
        declined: declined,
        snapshot: snapshot,
        scoreOf: scoreOf,
        games: games,
        owed: owed,
        exportAll: exportAll,
        forget: forget
    };
}));
