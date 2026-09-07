# Video overlays — documentation index

## What this is

A broadcast graphics layer for Ultimate tournaments. It turns the tournament data an event is keeping — the score, the clock, rosters, goals and assists — into graphics a video switcher can put on air, and gives the people running the broadcast somewhere to control them from.

Four surfaces, for four different jobs:

| surface | who it is for | what it is |
|---|---|---|
| **Scoreboard** | the switcher | A broadcast *bug* on a transparent 1920×1080 canvas: score, clock, timeouts, hold/break. One URL, points a browser source at it, done. |
| **Studio** | the operator | A full-frame stage hosting several cards at once, plus the control page that decides what is on it. One URL for the whole broadcast, changed live from a laptop. |
| **Commentator** | the people talking | A second screen, never on air: rosters, stats, who is on the field. Nothing here reaches a viewer, which is why it can show numbers a graphic must refuse. |
| **Match control** | whoever is watching the game | The score and the clock, from a phone at the pitch. Applies a press locally and sends it afterwards, so a bar of signal never makes anybody wait. |

### Two modes, both real

The project began as an extension to **[Live! by BULA](https://github.com/layoutd/live-by-bula)** 3.0.6 (which itself runs on UltiOrganizer 4.0), and that is still the mode that gets you a whole tournament. It is no longer the only one.

| | **Hosted** | **Standalone** |
|---|---|---|
| Underneath | Live! on UltiOrganizer | nothing |
| Installed as | a subdirectory of a Live! install | a site of its own, or `php -S` on a laptop |
| Data | Live!'s public JSON API | an event authored in a browser, squads through the commentary desk, score and clock from a phone |
| Needs | PHP, MariaDB, Composer, a signed Live! Terms of Use | PHP 8.3+ and a writable `conf/`. No database, no Composer |
| What you get | brackets, standings, tournament totals, per-player history, spirit | one pool, no bracket, no standings, no history — [`STANDALONE.md`](STANDALONE.md) §4 |
| Score and clock | UltiOrganizer's Scorekeeper, or match control per game | match control, which is the only source |
| Read it in | this file and [`PLAN.md`](PLAN.md) | [`STANDALONE.md`](STANDALONE.md) and [`DEPLOY.md`](DEPLOY.md) |

**Nothing above `shared/provider.js` knows which mode it is in.** One renderer, one payload shape — Live!'s, warts included — and two providers behind one function. That is the rule that has kept the modes from becoming two products, and [`STANDALONE.md`](STANDALONE.md) §7 is the full set.

The second rule that makes it work is **absent is not zero**. Standalone has no tournament totals, no history, no blocks, no seeds, no standings and no spirit, because there is no history to have. Those fields are *omitted* rather than sent as `0`, and every consumer already does the right thing with an omission — a player sheet showing this game's numbers and no tournament row is true, where one showing `0 G · 0 A` for somebody who has scored nine is a lie told on air.

Hosted, **no overlay touches the database** — everything goes through Live!'s API over HTTP. That is what makes the whole directory a drop-in that survives a Live! upgrade. Standalone there is no database to touch.

### Seeing it before installing anything

**[ultimate-broadcast.org](https://ultimate-broadcast.org) is a fully deployed standalone installation** on ordinary shared hosting — no UltiOrganizer, no Live!, no database. Every surface renders, match control keeps a real score, and `?demo=1` on any stage or scoreboard URL plays a whole game through: holds, breaks, a timeout, the cap, a running clock. No sign-in, and nothing a visitor does is written.

It is a demonstration rather than a service: nobody else's event is being authored on it, and the two stores that normally take unauthenticated writes are closed there. [`DEPLOY.md`](DEPLOY.md) is how to run one of your own, and most of its sharper notes came from putting that one on a host rather than from planning it.

### Why hosted mode lives in `live/overlays/`

`live/bin/update-from-github.sh` unzips a Live! release **over** the existing tree without deleting files it does not ship. So a self-contained directory alongside Live!'s own code survives every upgrade, and no file Live! ships is ever edited. That constraint is absolute — see `PLAN.md`.

Standalone the question does not arise: the directory *is* the document root, and `Overlays\Mode` is asked for every URL rather than `/live/overlays/` being written into a page. That assumption was in every one of them and broke the moment this directory was served from a root of its own.

---

## Getting started — hosted

### Requirements

- Live! by BULA 3.0.6 installed and a published season — which brings UltiOrganizer 4.0 with it. Live!'s own [Terms of Use](https://github.com/layoutd/live-by-bula/blob/main/Terms%20of%20Use%20-%20Live%20by%20BULA.pdf) must be signed and returned to live@beachultimate.org before use.
- PHP 8.3+, MariaDB 10.11+ (Live!'s own requirements).
- `mod_rewrite` and `AllowOverride All` for the short URLs. The dev image (`docs/dev/Dockerfile.app`) has both. Without them everything still works through the long `?view=live/overlays/...` form.

### Install

There is no build step and nothing to compile. The directory is the installation.

1. **Make `conf/` writable by the web server.** It holds operator-authored state — what is on air, kit colours, shared line selections, and the commentary desk's prepared notes about players — and is gitignored because it is per-installation runtime data, not code. It must stay unreadable over HTTP as well as writable: the `.htaccess` here serves three files by name and 404s the rest, which is what keeps the notes out of a browser.

   ```
   mkdir -p live/overlays/conf
   chown <web-server-user> live/overlays/conf
   ```

   Every page degrades to read-only rather than erroring when it is not writable, and says so.

2. **Optionally add the `/s/`, `/c/` and `/k/` short URLs.** Paste [`install/root-htaccess-snippet.conf`](../install/root-htaccess-snippet.conf) at the end of the host's root `.htaccess`. This is the only file the project cannot install itself, because it belongs to the UltiOrganizer root rather than to `live/overlays/` — and `.htaccess` has no import mechanism, so it has to be pasted rather than included (Apache rejects `IncludeOptional` in `.htaccess` outright and 500s every request under it).

   Mostly optional. `live/overlays/.htaccess` ships with the project and already serves `/live/overlays/702`, `/live/overlays/702/overlay` and the rest, and `?view=live/overlays/...` always works. The short forms exist because the thing typing them is often a switcher's on-screen keyboard — but **the Studio's own Match control link is `/k/<game>`**, which only the snippet provides, so skipping it leaves that link a 404 and match control reachable only as `?view=live/overlays/matchcontrol&game=702`.

3. **Check a URL resolves.** Open `/s/` (or `/live/overlays/` without the snippet). If it 404s, the rewrite rules are not being read — check `mod_rewrite` and `AllowOverride All`, and use `?view=live/overlays/index` meanwhile.

4. **Log in for control.** Anything that changes what is on air needs the Live! admin session from `?view=live/admin`. Read-only viewing never does — a camera operator at a field should not need a password to find a URL, least of all in auto mode where there is no operator at all.

5. **Point the switcher at one URL** and leave it there. In auto mode, with no `conf/show.json` at all, the stage runs the scoreboard and nothing else.

## Getting started — standalone

No UltiOrganizer, no Live!, no database, no Composer, no build step. [`STANDALONE.md`](STANDALONE.md) §6 is what a server needs, read off the code; [`DEPLOY.md`](DEPLOY.md) is putting one on a domain.

### On a laptop, in three commands

```
node tests/capture.mjs --game 702 --out fixtures/payloads/dev   # record a game from a live instance
php install/make-config.php --capture=fixtures/payloads/dev     # prompts for a password, hashes it
php -S 0.0.0.0:8080 -t . app.php                                # serve
```

The repository already ships `fixtures/payloads/dev` — two games, 84 players, both rosters and every player history — so the first command is only needed to record a different instance. Leave `--capture` out entirely and the pages read a live Live! instead.

**`app.php` must be the router**, not merely a file in the directory. `php -S` reads no `.htaccess`, so the rules keeping `conf/` off the network live in that front controller — serve the tree without it and the commentary desk's prepared notes, which are notes about named people, are served on request.

### Authoring your own event

A recording is somebody else's games. Two doors to your own, both writing through `shared/event.php` so the payload shape exists once:

- **In a browser**, signed in: the Studio's **Edit event** button, or `/s/event`. Name, teams, games, the pool's rules. Saved and served immediately. This is the surface for the person the mode is for — running a club's stream, with a laptop and no reason to have SSH anywhere.
- **Over SSH**: `php install/make-event.php my-event.json --set-capture`.

**Squads are deliberately not in that file** — nobody should type forty names into JSON, and the commentary desk already has a door for them. It exports a team's sheet, the team fills it in, and the desk imports it back; standalone that import also *creates* the players it does not recognise, and a field on the same bar types in whoever turned up unlisted.

Team and game ids are yours to choose and must not change afterwards: `conf/score-<game>.json` is keyed by game id, so is every URL typed into a switcher, and a squad and its prepared notes are keyed by team and player id.

### On the open internet

`php install/make-config.php --demo …` closes the two stores that take unauthenticated writes. `lines.php` and `notes.php` are unauthenticated by design — a room code is a namespace rather than a credential, so a commentator can join without an operator in the loop — and on a public domain that means anyone who guesses five characters can write into the prepared notes. Reads are untouched, an administrator still writes normally, and the desk says so before anybody starts typing.

`/s/imprint` names whoever runs the installation, from `conf/local-config.php`, since a publicly reachable site run from Switzerland, Germany or Austria has to. The same page carries an inventory of what the software stores about named people, written from the constants the stores actually prune by.

## URLs

Replace `702` with the game id. The Studio shows the URL for the game you have selected, so these are for reference rather than for typing.

Hosted, the short forms need the root `.htaccess` snippet; standalone they are what `install/standalone.htaccess` and `app.php` serve, and the long form is `app.php?view=…` rather than `index.php?view=live/overlays/…`.

| URL | what it is | mode |
|---|---|---|
| `/s/` | **Studio** — game list, control, colours, logo. Public read-only; controls appear when logged in | both |
| `/s/702/overlay` | **Stage** — the full-frame graphics layer. This is the browser source | both |
| `/s/field/1/overlay` | A stage that **follows whatever is live on field 1**, so a switcher set up in the morning survives every round change | both |
| `/s/702` | **Scoreboard alone**, if you want just the bug with no stage | both |
| `/s/702/green` | Scoreboard on a chroma-key background. Also `blue`, `magenta`, `black`, or any 6-digit hex | both |
| `/s/702/overlay/green` | The same, for the stage | both |
| `/c` | **Commentator** landing — pick a game | both |
| `/c/702` | Commentator page for that game | both |
| `/k/702` | **Match control** — the score and clock, on a phone | both |
| `/s/event` | **Edit the event** — name, teams, games, pool rules | standalone |
| `/s/imprint` | Who runs this installation, and what it stores about named people | standalone |
| `?demo=1` | On any scoreboard or stage URL: plays a whole game through from one real payload. Writes nothing | both |
| `?view=live/overlays/tests/selftest` | Switcher self-test (below). Standalone it is `?view=tests/selftest` | both |

Transparent by default. Use `?bg=` / the `green` form only if the switcher cannot key alpha.

### First checks on real hardware

Do these before a broadcast, in this order — they cost minutes and each rules out a whole class of failure:

1. **The self-test** on the switcher, watching the **program output**, not a laptop. Four panels move independently (JS timer, requestAnimationFrame, pure CSS, network poll). Whichever are frozen tell you which layer the device is not running. "The overlay does not update" has at least five distinct causes and this separates them.
2. **`/s/702?demo=1`** — walks every display state from one real payload: scheduled, live, hold, break, timeout, cap, paused, final. The only way to see a running clock without a live game, and the sharpest test there is, since no polling or server cache sits in the path.
3. **Confirm somebody starts the clock.** Hosted, `timer_start` is only ever written by UltiOrganizer's Scorekeeper; standalone it is written by match control. Either way, if nobody starts it there is no clock on air and the overlay quietly falls back to a status word. Worth a line on the pre-game checklist.
4. **Standalone or through match control, check the board is reading it.** The Studio's **Score from** switch decides, and until it points at match control the scorekeeper's phone shows a banner saying so. A whole game scored into a store nothing reads looks exactly like working.

---

## The documents

Read them in this order if you are new; each assumes the one before it.

### [`PLAN.md`](PLAN.md) — the platform and the scoreboard

The foundation. What Live! v3's API gives an overlay and what it withholds, how a request actually flows, which field names the payloads really use, the polling and caching model, the scoreboard bug itself, and what is still left to build — clock derivation, cap states, timeouts, hold/break classification.

**Go here for:** how the data reaches an overlay, what a field is called, why a poll interval is what it is, the directory layout, and the switcher-compatibility findings.

### [`STUDIO.md`](STUDIO.md) — the stage and the operator

The step from *one overlay per URL* to *one stage per broadcast with an operator deciding what is on it*. Slots, cards, show state and its optimistic locking, the arm-then-show lifecycle, and the control page.

Its long middle section — **"What the data actually supports"** — is the most reusable part of the whole set: every statistic checked against real payloads and the UO schema, with a verdict. It is what stops a card being designed around data that does not exist. Turnovers do not exist anywhere in the schema; blocks do, behind a setting that ships off; break chances cannot be derived at all.

**Go here for:** whether a card can be built, what a slot may contain, why a control is not disabled, and the full cases behind the upstream asks.

### [`POSTPRODUCTION.md`](POSTPRODUCTION.md) — adding an overlay after the fact

Most games are recorded by somebody with a camera and no switcher. This is how that footage gets the same scoreboard afterwards: the alignment problem between a video's timeline and a game's, what UltiOrganizer can and cannot tell you about when a goal happened, and why a fit that is more than twenty seconds out should refuse to render rather than produce something subtly wrong.

**Go here for:** the anchor model, the residual thresholds, and the parts that are designed but not yet built.

### [`COMMENTATOR.md`](COMMENTATOR.md) — the second screen

The information surface for the people calling the game. Prep mode (rosters, team stats, player detail) and play-by-play mode (line selection, who is on the field), plus line sharing between two commentators.

It opens with the observation that governs the whole document: **the incompleteness that blocks a graphic does not block a commentator.** A block count nobody can vouch for is unusable as a lower third and perfectly usable to someone who can say "at least four". That asymmetry is why this surface can show things the Studio cannot.

**Go here for:** what a commentator needs, how line sharing works and why its write is unauthenticated, and the pronunciation and pronoun questions.

### [`UPSTREAM.md`](UPSTREAM.md) — what is wanted from upstream

The digest of asks against UltiOrganizer and Live! by BULA — one entry per ask with what it unlocks, roughly what it costs, and a link to its full case in the documents above. The page to hand to an upstream maintainer, and the text to paste from when opening an issue.

**Go here for:** the upstream wishlist in one place.

### [`STANDALONE.md`](STANDALONE.md) — the overlays without UltiOrganizer

**It runs.** Every surface — Studio, stage, scoreboard, commentary desk and match control — works with no UltiOrganizer, no Live!, no database and no network, and CI proves it on every push. The coupling that made this seem hard turned out to be **two PHP classes, eighteen API reads and a clock that is three integers**; the classes now appear in one file, behind one function.

The part that once looked like the whole project — an editor — turned out to split in two, and neither half needed an authoring application. What a person types once is a small document (`shared/event.php`, from a page or a shell); the squads arrive through a door the commentary desk already had; and the score and clock were already match control's. §4 is what is still missing, and it is now a bracket rather than an editor.

**Go here for:** how to run the overlays with no host, and what is still needed before a tournament could.

### [`DEPLOY.md`](DEPLOY.md) — putting a standalone installation on a domain

The step from `php -S` on a laptop to a PHP host serving the overlays as a site of their own. Three things it exists to get right, each of which fails quietly rather than loudly: the shipped `.htaccess` is for **hosted** mode and would 404 every URL at a document root; `--delete` would take `conf/` and `logos/` apart on every deploy if they were not excluded, and those hold the password hash, what is on air and the desk's notes; and the `conf/` allow-list now exists in three copies that cannot be derived from one another, so a checker keeps them honest.

It is also candid about what a standalone installation can and cannot be today. An event is authored in a browser, squads arrive through the desk's own import and match control keeps the score — but it is one pool with no bracket and no standings, and it cannot read a remote Live!, because every page builds its API URL against itself and sends it same-origin.

**Go here for:** the deploy script, the one-time bootstrap, what to check afterwards, and what a visitor to a public installation is able to change.

### [`SETUP.md`](SETUP.md) — setup, checks and teardown

A concept, with nothing built. These docs ask for a pre-game checklist in **five separate places** — including the same clock item written twice in this file — and no checklist exists anywhere.

The design question it answers is narrow: a fixed list is wrong because every rig differs, and a blank one is a text file. What the software can add is that **it can check some of the items itself** — ten of them are already knowable from state this project reads, including the clock nobody started and the commentator who typed the code wrong. The rest are per-rig and configurable, which is where multiple cameras and different switchers live.

It also covers a **"remind me" mode** during the broadcast, which needs more care than it looks: the case for it is the strongest in the project — the characteristic failure is a graphic quietly asserting something untrue, and those look normal — but a timer that fires during a point is worse than what it guards against. The resolution is to fire at breaks the system already knows about (a score, a timeout, halftime) rather than on a clock, and to detect rather than nag wherever the thing is detectable. Reaching the operator is its own problem, since they are not looking at the page — and **the obvious answer, a beep, is usually wrong**: it leaks into the commentary mic, and firing at breaks puts it exactly when the mic is hot. The ladder runs silent-first, and the honest limit is that a crew watching a hardware multiview cannot be reached by a browser at all.

And it does not stop at the first pull. **Teardown carries the two most expensive mistakes of the day**: an overlay left on air over a finished game — the same "quietly asserting something untrue" failure, and checkable — and the post-production anchors. `POSTPRODUCTION.md` needs at least one *"goal n is at this position in the video"* or footage cannot be aligned at all, and **how many you need depends on how the game was scored** — one for a live Scorekeeper, one per goal for a sheet with clustered times. The software can work out which case you are in; the crew can only act on it before they leave.

**Go here for:** why this is a readiness display rather than a notes app, and the scoping rule that keeps it one.

### [`RELAY.md`](RELAY.md) — state in the browser, server as a relay

**A future direction, not scheduled work** — `STANDALONE.md` is the near-term path and stays server-based. Could the state live in the participating browsers, with a server that only passes messages and stores nothing — cheap to run as a service, with no data to be responsible for?

The encouraging half: **the stores are already most of the way to CRDTs**, not by design but because each was fixed after "two people wrote and one lost". Notes are a per-field last-write-wins map, lines a register per side, possession an append-only keyed log, and a goal is written as the point it creates rather than as `+1`.

Because those stores merge without coordination, a late joiner can take a snapshot from **any** peer — which is what makes a peer-to-peer version viable rather than merely appealing. And on a single LAN, peer-to-peer needs no STUN, no TURN and no internet at all.

What that is worth is narrower than it first appears, and worth stating plainly: **no internet means no stream**, whatever the overlays do. What offline buys is local recording, and — the valuable one — **fault tolerance against an uplink that comes and goes**, so a system that loaded fine does not quietly keep drawing a score from four minutes ago.

It all turns on one unanswered hardware question: whether a switcher's browser source can hold a peer connection. If it can, the design is complete; if it cannot, something on the network must serve HTTP — which is a laptop running `php -S`, the deployment that already exists and is already offline. Hence the reframing worth keeping: **peer-to-peer is not what makes offline possible, it is what removes the need for a local server.** The doc also covers why "no data at rest" is a weaker claim than end-to-end encryption, and the two-tier rule: broadcast surfaces stay conservative, desk surfaces may take dependencies, and neither gets a build step.

It is also honest about the cost, which is not the code — the pages are already JavaScript applications with a PHP header, and much of the store code is file locking that disappears when there is no file. The real price is a second implementation of every rule in a second language.

And it ends somewhere unexpected: **the capability may belong upstream rather than here.** Holding state locally and reconciling when a network appears is exactly what UltiOrganizer's Scorekeeper needs and has none of — server-rendered form posts with no offline storage of any kind, on a phone at a pitch with one bar. Built here it makes the covered games fault-tolerant; built there it fixes every game at every tournament. [`UPSTREAM.md`](UPSTREAM.md) records it as shared ground rather than as a request — neither project is waiting on the other, but both would otherwise solve it twice.

**Go here for:** whether this could be a service, and what would have to be true first.

### [`APPLIANCE.md`](APPLIANCE.md) — a small box that renders the overlay at the field

**A concept, with nothing built**, and deliberately undecided. A pre-configured box renders one of these overlay URLs on hardware we control, instead of inside a switcher's undocumented browser. It has **two shapes read as peers**: the *graphics source*, which stays out of the video path and hands a switcher a keyed overlay for about €60, and the *all-in-one*, which takes the camera feed and streams and records it for €180–270 plus capture, replacing the switcher on a one-camera field.

**Whether the all-in-one gets built is an open question the document refuses to pre-answer** — §12's phase 3 is the experiment, and §12a writes the stopping rule in advance, because an experiment without one is not an experiment. Phases 1 and 2 are worth doing regardless of the result. Read §§4–7 as the list of things phase 3 has to measure rather than as a construction plan.

The cost saving is the least interesting part. What the box actually buys is that **the browser stops being unknown** — the whole reason [`../tests/selftest.php`](../tests/selftest.php) exists — and that it is the local server [`RELAY.md`](RELAY.md) spends a document trying to design its way out of needing. [`STANDALONE.md`](STANDALONE.md) already built the software that would run on it.

Findings that invert the obvious approach. **The Pi 5 is the wrong board**: it dropped the H.264 hardware encoder and the decoder with it, which is exactly this workload, and its faster cores compensate only at `ultrafast` while consuming the machine a browser engine also needs. More generally, **hardware video encoding has quietly regressed across the SBC market** while NPU marketing has advanced — so "newer board, more capable" fails here specifically, and the honest answer for the all-in-one is an **Intel N100**, the only option that offers a real encoder *and* plain mainline Debian instead of making you choose. It costs about what a Pi build costs. **Hardware DRM/KMS planes are an optimisation, not the architecture** — they composite at scanout and an encoder needs a buffer, so they buy nothing for a stream, and audio sync and recording rule them out entirely; GStreamer's `wpevideosrc` already renders a web page into a video pipeline and collapses the hard part into an existing element. **Shipping an OS image is the mistake**, because it means owning an operating system's security updates forever; the update problem is three layers with three owners and only the smallest is ours. And **50 fps is bought for a feature this box does not have** — replay needs an operator no crew size in [`MATCHCONTROL.md`](MATCHCONTROL.md) has spare, the high-quality master is already on the camera's own card, and viewers can scrub a platform's DVR themselves.

Two things it recommends against building, both appealing: an OS image, and a wifi mesh between fields — which would solve a coordination problem the data model deliberately does not have, since state is stored per game precisely so that one field cannot damage another's.

Its organising principle is stated up front in §1a and decides most of the rest: **the goal is not cheaper hardware, it is lowering what an operator has to know.** Cost is a consequence. That is why configuration leaves the field, why recording is always on rather than a button, why audio gain goes to a physical knob, and why **OBS is set aside — on that thesis, not on capability.** Once the board is a normal PC, OBS does most of this already (browser source, replay buffer, VAAPI encoding, streaming, recording, an audio mixer, obs-websocket to drive it), and §7c confirms it runs headless perfectly well. But headless is not the bar — *unattended* is, and a GUI application's error handling is a dialog box, which on a screenless machine is a broadcast that has silently stopped.

So OBS lands somewhere better than rejected: **it is the documented way up, and a better one than buying a switcher.** That produces a ladder ordered by what a crew can do rather than what it can spend — appliance, graphics source, software switcher — where **the bottom two rungs are nearly free because they are what this project already is.** One product to build and two to document. With the uncomfortable corollary that the hardest engineering here serves the crews least able to debug it, which is the point of it and the reason its reliability bar is higher, not lower.

**Local recording is the best value in the document.** A `tee` after the encoder costs nothing, and the resulting file is the only artefact anywhere with the overlay burned in. It also removes [`POSTPRODUCTION.md`](POSTPRODUCTION.md)'s alignment problem for its own footage: a box that knows the wall-clock time of every frame it wrote, and is already polling the API that knows when the goals happened, can emit the anchors itself.

It is honest about the gap: this replaces a browser source, not a switcher. Multi-camera cutting, a battery, a confidence monitor and — the significant one — **replay** go with the Director Mini. Replay is unreachable twice over: no crew size has an operator spare, and scrubbing needs a jog wheel, which is the same finding as audio gain needing a knob. **A show-state store that polls once a second is structurally the wrong instrument for both**, and noticing that pattern is worth more than either instance.

Which is why the document has **two shapes read as peers**. The all-in-one puts the box in the video path and replaces the switcher. **§3b keeps it out of the video path and feeds one** — HDMI out as fill-and-key, or the `/green` chroma form that already exists — so the switcher keeps doing replay, cutting and audio. Every hard constraint in the document comes from being *in* the video path, so the second shape does not merely soften them, it deletes them: no capture device, no encoder, no frame rate problem, and 1080p60 graphics at a frame of latency. It is a €60 accessory rather than a €1,300 replacement, it is where hardware DRM/KMS planes are finally the right answer, and §12 builds it second, before anything is spent.

One finding reaches beyond the appliance: **a stream key would be the first real credential this project stores.** Everything in `conf/` today is a namespace or a note; a stream key is something an attacker can broadcast with, under the tournament's name. The rule that follows — *the tournament's own install holds the tournament's own credentials* — is what keeps this from becoming a service somebody has to operate, and it survives all the way up to §8c's idea of creating platform broadcasts from the schedule, whose real prize is a findable VOD per game rather than one unsearchable eight-hour video per field.

It also answers a question that will be asked again: **an AI HAT does not help.** The Hailo has no video encoder, so it misses the bottleneck entirely, and the statistics it appears to unlock — possession, lineups from jersey numbers — are precisely the claims [`STUDIO.md`](STUDIO.md) exists to refuse. The one transformative use, auto-framing, makes every constraint in the document worse and is a different product.

**Go here for:** why the Pi 4 beats the Pi 5, what the box cannot do, and the phased order in which to find out cheaply — starting with a hub that has no video in it at all.

### [`REPLAY.md`](REPLAY.md) — how replay works, if it is built

**Design notes, nothing built.** The operational half of [`APPLIANCE.md`](APPLIANCE.md) §6c: how a clip is triggered and tagged, how the score and replay event streams are reconciled when they arrive in either order, how a playlist is assembled to fit a stoppage, and what a review surface has to do.

Most of it comes from a workflow that has actually been operated on a Director Mini rather than being proposed here — which is why it is unusually concrete for a document about a feature nobody has approved.

**Two conclusions reach beyond it:** the control surface is about eight keys, so it is a €10 numpad; and the review UI is an application rather than a panel, making it the largest single piece of work `APPLIANCE.md` proposes anywhere.

**Go here for:** why marks are stored and tags derived, why instant air needs an undo rather than a confirmation, and why injury marks an interval and never a clip.

### [`MATCHCONTROL.md`](MATCHCONTROL.md) — score and clock, and who keeps them

**Built.** §0 is what shipped; the rest is the reasoning that preceded it.

Not standalone-specific, and the reasoning is worth reading even though the thing is built. A broadcast crew is one, two, three or four people depending on the day, and the wrong way to allocate the score button is to pick a crew size and design for it. `STUDIO.md` §3.5 already settled the axis — *does this compete with the capturer's main job, or is it their main job* — and for score the answer is whoever is already watching the game, which at two people is the commentator rather than the operator.

The load-bearing conclusion is technical rather than organisational: **a goal must be written as the point it creates, not as `+1`.** A delta entered twice is a real 2–0 from one point; a statement of the result is safe by construction, and that is what lets several surfaces hold the button without anybody having to own it.

What shipped is the simple one: **a single phone-optimised page, nothing embedded** — with keyboard shortcuts in the commentator page as the likely later step rather than a panel, since that page is already keyboard-driven and its play view cannot afford the rows.

It also records a gap found while writing it: timeouts were shown on air and on neither desk. **The commentary desk half is built** — it draws the allowance beside each team — and the Studio half was considered and declined, which `MATCHCONTROL.md` explains.

**Go here for:** who presses what, on which device, at each crew size — and why hosted mode wants the same surface with the score read-only and the clock as a fallback.

---

## Development

### Fixtures

[`../fixtures/dev-fixture.sql`](../fixtures/dev-fixture.sql) creates a tournament to develop against: two 28-player squads, a completed game (700) and an ongoing one (702) at 8–6 with a halftime cap, plus recorded blocks — and an ongoing mixed semi-final (703) with fourteen-a-side squads, for the gender-ratio features and the line picker. Idempotent — safe to re-run.

```
mariadb -uroot -p<root-password> ultiorganizer < live/overlays/fixtures/dev-fixture.sql
```

[`../fixtures/dev-score.sh`](../fixtures/dev-score.sh) adds or removes a goal on the ongoing game so you can watch an overlay update while it is on air:

```
live/overlays/fixtures/dev-score.sh home      # or visitor / undo / show
```

It writes straight to the database, bypassing Scorekeeper. Development only, and hosted only — there is no database standalone.

**Standalone develops against the committed capture** instead: `fixtures/payloads/dev` is those same two games recorded as API responses, which is what `npm run test:standalone` and [ultimate-broadcast.org](https://ultimate-broadcast.org) both serve. `node tests/capture.mjs --game 702 --out fixtures/payloads/dev` re-records it from a running hosted instance after a fixture change. A capture is evidence of what Live! actually sends, so it is re-recorded rather than hand-edited — adjust one and it stops being evidence and becomes a fake with extra steps. Variation belongs in a mutation layer over the recording, which is how `shared/demo.js` already works.

### Settings that change what the overlays can show

**Hosted.** Nothing here is an overlay setting — every one of them belongs to UltiOrganizer, Live! or the pool format, and the overlays simply render whatever is available. Collected in one place because an overlay that "does not show timeouts" is almost always a pool that has none configured rather than a bug.

Standalone none of this table applies: there is no `uo_setting`, no Live! admin and no pool table. The equivalents are authored with the event (the pool's time cap, its timeout allowance, the tournament logo) or are simply absent — blocks, seeds, standings and spirit have no source, so those columns and cards do not appear at all rather than appearing empty. The installation's own two settings live in `conf/local-config.php`: `capture`, which decides where payloads come from, and `admin_hash`, without which nothing can change what is on air.

| setting | where | ships as | what it changes |
|---|---|---|---|
| **Published season** | Live! admin, `LIVE_SEASON_ID` | — | The precondition for everything. An unpublished season means the API returns nothing and every surface is empty |
| `ShowDefenseStats` | `uo_setting`, UO admin | `false` (`sql/ultiorganizer.sql:975`) | On, every roster row gains `deftotal` and the commentator page grows a **Blk** column and a Blocks sort. Off, the field is absent and the column does not exist. Note the `totalavg` trap in `STUDIO.md` §3.1 |
| `TV_SCREEN_LOGO_PATH` | `live/conf/local-config.json` | a sample path | The tournament logo. Its corner is chosen in the Studio; the stage keeps it clear of whatever else is in that corner |
| `TEAM_PHOTOS_ENABLED` | `live/conf/local-config.json` | `false` | On, Live! exposes team photos and the scoreboard will fall back to one when `logos/<team_id>.*` is absent. Those are usually wide squad photos rather than a square mark, so a real logo file beats it |
| `UO_URL_PREFIX` | UO config | `/` | Every route and asset URL is built from this. Set it when UltiOrganizer is installed under a subpath, or the overlays will request their CSS from the wrong place |
| **Pool: time cap** | `uo_pool.timecap`, pool settings | unset | Set, the game clock counts **down** to it and the cap states appear. Unset, the clock counts up and no cap is ever shown |
| **Pool: timeouts** | `uo_pool.timeouts`, `timeoutsper` | unset | The allowance drawn as ticks under each team name. `timeoutsper: "half"` resets the allowance at half time, so only timeouts after the `half_cap` event count in the second half |
| `CACHE_MINUTES_MODULATOR` | Live! admin | `1.0` | How long game data is cached. **Upstream documents this as "do not lower below 1.0"** — lowering it to chase score latency is not the supported route. See `PLAN.md` §6 for what is |

**Two preconditions that are not settings at all**, and between them account for most of "the overlay is not working":

- **A scorekeeper has to start the clock.** `timer_start` is only ever written by UltiOrganizer's Scorekeeper. If nobody starts it there is no clock on air and the overlay quietly falls back to a status word. Worth a line on the pre-game checklist.
- **Games must be marked ongoing.** Live and final are different states, and almost everything transient — the clock, cap states, hold and break tabs, break chance — is deliberately suppressed once a game is not running.

### Tests

```
npm install
npm test                      # the suite, against a running instance
ADMIN_PASS=... npm test       # including the tests that change what is on air
npm run test:unit             # the pure logic alone: no browser, no instance
npm run test:standalone       # routing, guards and a capture-backed page on php -S
npm run check                 # the repository's own checks, exactly as CI runs them
node tests/capture.mjs --game 702 --out fixtures/payloads/dev   # re-record the capture
node tests/modules.mjs        # every shared module loads under CommonJS
npm run shots                 # regenerate the images in docs/images/
```

Playwright, against a running dev instance rather than a mock — the payload shape is exactly the thing that has been wrong before, so a mock would agree with itself and nothing else. It uses the system Chrome, so there is no browser download. The config lives in `tests/`, which is not where Playwright looks by default, so `npm test` passes `--config`.

The assertions are about **geometry, contrast and state transitions** rather than markup, because that is how this code has actually failed. On a 1920×1080 canvas viewed on a laptop, eyeballing is not evidence.

Tests needing the Live! admin session skip themselves without `ADMIN_PASS`, and anything that writes show state restores it afterwards — the suite borrows the same files a real broadcast reads.

### What continuous integration can and cannot prove

[`.github/workflows/ci.yml`](../.github/workflows/ci.yml) runs on every push and pull request: PHP syntax on 8.3 and 8.4, `npm run check`, `npm run test:unit` and `npm run test:standalone`.

**`npm run check` is the repository's own checks, and CI runs exactly that command** rather than a copy of it. That matters more than it sounds: these started as shell inside the workflow file, which meant the only way to run them was to copy them out of YAML — and a check nobody can run locally is a check nobody trusts or maintains. Five of them:

| Check | What it catches |
|---|---|
| [`tests/modules.mjs`](../tests/modules.mjs) | a `shared/` module that stops loading under CommonJS, which takes the whole suite down with an error naming the loader rather than the bug |
| [`tests/suites.mjs`](../tests/suites.mjs) | a spec run by no config, and — the real one — a **pure spec missing from the unit config**, which still passes locally under the hosted suite while never running in CI at all |
| [`tests/links.mjs`](../tests/links.mjs) | a relative link in these documents that no longer resolves |
| [`tests/capture-check.mjs`](../tests/capture-check.mjs) | a committed capture missing a roster or a player history — which fails as "Loading…" forever rather than as an error — or a game absent from the manifest, whose clock then cannot be rebased |
| [`tests/htaccess.mjs`](../tests/htaccess.mjs) | the `conf/` allow-list drifting between its three copies — the hosted rules, the standalone rules and `app.php` — which serves the desk's notes or breaks the stage on one deployment shape only |

Each one was checked by breaking something and watching it fail, which is the only evidence that a check does anything.

The standalone job is the one here that makes real HTTP requests, and it can only exist because standalone mode needs no UltiOrganizer, no Live!, no database and no Apache. It guards two things worth the job on their own: that `conf/` — which holds the commentary desk's notes about named people — is not served over HTTP, and that scorekeeping behaves under the conditions it is actually used in. `php -S` does not read `.htaccess`, so the rules keeping `conf/` closed exist a second time inside `app.php`, and only a request can prove it; and a phone kept scoring through a simulated outage is not something a unit test can claim.

It is also the only place the **administrator path** is exercised at all. Hosted, those tests skip without `ADMIN_PASS`; standalone the password is ours to set, so the login, the code nomination and the source switch are covered here and nowhere else.

**It does not run the browser suite, and since the recorded provider landed that is a choice rather than a limit.** A capture is now enough to render every page with no host at all — the standalone job proves exactly that for the commentary desk — so pointing the whole suite at one would work.

It is not done because of what that would quietly stop testing. The suite drives a real instance because the **payload shape** is the thing that has been wrong before: `teams.hometeam` rather than `awayteam`, `poolinfo.timecap` in minutes, an error body that is a string rather than an object. A capture is real data, which makes it far better than a mock, but it is data frozen on the day it was taken. Run the suite against it and every one of those assertions passes forever — including after Live! changes the shape underneath. That is the mock problem in better clothes, and it would fail worst exactly when it mattered.

So the division is deliberate: **CI proves the logic, the routing, the guards, and that a page renders from a recording. `npm test` against a real instance proves the contract with Live!, and stays a human's job before a branch is called done.** A green tick here is not a green suite.

If that contract ever needs proving automatically, the route is a private Live! mirror with a deploy key as a secret, PHP and MySQL services, `fixtures/dev-fixture.sql` loaded and `ADMIN_PASS` set — noting that secrets are not exposed to workflows from forked pull requests, so it would only run on pushes and same-repository branches.

[`../tests/selftest.php`](../tests/selftest.php) is a different thing: the switcher diagnostic described above, a routed page rather than part of the suite, because it has to be loadable by the device it is diagnosing.

**Every run starts from a configured stage, not an empty one.** `tests/global-setup.js` seeds a logo in a corner, cards placed and on air, both fixture games tracked with a code, a gender ratio and a live stoppage — what a stage looks like ten minutes into a tournament.

That is not decoration. These tests write to the files a real broadcast reads, so "empty" is not a state that occurs in practice, and a test that reads state it did not set passes or fails on history rather than on the code. Eight did over this project's life, each found only when something unrelated moved. Seeding makes the ninth fail immediately. `resetPossession()` is the helper for the commonest case: turning the mode on does not clear a log, so a test asserting on an event count must empty it first.

UltiOrganizer's own harness (`ktolonen/ultiorganizer-tests`) covers UltiOrganizer, not this directory.

### Two rules that keep biting

- **Never edit a file Live! ships.** The upgrade script unzips over the tree; a local patch disappears without warning at the worst possible moment.
- **Never read the database from an overlay.** Everything goes through `live/api`. It is what makes the directory portable, and the one place `STUDIO.md` contemplates relaxing it is marked as an open decision, not a precedent.
