/**
 * Whether a broadcast surface may say something, and whether what it is
 * already saying is still true.
 *
 * Two decisions, kept together because they are the same policy seen from two
 * sides, and kept out of the page because both were previously made inline on
 * a canvas that reaches air.
 *
 * WHAT WENT WRONG
 *
 * The scoreboard wrote its diagnostics onto the broadcast canvas: a bad game
 * id in a browser-source URL put white "Invalid ID" over the live picture, and
 * five consecutive failed polls replaced a working scoreboard with an error
 * message. Both are useful on a laptop during setup and unacceptable on air,
 * and the page cannot tell those two situations apart.
 *
 * WHAT DECIDES IT INSTEAD
 *
 * A person does, from somewhere else. Diagnostics are off unless an operator
 * turns them on — from the Studio, which writes an expiry into show state, or
 * with `?debug=1` for the laptop case. A URL parameter alone was not enough:
 * the switcher that most needs diagnosing is the one where editing a URL means
 * a virtual keyboard on a device in a rack, and show state arrives through a
 * static file the board already polls.
 *
 * It expires on its own. "Turn it on, fix it, forget to turn it off" is the
 * same failure the room code's auto-hide exists to prevent, and the
 * consequence here is text on air during the next fault.
 *
 * AND WHAT IS ON SCREEN HAS TO STAY TRUE
 *
 * Never replacing a working board with an error is only half an answer: a
 * board that keeps its last frame through a dead API shows a plausible, wrong
 * score for the rest of the game, which is the failure this project guards
 * against hardest. So a board withdraws instead — it hides itself, silently,
 * once nothing has confirmed what it is showing for long enough. A blank
 * corner is a true statement; 8-6 during an 11-9 game is not.
 *
 * ES5 on purpose: loaded by the scoreboard, inside a switcher's browser source.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    if (root) { root.Diagnostics = api; }
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    /**
     * How long a board waits before it stops believing itself, in seconds.
     *
     * Four polls, floored at a minute. The payload's own cache life is the
     * server saying when it will have news — 30 seconds for a live game — so
     * four of them is "this is not one slow response", and a minute is long
     * enough that a phone tethered on a bad field does not blank a board
     * between two goals.
     */
    function windowFor(interval) {
        var seconds = Number(interval);
        if (!isFinite(seconds) || seconds <= 0) { seconds = 30; }

        return Math.max(60, Math.round(seconds * 4));
    }

    /**
     * Is what is on screen still supported by something that answered?
     *
     * `game` and `score` are the last moments each channel succeeded, in
     * milliseconds, and 0 means never. The score channel counts only when the
     * operator has switched this game to match control: the board is then
     * showing a score that is arriving, and an upstream API that has gone away
     * takes the team names with it — which do not change during a game.
     *
     * A board that has never had anything confirmed is stale by definition,
     * which is what withdraws a field-following board that changed game and
     * could not load the new one. It would otherwise sit on the previous
     * game's graphic: a true sentence about a match no longer in front of the
     * camera.
     */
    function stale(now, seen, windowSeconds) {
        var limit = windowSeconds * 1000;
        var game = Number((seen || {}).game) || 0;
        var score = Number((seen || {}).score) || 0;

        if (game && now - game <= limit) { return false; }
        if ((seen || {}).scoreActive && score && now - score <= limit) { return false; }

        return true;
    }

    /**
     * May this surface paint a diagnostic at all?
     *
     * `until` is the expiry an operator wrote into show state, in SECONDS
     * because that is what the store holds; `debug` is the URL parameter. A
     * board that cannot read show state falls back to the parameter alone,
     * which is the laptop case and needs nothing served.
     */
    function enabled(opts) {
        var o = opts || {};
        if (o.debug) { return true; }
        var until = Number(o.until) || 0;

        return until > 0 && (Number(o.now) || 0) / 1000 < until;
    }

    /**
     * Should anything be painted right now?
     *
     * The rule that makes one switch safe for a whole broadcast: **a board
     * that is working says nothing, even with diagnostics on**. Turning them
     * on therefore marks only the boards that are actually failing, which are
     * already showing nothing useful, rather than putting a connection chip on
     * every healthy board on every field.
     */
    function show(opts) {
        var o = opts || {};

        return Boolean(enabled(o) && o.failing);
    }

    return {
        windowFor: windowFor,
        stale: stale,
        enabled: enabled,
        show: show,
    };
}));
