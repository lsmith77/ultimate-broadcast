# Score and clock — where they are kept, and by whom

Built, and in use as of this document's last revision. §§1–9 were written before any of it existed and are the reasoning; what follows immediately is what actually shipped, because the reasoning turned out to be right about the shape and wrong about the priority.

## 0. What exists

| | |
|---|---|
| `shared/score.php` | the store: goals keyed by the point they complete, the clock as UltiOrganizer's own three fields, and the nominated code in a separate `.private.json` |
| `score.php` | the endpoint. Reads are open; writes need the administrator session or the code an operator nominated |
| `shared/score-client.js` | the outbox: every press applied locally first, queued, retried |
| `matchcontrol.php`, at **`/k/<game>`** | the phone surface — two presses, the score, a clock, an undo, a sync state |
| The **More** panel on that phone | possession, timeouts, an injury stoppage, the first point's ratio and a clock reset — behind a toggle, because they are not the job |
| `shared/score-source.js` | puts a locally kept score into the payload every renderer already reads |
| The Studio's **Match control** bar | the scorekeeping code — nominate, generate, revoke — beside the **Score from** switch (`upstream` ⇄ `match control`), per game, administrator only |

The reason it was built was not standalone mode. It was latency: Live! serves a single game with a flat 30-second cache, so a goal is on air somewhere between at once and half a minute late and no polling rate improves that — the copy being polled is the stale one. Measured through the switch, a goal reaches the scoreboard in **about a second**.

The code and the switch are one decision, in one place. They were not: the switch shipped in the Studio and the code could only be nominated over the API, so `matchcontrol.php` told a scorekeeper to "ask the operator to set one" and the operator had nothing to set it with. The only person who could keep score was an administrator, who may write without a code — which is not a hand-off. Found by trying to use the first real installation, and fixed by putting both controls in one bar: switching the source to a store nobody can write is how a scoreboard freezes on 0-0, so the bar says "no code set" until one is.

A press carries the time it was pressed. Not the time it arrived — which over a bad connection is a different second, and for the clock a materially different one: `timer_start` is absolute, so a start delivered after a five-minute outage would run the rest of the game five minutes short, on air. The queue also survives a reload, because a bad connection is exactly when somebody pulls to refresh to see whether that helps.

The rest of what only somebody at the pitch knows. Possession, an injury stoppage and the opening ratio were reachable from the Studio and the commentary desk and nowhere else — while the person actually watching the game closely enough to press a button per point was holding a phone with two buttons on it. They are the same kind of fact as the score: true about the game and recorded nowhere upstream. So the **scorekeeping code now opens them too** (`possession.php`), rather than asking one person to carry two five-character codes, and it grants nothing upward — switching the mode on, nominating a code and changing the game stay the operator's.

Timeouts are recorded here now, and were recorded nowhere before. UltiOrganizer keeps them as game events, so a standalone installation had no way to note one and the allowance drawn on air never moved however many were called. They live in the score store, numbered **per side** by the same rule that makes a goal safe to retry — "home's second timeout" written twice is one timeout — and `shared/score-source.js` puts them into `gameevents`, which is the shape `shared/timeouts.js` already counts from. Switching the scoreboard's source switches the timeouts with it, because a board showing this project's score beside Live!'s timeouts would be two answers about one game.

Possession is offence and defence, not home and away. The store records which SIDE has the disc relative to the point being played, and whose offence it is changes at every goal without anybody re-declaring it. Naming the teams there would have asked the scorekeeper for the wrong fact — and did, in the first version of this panel.

**The panel is behind a toggle.** The two big buttons are the job; eight more controls in front of them is how somebody presses the wrong one at 13-12. Whoever wants it opens it once and the phone remembers.

**The clock can be reset, and that is the one destructive control on the page.** Start on a running clock does nothing on purpose — a second press is somebody checking, not somebody asking for the game to begin again — so until now a clock started by mistake, or started on the wrong game, could be paused but never returned to nothing. Reset writes `clock: 'reset'`, which clears `timer_start` and both pause fields, and it is idempotent in the same sense as a goal: resetting twice leaves the same state, so a retry over a bad connection is harmless. It sits in the **More** panel and asks twice — the first press arms it and says what it will do, and the arming lapses after five seconds, because the failure it guards against is a thumb on a phone at a sideline.

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

The intuition here is backwards.

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

**The scale has no floor and no ceiling, and that is the design rather than a consolation.** It starts at *nobody at the field* and runs as far as an event can staff. No row below is the supported configuration with the others as degraded modes; they are all it, and a feature that only works at one of them does not work (`AGENTS.md`).

The roles below are the obvious allocation, not a requirement. Capabilities are granted by a code rather than by a job title, so whoever is holding the phone keeps score — broadcast crew, a team volunteer, or the person also holding the camera.

With one phone surface, the allocation question becomes simply *who is holding the phone*.

| People | Who holds the phone | Devices | Notes |
|---|---|---|---|
| **0** | Nobody — there is no phone | A camera | Film it and add the overlay afterwards ([`POSTPRODUCTION.md`](POSTPRODUCTION.md)). The score comes from the scoresheet, or from a phone somebody carried at the sideline and synced later ([`OFFLINE.md`](OFFLINE.md)). The bottom of the scale is a real deployment, not a failure of one |
| **1** | The operator | Laptop + phone | The phone matters most here: a solo operator is away from the desk for much of the game. |
| **2** | The **commentator** | Two laptops + a phone | Their job is already watching every point; one press every three minutes rides along, where the operator's job does not (§2). Costs this crew a third device, which is the clearest price of the v1 scoping — and the case embedding would later remove. |
| **3** | A dedicated keeper, or a team volunteer at the pitch | Three + phone | Frees the commentator's hands for lines and possession, the high-frequency inputs. |
| **4** | A dedicated keeper | Four + phone | Operator, two commentators, keeper. |
| **more** | Still the keeper | More laptops | Nothing caps it. Further people buy specific things rather than a better version of the same thing: a spotter per team for detailed statistics (§10a), a replay operator ([`REPLAY.md`](REPLAY.md)), a second field. Each is optional and each degrades to the row above by simply not being staffed |

The two-person case decides the design, because it is the most common and the obvious allocation is wrong. Giving score to the operator "because they have the admin login" optimises for the permission model instead of for attention.

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

That prediction was half wrong. Hosted mode *does* take a second score input, because latency made it worth one: an operator can switch a game's scoreboard to match control and the overlay then reads this score instead of Live!'s (§0). What has not changed is the direction of travel —

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
- **Nobody pressing anything.** The overlay must be able to say the clock is not running rather than draw a stopped one, which is the §7 gap stated accurately.

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

### How complete the data is, declared rather than assumed

**The organising idea, and the one that has to come first.** This system already scales with the number of people (§6). Collected statistics scale the same way — and what changes with each person added is not only *how much* is recorded but **which classes of event are recorded completely**. That distinction is the whole thing, because a count is only a statistic when you know whether it is all of them.

**It exists already, in one place.** The scoreboard shows CLEAN HOLD only where possession was actually being tracked, and break chance only while somebody is driving it. That is exactly this rule, decided ad hoc inside one feature. The proposal is to make it a declared, per-class property of a game rather than a condition each new feature re-implements and eventually forgets.

**Coverage is per event class, not per game.** A commentary-driven capture is the example of an *uneven* one — and the shape of that unevenness was assumed here until it was measured, wrongly. The assumption was that a commentator reliably calls a throwaway, a drop and a block because those are the moments worth narrating. They do not; they reliably call **goals**. The measurement is below.

So:

| with | complete | partial or absent |
|---|---|---|
| **score and clock only** | goals, timeouts, the clock | everything else |
| **+ possession tracked** | turnovers per point, holds and breaks, clean holds | individual actions |
| **+ commentary-driven capture** | goals, near-completely | everything else — **measured, see below**: turnovers are narrated about one time in five, and most events name no player at all |
| **+ a dedicated spotter** | every throw for that team | the other team, unless they have one too |
| **+ two spotters** | every throw, both teams | — |

**What follows is mechanical rather than a judgement per feature.** A fact declares which classes it needs and at what completeness; the strip refuses anything whose inputs do not meet it. `shared/facts.js` already refuses to say what it cannot support, so this is a precondition field on an existing catalogue rather than a new mechanism — and it is testable, which "we remembered to check" is not.

Three rules make it safe:

- **A derived number inherits the worst coverage of its inputs.** One team has a spotter and the other does not, so a per-player leaderboard across both is a lie however good each half of it is. The comparison is the thing that must be refused, not the data.
- **Coverage is a claim somebody makes, and can be withdrawn.** A spotter who leaves at half time does not make the first half untrue — they make the second half partial. So it is an interval, and a number spanning the change carries the weaker one.
- **Absent is still not zero, one level up.** Nothing can distinguish "no throwaways happened" from "nobody was recording throwaways" by looking at the data. Only a declaration can, which is why it has to be stored rather than inferred.

**The spotter can say the game got ahead of them, in one press.** Within-point partiality is the one degradation nothing can detect: a spotter who catches four throws of a nine-throw point and then looks away leaves a record that reads as complete. So the levels above are not only a profile chosen before the game — they are a **control**, one key, that drops detail when the action outruns the caller: full, names only, outcomes only, off. Four notes on it:

- **Downshifting is reliable; upshifting is not.** You press it while drowning, and forget it while recovering. So it **returns to the declared default at the next goal** and is pressed again if still needed — a point ending is already this codebase's natural state boundary, and a switch that outlives its cause is the failure the diagnostics expiry was written for.
- **It is written into the log as an interval**, not set as a mode on the game, so the coverage of any window is reconstructable afterwards — including a number computed across points 8 to 15, which straddles the change.
- **It takes effect immediately, not only as a caveat.** A fact needing throw types refuses for those points automatically. The press does not merely annotate the data for later; it stops a wrong number being derived now.
- **It does not replace the cross-checks.** A spotter under enough load to downshift is also under enough load to forget the press, so "a point with a goal and no throws" stays the backstop. The button for what only a person knows; derivation for what the data can prove.

**Counts have to update within a point, not at the next goal.** "Already 25 passes this game" and "two turnovers this point" are in-game facts, which sets the write cadence — a few seconds, not a point boundary — and means the endpoint must answer with **totals by default** and the event log only on request. That is the shape `playingtime` already needed when returning full histories flooded a 2-second poll.

**The example worth keeping in mind:** with commentary-driven capture, *throwaways per player* is publishable and *completion percentage* is not — the numerator is complete and the denominator is not. Add possession tracking and turnovers per point becomes available too, because that class is complete for a different reason. The numbers that are safe to show are a function of who turned up, and the system should be able to work that out rather than leaving it to whoever is looking at the screen.

### The play clock, and the denominator it finally provides

**A spotter can also say whether the disc is live, and that turns out to be worth as much as the events.** It is one more thing to press, so it has to earn its place. It does, three times over:

- **It is a denominator that means something.** "Throws per point" is hostage to how long points ran, so it compares nothing across games. Throws per minute of *live play* does. Every rate in the system has wanted a real denominator and this is the first one available without a camera.
- **It gives time on the field, per player.** The line is already recorded per point. Intersect it with the live intervals and each player has a figure for how long they were on while the disc was live — the number a coach actually asks for, and one no amount of event capture produces on its own.
- **It is mostly inferable, so the cost is low.** A pull starts play, a goal ends it, a foul stops it, a check restarts it. These are rules, not guesses. The spotter says the words already, and the clock follows them; explicit presses are the exception rather than the workload.

**The failure mode is a spotter who forgets to restart it**, which would park the clock at "stopped" for the rest of a point and silently halve the game. The repair is a rule the data can apply by itself: a throw cannot happen while the disc is dead, so seeing one means play resumed. That makes an unclosed stoppage self-healing, which matters more than getting the restart instant exactly right.

**AFK and a stoppage look identical and mean opposite things.** This is the distinction the whole idea stands on, and conflating them would ruin both numbers at once:

| | what it says | what it does to the data |
|---|---|---|
| **stoppage** | the disc was not live | nothing is missing. A stopped disc is a real state of a game, and roughly half of one |
| **AFK** | the disc *was* live, nobody was capturing | data is missing, and the coverage rule turns it into "these points have no throw data" |

So they are separate timelines, and live time inside an AFK window is reported as **unknown** rather than counted either way. The table shows both: the live figure, and how much of it nobody was watching.

**What it is not, and the tool says so under the table.** This is *time present while the disc was live*. It is not work done, distance covered, or effort spent — a handler parked in the dump and a cutter running the whole point score identically. That gap needs trackers or vision and no amount of spotting closes it, which is exactly why the number has to be labelled rather than left to be read as "workload".

**The play clock is itself an input class with declared coverage**, by the rules above. A clock nobody ever started reports no field time at all rather than a plausible one; a clock driven entirely by inference from calls says so, because it deserves less trust than one a person drove. Both appear in the banner above the table rather than in a footnote.

### Two spotters, one from each team

**This is the answer to the staffing problem this section opened with.** "A tool that needs a person nobody rostered produces sparse data" — so do not ask the broadcast to roster one. Detailed statistics are what coaches buy software for, which is why teams already use apps like Statto; a team will staff a spotter for their own coach's sake, and they know their own players by sight. The broadcast then receives the data as a byproduct of somebody else's motivation, which is the only staffing model that survives a Sunday morning.

**A disagreement between the two is not an error.** One side scores a turnover as a block, the other as a throwaway, and **both readings are legitimate descriptions of the same event** — the sport genuinely has that ambiguity. So the system must not resolve it: not by majority, not by rule, not by trusting the defence. Four consequences:

- **Do not merge at write time.** Each spotter's stream stays their own; merging is a read-time policy per consumer. The broadcast takes the conservative view, each coach sees their own, and neither is overwritten by the other.
- **Separate the event from the attribution.** Both agree a turnover happened at that moment and disagree about whose it was, so store one agreed turnover carrying two competing attributions rather than two contradictory events. That also gives air something safe to say: *turnover* is agreed and publishable; *block by X* is not, until it is.
- **Bias stops being hypothetical and becomes measurable.** A team's own spotter over-credits their blocks and under-credits their throwaways — perception, not dishonesty. With both sides recording, the asymmetry is visible in the disagreement set and can be stated rather than assumed away. The agreed subset is roughly unbiased by construction, which is a second argument for air using only that.
- **Reconciliation is a deliverable, not a chore.** "Here are the eleven events you disagreed on, with the moment to scrub to" is worth having to both coaches — and it is the same worklist as the detail-downshift intervals and the bookmark idea below. Three sources, one mark-and-resolve mechanism.

**This is the first feature where "the code is a namespace, not a credential" breaks.** Every store here rests on that trade because everyone holding a code is on the same crew. Here the two parties are *adversaries*: team A must not write into team B's stream, and a coach watching the opposition's own classification of their turnovers mid-game is scouting. The shape that follows is a per-team capability rather than a shared room, with each side seeing only their own stream until the game ends and the broadcast seeing the agreed subset throughout. That is a genuine escalation from what the room code is today, and it should be designed as one rather than discovered.

**The alternative remains open.** Importing what a team already collects needs no attention at the pitch at all and depends on somebody else's format — which makes it post-game unless an integration exists. Team spotters on this project's own surface is the version that feeds the broadcast *during* the game. They are not the same product decision and both are worth keeping.

### Voice, which is an attempt to buy that attention back

**An idea, thought through and not built.** The cost above is attention at the pitch, and the obvious shape needs *two* people: one calling what happens, one looking at a screen entering it. The second person is the one nobody rosters. So the question is whether a machine can be the second person — and, separately, whether voice should drive the controls that already exist rather than only collect new data.

**Driving the existing controls is the stronger half, and it is not the same feature.** Voice that writes to the stores already here — lines, possession, stoppage — needs no new data model, no new pipeline and no new consumer: the scoreboard already reads possession, the strip already reads facts, the line history already feeds playing time. It is an input method for a tested path. That makes it worth discriminating between the controls rather than voice-enabling the page:

| control | today | worth saying out loud? |
|---|---|---|
| **The line, before each point** | seven picks, ~200 a game, by mouse | **Yes — the best candidate here.** It happens at a dead ball, so there is no latency pressure; the vocabulary is exactly the squad; and the line is *displayed before it matters*, so a misheard name is caught by eye before the pull. High volume, slack timing, visible verification |
| **Possession, injury stoppage** | one keystroke each (`O`, `D`, `I`) | **No.** One utterance plus a recognition error replaces one key, during live play, on a fact that reaches air through the break-chance tab |
| **Blocks, throws, completions** | not collected at all | The genuinely new data, and the hardest: the highest event rate in the game, during play, when the caller's attention is worst |

**What "in-browser AI" means matters.** The Web Speech API is not in-browser — Chrome streams the microphone to Google and Safari to Apple, which puts a pitch-side microphone carrying people's voices into a third party's hands, on a project whose imprint claims no analytics. Locally means Whisper or Vosk compiled to WASM or WebGPU: tens of megabytes of weights, downloaded once and cached, and no network at inference. The desk is the tier allowed to take dependencies (`AGENTS.md`), and a laptop that fetches a model at home works at a pitch with no signal — which is the deployment `OFFLINE.md` describes.

**Transcription is the easy half; structure is the hard half.** What is wanted is not dictation but events — thrower, receiver, action, outcome — over a vocabulary of about 28 names and twenty verbs. That is grammar-constrained recognition, a different and far more accurate problem than open dictation, and it favours an engine that constrains (Vosk) over one that invents fluent text when unsure.

**Three things this project already holds make that grammar unusually tight**, and they are the reason this is worth writing down here rather than admiring from a distance:

- a **name pronunciation** field per player, collected through the team's own CSV (§5 of `COMMENTATOR.md`) — a pronunciation lexicon, which is exactly what a recogniser needs and what almost nobody has
- **nicknames**, collected the same way — shorter to say than a full name, and the answer to two players sharing a surname
- **per-point line history** ([`PLAN.md`](PLAN.md) §4c) — the candidate set at any moment is not the squad but the seven on the field, and the score log segments the audio into points for free

**A speaker profile is not the mechanism people expect.** Modern recognition does not enrol a speaker the way dictation software did; the accuracy comes from the vocabulary and from confirmation. The cheap version of the idea is real, though: a spotter reading the roster once before the game — two minutes, 28 utterances — gives speaker-specific templates for exactly the words that matter. That is keyword spotting rather than a profile, and the throw verbs need no recording at all because they are the grammar.

**Accuracy is the gate, not a confirmer.** The first draft of this section said a recognised event may reach the desk immediately and air only once a person has confirmed it. That is the wrong trade: it invents a crew seat nobody rosters (§6 already stops at four), and it delays exactly the numbers that are wanted *during* a point. The decision is that recognition may feed air directly **when its class of event clears a measured accuracy bar** — and the bar is per class, because a name chosen from the seven players on the field is a far easier problem than a throw type chosen from twenty, and the two should not share a threshold.

Two things follow. **Confirmation survives only where it is nearly free:** the line before a point is displayed and read by eye anyway, so it is confirmed at no cost in attention — and it is also the highest-volume input, which is a pleasant coincidence. And **the bar must be measured continuously rather than assumed once**: two spotters agreeing is itself a live accuracy signal, so a class whose agreement rate falls can stop reaching air before anybody notices it should have.

**None of this proceeds until that measurement exists.** Whether constrained recognition clears a high bar on Ultimate jargon, in accented English, beside a windy pitch, is unknown — and it is the first thing to build, before any of the rest. If it does not clear the bar, this section describes a tool nobody should trust.

**Counts before rates**, which is the coverage rule above applied to a microphone. A completion percentage needs every throwaway and drop caught, and a caller who misses three produces a confident wrong number that nobody can audit during a game. "Third block called" is defensible; "60% completion" is not, until coverage is measured and travels with it — the denominator rule `AGENTS.md` already states. Partial capture is fine; a partial capture presented as a rate is the failure this section opened with.

**And voice never drives what is on air.** Card switching in the Studio stays a click. The arm-then-show lifecycle exists because a graphic appearing by accident cannot be taken back, and an accidental trigger is exactly what an open microphone beside a shouting sideline produces.

**It should never need to be multilingual, and that is a design choice rather than a limitation.** Whisper's multilingual quality collapses at the model sizes that fit in a browser, and Vosk ships one model per language, so a Swiss tournament — Swiss German commentary, English jargon, German and French surnames in one sentence — defeats any single language model. But the vocabulary here is names plus jargon, and Ultimate's jargon is English loanwords almost everywhere: *huck*, *break*, *dump*, *swing*, *callahan*. So the call format should be a bare keyword sequence with **no function words** — thrower then receiver, not "Weber to Lehner". With "to", "from" and "catches" gone, the only words left are proper nouns and borrowed jargon, and the recogniser never has to know which language is being spoken around them. Names then depend on the speaker's mouth rather than on a language model, which is what the roster read-through and the per-player pronunciation field are for.

**Fine-tuning is the wrong tool for the half that matters.** The roster changes every game, so anything needing a training run per squad is obsolete by the next one: names must be adapted at *runtime*, from a word list — constrained grammar first, then hotword boosting, then enrolment templates. Jargon is static and is the only part where training is even coherent, and its labelled data would come from confirmed captures, which means storing audio of named people and inheriting the retention question the notes store already answers. One architectural objection outweighs the rest: a fine-tuned model is a build artifact, and *the directory is the installation* is this project's defining property. A stock model plus a runtime word list keeps it; shipped weights the repository cannot rebuild would be the first thing here that does not.

**The practical constraint nobody plans for:** a close microphone on the caller. An open mic at a windy pitch defeats all of the above, whatever the model.

**If it gets built, the order is:** the line picker first, because it is the highest-volume mouse work in the project, it is verified by eye, and getting it wrong costs a click rather than a broadcast. Everything else can wait for that to prove itself.

### Proving it, before building it

**Everything above is conditional on one measurement, and the measurement is cheaper than the feature.** Ulti TV and comparable archives hold hundreds of hours of commentated Ultimate. That is a test set nobody has to stage — and, better, one recorded by commentators who had no idea a machine would listen, which makes it a **floor** rather than a best case.

**Two different truths, and only one of them is needed first.**

| truth | how it is obtained | what it measures |
|---|---|---|
| **What was said** | a person transcribes the audio, never needing to read a shirt number | whether recognition works — the viability question |
| **What happened** | somebody identifies players from video | whether commentary is *complete enough* — the coverage question |

The second is the hard one, and the reason is practical: **shirt numbers are rarely legible in broadcast footage**, so a spotter cannot be run retroactively over an archive. Splitting the two is what lets the work start, because the viability question needs only the first.

**Phase 0 needs no AI at all, and is the experiment most likely to kill the idea cheaply.** Transcribe twenty minutes of real commentary by hand and mark every utterance that carries a capturable event. That answers three things nothing else can: how often real commentary contains the events at all, which words are actually used (against the jargon list this section assumes), and what the utterances look like — whether commentators say full names, surnames, nicknames or numbers, and whether they use the function words the call format is designed to avoid. If natural commentary does not contain the events, no recogniser rescues it, and that is worth knowing before anybody downloads a model.

**Phase 1 is recognition against that transcript.** Vosk with a grammar built from the real roster, Whisper as a baseline, measured **per class** — names separately from jargon — because the decisions above set a per-class bar and a single aggregate number would hide exactly the split that matters.

**Phase 2 is the coverage question, narrowed to what video can answer without numbers.** A reviewer can see that a turnover happened without knowing who threw it, and the score gives the goals. So "how many turnovers went unmentioned" is measurable from footage; "was the block correctly attributed" is not. Partial, and it covers the class the commentary-driven profile most depends on.

**Phase 3 has no ground truth, and that is where the two-spotter model earns its place a second time.** In a live test, agreement between two independent spotters is the only available accuracy signal — which is the same signal the deployed system uses to police its own per-class bar. The field test and the production safeguard are the same mechanism.

**The observer effect is real and cuts both ways.** Commentators who know their voice feeds the statistics will call more, so anything measured on an archive understates what a deployed system would collect. That is the good half. The bad half deserves stating plainly: **the commentary must not degrade to serve the capture.** "Weber, Lehner, Weber, throwaway" is excellent input and terrible broadcast, and a broadcast project that makes its commentary worse to improve its statistics has traded the wrong way round. If prompting commentators turns out to be necessary, that is an argument for a separate spotter on a separate microphone — which is the team-spotter model above — not for coaching the people on air.

**Where it lives.** A harness rather than a feature: audio plus a reference transcript in, per-class accuracy out, run on a laptop and never deployed. It has no place in the installation, so it belongs outside the tree the release ships — and whatever directory it lands in needs adding to `deploy.sh`'s excludes on the day it is created, because everything not excluded is deployed.

### What phase 0 found, and the pivot it forced

**Run, on 2026-09-20, against seven complete games — 8 hours 36 minutes, 57,650 spoken words.** Ulti.tv's published commentary, read through YouTube's own auto-captions rather than by hand: a machine transcript is not ground truth, but ordinary English like *score*, *drop* and *turnover* survives general recognition intact, so the **rate** of those words can be counted across an archive for nothing. The tooling is deliberately local and uncommitted; it reads third-party captions and produces counts.

One trap worth recording for whoever runs this again: auto-caption VTT is *rolling* — each cue restates the previous line and adds a few words — so a naive parse counted 34,766 words in a 92-minute game, roughly double. Words have to be de-duplicated by overlap before anything is counted.

| | across 7 games | per minute |
|---|---|---|
| goal words | **154** | 0.30 |
| turnover | 19 | 0.04 |
| throwaway | 15 | 0.03 |
| drop | 13 | 0.03 |
| block | 10 | 0.02 |
| any event utterance | 203 | 0.39 |

**The class that is covered is the one already recorded.** Seven games hold somewhere between 105 and 210 goals, and there are 154 goal mentions — near-complete. The same games contain dozens of turnovers each, and all four turnover-ish classes together appear 57 times in eight and a half hours. The score log already has the goals.

**Attribution is the binding constraint, not recognition.** Of the 203 event utterances, **13% carry a shirt number** and 19% attribute only by pronoun. Two thirds name no usable actor at all. An event with no actor is a count, not a statistic — so even perfect transcription of this material would yield a turnover tally with nobody attached to it.

**And 8% of event utterances are hedged** — "I think", "looked like", "not sure". One in twelve, which is small and not nothing: a hedge must never be stored as a fact, so the format needs somewhere to put uncertainty rather than dropping it into the same field as an observation.

**Three caveats, and the first is the one that keeps this honest.** The measure is **lexical, not semantic**: a turnover narrated without any of those words is not counted, so every figure above is a *lower bound*. The attribution figures are proxies, because a general recogniser mangles names it has never heard. And this is one channel, one language, one broadcast culture.

**Why the gap exists, which is also the condition for revisiting it.** Television commentary leans on the picture for identity — the viewer can see who threw it, so saying the name is redundant. **Radio** commentary names everybody, continuously, because the listener cannot see. The events are narrated either way; what TV omits is precisely the actor. So commentary-driven capture is not dead, it is *conditional on a narration style* — and asking commentators to work in a radio register to feed a database is a real cost to the broadcast, which is the trade `AGENTS.md` refuses to make silently.

**So the direction pivots to the spotter**, which is the easier case and the one this section already named as the complete-coverage profile. If a dedicated spotter with a fixed call format works, commentary can be revisited as the cheap partial supplement it might still be.

### Decided on paper, built not at all

Taken deliberately rather than left open, so that whoever builds this is arguing with a decision rather than starting from nothing. Every one of them is reversible; none of them has been tested against a real game.

| question | decision |
|---|---|
| **How is completeness established?** | **Declared per game, measured as a check.** The crew names a profile; the cross-checks warn when reality diverges from the claim. Where the two disagree, the weaker governs what may be published |
| **What makes a throw safe to retry?** | **Client-generated ids**, deduped on arrival. Works for any number of observers and any number of devices per observer, which `(point, sequence)` does not |
| **How is a dead capture told from a quiet game?** | **A heartbeat**, and a point with none is marked *uncovered* rather than empty. Silence is never read as zero |
| **What shape is the store?** | **Append-only per observer**, flushed on a few-second interval rather than per point, because in-point counts are wanted. Reads answer with totals by default and the log only behind a cursor |
| **Who confirms before air?** | **Nobody, when the class clears a measured accuracy bar.** Confirmation is kept only where it is free — the line-up, which is read by eye anyway. The bar is per class and measured continuously |
| **How do season numbers work?** | **Aggregate only over games meeting the bar each statistic needs, and print the subset** — "9 blocks, from 4 of 7 games with block capture". A smaller true number beats a larger mixed one |
| **Ours or UltiOrganizer's?** | **A tournament decision, either way** — see below. Not a merge rule, because the answer is political rather than technical |
| **Coverage downgraded while on air?** | **The card finishes its dwell; no new claims are made under the weaker coverage.** A graphic vanishing mid-sentence draws more attention than the error it would correct |

### Whose data this becomes

**If it works, it probably should not stay ours.** Detailed capture that clears its accuracy bar is a better record than what a tournament collects today by hand — so the natural end state is that it *drives UltiOrganizer's own statistics* rather than living beside them. That is not a technical decision and should not be made by this project.

It is the **tournament's**: whether they trust a broadcast crew, or coaches recording their own players, to be the source of the event's official numbers. Some will; plenty will not, and will want the broadcast's capture to stay the broadcast's. So the option has to exist in both directions — feeding upstream, or broadcast-only — and the choice belongs beside the event's other policy, not in a config file here.

Two things follow. It makes the per-event **capture policy** already asked for upstream ([`UPSTREAM.md`](UPSTREAM.md), ask 2) load-bearing rather than convenient: if capture can feed the record, the event has to be able to declare who may source it. And it needs a **write path that does not exist** — the API is six endpoints and all GET — which makes it a larger ask than the score push, and one worth making only once the accuracy measurement says the data is worth having.

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

**A spotter's note is the same press wearing the spotter's hat, optionally against a player.** The spotter already has the line on screen, the play clock running and a microphone open, so a note costs them almost nothing and is the one artefact a coach asks for by name. It is mostly a recombination of what §10a already builds — a moment, an optional player from the six on the line, a tag, a line of text — and it inherits the personal-data boundary above the moment it names somebody.

**The version worth aiming at is the one that closes the loop during the game.** A note carries a timestamp, and in training mode a timestamp is a video position — so a player handed a tablet on the sideline could be looking at the thirty seconds their note is about, cued automatically, while the game is still on. The same mechanism serves the post-game discussion, which is the safer first target: nothing has to be live for a list of tagged moments with video positions to be worth having.

Unbuilt and deliberately so. It is recorded here because it is cheap, it reuses the whole of this section, and the temptation will be to build it before the thing it rides on works.

### What commentators actually talk about, measured over 26 games

Phase 0 asked whether commentary carries the events we had planned to collect. A second pass over a larger corpus — **26 full games, 276,335 words** of auto-captions from the same public channel — asks the opposite question: what do commentators find worth saying that we have no word for? What they reach for repeatedly is a reasonable proxy for what a coach and a viewer care about.

Counted by **breadth** rather than frequency. A term used sixty times in one game is a player's name or one commentator's habit; a term used in twenty-four of twenty-six games is a category the sport has.

| | in games | uses | reading |
|---|---|---|---|
| `match` / `zone` | 26, 25 | 303, 480 | the defence is named constantly |
| `switch`, `cup`, `poach`, `bracket` | 20, 14, 12, 9 | | and named in detail |
| `vertical stack` | 12 | 27 | the offence is **not** |
| `horizontal stack`, `side stack` | 8, 6 | 14, 8 | |
| `under` / `deep` | 26, 25 | 361, 251 | the CUT, which we do not record at all |
| `sideline` | 26 | 380 | |
| `reset` / `dump` | 22, 12 | 256, 106 | |
| `break side` / `open side` | 11, 9 | 38, 64 | where the throw went |
| `handler` / `cutter` | 22, 12 | 192, 19 | a role, not an event |
| `force` | 21 | 87 | but a direction (`force flick`, `force home`) **never** |
| `pressure`, `momentum`, `legs` | 26, 20, 14 | 250, 76, 35 | judgements, not facts |

**Four things follow.**

- **The defensive formation is the one worth capturing; the offensive one is not.** This is the asymmetry the idea below was missing. A spotter naming both would spend half their breath on something commentators mention in fewer than half of games, while the defence is named in every one of them.
- **Cut type is the largest thing we do not record.** `under` and `deep` appear more often than almost any throw name, and they describe the receiver's movement rather than the throw — a dimension the grammar has no slot for. One word appended to a throw would add it.
- **Force is named, its direction is not.** `force` appears in 21 games and `force flick`, `force backhand`, `force home` and `force away` appear in none. So a spotter who wants the direction has to volunteer it; commentary will never supply it, and neither will a crowd.
- **Effort is discussed constantly and is not collectable.** `pressure` appears in all 26 games. It is a judgement, and the coverage rules mean a judgement dressed as a measurement is worse than nothing.

### Two tiers of defensive vocabulary, and only one is in the commentary

Probing the same 26 games for the *instructions* a defence gives itself returns almost nothing: `face guard` appears **0** times, `no under` in 1 game, `no long` in 5, and `no deep`, `no huck` and `no unders` in none. Marking detail is similar — `straight up` in 6 games, `flat mark` in 2.

**That is a finding about the source, not about the terms.** They are ordinary things to say on a sideline; they are simply not what a broadcast commentator says, because a commentator is describing what a viewer can see and these are decisions a viewer cannot. Reading the zeros as "not worth collecting" would be exactly the wrong inference — and the measurement above is only safe to act on for the things commentary *can* supply.

So the defensive vocabulary splits in two, and the split follows who is holding the microphone:

| | what it needs | examples |
|---|---|---|
| **Visible to anyone** | a neutral spotter watching the field | `zone`, `match`, `cup`, `poach`, `bracket`, `switch` — named in nearly every game |
| **Known only to the team** | a spotter attached to that team | `face guard`, `no under`, `no long`, the force direction, the called look |

The second tier is the strongest argument yet for **a spotter per team** rather than one neutral spotter. Two spotters were proposed here as a disagreement model — one side scores a throwaway, the other scores a block, and the difference is detectable. This is a second and better reason: a team's own spotter hears the sideline call and knows the system, so they can record a defensive intent that no neutral observer could ever reconstruct, and that no commentator says out loud.

It also means the two captures are not redundant halves of one record. Each side sees things the other structurally cannot, which is a different shape of data from two people watching the same thing — and it argues for merging them by *claim* rather than by vote.

One term from this group is well attested and belongs in the visible tier: `over the top` appears in 18 of 26 games, which makes it a throw description rather than a tactic.

**One caveat that cuts against the zeros.** These are machine transcripts, so a term the recogniser cannot spell is under-counted rather than absent: `scoober` appears zero times across 26 games and is also missing from the acoustic model's lexicon, which are not independent facts. A zero here means *not reliably transcribed*, which is weaker than *not said*.

### Formations, which are two words a point and answer questions throws cannot

**Also unbuilt.** A spotter can name what each side is running — *match*, *zone*, *cup*, *poach*, *bracket*, *switch* on defence; *vert stack*, *horizontal*, *side stack*, *dominator* on offence — and it costs two words at the start of a possession rather than two per throw.

The measurement above puts the **defence first and the offence a distant second**: the defensive look is named in every game of a 26-game sample and the offensive one in fewer than half. So the defence is the one to build, and the offence is an optional second word for a spotter who has breath for it.

The reason to want it is that it answers a question the throw data cannot. "Completion rate" is a number about a team; "completion rate against a zone" is a number a coach can act on, and the difference between them is one word nobody is currently saying. It is also the thing an opponent scout writes down first, and the thing a commentator reaches for when a point goes twenty passes: *they have not solved this junk look yet*.

Four things it inherits, and one it does not:

- **It is declared, not observed.** Nobody can derive a side stack from a list of receivers, so it is a claim a person makes — with the same coverage rules as everything else here. A point nobody named a formation for is **unknown**, never "match".
- **It is an interval, not a point attribute.** A team that starts in a zone and switches to match after the first turnover did both in one point, and recording the first would make the second a lie. Same shape as the play clock and the AFK log: it changes when somebody says it changed.
- **It pairs.** The interesting cross-tab is offence *against* defence — vert against zone is a different question from either alone — which needs both sides named, and therefore a spotter covering both teams rather than one.
- **The words need the same collision check as every other name.** A closed vocabulary cannot afford two terms that sound alike, and this adds a dozen at once into a grammar that already contains *swing*, *dump* and *side*.

**What it does not inherit is the per-throw cost.** Formations are the cheapest high-value thing on this list — two utterances a point, no attribution, no timing — which makes them a plausible FIRST thing for a spotter who cannot yet keep up with every throw, rather than a refinement after everything else works. A capture that records only the line, the formations and the outcome of each point is already worth more to a coach than most tournaments collect, and it is within reach of somebody watching a game normally.

### In mixed, a called line can be checked against the prescribed ratio

**A line call in a mixed game carries one more criterion than a line call anywhere else**: the gender ratio. Seven names is not enough to be a legal line — four MMP and three FMP is a different line from three and four, and which one this point takes is fixed by rule rather than by choice.

**Most of this is already built, in `shared/ratio.js`.** That helper exists because the commentator page and the stage's progression card had each stated the rule separately and drifted apart. It holds the ABBA pattern (`slot()`, so points 1, 4, 5, 8 … repeat the first point's ratio), the ratios available at each line size (`pairForSize()` — two at an odd size, one at an even, since the prescription is only ever about who gets the odd player), and the arithmetic a check actually needs: `counts()` turns `4MMP/3FMP` into `{MMP: 4, FMP: 3}`. The spotter should reach for it rather than restate the rule a third time.

**Two inputs are missing, and only one of them is cheap.**

- **The first point's ratio**, which decides every later point through the ABBA pattern. It is already a declared value the desk holds, reachable from the More panel on the scorekeeping phone. For the spotter it is one declaration at the start of a game.
- **Per-player matching**, which nothing upstream records. `shared/roster.php` has no such field, and neither does UltiOrganizer — the same gap the size default already works around. So it has to come from wherever the squad came from: a reference game pack can carry it per player, and a hand-built squad has to be told.

**What the check buys is a second kind of evidence.** Everything else the spotter validates comes from the sport's causality — a pull cannot happen while the disc is live, a turnover needs a holder. The ratio is the first criterion that can falsify a line call from **outside** the capture: a heard line of five MMP on a seven-a-side mixed point is wrong no matter how confidently it was recognised, and it is wrong in a way that says *which* name to doubt. That makes it worth more as a prompt than as a rejection — the same posture as `implausible()`, which marks and never refuses.

**It is scoped to mixed.** `Ratio.isMixed()` decides that from the division name, and outside mixed there is no ratio and no check.

### Calls and how they resolved

**An idea, noted rather than designed.** Record the call as well as the moment: *foul*, *travel*, *pick*, *strip*, and then how it ended — contested or uncontested, and what happened to the disc.

Why it is a separate note from the bookmark above: a bookmark says something happened here, while a call has a small fixed vocabulary and an outcome, so it can be counted rather than only read. Ultimate is self-officiated, so calls and their resolutions are the game's own record of how it was played, and nothing records them today — not UltiOrganizer, which has timeouts and caps and spirit scores but no calls, and not this project.

Three things to settle before it is worth building:

- **Who presses it.** Cheaper than per-throw capture, because a game has a handful of calls rather than hundreds of throws, but more than a bookmark: somebody has to hear the call and see how it resolved. That is a person watching the game closely, not a scorekeeper watching the score.
- **Whether any of it reaches air.** Probably not by name. A running count of one team's fouls, or a named player attached to a contested call, is an accusation on a broadcast, and it would change how people call. Spirit scores are already the sport's channel for this and they are deliberate, mutual and after the fact.
- **How it relates to spirit scoring.** A tally of contested calls is not a spirit score and must not be presented as one. The most defensible use is the desk and the coach, where the audience is the team itself.

It shares the bookmark's shape — a mark with a tag, stored where possession already is — so if both are built, they are one feature with a richer tag set rather than two.

**So the first question is whether to build it at all.** Established apps already do this — Statto among them — with the input design worked out and users trained. A collaboration, or an import of their export, would get a coach's numbers onto a broadcast without this project growing a second product to staff. Either way the piece to build is the **join**: whatever collects the data, these overlays are where a number reaches air, and those rules are already written — a denominator travels with every rate, an untracked point is unknown rather than zero, and anything the data cannot support is not said.

### What a capture unlocks downstream, and the one thing in the way

**The order is deliberate: mature the capture first, integrate after.** Feasibility is settled — voice capture of per-throw data works, and the section below is the payoff. But every item in it is a number reaching a commentator or a screen, and none of it is worth having until the capture underneath is trustworthy in hands other than its author's. Integrating early would also be the expensive mistake: consumers bind to a store's shape, so wiring the desk and the overlays before the capture format has settled buys a migration rather than a feature. So the list below is a statement of what becomes possible, not a queue to start on. The gate is spotters who are not the author, on real games, agreeing with each other.

The spotter is built and nothing consumes it. **A capture is currently a file the spotter downloads** — no surface reads it, so none of what follows exists yet. The gating piece is a transport: the events need to live in a store the other surfaces already poll, the same shape as possession, lines and notes. Until that exists, every item below is a consequence waiting on one piece of plumbing, and it is worth listing them together because they argue for the *shape* of that store rather than for building them one at a time.

**At the commentary desk.** `shared/facts.js` ranks what is interesting right now, and everything it can currently say is derived from the possession log and the score: holds and breaks, turnover counts, conversion. Per-throw data adds a class of fact that is about **people** rather than about points — a completion streak, passes inside this possession, what a player has done so far this game, how many calls have passed between these two in this point. That last one is not a statistic anybody keeps; it is the kind of line a commentator actually says, and nothing records it today.

**On air.** The overlays render what a desk already knows, so the same events reach graphics without new rules: a lower-third keyed to whoever has the disc, a point's pass and turnover count while the point is still running, live time against elapsed time. The last is genuinely new — nothing upstream distinguishes disc-live from stopped, which is the denominator §10a argues the play clock finally provides.

**For a coach.** Per-player **live** time is not the same number as `shared/playingtime.js` computes: that is time on the field by line, this is time with the disc in play, which is the one that tracks workload. And a spotter covering both teams makes the cross-tab possible — completion rate *against* a named defensive look — which is the question throws alone cannot answer and the reason the formations note above exists.

**What it does not unlock.** No continuous position: a capture holds no field coordinates, so anything needing to know *where* on the field something happened is out of reach for a spoken grammar. Coarse space is a different matter and is discussed below. And a spotter is now encouraged to do a few points rather than a whole game, so partial coverage is the normal case rather than the exception.

**The rules are already written, and they are the hard part.** Everything here is a derived number reaching air, which is exactly where this project's characteristic bug lives — a graphic quietly asserting something untrue. The doctrine above applies unchanged: coverage is declared per event class, a derived number inherits the worst coverage of its inputs, a denominator travels with every rate, and a point nobody spotted is unknown rather than zero. A capture covering four points of a final is a complete record of four points, and any fact built from it has to say so.

### Space, which voice can approximate and touch could capture

"Nothing spatial" is too strong, and the 26-game measurement above already says so: `under` and `deep` appear more often than almost any throw name, and they are **the largest dimension the grammar has no slot for**. They describe where the receiver went. That is spatial information arriving as one appended word.

**The vocabulary route is cheap and deliberately deferred.** A throw could take a qualifier — *short*, *long*, a distance like *20m*, *break side*, or the mark it beat, *force forehand*. Each costs one more word on a call a spotter is already making, and `force` direction in particular is worth noting because commentary never says it: the corpus above found it absent, so a spotter is the only plausible source. The reason not to build it yet is sequencing rather than doubt. The grammar already has forty-odd terms and a collision problem that gets worse with every addition, and until per-throw capture is solid in the hands of people who are not the author, adding a dimension makes the thing harder to test without making it more trustworthy. Get the rest right, then see whether a spotter can really carry another word per throw.

**Fine-grained space needs a different input, not a bigger vocabulary.** Voice was chosen because a spotter's hands are busy and their eyes are on the field — but a finger tracing the disc's path on a phone in a pocket satisfies the same constraint, and arguably better: a direction and a distance are awkward to say and natural to draw. A gesture that can be made **without looking** is the version worth designing; anything needing the spotter to watch the screen spends the attention the whole design exists to protect.

**Statto has already solved this input.** It has an elegant interface for exactly this capture, and its development appears to have stalled. §10a's staffing argument above already points at apps like Statto for a different reason; this is a second one. Adopting that interface, or collaborating rather than reinventing it, would be a better use of effort than a second attempt at a problem somebody has already worked out — and it does not change the conclusion that the piece this project has to build is **the join**, not the collector.

## 11. Open questions

- **Does the keeper also hold the ratio and line size?** They already exist as declared values and are currently the desk's. The person with the paper scoresheet is the one who can actually see the circled ratio, which argues for moving them — but the desk is who needs them. Probably both, since they are already capability-gated declared values and reconcile cleanly.
- **Do timeouts belong to match control or to the desks?** §7 says both should *see* them; who *records* one in standalone is open. It stops the clock, which argues for match control; it is announced, which argues for the desk noticing.
- **Does match control subsume the Timekeeper?** UltiOrganizer ships a standalone Timekeeper for WFDF time-limit signalling. Overlapping it would be duplication; ignoring it means two clocks at one pitch. Read it before designing the clock.
- **One game or a field?** A keeper at a pitch covers consecutive games on it. A surface bound to one game id means re-navigating between rounds; one bound to a field follows whatever is live, as `/s/field/<n>/` already does.
- **Is undo enough, or is a full edit needed?** Undo covers the last goal. A score that has drifted by two over ten minutes needs a correction surface, which is a different and more dangerous thing.
