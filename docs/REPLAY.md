# REPLAY.md — how replay works, if it is built

**Design notes, nothing built.** [`APPLIANCE.md`](APPLIANCE.md) §6c establishes *why* replay is where this project's data advantage is decisive and what it costs the box. This is the operational half: how a clip gets marked, tagged, reviewed and played.

It is separated because it is design for a feature that has not been approved — `APPLIANCE.md` §12a has not decided whether the box is built at all — and because at ~70 lines it was unbalancing the document it came from.

**Most of it is drawn from a workflow that has actually been operated** on a Magewell Director Mini, rather than proposed here.

Section references throughout (§1a, §8b and so on) point into [`APPLIANCE.md`](APPLIANCE.md).

---

### The problem that shapes the whole design: those timestamps are entry times

A goal is recorded when the scorekeeper taps it, not when it was scored, and the lag is variable and unmeasured. A commentator's turnover mark has the same property. **An automatic clip built on an entry timestamp will be approximately right and sometimes wrong**, and a replay showing the ten seconds after the play is the video equivalent of the failure this project names as characteristic — something confidently wrong that looks completely normal.

Two consequences, and the first is the important one:

- **Never auto-*air* what the system detected on its own.** Where the *machine* picks the moment from a goal event, it proposes and a person confirms or nudges the in-point — because the timestamp it is working from has not been measured and might be seconds off. This restriction is about machine-chosen moments only. A replay a *human* triggered is a different thing entirely and should go straight to air; see the trigger mechanics below, where that distinction turns out to be the whole design.
- **Auto-air is defensible during a stoppage**, and only there. During a timeout or half time nothing live is being missed, the system already knows the state, and dead air is a real problem. Halftime playlists are the strongest version of this idea — ranked by break-versus-hold, assembled from marks already collected, needing no operator. The rule that makes it safe: the playlist stops the instant play resumes, which `stoppage.js` already knows.

### The two event streams are unsynchronised and can arrive in either order

Worse than lag, and the thing that actually shapes the design. **A replay trigger and a scorekeeper's goal entry are two independent human actions.** The operator may mark a clip before the goal is entered or well after it; the scorekeeper may be thirty seconds behind, or may enter a goal after the next point has started, or may undo and re-enter one and move its timestamp. There is no order to rely on and no fixed offset to subtract.

**The resolution is to stop trying to synchronise them: store the mark, derive the tag.**

The clip's own timestamp is not the unreliable part — it is a machine timestamp of a button press, exact. What is unreliable is the *meaning*. So capture the mark with nothing attached, and resolve which point it belongs to **on read**, from goal boundaries that have since settled. A late goal that moves a boundary silently re-tags the clips inside it, because nothing was ever written down to be wrong.

That makes arrival order irrelevant — only event time matters, and the join is computed rather than stored. **The architecture already supports this**: [`RELAY.md`](RELAY.md) notes these stores are close to CRDTs by accident, append-only logs that merge without coordination, and this is the same family as the rule in [`MATCHCONTROL.md`](MATCHCONTROL.md) that a goal is written as the point it creates rather than as `+1` — a statement of fact rather than an interpretation, safe by construction.

**Derivation alone cannot fix the live case.** At the moment the operator wants a replay on air, the score data may simply not have arrived — so a lower-third reading *BREAK, 8–6* could be wrong at air time even though the tag self-corrects an hour later. The safe default is §3c's, unchanged: a REPLAY bug and no scoreboard asserts nothing false.

### A clips list closes it, because the person looking at it watched the play

The missing source is not more data, it is **the human who is already there**. Somebody triggered that replay because they saw what happened; they know it was a break before the scorekeeper has finished typing. A clips UI showing each mark with its derived tag, to confirm or edit, is therefore a second and usually earlier source of the same fact — not a workaround for the derivation but a better input to it.

Which turns the live limit into a design with three states rather than a wall:

- **Unconfirmed** — airs with a replay bug and no claim. Safe, requires nothing, and is what happens if nobody touches it.
- **Confirmed** — a human pinned the derived value; the graphic may now assert it.
- **Edited** — an explicit override, which wins outright.

**The override rule is already written in this project**, in §8b: *a local override must win, be visibly marked as overriding, and stay until it is cleared — never silently reverted by the next poll.* A confirmed or edited tag is exactly that, and the same rule applies unchanged.

**With one case worth handling rather than suppressing.** If somebody confirms *break* and the settled data later says *hold*, that disagreement is information — most likely a scorekeeping error — and should surface rather than be resolved silently in either direction. Quietly preferring the human loses a correction; quietly preferring the data overrides somebody who watched the point. This project does not resolve that kind of conflict by picking a winner in code.

**And confirmation must stay optional, or it contradicts §1a.** The default is safe, so nothing is required; confirming *upgrades* a clip from asserting nothing to carrying a tag. Never a step the operator has to remember, always one that pays.

### Trigger mechanics, from the same operated workflow

The detail that reframes this: **a quick replay is not a mark, it is an air action.** Press the button and the last few seconds are on screen immediately. There is no capture-then-decide step, because in live sport there is no time for one.

**A delayed camera source changes this** — see `APPLIANCE.md` §4d. The operator must watch the delayed feed rather than the field, or the buffer will not contain what they just saw; and with the viewer already seconds behind, mark-then-decide becomes defensible where it is not elsewhere.

**"Immediately" is a transport requirement, and it is the only one in this project.** The overlays poll show state at about a second, which is fine for a card going on air and too slow for this. A box on the field can hold a WebSocket where the hosted deployment cannot (`APPLIANCE.md` §2), so the trigger is the one path that would justify a push channel — and the one place to measure whether a second is genuinely too slow before building one.

**Two buttons, not one configurable depth — 5 seconds and 10.** That is better than a setting, and the reason is that *the operator knows how long the play was*: a quick block is five seconds, a possession ending in a goal is ten. The depth is a property of the press, not of the configuration; config supplies the available depths, the operator picks one at the moment of pressing. Two dedicated keys are faster than any menu, and §1c's numpad has plenty of them.

**Those durations are short, and that is a constraint rather than a preference.** While a replay plays, live play continues — so the cost of a replay is measured in *live action missed*, not in buffer. Five to ten seconds is short enough to come back having lost little. It is also why §6c's playlist arithmetic works out so favourably: a sixty-second timeout holds six to ten highlights, which is a lot.

**And the undo button is the precondition for all of it, not a nicety.** Instant air is only safe because it is instantly reversible. Take the undo away and pressing the wrong key puts something wrong on air with no recourse, and the whole aggressive default becomes indefensible.

**Which is a principle worth naming, because this project has been circling it.** The house rule is *never block a click to prevent a consequence — show the consequence first*. For a time-critical live control, there is a better answer still: make it undoable rather than confirmable. A confirmation dialog costs exactly the moment you are trying to catch. Reversibility buys the same safety and costs nothing.

**Buffering follows directly.** A 5-second jump-back has to be instant, which means an in-memory ring rather than a seek into disk segments — while the playlist and archive paths want the long segmented recording from §7a. Two mechanisms, and the operated workflow confirms both are needed.

### Trim and extend: mostly already solved, and the rest belongs in the clips list

The same device offers a UI to trim or extend a replay, and it is reportedly where the complexity starts. Worth noticing that **the two-depth design has already done most of that job**: choosing 5 or 10 at press time is a coarse trim performed in advance, with no interface at all. And undo covers the other common case — wrong depth, undo, press the other key. That is a re-do at a different length in two keypresses.

So the conclusion is a scoping one: **no trim UI in the live path.** Depth buttons plus undo handle it, and anything more elaborate costs the seconds it exists to save.

Trimming earns its place only in the clips list, where there is time and where the in-point matters more because the clip persists into a playlist or an archive. That is the same surface §6c already has, and it is the same argument: **the review UI is for fixing things later, never for doing things now.**

### The tagging model, from a workflow that has actually been operated

This is not a proposal. It is a flow run on a Director Mini: **one button marks a quick replay, then further shortcuts tag the mark that was just made.** Two things in that are better than what the sections above worked out.

**The human is not merely confirming the machine's work.** The derivation and the person supply different dimensions, not competing versions of one:

| dimension | source | example |
|---|---|---|
| **context** | derived, free | which point, which teams, hold or break |
| **type** | mostly human | goal, D, throwaway, catch |
| **rating** | human only, always | how good was it |

**Quality cannot be derived, ever, by anything** — and it is the field that makes automatic playlists possible. So the human is not checking the machine's work; they are supplying the half the machine has no access to. *(Type partly overlaps: a goal and a throwaway are both already in the data, since `possession.js` tracks turnovers. A layout catch is not.)*

**And "tag the last one" removes the selection problem**, which is the expensive part of any clips UI. There is nothing to find — the target is implicit, it is the mark just made. Two or three keys, all muscle memory, and §1c's €10 numpad is exactly the right instrument for it. That works during live play, where a list does not.

So there are three input points, each optional and each adding more than the last: **air it** (a depth button — which is also what creates the mark), **tag** (a key or two, immediately after), **review** (the clips list, later). Nothing is required; each step pays.

Note what that means for the numpad in §1c: two depth keys, an undo, a handful of type keys and a rating — **around eight keys, which is a numpad exactly.** The control surface for this entire feature costs €10 and needs no new code beyond keyboard bindings and their row in the Keys reference.

### Assembling a playlist to fit the stoppage

Knowing timeout and half-time durations turns filler into a **bin-packing problem with a deadline**, and three rules fall out of it that are not obvious:

- **Order best-first, not chronologically.** Play resumes when it resumes, not when the clock says — §6c's rule is that the playlist stops instantly. So the tail is what gets lost, which means the tail must be the least valuable thing. Chronological ordering throws away the wrong end.
- **Under-fill rather than over-fill.** Running out early cuts cleanly back to the field; being cut off mid-highlight looks like a fault. Fit conservatively.
- **Alternate teams in pairs.** Alternation is a fairness requirement, and it fights best-first: if the playlist is cut short on an odd boundary one team got more airtime. Pairing bounds the imbalance at one clip.

For a short stoppage, restricting to the current point — as the operated workflow does — is the right default: it is topical, and it is the set most likely to be worth seeing again.

### One surface, three uses

Worth noticing before it gets built three times: the clips list is also **the halftime playlist editor** — same marks, same tags, the action is *include* rather than *air now* — and it is also the post-production anchor tool, since §7a wants exactly a set of confirmed timestamps and [`POSTPRODUCTION.md`](POSTPRODUCTION.md) is built around having them.

**Scope caution: this is an application, not a panel.** A list with scrubbing, in-point adjustment and editable tags is the largest single piece of work proposed anywhere in §6c, and larger than the pipeline changes around it. Its saving grace is that it collapses three things that would otherwise be built separately.

**But it is not the first thing to build**, and the operated workflow above says why: mark-and-tag on a numpad is most of the value at a fraction of the cost, and it works live where a list does not. Build the marks, the tag keys and the playlist assembler first; the review UI is what you add when somebody wants to fix a tag, not what makes the feature work.

And the split that remains is favourable either way:

- **Live** — assert nothing unless a human confirmed it.
- **Later** — everything works. By half time the goals have settled, the tags are reliable, and ranking by break-versus-hold is sound. The halftime playlist is barely touched by the synchronisation problem at all, because time is exactly what it has.

**One subtlety the derivation does not handle by itself.** Not every marked clip is *of* a goal — an operator will mark a good defensive play mid-point, or a catch that led nowhere. Attaching those to whichever goal came next would be wrong. A clip is *within* a point always, but only *of* the goal that ended it sometimes, and proximity to the point's end is the only signal available. Where that is ambiguous, propose nothing and leave it unconfirmed — an untagged highlight is useful; a mislabelled one is the failure this project is named for. The clips list above is where that ambiguity gets resolved by somebody who knows, which is the cheapest place for it to be resolved.

### The commentators need a monitor, and it should not be hardware

Correct, and it is the same gap §10 calls the subtle one. But the answer is cheaper than a screen: **put the clip on the commentary page.** [`COMMENTATOR.md`](COMMENTATOR.md)'s second screen is already the surface those people are looking at, it is already a browser, and §7a's segments are already on the box's disk — extracting fifteen seconds is a remux and serving it is an HTTP range request. No new hardware, and it lands on the device they are already holding.

---

## What this costs to build

The marks, the tag keys and the playlist assembler are small. **The review surface is not** — a list with scrubbing, in-point adjustment and editable tags is an application, and the largest single piece of work `APPLIANCE.md` proposes anywhere.

Its saving grace is that it collapses three things that would otherwise be built separately: the clips list, the halftime playlist editor, and the post-production anchor tool [`POSTPRODUCTION.md`](POSTPRODUCTION.md) wants.

**But it is not the first thing to build.** Mark-and-tag on a numpad is most of the value at a fraction of the cost, and it works live where a list does not.
