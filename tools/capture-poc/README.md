# Phase 0 — does real commentary carry the events?

The cheapest experiment in the detailed-statistics direction ([`../../docs/MATCHCONTROL.md`](../../docs/MATCHCONTROL.md) §10a), and the one most likely to end it. **No AI, no model, no download.** A person listens to twenty minutes of real commentary and writes down what was said; this reports what that implies.

If natural commentary does not contain the events, no recogniser rescues it — and that is worth knowing before anybody evaluates a model.

## What it answers

| | why it decides something |
|---|---|
| **Capturable events per minute** | the ceiling on everything downstream. Sparse commentary means sparse statistics however good the recognition |
| **How often a player is named at all** | an event with no actor is a count, not a statistic. "Great block!" attributes nothing |
| **Which name form is used** | surname, first name, nickname or shirt number. The recogniser's vocabulary has to be the one people actually say, and the desk already collects nicknames and pronunciations that could seed it |
| **How often that form is ambiguous** | two Webers on the field makes "Weber" unresolvable. Measured against the real roster rather than assumed |
| **Which jargon actually appears** | §10a assumes a closed verb set of English loanwords. This is where that assumption is checked against a real broadcast |

## The spotter moved

It lives at [`../../spotter.php`](../../spotter.php) now, served as `?view=spotter` or `/p/<game>`, because the experiment answered its question: a person can keep up, the grammar holds, and what is left is pacing and rule tuning rather than whether the idea works.

What remains here is phase 0 — the commentary measurement that decided the direction, which is a record of how the question was settled rather than a tool anybody still runs.

The recogniser model is fetched by [`../../spotter/get-model.sh`](../../spotter/get-model.sh) and is not in a release: about 40MB of acoustic model does not belong in an archive, and without it the surface runs on typed calls and says which engine it has.
