/**
 * How long this game has been running, from the score store's timer fields.
 *
 * The store keeps `timer_start`, `timer_paused_duration` and, while a pause is
 * in progress, `timer_pause_start`. Turning those into an elapsed time is four
 * lines of arithmetic, which is exactly the size of thing that gets written out
 * again in each new consumer and then drifts: the scoreboard and the match
 * control phone already had a copy each when the spotter needed a third.
 *
 * A pause in progress is the part worth stating. `timer_paused_duration` counts
 * pauses that have ENDED, so a clock stopped right now is still accumulating
 * time that nothing has banked yet - subtracting it is what stops a paused
 * clock from creeping forward while somebody watches it.
 *
 * Loaded both by a browser page and by the test runner; see shared/stoppage.js
 * for why it publishes to `window` and `module.exports` alike.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    if (root) { root.GameClock = api; }
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    /**
     * Seconds of game time, or NULL when no clock has been started.
     *
     * Null rather than zero, because "not started" and "started a moment ago"
     * are different facts and a board that prints 0:00 for both is lying about
     * one of them.
     *
     * @param view a score-client view, or anything carrying the same fields
     * @param now  seconds since the epoch; defaults to the real clock
     */
    function elapsed(view, now) {
        if (!view || !view.timer_start) { return null; }
        var at = now === undefined || now === null
            ? Math.floor(Date.now() / 1000) : now;
        var out = at - view.timer_start - (view.timer_paused_duration || 0);
        if (view.timer_pause_start > 0) { out -= at - view.timer_pause_start; }
        return Math.max(0, out);
    }

    /** `m:ss`, and the same dashes every consumer already shows for no clock. */
    function format(seconds) {
        if (seconds === null || seconds === undefined) { return '--:--'; }
        var whole = Math.max(0, Math.floor(seconds));
        var s = whole % 60;
        return Math.floor(whole / 60) + ':' + (s < 10 ? '0' : '') + s;
    }

    return { elapsed: elapsed, format: format };
}));
