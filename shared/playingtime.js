/**
 * How much of the game a player has been on for.
 *
 * Nothing upstream records who is on the field — no table, no payload, and
 * `UPSTREAM.md` carries the ask. But the commentary desk knows: it picks the
 * line every point, which is the whole point of that surface. Until now that
 * knowledge was thrown away, because `shared/lines.php` kept one current
 * selection per team and overwrote it. The history it now keeps is what this
 * reads.
 *
 * So this is derivable HERE and nowhere else, and that has a consequence worth
 * stating plainly: the number is **as recorded by a commentary desk**, not as
 * played. Same class of fact as declared possession — true to the extent
 * somebody was keeping up — and it has to be presented that way.
 *
 * WHY EVERY NUMBER CARRIES ITS DENOMINATOR
 *
 * A desk records the points it confirmed, which is not every point. "On for 9"
 * says nothing without "of 11 recorded", and "64%" over four recorded points of
 * a fourteen-point game is not a statistic — it is a guess with a percentage
 * sign on it. So there is no function here that returns a share on its own:
 * `of` is part of every answer, and a caller that wants a percentage has to
 * pass through a coverage check to get one.
 *
 * WHAT IT REFUSES
 *
 *   - **An unconfirmed point is not an absence.** The desk's line carries over
 *     between points, so only points somebody confirmed are in the history at
 *     all; the rest are unknown, and a player missing from them has not been
 *     shown to be off.
 *   - **An empty line is not a point nobody played.** The store drops those on
 *     the way in; this treats one that arrives anyway the same way.
 *   - **No coverage, no claim.** With nothing recorded there is no answer, and
 *     `null` is the answer rather than a row of zeroes.
 *
 * Loaded both by a browser page and by the test runner; see shared/stoppage.js
 * for why it publishes to `window` and `module.exports` alike.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    if (root) { root.PlayingTime = api; }
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    /** A point key is the score the point started at: "9-6". */
    function isScoreKey(value) {
        return typeof value === 'string' && /^\d{1,3}-\d{1,3}$/.test(value);
    }

    /**
     * The points this team has a confirmed line for.
     *
     * @param points the store's `points` map for one team: {"9-6": [3,7,12]}
     * @return an array of {score, players}, in the order the points were played
     *
     * Ordered by the score key rather than by insertion, because a room is
     * written by two desks and read back from a file: insertion order is
     * whatever the last writer happened to do, while a point at 9-6 is always
     * before one at 10-6.
     */
    function recorded(points) {
        if (!points || typeof points !== 'object') { return []; }
        return Object.keys(points)
            .filter(function (k) {
                return isScoreKey(k) && Array.isArray(points[k]) && points[k].length > 0;
            })
            .map(function (k) {
                var parts = k.split('-');
                return {
                    score: k,
                    at: Number(parts[0]) + Number(parts[1]),
                    home: Number(parts[0]),
                    players: points[k].slice()
                };
            })
            .sort(function (a, b) { return a.at - b.at || a.home - b.home; });
    }

    /**
     * One player's playing time.
     *
     * @param points   the store's `points` map for that player's team
     * @param playerId
     * @return {on, of, run} or NULL where the desk recorded nothing at all.
     *         `on` is points confirmed with this player on, `of` is points
     *         confirmed at all, and `run` is how many of the most recent
     *         confirmed points in a row they have been on for.
     */
    function forPlayer(points, playerId) {
        var list = recorded(points);
        if (list.length === 0) { return null; }

        var id = Number(playerId);
        var on = 0;
        list.forEach(function (p) {
            if (p.players.indexOf(id) !== -1) { on += 1; }
        });

        var run = 0;
        for (var i = list.length - 1; i >= 0; i -= 1) {
            if (list[i].players.indexOf(id) === -1) { break; }
            run += 1;
        }

        return { on: on, of: list.length, run: run };
    }

    /**
     * How much of the game the desk actually recorded.
     *
     * @param points  one team's history
     * @param played  points played so far, from the goal list
     * @return {recorded, played, complete}
     *
     * `complete` is the gate every share has to pass. Points can be confirmed
     * out of order or missed in the middle, so this compares counts rather than
     * assuming the recorded points are a prefix — a desk that recorded ten of
     * fourteen has four unknown points wherever they fell.
     */
    function coverage(points, played) {
        var have = recorded(points).length;
        var total = Number(played);
        if (!isFinite(total) || total < 0) { total = have; }
        return {
            recorded: have,
            played: total,
            complete: have > 0 && have >= total
        };
    }

    /**
     * The share of points a player was on for, as text, or NULL.
     *
     * Always "9 of 11 points", never "82%", and null below `minPoints` — a
     * share of three recorded points says nothing about a game. The denominator
     * travels with the number because it is the only thing that makes the
     * number honest.
     */
    function label(points, playerId, minPoints) {
        var t = forPlayer(points, playerId);
        var floor = Number(minPoints);
        if (!t || t.on === 0) { return null; }
        if (isFinite(floor) && t.of < floor) { return null; }
        return t.on + ' of ' + t.of + ' points';
    }

    /**
     * Which unit each player belongs to, inferred from where they have played.
     *
     * A team's O points are the ones they received; their D points are the ones
     * they pulled on. Nothing records which unit a player is in — so it is
     * inferred, and the inference is the risky part rather than the arithmetic.
     *
     * @param points     one team's recorded lines
     * @param received   {score: true|false} — did THIS team receive that point.
     *                   Supplied by the caller from the goal walk rather than
     *                   re-derived here, the same way `Possession.conversion()`
     *                   is handed the starting offence: there is one rule for
     *                   who receives and it lives with the goals.
     * @param minPoints  how many points in a unit before a player is called
     *                   one of theirs
     * @return {o, d, unit} per player id, where unit is 'o', 'd' or null
     *
     * **Null is the common answer and must stay comfortable.** Plenty of teams
     * do not split O and D at all, and early in any game nobody has played
     * enough points to have a unit. A player with points in both is null unless
     * one side of it is clear, because "crossover" means nothing about a squad
     * that rotates everybody.
     */
    function units(points, received, minPoints) {
        var list = recorded(points);
        var floor = Number(minPoints) > 0 ? Math.floor(Number(minPoints)) : 3;
        var tally = {};

        list.forEach(function (p) {
            var side = (received || {})[p.score];
            // A point whose side is unknown teaches nothing about a unit, and
            // counting it as either would invent the very thing being measured.
            if (side !== true && side !== false) { return; }
            p.players.forEach(function (id) {
                tally[id] = tally[id] || { o: 0, d: 0 };
                tally[id][side ? 'o' : 'd'] += 1;
            });
        });

        var out = {};
        Object.keys(tally).forEach(function (id) {
            var t = tally[id];
            var unit = null;
            if (t.o >= floor && t.d === 0) { unit = 'o'; }
            if (t.d >= floor && t.o === 0) { unit = 'd'; }
            out[id] = { o: t.o, d: t.d, unit: unit };
        });
        return out;
    }

    /**
     * Players crossing over: an established O-line player on a D point, or the
     * reverse.
     *
     * Common late in a game and around half, when a team puts its best seven on
     * regardless of which unit they belong to — which is exactly why it is
     * worth saying out loud, and exactly why it must not be said when it has
     * not happened.
     *
     * The refusal that makes it usable: a player's unit is established only by
     * a run of points in one unit and NONE in the other (`units()` above), so
     * the first time they appear in the other one is a genuine crossing rather
     * than a squad that was always rotating. Once they have crossed, their unit
     * is no longer clear and they stop being reported — the fact is about the
     * crossing, not a standing label.
     *
     * @return [{id, from, at}] — `from` is the unit they came from and `at` is
     *         the score of the point they first crossed in, most recent first.
     */
    function crossovers(points, received, minPoints) {
        var list = recorded(points);
        var floor = Number(minPoints) > 0 ? Math.floor(Number(minPoints)) : 3;
        var seen = {};
        var out = [];

        list.forEach(function (p) {
            var side = (received || {})[p.score];
            if (side !== true && side !== false) { return; }
            var here = side ? 'o' : 'd';

            p.players.forEach(function (id) {
                var s = seen[id] = seen[id] || { o: 0, d: 0, crossed: false };
                var theirs = s.o >= floor && s.d === 0 ? 'o'
                    : (s.d >= floor && s.o === 0 ? 'd' : null);
                if (!s.crossed && theirs !== null && theirs !== here) {
                    s.crossed = true;
                    out.push({ id: Number(id), from: theirs, at: p.score });
                }
                s[here] += 1;
            });
        });

        return out.reverse();
    }

    return {
        recorded: recorded,
        forPlayer: forPlayer,
        coverage: coverage,
        label: label,
        units: units,
        crossovers: crossovers
    };
}));
