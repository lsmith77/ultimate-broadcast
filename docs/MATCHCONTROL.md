# Score and clock — where they are kept, and by whom

Built, and in use as of this document's last revision. §§1–9 were written before any of it existed and are the reasoning; what follows immediately is what actually shipped, because the reasoning turned out to be right about the shape and wrong about the priority.

## 0. What exists

| | |
|---|---|
| `shared/score.php` | the store: goals keyed by the point they complete, the clock as UltiOrganizer's own three fields, and the nominated code in a separate `.private.json` |
| `score.php` | the endpoint. Reads are open; writes need the administrator session or the code an operator nominated |
| `shared/score-client.js` | the outbox: every press applied locally first, queued, retried |
| `matchcontrol.php`, at **`/k/<game>`** | the phone surface — two presses, the score, a clock, an undo, a sync state |
| The **More** panel on that phone | possession, timeouts, an injury stoppage and the first point's ratio — behind a toggle, because they are not the job |
| `shared/score-source.js` | puts a locally kept score into the payload every renderer already reads |
| The Studio's **Match control** bar | the scorekeeping code — nominate, generate, revoke — beside the **Score from** switch (`upstream` ⇄ `match control`), per game, administrator only |

The reason it was built was not standalone mode. It was latency: Live! serves a single game with a flat 30-second cache, so a goal is on air somewhere between at once and half a minute late and no polling rate improves that — the copy being polled is the stale one. Measured through the switch, a goal reaches the scoreboard in **about a second**.

The code and the switch are one decision, in one place. They were not: the switch shipped in the Studio and the code could only be nominated over the API, so `matchcontrol.php` told a scorekeeper to "ask the operator to set one" and the operator had nothing to set it with. The only person who could keep score was an administrator, who may write without a code — which is not a hand-off. Found by trying to use the first real installation, and fixed by putting both controls in one bar: switching the source to a store nobody can write is how a scoreboard freezes on 0-0, so the bar says "no code set" until one is.

A press carries the time it was pressed. Not the time it arrived — which over a bad connection is a different second, and for the clock a materially different one: `timer_start` is absolute, so a start delivered after a five-minute outage would run the rest of the game five minutes short, on air. The queue also survives a reload, because a bad connection is exactly when somebody pulls to refresh to see whether that helps.

The rest of what only somebody at the pitch knows. Possession, an injury stoppage and the opening ratio were reachable from the Studio and the commentary desk and nowhere else — while the person actually watching the game closely enough to press a button per point was holding a phone with two buttons on it. They are the same kind of fact as the score: true about the game and recorded nowhere upstream. So the **scorekeeping code now opens them too** (`possession.php`), rather than asking one person to carry two five-character codes, and it grants nothing upward — switching the mode on, nominating a code and changing the game stay the operator's.

Timeouts are recorded here now, and were recorded nowhere before. UltiOrganizer keeps them as game events, so a standalone installation had no way to note one and the allowance drawn on air never moved however many were called. They live in the score store, numbered **per side** by the same rule that makes a goal safe to retry — "home's second timeout" written twice is one timeout — and `shared/score-source.js` puts them into `gameevents`, which is the shape `shared/timeouts.js` already counts from. Switching the scoreboard's source switches the timeouts with it, because a board showing this project's score beside Live!'s timeouts would be two answers about one game.

Possession is offence and defence, not home and away. The store records which SIDE has the disc relative to the point being played, and whose offence it is changes at every goal without anybody re-declaring it. Naming the teams there would have asked the scorekeeper for the wrong fact — and did, in the first version of this panel.

**The panel is behind a toggle.** The two big buttons are the job; eight more controls in front of them is how somebody presses the wrong one at 13-12. Whoever wants it opens it once and the phone remembers.

The phone says when it is not the source. A banner across the top, shown whether or not that phone may write. The failure it prevents is somebody keeping a whole game's score carefully into a store nothing reads, which looks exactly like working until somebody watches the broadcast.

**What is deliberately not collected:** who scored and who assisted. A scorekeeper on a phone is not going to pick two players out of a squad per point, so the goals carry the point number and the side and nothing else — and `scorer` is *omitted* rather than sent as zero, so a top-scorer card shows nothing instead of a whole squad on nought.

**Still open:** delivering the log upstream. UltiOrganizer's API is read-only — six endpoints, GET only — so there is nowhere to send it yet. The log is already the outbox: append-only, ordered, and idempotent by the point-number rule, which is the shape a push API would want. [`UPSTREAM.md`](UPSTREAM.md) records that as shared ground.

---

It arose from the standalone question ([`STANDALONE.md`](STANDALONE.md)), where nothing keeps the score, but it is **not standalone-specific**: hosted mode has the same problem wearing different clothes, and §8 is about that.

## 0a. Keeping score with no signal at all — **built**

[`OFFLINE.md`](OFFLINE.md) is the practical version: what to tell somebody at a pitch, and what to do with what they recorded. This section is why it is built this way.

The outbox survived a reload, but only if the page could load, and with no signal a reload produced a dead page — at the moment somebody refreshes to see whether that helps. Four pieces close that.

| | |
|---|---|
| **A manifest and a service worker** (`manifest.php`, `sw.js`) | Add `/k/<game>` to a home screen and it opens as an app, with no browser chrome and no network. Only match control gets either; see the scope rule below |
| **A snapshot of the last server answer** (`shared/score-client.js`) | The outbox holds what has not been sent and drops each item as it lands. Without a snapshot, a phone that synced a few points, lost signal and was reloaded came back with the server's answer gone — it was in memory — and only the unsent tail, so the score jumped backwards |
| **A list of games on the device** (`shared/score-archive.js`, `/k/`) | Where a home-screen icon lands. Every game the phone has opened, its score, and how many presses it is still holding. Opening it flushes every game that owes something, and a row can be removed once it owes nothing — which takes its scorekeeping code with it |
| **Export** | One game or all of them, as JSON, from a page that may be offline. Synced and unsent parts separately, and each press still names the point it completes, so the file can be replayed into a store |

**Preparing at home already worked:** author the event and open each game once while there is signal, which also fills the cache. The phone can then be dark all day.

**The scope rule decided two other things.** A service worker's scope is a path, and every other surface here is on air. A stale cached scoreboard served to a switcher is the failure this project guards against hardest, so the worker is scoped to `/k/` in both modes and refuses air-facing URLs by name as well. Two consequences:

- Hosted, `sw.js` is served from `live/overlays/` and cannot claim a prefix above itself, so `.htaccess` sends `Service-Worker-Allowed: /k/` with that one file. Scoping it to `/` would put a worker in front of UltiOrganizer's own pages.
- Standalone, `app.php` serves match control in place at `/k/` instead of redirecting to the long form as every other short URL does. Redirected, the phone lands on `/app.php`, which is also the scoreboard's path, and a worker for the phone would be a worker for the broadcast. The cost is one page reading `$_GET` instead of `filter_input`, noted at that call site.

**The page fills the cache, not the worker.** A first visit loads before any worker controls it, so a worker-only cache would still be empty after the one visit somebody was told to make while they had signal. The page stores its own shell on load; the worker reads it back.

**What it does not do.** It does not sync to UltiOrganizer: that API is read-only, six GET endpoints, so a score kept here stays parallel to the tournament record. "Upload when home" means this project's own store, plus the file.

## 0b. Conflicts: what happens today, case by case

Offline makes conflicts possible, so this is what happens in each case. **The model is not a merge.** One store per game is the authority, every message names the thing it describes rather than a change to it, and where two answers exist the store's wins. That is a policy rather than a limitation: two people keeping divergent scores is worse than one of them being corrected, and a merge would hide the case somebody needs to sort out.

| case | what happens | is anybody told? |
|---|---|---|
| **A press that has not been sent** | Queued, applied on screen, retried until it lands. Not a conflict at all | yes — an unsent count on the chip |
| **The same press sent twice** | A goal names the point it completes, so the store keeps one and reports the second as already recorded | nothing to tell: nothing was lost |
| **Two scorekeepers, and the other one got there first** | This phone's point 9 arrives after theirs. The store keeps theirs; this phone's press is **dropped** and its screen converges on the store's answer at the next read | **yes** — a notice on the game, and the press is written down as not accepted, which the list of games shows afterwards and the export carries |
| **This phone is out of step** (it was dark for several points) | The head of the queue is numbered from a score that no longer matches, so the store answers 409, the client drops that message and re-reads rather than renumbering it. Renumbering would invent a second goal for a point the other scorekeeper may already have recorded | **yes** — same notice, same record |
| **An undo that arrives late** | An undo names the point it takes back and only applies if that is still the last point. Arriving after the game has moved on, it does nothing | **no** — this is the known gap below |
| **A timeout** | Numbered per side, so the same timeout twice is one timeout | nothing to tell |
| **The clock** | `timer_start` is absolute and starting a running clock is not a restart, so a replayed start is harmless. Two people starting *different* clocks is last-write-wins, and the drift is visible on air | no |
| **A line for a point** (the commentary desk) | Last write wins, per team, per point. Two desks confirming different sevens for one point leaves the later one | no |
| **An exported file, re-imported later** | Not built. When it is, the point-number rule means an import merges by point number and the store's own goals win | — |

**Why "nothing left to send" is not "everything got through".** Three paths empty the outbox and only one of them is delivery: a conflict and any other refusal both drop the message, because leaving it would block every good press behind it. A phone that counted only its queue would therefore report a game as sent over a press that was never stored anywhere. So a dropped press is written down — what it was, which point it named, and why it was refused — and the list of games reports it apart from the queue, because finding signal fixes one and not the other. The export carries them for the same reason: nothing else records that the press existed.

**Three limitations:**

1. **A dropped press is kept, not reconciled.** The phone shows that it happened and the export carries it, but there is no "your version / their version" screen and no way to resubmit — somebody reads it and decides.
2. **A late undo is silent.** It is the one message that can do nothing without saying so, and the one most likely to be queued during an outage.
3. **Nothing detects two scorekeepers in advance.** The conflict surfaces on the first press that collides, which may be several points in.

They are listed in the order they should be fixed.

### Where this goes if it needs to be better

- **Say which points were refused, on the game screen.** The device keeps each dropped press with its point number, and the list of games counts them; the game itself still only shows the notice. "The other desk had points 9, 10 and 11" is the same data, one screen further in.
- **Make a late undo speak.** The store already distinguishes applied from not applied (`applied: false`, which is what the notice above is built on); the undo path does not surface it yet.
- **Say who else is writing, before the collision.** The possession store already counts connected clients for the commentary desk. The same count here would let a phone say "another phone is also keeping this game" when the second one opens it, rather than at the first contested point.
- **A replication protocol — PouchDB or similar.** It solves a problem this system does not have. [`RELAY.md`](RELAY.md) §7a has the full reasoning: there is nothing CouchDB-shaped to replicate to (PHP, files, no database), and its conflict model — keep both revisions, ask the application to choose — is the opposite of the policy above. The decision point is not offline work; it is many writers producing many documents that have to merge. Two things would cross it: per-throw stats collection (§10a), and device-to-device sync with no server in the middle. At that point IndexedDB is the floor and a replication protocol pays for itself.

## 1. The question

Standalone mode has to get the score and the clock from somewhere, and "somewhere" is a person with a device. Which person, and which device, changes with how many people showed up — and a broadcast crew is one, two, three or four people depending on the day, the round and who did not turn up.

The wrong way to answer this is to pick a crew size and design for it. Every surface in this project is already shaped by *whose job it is*, and that is the question that survives a crew of any size.

## 2. The principle is already settled

`STUDIO.md` §3.5, on why possession declaration belongs with the scorekeeper rather than the operator, states the axis plainly:

> *the operator's attention is not spare capacity.* Their job is directing — choosing graphics, timing them, watching the programme output. Adding "press a button on every turnover" competes with that job rather than riding along with it, and it competes hardest exactly when the broadcast matters most.

and scores the options on one row that matters more than the others:

> **Competes with the capturer's main job** — yes / no, *it is their main job*.

So the question is not "where is there room on a screen". It is **whose eyes are already on the thing being recorded**.

For score and clock that answer is unusually clear: *whoever is watching the game*. Which is the commentator, the scorekeeper, and — only incidentally, and least reliably — the operator.

## 3. Scoring is cheap to do and expensive to get wrong

Worth stating precisely, because the intuition is backwards.

**The volume is tiny.** A game to 15 is about 30 goals over 80–100 minutes: one input every three minutes. The clock is start, halftime, restart, and the odd stoppage — under a dozen presses a game. Compare that with the surfaces this project already asks people to drive: line selection is seven picks per point (~200 a game), and possession is several presses per point.

The consequence is the largest on the page. The score is the one number every viewer independently knows, and a wrong one is not a degraded graphic but a visibly false one. It is also the input with the widest blast radius internally: the `goals` array drives the point number, the ABBA slot, hold/break, the progression card and the per-point strip.

So this is a **low-frequency, high-consequence** input, and that combination has design consequences:

- It can ride along with somebody else's job without competing with it — unlike possession, and unlike line selection.
- It cannot be allowed to be entered twice.
- It is worth interrupting somebody to correct, and therefore worth making correctable in one press.

## 4. The real crux: a goal is not an increment

This is the part that decides the design, and it is easy to miss until two people are pressing buttons.

**`+1` is not safe to send.** Line selection is last-write-wins and possession presses are dropped when they change nothing, because both are *statements of current state*. A goal button that means "add one" is a statement of a **delta**, and two people pressing it for the same goal produces 2–0 from one point. So does one person pressing it twice on a laggy connection, which is the likelier case.

So a goal write must state the result, not the change. The store already works this way everywhere else: possession events are filed under the score they were made at (`score: "9-6"`), notes skip a write that would change nothing, and the line store now does too. A goal should carry the point it creates — "home scored point 10, making it 6–4" — and the store should accept it only if point 10 is the next one to exist.

That single rule makes the whole crew question tractable:

- Two people pressing for the same goal: the second is a no-op, not a second goal.
- A retry after a timeout: safe, by construction.
- A phone that was asleep and comes back: it can send what it thinks and be told no.

It also means **more than one surface can hold the button without anybody having to own it**, which is what makes the crew matrix below work.

## 5. Decided for now: one phone-optimised surface

A separate page, built for a phone, and nothing embedded anywhere. That is the v1, and the rest of this section is why that is a scoping decision rather than a limitation, and what has to be true now so that it stays one.

`/m/<game>` — its own URL, following the convention the root `.htaccess` already states for `/c/`: *"a different job and a different person: /s/ is the operator deciding what goes on air, /c/ is the person talking over it."* Keeping score is a third job and often a third person.

**What is on it:** the two teams with a large press each, the current score, an undo, and a clock with start / pause / half. A visible sync state. Nothing else — every feature added here competes with the one job the surface exists for.

Phone-optimised is the requirement, not the fallback. The person keeping score is frequently standing at the pitch rather than sitting at a desk: behind a camera, beside the scoresheet, away from the laptop. Designing for the phone first and letting it work on a laptop is the right way round; the reverse produces a page with buttons too small to press without looking.

A second device is an acceptable ask, and that is what makes this simple. Even a solo crew can be assumed to have a laptop and a phone. Without that assumption, v1 would have to be embedded somewhere, and then the question of *where* has to be answered for every crew size before anything can ship.

**Not called "Scorekeeper".** UltiOrganizer already ships `scorekeeper/`, `timekeeper/` and `spiritkeeper/`, and in hosted mode its Scorekeeper is the authority this must not pretend to be (§8).

### What this defers, and what it must not foreclose

Bringing score to the other two surfaces is the obvious later move — it would let a two-person crew keep score without a third device, and give any crew a fallback when the phone dies. Deferred, not rejected.

And when it comes, it is probably keys rather than a panel. The commentator page is already keyboard-driven and has a reference dialog listing every shortcut: digits type a shirt number, `L`/`R` open a sheet, `O`/`D`/`I`/`U` drive possession. Two more keys cost no screen space on a page whose play view is deliberately ordered so nothing scrolls during a point — where a panel would cost the field rows.

That is a genuinely lighter change than embedding a control, but it carries a risk a panel does not, and the project has already been bitten by the general form of it. The quick-card design records retiring a letter-row mapping because *"it was a single blind keypress and it was wrong"* — for a different reason, but the caution transfers: **the score is the highest-consequence thing on the page and a mis-press reaches air.** So if keys are added:

- **Opt-in**, not on by default. A commentator who is not keeping score should not have live score keys under their fingers.
- **Undo in one press**, which §3 already requires of every mounting.
- **Pick keys that are not neighbours of anything destructive**, and add them to the Keys dialog in the same change — `AGENTS.md` now requires that pairing precisely because a gesture missing from that dialog does not exist as far as a user is concerned.

Deferring it is free if, and only if, §4 holds. Because a goal is written as *the point it creates* rather than as `+1`, a second surface can be added later without touching the store and without any risk of double entry: whichever press lands first creates point 10, and anything else claiming point 10 becomes a no-op. If instead v1 ships a `+1` endpoint — which is the tempting shortcut when there is only one surface and it obviously cannot race itself — then embedding later means changing the write protocol, migrating whatever has been stored, and re-testing every consumer.

So the rule to hold now, while it costs nothing:

> **Write the result, not the change — even though only one surface can currently send it.** The single-surface simplification is in the UI, never in the store.

Two smaller consequences of the same discipline:

- **Build it as a module with a page around it**, not as a page. If the control is a self-contained thing the page mounts, embedding later is a second caller rather than a second implementation — the same shape as `render()` having a post-production caller.
- **Gate it by capability, not by page.** The room code already grants a commentator exactly one power without granting admin, and `possession.php` documents the two doors that make that safe. Score and clock become another grant of the same kind. That costs nothing extra now and is the whole mechanism later.

## 6. The crew, at each size

With one phone surface, the allocation question becomes simply *who is holding the phone*.

| People | Who holds the phone | Devices | Notes |
|---|---|---|---|
| **1** | The operator | Laptop + phone | The phone matters most here: a solo operator is away from the desk for much of the game. |
| **2** | The **commentator** | Two laptops + a phone | Their job is already watching every point; one press every three minutes rides along, where the operator's job does not (§2). Costs this crew a third device, which is the clearest price of the v1 scoping — and the case embedding would later remove. |
| **3** | A dedicated keeper, or a team volunteer at the pitch | Three + phone | Frees the commentator's hands for lines and possession, the high-frequency inputs. |
| **4** | A dedicated keeper | Four + phone | Operator, two commentators, keeper. |

The two-person case is the load-bearing one, because it is the most common and because the obvious allocation is wrong. Giving score to the operator "because they have the admin login" optimises for the permission model instead of for attention.

The keeper is often not broadcast crew at all. At three or more the natural candidate is the person already keeping the paper scoresheet. They should be able to hold the score capability without being able to touch what is on air — exactly the shape the room code already has, and the strongest argument for capability grants over a single admin password.

## 7. Timeouts — the desk now shows them; the Studio deliberately does not

Raised while writing this. The answer was "shown on air and on neither desk", and **the commentator half is now built**; the Studio half was considered and declined.

**What the scoreboard had.** It derives timeouts properly — the allowance from `poolinfo.timeouts`, the count from `gameevents` entries of type `timeout` carrying `ishome`, with `timeoutsper: "half"` resetting at the `half_cap` event. The derivation now lives in `shared/timeouts.js` and the scoreboard calls it, because a derivation used twice is a derivation about to be *written* twice — which is how the gender ratio came to be printed two different ways before `shared/ratio.js` existed.

**What the commentator page now has.** Ticks beside each team name in the header, filled while unspent, with the count in words in the title and the period it counts: *"Valley Vipers: 1 of 2 left this half."* It is the kind of fact this page exists to hold — known, derivable, and not holdable in your head across two teams and a half, precisely because the allowance resets at the break.

**Why not the Studio.** Two reasons, and the second is the real one.

The Studio holds **no game payload at all** — no `poolinfo`, no `gameevents`, no `game_result` — and the games *list* it does read carries none of them. Adding a count would mean introducing a per-game detail fetch to a page that has never needed one, which is a new poll and a new dependency for one number.

And the operator can already see it. **They are watching the programme output, and the programme output is the scoreboard, which shows the ticks.** Spending operator attention and a network poll to duplicate something already on their screen is the §2 principle failing in both directions at once.

That is not an assumption made for this decision. The Studio already states it twice and has built on it both times: possession is `O` and `D` on the keyboard because *"an operator is watching the programme output, not this page"*, and the off-air flash is `aria-live` because *"an operator watching the program monitor is not looking at this bar"*. Two existing features exist in the shape they do precisely because the operator's eyes are on the output rather than on the Studio.

What it does rest on is a fact about the room: that the operator has a programme monitor or a switcher multiview. The Studio has no preview of its own — no iframe, no thumbnail — so an operator working from the Studio page alone would see nothing. That is a fair assumption for a switcher setup and a poor one for somebody running a laptop and a webcam, which is the deployment standalone mode is aimed at (`STANDALONE.md` §6). If the Studio ever grows a preview, the timeouts arrive with it for free and this section is moot.

If a use emerges that the output does not serve — a warning that a team is about to run out, say — revisit it, but not before.

One thing this makes plain about standalone. Timeouts are `gameevents`, so in standalone nothing would produce them and both surfaces would correctly show nothing. Taking a timeout is therefore a fourth thing match control would record, alongside score, clock and half — and an argument for the surface being about *the match* rather than only about score and clock.

## 8. Hosted mode has the same problem, and one real gap

In hosted mode score and clock come from UltiOrganizer's Scorekeeper, driven by a tournament volunteer at the pitch. That separation is healthy and should not be dissolved: **the scoresheet is the tournament's record and the overlay is a picture of it.** Standalone recreates the picture; it must not quietly become the record for a game the tournament also records elsewhere.

That prediction was half wrong, and what shipped is worth stating plainly. Hosted mode *does* take a second score input, because latency made it worth one: an operator can switch a game's scoreboard to match control and the overlay then reads this score instead of Live!'s (§0). What has not changed is the direction of travel —

> **Nothing here is ever written back to UltiOrganizer or Live!.** Their API is read-only, six endpoints and GET only, so there is nowhere to send a goal even if we wanted to. A game kept in match control is a **parallel** score that the overlay can be pointed at; UO's own scoresheet remains whatever UO's scorekeeper entered, and the tournament's record is unaffected either way.

So the separation the section below argues for survives, but as a fact about *storage* rather than about *display*:

| | standalone | hosted |
|---|---|---|
| Score, on the overlay | authoritative | authoritative **when an operator switches to it**, otherwise Live!'s |
| Score, in the tournament's record | this is the record | **never touched** — UO's scoresheet is the record and this cannot write to it |
| Clock | authoritative | same switch, and see the gap below for when UO has none |

The exception is worth building, because the gap is already documented. `STUDIO.md` §10.3 records it: *"`timer_start` is only written by Scorekeeper. A scorekeeper who never starts the clock means no clock on air, and the overlay silently falls back to a status word. Worth a pre-game checklist item."*

A pre-game checklist item is a hope. The same surface, offering a **local clock only when UO has none**, turns a silent failure into a one-press recovery — and it corrupts nothing upstream, because it writes to overlay-local state and never to UltiOrganizer. That makes match control worth building for hosted mode even if standalone never ships.

It should say which it is doing, in words, on the screen. A clock that is the overlay's own and looks identical to the Scorekeeper's is a graphic asserting something it does not know.

## 9. Failure modes to design for, not discover

- **Venue wi-fi.** A phone at the pitch will lose the network. The surface must show the score it believes locally, retry, and say plainly when it is not synced — the notes box already has the pattern and the wording: *"Not shared — kept on this screen."* Silence is the one unacceptable response.
- **Double entry.** Solved structurally by §4, not by discipline or by locking a surface to one person.
- **The wrong goal.** Undo must be one press and must be reachable while the next point is being played. The possession store already scopes corrections to the current point for exactly this reason, and the same reasoning applies: correcting the point being played changes a number nobody has read out; correcting an earlier one silently rewrites what a commentator already said.
- **A sleeping phone.** It comes back with a stale view. It must reconcile to the store rather than push what it remembers — the same rule as every declared value: shared wins, local only fills a gap.
- **Nobody pressing anything.** The overlay must be able to say the clock is not running rather than draw a stopped one, which is the honest version of the §7 gap.

## 10. Undo, and the keys it lives under — logged, not settled

Surveyed rather than designed, so the state is written down before somebody assumes it is consistent.

Every surface has an undo, and they are not the same thing. The Studio's card undo restores a snapshot; its possession undo removes the last press; match control's removes the last point. Three actions, one word. Each is right for its own risk, and nothing shares an implementation.

**The two desks already share a key set** — `O` and `D` for possession, `I` for a stoppage, `U` — and both document it in a **Keys** dialog. But `U` does two different things:

| | Studio | Commentary desk |
|---|---|---|
| `U` | undoes the last press **directly** | opens the possession **log** |

That is deliberate, and the reason survives examination: the operator is looking at the possession bar with its press count, while the commentator is looking at the pitch. A blind key that deletes is not the same risk as a button beside a visible number. The desk's log now opens with **"↶ Undo last press" focused**, so the gesture is `U` then `Enter` — as quick as the Studio's, without becoming a blind delete.

So the rule that actually holds is not "same key, same action". It is *same key, same intent — undo — with the confirmation matched to how blind the press is.* That is a defensible rule and it was nowhere written down; it was only recoverable by reading two comment blocks in two files.

**Known gaps, none urgent:**

- **Match control has no keyboard at all.** Reasonable for a phone, and it is the intended device — but a tablet with a keyboard attached is a plausible way to drive it, and `U` should undo there too when that happens.
- **Two surfaces have no undo.** Prepared notes fall back to the browser's own textarea undo; line selection has none, because re-clicking a player toggles them off, which covers most of it and is not the same thing.
- **Nothing shares an undo implementation**, so a future fourth surface will invent a fourth one.

## 10a. Where this could go: detailed stats, or somebody else's app

**A direction, nothing built.** Two large presses on a phone are the floor, not the ceiling. The same surface with more inputs is a stats collection tool, for one team or both, serving the commentary desk during the game and a coach afterwards — and coaches are the audience that pays for this kind of software.

**What the ground already supports.** The store is an append-only log of events, each naming the thing it describes rather than a delta, which is what makes it safe offline. Turns, throws, drops and blocks are the same shape with a bigger alphabet. The line history the commentary desk records ([`PLAN.md`](PLAN.md) §4c) is the other half a coach wants: who was on for what.

**What it would cost.** Not storage, though the data volume does cross the line where `localStorage` and a JSON file stop being adequate ([`RELAY.md`](RELAY.md) §7a). The cost is **attention at the pitch**. Every surface here is shaped by whose job it is, and per-throw capture is a full-time job for somebody doing nothing else — the spotter argument in [`COMMENTATOR.md`](COMMENTATOR.md) §6a, one level harder. A tool that needs a person nobody rostered produces sparse data, and sparse data presented as statistics is the failure this project guards against.

### A bookmark button, which is the cheap half of it

**Also unbuilt, and much smaller than the tool above.** One button on the phone that marks the moment, with a tag and an optional line of text. Standard tags — *great point*, *injury*, *questionable call*, *coaching note*, *highlight* — plus whatever an event adds.

It belongs beside the stats idea because it is the same input surface at the opposite cost. Per-throw capture needs somebody whose whole job it is; a bookmark is one press by whoever is already holding the phone, at a moment when something has just happened. One needs staffing, the other rides along.

Three audiences, from one press:

- **Post-production.** A mark carries the game clock, which is the anchor [`POSTPRODUCTION.md`](POSTPRODUCTION.md) needs and cannot derive — "goal 9 is at this position in the video".
- **Replays.** [`REPLAY.md`](REPLAY.md) designs the operator's side: marks stored, tags derived, an interval for an injury rather than a clip. It assumes somebody at a switcher. A phone at the sideline is a second source of the same marks, from the person closest to the play.
- **Coaches.** For them a tagged note against a timestamp is most of what a stats tool would have given.

Three rules it inherits:

- A mark names the moment it describes rather than a delta, so it is safe in the same outbox as everything else.
- Free text becomes personal data as soon as it names a player. That is the boundary [`COMMENTATOR.md`](COMMENTATOR.md) §5a draws around the notes store.
- Tags are language-neutral values rendered through a label, not strings typed twice.

### Calls and how they resolved

**An idea, noted rather than designed.** Record the call as well as the moment: *foul*, *travel*, *pick*, *strip*, and then how it ended — contested or uncontested, and what happened to the disc.

Why it is a separate note from the bookmark above: a bookmark says something happened here, while a call has a small fixed vocabulary and an outcome, so it can be counted rather than only read. Ultimate is self-officiated, so calls and their resolutions are the game's own record of how it was played, and nothing records them today — not UltiOrganizer, which has timeouts and caps and spirit scores but no calls, and not this project.

Three things to settle before it is worth building:

- **Who presses it.** Cheaper than per-throw capture, because a game has a handful of calls rather than hundreds of throws, but more than a bookmark: somebody has to hear the call and see how it resolved. That is a person watching the game closely, not a scorekeeper watching the score.
- **Whether any of it reaches air.** Probably not by name. A running count of one team's fouls, or a named player attached to a contested call, is an accusation on a broadcast, and it would change how people call. Spirit scores are already the sport's channel for this and they are deliberate, mutual and after the fact.
- **How it relates to spirit scoring.** A tally of contested calls is not a spirit score and must not be presented as one. The most defensible use is the desk and the coach, where the audience is the team itself.

It shares the bookmark's shape — a mark with a tag, stored where possession already is — so if both are built, they are one feature with a richer tag set rather than two.

**So the first question is whether to build it at all.** Established apps already do this — Statto among them — with the input design worked out and users trained. A collaboration, or an import of their export, would get a coach's numbers onto a broadcast without this project growing a second product to staff. Either way the piece to build is the **join**: whatever collects the data, these overlays are where a number reaches air, and those rules are already written — a denominator travels with every rate, an untracked point is unknown rather than zero, and anything the data cannot support is not said.

## 11. Open questions

- **Does the keeper also hold the ratio and line size?** They already exist as declared values and are currently the desk's. The person with the paper scoresheet is the one who can actually see the circled ratio, which argues for moving them — but the desk is who needs them. Probably both, since they are already capability-gated declared values and reconcile cleanly.
- **Do timeouts belong to match control or to the desks?** §7 says both should *see* them; who *records* one in standalone is open. It stops the clock, which argues for match control; it is announced, which argues for the desk noticing.
- **Does match control subsume the Timekeeper?** UltiOrganizer ships a standalone Timekeeper for WFDF time-limit signalling. Overlapping it would be duplication; ignoring it means two clocks at one pitch. Read it before designing the clock.
- **One game or a field?** A keeper at a pitch covers consecutive games on it. A surface bound to one game id means re-navigating between rounds; one bound to a field follows whatever is live, as `/s/field/<n>/` already does.
- **Is undo enough, or is a full edit needed?** Undo covers the last goal. A score that has drifted by two over ten minutes needs a correction surface, which is a different and more dangerous thing.
