/**
 * The scorekeeper's two tiles, built rather than written out twice.
 *
 * Match control renders this markup server-side; the spotter needs the same
 * thing in a panel. Writing it again by hand is how one surface ends up with
 * "+1" buttons beside a readout while the other has tiles you tap - two ways
 * to keep one score, and a scorekeeper who moves between them has to learn
 * both. So the structure lives here and `shared/scoreboard.css` styles it.
 *
 * It renders and paints; it decides nothing. What a goal means, whether this
 * desk may write one, and what the score currently is are all `ScoreClient`.
 *
 * Loaded both by a browser page and by the test runner; see shared/stoppage.js
 * for why it publishes to `window` and `module.exports` alike.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    if (root) { root.ScoreUI = api; }
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    /**
     * Build the pair. Returns the container and the parts that change, so a
     * caller repaints without going back through the DOM to find them.
     *
     * @param opts.doc      the document to create in
     * @param opts.compact  true for a panel rather than a whole screen
     * @param opts.onGoal   (isHome) -> void, fired by a tap
     * @return { node, home:{tile,name,score}, away:{tile,name,score} }
     */
    function tiles(opts) {
        var doc = opts.doc || (typeof document !== 'undefined' ? document : null);
        if (!doc) { return null; }

        var wrap = doc.createElement('div');
        wrap.className = 'teams' + (opts.compact ? ' compact' : '');

        function one(side) {
            var tile = doc.createElement('button');
            tile.type = 'button';
            tile.className = 'team ' + side;
            var name = doc.createElement('span');
            name.className = 'name';
            var score = doc.createElement('span');
            score.className = 'n';
            score.textContent = '0';
            tile.append(name, score);
            tile.addEventListener('click', function () {
                if (opts.onGoal) { opts.onGoal(side === 'home'); }
            });
            wrap.append(tile);
            return { tile: tile, name: name, score: score };
        }

        var home = one('home');
        var away = one('away');
        return { node: wrap, home: home, away: away };
    }

    /**
     * Put a view on the tiles.
     *
     * A tile nobody may press is DISABLED rather than hidden: the score is
     * still the thing somebody came to read, and a board that vanishes when
     * you lack a code tells you nothing about the game.
     *
     * @param built  what tiles() returned
     * @param view   a ScoreClient view, or null for "no score yet"
     * @param names  { home, away } to label them
     * @param canWrite whether tapping does anything
     */
    function paint(built, view, names, canWrite) {
        if (!built) { return; }
        var n = names || {};
        built.home.name.textContent = n.home || 'Home';
        built.away.name.textContent = n.away || 'Away';
        built.home.score.textContent = view ? view.home : '0';
        built.away.score.textContent = view ? view.away : '0';
        built.home.tile.disabled = !canWrite;
        built.away.tile.disabled = !canWrite;
    }

    return { tiles: tiles, paint: paint };
}));
