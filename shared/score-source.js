/**
 * Putting a locally kept score into the payload the overlays already render.
 *
 * When an operator has switched a game's scoreboard to match control, the score
 * and the clock come from `conf/score-<game>.json` instead of from upstream —
 * about a second behind instead of up to thirty (`shared/score.php`). Nothing
 * downstream should have to know that happened, so this puts the local answer
 * into the shape every renderer already reads and hands it on.
 *
 * ONE RENDERER, ONE PAYLOAD SHAPE
 *
 * The same rule as `shared/provider.js`, one field deeper. A scoreboard that
 * branched on where its score came from would be two scoreboards, and the
 * second one would be the one nobody looks at until a final.
 *
 * WHAT IT DELIBERATELY CANNOT FILL IN
 *
 * A locally kept goal knows the point it completed and which side scored it.
 * It does not know **who** scored or assisted, because match control does not
 * ask — a scorekeeper on a phone at a pitch is not going to pick two players
 * out of a squad list per point, and pretending otherwise would produce a
 * roster of zeroes rather than an honest absence.
 *
 * So `scorer` and `assist` are omitted, not set to null or zero. Every
 * consumer here already treats an absent field as "not tracked" rather than as
 * "none" — the rule this project keeps returning to — which means top-scorer
 * cards correctly show nothing rather than showing everybody on nought.
 *
 * Hold, break, the point number and the ABBA slot all derive from `num` and
 * `ishomegoal`, which are exactly what a local goal does know. Those keep
 * working unchanged.
 *
 * Loaded both by a browser page and by the test runner; see shared/stoppage.js
 * for why it publishes to `window` and `module.exports` alike.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    if (root) { root.ScoreSource = api; }
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    /** Is this local document one an overlay should be reading? */
    function active(local) {
        return !!(local && local.enabled && Array.isArray(local.goals));
    }

    /**
     * @param payload the upstream payload, as rendered today
     * @param local   the parsed conf/score-<game>.json, or null
     * @return the payload to render — the same object when local is not active
     */
    function merge(payload, local) {
        if (!payload || !active(local)) { return payload; }

        var goals = local.goals.map(function (g) {
            return {
                num: Number(g.num),
                // The payload's spelling, not ours. A renderer reads
                // `ishomegoal`, so this is where the two vocabularies meet and
                // the only place either name appears beside the other.
                ishomegoal: g.home ? 1 : 0,
                time: Number(g.at) || 0
            };
        });

        var home = goals.filter(function (g) { return g.ishomegoal === 1; }).length;
        var away = goals.length - home;

        // A shallow copy: the caller may still be holding the upstream payload
        // for something this does not touch, and mutating it under them is how
        // a stale roster ends up attached to a fresh score.
        var out = {};
        Object.keys(payload).forEach(function (k) { out[k] = payload[k]; });

        var result = {};
        Object.keys(payload.game_result || {}).forEach(function (k) {
            result[k] = payload.game_result[k];
        });
        result.homescore = home;
        result.visitorscore = away;
        result.timer_start = local.timer_start || null;
        result.timer_paused_duration = Number(local.timer_paused_duration) || 0;
        result.timer_pause_start = Number(local.timer_pause_start) || 0;

        /**
         * Timeouts, as the game events every consumer already counts.
         *
         * `shared/timeouts.js` derives the allowance from `gameevents`, which is
         * Live!'s shape — so a timeout kept here has to arrive in that shape or
         * the ticks on air never move. Standalone that is the only source there
         * is; hosted, switching the source switches these too, which is right:
         * a scoreboard showing this project's score beside Live!'s timeouts
         * would be two answers about one game.
         *
         * Appended to whatever the payload already carries rather than
         * replacing it, because `gameevents` also holds the half and the cap,
         * which are not ours to drop.
         */
        var events = (payload.gameevents || []).filter(function (e) {
            return e && e.type !== 'timeout';
        });
        (local.timeouts || []).forEach(function (t) {
            events.push({
                time: Number(t.at) || 0,
                ishome: t.home ? 1 : 0,
                type: 'timeout',
                info: null
            });
        });
        out.gameevents = events;

        out.game_result = result;
        out.goals = goals;
        // Says which answer this is, for anything that wants to show it — and
        // so a page can tell "no goals yet" from "not reading this at all".
        out.score_source = 'local';

        return out;
    }

    return { active: active, merge: merge };
}));
