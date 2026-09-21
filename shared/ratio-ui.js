/**
 * The one gender-ratio control, for every desk allowed to declare it.
 *
 * The commentator page built a `<select>` of point one's ratio; the spotter
 * needed the same control the moment the ratio became a responsibility it
 * could take. Two copies of a picker is how the option labels, the wording of
 * the "not shared yet" warning and the blank-option text drift apart, and a
 * spotter and a commentator disagreeing about what a control MEANS is worse
 * than either of them being wrong on their own - they are describing one game.
 *
 * So the markup lives here and each page supplies only what differs: its own
 * class names, and whether this desk can share what it declares.
 *
 * It renders; it decides nothing. Which ratios exist at a size and which one a
 * point is played at are `shared/ratio.js`, and local-versus-shared is
 * `shared/declared.js`. This file knows about elements and words.
 *
 * Loaded both by a browser page and by the test runner; see shared/stoppage.js
 * for why it publishes to `window` and `module.exports` alike.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    if (root) { root.RatioUI = api; }
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    function ratio() {
        return (typeof window !== 'undefined' && window.Ratio)
            || (typeof module === 'object' && module.exports ? require('./ratio.js') : null);
    }

    /**
     * Point one's ratio, as a picker.
     *
     * @param opts.doc       the document to create in (tests pass a stub)
     * @param opts.size      players per side
     * @param opts.current   the ratio in force, or null
     * @param opts.canShare  whether declaring it reaches anybody else
     * @param opts.onChange  (value|null) -> void
     * @param opts.className optional, for the page's own chrome
     * @return a <select>, or NULL when the size forces the ratio and there is
     *         nothing to pick - an even line has one legal split, and offering
     *         a choice would invent one.
     */
    function select(opts) {
        var R = ratio();
        var doc = opts.doc || (typeof document !== 'undefined' ? document : null);
        if (!R || !doc || !R.isChoice(opts.size)) { return null; }

        var pair = R.pairForSize(opts.size) || [];
        if (pair.length < 2) { return null; }

        var el = doc.createElement('select');
        if (opts.className) { el.className = opts.className; }
        el.title = opts.canShare
            ? (opts.current
                ? 'Gender ratio on point 1. Everything else follows the ABBA '
                    + 'pattern from it.'
                : 'Set the gender ratio on point 1, from the paper scoresheet.')
            : 'Gender ratio on point 1 — kept on this screen only until the '
                + 'desk is linked; a shared value replaces it.';
        el.setAttribute('aria-label', 'Gender ratio on point 1');

        // The blank stays first and stays empty-valued: "no ratio declared" is
        // a real state and has to be reachable again after a mistake.
        [['', 'ratio pt 1']].concat(pair.map(function (r) {
            return [r, R.short(r) + ' pt 1'];
        })).forEach(function (o) {
            var opt = doc.createElement('option');
            opt.value = o[0];
            opt.textContent = o[1];
            if (o[0] === (opts.current || '')) { opt.selected = true; }
            el.append(opt);
        });

        el.addEventListener('change', function () {
            opts.onChange(el.value || null);
        });
        return el;
    }

    /**
     * What THIS point is played at - a reading, not a control.
     *
     * Shows nothing confident when point one was never declared: "not said" is
     * the honest label, and printing the commoner ratio instead would be the
     * kind of quiet default the coverage rules exist to forbid.
     *
     * @param opts.doc/size/first/point, and optional className
     */
    function chip(opts) {
        var R = ratio();
        var doc = opts.doc || (typeof document !== 'undefined' ? document : null);
        if (!R || !doc) { return null; }

        var now = R.forPoint(opts.first, opts.size, opts.point);
        var el = doc.createElement('span');
        el.className = (opts.className || 'ratiochip') + (now ? '' : ' unset');
        el.textContent = now ? R.short(now) : 'ratio not said';
        el.title = now
            ? 'Point ' + opts.point + ' is played at ' + now
            : 'Nothing is assumed: declare point 1’s ratio and the rest follow.';
        return el;
    }

    return { select: select, chip: chip };
}));
