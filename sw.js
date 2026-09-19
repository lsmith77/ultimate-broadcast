/**
 * The service worker, for match control and nothing else.
 *
 * It exists so a phone can keep score with no network AT ALL. The outbox has
 * always survived a reload, but only if the page could load — and with no
 * signal a reload was a dead page, which is exactly the moment somebody pulls
 * to refresh to see whether that helps. This makes the page itself available
 * offline, so "add to home screen" is a real offline app rather than a
 * bookmark.
 *
 * WHY IT IS DANGEROUS HERE, AND WHAT THAT DICTATES
 *
 * Every other surface in this project is ON AIR. A stale cached scoreboard
 * served to a switcher mid-tournament is the exact failure this codebase is
 * most careful about — a graphic quietly showing something untrue, which looks
 * completely normal — and it has already been bitten once by browsers caching
 * assets heuristically (`PLAN.md` §4). So:
 *
 *   - **Only match control is cached.** Everything else falls through to the
 *     network untouched. Standalone this worker is allowed the whole origin
 *     (the directory is the document root), so the guard below is what keeps
 *     it off the scoreboard and the stage rather than the scope.
 *   - **Air-facing URLs are refused explicitly**, by name, before anything
 *     else is considered. A bug in the matching below should fail towards "the
 *     network decides", never towards "the cache decides".
 *   - **The page is network-first.** A scorekeeper with signal gets the current
 *     page; the cache is the fallback, not the source. Only static assets are
 *     served cache-first, and they are versioned by `?v=<filemtime>` already.
 *   - **Never the stores.** `score.php` and `possession.php` are writes and
 *     reads of live state; a cached answer there would show a score that is not
 *     the score. They are always network, and failing is correct — the client's
 *     own outbox is what covers the outage.
 *
 * No build step, like everything else here: this is the file that ships.
 */

/**
 * The cache name, handed to this worker in its own URL.
 *
 * The PAGE also needs it, because the page is what fills the cache: a first
 * visit loads before any worker controls it, so nothing would ever be stored if
 * this file were the only writer. Two copies of a cache name is a rule written
 * twice, and a rule written twice eventually disagrees — so `matchcontrol.php`
 * states it once and registers `sw.js?cache=<name>`, and this reads it back.
 * A query string does not affect scope, which is taken from the path.
 *
 * Changing the name is also how a cached set is retired: a different name means
 * a different worker, and `activate` below deletes every cache but its own.
 */
var CACHE = (function () {
    try {
        return new URL(self.location.href).searchParams.get('cache')
            || 'uo-matchcontrol-v1';
    } catch (e) { return 'uo-matchcontrol-v1'; }
}());

/** Requests this worker will never touch, whatever else matches. */
var AIR = [
    'view=live/overlays/scoreboard',
    'view=live/overlays/stage',
    'view=live/overlays/index',
    'view=scoreboard',
    'view=stage',
    'view=index',
    '/s/'
];

/** The page itself, in either mode's spelling. */
function isMatchControl(url) {
    return url.pathname.indexOf('/k/') === 0
        || url.pathname === '/k'
        || url.search.indexOf('view=live/overlays/matchcontrol') !== -1
        || url.search.indexOf('view=matchcontrol') !== -1;
}

/** Static assets this page needs, which are safe to serve from cache first. */
function isAsset(url) {
    return /\.(?:css|js|png|svg|webmanifest|woff2?)$/.test(url.pathname);
}

/**
 * The live stores, and the one file that says what is deployed.
 *
 * Always the network. A cached score is not the score — and `version.json`
 * exists precisely to answer "is my fix live", so serving it from a cache
 * written by the version being questioned would make it agree with itself
 * forever. It matches `isAsset()`'s extension list, which is what would have
 * done it.
 */
function isStore(url) {
    if (/\/version\.json$/.test(url.pathname)) { return true; }

    return url.search.indexOf('view=score') !== -1
        || url.search.indexOf('view=possession') !== -1
        || url.search.indexOf('view=live/overlays/score') !== -1
        || url.search.indexOf('view=live/overlays/possession') !== -1
        || /\/conf\/(score|possession)-\d+\.json$/.test(url.pathname);
}

self.addEventListener('install', function (event) {
    // Nothing is precached: the page is reached with a game id in the URL and
    // the asset URLs carry a filemtime nobody here can predict. The first visit
    // while online is what fills the cache, which is also the instruction a
    // scorekeeper is given — open it once at home.
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (names) {
            return Promise.all(names.map(function (name) {
                return name === CACHE ? null : caches.delete(name);
            }));
        }).then(function () { return self.clients.claim(); })
    );
});

self.addEventListener('fetch', function (event) {
    var request = event.request;
    if (request.method !== 'GET') { return; }

    var url;
    try { url = new URL(request.url); } catch (e) { return; }

    // Another origin's problem.
    if (url.origin !== self.location.origin) { return; }

    // Anything that reaches air, and anything live: the network decides, and
    // this worker does not participate at all.
    for (var i = 0; i < AIR.length; i += 1) {
        if (url.href.indexOf(AIR[i]) !== -1) { return; }
    }
    if (isStore(url)) { return; }

    if (isMatchControl(url)) {
        // Network first. A scorekeeper with signal gets the live page; the copy
        // kept here is for the car park.
        event.respondWith(
            fetch(request).then(function (response) {
                if (response && response.ok) {
                    var copy = response.clone();
                    caches.open(CACHE).then(function (c) { c.put(request, copy); });
                }

                return response;
            }).catch(function () {
                // EXACT match only. An `ignoreSearch` fallback was here and is
                // a trap: match control is also reachable as
                // `?view=matchcontrol&game=702`, where the game id lives in the
                // query — so ignoring the query means serving one game's page
                // for another, and a scorekeeper would enter a whole game
                // against the wrong fixture. Those URLs are out of scope today;
                // the fallback would have made widening the scope silently
                // dangerous rather than merely a decision.
                return caches.match(request).then(function (hit) {
                    if (hit) { return hit; }

                    throw new Error('offline and not cached');
                });
            })
        );

        return;
    }

    if (isAsset(url)) {
        // Cache first: these are versioned by `?v=<filemtime>`, so a changed
        // file is a changed URL and a stale one is impossible.
        event.respondWith(
            caches.match(request).then(function (hit) {
                if (hit) { return hit; }

                return fetch(request).then(function (response) {
                    if (response && response.ok) {
                        var copy = response.clone();
                        caches.open(CACHE).then(function (c) { c.put(request, copy); });
                    }

                    return response;
                });
            })
        );
    }
});
