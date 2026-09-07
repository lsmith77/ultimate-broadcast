# APPLIANCE.md — a small box that renders the overlay at the field

**A concept, nothing built, and deliberately undecided.** A pre-configured box renders one of this project's overlay URLs on hardware we control, at the field, instead of inside a switcher's undocumented browser.

**The next two pages are the whole argument in summary. Each line links to the section that makes it** — read only what you want to disagree with.

---

## The short version

**Goal:** make it feasible for tournaments to stream more games, at higher quality — whether that means more fields at one event, or more events covered at once. Professionals with professional kit already achieve this; it is not financially viable for these events. So this is not invention — it is reaching a known standard inside a budget that cannot pay for it. [§1a](#1a-the-thesis-more-games-watchable-within-two-hard-constraints)

**Two constraints, both binding, and they trade against each other:** *money*, counted per field rather than per project, and *people*, because complexity caps how many fields can run at once as surely as price does. The answers worth having satisfy both. [§1a](#1a-the-thesis-more-games-watchable-within-two-hard-constraints)

**What this project uniquely adds is not graphics — it is that nobody keeps score twice.** Commercial tools already draw good scoreboards; they need an operator retyping a score a scorekeeper already entered. These overlays read it and cannot disagree with it — from UltiOrganizer where there is one, and from [`MATCHCONTROL.md`](MATCHCONTROL.md)'s own store where there is not. [§1b](#1b-two-working-rigs-for-reference) · [§11](#11-the-money-stated-honestly)

**It is a grid, not a ladder, and that may be the strongest argument here.** Camera work, camera count, graphics, commentary, replay and uplink are **independent dials**, each set where a tournament's money and people run out — and each position can be filled with commodity hardware or with something specific bought to fix one named pain. The software is the constant. So six fields can be equipped modestly and one properly, on *one system nobody has to learn twice* — which attacks both constraints at once. A fixed bundle cannot do that: you cannot buy a third of a Director Mini, so six fields cost six times €1,300 whether or not five of them ever need replay. [§1d](#1d-it-is-a-grid-not-a-ladder--and-that-is-the-strongest-argument-here)

**Producing replays pays for itself in bandwidth.** Without them, viewers do their own slow motion — which means the high frame rate has to be *in the stream*, forever. Produce them instead and the wire carries 30p, saving three or four megabits per field at every event against the one cost that never stops. A one-time hardware requirement buys a permanent operating saving. [§6d](#6d-replay-needs-the-frame-rate-at-capture-not-on-the-wire)

**Live is an assumption, not a requirement.** Bandwidth caps how many games can be *live*; it does not cap how many can be *covered*, because every box records continuously and a recording uploads off-peak for almost nothing. For the stated goal — more games — that is the cheapest lever in the document. [§10a](#10a-the-network-at-a-field) · [§7a](#7a-local-recording-which-is-nearly-free-and-worth-more-than-it-costs)

**Two shapes, peers rather than plan and fallback:**

| | what it is | cost | needs |
|---|---|---|---|
| **Graphics source** [§3b](#3b-the-other-shape-the-box-out-of-the-video-path) | box stays *out* of the video path, hands a switcher a keyed overlay over HDMI | ~€60 | a switcher |
| **All-in-one** | box goes *in* the video path — camera in, composite, stream, record | €180–270 + capture | nothing else |

Every hard constraint on the *box* comes from being in the video path, so the first shape deletes those rather than softening them — the uplink cost in [§10a](#10a-the-network-at-a-field) belongs to the rig either way. Most of the document is about the second, because it is the one with the questions.

### The two hardware paths, and what each realistically buys

They are not competing for the same job. **The Pi is the right board for the cheap shape; the N100 is the right board for the capable one** — and the money between them is close enough that the decision is really about power and headroom.

| | **Raspberry Pi 4** | **Intel N100** |
|---|---|---|
| **money, built** | ~€120, plus capture if it sits in the video path | €110–140 complete — PSU, SSD, case and cooling included — plus capture |
| **power** | 5–7W · **a full tournament day from one power bank** | 15–20W · 3–4 hours. Most take a 12V barrel jack, but **models with USB-C PD input exist in the same price class** and are what to buy if battery matters ([§10b](#10b-running-off-a-battery)) |
| **what it is genuinely best at** | the **graphics source** and the hub — neither needs an encoder, so none of the hard parts of this document apply | the **all-in-one** — QuickSync does roughly ten times the encoding this needs |
| **as an all-in-one** | works, at 1080p25/30 in hardware, with no headroom | comfortable, with room for a second simultaneous encode |
| **complexity it adds** | **lowest in the document as a graphics source.** As an all-in-one it is the riskiest option: WPE on VideoCore is untested ([§13](#13-what-would-have-to-be-true)'s first line) and capture bandwidth is tight | **fewer unknowns** — plain mainline Debian, better-supported graphics, and a mini PC form factor that removes three of [§10](#10-where-the-director-mini-is-genuinely-ahead)'s field-reliability problems outright |
| **what it opens later** | add a switcher and it becomes the graphics source; replay via a laptop already in the rig | on-box replay — **and the high-rate capture buffer that makes its slow motion smooth** ([§6d](#6d-replay-needs-the-frame-rate-at-capture-not-on-the-wire)) — plus OBS if that fork is taken, and booting from a USB SSD on a borrowed laptop ([§5h](#5h-bring-your-own-hardware--the-option-only-x86-has)) |
| **what it cannot do** | two encodes at once, so no replay on the box | run all day on a battery without planning for it |

**Neither choice has to be made now.** Phases 0–2 are Pi work either way, and the board is a phase-3 purchase made once [§12a](#12a-what-phase-3-has-to-prove-and-what-result-stops-this) has measured rather than argued. Detail in [§5f](#5f-so-is-a-pi-still-the-answer) and [§5g](#5g-the-rung-1-comparison-and-three-things-it-is-easy-to-get-wrong).

### Settled, so not worth re-litigating

- **Build the graphics source before the all-in-one** — cheaper and simpler, and its topology already runs in a real rig. [§3b](#3b-the-other-shape-the-box-out-of-the-video-path)
- **Never ship an OS image.** An `apt` package on a stock OS; an image means owning an operating system's security updates. [§9](#9-maintenance-do-not-build-a-distro)
- **OBS with its UI visible is the documented way up** for crews that have the skills — better than buying a switcher. Whether OBS should also be the appliance's hidden engine on x86 is open, not settled, and is one of phase 3's first questions. [§7b](#7b-the-fork-5d-opens-obs-instead-of-a-pipeline)
- **Configuration comes from the tournament's own install**, never a service we run — so no new party holds anybody's credentials. [§8b](#8b-most-of-this-should-not-be-configured-at-the-field-at-all)
- **Automated camera operation is bought, not built.** [§4d](#4d-automated-cameras-and-what-an-rtmp-only-source-demands)
- **A capture device is always required** — no board here has an HDMI input, and that cannot be worked around. [§4](#4-video-in-yes-you-always-need-a-capture-device)
- **No wifi mesh, and no AI accelerator.** Both look attractive and neither survives contact with the problem. [§10a](#10a-the-network-at-a-field) · [§5e](#5e-would-a-pi-5-ai-hat-help)

The engineering decisions underneath these — frame rate, compositing method, credential handling, control surfaces — are argued in the body rather than summarised here.

### Leaning, not decided

| | leaning | where |
|---|---|---|
| Getting video in | network transport beats HDMI on cabling, power and placement — but costs an encoder per camera unless the camera speaks it natively | [§4c](#4c-why-network-input-wins-on-the-field-not-just-in-the-code) |
| Replay | off the box: about €10 wherever a laptop is already in the rig, expensive or absent on a bare appliance | [§6b](#6b-replay-off-the-box-entirely--cheap-where-there-is-a-laptop-not-otherwise) |
| Driving the platform from the schedule | a findable VOD per game is the real prize; OAuth verification is what it lives or dies on | [§8c](#8c-driving-the-platform-from-the-schedule) |

### The one thing deliberately open

**Whether the all-in-one is built at all.** [§12](#12-what-to-do-first)'s phase 3 is the experiment and [§12a](#12a-what-phase-3-has-to-prove-and-what-result-stops-this) writes the stopping rule in advance. Phases 0–2 are worth doing regardless of the answer, so read §§4–7 as what phase 3 must measure, not as a construction plan.

### Do this next

| phase | what | needs buying |
|---|---|---|
| **0** | talk to the DFV — a federation already publishes a rig, and reaching more fields may need no box at all | nothing |
| **1** | the hub: a Pi on the field network running `app.php`, no video | nothing |
| **2** | the graphics source — the first thing usable at a tournament | nothing |
| **3** | bench the streaming pipeline; **[§12a](#12a-what-phase-3-has-to-prove-and-what-result-stops-this) decides here** | nothing |
| **4** | HDMI in, then audio, then packaging | yes |

Full detail in [§12](#12-what-to-do-first). Every untested claim the above rests on is inventoried, grouped by the phase that answers it, in [§13](#13-what-would-have-to-be-true).

### Scope, recorded because several sections only make sense against it

- **A product other tournaments deploy**, not a rig for one crew — which is what justifies [§9](#9-maintenance-do-not-build-a-distro)'s maintenance model and makes [§8c](#8c-driving-the-platform-from-the-schedule)'s OAuth problem hard. But the unit is a rig, not a field, and most rigs are single-field: a federation replacing one travelling rig with three is as good a reason to build as one tournament equipping six fields ([§1a](#1a-the-thesis-more-games-watchable-within-two-hard-constraints)). A fourth audience — **DIY, attached to no association** — is served by what already exists rather than by the box, and its value depends on whether the event runs UltiOrganizer rather than on who the filmer is ([§1a](#1a-the-thesis-more-games-watchable-within-two-hard-constraints)).
- **Stream and archive matter equally.** A venue screen does not, which is what confines hardware planes to [§3b](#3b-the-other-shape-the-box-out-of-the-video-path).
- **Two roles.** Somebody technical installs; volunteers operate. The thesis targets the tournament morning, not the install — a narrower and far more achievable target.

---

## How to read this

Thirteen numbered sections in seven groups. Lettered subsections (§3b, §5d) are sub-parts of the number they carry, and the cross-references throughout use those labels.

| group | sections | what is in it |
|---|---|---|
| **Why** | §1–§2 | the idea, the thesis (§1a), two working rigs at opposite ends of the constraints (§1b), and what this project uniquely adds |
| **The comparison** | §3 | a Director Mini's capabilities one by one, and where each lands here — plus §3b, the *other shape* |
| **Getting video in** | §4 | the capture device you always need, transport and cabling (§4c), what the cameras dictate (§4d) |
| **Hardware** | §5–§6 | which board and why the obvious answer is wrong, frame rate, and replay |
| **Software** | §7 | compositing, recording, and whether OBS should just do this (§7b) |
| **Running it** | §8–§10 | configuration and credentials, maintenance without owning an OS (§9), what fails at a field |
| **Deciding** | §11–§13 | cost, the plan (§12), the stopping rule (§12a), every untested claim (§13) |

---

## 1. The idea

One box, pre-configured, on the field network:

- **Video in**, either over HDMI through a capture device, or over the network as NDI, RTMP or SRT.
- **The overlay is a URL** — the stage, exactly as a switcher's browser source consumes it today.
- **Composited on the box** and encoded out to the platform.
- **Ethernet to the router**, which is already there because everything else here needs it.
- **Configured from the Studio**: which video source, and which overlay URL. The same page that already decides what is on air.

And the part that is not about video at all: if a box is on the field network anyway, it can be the thing everything else talks to — the local hub, rather than a set of browsers finding each other.

### 1a. The thesis: more games, watchable, within two hard constraints

**The goal is to make it feasible for tournaments to stream more games, at higher quality.** Not one showcase field — more of them; and not merely present — watchable.

**The benchmark is not in doubt, and that is the point.** Hiring professionals with professional kit achieves all of this today and always has. It is simply not financially viable for the events this is for. So this is not an attempt to invent something; it is an attempt to reach a known standard inside a budget that cannot pay for it.

#### Two constraints, and both bind

Neither is the goal. Both are what stand between the goal and the tournaments that want it.

- **Money — per field, not per project.** A tournament with six fields and the budget for one has not solved anything, so the number that matters is what it costs to add the *next* field.
- **People.** You cannot train and support hundreds of volunteers across dozens of events. Complexity does not merely annoy an operator; it caps how many fields can run at once, as surely as price does — and unlike hardware, it keeps costing after it is paid for.

**They trade against each other, and that tension is what this document is actually about.** Cheap usually means fiddly: a €200 box with a capture device and a pipeline, against a €1,300 appliance that simply works. Simple usually costs, because the Director Mini is expensive *precisely* for having solved the hard parts. Either constraint alone is easy to satisfy, and neither alone puts a second field on air.

**So the answers worth having satisfy both** — and read that way, most of this document's conclusions turn out to be instances of one pattern rather than separate judgements:

- **Both, and therefore ranked first.** §3b's graphics source is cheaper *and* simpler. §9's `apt` over an image is cheaper to maintain *and* one fewer thing to explain. §1c's €10 numpad beats a €150 Stream Deck on price *and* on having no software to install.
- **Cheap but complex, and therefore rejected or ranked last.** §7b's OBS is free and asks an operator to know what a scene is. §4d's cheapest cameras save money and cost a quality generation plus a wifi hop.
- **A genuine trade, and therefore genuinely undecided.** §5d's x86 costs more and removes a whole class of difficulty. That is exactly why §12a leaves it to a measurement rather than an argument.

#### The people constraint is about the tournament morning, not the install Per the scope note above there are two roles: somebody technical installs a box, and volunteers run it on the day. That distinction does real work — it means the install may be an `apt` command and an enrolment token (§9, §8b) without violating anything, while **the operating surface has to be usable by somebody who will not open a terminal and cannot read a log.** The two most expensive consequences are that every failure has to be visible in the Studio rather than in `journalctl`, and that §10's missing confidence monitor stops being a nice-to-have.

#### Four audiences, and the unit is a rig

The constraint above says *per field*. **More precisely the unit is a **rig, and a rig may well cover a single field — which matters because the demand comes in three shapes and only one of them is about fields at all.

**One rig, one field.** The commonest case by far: a club tournament streaming one game at a time, or a large one streaming only the final. Almost everything this project offers is scale-independent and lands here in full — nobody keeps score twice, the browser stops being unknown, the overlay can run itself from scorekeeper actions, the recording is a publishable VOD, replay is data-driven. And it is served today, by [§12](#12-what-to-do-first)'s phases 0–2 and by overlays that already exist, with nothing built.

**One tournament, several fields.** Here the per-field arithmetic dominates and [§11](#11-the-money-stated-honestly) applies as written: six fields with the budget for one is not solved, so what counts is the cost of the next field.

**One organisation, several rigs.** The DFV currently has one rig, shipped from event to event, which caps coverage at one tournament at a time regardless of how many fields any of them has. A cheaper rig that is easier to maintain is not a saving on that rig — it is permission to have three. Three rigs cover three weekends' events simultaneously, which is [§1a](#1a-the-thesis-more-games-watchable-within-two-hard-constraints)'s goal reached along an axis that has nothing to do with fields.

**DIY, attached to no association at all.** Somebody filming their own club's games, or a supporter with a camera at a tournament nobody is covering. This shape behaves unlike the other three and is worth stating separately.

**Its value depends entirely on whether the event runs UltiOrganizer, and not at all on whether the filmer is part of anything.** Where it does, a DIY filmer reads the public API and gets an accurate scoreboard for free, with no permission and no coordination — the differentiator arrives intact from an event they have nothing to do with. Where it does not, the case is **no longer the weak one it looks like**: [`MATCHCONTROL.md`](MATCHCONTROL.md) keeps the score in this project's own store, on a phone at `/k/<game>` with an offline outbox, and the overlays read it. So the score is still entered once and still drives the graphics — **the differentiator survives without an upstream**, which is what [`STANDALONE.md`](STANDALONE.md) and [`DEPLOY.md`](DEPLOY.md) exist to make possible.

**Its cheapest form is not the appliance and not OBS.** Two action cameras recording to their own cards, processed and overlaid afterwards ([§4d](#4d-automated-cameras-and-what-an-rtmp-only-source-demands)), needs no box, no network at the field, no mains and nobody operating anything — and [`POSTPRODUCTION.md`](POSTPRODUCTION.md)'s alignment problem becomes a subtraction because the cameras stamp their own footage. **For somebody with no association, no budget and no crew, that is the whole product.**

**It also inverts the people constraint rather than straining it.** A DIY user is self-selected and motivated; nobody handed them a box on a Saturday morning. So they tolerate complexity a volunteer cannot, which puts them naturally on the **top rung** — their own laptop, OBS, and the overlay URLs — rather than on the appliance. **They need documentation and public availability, not a federation partnership**, so [§12](#12-what-to-do-first)'s phase 0 does not apply to them and phases 1–2 do.

**What they cannot have is support.** Unaffiliated users on unknown hardware are precisely [§5h](#5h-bring-your-own-hardware--the-option-only-x86-has)'s known-good-list problem, and pretending otherwise would create an obligation nobody can meet. Set the expectation instead: **it works, it is documented, and it is best-effort.** In exchange they are the channel through which the other three shapes discover this exists, and the people most likely to find its bugs.

**Which resolves an apparent contradiction in [§11](#11-the-money-stated-honestly).** That section says the economics only turn positive at scale, and that for a single rig buying a Director Mini is cheaper. Both are true, and they are statements about the decision to *build*, not about who the result is for. The scale that justifies building is satisfied by *many rigs* just as well as by many fields — so a federation wanting three travelling rigs is exactly as good a reason as one tournament wanting six fields. Every one of those rigs may be single-field.

**Two things follow for the travelling case**, and [§10d](#10d-circulating-a-rig-without-it-coming-home) covers the logistics. A travelling rig is packed and unpacked by different people at every event, so it must carry no event-specific state — which is precisely what [§8b](#8b-most-of-this-should-not-be-configured-at-the-field-at-all)'s central provisioning delivers: the rig learns which field it is on arrival rather than being reconfigured before shipping. And several rigs need several update paths, which [§9](#9-maintenance-do-not-build-a-distro)'s `apt` model handles far better than posting cards around.

**And one argument the cost framing misses entirely.** For a club tournament with €200 rather than €2,000, this is not a saving — it is the difference between streaming and not streaming at all. That is an access argument rather than an economic one, it applies only at shape (a), and it is arguably the strongest case the project has.

#### A third cost, and where it ranks

The two constraints above are money and people. There is a third that this document weighs constantly without naming — **how much there is to build and maintain** — and it needs a place in the order, because it does not always point the same way.

**The ideal is one platform that scales up and down with minimal change.** One codebase across both shapes, both boards and every rung; anything else means two sets of bugs, two things to keep in step, and features that exist on one side and not the other. That pressure is real: it is why [§3b](#3b-the-other-shape-the-box-out-of-the-video-path) and the all-in-one deliberately share almost everything, and why [§7b](#7b-the-fork-5d-opens-obs-instead-of-a-pipeline)'s engine question matters at all.

**But when it conflicts with operational simplicity, operational simplicity wins.** The asymmetry is not close:

- **Build complexity is paid once**, by one person, at a keyboard, with a debugger, at whatever pace they choose.
- **Operational complexity is paid at every event, by every crew, at a field, under time pressure, by somebody who cannot debug it** — and per the people constraint above it caps how many fields can run at once. Duplicated code caps nothing; it only costs maintainer hours.

So the order is **money and people first, effort third** — and effort loses outright when the trade is against the tournament morning.

**Mostly the three agree**, which is why the conclusions here have been fairly consistent: the numpad, the router-not-the-box, `apt`-not-an-image and the two shapes sharing a codebase are all simultaneously cheaper, simpler to run *and* less to maintain. The one place they genuinely pull apart is [§7b](#7b-the-fork-5d-opens-obs-instead-of-a-pipeline), where adopting OBS as a hidden engine would halve what there is to build while importing a failure mode nobody at a field can see. This rule decides that case rather than leaving it to taste.

#### The ladder that falls out

Ordered by **what the crew can do** — the people constraint made concrete. It is the useful axis precisely because money alone would rank these differently, and the cheapest rung is not the one most crews can actually run:

| tier | the crew has | what they run | new work |
|---|---|---|---|
| **Appliance** | nobody with broadcast skills | the box — most of this document | all of it |
| **Graphics source** | someone who can run a switcher | §3b: a Pi feeding their switcher | small |
| **Software switcher** | someone who can run OBS | today's overlay URLs inside OBS, plus phase 1's hub | almost none |

Two things follow from reading it this way.

**The bottom two rungs are nearly free, because they are what this project already is.** A skilled OBS operator needs the overlay pages and a local hub; the pages exist and the hub is §12's phase 1. So this is not three products to build — it is one to build and two to document, and the documentation is worth writing regardless of whether the appliance ever ships.

**And the uncomfortable one: the hardest engineering here serves the crews least able to debug it.** The appliance carries every risk in this document and is aimed at exactly the people who cannot work around it when it misbehaves at a field. That is the point of it — and it is also why its reliability bar is higher than a system for experts, not lower, and why §12 is ordered to deliver the easy rungs first.

### 1b. Two working rigs, for reference

Everything above is reasoning. These are rigs that actually run, and they are worth more than another argument because they show which of these problems people solve and which they route around. **They sit at opposite ends of §1a's two constraints**, which is what makes the pair more useful than either alone.

| role | **A — the DFV reference build** | **B — a one-person rig** |
|---|---|---|
| cameras | two, hand-operated, each with a monitor; **optical HDMI** back to base | **Pix4Team 2 + Sony camcorder** — the robot does the operating |
| switching | an **ATEM** | **Magewell Director Mini** |
| graphics | **MacBook + H2R Graphics** on a **Stream Deck** | the Director Mini |
| replay | **HyperSlow on an iPhone**, driving Blackmagic **HyperDecks** | the Director Mini |
| audio | two headsets with microphones | **Ulanzi AM18 wireless lavalier kit**, into the camera |
| network + uplink | a Fritzbox, plus **prepaid LTE at €10/day** | a **GL.iNet 5G router**, doing both |
| crew | several | **one** |

**Rig A is a federation's reference build**, documented by the DFV, the German flying disc association — used across events, written down for people who did not design it, and representing what an association believes a volunteer can be handed. That is precisely the audience the scope note names.

**Rig B is the more directly relevant benchmark, because it is what the appliance is actually trying to be.** One camera that operates itself, one box doing graphics, switching, replay, recording and streaming, one cellular router, one person. On a one-camera field the Director Mini's job list *is* the appliance's scope — which makes this the sharpest test case in the document.

**And between them they isolate the problem exactly.** Rig B already achieves §1a's goal — more games, watchable, with almost nobody — and proves the quality and crew-size halves are solvable today. What it cannot do is happen six times, because at roughly €2,000 a field nobody equips six fields. Rig A reaches a comparable result by spending people instead of money, and cannot scale for the opposite reason.

**So neither constraint is theoretical and neither rig fails on quality.** They fail on the two things §1a names, one each. That is the whole case for this document, demonstrated rather than argued.

Four things they settle, three of which are argued in full elsewhere and are listed here only because these rigs are the evidence for them:

- **The control-surface pattern**, confirmed independently — a Stream Deck drives the graphics rather than a web UI ([§1c](#1c-where-a-hardware-control-earns-its-place-and-where-a-10-numpad-does)).
- **Replay as a separate appliance** — though HyperSlow records nothing itself, it drives Blackmagic HyperDecks, so the capability is €600–800 rather than the price of an app ([§6b](#6b-replay-off-the-box-entirely--cheap-where-there-is-a-laptop-not-otherwise)). A HyperDeck fed from an ATEM output most likely records clean, which would mean rig A solved [§3c](#3c-the-frozen-scoreboard-over-a-replay)'s frozen scoreboard by architecture.
- **Optical HDMI is a real alternative to network transport** — uncompressed, no encode hop, real money ([§4c](#4c-why-network-input-wins-on-the-field-not-just-in-the-code)).

**The fourth is the one stated nowhere else as sharply: H2R Graphics already does the graphics, commercially, today.** So the overlay layer is not this project's differentiator — **the data is.** H2R needs an operator typing a score a scorekeeper already entered, with a second chance to get it wrong; these overlays read it and cannot disagree with it. **Nobody has to keep score twice.** That is the value proposition, and [§11](#11-the-money-stated-honestly) carries it because any budget comparison that ignores it is arguing about the wrong thing.

#### And the ATEM places it on the ladder — at the middle rung, not the top

With a hardware switcher in the rig, the parts list resolves into a familiar shape. Two cameras cut on the ATEM; the MacBook produces graphics; the noted *"2 × HDMI cables, for monitor and MacBook"* most plausibly means **the MacBook's HDMI output going into an ATEM input**, keyed there, with the ATEM streaming. A MacBook has no HDMI input, and the ATEM already offers itself as a USB camera, so HDMI *into* the laptop would be the odd choice.

**If that reading is right, this rig is already doing §3b** — a computer out of the video path, handing a switcher a keyed graphics layer. That changes §3b's status materially: it is not a speculative shape, it is a proven topology, and the Pi version is a cost reduction of something that already works rather than a new idea. It is the strongest single piece of evidence in this document, and it argues for phase 2 more than any reasoning in §12 does.

It also corrects where this sits on §1a's ladder: **middle rung, not top.** A skilled operator with commercial tools, yes — but with a hardware switcher doing the cutting and a laptop where we would put a €60 board.

#### What a federation reference build changes strategically

Three things, and the third is the one that should change the plan.

**This project is not entering a vacuum.** A federation already publishes a recommended rig, which means any proposal here is an alternative to something an association stands behind and clubs have learned. That is a harder position than "nobody has solved this", and pretending otherwise would produce a worse plan.

**But it is also the distribution channel.** The scope note says this is meant to be a product other tournaments deploy, and the hardest part of that is normally reach. A federation that already documents a rig is exactly the party that would adopt, extend, or decline — and there is one, named, with a published document.

**Which points somewhere other than the appliance.** §1a's ladder concluded *one product to build and two to document*; the provenance makes the documentation half concrete. The fastest route to many tournaments is almost certainly not a new box — it is making these overlays work inside the rig a federation already recommends, and getting that written into their document. The MacBook is already there, the ATEM is already there, and the overlay is a URL. That is a smaller ask than anything in §12 and it reaches more fields than the appliance would.

The right posture follows: **add to their rig, do not compete with it.** These overlays drop into the MacBook they already carry, as a browser source or over the HDMI that already runs to the ATEM.

#### The pain worth confirming first

§1b's H2R observation now has a named owner. If DFV events run on UltiOrganizer, then **somebody at those events is keeping score twice today** — once in the scorekeeper, once typed into H2R — with a second chance to be wrong on air. That is a real, current, addressable problem rather than a hypothesis, and it is the single thing most worth checking before anything else in this document is built.

**If DFV does not run UltiOrganizer, that is the prerequisite** and it is much larger than any hardware question here.

**Two more things worth asking the people running it**, because both are live questions here that they have real answers to:

- **Which ATEM, and does its recording or streaming tap the program feed?** That decides whether §3c's frozen scoreboard actually bites them, and how they handle it. A real answer beats the three theoretical ones in that section.
- **What does HyperSlow record from** — its own camera, or a feed? That determines whether their replays carry the burned-in overlay, which is the same question from the other end.

### 1c. Where a hardware control earns its place, and where a €10 numpad does

This document has now concluded three times that something *belongs to hardware with knobs on it* — audio gain (§3a), replay scrubbing (§6a), graphics firing (§1b). That is a pattern worth trusting, but not without the other half: **hardware also costs money, gets carried, gets left behind, and breaks.** A control surface that fails at a field is worse than one that was never there, because the crew has built a habit around it.

The distinction that resolves it is not price, it is **what kind of task it is**:

| task | continuous? | hardware | why |
|---|---|---|---|
| **Audio gain** (§3a) | yes, latency-bound | **mandatory** | cannot ride a fader over a one-second poll |
| **Replay scrubbing** (§6a) | yes, latency-bound | **mandatory** | hand and picture must agree in tens of ms |
| **Firing a graphic** (§1b) | **no — discrete** | **optional** | a click already works; hardware only makes it faster |

**For the two mandatory rows, the hardware exists anyway** — the audio interface has to be there to get mic signal in, and §1b's replay device brings its own touchscreen. The knobs are free, and neither adds a component that was not already required. Those two do not answer to this objection.

**The third row does, and it is the expensive one.** Switching a lower-third on air is discrete and has no latency requirement. The Studio already does it. A Stream Deck at €100–150 is buying muscle memory and not having to find a window — real, but not structural.

#### The numpad is the better answer, and not only on price

A USB numpad is €10–15 against €100–150, and the cheapness is the least of it:

- **It is a plain HID keyboard.** No driver, no companion application, no background service, nothing to install on a headless box. A Stream Deck on Linux needs software that is one more thing to fail on an appliance.
- **A spare costs €10.** That is the actual answer to "hardware breaks" — at €150 you do not carry a second one, at €10 you do, and the failure stops being an incident.
- **Plug it into the operator's laptop, not the box** — and then it needs *no new code at all*. A numpad is a keyboard, the Studio is a web page, and `AGENTS.md` already mandates the pattern: *a keyboard shortcut or a click gesture → the Keys reference*, because a gesture not listed there does not exist as far as a user is concerned. This is an existing capability with stickers on it, not a feature.
- **Print the label card from the mapping.** Fixed keycaps under pressure are how the wrong graphic goes on air; a card generated from the actual shortcut configuration cannot drift from it, which is the same change-one-change-its-pair discipline the Keys reference exists for.

What a Stream Deck genuinely buys and a numpad cannot: **dynamic labels, and on-air state on the key itself.** For a crew running many cards those matter. For one that fires four, they do not.

#### The rule that makes any of this safe

**One caveat on latency, since it is easy to conflate with this.** A keypress reaches air through the show store and its ~1s poll, so a numpad is fast to *use* and not instantaneous in *effect*. [§2](#2-why-this-is-interesting-here-and-it-is-not-mainly-the-money) notes a box can push instead of polling, which removes that second for discrete actions. **It does not change anything above** — push is still not a jog wheel or a fader, so continuous controls stay on hardware regardless.

**The Studio stays fully capable, always. Hardware accelerates; it never gates.** If the numpad is forgotten, unplugged or dead, somebody uses the laptop and the broadcast continues — which is the same degrade-rather-than-fail posture the rest of this project takes when `conf/` is unwritable or the uplink drops. A control surface may never become the only path to anything, and that constraint is what lets hardware be added cheaply and removed without ceremony.

---

### 1d. It is a grid, not a ladder — and that is the strongest argument here

§1a's ladder has three rungs, which implies choosing one. **That is too coarse.** What is actually on offer is several dials that move independently, and a tournament sets each one where its money and its people run out.

| dial | cheapest | → | most capable |
|---|---|---|---|
| **camera work** | a fixed wide shot | XbotGo-class AI framing | Pix4Team + camcorder | a human operator |
| **cameras** | one | | two or more, which needs a switcher |
| **graphics** | **fully automatic from scorekeeper actions** | an operator adding cards | a full studio operator |
| **commentary** | none, ambient audio only | one wireless mic | two, with a desk |
| **replay** | none | OBS buffer + a numpad | HyperDeck or switcher-native |
| **uplink** | venue wifi | an LTE day pass | a 5G router |

**And the automatic end of the graphics dial is not aspirational — it already ships.** With no `conf/show.json` at all the stage runs the scoreboard and nothing else, driven entirely by what the scorekeeper enters. The bottom-left corner of this grid works today.

#### The second dimension: commodity hardware, or specific hardware to fix a specific pain

Every position above can be filled with something a tournament already owns or something bought to solve one problem. **The software is the constant; the hardware grade is a dial of its own** — which is what §5h's boot-from-a-USB-SSD makes literal.

The useful way to spend, given money is counted per field, is **not a shopping list but a symptom list**:

| what hurts | what fixes it |
|---|---|
| nobody spare to operate a camera | XbotGo, or Pix4Team + camcorder ([§4d](#4d-automated-cameras-and-what-an-rtmp-only-source-demands)) |
| long HDMI runs, trip hazards, no power at the tripod | a network encoder, PoE ([§4c](#4c-why-network-input-wins-on-the-field-not-just-in-the-code)) |
| graphics too slow to fire by hand | a €10 numpad first, a Stream Deck only if that is not enough ([§1c](#1c-where-a-hardware-control-earns-its-place-and-where-a-10-numpad-does)) |
| encoder has no headroom | x86 instead of a Pi ([§5d](#5d-off-the-pi-entirely-x86-which-is-cheaper-than-it-sounds)) |
| the stream drops | a 5G router ([§10a](#10a-the-network-at-a-field)) |
| no replay | a laptop already in the rig, plus OBS ([§6b](#6b-replay-off-the-box-entirely--cheap-where-there-is-a-laptop-not-otherwise)) |

**Spend where it hurts, not everywhere.** That is the whole difference from buying a bundle.

#### Why this attacks both constraints at once

**Money**: a minimal field is very cheap and a showcase field is not, so a tournament can equip six fields modestly and one properly — instead of equipping one and stopping.

**People**: it is one system across all of them. A crew that learns the minimal setup has already learned the full one, and a field can be dialled up mid-season without anybody relearning anything. That is the constraint §1a says complexity attacks, answered by not having a second product.

**The contrast is sharp.** A Director Mini is a fixed bundle — you cannot buy a third of one, so six fields cost six times €1,300 whether or not five of them ever need replay or a second camera. Composability is the thing the incumbent structurally cannot offer, and it matters more than any per-unit price in this document.

#### Where the dials are not independent

Three couplings, worth knowing because they are where a tournament gets surprised:

- **More than one camera means a switcher**, which makes the box a graphics source ([§3b](#3b-the-other-shape-the-box-out-of-the-video-path)) rather than the all-in-one. That is the largest coupling in the grid.
- **Replay needs the video**, so it lives wherever the video is — the box, or a laptop running OBS.
- **Commentary has a range limit.** Wireless mics into the camera are the cheap answer ([§3a](#3a-audio-and-why-it-is-smaller-than-it-looks)) and stop working when the desk is far from the camera.

### 1e. Other sports, and where the boundary actually falls

Worth settling because it comes up whenever a partner is soccer-focused, and the answer is not the obvious one.

**Almost nothing in this document is about Ultimate.** The box, the pipeline, the encoding, the recording, the replay design in [`REPLAY.md`](REPLAY.md), the provisioning model, the maintenance model, the uplink arithmetic — none of it knows what sport it is carrying. **The appliance is a graphics-and-streaming box that happens to be fed by an Ultimate data source.**

**The sport-specific part is the overlays, and that is exactly where the value is.** Score semantics, hold and break, gender ratio, timeouts, caps, what the data does and does not support — `AGENTS.md`'s hard-won account of that is Ultimate knowledge, and it is what makes these graphics right rather than merely present. The read path is already isolated in `shared/provider.js`, so the seam exists; **it is the knowledge behind it that does not transfer.**

**Which is why generalising is a worse idea than it looks.** The differentiator is being wired into the sport's own scorekeeping, and for soccer that means a fragmented world of systems this project has no standing in — against dozens of established competitors. **Being the only good option for Ultimate is worth more than being the fiftieth for soccer.**

**But that is a product argument, not a technical one, and it does not block a partnership.** A soccer-focused supplier can be a technical partner or a distribution channel without this project growing a soccer mode: their camera feeds this box, our overlays stay Ultimate's. The generality is already there in the half that would need it.

## 2. Why this is interesting here, and it is not mainly the money

Three reasons, and the cost saving is the weakest of them.

**a. It retires the unknown browser.** `AGENTS.md` puts it plainly: what is genuinely unverified in this project is the hardware, not the syntax — *"Magewell and Yolobox publish nothing about their embedded engines"* — and [`../tests/selftest.php`](../tests/selftest.php) exists solely to find out, on the device, which layers a switcher is actually running. The failure it detects is not "an overlay looks wrong" but "an overlay never updates". On a box we build, the engine is pinned, inspectable and the same at every tournament. An entire category of pre-broadcast doubt disappears, and the two-tier rule in `AGENTS.md` — broadcast surfaces stay conservative because they run on an engine nobody can inspect — loses its reason to exist for any rig using this box.

**b. It answers [`RELAY.md`](RELAY.md) by removing its question.** That document turns on one unresolved hardware fact: whether a switcher's browser source can hold a peer connection. Its conclusion is the sentence worth rereading — *"peer-to-peer is not what makes offline possible, it is what removes the need for a local server."* If a Pi is in the rig for video reasons, the local server is already paid for. The peer-to-peer work becomes an optimisation rather than the thing the offline story rests on, and the fallback `RELAY.md` names — "a laptop running `php -S`" — stops being a laptop somebody has to remember to bring.

**c. The software that would run on it is already built.** [`STANDALONE.md`](STANDALONE.md): every surface runs with no UltiOrganizer, no Live!, no database and no network, behind `app.php` — and [`DEPLOY.md`](DEPLOY.md) puts such an installation on a domain of its own. **[`MATCHCONTROL.md`](MATCHCONTROL.md) goes further and keeps the score and clock here**, in this project's own store, so the live half of a broadcast no longer depends on an upstream at all. The appliance is that deployment with a box around it. This is not a new codebase; it is a chassis for one that exists.

**d. It makes push possible, which the hosted deployment cannot do.** Today the overlays poll, and `AGENTS.md` is emphatic about why: show state is a *static* `conf/show.json` read at about a second *"because an operator's click must feel instant"*, and *"do not route show state through PHP"*. **That rule is correct and is a consequence of the deployment**, not a preference — PHP-FPM behind Apache, and [`STANDALONE.md`](STANDALONE.md)'s `php -S`, cannot hold a connection open. A box we control runs a persistent process and can, which makes a WebSocket relay available for the first time. [`RELAY.md`](RELAY.md) is the document about a server that only passes messages; **this is a small piece of that direction arriving as a side effect.**

**Be precise about what it buys, because it is narrower than it sounds.** It removes up to a second from *operator actions* — a card going on air, and in particular [§6c](#6c-replay-is-where-the-data-advantage-is-decisive)'s replay trigger, where instant air on a button press is the whole design and a one-second poll cannot deliver it. **It does nothing for score latency**, which is bounded by the API's own cache life rather than by how the client asks.

**And it can only ever be an enhancement.** The overlays must keep working with no box — hosted, standalone, on somebody's laptop — so polling stays the floor and push is what the client prefers when a relay answers. That is a second path to maintain, which [§1a](#1a-the-thesis-more-games-watchable-within-two-hard-constraints) ranks third among costs and is worth paying only where the latency actually matters. **On the evidence so far that is the replay trigger and very little else.**

There is a fifth, smaller one. The known open risk in `AGENTS.md` — diagnostics painted onto the broadcast canvas, useful during setup and unacceptable once the source is live — is partly a problem of having nowhere else to put them. A box with its own second output has somewhere else.

---

## 3. Measured against a Director Mini, capability by capability

**A Director Mini is a switcher, an audio mixer, a battery and a touchscreen, with a browser source attached. This proposal replaces the browser source** — so the question is what happens to everything else it bundles. Written as a status rather than a list of losses, because most of it turned out to be recoverable and the totals are the useful part.

| capability | status here | where |
|---|---|---|
| **Multi-camera cutting**, PiP, transitions | **reachable, and deliberately declined** — the cost is not the switching, it is everything switching drags in | below, and [§3b](#3b-the-other-shape-the-box-out-of-the-video-path) |
| **Recording** as a hedge against the uplink | **recovered, and better** — the box's copy has the overlay burned in, so it is publishable as a VOD | [§7a](#7a-local-recording-which-is-nearly-free-and-worth-more-than-it-costs) |
| **Audio** — mic inputs, mixing, monitoring | **mostly recovered** — wireless mics into the camera arrive embedded, with no second clock to drift | [§3a](#3a-audio-and-why-it-is-smaller-than-it-looks) |
| **Replay** | **recovered off the box** at about €10 wherever a laptop is already in the rig; rich, data-driven replay is a real prospect; absent on a bare appliance | [§6b](#6b-replay-off-the-box-entirely--cheap-where-there-is-a-laptop-not-otherwise) · [§6c](#6c-replay-is-where-the-data-advantage-is-decisive) |
| **A battery** | **recovered for the low-power shape** — a full day from one bank; awkward on x86 | [§10b](#10b-running-off-a-battery) |
| **A confidence monitor** | **recovered where an HDMI output is spare**, as an ambient dashboard | [§10c](#10c-a-monitor-on-the-box-and-which-roles-should-actually-move-to-it) |

**The first row is the only one that does not come back, and it is worth being precise about why.** Not because it cannot be built — a second input and a software switch are unremarkable. Because of what comes with it:

- **A second capture path**, which doubles the most variable line in [§11](#11-the-money-stated-honestly) — €30–150 for another capture device, or €100–400 for another network encoder.
- **Two simultaneous decodes**, which forces x86 ([§5d](#5d-off-the-pi-entirely-x86-which-is-cheaper-than-it-sounds)) and rules out the cheap board.
- **A multiview** — and this is the real cost. You cannot cut what you cannot see. A switcher's core value is not the switching, it is showing every source at once so somebody can choose; building that means decoding and displaying all of them alongside the program, which is more compositing work than everything else in this document combined.
- **A dedicated operator**, watching that multiview continuously and able to do nothing else — against [`MATCHCONTROL.md`](MATCHCONTROL.md)'s finding that no crew size has anybody spare.

**So it fails on both of [§1a](#1a-the-thesis-more-games-watchable-within-two-hard-constraints)'s constraints at once, and at the end of it you have built a switcher — worse, and for more than a switcher costs.** That is why [§3b](#3b-the-other-shape-the-box-out-of-the-video-path) hands the job to one: not because the box is incapable, but because switchers are cheap, mature, and already good at exactly this.

**Which is the third time the same shape has appeared**, and it is [§1a](#1a-the-thesis-more-games-watchable-within-two-hard-constraints) doing its job: OBS is declined because it asks an operator to know things, not because it cannot do the work ([§7b](#7b-the-fork-5d-opens-obs-instead-of-a-pipeline)); on-box replay is declined for an operator and a control surface, not for silicon ([§6a](#6a-replay-on-the-box-later-and-why-not-now)); multi-camera is declined here for a multiview and a person. Nothing in this document is turned down for being technically out of reach.

So the honest framing is not *a cheaper Director Mini*. It is: **for a one-camera field this removes the need for one entirely, and for a multi-camera field it makes the switcher cheaper to live with.** What it does not do is pretend to be a switcher — and that is a choice about where the money and the people go, restated in [§1d](#1d-it-is-a-grid-not-a-ladder--and-that-is-the-strongest-argument-here) as a dial a tournament turns rather than a wall it hits.

**Audio is the one worth reading further on**, because it looks like the biggest gap and is not. [`COMMENTATOR.md`](COMMENTATOR.md) is an entire document about the people talking over this footage, so a box that composites beautifully and drops the commentary mics has replaced nothing — but §3a gets there for about €70 and a purchase rather than a pipeline.

### 3a. Audio, and why it is smaller than it looks

The instinct is that adding commentary means building a mixer, with level controls in the Studio. **Both halves of that are wrong**, and seeing why removes most of the work.

**The box already has to emit audio.** RTMP and SRT to any platform want an audio track, and a stream with none misbehaves in platform-specific ways. So the floor is not *no audio* — it is passing through whatever audio arrives embedded in the video source, which costs one element in the pipeline. That is the fallback, and it is also what a camera-mounted mic already gives you. Commentary is an upgrade from *one source* to *two mixed*, not a new subsystem from zero.

**Gain belongs to hardware, not to the Studio.** A web UI cannot ride a fader: show state polls at about a second, and a control with a second of latency is unusable for the one thing live gain is for. A two-input USB interface with physical gain knobs and direct monitoring — the €60–90 class — hands each commentator control of their own level, which is how every broadcast has ever done it, and gives them zero-latency foldback in their headphones for free. That is a knob replacing a feature, and it is the whole reason this section is short.

What software should own is the part hardware cannot:

- **A limiter for the live path.** Clipping cannot be repaired in a stream that has already gone out. Note that a kit like the AM18 already covers the other half: its transmitters record 8GB of backup audio onboard and offer a safety track at reduced gain, so a clipped *archive* is recoverable even when the broadcast was not. Given the scope note weights stream and archive equally, that is half this problem solved in hardware — and it also means an RF dropout costs the recording nothing.
- **Level meters surfaced in the Studio**, which are telemetry rather than control — is a mic dead, is one commentator inaudible, is the source clipping. This is exactly the class of thing [`SETUP.md`](SETUP.md) argues for: a checklist item the software can *verify*, and one nobody can check today.
- **Fixed relative levels in `conf/`**, set once per rig, applied at start. Set-and-forget is a configuration value, not a mixing desk.

**Two things that will actually bite.** First, **clock drift**: a USB audio interface and a USB capture device have independent clocks, and over a ninety-minute game they diverge. GStreamer can slave one to the other, but this is the failure that appears forty minutes in rather than at setup, so it needs a long soak test rather than a smoke test. It is also avoidable entirely — see the wireless-mic route below, where the audio never becomes a second clock. Second, and worth stating as a decision rather than a detail: mixing audio on the box kills §7's hardware-plane variant outright. If the video never enters a software pipeline there is nothing for the audio to be timestamped against. Planes remain viable only for the venue-screen case where the box is not producing a stream at all.

**Where the commentators physically sit decides the difficulty, and the answer is cheap.** §1b's rig B uses a Ulanzi AM18 wireless lavalier kit — two clip-on transmitters, one receiver, around €90, with roughly 100m of usable range. Beside the box it is a cable; across the field it is **not** audio over the network and not a different project — it is RF, for roughly what the USB interface would have cost.

**And feeding those mics into the camera rather than the box removes the hardest problem in this section.** If the receiver goes into the camcorder, the commentary arrives *embedded in the video signal* — which is §3a's floor, already the plan. There is then no second clock, and therefore no drift: the failure above that appears forty minutes into a game simply cannot happen, because there is only one audio path and it is timestamped with the picture.

That is the cheapest correct answer in the whole section, and it costs a purchase rather than a pipeline. Its limits are real but narrow: roughly 100m of range, so the commentators must be near the camera; the transmitters are summed rather than mixed; and per-mic level is set on the transmitters — hardware gain again, per §1c. A booth far from the camera, or a crew wanting a genuine mix, still needs the interface.

### 3b. The other shape: the box out of the video path

§3 concedes replay, multi-camera cutting and audio to the switcher. There is a version of this idea that stops competing with the switcher and feeds it instead, and it is worth stating as a peer of the all-in-one rather than as a footnote — because it is cheaper, far more reliable, and shares nearly all of the same software. It is the **middle rung of §1a's ladder**: for a crew that has a switcher and somebody who can run it.

**And it is not speculative. §1b's rig A already runs this topology** — a laptop's HDMI output feeding an ATEM as a keyed graphics layer, with the switcher cutting two cameras and streaming. What follows is therefore a cost reduction of something proven, not a design being proposed for the first time, which is the best position any section of this document is in.

**The Pi renders the overlay and outputs it over HDMI. A switcher does the cutting, the replay, the audio and the streaming.** The box is a *hardware browser source* and nothing else.

**Every hard constraint in this document comes from the box being in the video path.** §4's capture device, §5's missing encoder, §5a's thermals and 80% CPU, §6's frame rate, §7a's disk backpressure — all of it. Take the box out of the video path and they do not get easier, they cease to exist. What is left is a €60 computer rendering a web page to an HDMI port at 1080p60, which is a thing a Raspberry Pi is extremely good at and has been for a decade.

**Two ways to give the switcher a key, and this project already ships the second.** Fill and key on the Pi 4's two HDMI outputs, for switchers that accept a separate key input — cleaner, because alpha is exact. Or chroma, for switchers that do not: `/s/702/green`, `/s/702/overlay/green`, or any six-digit hex, are already in the URL table because a switcher that cannot key alpha was always an anticipated case. Chroma fringes on thin white text against a busy background, so fill-and-key is worth having where the hardware allows it, but the fallback needs no new code at all.

**This is where §7's hardware planes finally earn their keep.** The argument against them was that an encoder needs one buffer in memory and a scanout plane never produces one. With no encoder anywhere in the path that objection evaporates: the HVS blends video-free graphics at scanout, output latency is a frame, and the frame rate is the display's rather than an encoder's. The plane approach was the wrong answer for §7 and is the right answer here.

**What it costs is honesty about the comparison.** This does not replace a Director Mini — it needs one, or an ATEM, or whatever the tournament already owns. It is an accessory. What it buys for roughly €60 is §2's first reason: the browser stops being unknown, which is the single thing [`../tests/selftest.php`](../tests/selftest.php) exists to worry about, on the device that has always been the least inspectable part of the rig. Measured as value per euro that is probably the best idea in this document.

And the two shapes are not a fork. Same WPE rendering, same overlay URLs, same `conf/`, same central provisioning and field-following from §8b. They differ only in what sits downstream of the browser, which means **building this one first de-risks the other rather than competing with it** — see §12.

### 3c. The frozen scoreboard over a replay

A hazard that looks like a §3b drawback, is not one, and is worth separating carefully because the real version is worse and applies everywhere.

**§3b does not hand the switcher a combined view.** Fill-and-key, and the `/green` chroma form, exist precisely so the switcher receives the graphics as a *separate layer* and composites them itself. The problem arises one step later: if the switcher's replay buffer taps the program feed, it records the graphics burned in — and a replay of a goal then carries the post-goal score over footage of the play that produced it.

**A delayed source produces the mirror image of this** — the graphic arriving *before* the picture rather than after it, which [§4d](#4d-automated-cameras-and-what-an-rtmp-only-source-demands) covers. Both are the same fault: the overlay and the video disagreeing about what time it is.

**That is not merely ugly, it is this project's named failure mode.** `AGENTS.md`: the characteristic bug here is not a crash but *a graphic quietly asserting something untrue*, which looks completely normal in a screenshot. A scoreboard reading 8–6 over the point that made it 8–6 is exactly that, on the most-watched thirty seconds of the broadcast.

**And it is not a §3b problem. It is inherent to burned-in graphics**, which is what this whole project produces. §7a's recording is a tee off the encoder and carries the overlay too; §6a's on-box replay would hit the same wall. §3b only surfaces it earlier, because rung 2 is where replays actually happen.

#### Three answers, cheapest first

- **Put the overlay on the switcher's downstream keyer and tap the replay buffer upstream of it.** A DSK sits after the program bus for exactly this reason: the buffer records clean, and graphics are added last on the way out. This is a switcher configuration question, not a code question, and on hardware that offers a DSK or a clean-feed output it costs nothing. Where the switcher only records program — as cheaper ones do — it is unavailable, which is worth checking before buying one.
- **A replay state that hides the score.** A REPLAY bug and no scoreboard asserts nothing false, which is this project's standing preference: refuse to claim rather than claim wrongly. One toggle, and at rungs 2 and 3 there is an operator to press it.
- **Rewind the scoreboard, using the renderer that already exists.** `?at=<seconds>&goals=<n>&phase=…` draws a deterministic single frame for [`POSTPRODUCTION.md`](POSTPRODUCTION.md), by handing the existing `render()` a truncated payload — *"one renderer, never two"*. Told the game-time of the moment being replayed, the overlay can show the score **as it was**, which is what a broadcast actually does. The feature is built; what is missing is only the signal telling the box a replay is running and from when.

**That third one is the interesting one**, because it costs almost nothing and nobody would build it from scratch for this. It is also an argument for rung 3: OBS knows when its replay buffer is playing, and obs-websocket can say so.

---

## 4. Video in: yes, you always need a capture device

**Stated plainly, because it is the single most load-bearing fact in this document and it is easy to assume otherwise: no board here has an HDMI input.** The HDMI ports on a Raspberry Pi, on a Radxa X4 and on an N100 mini PC are **outputs only**, and this is not a driver limitation that can be worked around. HDMI source and sink are different roles in hardware: receiving requires a chip that decodes TMDS and handles EDID and HDCP, and general-purpose computers do not have one. A machine with four HDMI ports still cannot accept a camera.

**So a capture device is mandatory for the all-in-one shape — it is not optional and it is not cheap.** At €30–150 it is the most variable line in §11's budget and, per §10, the component most likely to misbehave at a field.

**Except in §3b, where the question does not arise at all.** There the Pi's HDMI is an *output* going to the switcher, and the switcher owns the inputs — which is one more reason that rung is the easy one, and worth being clear about before conflating the two shapes. What that rung buys instead is the keying question in §3c.

### 4a. What the options actually are

| route | works on | cost | notes |
|---|---|---|---|
| **USB 3 capture** (MS2130 class) | both | ~€30 | 1080p60 uncompressed. The sweet spot |
| **USB 2 capture** (MS2109 class) | both | ~€15 | 1080p30 MJPEG only — compressed then re-encoded, and flaky. Avoid |
| **Prosumer USB** (Cam Link, UltraStudio) | both | €120–150 | What you buy when reliability matters more than €100 |
| **CSI bridge** (TC358743) | **Pi only** | ~€30 | Lower latency, no USB stack. Fiddly: EDID and timings must be set by hand |
| **PCIe card** (DeckLink) | x86 with a slot | €150+ | The most reliable capture there is, and the one argument for a board like the ZimaBoard 2 that §5d otherwise dismisses |
| **Network in** (NDI/SRT/RTMP) | both | €0 *or* €100–400 | No capture hardware — but see below |

**Network input is not actually free unless the camera speaks it.** §4b calls this the easier half, and it is, but if the camera only has HDMI then something upstream must encode — and an HDMI-to-SRT or NDI encoder box costs €100–400. That does not remove the capture hardware, it moves it outside the case and adds a hop. It is genuinely free only where the camera does NDI or SRT natively, which the better ones increasingly do. §4c argues it is worth paying for anyway, on cabling and placement grounds that have nothing to do with the software.

**Three things to check on whatever is bought**, each of which has cost somebody a broadcast:

- **The pixel format it actually emits.** Run `v4l2-ctl --list-formats-ext` before believing a listing. §7 notes that vc4's planes may not accept packed YUYV, which is what most dongles produce, and a device that only offers MJPEG at the frame rate you want adds a decode step and a generation of loss.
- **Latency.** USB capture adds 50–100 ms; a CSI bridge is closer to 20–30 ms. Irrelevant for a stream that is seconds behind anyway, noticeable but tolerable on a venue screen.
- **HDCP.** A camera output is unprotected and fine. Anything consumer plugged in later will not capture at all, and the failure looks like a black frame rather than an error.

### 4b. The two inputs are two different projects

**Network in — NDI, RTMP or SRT — is much the easier half.** No capture hardware, no HDMI problem, and the ethernet cable is already in the box. It should be built first, because it is the same pipeline as the HDMI version minus its riskiest component.

Two caveats. Something upstream is already encoding that stream, so the box decodes and re-encodes it: a generation of quality and a second or so of latency, for graphics. And the NDI SDK's redistribution terms need reading before it goes into an image that gets handed to tournaments.

**HDMI in is where the difficulty actually lives**, and §4a lists the hardware. The bandwidth arithmetic is what decides which of those options survives on a Pi: the Pi 4's CSI is two lanes, putting 1080p50 right at the limit, and its USB 3 ports run through a VL805 on a single PCIe 2.0 lane — about 4 Gbps for everything — while uncompressed 1080p50 YUY2 is roughly 1.7 Gbps of it. Feasible; not comfortable. At the 1080p30 §6 settles on it is comfortable, and on x86 (§5d) the question does not arise: USB 3.2 at 10 Gbps has room to spare.

The operational point matters more than the bandwidth arithmetic: **a €30 dongle that drops frames on a hot afternoon is worse than the box it replaced**, because the box it replaced fails in ways its manufacturer has already found.

### 4c. Why network input wins on the field, not just in the code

§4b calls network input the easier half to *build*. That undersells it: **it is also the better thing to deploy**, and the reason has nothing to do with software.

**HDMI does not travel far on passive cable** — about 5m — but optical HDMI does, and §1b's rig A uses exactly that from camera monitors back to base. So this is a real choice rather than a dead end, and the trade is worth stating properly: optical HDMI keeps the picture uncompressed with no encode-decode hop and no generation loss, which is the one thing network transport cannot offer. It costs more per run, the cable is directional and fragile, and it carries no power.

Ethernet goes 100m on €15 of Cat6 that venues already run and people already expect on the ground, and a 20m HDMI run of any kind is heavier and more of a trip hazard across the route everybody walks.

**And PoE is the detail that decides it.** One Cat6 run carries the video *and* the power to the camera position. No mains at the tripod, no battery to watch, no second cable — which is §10b's problem solved for half the rig by choosing a transport.

**The real argument underneath is placement.** The box wants mains or a battery, a network, shade, and to be near whoever is operating it. The camera wants a tripod on a sideline. Those are different places, and HDMI forces them to be the same place. Network transport decouples them, and that is worth more at a tournament than any of the bandwidth arithmetic in §4b.

#### Two corrections worth making

**RTMP is not limited to 30 fps.** Nothing in the protocol caps frame rate — platforms ingest 1080p60 over RTMP routinely, and encoder hardware advertises 60 fps RTMP output. Where the belief comes from is individual devices that cap it, plus RTMP's general air of being legacy.

**But RTMP is still the wrong protocol for this hop.** It has no error correction and more latency than the alternatives, and it is designed for *egress*. The clean split: NDI|HX or SRT into the box, RTMP out of it. SRT in particular retransmits, which is what an imperfect field link needs.

**One Pi-specific note.** Full NDI is SpeedHQ at roughly 130 Mbps, and decoding it is CPU work — too much to ask of a Pi 4 that is also running a browser. NDI|HX2/HX3 is H.264 or H.265 underneath, so the Pi 4's hardware decoder handles it. On x86 (§5d) either is fine. So on a Pi, choose HX or SRT, not full NDI.

#### What owning a ZowieBox means

It is an HDMI-to-network encoder that outputs **NDI, NDI|HX2/HX3, SRT, RTMP(S) and RTSP** up to 50 Mbps, takes **PoE over 100m** or USB-C power including a power bank, carries a tally light and a tripod mount, and records to its own storage while streaming. It also presents as a UVC device over USB.

Three consequences:

- **Phase 3 costs nothing to start.** The ingress hardware is already owned, in either mode — over the network, or as a plain USB capture device. §12 can be run without buying anything.
- **Its recording is a clean master, and that is architecturally useful.** §7a's recording is the *program*, with the overlay burned in and therefore unusable for §3c's replay problem or for re-cutting later. A device that independently records the clean camera feed gives the second half of that pair for free — which is exactly what [`POSTPRODUCTION.md`](POSTPRODUCTION.md) wants and what §3c's frozen scoreboard needs.
- **It clarifies what the appliance is actually for.** A ZowieBox alone already streams to YouTube and records. What it cannot do is put a scoreboard on the picture. The overlay is the entire value-add, which is a clean product boundary and worth keeping in view.

**What it is not is the scaling answer.** At around $300–400 it costs more than the box it feeds, so one per camera position inverts §11's budget. For scaling, the cheaper routes are a camera with native NDI|HX or SRT output — increasingly common — or, where the box can sit within a few metres of the tripod, a €30 USB capture device and no transport problem at all. The cabling argument and the budget argument pull in opposite directions, and which wins is a per-field question rather than a design decision.

### 4d. Automated cameras, and what an RTMP-only source demands

The cameras available change the design more than the board does, and two facts about the ones already owned matter.

**Automated camera operation is a solved commodity, not something to build.** XbotGo-class devices do AI framing cheaply; a Pix4Team-class robot physically pans a real camcorder and follows the action. This retires §5e's auto-framing idea completely — the one AI use case in this document that looked genuinely transformative turns out to be purchasable for less than the accelerator that would have been needed to attempt it, and better.

**More importantly, these devices serve §1a's thesis directly rather than merely being convenient.** [`MATCHCONTROL.md`](MATCHCONTROL.md)'s finding is that no crew size has anybody spare; an automated camera removes the camera operator from the crew. That is the same win the appliance is chasing, bought rather than built. A softer picture that frames itself beats a sharp static wide shot nobody is watching, and that trade should be made deliberately rather than apologised for.

#### Three tiers of automated camera

| tier | source | transport | quality |
|---|---|---|---|
| **cheap, automated** | XbotGo-class AI camera | **RTMP only**, over wifi | two lossy generations — see below |
| **good, automated** | Pix4Team-class robot + camcorder | HDMI → ZowieBox → NDI\|HX or SRT (§4c) | the best path in this document |

| **fixed, hindsight-framed** | two GoPros stitched, or a Reolink Duo | RTSP into Autocam-class software, then RTMP out | **delayed** — see below |

The first two are owned already. The third and the cheap one each have consequences.

#### The fixed-camera tier trades latency for never missing the play

Once.sport's Autocam is the example: **fixed cameras, no moving parts, and the framing done by cropping a virtual window out of a stitched wide shot.** Because it crops after the fact rather than predicting, it can look backwards — which is why it is **rarely wrong and always late**, the exact inverse of a robot that must guess where the play is going and sometimes guesses badly.

**Its operational advantages are real and undersold.** Nothing to level, nothing to pan, nothing mechanical to fail, and a pair of GoPros or a Reolink is far more weatherproof than a robot on a tripod. That is [§1a](#1a-the-thesis-more-games-watchable-within-two-hard-constraints)'s people constraint answered by having nothing to operate — and it also **confirms [§5e](#5e-would-a-pi-5-ai-hat-help)'s conclusion from the other side**: cropping a high-resolution wide shot is exactly the auto-framing this document declined to build, sold as a product.

**It has a real-time mode, which is what makes it relevant at all.** The software takes RTSP from the cameras and can stream the result live; there is also an offline mode and a cloud mode with a turnaround measured in hours, and **neither of those is a broadcast**. Only the real-time path belongs in this document.

**But the delay imposes a requirement nothing else here does: the overlay has to be delayed to match.** A goal reaches the scoreboard from the API the moment it is entered, while the picture is still seconds behind — so the graphic announces a score the viewer has not seen yet. **That is not a cosmetic mismatch, it is a spoiler**, and it is [§3c](#3c-the-frozen-scoreboard-over-a-replay)'s failure family arriving from the opposite direction: there the graphic was stale, here it is early. **The delay is almost certainly not constant**, which is what makes the obvious fix — subtract a fixed offset — the wrong one to reach for. Hindsight framing needs a deliberate lookahead buffer, and that part *is* fixed by design; but AI inference on a general-purpose machine adds latency that moves with load, scene complexity and thermal state. A well-built pipeline absorbs that variance into its buffer and emits at a constant cadence. Whether this one does is not something to assume.

**It does not need to be constant, because the error is asymmetric.** A scoreboard that ticks up a second *after* the goal appears looks entirely normal — that is how broadcasts have always looked. One that ticks up *before* is a spoiler. **So the rule is: err late, never early.** Set the hold to comfortably more than the worst delay rather than trying to match it exactly, and a delay that wanders between two and five seconds is handled by holding six. Precision is not required; a guaranteed lower bound is.

**And the scope is narrower than "delay the overlay".** Nothing on a scoreboard is timing-sensitive except the moment a number changes. Team names, logos and lower thirds do not care, and a static scoreboard that is three seconds stale is invisible. **Hold score-change events, not the graphics layer** — which is less work and less to go wrong.

**This belongs to the box and nowhere else.** Only it sees the video and the data feed, and a hardware switcher cannot hold back a score change at all. One more entry in this section's argument for the appliance.

**And it makes [§10c](#10c-a-monitor-on-the-box-and-which-roles-should-actually-move-to-it)'s monitor mandatory rather than merely useful** — for two reasons, of which the second is the sharp one.

Commentators watching the field would describe play several seconds before viewers see it, so they have to watch the delayed feed. **But replay breaks outright without it.** An operator watching the *field* sees a goal and presses; at that instant the buffer does not contain the goal yet, only the build-up, so a jump-back shows the wrong thing entirely. An operator watching the *delayed feed* presses in the same timeframe the buffer is in, and the depth buttons in [`REPLAY.md`](REPLAY.md) behave exactly as designed. **Compensating in software is not a way out**, because the delay is variable and here the asymmetry runs the other way — being late on a replay in-point means missing the play, so the "err late" rule that saves the scoreboard does not apply.

**Two smaller consequences.** Catching up costs more: a replay already starts five seconds behind, so the live action missed while it plays is that much greater, and [`REPLAY.md`](REPLAY.md)'s short depths matter more rather than less. And **the case for instant air weakens on this tier specifically** — its premise is that live sport leaves no time to deliberate, which is less true when the viewer is seconds behind already. Mark-then-decide is defensible here in a way it is not elsewhere.

**Automatic replay is the part that gets easier.** [§6c](#6c-replay-is-where-the-data-advantage-is-decisive) locates a goal by timestamp rather than by reflex, and a delay is just an offset the box can subtract — provided footage is stamped with event time rather than arrival time, which is a thing to get right once. **On a delayed source the machine is better placed than the operator**, which is the reverse of everywhere else in this document.

**Two costs to weigh against never missing the action.** There is no zoom, so the crop is only as good as the sensor behind it — a stitched pair leaves room, a cheap single camera less. And **20 fps on the budget Reolink option fits neither regional family** in [§6e](#6e-it-has-to-work-in-ntsc-regions-too): converting it to 25 or 30 judders, and [§6d](#6d-replay-needs-the-frame-rate-at-capture-not-on-the-wire)'s slow motion is not worth having from a 20 fps source. **The GoPro pair is the version of this tier that survives contact with the rest of the document.**

**And the processing is local, which changes what this tier costs.** Published requirements: **Windows, macOS or Linux, with an NVIDIA RTX-series GPU recommended** — or **Apple Silicon, where the Neural Engine is claimed to be up to ten times faster** than what it replaced. Source resolution guidance is 2K minimum, 4K for performance, 8K for quality, which follows from cropping a wide shot: **the crop is only ever as good as the sensor behind it.** Minimum CPU and RAM are not published.

So this needs its own machine, and an N100 will not be doing it alongside compositing and encoding. **The convergence that first suggests itself — one box for both — is not available**, though Linux support means colocation is at least conceivable on a desktop with a discrete GPU rather than on anything mini-PC-shaped.

#### What it actually costs, from the published brochure

| | |
|---|---|
| **software** | **€19/month or €171/year** (20 hours' footage a month), **€38/month or €342/year** unlimited — both include livestreaming. A €5 per-match cloud tier exists but returns the game in 24 hours, so it is not a broadcast |
| **fixed camera kit** | **from about €300** DIY — Reolink camera, **PoE adapter**, 128 GB v30 card |
| **portable kit** | two GoPro Hero 11-or-newer (or DJI Osmo Action 4), two 128 GB cards, a 20,000 mAh power bank feeding both, cables, mount, 3D-printed case |
| **tripod** | **€350** from them, or sourced yourself — and note the spec: **7.3m extended, 5.5kg, wind kit not included** |
| **hardware ownership** | bought outright, "yours for life"; the software is the subscription |

**Two things in that are worth pulling out.** The fixed installation is **PoE-powered over a single Ethernet run**, which is exactly the arrangement [§4c](#4c-why-network-input-wins-on-the-field-not-just-in-the-code) argues for on cabling and placement grounds — arrived at independently. And the portable rig runs its cameras **off a power bank**, so at the camera end this tier is battery-friendly in a way the processing end is not.

**One caveat that may decide the tier.** The brochure describes the live path specifically as *"wide-angle video from the Reolink camera... processed in real time on a local device"*, while the GoPro pair is presented as record-then-stitch-then-process. **If real-time streaming is Reolink-only, then the live tier is locked to the lower-resolution 20 fps camera** and the higher-quality stitched option is a post-production workflow — which would remove most of what made this tier attractive for broadcast. It is the first thing to confirm.

**And note the tripod is 7.3m of mast.** That is a real object to transport, erect and guy in wind, and the wind kit is extra — another reason this tier suits a permanent mount rather than a travelling rig.

**Which inverts where this tier sits.** The cameras are the cheap part; the computer is not. Against a €110–140 N100 the processing machine is a different order of expense, so **this is the most expensive tier here, not the middle one** — and per [§1a](#1a-the-thesis-more-games-watchable-within-two-hard-constraints) that cost lands per field, where it hurts most. Power depends on which route: **an RTX desktop ends [§10b](#10b-running-off-a-battery)'s battery story outright**, while an Apple Silicon Mac mini is a different proposition — a few hundred euro, low draw, silent, and not obviously off a large power station. **That is a materially better option than the GPU tower this section first assumed**, and [§1b](#1b-two-working-rigs-for-reference)'s rig A already has a Mac in it, so it is not alien to this world.

**But that points at the deployment it actually suits, which is not a travelling rig at all.** Fixed cameras, a machine that stays put, mains power, nothing to pack or level or aim — **this is a club's permanent installation at its own ground**, streaming every home game, rather than a rig that visits tournaments. Read that way its costs stop being objections: a permanent site has mains, usually has wired internet, and has no packing list, which removes most of [§10](#10-where-the-director-mini-is-genuinely-ahead)'s field problems at a stroke, and the hardware is bought once rather than per event.

#### On this tier the overlay problem is already solved, and so is the audio

**Once's own answer to graphics is OBS.** Their documentation points users at it for scoreboards and sponsor banners, compatible with both the camera and Autocam. So there is no competing product here and no overlap to worry about — **OBS is already in the chain**, and these overlays go into it as a browser source. That is [§1a](#1a-the-thesis-more-games-watchable-within-two-hard-constraints)'s top rung, reached with nothing built.

**Audio is the part this tier does not solve, and the tempting answer does not work.** It would be convenient if commentators simply watched the delayed feed, since then their speech would be aligned with the picture by construction. **On site that is not viable.** You cannot un-see the game happening in front of you: the crowd reacts, you hear it, and the monitor has not got there yet. Several seconds of that is disorienting enough to degrade the commentary, which is the opposite of the point. Booth commentators work off monitors because they are isolated from the live action and the delay is a frame or two, not five seconds.

**Three configurations actually work, and one of them is the tier's natural fit:**

- **No commentary at all** — a club's automated home-game stream, which is what this deployment is for.
- **Remote commentators**, watching the stream with no live reference. Perfectly workable, and — as [§1a](#1a-the-thesis-more-games-watchable-within-two-hard-constraints)'s audiences do not yet cover — **an entirely different workflow**, with its own return-feed, latency and coordination problems that this document does not address.
- **On-site commentary with the audio delayed to match.** The commentator watches the *field* and speaks naturally; the pipeline holds their audio back by the same amount as the video. This needs no mixer at the camera, only a delay line downstream, and the [`§4d`](#4d-automated-cameras-and-what-an-rtmp-only-source-demands) asymmetry applies again — **commentary landing slightly late reads as an unhurried commentator, landing early is a spoiler.** Its accuracy is bounded by how well the delay is known, which is the same open question as the scoreboard hold.

**That last one is what routing audio into the camera would achieve**, and it achieves it without a mixer, a cable run to an awkwardly placed camera, or a line-in the cheap Reolink may not have. The camera route is worth it only if the delay cannot be measured well enough to set a delay line — which is worth knowing before choosing this tier for an event with commentary at all.

**Commentators need a monitor too, and the earlier distinction was too clean.** They cannot narrate live play off a delayed screen — but **they must see a replay to talk over it**, and commentary over a replay is half of what makes one worth airing. That is ordinary practice and not the disorienting thing described above: watch the field, look at the monitor when a replay rolls.

**Which breaks the naive delay line, and the fix is one specific detail.** If commentary is held back by the delay and the monitor shows what is going to air, the commentator sees a replay, speaks, and their words land seconds after the replay has ended. **The monitor has to show the programme *before* the audio delay is applied** — so they are watching what will air at the moment their delayed voice arrives. Then both cases align without a special case: during live play they speak to the field, during a replay they speak to the monitor, and the same fixed delay carries either onto the right pictures.

**Get that backwards and replay commentary is unusable**, which is worth knowing before wiring a monitor to the convenient output rather than the correct one.

**None of this arises on the other tiers.** With an undelayed source the commentator watches the field, glances at a monitor for replays, and their audio is live — everything aligns because nothing was shifted.

#### The version that would fix all of it, and why it is not ours to build

Everything above works around the delay. **A tighter integration would remove it**, and it is worth recording because it is the right design rather than a workaround.

**Separate the two delays.** Stitching a panorama is cheap; deciding where to crop is what costs seconds. So expose the **stitched wide feed live**, at whatever the stitch alone costs — plausibly one to three seconds — and treat the crop as an *instruction* applied to it rather than as the only output. Then:

- **Commentators watch the wide feed.** One to three seconds is a different proposition from five or more, and it is the arrangement broadcast talent already works in.
- **Replay becomes a crop instruction against a buffer**, not a second video path — *show this region, from this timestamp*. And because the buffer is the **whole field**, any replay can be framed correctly after the fact, **including plays the automatic crop framed badly at the time.** That is strictly better than a switcher's replay, which only ever has the shot that was taken.

**One failure mode it introduces, and there is no cheap fix.** A commentator watching the wide shot sees more than the viewer does, and will eventually describe something outside the crop. Drawing the crop rectangle on their monitor sounds free — the software renders exactly that in its own interface — **but the rectangle *is* the AI's output, so at one to three seconds it has not been computed yet.** A rectangle from the delayed decision would show where the crop *was*, which is worse than none. The honest position is that this configuration asks the commentator to describe conservatively and accept the occasional miss, or asks for a cheap low-latency predicted crop refined later — which is prediction, and being wrong by predicting is the failure this whole tier exists to avoid.

**And it is gated on something before it is gated on Once.** [§6d](#6d-replay-needs-the-frame-rate-at-capture-not-on-the-wire) established that slow motion needs source frames rather than output frames — so a 20 fps source gives ten unique frames a second at half speed, which is a slideshow rather than a replay. **If the real-time path really is Reolink-only at 20 fps, then replay on this tier is not merely awkward, it is not worth having** — and the crop-instruction architecture inherits the same limit, because it is cropping the same frames.

**That orders the phase-0 questions.** *Does real-time work with the stitched GoPro pair, and at what frame rate?* comes first, because a no makes everything else moot: no good replay on this tier ever, and no point discussing an integration to deliver one. A yes at 50 or 60 makes the rest worth asking.

**It also tidily confirms the verdict above rather than complicating it.** This is a tier for streaming without replay, and 20 fps is one more reason why — alongside its being outside both regional families in [§6e](#6e-it-has-to-work-in-ntsc-regions-too).

**And the live GoPro path may not exist to build on.** The brochure's stitched pair is a record-then-import workflow off SD cards; feeding it live means **two capture inputs and real-time stitching on top of the detection** — a different product from what ships, not a setting. So the pleasant reading of the question above, *yes with GoPros at 60 fps*, is also the one requiring the most work from someone else.

**This is a joint product, not an integration**, and the work is mostly on the other side: a low-latency wide output, live stitching for the higher-frame-rate cameras, and timestamped crop instructions against a retained buffer. **It only happens if Once wants replay in their own product** — which is not far-fetched, since soccer clubs want replays as much as anyone, and it would be their feature rather than a favour to us.

It fits [§1e](#1e-other-sports-and-where-the-boundary-actually-falls)'s conclusion exactly — partner on the sport-agnostic half while the overlays stay Ultimate's — but **it is speculative rather than promising, and it is recorded here as the right shape rather than as an option.** Worth one question in the phase-0 conversation; not worth planning around.

#### The same cameras are close to ideal for post-production

The record-to-SD GoPro workflow is poor for live precisely because it is not live — which makes it a good fit for [`POSTPRODUCTION.md`](POSTPRODUCTION.md), the document about adding overlays to footage somebody recorded without a switcher.

**Every constraint the live tier fights disappears.** No processing delay to compensate, no live encoding budget, no frame rate forced by a real-time path — record at 60 and slow motion works. It is the **highest-quality** option the brochure offers, and the processing happens afterwards on whatever machine is available, at whatever speed it manages.

**And at the field it is the cheapest setup in this entire document.** Two cameras, a power bank, a tripod. **No box, no network, no mains, no uplink and no operator** — which also means none of [§10a](#10a-the-network-at-a-field)'s recurring bandwidth cost, since nothing is transmitted while the game is on. It is the extreme form of that section's point that live is an assumption rather than a requirement: **nothing is live and everything is covered.**

**It also makes [`POSTPRODUCTION.md`](POSTPRODUCTION.md)'s central problem tractable.** That document needs anchors tying a video's timeline to the game's, and calls getting them wrong the most expensive teardown mistake because it is unrecoverable once everyone has left. Here the cameras stamp their own recordings with wall-clock time and the API knows when goals were entered, **so alignment is a subtraction rather than a hunt** — the same trick [§7a](#7a-local-recording-which-is-nearly-free-and-worth-more-than-it-costs) uses, without needing the box present.

**With one failure to guard, and it is cheap.** A camera whose clock is wrong breaks the alignment silently and completely. **Film something showing the correct time at the start** — a phone screen will do — and one anchor calibrates the offset for the whole game, which is far less than the per-goal anchoring that document otherwise contemplates.

The renderer for this already exists: the scoreboard's `?at=<seconds>&goals=<n>&phase=…` draws a deterministic single frame, which is exactly what compositing an overlay onto recorded footage needs.

**And this is the answer for [§1a](#1a-the-thesis-more-games-watchable-within-two-hard-constraints)'s fourth audience.** Somebody unaffiliated, with no budget, no crew and nothing they want to operate, gets a produced game with a correct scoreboard for two cameras and a tripod — **no box, no subscription to a streaming platform's schedule, and nothing to go wrong while the game is on.** It asks only that they film a clock at the start and process afterwards.

#### The verdict: viable without replay, awkward with it

Listed together the delay looks like a pile of objections. Sorted by cause it is not, because **almost all of them are replay problems**:

| complication | without replay |
|---|---|
| scoreboard arriving before the picture | **remains** — solved by holding score changes, erring late |
| commentary aligned to delayed video | **remains** — solved by a delay line on the commentary path |
| monitor must tap before the audio delay | **gone** — the monitor existed for replays |
| operator must watch the delayed feed | **gone** |
| footage stamped with event time | **gone** — it was for locating goals automatically |

**So the tier is viable without replay**, on two adjustments that are both bounded rather than precise: hold score changes behind the worst-case delay, and delay the commentary path to match. Neither needs the delay measured exactly, only bounded.

**With replay it is awkward** — not impossible, but every remaining subtlety is one that fails silently and only during the thirty seconds a replay is on air.

**Which costs less than it sounds, because the tier's audience is the one least likely to want replay anyway.** A club's permanent installation streaming home games is a deployment with one operator or none, and [§6a](#6a-replay-on-the-box-later-and-why-not-now) already found that no crew size has anybody spare for it. **The tier and replay are effectively an either/or, and for the deployment this tier is actually for, that is not a loss.**

It also explains why this tier needs so little from the appliance: strip out replay and what remains is a camera, OBS and an overlay URL — [§1a](#1a-the-thesis-more-games-watchable-within-two-hard-constraints)'s top rung again.

*(Nothing published says Autocam handles commentary audio itself, so it is OBS's job either way.)*

**Most of what this tier turns on is unpublished and answerable by asking**, which makes it a [§12](#12-what-to-do-first) phase-0 item alongside the federation conversation — cheap, and it decides whether the tier is usable at all:

- **Does the real-time path work with the stitched GoPro pair, or only with the Reolink?** The brochure describes live streaming in terms of the Reolink alone, and the answer decides whether the live tier is stuck at 20 fps.
- **What is the real-time delay, and how much does it vary?** Everything above depends on this, and on whether it can be read out rather than guessed.
- **Does audio pass through the processing**, and does stitching preserve it from a nominated camera?
- **Is output footage stamped with event time or arrival time?** This decides whether automatic replay can locate a goal (§6c).
- **What does the real-time mode require in hardware for a given camera and frame rate?** RTX or Apple Silicon is published; minimum CPU and RAM are not, and the tier's dominant cost turns on the answer.



**So it is a fourth deployment rather than a third camera option** — worth keeping in view because a club ground is a real audience, and worth keeping out of the travelling-rig arithmetic where it does not belong.

#### What RTMP-only actually demands

**RTMP is push-based, so the box has to listen.** This is a concrete architectural requirement rather than a configuration detail: there is no "fetch the RTMP stream" — the camera pushes, and something must accept it. MediaMTX is the off-the-shelf answer — a single Go binary, packaged, that ingests RTMP, SRT, RTSP and WebRTC and republishes them internally.

And it earns its place beyond this one case: it makes every source look the same to the pipeline. **RTMP in from an XbotGo, SRT in from a ZowieBox, one uniform internal feed** — which is exactly the shape §8b wants, where a per-field configuration names a source and nothing downstream has to care what kind it is.

**The honest costs**, because this is the worst-quality path here and should not be sold as anything else:

- **Two lossy generations.** The camera encodes, the box decodes, composites and re-encodes. At the modest bitrate such a device emits, the second pass is visible — and §5g's warning applies with force, since high-motion wide shots are exactly what suffers and exactly what this sport is.
- **RTMP over wifi at a venue is the weakest link in the whole chain.** No error correction, TCP-based, so congestion produces a stall and then a jump rather than graceful degradation. §10a's argument about hundreds of spectators' phones applies here, and unlike the mesh it dismissed, this one is not optional.
- **No clean master, which the scope note makes decisive rather than unfortunate.** Unlike the ZowieBox path (§4c), nothing independently records the unencoded feed — so [`POSTPRODUCTION.md`](POSTPRODUCTION.md) and §3c's replay problem lose their free answer, and with the archive weighted equally with the stream, this tier gives up half of what the box is for. It is the reason §12a expects a partial go rather than a clean one.

#### The argument this hands the appliance

Worth noticing, because it is the first thing that favours rung 1 over rung 2. **A hardware switcher cannot ingest RTMP.** It wants SDI, HDMI or at best NDI — so an XbotGo cannot feed §3b's switcher at all, while the box can accept it and could even re-emit it as NDI for a switcher downstream.

**Where the cheap automated cameras are the source, the appliance is not merely the cheaper option — it is the only one that works.** That is a better reason to build it than cost, and it is worth weighing when §12 reaches phase 3.

---

## 5. The board, and the Pi 5 is the wrong way round

This is the one place where the intuition inverts, so it is worth stating as a finding rather than a preference.

**The Raspberry Pi 5 removed the H.264 hardware encoder, and the H.264 hardware decoder with it** — not an aberration but part of a pattern [§5c](#5c-the-market-is-moving-away-from-what-this-needs) sets out. It keeps HEVC decode; H.264 in both directions is software on the A76 cores. The Pi 4 has both in hardware.

The box's job is *decode H.264 in, encode H.264 out*. That is precisely the workload the Pi 5 gave up and the Pi 4 still does in silicon. **The 8GB Pi 4 already owned is the better board here, and a Pi 5 is a downgrade for this specific task** — the opposite of the usual advice, and the opposite of the assumption this idea started from.

Two secondary points. A 2GB board is too little once a browser engine is resident; 4GB is the floor and the 8GB Pi 4 is comfortably right. And boot from a USB SSD rather than an SD card — see §10.

### 5a. Can the Pi 5's cores just do it in software?

The obvious rebuttal to §5 is that the A76s are much faster than the A72s, so the missing silicon should not matter. **For decode, that is correct. For encode, it is true in a way that does not help.**

| task | Pi 4 | Pi 5 |
|---|---|---|
| H.264 **decode** 1080p in software | tight | comfortable — a fraction of one core |
| H.264 **encode** 1080p, `medium` preset | ~9 fps | ~24 fps — still not real time |
| H.264 **encode** 1080p30, `ultrafast` | out of the question | works; **~80% CPU** at 4 Mbps |
| H.264 **encode** 1080p60, `ultrafast` | out of the question | reported achievable |
| H.264 encode in hardware | 1080p30, ~free | **does not exist** |

So the answer is a qualified yes with three conditions attached, and the conditions are what matter:

**It only works at `ultrafast`, and `ultrafast` is worst at exactly our content.** That preset buys frame rate by giving up compression efficiency, and the video it handles worst is high-motion wide shots at a modest bitrate — a camera panning across a field is close to the worst case you can hand it. A Pi 4's hardware encoder at 1080p30 will likely produce a *cleaner* picture at the same bitrate than a Pi 5 at 1080p50 `ultrafast`, so this is not simply trading CPU for smoothness; it is trading artefacts for smoothness.

**It consumes the machine, and this machine has another job.** 80% of four cores for 1080p30 leaves very little for WPE rendering the overlay page, the PHP server, audio (§3a) and NDI depacketisation. The reports are specific on this point: software encoding performance *"drops significantly when additional graphic applications are running"* — and a browser engine compositing a 1920×1080 layer is precisely an additional graphics application. The measurement that matters is therefore not "can a Pi 5 encode 1080p" in isolation; it is whether it can encode while doing everything else in §12's phase 3, and nobody has published that.

**Sustained beats peak.** A benchmark runs for a minute; a game runs ninety. Four A76s pinned near 100% in a sealed case at a summer tournament will throttle, and throttling on a live stream is a soft failure — dropped frames that look like a bug in our software rather than a hot board. It also puts the Pi 5's 5V/5A supply requirement on the critical path (§10).

**The Pi 4's hardware encoder has none of these properties.** It is a corner of the die doing one job at roughly constant power, leaving all four cores for the browser, and it does not care how long the game is.

### 5b. The exit, if 50p ever becomes non-negotiable

**It is not a different Pi.** If the frame rate in §6 is ever mandatory, the answer is a board with a real encoder — RK3588 (Orange Pi 5, Radxa Rock 5, around €100) does H.264 and H.265 in hardware far beyond 1080p60, with better I/O as well.

The reason this is worth knowing now is that **it costs nothing to keep the option open.** Nothing in the software plan is Pi-specific: GStreamer, V4L2 M2M encoding, WPE, PHP and a systemd unit all move to another ARM board largely unchanged. Choosing the Pi 4 today is therefore not a decision that has to be right — which is the best thing that can be said about any hardware choice made this early.

**But the exit costs much more than €100, and the reason is §9 rather than the port.** The maintenance plan in §9 works because Raspberry Pi OS exists: a real Debian derivative with a security team, one hardware target, and a decade of availability, which is what allows this project to own a package instead of an operating system.

RK3588 does not offer that yet, and the gap is precisely in the part we need. Mainline Linux gained RK3588 hardware *decode* only recently (Collabora's VDPU381/383 work), while **hardware H.264 encode still effectively means Rockchip's vendor BSP kernel — 5.10 or 6.1 — plus `rkmpp`.** The trade is put well in the reporting on it: you can have a recent, powerful processor with a vendor BSP, or an older one with mainline, but not a new one with mainline.

**A vendor BSP kernel is exactly the obligation §9 exists to refuse.** So the honest cost of the RK3588 exit is not a €100 board, it is the maintenance model. Revisit when mainline encode support lands, not before — and in the meantime §5d is a better exit in every respect.

### 5c. The market is moving away from what this needs

Worth stating because it explains why the board question keeps coming out badly. **Single-board computing's energy is going into NPUs, and hardware video encoding has quietly regressed.** The Pi 5 dropped H.264 encode and decode (§5). NVIDIA's Jetson Orin Nano dropped the NVENC block its predecessor had. RK3588 has a good encoder that mainline Linux still cannot drive (§5b). Meanwhile TOPS figures climb on every product page.

So the intuition that "newer board, more capable" fails here specifically, and §5e explains why the accelerators filling that space do not help either. **The capability this project needs peaked a generation ago on ARM SBCs** — which is the whole reason a five-year-old Pi 4 beats its successor at this one job.

It has not regressed everywhere, though. It has been sitting on x86 the entire time, cheaply, and nobody advertises it.

### 5d. Off the Pi entirely: x86, which is cheaper than it sounds

The strongest option is the boring one, and it is the only category that satisfies **both** constraints at once — a real hardware encoder *and* a mainline-supported distribution with a security team behind it. Every ARM answer so far has made you choose.

| | encoder | distro | rough cost |
|---|---|---|---|
| Pi 4 | H.264, 1080p30 | Raspberry Pi OS — maintained | ~€75 + PSU, cooler, SSD |
| Pi 5 | none (§5a) | Raspberry Pi OS — maintained | ~€60–80 + more |
| RK3588 | good, needs vendor BSP | Armbian or a vendor image | ~€100 |
| **Intel N100 / N150** | **QuickSync — H.264 and HEVC** | **plain Debian, mainline** | **~€60–80 board, ~€110–140 complete** |

The numbers are not close. **QuickSync on an N100 transcodes three to four simultaneous 1080p streams at almost no CPU cost, and around ten for 1080p H.264 to H.264.** This box needs *one*. Every constraint §5a agonises over — 80% CPU, `ultrafast` quality, thermal throttling over ninety minutes, the browser competing with the encoder — simply does not arise. The 6W TDP is in the Pi's class, and a complete mini PC draws 8–12W under load.

**Two form factors, and they suit different halves of this document.**

The **Radxa X4** is an N100 in a Pi 5 form factor at roughly €55 for 4GB and €75 for 8GB, with dual micro-HDMI out, an M.2 slot, 2.5GbE and USB 3.2 at 10 Gbps. That last one meaningfully relieves §4, where the Pi 4's capture bandwidth runs through a VL805 on a single PCIe 2.0 lane; the M.2 slot serves §7a's recording without the §5e slot conflict; and the two HDMI outputs serve §3b's fill and key.

A **generic N100 mini PC** at €110–140 arrives with RAM, SSD, case, cooler and a real power supply. That is worth noticing against §10, because most of §10's field-reliability list is a single-board-computer problem rather than a computer problem: SD card corruption, brownouts from an inadequate supply, and a bare board cooking in a bag are all things a finished mini PC has already solved. Buying one deletes three bullets.

**And it answers the objection that killed RK3588.** The concern with leaving the Pi was losing a mainstream platform. But on x86 the *software* is the most mainstream target in existence — plain Debian, `i915`, VAAPI, no vendor kernel, no BSP, no board-specific image — so the community that matters is not the board's. Nor is the board a dependency: any N100 machine runs the same install, so unlike RK3588 there is no vendor to be locked to and nothing to be stranded by. What is genuinely given up is Raspberry Pi's long availability guarantee and its unmatched community for *hardware* questions, which is real but narrower than it first appears.

**The split that falls out is clean.** §3b's graphics source needs no encoder at all, so a Pi is the right board for it and the cheapest. The all-in-one needs an encoder that is not embarrassing, so it wants x86. The two shapes want different hardware, and neither choice constrains the other, because §5b's portability argument holds in this direction too — and holds better, since x86 needs no porting at all.

**The one place the Pi keeps a clear advantage is power.** It runs on 5V over USB-C from any bank; N100 machines mostly want 12V on a barrel jack, which at a field is a genuine problem rather than an inconvenience — §10b. That reinforces the split rather than complicating it: the low-power shape is the one that has to survive on a battery.

**None of which changes §12.** The Pi 4 on the desk is still the right prototype: it is free, it is there, and phases 1 and 2 need no encoder. This is a purchasing decision for the day phase 3 succeeds, not a reason to buy anything now.

*Also considered and not recommended: the ZimaBoard 2 (N150, PCIe 3.0 x4, dual SATA, dual 2.5GbE) is the same silicon class in a NAS-shaped board at $339–399 retail. The SATA ports and second network interface are not features this box has any use for, and the price is triple a mini PC that does the same job.*

### 5e. Would a Pi 5 AI HAT help?

**No, and the first reason is decisive: the Hailo-8/8L on the AI HAT+ is a neural-network inference accelerator with no video encoder of any kind.** It does not touch the §5a bottleneck. Nothing about adding one makes a Pi 5 better at the job this box actually has.

It also costs something concrete. The AI HAT+ and the M.2 HAT+ both use the Pi 5's single PCIe connector and are mutually exclusive, and that connector supplies at most 1A. §10's USB SSD keeps this survivable — the recording in §7a writes under a megabyte a second, so it does not need NVMe — but "AI accelerator *or* NVMe" is the real choice.

What it could plausibly do, worst to best:

- **Possession and turnover detection.** `AGENTS.md` is blunt: turnovers do not exist in the schema, and anything needing possession is *"impossible, not merely hard"*. Filling that absence with computer-vision guesses would invert the most valuable discipline in [`STUDIO.md`](STUDIO.md) — refusing to put a number on air that the data cannot support. The absence is a feature. This is the worst idea on the list precisely because it is the most tempting.
- **Jersey numbers, to populate the line automatically.** [`COMMENTATOR.md`](COMMENTATOR.md)'s line picking is manual and this looks like the obvious win. It is the same trap one step down: numbers on moving players in a 1080p wide shot are read unreliably, and this project's characteristic failure is a graphic quietly asserting something untrue, which looks completely normal in a screenshot. A lineup that is 80% right is worse than no lineup.
- **Highlight and clip detection — which needs no accelerator at all.** The API already says when a goal happened. The one thing you would reach for AI to find, this project gets from data it is already polling, and §7a's recording is already timestamped against it.
- **Auto-framing, which was the only genuinely transformative one — and is already a product you can buy.** A robot camera operator attacks the binding constraint rather than a convenience, because [`MATCHCONTROL.md`](MATCHCONTROL.md)'s finding is that no crew size has anybody spare. But §4d settles it: XbotGo- and Pix4Team-class devices do this today, cheaply, better, and without touching this box at all. Building it here would also have made every constraint in the document worse — cropping without upscaling needs a 4K source, far past §4's ingest budget, and the crop still has to be encoded (§5a) — for a result that is purchasable.

**So the AI HAT has no remaining use case here.** Every candidate is a trap this project's discipline forbids, a thing the API already answers, or a commodity product. Not this box, and not any box — the question is closed rather than deferred.

### 5f. So is a Pi still the answer?

Yes — and on two of §1a's three rungs it is not a compromise but the correct choice. The x86 case from §5d is narrower than that section, read alone, makes it sound.

**Rung 2 (§3b) belongs to the Pi outright, and x86 would be actively worse.** No encoder is needed there, so the whole of §5 is moot. What matters instead: two HDMI outputs for fill and key, a ~5–7W draw on 5V USB-C — which per §10b is a full tournament day from one power bank, where the x86 box needs a trigger cable and manages four hours — and DRM/KMS planes (§7) being the Pi's home ground rather than an exotic option. Cheaper, cooler, simpler, better.

**Rung 3 wants a Pi too, just not for video.** A skilled crew runs OBS on their own laptop (§7b), but they still want phase 1's hub — and a €40 board drawing 5W that serves PHP all day is exactly that and nothing more.

**Rung 1 is where x86 earns its place, and less decisively than §5d implies.** The Pi 4's encoder does 1080p30 in hardware, and §6 settled on 25/30p for reasons that have nothing to do with silicon. At that frame rate §4's capture bandwidth is comfortable as well. The all-in-one on a Pi 4 therefore **works**; what it lacks is headroom.

**And every case where the Pi loses is one this document has already declined on other grounds.** 50p — wanted for slow motion, which §6b shows comes from a laptop's replay buffer rather than from this box's frame rate. Replay *on the box* — blocked by operators and control surfaces, not silicon (§6a). OBS resident on the appliance — set aside by the thesis (§1a). The Pi's weakness is real and it sits entirely inside territory already ruled out.

**The honest reason to keep x86 in view is a different one, and it is §13's first line.** If phase 2 or 3 finds `wpevideosrc` marginal on VideoCore, Intel's `i915` and Mesa are a far better-supported target for WPE. So x86 is the **recovery path** at least as much as the upgrade — worth knowing about before the day it is needed, which is the only reason §5d is written at the length it is.

**Nothing already on the desk is wasted under any of these outcomes.** Phases 1 and 2 are entirely Pi work, and they are the phases most likely to reach a tournament.

### 5g. The rung-1 comparison, and three things it is easy to get wrong

| | Pi 4 | x86 (N100) |
|---|---|---|
| price, built | ~€180–270 | ~€180–270 (mini PC includes PSU, SSD, cooling) |
| size | smaller *board* | comparable *rig* — see below |
| power | 5–7W, 5V USB-C, **10h+ on a bank** | 15–20W, mostly 12V barrel, **3–4h** |
| encode | H.264 1080p30, hardware, no headroom | QuickSync, ~10× what is needed |
| toolchain | GStreamer pipeline, ours | OBS, or the same pipeline |
| replay | no | **not by itself** — see below |
| WPE support | VideoCore, unproven (§13) | `i915`/Mesa, better supported |

**Video quality is measurable, not unknown — and it is the cheapest thing here to settle.** The Pi 4's fixed-function encoder is roughly `ultrafast`-class; Gen12 QuickSync is materially better per bit. At 1080p30 and the 6–9 Mbps a platform ingests, that gap is small for most content and largest on high-motion wide shots, which is unfortunately what this sport is. Encode the same clip on both at the same bitrate and look. An afternoon settles what no amount of reasoning will, and it should happen in phase 3 before any board is bought.

**x86 does not add replay. x86 *plus OBS plus an operator* adds replay.** §6a's blocker is the control surface and the person, not the encoder, and neither is supplied by a faster chip. Choosing x86 keeps the door open; it does not walk through it. The realistic route to replay stays §1a's third rung.

**OBS replaces the easy half of the work, not the hard half.** The pipeline is a handful of GStreamer elements — perhaps a week. The actual project is everything around it: supervision, central config reconciliation (§8b), audio sync (§3a), and failing *visibly* rather than silently. All of that is still needed with OBS, and §7c argues OBS makes several of those harder — its state is a scene collection rather than a document, and its error handling is a dialog box on a machine with no screen. The saving is real but it is smaller than "we would not have to build the toolchain" suggests.

**Two things the comparison leaves out.** Rung 2 (§3b) is a Pi regardless and is unaffected by any of this — so this decision binds only the all-in-one, which is the tier furthest from being built. And the compactness gap is thinner than it looks: once a Pi has a case, a PSU, a USB SSD and a capture dongle hanging off it, the assembled rig is comparable to a mini PC. The Radxa X4 collapses the distinction entirely — Pi form factor, N100 silicon, USB-C power — and is worth pricing and power-testing before treating "compact" and "x86" as opposites.

**None of which has to be decided now.** Phases 1 and 2 are Pi work either way, phase 3 measures, and the board is a phase-3 purchase made with numbers instead of arguments.

### 5h. Bring your own hardware — the option only x86 has

Choosing x86 opens something ARM cannot: **boot the whole appliance from external media on a computer the tournament already owns.**

**This is a real argument for x86 that the sections above miss.** Every ARM board needs its own image and its own bootloader; x86 has a universal boot standard, so one install boots almost anything. It is the only path where "plug this in and restart" is a sentence you can write.

**And for a product other tournaments deploy, it may matter more than anything else here** — because it removes the hardware purchase from the trial. A tournament curious about this needs a €25 USB SSD rather than a decision to buy a box, and §11's "for a single rig, buying the Magewell is cheaper" argument stops applying to *trying* it.

**A laptop is better than a mini PC in exactly the ways §10 complains about.** It arrives with a **battery** (§10b), a **screen** — the confidence monitor §10 calls the subtle gap — a keyboard and a working power supply. Three of §10's five bullets are solved by hardware already owned. QuickSync has been on essentially every Intel laptop for a decade, so §5d's encoder requirement is met almost incidentally.

#### Do it as a normal install on external media, not a live ISO

This distinction is the whole thing. **A live ISO is an image, which is precisely what §9 spends a section refusing** — you would own the operating system and its security updates again, and for a many-tournament product that is the obligation being avoided.

**A plain Debian install onto a USB SSD is not an image.** It is the same install §9 describes with a different target disk, `apt` and all, so the maintenance model survives intact. It boots any UEFI machine, and Linux loads drivers at boot rather than baking them in, so a stick made on one machine runs on another. Watch three things: UEFI versus legacy boot, Secure Boot (Debian ships a signed shim, so this is a setting rather than a wall), and interface naming, which differs per machine — match on type, never on `enp3s0`.

**Use an SSD, not a flash stick.** §7a writes continuously; a cheap USB stick is slow and dies under sustained writes, which would present as §10's random unattributable failure.

#### What it costs

- **"Any x86 computer" gives up §9's one-hardware-target premise**, which is the thing that made the maintenance story cheap. Every machine has different wifi, a different GPU and its own quirks, and *"it does not boot on my Dell"* becomes a support burden for a product. The honest posture is a known-good list, supported properly, and everything else best-effort — with the purchased box remaining the path that is actually guaranteed.
- **The hardware assumptions stop holding.** An AMD machine has VAAPI with different quirks; an old Intel may lack the encoder generation. So this needs a first-boot capability check that says plainly whether this machine can do the job — which is [`SETUP.md`](SETUP.md)'s argument exactly, verify rather than assume, and has a precedent in [`../tests/selftest.php`](../tests/selftest.php), a diagnostic page that exists to answer this question about a device nobody can inspect.
- **A pocket-sized system carrying credentials is easier to lose than a box.** §8a's concern gets worse, and it argues harder for §8b's central provisioning, where a key is fetched rather than stored and a missing unit is de-enrolled rather than hunted.

**It does not conflict with §1a**, because the scope note splits the roles: changing a boot order is a setup task for the technical person, and the volunteer still meets a box that is already running.

---

## 6. 50 fps is bought for a feature this box does not have

The frame rate is the requirement that drives the board choice, so it is worth being precise about what 50p is actually *for*. Two things, and they have different answers.

**Motion rendering.** 50p genuinely looks better live than 25p on a fast horizontal pan following a disc, and this is real rather than pixel-peeping. It is also a modest difference on a laptop or a phone, which is where this is watched.

**Slow motion.** This is the substantial one — 25p slowed to half speed is a slideshow, 50p is not. But slow motion is only worth having if somebody can *play it*, and replay control is the Director Mini's headline feature, not this box's. §3 already conceded it.

That concession settles the frame rate, and it settles it more firmly than a performance argument could:

- **A replay needs an operator, and there isn't one.** [`MATCHCONTROL.md`](MATCHCONTROL.md) works through crew sizes one to four and finds nobody spare even for the score button. Replay is out of reach for staffing reasons before it is out of reach for technical ones — and unlike a codec, that does not improve with a better board.
- **The 50p master usually already exists, on the camera.** Where a camcorder is recording its own card, archive quality is a camera menu rather than a Pi decision, and [`POSTPRODUCTION.md`](POSTPRODUCTION.md) works from that file rather than from the stream. But §4d breaks this for the cheap tier: an AI camera that only pushes RTMP may record nothing locally, so there is no master and the stream *is* the archive. Where that is the source, the box's own recording (§7a) is the only artefact that exists — which raises what the recording is worth rather than what the frame rate should be.
- **Viewers can already do this themselves.** Platform DVR lets a live audience scrub back and play at reduced speed. Not as good as a produced replay with a commentator talking over it; considerably better than nothing, and free.

**None of which makes the loss small, and it should not be written up as though it were** — though §6b finds it recoverable for about €10 wherever a laptop is already in the rig, and only genuinely lost on the appliance. A replayed goal with a commentator talking over it is the most watchable thirty seconds of any broadcast, and giving it up is the largest single quality gap between this box and the hardware it is meant to displace — larger than the frame rate, larger than the missing second camera. The argument is not that replay does not matter. It is that this box cannot get there from here, on hardware and on control surface both, and pretending otherwise would produce a worse plan than conceding it.

**So: 1080p25 or 1080p30, on the Pi 4, in hardware.** Not as a compromise forced by the silicon, but because the capability 50p exists to serve is one this box has decided not to have. If that decision is ever reversed, the answer is not a faster Pi and not a better pipeline — it is §3b, letting a switcher do the thing switchers are for.

**And note that this conclusion survives §5d, which is the strongest thing about it.** On N100-class hardware 50p is free — the encoder would not notice — so the frame rate stops being a constraint and becomes purely a choice. The choice is still 30p, for the reasons above, which means the argument was never really about silicon. Where §5d does help is in removing the *coupling*: today a frame rate decision is also a board decision, a thermal decision and a quality decision, and it should not have to be any of those.

One asymmetry worth carrying into §7 whatever the answer: **the video wants the frame rate, the graphics do not.** The clock ticks once a second and nothing moves except a card arriving or leaving. The browser layer never needs redrawing at the video's rate.

### 6a. Replay on the box later, and why not now

Not impossible. `splitmuxsink` keeping a rolling buffer of the last minute on the SSD, and a "replay the last point" control in the Studio, is a recognisable design.

Four reasons it stays out of scope on a Pi 4 — and **§5d's hardware removes the first two entirely**, which is worth knowing precisely because it means the remaining reasons are the real ones.

**On a Pi 4:** it cannot encode two things at once, so a replay would have to **cut the program** rather than sit in a corner — §8's destructive-change hazard aimed at the most exciting thirty seconds of the game. And re-entering a live stream after a source switch means a discontinuity platforms handle unevenly.

**On an N100, both of those stop being true.** QuickSync handles roughly ten simultaneous 1080p H.264 transcodes and this needs two — a clean encode of the camera feeding the ring buffer, and the program — plus a decode for playback, which is nothing. The discontinuity problem turns out to have been an artefact of the Pi 4 design rather than a property of replay: switch sources into the compositor rather than switching the encoder's input, and the output stream never has a discontinuity at all, because the encoder simply sees different pixels. That is how a real switcher works, and it is available here for free once the encoder is not the scarce resource.

Two implementation details would still need care. The replay buffer wants **short segments or an in-memory ring**, which is a different mechanism from §7a's long archival segments — the last five minutes of a five-minute segment is not readable yet. And smooth slow motion wants short GOPs or intra-only encoding, since seeking otherwise decodes from the previous keyframe; QuickSync makes that affordable where the Pi's encoder would not.

**So the hardware answer is yes, comfortably.** What remains is the pair that has nothing to do with silicon: it needs the operator [`MATCHCONTROL.md`](MATCHCONTROL.md) says no crew size has spare, and — the one that actually closes it —

**there is no control surface, which is the same finding as §3a's faders arriving somewhere new.** Replay is scrubbing: find the moment, mark in, mark out, roll. That is a jog wheel and dedicated keys, and it is a task where the operator's hand and the picture have to agree in tens of milliseconds. Show state polls at about a second. A web UI is the wrong instrument for this in the same way it is the wrong instrument for riding a fader — not underbuilt, but structurally unsuited, and no amount of work on this project's side fixes it. Twice now the answer to "should the Studio control this?" has been *no, that belongs to hardware with knobs on it*, and noticing the pattern is worth more than either instance.

**So the conclusion is unchanged but its reason has moved, which matters for what to buy.** Replay is blocked by people and instruments, not by silicon — so no future board purchase unblocks it, and equally, choosing §5d's hardware for other reasons quietly leaves the door open should the control-surface question ever get a good answer. In the meantime replay comes from a switcher, via §3b, which is why that section is written as a peer rather than a fallback.

### 6b. Replay off the box entirely — cheap where there is a laptop, not otherwise

The structural move §1b reveals is that **replay does not have to belong to the switcher or to the box.** Put it on its own device and the control-surface objection dissolves rather than being worked around — the device brings its own screen, and nothing here grows a jog wheel. That reopens replay at every rung, including the appliance.

What it costs varies by nearly two orders of magnitude:

| approach | cost | records what |
|---|---|---|
| **OBS replay buffer** on a laptop already in the rig, fired from a numpad (§1c) | **€10** | whatever OBS is compositing — the real camera |
| **HyperSlow + HyperDeck** (§1b's rig) | **€600–800** | a clean feed off the switcher, synced across decks |
| switcher-native replay | the switcher | depends on the tap point (§3c) |
| a free iPad app on its own camera | €50 | **ruled out** — see below |

**The free tier does not survive contact with this sport, and the reason is optics rather than software.** Those apps buffer the tablet's *own* camera, and an iPad has a fixed wide lens with no optical zoom. An Ultimate field is 100m end to end and 37m wide; framing all of it puts the players at a scale where the disc is a few pixels and a slow-motion replay shows nothing worth watching. No amount of frame rate fixes a focal length. Add rolling shutter on fast pans, no exposure or ND control outdoors, and nobody spare to operate it, and the row is not a compromise — it is unusable. Struck rather than caveated.

*(The one door it leaves open: iPadOS supports UVC input over USB-C, so a tablet **fed** a real camera signal is not absurd. Whether any of these apps accept an external source rather than the built-in camera is unknown and probably no, since they are camera apps. Not worth pursuing before the OBS row is tried.)*

**Which leaves the OBS row as the genuinely cheap answer, and it is cheap for the right reason:** it buffers the feed the rig is already producing rather than a second-rate view of the same field. The replay buffer is free and built in, and §1c's numpad supplies exactly the discrete control it needs.

**The conclusion is rung-dependent.** Where a laptop is in the rig — §1a's top two rungs, and §1b's real one — replay costs about €10 and the largest quality gap in this document mostly closes. On the appliance, with no laptop, it stays expensive or absent, which is one more thing the bottom rung gives up and the upper ones do not.

If the control surface is ever attempted, the honest starting point is that **it is a solved problem in off-the-shelf software** rather than something to design — see §7b.

---

### 6c. Replay is where the data advantage is decisive

§6a and §6b treat replay as a capability to acquire. That misses what is actually interesting: **a replay operator's job is to mark the moments, and this project already knows them.**

That is the §1b argument again — *nobody has to keep score twice* — applied to video. HyperSlow gives an operator six buttons to mark events by hand. Here the marks arrive as data, timestamped, for free, because somebody already entered them for another reason.

#### What is already collected, and it is more than expected

| signal | where it lives | what it marks |
|---|---|---|
| **goal times** | the Live! API | every score |
| **hold or break** | derived, already on the scoreboard | a break is the more notable highlight, definitionally |
| **turnovers** | `shared/possession.js` — `turnovers()`, `eventsFor()` | every change of possession |
| **defence events** | `shared/tracking.js` — `setDefence()`, and `defenceTouched()` / `defenceHasDisc()` | blocks and defensive pressure |
| **stoppages and timeouts** | `shared/stoppage.js`, `shared/timeouts.js` | when it is safe to play something |

**One correction worth making explicitly**, because `AGENTS.md` says turnovers do not exist: that is true of *UltiOrganizer's schema*. It is not true of this project, which built its own collected-facts store precisely because the gap existed. The possession log is append-only, timestamped and keyed per game — which is to say it is already a replay marker track, built for a different purpose.

So the O/D distinction is closer than assumed. A **break is a defensive highlight by definition**, and `setDefence` marks the rest.

#### It requires a clean recording, which is a real new constraint

§7a records the *program*, with the overlay burned in. A clip extracted from that carries the score as it was at the moment of extraction, not the moment of play — **§3c's frozen scoreboard, arriving by a third route.**

So automatic replay needs a **second, clean recording** alongside the program one. That is affordable where §6a established two encodes are fine — comfortably on x86, not on a Pi 4 — and free where §4c's ZowieBox is already recording a clean master. It is one more reason the cheapest camera tier (§4d) is the weakest: no clean feed, so no usable replay.

#### Injury is a stoppage marker and must never be a highlight

The system does know who was injured; [`COMMENTATOR.md`](COMMENTATOR.md) tracks it, because a desk needs it. **That makes this the one tag in the list with a privacy edge, and it needs stating before it is built rather than after.**

`AGENTS.md` is already explicit that `notes.php` holds personal data none of the other stores do, and treats that as load-bearing rather than tidy. **An injury is health information about a named person.** So:

- **As a stoppage marker it is genuinely useful** — an injury stoppage is unplanned dead air, exactly when filler is wanted.
- **As a highlight tag it must not exist, and an automatic playlist must never be able to select one.** Replaying somebody's injury, or naming them under it, is not a thing this system should be capable of doing by accident at three in the afternoon because a rating was high.

The distinction is cheap to enforce and expensive to retrofit: **injury marks the interval, never the clip.**

#### The operational design lives in [`REPLAY.md`](REPLAY.md)

How a clip is triggered and tagged, how the two event streams are reconciled, how a playlist is assembled to fit a stoppage, and what the review surface has to do are worked out there. **Two conclusions from it matter at this altitude:** the control surface is about eight keys, which is [§1c](#1c-where-a-hardware-control-earns-its-place-and-where-a-10-numpad-does)'s €10 numpad; and the review UI is an application rather than a panel, so it is the largest single piece of work anywhere in this document.

#### Two honest cautions

**This is a replay system, and that is a different product** — the same warning §8c carries about broadcast management. The difference is that this one sits directly on top of the asset that makes this project distinctive rather than off to one side, which makes it far more defensible. It is still not small.

**And it favours the all-in-one, which little else does.** Replay needs the video, the marks and the output in one place. §3b's graphics source never touches the video and cannot do this at all; the OBS rung could, driven over obs-websocket. Alongside §4d's RTMP ingest, this is the second real argument for building the box — and §12a should weigh it, because a feature nothing else can deliver is worth more than a cost saving anything can.

---

### 6d. Replay needs the frame rate at capture, not on the wire

**Once replay is in scope, [§6](#6-50-fps-is-bought-for-a-feature-this-box-does-not-have)'s reasoning needs revisiting** — it ruled out 50p partly because nobody could play slow motion, and [§6b](#6b-replay-off-the-box-entirely--cheap-where-there-is-a-laptop-not-otherwise) puts replay at about €10. The output conclusion survives; the requirement moves.

**But the requirement lands somewhere cheaper than "stream at 50p".** Slow motion needs frames to *slow down*, which is a demand on capture and the replay buffer — not on the output. Capture at 50 or 60, encode the programme at 25 or 30, and a replay drawn from that buffer plays at half speed with a full complement of unique frames inside a 30p stream. The output rate was never the thing that made slow motion smooth; the source rate was.

So [§6](#6-50-fps-is-bought-for-a-feature-this-box-does-not-have)'s output conclusion survives — **25/30p out is still right** — but its reasoning changes, and one requirement moves upstream: the capture path and the ring buffer want the higher rate the moment replay is in scope.

**And that couples to the board.** Holding a 50p buffer while encoding a 30p programme means the buffer is not simply a tee off the encoder — it is a second, faster path. [§6a](#6a-replay-on-the-box-later-and-why-not-now) already established two encodes are comfortable on QuickSync and out of the question on a Pi 4. So replay does not merely make x86 attractive, it makes the Pi's ceiling load-bearing — which is one more entry in the N100 column and, unlike the others, a consequence of a feature rather than a preference.

#### Producing replays is what lets the stream stay at 30p — and that pays for itself

The trade runs both ways, and the return side is larger than the cost.

**Why would anyone stream 50/60 at all?** [§6](#6-50-fps-is-bought-for-a-feature-this-box-does-not-have) gives two reasons: motion rendering on pans, and slow motion. And its consolation for having no replay was that *viewers can scrub a platform's DVR themselves* — but a viewer doing their own slow motion needs the high frame rate to be in the stream, because they are slowing down whatever we sent them. Not producing replays pushes the frame rate onto the wire, permanently.

Produce them instead and the slow motion happens **before** transmission, out of a buffer that never leaves the box. The stream then only has to carry the live picture, and the remaining case for 50p is motion rendering alone — which [§6](#6-50-fps-is-bought-for-a-feature-this-box-does-not-have) already judged modest.

**The saving is recurring, which is the kind that matters.** 1080p50 costs roughly 1.5 times 1080p30 at comparable quality — call it 9–10 Mbps against 6. That is three or four megabits per field, at every event, forever, against exactly the constraint [§10a](#10a-the-network-at-a-field) identifies as never stopping. Across six fields it is around 20 Mbps of upload, which is the difference between a venue line working and not.

**So a one-time hardware cost buys a permanent operating saving:** capture and buffer at 50/60 needs the headroom only x86 has (§6d above), and in exchange the wire carries 30p for the life of the rig. The feature that most justifies building the box also reduces the cost that most threatens scaling — and those are [§1a](#1a-the-thesis-more-games-watchable-within-two-hard-constraints)'s two constraints pulling the same direction for once, rather than against each other.

**One bonus falls out of it.** If the capture is already running at 50/60 for the buffer, the archive can be recorded at the higher rate while the live stream stays at 30p — uploaded off-peak per [§10a](#10a-the-network-at-a-field), costing nothing live. The VOD that [§8c](#8c-driving-the-platform-from-the-schedule) calls the real prize then ends up better than the broadcast was, which is not usually how this goes.

### 6e. It has to work in NTSC regions too

This document has been writing "25 or 30" and "50 or 60" as though the pair were interchangeable. **They are not, they are regional, and a rig may be shipped to either.**

- **PAL regions** — 25 and 50.
- **NTSC regions** — 30 and 60, and in practice the fractional rates **29.97 and 59.94** rather than the round numbers.

**The rule that matters is that the whole chain agrees.** Camera, capture device, buffer, encoder and platform must be on one family end to end. A 50Hz source into a 60Hz pipeline does not fail loudly — it judders, drops or repeats frames, and looks exactly like a performance problem in the box, which is the worst possible disguise given how much of this document is about proving the box keeps up. Somebody will spend a day on the encoder before checking the camera's region setting.

Two practical consequences:

- **Frame rate belongs in the per-installation configuration** ([§8b](#8b-most-of-this-should-not-be-configured-at-the-field-at-all)), not in a constant. It is tournament-wide, known before anyone arrives, and exactly the sort of thing that section exists to deliver.
- **Mains frequency is the other half of the same problem.** Indoor venues flicker at the local mains rate, so shutter speed has to match it — a camera setting rather than ours, but it belongs on [`SETUP.md`](SETUP.md)'s checklist because it is invisible until the footage is unusable and unrecoverable afterwards.

## 7. Compositing: `wpesrc` for the stream, hardware planes for the graphics source

The instinct to reach for DRM/KMS hardware planes — video on one plane, the overlay on another with per-pixel alpha, blended by the HVS at scanout — is sound, and it is how set-top boxes have done graphics over video for a decade. **It is right for one of this document's two shapes and useless for the other**, and the dividing line is not difficulty.

**Planes composite at scanout. An encoder needs one buffer in memory. A scanout plane never produces one.** Hardware planes are the right answer when the output is HDMI to a screen — a venue display, a projector — where nothing is encoded, nothing is composited in software, and glass-to-glass is about a frame. They buy nothing at all when the output is a stream. (The vc4 TXP writeback connector is the exception that proves it, and it is a research project rather than a plan.)

**For the streaming case the piece already exists.** GStreamer's `wpesrc` / `wpevideosrc`, shipped in Debian as `gstreamer1.0-wpe` since bookworm, renders a web page through WPE WebKit into a GL texture. Igalia maintains a demo of exactly this use case — web-augmented graphics overlay broadcasting. The pipeline is roughly:

```
<source> ! decode ! glvideomixer name=m ! v4l2h264enc ! flvmux ! rtmpsink
wpevideosrc location=<stage URL> draw-background=0 ! m.
```

That collapses the hard part into an existing, maintained element, and the compositing itself is trivial work for the VideoCore VI. **Test `draw-background=0` before anything else** — transparency is the property in that element with a documented history of not working, and every surface in this project is transparent by design.

Two notes for the day someone does build the plane version for a venue screen. It needs full KMS (`dtoverlay=vc4-kms-v3d`, not fkms), and the format the overlay plane advertises should be read out of `drm_info` rather than assumed: vc4 handles planar and semi-planar YUV, and packed YUYV — which is what most capture dongles emit — may not be on the list, in which case the zero-copy story gains a conversion hop. The one thing the plane approach wins outright is §6's asymmetry: the graphics plane can sit at 25 fps while the video plane runs at 50, for free, because an unchanged plane costs nothing to scan out.

**And the project's own rule settles the tempting shortcut.** *"One renderer, never two"* (`AGENTS.md`). Rendering the overlay natively on the box from the same JSON would be faster and would immediately be a second implementation of every graphic, drifting from the first. The browser is the renderer, on the appliance as everywhere else.

### 7a. Local recording, which is nearly free and worth more than it costs

**This is a primary deliverable, not a bonus.** The scope note at the top weights the archive equally with the stream, which changes what this section is: not a cheap extra that happens to fall out of the pipeline, but half the reason the box exists. §12a makes it a stop condition.

**Record the encoder's output, not a second encode.** The pipeline already produces an H.264 stream for the platform; a `tee` after the encoder into a segmenting sink writes the same bytes to the SSD. One element, no extra CPU, no extra silicon. It is simultaneously the cheapest feature in the document and, per §10, the one that fills the most conspicuous gap against the Director Mini.

The consequence of recording the encoder output is that **the file is a copy of the stream, not a better version of it** — same bitrate, same encoder artefacts. That is the right trade anyway, because §6 already established that the high-quality master exists on the camera's own card. The box is not competing with it.

**What the box's recording has that the camera's does not is the overlay burned in.** The camera master has no scoreboard. So this file is the only artefact with graphics on it, and it is publishable as a VOD with no post-production at all.

#### What it is actually for

- **Uplink insurance.** [`RELAY.md`](RELAY.md) names this precisely: *"what offline buys is local recording, and — the valuable one — fault tolerance against an uplink that comes and goes."* A stream that drops for ten minutes leaves a hole; the recording does not have one.
- **A produced game where there was no stream at all.** A field with no signal still gets a finished video with a scoreboard on it. That is a real outcome at tournaments where one field always has worse coverage than the rest.
- **And it is how a tournament covers more games than its uplink can carry live** — record now, upload off-peak. [§10a](#10a-the-network-at-a-field) argues this is the cheapest way to raise the number of games covered, and it needs nothing this section does not already do.
- **It collapses [`POSTPRODUCTION.md`](POSTPRODUCTION.md)'s central problem for its own footage.** That document exists because aligning a video's timeline to a game's timeline is hard and needs anchors — *"goal n is at this position in the video"* — which [`SETUP.md`](SETUP.md) calls the most expensive thing to get wrong at teardown, because it is unrecoverable once everyone has left. A recording made by this box needs no alignment: the box knows the wall-clock time of every frame it wrote and is already polling the API that knows when the goals happened. It can emit the anchor file itself, automatically, and that teardown item disappears for every game it records.

#### The details that decide whether it works

- **Segment, and do not write a single MP4.** An MP4 still being written when someone pulls the power is an unplayable file — the index is written last. Segmented MPEG-TS or fragmented MP4 survives truncation, and costs at most the final few minutes. This is the characteristic recording failure and it is entirely avoidable.
- **The recording branch must never stall the stream.** A `tee` into a slow disk applies backpressure to the whole pipeline, so a struggling SSD can take the live broadcast down with it. The recording branch gets a queue that drops rather than blocks. Stated as a rule: the stream is the primary function and the recording is subordinate — if the disk fills or falls behind, abandon the recording, keep streaming, and say so in the Studio. Never the reverse.
- **Always on, never a button.** An operator who has to remember to press record will eventually not, and that failure is total, while the cost of recording something nobody wanted is a few gigabytes. Record everything, prune by age. At 1080p30 and 6 Mbps this is about 2.7 GB per hour — an eight-hour tournament day is roughly 22 GB, so a 256 GB SSD holds a season of weekends. Storage is not a constraint; remembering is.
- **Name segments by game id and wall clock**, which is what makes the automatic anchor generation above possible at all.
- This makes the USB SSD from §10 mandatory rather than advisable — sustained writes to an SD card are the other half of why those cards fail in the field.
- And it is one more thing that only exists in the software-pipeline design: **§7's hardware-plane variant cannot record**, for the same reason it cannot stream. Planes remain a venue-screen-only option.

### 7b. The fork §5d opens: OBS instead of a pipeline

Choosing x86 has a consequence worth confronting rather than discovering later. **Once the board is a normal PC, OBS Studio is right there, and it already does most of this document.**

The overlap is uncomfortable to look at: a browser source, scene switching, **a replay buffer built in**, VAAPI encoding with QSV in recent Linux releases, RTMP and SRT output, recording, and an audio mixer with filters, meters and a limiter — which is §3a's entire list of what software should own. It is packaged in Debian, so §9's maintenance model applies to it unchanged. And it is remotely controllable: obs-websocket has been bundled since OBS 28, so the Studio could drive it rather than driving a pipeline we wrote. Even the browser objection is weaker than it looks — `AGENTS.md` already notes that OBS moved off CEF 75 and current releases ship Chromium 95 or newer.

**Said plainly: if OBS does the job, a large part of this document is a research project competing with working software.** That is worth saying out loud before any of it is built.

The case against is real but narrower than it first appears, and it is all about *what kind of thing the box is*:

- **OBS is a GUI application, and an appliance is not.** This box should boot into a known state and be touched by nobody; OBS wants a display server, a window and a person. Headless operation is possible and is working against the grain, which is exactly the kind of arrangement that fails at a tournament rather than on a bench.
- **Its configuration is a scene collection, not a document.** §8b wants config generated centrally and applied deterministically. obs-websocket can set things at runtime, but reconciling a declarative `conf/video.json` against OBS's own persisted state is a synchronisation problem a pipeline simply does not have.
- **The browser is known but not ours.** §2's first reason is only partly won: we would not be pinning the engine, only trusting somebody else's choice of it — better than a switcher's undocumented blob, weaker than the guarantee that motivated this.
- **More surface, more to go wrong**, in a box whose entire value proposition is that it fails less than the alternative.

**§1a does not settle this, though it looks as though it should.** The thesis governs what an *operator* has to know, and hiding the UI satisfies it completely — it says nothing about which engine runs behind a Studio page the operator never leaves. So the question is open on x86: **OBS as a hidden engine is a serious candidate for the standard, not a niche upgrade.** Restating the four objections against that version specifically:

| objection | does it survive the UI being hidden? |
|---|---|
| It is a GUI application | **weakened.** §7c shows headless is a solved, documented problem — a €8 dummy plug and it runs on the real GPU |
| Its config is a scene collection, not a document | **survives, but is solvable** — regenerate the collection from `conf/` on every start, so OBS's own persistence is derived and never authoritative |
| The browser is known but not ours | **survives, and is weak** — current Chromium rather than a switcher's undocumented blob |
| More surface, more to go wrong | **cuts both ways.** A hand-written pipeline plus a supervisor plus reconnection plus an audio mixer plus a replay buffer is not obviously safer than one battle-tested program |

**What it brings is substantial.** [§6c](#6c-replay-is-where-the-data-advantage-is-decisive) made replay the headline differentiator — and OBS has a replay buffer, an audio mixer with a limiter and meters ([§3a](#3a-audio-and-why-it-is-smaller-than-it-looks)'s entire software list), encoder plumbing, reconnection and recording, all maintained by other people and packaged in Debian so [§9](#9-maintenance-do-not-build-a-distro)'s model holds unchanged. Every one of those is something the pipeline route has to write.

**There is also a structural argument that nothing else in this document offers.** If the appliance runs OBS internally, then the appliance and [§1a](#1a-the-thesis-more-games-watchable-within-two-hard-constraints)'s skilled-crew rung are the same engine at two levels of exposure, rather than two codebases with two sets of bugs. That is *one product to build* made literal, and it is the cleanest version of the ladder yet.

**One objection survives all of this, and it is the reason the question is not simply closed in OBS's favour.** [§7c](#7c-can-obs-run-headless-yes--but-headless-is-the-easy-half): a GUI application's error handling is a dialog box, and a modal on a screenless machine is a broadcast that has silently stopped — with obs-websocket's crash history, including a reported startup crash when a scene collection contains a browser source, which is every scene we would build. A pipeline under systemd fails by exiting, which a supervisor sees.

**So the fork is open, and narrower than "pipeline or OBS" — but it is not a contest between equals.** [§1a](#1a-the-thesis-more-games-watchable-within-two-hard-constraints) ranks operational simplicity above build effort, so OBS has to clear a bar rather than merely compete on points. Halving the work is worth a lot, and worth nothing if the box can stop silently at a field.

#### Would requiring a monitor settle it? Not quite — and the better answer is cheaper

The obvious fix for "a dialog nobody can see" is to make a screen part of the rig. It helps, and [§10c](#10c-a-monitor-on-the-box-and-which-roles-should-actually-move-to-it) wants one anyway on x86, where the HDMI output is spare because the video leaves over the network. **But visible is not the same as noticed** — a modal on a monitor nobody is looking at is still a stopped broadcast, and §10c argues that screen should be showing an *ambient dashboard* rather than an application window, so the two compete for it.

**The load-bearing thing is detection, not visibility, and it needs no monitor at all:**

- **A watchdog over obs-websocket.** Poll liveness *and* the output statistics. If a modal blocks the UI thread the socket likely stops answering; if it answers while the encode has stalled, the output stats say so. Either way it is caught, whether or not anybody is watching.
- **Supervised restart with the scene collection regenerated from `conf/` on every start.** A hang or crash then costs about ten seconds rather than the rest of the game, and comes back deterministically — which is also what makes OBS’s own persistence safe to ignore.

That pair clears [§1a](#1a-the-thesis-more-games-watchable-within-two-hard-constraints)’s bar without depending on a person looking at a screen, and it is worth having under the pipeline route too. **So a monitor is a good idea for [§10c](#10c-a-monitor-on-the-box-and-which-roles-should-actually-move-to-it)’s reasons — not the tie-breaker for this one**, and making it a hard requirement would add a per-field cost and a power draw ([§10b](#10b-running-off-a-battery)) for a job a watchdog does better.

**Which makes phase 3’s test concrete rather than a judgement call:** build the watchdog and the supervised restart, then try hard to make OBS fail — kill it, block it, pull the network, corrupt the collection — and see whether every failure is caught and recovered. If yes, it is very likely the right engine and a great deal of work disappears. If it is merely *probably* fine, that is a no, and the pipeline wins despite costing more to write. [§12](#12-what-to-do-first)'s phase 3 answers it in about a day, and it deserves to be one of the first things tried rather than a footnote.

**None of this touches the Pi**, which settles cleanly the other way: OBS has no good hardware-encode path there, and [§3b](#3b-the-other-shape-the-box-out-of-the-video-path)'s graphics source needs no engine at all. The engine question only exists on x86.

**And it does not change where OBS sits for skilled crews.** For a rig that already has a laptop, OBS with its UI *visible* remains the better scale-up than buying a switcher — it costs nothing, and it has the replay buffer and the control surface [§6a](#6a-replay-on-the-box-later-and-why-not-now) says are the blockers. That crew will run it on their own laptop rather than ours, which is why that rung needs almost nothing built: the overlay URLs, which exist, and phase 1's hub.

### 7c. Can OBS run headless? Yes — but headless is the easy half

**Running it without a monitor is a solved, documented problem**, with three routes:

- **An HDMI dummy plug**, about €8. The machine believes a display is attached, X starts normally, the GPU is used for real, and nothing needs configuring. **The pragmatic answer.**
- **The `dummy` X video driver**, via an `xorg.conf` fixing a 1920×1080 virtual screen. No hardware, slightly more setup.
- **Xvfb**, with a systemd unit — there is a working Debian recipe. Note it must be **24-bit colour** or OBS's preview pane fails, and watch that you do not fall back to software GL: compositing 1080p through `llvmpipe` would burn exactly the CPU that QuickSync was chosen to save.

**But "headless" is not the bar this box has to clear. "Unattended" is**, and they are different questions. Headless means nobody is looking; unattended means nobody is *there* — to restart it, dismiss something, or notice it stopped. The evidence on that second question is less comforting than on the first.

obs-websocket's issue history includes crashes when switching scene collections, crashes on rapid stream start/stop issued over the socket, and events that do not fire reliably — and, most pointedly for us, **a reported startup crash when a scene collection contains a browser source**, which is every scene this project would ever build. Whether any individual bug is still live matters less than the class: these are the seams of a program being driven in a way it was not primarily designed for.

**And the structural problem underneath is that a GUI application's error handling is a dialog box.** A modal dialog on a machine with no screen is a broadcast that has silently stopped, with nothing in any log and nothing on air to say so. That is this project's named failure mode — the thing `AGENTS.md` calls out as looking completely normal in a screenshot — arriving through a new door. A pipeline under systemd fails by exiting, which a supervisor can see and act on. A dialog waits forever.

**One thing this buys back, though, and it is not small.** If X is running anyway, VNC into it is a **real confidence monitor** — the actual program output, not a browser showing what the overlay ought to be. §10 lists that gap explicitly and calls it the subtle one, because the two differ in exactly the cases that matter. For §1a's top rung, where somebody skilled is running OBS anyway, this is the answer to §10's missing screen.

---

## 8. Configuration, and the privilege boundary

Configuring the box from the Studio fits the existing architecture almost exactly: another document in `conf/`, written behind `Overlays\Auth::isAdmin()` with the same optimistic locking as show state. Nothing new is needed in the storage model.

**And if a push channel is ever added ([§2](#2-why-this-is-interesting-here-and-it-is-not-mainly-the-money)), it notifies — it does not become the authority.** `AGENTS.md`'s rule is that the store decides, not the UI, and a relay that carried state instead of pointing at it would put a second source of truth on the field. Clients are told *something changed*; they still read the store.

**What must not happen is PHP starting pipelines.** The Studio writes `conf/video.json`; a small daemon on the box watches that file and reconciles the running pipeline to it. That keeps *the store the authority, not the UI* — the rule already in `AGENTS.md` — and keeps the web server on the safe side of a privilege boundary that does not exist in this project today.

**A pipeline restart is destructive in a way no show-state write is.** Changing the video source is a cut to black on a live broadcast. The house rule is *never block a click to prevent a consequence — show the consequence first*, and this is the case that rule was written for: warn on the control, do not disable it.

One real gap to note rather than solve here. `Overlays\Auth::isAdmin()` has two answers: Live!'s admin session, or a hash in `conf/` in standalone mode. An appliance on a field LAN with no Live! above it is in standalone mode, so that hash is the only thing between the field network and the encoder. **That is a thinner thing to rest a device on than it is to rest a lower-third on**, and it deserves a decision before the box gets a network port rather than after. §8a is why it gets thinner still.

### 8a. The streaming destination, and the first real secret this project would hold

The destination has to be configurable alongside the source and the overlay URL — an RTMP or SRT endpoint and a stream key. Mechanically it is one more field in `conf/video.json`. **Security-wise it is a change of kind, not of degree, and it should be treated as one.**

**Nothing this project stores today is a credential.** `AGENTS.md` is explicit that a room code *"is a namespace, not a credential"*, and the most sensitive thing in `conf/` is a commentator's notes. A stream key is different: anyone holding it can broadcast anything to the tournament's channel, under the tournament's name, and the organisation finds out from its audience. It is the first stored value where the failure is reputational and public.

Three rules follow, and they are cheap:

- **Write-only.** Set it, never send it back. The API answers *configured* or *not configured* and nothing else. This is deliberately stricter than [`shared/secret.js`](../shared/secret.js)'s room-code masking, which reveals on demand because a code has to be read aloud. A stream key never has to be read aloud, so it should never be readable.
- **Never in a log or a filename.** GStreamer will happily print a full RTMP URL — key included — at debug level, and §7a is about to start writing files named after things. Redact at the boundary rather than remembering not to log.
- **Set it before the day, not at the field.** It is an organiser's value, configured once from a laptop, not something typed into a phone keyboard beside a pitch. Which also means the natural shape is a short list of named destinations — one per channel or event — chosen per game, rather than a key re-entered each time.

**And a travelling rig makes this a hard requirement rather than a preference** ([§1a](#1a-the-thesis-more-games-watchable-within-two-hard-constraints)): a box passing through many hands must hold no secret at rest, so keys are fetched per event and never written to the disk that is moving.

**This sharpens the auth gap in §8 rather than adding a new one.** The standalone-mode password hash now stands between a field network and the tournament's broadcast credentials, not merely between it and an overlay. That is the argument for deciding the appliance's authentication story deliberately, and probably for the box's admin surface not being reachable from the crew's access point at all.

#### Two practical notes

**Verify the destination before the game, because afterwards you cannot tell the failures apart.** A wrong stream key and a dead uplink both present as "we are not live". A connect-push-disconnect test takes seconds and distinguishes them, and it is precisely the kind of item [`SETUP.md`](SETUP.md) argues earns its place on a checklist: one the software can actually verify, currently checked by nobody.

**Prefer SRT where the platform accepts it.** RTMP has no answer to packet loss; SRT retransmits. Given that [`RELAY.md`](RELAY.md)'s whole premise is an uplink that comes and goes, that difference is worth more here than at a venue with a fixed line — and where the platform only speaks RTMP, §7a's recording is the fallback that makes the loss survivable.

And the question that will be asked: **streaming to two platforms at once is technically easy and practically a bad idea.** The encode happens once and a `tee` costs nothing in CPU — but it doubles the uplink bandwidth, which is the scarce resource at a field and the one thing §10a establishes cannot be fixed locally. Send one stream; let a restream service fan it out where that is wanted.

### 8b. Most of this should not be configured at the field at all

§8 and §8a describe a box configured from the Studio, which is the fallback rather than the goal. **Almost everything a box needs is already known centrally before anyone arrives**, and the Studio's real job is to override it, not to supply it.

The lever is that **a box only has to be told which field it is.** Everything else follows, because the schedule already knows which game is on which field at what time — and this project already has the concept: `shared/field.js` is the field lookup, and `AGENTS.md` lists *"a field-following overlay silently showing the wrong game"* among the bugs it has had, which means field-following is existing behaviour rather than a new feature. A box that knows its field derives its game and therefore its overlay URL, all day, with nobody touching it between games.

#### What can come from where

| tier | examples | source |
|---|---|---|
| **Tournament-wide** | season and API base, logo, colours, destination platform, update channel | central, identical on every box |
| **Per field, centrally knowable** | which field, and therefore which game; a per-field stream destination where each field has its own event | central, keyed by field |
| **Per box, unknowable centrally** | which capture device is plugged in, audio device and levels, AP and network settings | local only — these describe *this* hardware |

That last row is the reason the Studio's device page does not disappear. It is a much smaller page than §8 implies, though: the things nobody can know remotely, plus overrides.

#### "Central" means the tournament's own install, not a service we run

This is the load-bearing decision, and it goes against the instinct to build an API. **The config should be served by the tournament's own overlays install** — a document in its `conf/`, keyed by field, read through the same `live/api`-shaped path everything else already uses.

Two reasons, and the second is the serious one. It needs **no upstream change**: `UPSTREAM.md` exists because asks against UltiOrganizer and Live! are expensive, and this ask can be avoided entirely by putting the document in a directory this project already owns. And it adds no new party to the trust boundary. §8a establishes that a stream key is the first real credential here; a service we operate would hold every tournament's keys, which is a far better target than any single box and precisely the responsibility [`RELAY.md`](RELAY.md) is trying to avoid taking on when it prefers having no data at rest. The tournament already trusts its own server with its own data. Keep it there.

Central delivery does improve the credential story rather than only relocating it: a key is never typed at a field, it can be rotated in one place, and a box that goes missing can be de-enrolled instead of hunted.

#### The two details that decide whether this is pleasant or maddening

**Enrolment, given the box has no screen.** It needs exactly one fact at first boot — a tournament enrolment token — and Raspberry Pi Imager can preload a file onto the boot partition (§9), so this costs nothing extra in the install flow. After that the box registers itself and waits to be assigned a field from the Studio. Assigning a box to a field is a click by someone who can see both.

**Cache last known good, and never block on the fetch.** A box that cannot reach the server on Saturday morning must come up with Friday's configuration and say so. This is the same degrade-rather-than-error rule the rest of the project follows when `conf/` is not writable.

**And be explicit about precedence, because this is where it will go wrong.** A local override must win, be visibly marked as overriding, and stay until it is cleared — never be silently reverted by the next poll. *"I changed it and it changed back"* is the characteristic failure of every system built this way, it is indistinguishable from a bug, and it would land during a game.

### 8c. Driving the platform from the schedule

Once the tournament's own install holds per-field configuration (§8b), it knows enough to go further: which camera setup is on each field, which destination it streams to — and, since it also holds the schedule, **what each broadcast should be called.** The end of that road is creating the platform's broadcasts programmatically rather than by hand.

**The prize is real, and it is mostly not the stream key.** A broadcast created from the schedule gets a correct title, the right division and round, and a description, on every game — where the manual version produces forty videos called "Field 3". More valuable still: a broadcast per game means a VOD per game. The usual alternative is one eight-hour stream per field per day, in which individual games are effectively unfindable, which quietly wastes the whole archive. And keys stop being long-lived secrets typed by people: they are issued per game, used once, and expire.

That last point retires most of §8a rather than adding to it — **but it replaces one credential with a considerably stronger one.**

#### The obstacles, in the order they will actually bite

**OAuth, not the API.** Creating broadcasts needs a Google account that owns the channel, and a stored refresh token. A stream key lets someone broadcast; a refresh token lets someone create, retitle and delete broadcasts on the tournament's channel, and YouTube's scopes are coarse enough that narrowing it to "create a broadcast" is not really available. §8b's rule generalises and should be applied harder: the tournament's own install holds the tournament's own credentials. We do not build a shared OAuth application, because owning one is exactly the standing responsibility §9 is written to avoid.

**Verification is probably the deciding constraint.** A public OAuth app using YouTube scopes requires Google's verification, and unverified apps are capped and shown to users with warnings. The alternative — every tournament creating its own Google Cloud project, enabling the API and configuring a consent screen — keeps the trust boundary right but is a genuinely long list of steps to hand a volunteer. Whether that list can be made short enough is the question this idea lives or dies on, and it is answerable in an afternoon by writing the instructions and watching somebody follow them.

**Quota is a hard ceiling, so do the arithmetic first.** The Data API bills per call against a daily allowance, and a game costs several calls; a large tournament running many games across many fields can plausibly exhaust a default allowance, and raising it means an audit rather than a setting. Binding many broadcasts to **one reusable stream** rather than creating a stream per game is the cheap mitigation and should be assumed.

**Create on the schedule, go live on the box.** Tournament schedules slip constantly. Auto-transitioning a broadcast to live at its scheduled time will eventually broadcast an empty field to a waiting audience. The box already knows the only fact that matters — whether frames are actually flowing — so creation is the server's job and going live is the box's signal, never the clock.

#### Where this sits

This is broadcast *management*, not overlay rendering, and it is worth saying plainly that it is a different product growing out of the side of this one. It belongs here only because the schedule does. **Build it in tiers, and stop wherever the value runs out**: per-field destinations from `conf/` with static keys (§8b, no external dependency at all); then schedule-derived titles and metadata handed to whoever is creating broadcasts by hand, which needs no Google API; and only then automated creation, if somebody is prepared to own a Google Cloud project and the verification that comes with it. The first tier delivers most of §8b's operational benefit and cannot break because a third party changed something.

---

## 9. Maintenance: do not build a distro

The obvious shape — a `pi-gen` image with a read-only root and signed A/B updates — is the wrong one, and the reason is not technical difficulty. **Shipping an image means owning the security updates of an entire operating system.** Every OpenSSL advisory becomes a release you owe to tournaments who have your card in a box in a shed. That is an unbounded, permanent obligation attached to a project whose actual content is a directory of PHP and JavaScript.

The way out is to notice that **the update problem is three problems with three different owners, and building an image is what welds them into one.**

| layer | what is in it | who should own it |
|---|---|---|
| **OS, kernel, firmware** | Raspberry Pi OS itself | Raspberry Pi, via `unattended-upgrades` |
| **Runtime stack** | PHP, GStreamer, `gstreamer1.0-wpe`, WPE WebKit | Debian's security team, *provided we depend on distro packages rather than vendoring them* |
| **This project** | `live/overlays/`, a systemd unit, a pipeline runner, `conf/` | us — and this is small |

Only the third row is ours, and **it is already a directory you unzip**. *"The directory is the installation"* and *"there is no build step"* are what make this easy rather than what an image would cost. There is prior art one level up: Live!'s own `bin/update-from-github.sh` fetches a release and unzips it over the tree. The appliance does not need a new idiom, it needs that one plus a service file.

#### The shape

**Step one is stock Raspberry Pi OS Lite (64-bit), installed with Raspberry Pi Imager.** This is the "defined third-party source" — and it is better than that, because Imager already does headless first-boot configuration: hostname, user, password, wifi, SSH keys, all set before the card is written. The §9-that-was worried about configuring a screenless box on first boot; that problem belongs to a tool that already solved it, has a GUI, and can be documented with three screenshots.

**Step two is one package, from an apt repository we host.**

```
curl -fsSL https://<host>/uo-appliance.gpg | sudo tee /etc/apt/keyrings/uo-appliance.gpg > /dev/null
echo "deb [signed-by=/etc/apt/keyrings/uo-appliance.gpg] https://<host>/apt stable main" | sudo tee /etc/apt/sources.list.d/uo-appliance.list
sudo apt update && sudo apt install uo-appliance
```

A package rather than a `curl | bash` installer, for reasons that are all operational rather than aesthetic: it is idempotent, it uninstalls, it is versioned, it declares its dependencies so `gstreamer1.0-wpe` and the rest are the distro's problem to keep patched, and — the one that matters most — **it puts all three layers behind a single `apt upgrade`.** That is the "even better way": not two update mechanisms to explain, one.

The repository is a static file tree. `reprepro` or `aptly` in CI, published to GitHub Pages or any bucket. The entire ongoing obligation is a signing key that must not be lost. Set against an image release process — build infrastructure, A/B partitions, rollback, bootloader — this is close to free. **If even that is too much for a first version, ship a `.deb` on a GitHub release and document `sudo apt install ./uo-appliance_1.0.0_arm64.deb`;** it costs nothing, and adding the repository later changes the install instructions and nothing else.

#### Automatic updates, without the failure §10 is afraid of

The tension is real: security patches should land without anyone thinking about it, and **a box that changes its own behaviour mid-tournament is the failure this cannot have.** Both are satisfiable, because they are about different packages.

- **`unattended-upgrades` on, security pocket only** — Debian's default — and **`Unattended-Upgrade::Automatic-Reboot "false"`**. Patches flow; the box never restarts itself between points.
- **Pin our own package out of it.** Functional change is deliberate, applied by a person, between tournaments. An overlay that starts behaving differently on a Saturday morning is exactly the class of surprise this project exists to avoid.
- **Surface the pending update in the Studio rather than applying it.** "Appliance has updates available" is one more machine-checkable readiness item, which is precisely the argument [`SETUP.md`](SETUP.md) makes: detect rather than nag, and check the things the software can actually check.

This also settles a conflict in §10. **Read-only root is out for now** — it fights `apt`, and its main justification was SD card corruption, which booting from a USB SSD already addresses. Read-only root is a hardening step for a mature product, not a starting position.

#### The one thing that could force a container

There is a case where this all changes, and §13 decides it rather than preference: **if the `gstreamer1.0-wpe` that Raspberry Pi OS ships cannot do transparent overlays** (§7), the alternative is vendoring a newer WPE WebKit — and the moment that is vendored, its CVEs are ours, which is the obligation this whole section exists to avoid. At that point a container is the better container for the mess: pin the pipeline stack in an OCI image, keep the host OS stock and self-updating, and accept ownership of one clearly bounded userspace instead of an operating system.

The cost of that branch is worth knowing in advance. A container needs `/dev/video*`, `/dev/dri` and probably `/dev/snd` passed through, and the userspace inside it has to stay compatible with the host's kernel and firmware — so it buys reproducibility and gives back some of the isolation that made it attractive. **Do not choose between these two now.** Phase 3's first test chooses, and it is a day's work.

**One thing this section has not absorbed: §5d.** If the all-in-one lands on x86, the OS row above becomes plain Debian rather than Raspberry Pi OS, the Imager step in "The shape" is replaced by a normal Debian install, and the apt repository has to carry both `arm64` and `amd64` — because §3b's graphics source stays on a Pi regardless (§5f). That is a packaging detail rather than a change of plan, but it doubles the build matrix and should be assumed from the first `.deb` rather than retrofitted.

---

## 10. Where the Director Mini is genuinely ahead

Not a list of objections; a list of things that have to be answered before a box goes to a tournament.

- **SD card corruption is the Pi's characteristic field failure.** Boot from a USB SSD. This is not optional for a device that gets power-cycled by unplugging it.
- **Power.** Brownouts on a Pi present as random, unattributable failures rather than as a power fault. A Pi 5 wants 5V/5A; a phone charger will not do it.
- **Heat.** Sustained capture-and-encode in a closed case in the sun throttles. A real cooler, and a thermal test at the frame rate you actually intend to run — see §13.
- **No battery**, where the Director Mini has one — recoverable, but not casually, and the details matter enough to have their own section: §10b.
- **No confidence monitor** — **partly recovered**, see §10c, though only in the all-in-one shape where an HDMI output is spare. This is the subtle one. The Pi 4's second HDMI output could drive a screen, but *the operator's only view of what is on air would otherwise be a laptop showing the overlay page* — and that shows what the overlay **should** be, not what the encoder actually sent. Those differ in exactly the cases that matter. §7c notes that the OBS rung gets this back for free through VNC; the appliance does not, and needs its own answer.

[`SETUP.md`](SETUP.md) is the other side of this. Its argument is that a checklist earns its place where the software can *verify* an item, and an appliance turns a pile of currently invisible facts — is the capture device present, is the encoder keeping up, is the board throttling, is the uplink there — into machine-checkable ones. The box makes that document more valuable, not less.

### 10a. The network at a field

**Each field is an island with its own router — and the router should not be the box.** Both reference rigs in §1b already carry one: a Fritzbox in rig A, a GL.iNet 5G router in rig B. That is the correct arrangement and it was reached independently by both.

**The box should not be the access point**, for three reasons. A €200 travel router does local LAN, wifi for the crew, and the cellular uplink in one object — that last is the part no box provides and the one §10a's opening calls the thing that actually fails. Its radio is not competing with anything, whereas §4d's cheap tier pushes RTMP to the box over wifi, so a box acting as AP would be receiving the video and serving the crew's tablets on one chip — the same self-defeating arrangement this section uses against a mesh two paragraphs down. And a router is the one component here that is genuinely commodity: replaceable at any electronics shop on a Saturday, which nothing else in the rig is.

**So the box gets an ethernet port and no networking responsibilities**, which is one fewer thing to configure (§1a's people constraint) and one fewer thing to fail.

The islands are not a limitation, they are the architecture. `AGENTS.md`: *"State that belongs to a game is stored per game"* — because a tournament runs several fields at once, and a single shared document *"lets one field silently destroy another's data and put it on the wrong scoreboard"*, which is not hypothetical, it was demonstrated twice before the possession store was keyed by game. **Fields have nothing to say to each other.**

That is the case against a Pi mesh, which is appealing enough to survive most objections:

- **It solves a coordination problem the data model deliberately does not have.** There is no cross-field state; the only thing genuinely shared across a tournament lives in UltiOrganizer and is reached over the internet, not sideways between fields.
- **The one real use case fails on arithmetic.** Sharing an uplink across several fields is the scenario that would justify it — and six fields at 6 Mbps is 36 Mbps across a multi-hop, single-radio mesh where every hop roughly halves throughput, on a band shared with several hundred spectators' phones. It does not nearly work.
- **It fails soft.** A degraded mesh is a broadcast that gets worse for reasons nobody at the field can diagnose, which is the worst failure mode available — indistinguishable from a bug in our software, at the moment it is least debuggable.

[`RELAY.md`](RELAY.md) already contains the sentence that settles this: *"no internet means no stream, whatever the overlays do"*. **A mesh does not produce an uplink**, and the uplink is the thing that actually fails.

#### But every island still has to reach the internet, and that is a per-field cost

**"Islands" is right about *state* and wrong about *infrastructure*.** Fields have nothing to say to each other — that argument stands and it is what kills the mesh. But they all have to say the same thing to the *outside*, because the composited video has to leave. That is a star, not isolation and not a mesh, and it is the one legitimate reason to network fields together.

**The number that decides it is upload.** A field needs roughly 8 Mbps up for 1080p30 with overhead; six fields need close to 50 Mbps up, sustained, for hours. Most venue connections are asymmetric and people check the download figure — a 100/10 line supports one field and will be discovered not to support four on the morning it matters.

Two arrangements, and the choice is usually made by the venue rather than by preference:

- **Cellular per field**, which is what both rigs in [§1b](#1b-two-working-rigs-for-reference) do — an LTE day pass, or a 5G router. Each island is genuinely independent, so one field's uplink failing costs only that field. The cost is a router per field plus data that recurs every event, and at a crowded venue it competes with several hundred spectators for the same cells.
- **One good venue uplink, shared by directional point-to-point links.** [§10a](#10a-the-network-at-a-field) dismissed this too quickly by lumping it in with the mesh. A 5GHz PtP bridge pair is around €120 per field, does far more than 8 Mbps at field distances, is directional rather than contending with the crowd, and — unlike cellular — is bought once instead of paid for every weekend. Where a venue has a real connection and line of sight, this is very likely the cheaper and more reliable answer after one or two events.

**Neither is a mesh**, and the distinction matters: a star with dedicated links has none of the halving-per-hop or soft-degradation problems this section raises against mesh routing.

**And this is a recurring per-field cost, which [§1a](#1a-the-thesis-more-games-watchable-within-two-hard-constraints) says is the number that counts.** Boxes are bought once; connectivity is paid for at every event, for every field, forever. Over a few seasons it plausibly exceeds the hardware — so "how does each field reach the internet" deserves to be asked before "which board", and it currently is not.

#### When bandwidth runs out, stop insisting every field is live

Sharing one uplink across several fields raises an obvious next idea: a central hub that arbitrates — throttling some fields, delaying others, reallocating as demand moves. **That is a real system and it is not the first thing to build.** It needs a controller, a policy, encoders that accept bitrate changes mid-stream, and a plan for the arbiter itself failing; and its failure mode is [§10a](#10a-the-network-at-a-field)'s worst one — a field's picture quietly degrading for reasons nobody standing at that field can see.

**But there is a much cheaper idea inside it, and this document already built the hard part.**

**Not every game has to be live.** [§7a](#7a-local-recording-which-is-nearly-free-and-worth-more-than-it-costs)'s recording already runs continuously on every box, already has the overlay burned in, and is already publishable with no post-production. So a field that cannot be live can still be **covered** — recorded now, uploaded when the field goes quiet between games or overnight, on capacity that is otherwise idle.

That reframes the constraint usefully. Bandwidth caps how many games can be **live**; it does not cap how many can be **covered**, because a recording costs roughly nothing to upload off-peak. And [§1a](#1a-the-thesis-more-games-watchable-within-two-hard-constraints)'s goal is more games — live was never the requirement, it was an assumption. For a pool game at nine in the morning the audience is mostly retrospective anyway.

It also lands exactly where [§8c](#8c-driving-the-platform-from-the-schedule) wants to be: a findable VOD per game, which that section calls the real prize.

**So the ladder is:**

- **Free today** — a per-field choice of *live* or *record and upload later*, plus a fixed bitrate per field, set in the central configuration [§8b](#8b-most-of-this-should-not-be-configured-at-the-field-at-all) already provides. A tournament gives the showcase field the bandwidth and records the rest. No coordination, no arbiter, nothing new.
- **Cheap next** — each box notices *its own* uplink struggling, drops bitrate or falls back to record-only, and says so in the Studio. Entirely local, so there is no hub to fail and no cross-field state to get wrong.
- **Not now** — central dynamic arbitration across fields. Optimises "more games *live*", which is a smaller prize than "more games covered" and costs far more to get right.

#### The crew's wifi must not become the venue's wifi

The uplink the stream depends on is the same one the crew's phones are on. **Give people general internet at a field and some of them will watch video on it**, and the stream degrades for reasons nobody connects to the person sitting nearby with a phone.

**Prefer prioritisation over prohibition.** The instinct is to block, but an allowlist means knowing every host a scorekeeper needs — and getting that wrong breaks something on a Saturday that nobody at the field can diagnose, which is this section's worst failure mode arriving by a new route. Traffic shaping cannot fail that way: give the box's traffic strict priority by MAC address and let everything else have what is left. Somebody streaming video then slows *their own* video down, which is exactly the right person to inconvenience. An OpenWrt-based travel router — a GL.iNet, as in [§1b](#1b-two-working-rigs-for-reference)'s rig B — does this out of the box.

Worth knowing who genuinely needs what, because it is less than it looks: **nobody in the crew needs open internet.** A scorekeeper needs the tournament's own host, the commentary desk and the Studio need that host and the box, and the box needs the streaming platform. An allowlist is therefore *possible* as a second layer where the venue is hostile — but it should sit behind shaping, not instead of it.

**And it is a router setting, not a box feature**, which puts it on the right side of [§1a](#1a-the-thesis-more-games-watchable-within-two-hard-constraints)'s two roles: configured once by whoever sets the rig up, never touched on a tournament morning. The one thing that does not survive contact with a field is a shared wifi password — [§8a](#8a-the-streaming-destination-and-the-first-real-secret-this-project-would-hold)'s observation about room codes applies unchanged, so assume the password spreads and let the shaping, not the secret, be what protects the stream.

What *would* earn its place is unglamorous: a fixed hostname over mDNS so a crew types `uo-box.local` rather than hunting for an IP address, and the box reporting uplink state into the Studio so that "the stream is down" is something the software says rather than something a viewer tweets. Both are readiness items in [`SETUP.md`](SETUP.md)'s sense — checkable facts that are currently invisible.

### 10b. Running off a battery

A field has no mains, so this decides whether either shape is deployable at all. **The two shapes have very different answers, which is the fourth time that split has turned out to be the real one.**

**§3b's graphics box is trivially battery-powered.** A Pi 4 draws roughly 5–7W and takes 5V over USB-C natively, so any power bank drives it with a plain cable and no negotiation to get wrong. A 20,000 mAh bank is about 74 Wh, and after conversion losses that is ten hours or more — a full tournament day from one bank.

**The all-in-one x86 box is harder, and the failure mode is nasty.** Most N100 mini PCs take 12V on a barrel jack, not USB-C. USB-C to 12V trigger cables are cheap and widely sold, but 12V was made optional in newer USB PD revisions, so many banks simply do not offer it — and when a bank cannot supply the requested voltage, the documented behaviour is to *provide the next lowest instead*. That means 9V into a 12V device: it may well boot, run, and then fail under load. §10 already warns that power problems present as random, unattributable failures, and this is the purest example of one — a box that worked on the bench and misbehaves at a tournament, with nothing in any log to say why.

Three ways out, in increasing order of how much they can be relied on:

- **Buy a mini PC with native USB-C PD input** — and be specific about which kind. These are a minority but not exotic: MeLE's Quieter 4C and MINISFORUM's S100 are examples, in the same price class as barrel-jack models rather than at a premium. The criterion that matters is not "has USB-C" but "accepts a voltage *range*" — the Quieter 4C takes 12–20V over PD, so it negotiates happily with a bank offering 15V or 20V and never depends on the optional 12V profile that trips the trigger-cable route below. That single property removes the whole failure mode. Make it a purchasing criterion: it costs nothing at the time and cannot be retrofitted.
- **Check the supply rating separately from the draw.** Such a machine may specify a 36W-plus source while actually consuming 15–20W under this workload. The wattage decides whether a bank will negotiate at all; the watt-hours decide how long it lasts. A bank that satisfies one and not the other fails in a way that looks like a fault in the box.
- **Use a bank with a real 12V PDO, or PPS.** PPS negotiates arbitrary voltages and is the dependable version of the trigger-cable trick. Verify the bank's actual profiles rather than its advertised wattage.
- **Bring a power station rather than a power bank.** This is the answer for a real rig, because the box is not the only thing that needs power: the camera, the uplink or router, and possibly an audio interface do too. A 250–500 Wh unit with an AC outlet runs everything from its own supply, with no trigger cables and no negotiation anywhere. At €150–300 it is a real cost, but it is one a tournament can share across fields and reuse for everything else.

**And note the unit confusion, because it is the commonest way to get this wrong.** "Decent wattage" is the wrong measure — output watts say how fast a bank can deliver, watt-hours say how long. A bank advertised as 100W may hold only 74 Wh. Against the all-in-one's roughly 15–20W under capture, encode and a browser, that is three to four hours, not a day: enough for a session, not for a tournament.

---

### 10c. A monitor on the box, and which roles should actually move to it

If the box has a spare HDMI output, attaching a monitor makes it a place to *display* things — and the surfaces are already HTML served over HTTP, so any of them can appear on it. The instinct that this removes devices is half right, and the half that is wrong matters.

**Where it plainly wins.** §10 calls the missing confidence monitor the subtle gap, and a screen on the box closes it — the program output, as it actually left the encoder, rather than a browser showing what the overlay ought to be.

**Four reasons it does not remove the operator's laptop or the commentators' tablets:**

- **The output is often already spoken for.** In §3b both of a Pi 4's HDMI outputs carry fill and key. There is no monitor port left. This only works for the all-in-one, where the video leaves over the network.
- **A monitor is not an interface.** The Studio and the commentary page are interactive — the second is heavily keyboard-driven, with gestures in its Keys reference. A screen means a screen *plus* keyboard and mouse, or a touchscreen. That is more objects to carry, not fewer.
- **The surfaces are distributed because the people are.** The operator is at the switcher; the commentators are at a desk with microphones, and [`COMMENTATOR.md`](COMMENTATOR.md)'s daylight rule exists because they are outdoors beside a pitch. One box with one output cannot serve people who are not sitting together, and there are usually two commentators.
- **Power.** A 24-inch monitor draws more than the box does. §10b's battery arithmetic gets materially worse, and the graphics-source shape — the one that runs all day on a power bank — is exactly the one that has no spare output anyway.

Worth adding: **a used laptop may beat box-plus-monitor-plus-keyboard for a field.** It is one object with a battery, a keyboard and a screen that folds shut, which is §5h's observation arriving from the other direction.

#### The strong form: move the ambient information, not the interactive roles

The version of this idea that survives every objection above is narrower and better. **Drive a passive dashboard from the box's spare output — program output, uplink state, encoder health, audio levels, what is on air — and leave every interactive surface on the devices people already carry.**

That needs no keyboard, no mouse and no decision about who sits where. It is simultaneously §10's confidence monitor and the readiness display [`SETUP.md`](SETUP.md) argues for, which is the thing that document says the software could check and nobody currently can. Glanceable is exactly what a field crew wants and interaction is exactly what it does not want at a box on a table.

#### And the second half of the point is the real architecture

**Because every surface is HTML over HTTP, adding one costs nothing.** That is already how this works and it is why none of the above is a lock-in: a monitor on the box, a laptop, a phone, a tablet a volunteer brought, all join by opening a URL. The right posture is not to decide where the surfaces live — it is to keep them cheap enough that it never has to be decided, and then put the one thing that genuinely belongs to the box, the ambient dashboard, on the box.

---

### 10d. Circulating a rig without it coming home

A travelling rig ([§1a](#1a-the-thesis-more-games-watchable-within-two-hard-constraints)'s third audience) gets more interesting if a rig never returns to one place: **tournament to tournament directly, carried by a team attending both.** That removes shipping cost, shipping time and the bottleneck of a single custodian, and it is only possible because the rig can be configured remotely.

**Which is nearly free where the rigs and the events share an owner.** A federation's rig points at the federation's own install permanently; that install already knows which event is next, so "reconfiguring" is somebody clicking a field assignment. No physical access, no card to reflash, no visit. Crossing organisations is the harder case — a rig lent abroad has to be re-pointed at a different server — and that wants a documented remote step rather than a new service, since [§8b](#8b-most-of-this-should-not-be-configured-at-the-field-at-all) refuses to operate one.

**It also strengthens [§8b](#8b-most-of-this-should-not-be-configured-at-the-field-at-all)'s fetch-don't-store rule into a requirement.** A rig passing through many hands is a *credential* passing through many hands, so it must hold no secrets at rest: keys fetched per event, never written to the disk that is travelling. Then a rig that goes missing is a hardware loss rather than an incident, and de-enrolling it is a click.

**What genuinely breaks is the thing a return trip was silently providing: inspection.** Two failures, and the first is worse because it lands on somebody else.

- **Completeness.** A rig is a bag of parts, and after an event things go missing. Ship it straight onward and nobody notices until a crew discovers the absent HDMI cable on a Saturday morning.
- **Wear.** Cables fray, connectors loosen, storage ages. A rig that never comes home is never looked at.

**But most of the first problem is machine-checkable, which is [`SETUP.md`](SETUP.md)'s argument applied to logistics rather than to a broadcast.** On boot the rig can enumerate what is attached and report it: capture device present, audio interface present, SSD present, numpad present — and it booted, so the power supply survived. That converts "is it complete?" from a checklist somebody does at home into a check the box performs on arrival, which is both earlier and more reliable. Wear is partly self-reporting too: storage health, thermal history, boot counts.

What it cannot see is exactly what it cannot see: **cables, mounts, cases, and anything unpowered.** Those still need eyes.

**And the real risk is not technical.** Peer-to-peer logistics fails on human follow-through — a rig sitting in a car boot for three months because no handoff was anybody's job. So the honest arrangement is a hybrid rather than either extreme: direct handoff for a run of events, with a periodic return for physical inspection, the way a rental fleet works. Someone must still own the rig and know where it is.

---

## 11. The money, stated honestly

**The number that matters is per field, not per project** (§1a). A tournament with six fields and the budget for one has not solved anything, so every figure below should be read as *what it costs to put the next field on air* — and the engineering, which dominates any single build, divides away across fields and events while the per-field cost does not.

Roughly €180–270 to build one, assuming the board has to be bought: board ~€75, PSU and cooler and SSD ~€45, capture €30–150, USB audio ~€30. Against a Director Mini at something close to an order of magnitude more. *Prices are approximate and mix currencies where a product is listed in dollars; treat them as orders of magnitude, not quotes.*

**The marginal cost of finding out is far lower than any of this**, because §12's phases 1 to 3 need nothing bought: a Pi 4, a ZowieBox and both automated camera tiers (§4d) are already owned, and §5h means even the x86 comparison can be run from a USB SSD on an existing laptop. The first purchase this plan requires comes after phase 3 has already answered whether to make it — which is the right shape for a decision §12a leaves open.

**And one line recurs rather than being bought once: connectivity.** Every field needs its own path to the internet for as long as it streams (§10a), whether that is a data plan per event or a one-off pair of directional radios. Over a few seasons it plausibly exceeds the hardware, and it is the only cost here that never stops.

**The line that actually decides the total is the transport, not the board.** §4c's network ingress needs an encoder at the camera unless the camera speaks NDI or SRT itself, and at $300–400 a ZowieBox-class device costs more than everything else combined. A €30 USB capture device with the box beside the tripod is a tenth of that and gives up the cable, power and placement advantages in §4c. Scaling to many fields makes this the dominant cost, which argues for cameras with native network output as the thing to specify when a tournament next buys any.

**§5d barely moves this, which is the point.** A complete N100 mini PC at €110–140 arrives with the PSU, cooler and SSD already in it, so it lands inside the same range while removing the encoder problem entirely — and §3b's graphics-only box is cheaper than either, because it needs no capture device, no SSD-for-recording and no audio interface at all. The interesting spread in this table is not between boards, it is between the two shapes in §3b.

**And the comparison this section makes is the wrong one anyway.** §1b names the real competitor: a MacBook running H2R Graphics, driven from a Stream Deck, which already produces good graphics today. The case for this project is not that it is cheaper — it is that nobody has to keep score twice. H2R needs an operator typing the score into it, duplicating what a scorekeeper already entered and adding a second chance to be wrong; these overlays read it from UltiOrganizer and cannot disagree with it. Any budget argument that ignores that is arguing about the wrong thing.

But the hardware is not the cost. **The engineering is weeks, and for a single rig, buying the Magewell is cheaper than building this** — by a wide margin, once anyone's time is priced at all. The economics only turn positive at scale.

**That is a statement about the decision to build, not about who the result is for**, and [§1a](#1a-the-thesis-more-games-watchable-within-two-hard-constraints) sorts the two. The scale in question is **rigs**, not fields: a federation replacing one travelling rig with three is exactly as good a justification as one tournament equipping six fields, and every one of those rigs may cover a single field. Most of the value here is scale-independent and arrives in full at one field — the build cost is the only part that needs numbers behind it, which is precisely the audience [`STANDALONE.md`](STANDALONE.md) is written for.

---

## 12. What to do first

**Five phases, numbered from zero**, ordered so that each is worth having if the next never happens and so that risk only ever increases. Phases 0 to 2 need no capture device, no encoder and no packaging, and between them they deliver most of §2's argument. Phase 3 is where money and uncertainty start, and §12a is the rule that decides whether to enter it.

**Phase 0, and cheaper than all of them: talk to people.** Two conversations, neither needing anything built.

**The DFV.** A federation already publishes a reference rig, already carries a MacBook and an ATEM, and — if its events run on UltiOrganizer — already has somebody keeping score twice at every game. Getting these overlay URLs into that document reaches more fields than the appliance would, needs nothing built, and answers §12a's unanswerable stop condition about what cameras and kit tournaments actually own. It also risks being told no, which is information worth having before phase 3 rather than after.

**Once.sport.** [§4d](#4d-automated-cameras-and-what-an-rtmp-only-source-demands)'s fixed-camera tier rests on four unpublished facts — the delay and its variance, whether audio survives processing, whether footage carries event time, and what hardware the real-time mode needs. A small company and an existing beta relationship makes that a short email rather than a research project, and the answers decide whether that tier is usable at all.

**Phase 1 — the hub, with no video at all.** A Pi on the field network running `app.php`: the overlays, the stores, the commentary desk, served locally. No capture device, no encoder, no image, no new code. It answers `RELAY.md`'s local-server question by making it moot, keeps working when the uplink comes and goes, and can be done in an afternoon with hardware already on the desk. If the video half never happens, this is still the most valuable single box this project could put on a field. It is also where a push channel would live if [§2](#2-why-this-is-interesting-here-and-it-is-not-mainly-the-money)'s WebSocket idea is ever taken up, and the natural place to prove §8b: a box that knows its field and follows the schedule to the right game is useful with no video pipeline behind it at all.

**Phase 2 — the graphics source (§3b), which is the first thing anyone can use at a tournament, and the only phase whose topology is already proven** (§1b: a laptop feeding an ATEM as a keyed layer is a rig that runs today). WPE rendering an overlay URL to HDMI out, into whatever switcher the event already has, keyed as fill-and-key or with the `/green` form that already exists. No capture device, no encoder, no frame rate problem, nothing from §4 or §5. It delivers §2's first reason — a browser that is finally known — to a real broadcast, and it is a €60 accessory rather than a €1,300 replacement, which makes it something a tournament can try without a decision.

This is also the honest place to learn whether WPE renders these pages correctly at all, on hardware, at rate, before anything depends on it.

**Phase 3 — bench the streaming pipeline, and "buy nothing" is literal.** §4c notes the ingress hardware is already owned: a ZowieBox feeds the box over NDI|HX or SRT, or presents as a plain USB device. Neither the capture question nor the transport question needs a purchase to be answered. `wpevideosrc` plus a network source plus `v4l2h264enc` on the Pi 4, at 1080p25 and again at 1080p50, streaming to a test endpoint. Three questions, in this order, because each makes the next moot if it fails: does `draw-background=0` produce real transparency; does the encoder hold frame rate for a full game's length without throttling in a closed case; does the browser layer stay smooth through a card transition. Answer all three before buying a capture device. The first also settles the packaging question in §9 — distro packages if it works, a container if a newer WPE has to be vendored.

Add the recording `tee` (§7a) here as soon as the pipeline runs at all. It is one element, it costs nothing, and it is the feature most likely to prove its worth before the box is finished — including on the day something else in this document fails.

**Phase 3 also settles §7b's engine question, and it should be tried early rather than last.** Not *pipeline or OBS as a preference* — §7b now argues OBS-as-hidden-engine is a serious candidate for the standard on x86, since it already contains the replay buffer, audio mixer and encoder plumbing the pipeline route would have to write. The question is narrow: can it be made to fail visibly and hold state deterministically when driven headless over obs-websocket? If yes, a great deal of work disappears. If no, it is a workstation wearing an appliance's clothes and the pipeline wins. One day of trying answers it, and the answer decides how much of phase 3 there is.

**Phase 4 — HDMI in, then audio, then packaging.** Only after phase 3 says yes. Audio (§3a) before packaging, because §3 is right that without it this is a graphics box rather than a broadcast, and because the clock-drift question needs a long soak test better run on a bench than at a tournament.

**Note what the ladder implies.** Phases 1 and 2 are cheap, low-risk, and useful to a tournament that owns a switcher. Phases 3 and 4 are where the money, the heat and the uncertainty live, and they buy the *absence* of a switcher. If the second half never happens, the first half is not a failed appliance project — it is a working one with a smaller scope.

### 12a. What phase 3 has to prove, and what result stops this

Whether the all-in-one gets built is undecided by design, and phase 3 is the experiment that settles it. **An experiment without a stopping rule written in advance is not an experiment**, so this section states the rule before anyone is invested in the answer.

Phases 1 and 2 are not subject to any of this. They are worth doing whatever phase 3 says.

#### Go — all five, not most of them

1. **`wpevideosrc` transparency works on the distro's own package**, with nothing vendored (§7, §9). This is first because everything else is downstream: vendoring WPE means owning a userspace's security updates, which for a product other tournaments deploy is the exact obligation §9 exists to refuse.
2. **The encoder holds frame rate for a full game, in a closed case, at field temperature** (§6, §10). Ninety minutes, not five.
3. **The browser layer is smooth through a card transition** (§6). A stuttering lower-third is an on-air defect.
4. **The re-encoded picture is not visibly worse than a switcher's browser source produces today**, judged on real high-motion footage at platform bitrates (§5g). This is the criterion most likely to be skipped and the one an audience would actually notice.
5. **It runs a full day unattended and fails *visibly*** — in the Studio, not in a log (§1a, §10). Given volunteers operate it, a box that fails silently is not a smaller success, it is a different and worse product than the one it replaces.

**Criterion 1 is engine-specific, and [§7b](#7b-the-fork-5d-opens-obs-instead-of-a-pipeline) reopened which engine that is.** On the pipeline route it is `wpevideosrc` transparency. On the OBS route the equivalent gate is different and harder: **can OBS be made to fail detectably and hold state deterministically when driven headless** — a watchdog over obs-websocket plus a supervised restart, verified by breaking it on purpose. **Whichever engine is on trial, criteria 2 to 5 apply unchanged.** Settle the engine first, because it decides what criterion 1 even means.

#### Stop — any one of these

- **WPE has to be vendored.** Trades the maintenance model for a feature; §9 says no.
- **Failures cannot be made visible without a terminal.** The operating role cannot use it, and §1a's thesis is contradicted rather than compromised.
- **The picture is worse than today.** Convenience does not buy a worse broadcast; a tournament that notices will go back to the switcher and be right to.
- **The archive half does not work** — no publishable VOD, or anchors [`POSTPRODUCTION.md`](POSTPRODUCTION.md) would reject. With stream and archive weighted equally, losing one loses half the case.

#### What would argue for building it anyway

Two things only the all-in-one can do, worth weighing against the stop conditions rather than being lost among them.

**It ingests sources nothing else will.** §4d: a hardware switcher cannot take RTMP, so where a cheap automated camera is the source, the box is not the cheaper option — it is the only one.

**It can do data-driven replay, and that replay pays for itself.** §6c: the marks already exist as data, and replay needs the video, the marks and the output in one place. §6d adds the economics — producing replays is what lets the stream stay at 30p, a recurring bandwidth saving against the cost §10a says never stops. A capability nobody else offers is worth more than a cost saving anyone can match. If phase 3 goes well, this is the feature that would justify the box — not the €1,000 saved.

#### The likely answer is neither, and that is worth planning for

The realistic outcome is **a partial go: the all-in-one works with a ZowieBox-class source and not with §4d's RTMP-only AI cameras**, which are the tier with two lossy generations, no clean master and wifi in the path. That is a real product — it just has a narrower supported-source list than the ambition, and saying so up front is better than discovering it at a tournament.

**And one stop condition that has nothing to do with phase 3.** If §11's transport cost stays where §4c leaves it — an encoder per camera position at ZowieBox prices — then a field costs €500 or more regardless of the box, and the comparison against a switcher weakens badly. That is settled by what cameras tournaments actually own, not by any measurement here, and it is worth asking a few organisers before phase 3 rather than after.

---

## 13. What would have to be true

Every claim this rests on that has not been measured. In this project's terms: untestable is a claim to justify, not a default, and none of these is untestable — they are merely untested.

**Grouped by which phase answers them**, because the order matters more than the list: several are cheap and gate expensive ones, and §12a's stopping rule depends on the phase-3 group in particular.

### Phase 0 — answerable by asking, before anything is built

- A capture device exists, at a price that keeps §11 true, emitting a format the pipeline accepts without a conversion pass. Confirm with `v4l2-ctl --list-formats-ext` on the actual device rather than from a product listing (§4a).
- N100-class hardware stays purchasable in Europe at these prices for as long as a tournament would expect a box to last, given it carries no availability guarantee of the kind Raspberry Pi publishes (§5d).
- A switcher a tournament actually owns accepts a separate key input at all; where it does not, the existing `/green` chroma form is legible enough on thin white text over a busy background to use on air (§3b).
- That switcher also offers a downstream keyer or a clean-feed tap, without which a replay carries a frozen scoreboard and the only remaining answer is to hide the score (§3c).
- A volunteer can be walked through creating a Google Cloud project and an OAuth consent screen in a length of documentation anybody will actually follow. Write the instructions and watch somebody use them; this is the constraint §8c depends on and it needs no code to test (§8c).
- The platform's daily API allowance covers a large tournament's game count, with broadcasts bound to one reusable stream (§8c).
- Someone owns a package signing key and can be trusted not to lose it. That is the entire maintenance obligation this design is trying to reduce itself to (§9).

### Phase 2 — the graphics source, and cheap to settle

- The Pi 4 can drive fill and key on its two HDMI outputs at 1080p simultaneously — two CRTCs at once is where the HVS bandwidth limits actually bite, and the key channel has to be derived from the page's alpha rather than rendered twice (§3b).
- A numpad's keycodes reach the Studio distinctly enough to bind — some pads emit the same codes as the main number row, which would collide with existing shortcuts and is worth checking with €10 before designing around it (§1c).
- A Debian install on a USB SSD boots unmodified across several different machines, with Secure Boot on. If it does not, §5h's bring-your-own-hardware option is a known-good list rather than a general capability (§5h).

### Phase 3 — the pipeline bench. The first gates everything below it

- `wpevideosrc` transparency works on the version Raspberry Pi OS actually ships (§7). Everything else is downstream of this, including whether §9 can stay distro-packaged or has to vendor a stack.
- The Pi 4's encoder sustains the target frame rate for a full game, in a closed case, at ambient field temperature (§6, §10).
- `wpevideosrc` frame pacing survives a card animation without visible judder — a stuttering lower-third is an on-air defect (§6).
- A Pi 5, if one is ever used, can encode **while** WPE is compositing — the published numbers measure encoding alone, and this box is exactly the "additional graphics application" they warn degrades it (§5a).
- QuickSync's transcoding headline survives contact with this pipeline: those figures are file-to-file transcodes, and what §5d needs is a live capture plus a WPE layer plus a limiter, sustained (§5d).
- WPE and `wpevideosrc` are as well behaved on Intel `i915`/Mesa as on the Pi's VideoCore — likely better, but assumed rather than measured, and §12's phase 3 is where it would be found out (§5d).
- OBS's failures are all *detectable* over obs-websocket — a blocked UI thread, a stalled encode, a lost output — and a supervised restart with the scene collection regenerated from `conf/` recovers each of them in seconds. Headless is proven and not the question; **detection and recovery are**, and they are tested by breaking it deliberately rather than by running it and hoping (§7b, §7c).
- The distro's `gstreamer1.0-wpe`, PHP and WPE versions are new enough to depend on rather than vendor — the premise the whole of §9 rests on (§9).
- A Pi 4 can decode NDI|HX3 and composite a WPE layer at once. Full NDI is ruled out on CPU grounds; HX is assumed to be fine because it is H.264 underneath, which is reasoning rather than measurement (§4c).
- An RTMP source re-encoded once is still good enough to put on air. This is the cheapest path and the worst quality in the document, and it is settled by watching a re-encode of real footage rather than by arguing about generations (§4d, §5g).
- RTMP over venue wifi holds a full game without stalling. If it does not, the automated-camera tier needs a wired or SRT-capable source and the cost changes (§4d).

### Phase 4 — audio, recording and packaging

- A USB audio interface and a USB capture device stay in sync across a full game, not across a five-minute test — needed only where §3a's wireless-mic-into-the-camera route is unavailable, since that one has no second clock to drift (§3a).
- The recording branch can be starved without disturbing the stream, verified by filling the disk deliberately rather than by reading the pipeline (§7a).
- The venue's *upload* capacity supports the number of fields intended — asymmetric lines are the trap, and this is asked of the venue rather than measured on a bench (§10a).
- Directional point-to-point links are workable at the field distances and sightlines real venues have. If not, cellular per field is the only option and the recurring cost stands (§10a).
- The capture path can hold 50/60p for the replay buffer while the programme encodes at 25/30p. On a Pi 4 this is where the ceiling bites; on x86 it should be unremarkable, but it is assumed rather than measured (§6d).
- The whole chain can be pinned to one regional family — 25/50 or 29.97/59.94 — from camera to platform, with the rate carried in per-installation config rather than a constant (§6e).
- A boot-time inventory can see enough of the rig to be worth trusting — capture device, audio interface, storage, numpad — with cables and mounts honestly excluded. Worth checking what actually enumerates before promising a completeness check (§1a).
- A WebSocket relay on the box is worth a second code path at all. It helps the replay trigger and nothing else measurably, so this is settled by trying the trigger over a 1s poll first and seeing whether it is actually too slow (§2).
- The processing delay can be measured well enough to set an audio delay line against it. If not, a delayed tier cannot carry on-site commentary and is limited to no commentary or remote commentary (§4d).
- Footage can be stamped with event time rather than arrival time on a delayed source, which is what lets automatic replay locate a goal at all and is cheap to get right once and expensive to retrofit (§4d, §6c).
- A fixed-camera tier's worst-case delay can be bounded well enough to hold score changes behind it. The delay does not need to be stable — only bounded — but a tier whose worst case is unbounded cannot be used with a live scoreboard at all (§4d).
- What a fixed-camera tier's processing machine costs and draws for a given camera and frame rate. RTX-class or Apple Silicon is published; the minimum is not, and an Apple Silicon mini and an RTX desktop are very different answers for both cost and power (§4d).
- Timeout and half-time durations are knowable precisely enough to pack a playlist against, given `uo_pool.timeouts` records an allowance rather than a length. If not, the assembler fits to a configured nominal and relies on pre-emption (§6c).
- Scorekeeper lag has a shape worth knowing: log the goal-entry stream against a real game's video and measure the distribution, since §6c's auto-tagging rests on the claim that a clip can be attributed to a point but not reliably to a goal (§6c).
- A second clean encode runs alongside the program one without disturbing it — the precondition for replay being usable at all, and free only where a device is already recording a clean master (§6c, §4c).
- The overlay's wall-clock knowledge is precise enough to generate post-production anchors that [`POSTPRODUCTION.md`](POSTPRODUCTION.md) would accept — its own threshold is that a fit more than twenty seconds out should refuse to render (§7a).
- The audio question has an answer at all. Without one this is a graphics box, not a broadcast (§3, §3a).

### Not settled by a bench test — market facts and decisions to take

- A USB-C-PD mini PC accepting a 12–20V range is available at the same price as a barrel-jack one when it comes time to buy — the models exist now, but availability in this class turns over quickly. Test whichever is bought under full load rather than at idle: the failure being guarded against is a silent voltage drop that boots fine and dies during a game (§10b).
- The box's admin surface can be kept off the crew's access point without making the box unmanageable at a field — the question §8a forces and §8 only raises (§8a).
- The schedule is reliable enough to drive a box unattended. Field-following is existing behaviour and has been wrong before; central provisioning makes a late-running schedule into a wrong game on air rather than an inconvenience (§8b).
