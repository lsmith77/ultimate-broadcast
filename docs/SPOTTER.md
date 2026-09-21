# The spotter

Records what happened in a game, throw by throw, from the sideline. Input is by voice, because a spotter's hands are busy and their eyes are on the field.

Upstream records only the score. This records who threw to whom, what was called, and how long the disc was live.

Why it exists and how its design was arrived at: [`MATCHCONTROL.md`](MATCHCONTROL.md) §10a. This document is how to use it.

## 1. URLs

| URL | Mode |
| --- | --- |
| `?view=spotter&game=<id>` | Live, following a game |
| `?view=spotter&mode=training` | Training, with a video as the clock |
| `/p/<id>`, `/p/` | The same two, short |

Live spotting requires `game=` and returns 400 without it. This matches the scoreboard: no password, but the event-publication boundary applies before the page runs.

Training needs no game. The clock is a video, the squad is fetched, and no game, event or store is touched. The welcome page links training mode.

The mode is in the URL and updates when you switch, so the address can be copied to somebody else.

## 2. Voice

Recognition runs in the browser using Vosk (WASM). Nothing is sent anywhere.

The vocabulary is a **decoding constraint**, not a filter applied after the fact. The engine is built with the word list and cannot return anything outside it. The full grammar is readable in the page under *the call grammar*, with a link for proposing additions.

The lexicon is English. Invented nicknames, bare shirt numbers and the sport's loanwords cannot be decoded. Say the surname, or say a number as words ("twenty three").

Because the recogniser only returns legal words, legality proves nothing. Validation comes from the sport:

- a pull cannot happen while the disc is live
- a team cannot catch its own pull
- a pass between opponents is not a pass
- possession does not change without a stated cause

Anything impossible is recorded as a **question**, not an observation, and settled at the next stoppage.

## 3. Names

Two players whose names sound alike produce a capture that attributes throws to the wrong person.

The picker lists every clash it finds: each player's aliases against the whole squad, and against the call grammar. A player called Hawk collides with `huck`.

Right-click a player (long-press on a phone) to open an editor showing the clash and offering safe alternatives as one-tap buttons. A **calling name** set here applies to this page only. It changes what the recogniser listens for and what the chips show. It does not reach the commentary desk.

Clashes are checked across the whole squad, not the current line, because the grammar contains every squad member so that a line call can name somebody not yet on.

## 4. The play clock

Two separate things are tracked:

- **live or dead** — a fact about the game
- **watched or not** — a fact about the capture

A stoppage means nothing was missed. An absence means nobody knows, and live time inside one is reported as unknown rather than counted.

This gives throws per minute of live play, and time on the field per player. Every figure carries its denominator. Time on the field measures presence, not work.

Calls that stop the disc by rule (`foul`, `travel`, `pick`, `injury`, and others) stop the clock when spoken. No second press.

## 5. Lines

One spotter covers both teams. The squad has two slots, **O** and **D**. In a live game the home team is O and the visitor is D. Which slot is on offence follows the point, not the slot.

**Point one's offence is declared** — tap the pill naming the team on offence. Nothing precedes point one to derive it from.

After that it is derived: whoever scores pulls. This includes the first point after half time, which reverses the opening pull rather than following the last goal. Say `half time` and the rest stays correct.

The field empties between points. Carrying a line over would credit playing time to players who walked off.

## 6. Mixed games

Taking the gender ratio is optional; a commentary desk may already hold it. Training defaults it on, live does not.

Declare point one's ratio and the rest follows the prescribed pattern, using the same rule as the commentary desk and the stage card.

With each player's **MMP/FMP** known, the picker groups players by matching and counts each group against the point's quota, marking a line that exceeds it.

Matchings come from one of three places, never inference:

1. the commentary desk's notes, if you have its code
2. a reference game pack
3. typed per player in the editor

## 7. Score

A tournament-supplied spotter usually replaces the scorekeeper rather than working alongside one, since the score is a subset of what they already record.

The spotter therefore shows the scorekeeping scoreboard — the same two tiles as the match control phone, where the tile is the button. A spoken goal counts as a pressed one.

Writing to the store requires the scorekeeping code. Without it the board still shows the score derived from the capture.

When the store is linked, the header shows the **game** clock, including halves and pauses. Events remain stamped with the capture clock; these are different measurements.

A coach acting as spotter should not keep the tournament's score — see §9.

## 8. Training mode

Video timestamps are the clock, so two people spotting the same footage produce captures that align exactly. This is how a capture is checked without anyone holding the correct answer: **agreement between people who spotted the same passage is the measure.**

- **Spot a few points, not a whole game.** Three or four points is a useful contribution. No bookkeeping is needed — every event carries its video time, so the capture states which passage it covers.
- **A reference game** loads in one press, with links to the tournament's roster and statistics. Squads are fetched at load rather than shipped, so a pack contains no personal data.
- **When you stop**, press *Save* and email the file. The address is next to the button. Scoring happens on the collected captures, not in the page.

## 9. Coaches

A coach is the other person who might use this, and they use it differently from a tournament spotter. The data is the same shape; who may write what is not.

### At practice

The obvious case: a coach records a scrimmage to see who threw what, with no video and no tournament behind it.

**This does not work yet.** Both routes fail, for different reasons:

- **Live mode requires `game=`** and returns 400 without it. There is no game at a practice.
- **Training mode works without a video**, but the clock is video time, so with nothing loaded every event is stamped `0:00`. The order is kept, the timing is not, and anything per-minute is meaningless.

What it needs is a third mode: a wall clock with no game and no store to write to. That is a small change and it is not made.

### At an official game, with the other team spotting too

Two coaches spotting the same game produce two independent captures. They will disagree, and the disagreements are informative rather than a defect. The rules for handling that are in [`MATCHCONTROL.md`](MATCHCONTROL.md) §10a, *Two spotters, one from each team* — the two that matter most:

- **Do not merge at write time.** Each capture stays its own; merging is a decision the reader makes, per consumer.
- **Separate the event from the attribution.** Both may agree a turnover happened at that moment and disagree about whose it was. Store one and dispute the other.

A coach must not also keep the tournament's score. They are partisan about exactly the calls a capture records, and the scoresheet is the tournament's record. The scoreboard here is already split so the board can be shown without the controls, but **nothing yet distinguishes a coach from a tournament spotter**, so that split is not enforced.

### Notes during a game

Not built. The idea is in [`MATCHCONTROL.md`](MATCHCONTROL.md) §10a, *A bookmark button, which is the cheap half of it*: a mark with a tag, optionally against a player, said in the moment and read afterwards. A coach cannot write "watch this again" now, and it is the thing they would reach for most.

Connecting a note to the footage is the harder half, and it is not specific to notes. In **training mode** it is already solved — events carry video time, so anything recorded can be seeked to. In **live mode** events carry wall time, and matching that to a camera is the subject of [`POSTPRODUCTION.md`](POSTPRODUCTION.md), which calls alignment the whole problem. A note taken live is findable in footage only as accurately as the two clocks are related, so the practical answer is to record what [`OFFLINE.md`](OFFLINE.md) §6 asks for at the pitch.

## 10. Limitations

- **Nothing reads a capture yet.** It is a file you download. The commentary desk and overlays cannot use it until a transport exists. [`MATCHCONTROL.md`](MATCHCONTROL.md) §10a lists what that would unlock.
- **No spatial data.** No field coordinates, so nothing about position or cuts. Coarse terms (`under`, `deep`, a distance, a force) are possible as vocabulary but deferred.
- **No coach mode.** A coach should see the score without writing it. The scoreboard is already split for this, but nothing yet distinguishes a coach from a tournament spotter.
- **No mid-game start.** The pull can only be declared on point one, so a spotter joining late cannot set it.
- **Unproven.** Used against footage by its author, not by other people against a live game.

## 11. The recogniser

About 40MB. Not in the repository; `spotter/get-model.sh` fetches it.

It **is** deployed, sent from the working directory so that a tagged deploy and a working-directory deploy put identical files on the server.

Serving it makes an installation a redistributor of Apache-2.0 work, so it ships with `spotter/NOTICE.md` and the licence text, and the imprint credits both components.

Without it, the page accepts typed calls and states which engine it has.
