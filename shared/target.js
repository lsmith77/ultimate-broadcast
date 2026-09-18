/**
 * What the game is played to, and whether the point being played decides it.
 *
 * Two questions that look like one. "Game to 15" is a fact about the pool;
 * "the next point wins it" is a fact about the pool AND the score AND whether
 * a cap has been called. Both are derived here so that nothing derives half of
 * either somewhere else — `scoreboard.php` already held the cap resolution
 * inline, and the moment the stage or the commentary desk wanted to say
 * "universe point" that would have been the second copy.
 *
 * WHERE THE NUMBERS COME FROM
 *
 * Every game payload carries the whole `uo_pool` row as `poolinfo`, so nothing
 * here needs an upstream change. The fields, with UltiOrganizer's own labels
 * from `admin/addseasonpools.php`:
 *
 *   winningscore    "Game points"      — the score the game is played to
 *   halftimescore   "Halftime at point" — the score half is called at
 *   timecap         "Time cap"          — MINUTES, not a score
 *   scorecap        "Point cap"         — a ceiling, not a target
 *
 * Three traps, each of which has already been walked into:
 *
 *   `poolinfo.halftime` is NOT the half target. It is the length of the break,
 *   in minutes (35 in the recorded payload). The score at half is
 *   `halftimescore`. Two adjacent fields, one of which reads plausibly as the
 *   other -- and `shared/event.php` wrote a score into the wrong one of them
 *   for as long as standalone mode has existed.
 *
 *   A called cap REPLACES the target. UltiOrganizer models two of them,
 *   `half_cap` and `time_cap`, each carrying the new point cap in `info` --
 *   "Time cap 6.45 - new point cap 4". A board saying "to 15" during a game
 *   that is now playing to 9 is worse than a board saying nothing.
 *
 *   `scorecap` is deliberately not used as a target. It is the ceiling a score
 *   may reach, not the score the game ends on, and treating it as the latter
 *   would announce universe point one or two goals early.
 *
 * Loaded both by a browser page and by the test runner; see shared/stoppage.js
 * for why it publishes to `window` and `module.exports` alike.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    if (root) { root.Target = api; }
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    /**
     * The cap that is currently in force, if one has been called.
     *
     * UltiOrganizer models exactly two, `GameCapEventTypes()` in
     * lib/game.functions.php — `half_cap` ("Halftime cap") and `time_cap`
     * ("Time cap"). Each is a game event carrying the time it was called and,
     * in `info`, the new point cap.
     *
     * There is deliberately no soft/hard distinction, because UO has none.
     * Every cap sets a new target and play continues to it, which is soft-cap
     * behaviour; a hard cap is only representable as a target equal to the
     * current score. (`poolinfo.timeoutstimecap: "soft"` is a different thing —
     * whether timeouts may be taken once time cap is reached.)
     *
     * @return the event, or null
     */
    function activeCap(gameEvents) {
        if (!Array.isArray(gameEvents)) { return null; }
        var found = null;
        gameEvents.forEach(function (e) {
            if (!e || (e.type !== 'half_cap' && e.type !== 'time_cap')) { return; }
            // A time cap supersedes a halftime cap; otherwise the later one wins.
            if (!found
                || (e.type === 'time_cap' && found.type !== 'time_cap')
                || (e.type === found.type && Number(e.time) > Number(found.time))) {
                found = e;
            }
        });
        return found;
    }

    /** A positive whole number, or null. Absent is not zero, everywhere here. */
    function score(value) {
        var n = Number(value);
        return isFinite(n) && n > 0 ? Math.floor(n) : null;
    }

    /**
     * The score the game currently ends on.
     *
     * @return {{value: number, capped: boolean}} or NULL when nobody has said.
     *
     * Null is the honest answer and the caller must handle it: an installation
     * that never filled in "Game points" has not agreed a target, and a board
     * that guessed one would be asserting something the tournament never said.
     */
    function game(pool, gameEvents) {
        var cap = activeCap(gameEvents);
        if (cap) {
            var capped = score(cap.info);
            // A cap whose `info` is missing or unparseable leaves the game
            // with no agreed target at all -- the scheduled one is no longer
            // in force, and what replaced it was not recorded.
            return capped === null ? null : { value: capped, capped: true };
        }
        var target = score((pool || {}).winningscore);
        return target === null ? null : { value: target, capped: false };
    }

    /**
     * The score half is called at.
     *
     * @return {{value: number, derived: boolean}} or NULL.
     *
     * `derived` is true when the number was computed rather than recorded.
     * `halftimescore` is an optional field and is commonly left unset, so the
     * fallback is `floor(game target / 2) + 1`: game to 15, half at 8, galaxy
     * point at 7-7. That is the rule as played, and it is NOT the same as
     * rounding half the target up — on an even target the two disagree, and a
     * game to 14 breaks at 8 rather than at 7. It is flagged as derived because
     * it is an inference about this tournament that this tournament never made,
     * and a later reader must be able to tell the two apart.
     *
     * Null once any cap has been called: at that point half is taken at the end
     * of the current point by the clock rather than at a score, so there is no
     * half target left to be one point away from.
     */
    function half(pool, gameEvents) {
        if (activeCap(gameEvents)) { return null; }
        var recorded = score((pool || {}).halftimescore);
        if (recorded !== null) { return { value: recorded, derived: false }; }
        var target = score((pool || {}).winningscore);
        if (target === null) { return null; }
        return { value: Math.floor(target / 2) + 1, derived: true };
    }

    /**
     * Is the point being played right now the one that decides something?
     *
     * @param result     `game_result` — the two scores and the status
     * @param pool       `poolinfo`
     * @param gameEvents `gameevents`
     * @return {{kind: string, target: number, derived: boolean}} or NULL,
     *         where kind is 'universe' (the game decider) or 'galaxy' (the
     *         half decider).
     *
     * Both require the scores to be LEVEL one short of the target, which is
     * what makes the next point the last one. A side sitting on game point
     * while the other trails is not universe point — it can be held off, and a
     * board that called it would be wrong for as long as the game continued.
     *
     * Not when the game is over. A capped game can legitimately finish level
     * where draws are allowed, and 14-14 final would otherwise be announced as
     * a decider forever.
     */
    function decider(result, pool, gameEvents) {
        result = result || {};
        if (String(result.status) === 'completed') { return null; }

        var home = Number(result.homescore);
        var away = Number(result.visitorscore);
        if (!isFinite(home) || !isFinite(away) || home !== away) { return null; }

        var target = game(pool, gameEvents);
        // No agreed game target, no badge -- and that holds for the half too,
        // because the half fallback is derived from the game target and would
        // be an inference stacked on an assumption.
        if (target === null) { return null; }

        // A target of 1 would make 0-0 the decider, which is true and useless:
        // it would be on the board before the pull of every such game.
        if (target.value >= 2 && home === target.value - 1) {
            return { kind: 'universe', target: target.value, derived: false };
        }

        var breakAt = half(pool, gameEvents);
        if (breakAt !== null && breakAt.value >= 2 && home === breakAt.value - 1) {
            return { kind: 'galaxy', target: breakAt.value, derived: breakAt.derived };
        }

        return null;
    }

    /** The words that go on air. Uppercased by CSS, not here. */
    function label(state) {
        if (!state) { return null; }
        return state.kind === 'universe' ? 'Universe point' : 'Galaxy point';
    }

    return {
        activeCap: activeCap,
        game: game,
        half: half,
        decider: decider,
        label: label
    };
}));
