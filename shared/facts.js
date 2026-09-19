/**
 * What is interesting about this game right now.
 *
 * `COMMENTATOR.md` §2 states the principle this exists to serve, and states it
 * about people rather than graphics: commentators do not read tables on air,
 * they use *talking points* — a streak, a run of play, a milestone. A surface
 * that answers "what is interesting right now?" beats one that answers "what
 * are all the numbers?", and the second is much easier to build by accident.
 *
 * So this returns FACTS, ranked, not a statistics table. Each one is a single
 * sentence that is true at this moment, carries its own threshold for being
 * worth saying, and disappears when it stops being true. A caller takes the top
 * one, or nothing at all — an empty list is a normal answer and means the game
 * is not doing anything worth a line.
 *
 * WHAT IT REFUSES TO SAY, AND WHY THAT IS THE POINT
 *
 * This is the failure mode `AGENTS.md` names: the characteristic bug here is
 * not a crash but a graphic quietly asserting something untrue, and a wrong
 * statistic looks completely normal on air. Four refusals are built in:
 *
 *   - **Holds and breaks need the starting offence.** Which side received the
 *     pull is in no payload; `classifyPoints()` carries an `unresolved` bucket
 *     for exactly this. Without it every hold/break fact is omitted rather than
 *     guessed, because guessing inverts all of them at once.
 *   - **A clean point needs positive evidence.** An untracked point is
 *     UNKNOWN, not clean. A run of clean O points across points nobody was
 *     watching would be this project inventing a statistic, so a run is
 *     claimed only where every point in it was tracked.
 *   - **Timed facts need times.** Some tournaments record no goal times at
 *     all, so anything measured against the clock — including "since half" —
 *     first checks that the times are really there.
 *   - **Nothing the board already says.** The score carries the lead; a strip
 *     that spends itself restating it has said nothing.
 *
 * WHAT IS NOT HERE, AND WHY
 *
 * Tournament totals — top scorer, seeds, records, blocks — need `entity=teams`,
 * a fetch per side the scoreboard does not make and to which the arm/show rule
 * (`STUDIO.md` §2.6) applies. Anything per-throw — completions, yardage,
 * drops, hockey assists — exists nowhere in UltiOrganizer at all.
 *
 * Loaded both by a browser page and by the test runner; see shared/stoppage.js
 * for why it publishes to `window` and `module.exports` alike.
 */
(function (root, factory) {
    'use strict';
    // The two derivations this reuses are resolved lazily rather than taken as
    // arguments: the clean, turnover and timeout facts have to agree with
    // `shared/possession.js` and `shared/timeouts.js` exactly, and a second walk
    // of the same data here is how the copies would drift.
    var dep = function (name, global) {
        return function () {
            if (typeof module === 'object' && module.exports) { return require(name); }
            return root ? root[global] : null;
        };
    };
    var api = factory(dep('./possession.js', 'Possession'), dep('./timeouts.js', 'Timeouts'));
    if (typeof module === 'object' && module.exports) { module.exports = api; }
    if (root) { root.Facts = api; }
}(typeof window !== 'undefined' ? window : null, function (possession, timeouts) {
    'use strict';

    /**
     * Thresholds, in one place, and overridable.
     *
     * Every one answers "how big before it is worth a line on a broadcast",
     * which is a judgement rather than a fact — so they are named, gathered,
     * easy to argue with, and settable per game rather than compiled in. Two in
     * a row is not a streak *at most tournaments*: a showcase final and a
     * Saturday pool game want different bars, and an operator who cannot move
     * them ends up switching the whole strip off instead.
     *
     * These are the defaults. `ctx.thresholds` overrides any of them, and
     * `thresholds()` is what decides which overrides are allowed to count.
     */
    var MIN = {
        run: 3,
        breakRun: 2,
        cleanRun: 3,
        cleanHolds: 3,
        holdRun: 4,
        comeback: 3,
        turnovers: 4,
        chances: 2,
        sinceHalf: 3,
        playerGoals: 3,
        playerPoints: 4
    };

    /** Rank is the order somebody would rather say them in, not a probability. */
    var RANK = {
        callahan: 95,
        // Above the break run on purpose, and they usually co-occur: a side
        // coming back from four down has necessarily broken to do it, and
        // "back from 4 down" is the story that "2 breaks in a row" is part of.
        comeback: 84,
        breakrun: 82,
        cleanrun: 76,
        run: 62,
        turnoverpoint: 58,
        holdrun: 54,
        conversion: 50,
        sincehalf: 46,
        notimeouts: 42,
        player: 40,
        cleanholds: 34,
        breaks: 30
    };

    /**
     * The thresholds in force: the defaults, with an operator's overrides on
     * top.
     *
     * Only known keys, and only whole numbers of at least one. A threshold of
     * zero would make every fact permanently true — "1 in a row" after every
     * goal — which is a strip that says something meaningless continuously,
     * and is worse than an empty one. An unknown key is dropped rather than
     * stored, so a typo in a saved configuration cannot silently disable a
     * fact by shadowing nothing.
     */
    function thresholds(over) {
        var out = {};
        Object.keys(MIN).forEach(function (k) { out[k] = MIN[k]; });
        Object.keys(over || {}).forEach(function (k) {
            if (!Object.prototype.hasOwnProperty.call(MIN, k)) { return; }
            var n = Number(over[k]);
            if (isFinite(n) && n >= 1) { out[k] = Math.floor(n); }
        });
        return out;
    }

    /** The other side. */
    function other(side) { return side === 'home' ? 'visitor' : 'home'; }

    /** A finite number, or null. */
    function num(value) {
        var n = Number(value);
        return isFinite(n) ? n : null;
    }

    /**
     * Every point of the game so far, walked once.
     *
     * One walk, because each fact below is a different question about the same
     * sequence, and a walk per fact is how two of them come to disagree about
     * what happened. Each entry carries what is KNOWN about that point:
     *
     *   scored     'home' | 'visitor'
     *   receiving  the side that received the pull, or null when unknown
     *   kind       'hold' | 'break', or null when the point is unresolved
     *   tracked    was anybody recording possession for this point
     *   clean      true only when tracked AND the defence never touched it
     *   turnovers  changes of possession, or null when the point is untracked
     *   home/visitor  the score BEFORE the point
     *
     * That score is also the possession store's key, which is the convention
     * `Possession.conversion()` uses — filing a point's events under the score
     * after it would attribute every clean hold to the wrong point.
     *
     * Mirrors `classifyPoints()` in `shared/overlay-client.js`, including the
     * two ways a point becomes unresolved, and it is worth knowing that the
     * first is narrower than it sounds: with no recorded starting offence only
     * the FIRST point is unknown, because whoever concedes receives next and
     * the chain re-establishes itself from the first goal. The second is a gap
     * in the goal numbers — a missing goal breaks that chain, so the point
     * after the gap is unresolved rather than confidently inverted.
     */
    function points(ctx) {
        var goals = (Array.isArray(ctx.goals) ? ctx.goals : []).slice()
            .sort(function (a, b) { return Number(a.num) - Number(b.num); });
        var events = (ctx.declared && Array.isArray(ctx.declared.events))
            ? ctx.declared.events : [];
        var tracking = Boolean(ctx.declared && ctx.declared.enabled) && events.length > 0;
        var P = possession();

        var receiving = (ctx.startingOffence === 'home' || ctx.startingOffence === 'visitor')
            ? ctx.startingOffence
            : null;
        var home = 0;
        var visitor = 0;
        var out = [];
        var expected = goals.length ? Number(goals[0].num) : null;

        goals.forEach(function (g) {
            var scored = Number(g && g.ishomegoal) === 1 ? 'home' : 'visitor';
            var tracked = Boolean(tracking && P
                && P.eventsFor(events, P.scoreKey(home, visitor)).length > 0);
            var known = receiving !== null && Number(g.num) === expected;

            out.push({
                scored: scored,
                receiving: known ? receiving : null,
                kind: !known ? null : (scored === receiving ? 'hold' : 'break'),
                tracked: tracked,
                clean: tracked ? !P.defenceTouched(events, home, visitor) : false,
                turnovers: tracked ? P.turnovers(events, home, visitor) : null,
                callahan: Number(g && g.iscallahan) === 1,
                time: num(g && g.time),
                home: home,
                visitor: visitor
            });

            // Whoever conceded receives next. True of every point with no
            // possession data at all, which is why holds and breaks come out of
            // the goal list alone once the first receiver is known.
            receiving = other(scored);
            expected = Number(g.num) + 1;
            if (scored === 'home') { home += 1; } else { visitor += 1; }
        });

        return out;
    }

    /** How many entries at the END of the list satisfy `ok`. */
    function runOf(list, ok) {
        var n = 0;
        for (var i = list.length - 1; i >= 0; i -= 1) {
            if (!ok(list[i])) { break; }
            n += 1;
        }
        return n;
    }

    /** The name to put on air for a side. */
    function nameOf(ctx, side) {
        var names = ctx.names || {};
        return (side === 'home' ? names.home : names.away)
            || (side === 'home' ? 'Home' : 'Away');
    }

    /** "1 break" / "2 breaks", because a graphic saying "1 breaks" is a bug. */
    function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

    /**
     * Every fact that is true right now, ranked, highest first.
     *
     * @param ctx {goals, gameevents, pool, declared, startingOffence, names}
     * @return an array of {id, rank, text, tracked}
     */
    function candidates(ctx) {
        ctx = ctx || {};
        var MIN = thresholds(ctx.thresholds);
        var pts = points(ctx);
        var found = [];
        /**
         * One entry per id, keeping the strongest.
         *
         * Four of the facts below are asked of both sides, so without this the
         * list can hold the same id twice with different text — and then a pin
         * on that id locks to whichever side happened to be checked first,
         * which is always home. `weight` is what makes "strongest" mean
         * something per fact: more chances, a bigger deficit, more clean holds.
         */
        var add = function (id, text, tracked, weight) {
            var entry = {
                id: id,
                rank: RANK[id],
                text: text,
                tracked: Boolean(tracked),
                weight: Number(weight) || 0
            };
            for (var i = 0; i < found.length; i += 1) {
                if (found[i].id === id) {
                    if (entry.weight > found[i].weight) { found[i] = entry; }
                    return;
                }
            }
            found.push(entry);
        };

        if (pts.length === 0) { return found; }

        var last = pts[pts.length - 1];
        var events = Array.isArray(ctx.gameevents) ? ctx.gameevents : [];
        var final = {
            home: last.home + (last.scored === 'home' ? 1 : 0),
            visitor: last.visitor + (last.scored === 'visitor' ? 1 : 0)
        };

        // A callahan: rare enough to outrank everything, and a flag on the goal
        // rather than anything derived.
        if (last.callahan) {
            add('callahan', 'Callahan — ' + nameOf(ctx, last.scored));
        }

        // Consecutive points by one side — the plainest fact on the list, and
        // the one that needs nothing but the order of the goals.
        var run = runOf(pts, function (p) { return p.scored === last.scored; });
        if (run >= MIN.run) {
            add('run', nameOf(ctx, last.scored) + ': ' + run + ' in a row');
        }

        if (last.kind !== null) {
            // Breaks in a row: a different fact from the run above, because a
            // side can score five straight of which only two were breaks.
            var breakRun = runOf(pts, function (p) {
                return p.scored === last.scored && p.kind === 'break';
            });
            if (breakRun >= MIN.breakRun) {
                add('breakrun', nameOf(ctx, last.scored) + ': '
                    + plural(breakRun, 'break', 'breaks') + ' in a row');
            }

            // Both sides holding, point after point. Not about one team: it is
            // the shape of a game nobody is breaking.
            var holdRun = runOf(pts, function (p) { return p.kind === 'hold'; });
            if (holdRun >= MIN.holdRun) {
                add('holdrun', holdRun + ' straight holds');
            }

            var breaks = { home: 0, visitor: 0 };
            pts.forEach(function (p) {
                if (p.kind === 'break') { breaks[p.scored] += 1; }
            });
            if (breaks.home !== breaks.visitor) {
                var ahead = breaks.home > breaks.visitor ? 'home' : 'visitor';
                add('breaks', 'Breaks: ' + nameOf(ctx, ahead) + ' ' + breaks[ahead]
                    + ', ' + nameOf(ctx, other(ahead)) + ' ' + breaks[other(ahead)]);
            }
        }

        /**
         * Clean offensive points in a row.
         *
         * The one from the reference shot, and the one that is easiest to build
         * wrongly. "Five straight clean O points" is about a team's OWN
         * offensive points — the points they received — and not about five
         * consecutive goals. Counting consecutive goals instead makes the fact
         * unreachable in any game where the teams are trading points, which is
         * most of them, and reachable only during a scoring run, which is when
         * it is least interesting.
         *
         * And the sharpest refusal in the module: the run stops at the first of
         * that team's O points nobody tracked, because an untracked point is
         * unknown rather than clean. So this appears only where somebody
         * watched the disc through the whole run.
         */
        var cleanRun = { home: 0, visitor: 0 };
        ['home', 'visitor'].forEach(function (side) {
            var theirs = pts.filter(function (p) { return p.receiving === side; });
            cleanRun[side] = runOf(theirs, function (p) {
                return p.scored === side && p.tracked && p.clean;
            });
        });
        // One fact, not two: the longer run, and on a tie the side that scored
        // last, because that is the one the picture is currently about.
        var runSide = cleanRun.home === cleanRun.visitor
            ? last.scored
            : (cleanRun.home > cleanRun.visitor ? 'home' : 'visitor');
        if (cleanRun[runSide] >= MIN.cleanRun) {
            add('cleanrun', nameOf(ctx, runSide) + ': ' + cleanRun[runSide]
                + ' straight clean O points', true);
        }

        var cleanHolds = { home: 0, visitor: 0 };
        pts.forEach(function (p) {
            if (p.tracked && p.clean && p.kind === 'hold') { cleanHolds[p.scored] += 1; }
        });
        ['home', 'visitor'].forEach(function (side) {
            // Suppressed where the run above already says it, better.
            var saidBetter = side === runSide && cleanRun[runSide] >= MIN.cleanRun;
            if (cleanHolds[side] >= MIN.cleanHolds && !saidBetter) {
                add('cleanholds', nameOf(ctx, side) + ': ' + cleanHolds[side]
                    + ' clean holds', true, cleanHolds[side]);
            }
        });

        // Turnovers in the point that just ENDED. The scoreboard's ribbon
        // already counts the point in progress; this is the one that finished,
        // which is the version worth a sentence rather than a number.
        if (last.tracked && last.turnovers >= MIN.turnovers) {
            add('turnoverpoint', 'That point: ' + last.turnovers + ' turnovers', true);
        }

        // Break chances taken. "Two breaks" says what happened; "two from five"
        // says how the game is actually going.
        var P = possession();
        if (P && ctx.declared && ctx.declared.enabled && last.kind !== null) {
            var conv = P.conversion(ctx.declared.events || [], ctx.goals || [],
                ctx.startingOffence);
            ['home', 'visitor'].forEach(function (side) {
                if (conv[side] && conv[side].chances >= MIN.chances) {
                    add('conversion', nameOf(ctx, side) + ': ' + conv[side].converted
                        + ' from ' + plural(conv[side].chances, 'break chance', 'break chances'),
                        true, conv[side].chances);
                }
            });
        }

        // Back from a deficit, measured against the largest gap a side has
        // faced — so it fires on drawing level and still means something after.
        ['home', 'visitor'].forEach(function (side) {
            var worst = 0;
            pts.forEach(function (p) {
                var gap = side === 'home' ? p.visitor - p.home : p.home - p.visitor;
                if (gap > worst) { worst = gap; }
            });
            var now = side === 'home'
                ? final.home - final.visitor
                : final.visitor - final.home;
            if (worst >= MIN.comeback && now >= 0) {
                add('comeback', nameOf(ctx, side) + ': back from ' + worst + ' down',
                    false, worst);
            }
        });

        /**
         * The second half, where there is one.
         *
         * `half_cap` is the only halftime marker UltiOrganizer has — there is no
         * explicit halftime event (`STUDIO.md` §10.2) — and it carries a time,
         * so the split needs goal times to be real. Where a tournament recorded
         * none, every goal reads as time 0 and the fact is omitted rather than
         * being computed against a clock that was never running.
         */
        var halfTime = null;
        events.forEach(function (e) {
            if (e && e.type === 'half_cap') {
                var t = num(e.time);
                if (t !== null && (halfTime === null || t > halfTime)) { halfTime = t; }
            }
        });
        var timed = pts.some(function (p) { return p.time !== null && p.time > 0; });
        if (halfTime !== null && halfTime > 0 && timed) {
            var since = { home: 0, visitor: 0 };
            pts.forEach(function (p) {
                if (p.time !== null && p.time > halfTime) { since[p.scored] += 1; }
            });
            if (since.home + since.visitor >= MIN.sinceHalf && since.home !== since.visitor) {
                var better = since.home > since.visitor ? 'home' : 'visitor';
                add('sincehalf', nameOf(ctx, better) + ': ' + since[better] + '-'
                    + since[other(better)] + ' since half');
            }
        }

        /**
         * The player who just scored, and what they have done in this game.
         *
         * Goals and assists are on the goal rows themselves — `scorer`,
         * `scorernum`, `scorerfirstname`, `scorerlastname` and the assist
         * equivalents — so this needs no roster fetch and no tournament totals.
         *
         * Two absences are load-bearing. `scorer` is frequently null: match
         * control deliberately does not collect who scored (`MATCHCONTROL.md`),
         * and plenty of hosted scoresheets are kept without it, so the fact is
         * omitted rather than attributed to nobody. And BLOCKS are not here at
         * all: `deftotal` is a tournament total on the roster row, for
         * completed games, only where `ShowDefenseStats` is on — there is no
         * per-game block list in any payload (`STUDIO.md` §3.4), so "blocks in
         * this game" cannot be said by anyone.
         */
        var lastGoal = (ctx.goals || []).slice().sort(function (a, b) {
            return Number(a.num) - Number(b.num);
        }).pop();
        if (lastGoal && lastGoal.scorer) {
            var tally = { goals: 0, assists: 0 };
            (ctx.goals || []).forEach(function (g) {
                if (g.scorer && g.scorer === lastGoal.scorer) { tally.goals += 1; }
                if (g.assist && g.assist === lastGoal.scorer) { tally.assists += 1; }
            });
            var who = [lastGoal.scorerfirstname, lastGoal.scorerlastname]
                .filter(Boolean).join(' ')
                || (lastGoal.scorernum ? '#' + lastGoal.scorernum : '');
            var worth = tally.goals >= MIN.playerGoals
                || tally.goals + tally.assists >= MIN.playerPoints;
            if (who && worth) {
                var parts = [plural(tally.goals, 'goal', 'goals')];
                if (tally.assists > 0) {
                    parts.push(plural(tally.assists, 'assist', 'assists'));
                }
                add('player', who + ': ' + parts.join(', ') + ' this game');
            }
        }

        // No timeouts left, which changes how the last points can be played.
        // The allowance and the half reset both belong to `shared/timeouts.js`.
        var T = timeouts();
        if (T) {
            var spent = ['home', 'visitor'].filter(function (side) {
                var left = T.remaining(ctx.pool, events, side === 'home');
                // Null is a pool that gives no timeouts at all, which is not the
                // same as a side that has spent them.
                return left && left.used > 0 && left.remaining === 0;
            });
            if (spent.length === 2) {
                // Both, said once. Two facts saying the same thing about
                // different teams would have the ranking pick one and drop the
                // other, which is a worse sentence than the true one.
                add('notimeouts', 'Neither team has a timeout left');
            } else if (spent.length === 1) {
                add('notimeouts', nameOf(ctx, spent[0]) + ': no timeouts left');
            }
        }

        return found.sort(function (a, b) { return b.rank - a.rank; });
    }

    /**
     * The one to put on air, or null.
     *
     * @param pin an id the operator chose to hold. It wins while it is still
     *            true and yields to the ranking the moment it is not: a pinned
     *            fact that has stopped being true must never stay on screen,
     *            which is the same defect as a tab outliving its point.
     */
    function best(ctx, pin) {
        var all = candidates(ctx);
        if (pin) {
            for (var i = 0; i < all.length; i += 1) {
                if (all[i].id === pin) { return all[i]; }
            }
        }
        return all.length ? all[0] : null;
    }

    return {
        candidates: candidates,
        best: best,
        // The per-point walk, for callers that want the points rather than a
        // sentence about them -- the commentary desk joins it to the recorded
        // lines to work out which unit a player belongs to.
        points: points,
        thresholds: thresholds,
        MIN: MIN,
        RANK: RANK
    };
}));
