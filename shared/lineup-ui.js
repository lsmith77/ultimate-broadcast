/**
 * How a mixed line picker SHOWS its matchings, for every desk that has one.
 *
 * `shared/lineup.js` decides the grouping - which matching leads, whose quota
 * is full, who has no data. This is the half you can see: the per-matching
 * counts above the chips, and what a picker says when the metadata is absent.
 *
 * It is shared because a commentator and a spotter watching the same point
 * must not read "MMP 2 of 4" one place and something differently worded the
 * other; the tint classes (`mt`, `mmp`, `fmp`) are part of that contract, so
 * both stylesheets key off the same names.
 *
 * The chips themselves are NOT here. Each surface's picker carries its own
 * concerns - the desk has injury substitution and the notes store, the spotter
 * has roster groups and a call grammar - and forcing one element to serve both
 * would couple them far more tightly than the duplication costs.
 *
 * Loaded both by a browser page and by the test runner; see shared/stoppage.js
 * for why it publishes to `window` and `module.exports` alike.
 */
(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    if (root) { root.LineupUI = api; }
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    /**
     * "MMP 2 of 4", once per matching, in the order the chips below use.
     *
     * @param opts.doc    the document to create in
     * @param opts.groups from Lineup.groups().groups, or null
     * @return a fragment, empty when there is nothing to count. Empty rather
     *         than absent: a picker with no ratio and no matchings must look
     *         like missing data, never like "0 of 3" against a full line.
     */
    function counts(opts) {
        var doc = opts.doc || (typeof document !== 'undefined' ? document : null);
        var frag = doc ? doc.createDocumentFragment() : null;
        if (!doc || !opts.groups) { return frag; }

        opts.groups.forEach(function (g) {
            var wrap = doc.createElement('span');
            // "Full" means exactly the quota. More than it is OVER, which is
            // an illegal line and must not wear the same colour as a finished
            // one - Lineup.groups folds both into `full`, because for HIDING
            // they behave alike; for reading they do not.
            var over = g.picked > g.quota;
            wrap.className = 'gcount'
                + (over ? ' over' : (g.picked === g.quota ? ' full' : ''));

            var tag = doc.createElement('span');
            tag.className = 'mt ' + g.matching.toLowerCase();
            tag.textContent = g.matching;
            wrap.append(tag);

            wrap.append(doc.createTextNode(' ' + g.picked + ' of ' + g.quota));
            wrap.title = g.matching + ': ' + g.picked + ' of ' + g.quota
                + ' on the line'
                + (over ? ', which is ' + (g.picked - g.quota)
                    + ' too many for this point\u2019s ratio'
                    : (g.picked === g.quota ? ', which is the quota filled' : ''));
            frag.append(wrap);
        });
        return frag;
    }

    /**
     * What a mixed picker says when nobody has any matching against them.
     *
     * The first sentence is the same wherever it appears, because it states a
     * consequence rather than a fix: without matchings nothing can group,
     * count or hide, and a desk that reads it differently in two places will
     * think two different things are wrong. The REMEDY differs by surface -
     * one imports a sheet, the other edits a game pack - so the caller
     * supplies it.
     */
    function missingNote(opts) {
        var doc = opts.doc || (typeof document !== 'undefined' ? document : null);
        if (!doc) { return null; }
        var p = doc.createElement('p');
        p.className = opts.className || 'muted mtwarn';
        p.textContent = 'No FMP/MMP data for this team, so nothing here can '
            + 'group, count or hide.' + (opts.remedy ? ' ' + opts.remedy : '');
        return p;
    }

    return { counts: counts, missingNote: missingNote };
}));
