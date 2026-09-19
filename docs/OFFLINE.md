# Keeping score with no signal

**Built.** A phone at a pitch keeps the score for a whole game, or a weekend of games, with no network, and sends everything when it gets back to signal.

This is the path for a club that films a game and streams nothing. There is no broadcast and no operator at a laptop — one person at the sideline with a phone, and a camera running. What that person records is the input to [`POSTPRODUCTION.md`](POSTPRODUCTION.md), which draws the scoreboard onto the footage afterwards.

The reasoning behind the surface is [`MATCHCONTROL.md`](MATCHCONTROL.md). This page is how to use it, what it guarantees, and where it stops.

## 1. What a scorekeeper does

1. **At home, with signal:** open `/k/<game>` once for each game of the day, then add it to the phone's home screen ("Add to Home Screen" on iOS, "Install app" on Android). Opening each game caches it; adding to the home screen makes it open without browser chrome.
2. **At the pitch:** tap the icon. It opens the list of games on the phone. Pick one and keep score. Every press is applied on screen at once.
3. **Back in signal:** the presses send themselves. The list shows what is still unsent.
4. **If they cannot be sent:** export the file from the list and pass it on.

No account is needed. A scorekeeper is handed a link and a five-character code.

## 2. Why it is safe to keep score offline

**A goal is written as the point it completes, never as "+1".** Sending the same point twice therefore stores one goal, so a queue can be retried, replayed after a reload, or delivered a day late without double-counting. With `+1` messages, a duplicate would add a goal that nobody could distinguish afterwards from a real one.

Everything below follows from that rule.

| | |
|---|---|
| **The page works offline** | A service worker keeps the page and its assets, so a reload with no network opens instead of failing. A reload is what people do when nothing seems to be happening |
| **The score survives being closed** | The unsent queue and the last answer from the server are both stored on the device, so a phone that synced a few points, lost signal and was reopened comes back with all of it rather than only the unsent part |
| **Several games at once** | Each game is separate. `/k/` lists them with their scores and what each has left to send |
| **The scoring screen stays two buttons** | The list, the export and the sync state sit outside it |

## 3. Handing it over

**Automatically.** Back in signal, the queue drains on its own. Opening the game sends that game; opening `/k/` sends every game on the phone, and the hint at the top says "Sending…" until it has.

**A row reports three things, not two.** What the server has (`14–12 sent`, the server's own answer rather than the phone's), what is still queued, and what was **not accepted** — a press the store refused, usually because another scorekeeper had already recorded that point. The last one matters because finding signal does not fix it: the queue empties either way, so a phone counting only its queue would call that game sent. Refused presses stay on the device and go into the export, since nothing else records that they happened.

**As a file.** *Export all*, or export one game, writes JSON from the phone with no server involved. Per game it carries the teams, the score, what has already reached a server and what has not — separately, because they are different claims. Each press still names the point it completes, so the file can be replayed into a store rather than only read.

**It does not reach UltiOrganizer.** That API is read-only — six endpoints, all GET — so a score kept here is parallel to the tournament record and does not replace it. An event that wants the result in UltiOrganizer enters it there as usual. [`UPSTREAM.md`](UPSTREAM.md) records the ask.

## 4. Limits

- **The first visit needs signal.** A game that has never been opened has nothing cached and no team names. Opening the list caches it too, so the icon works cold afterwards.
- **Only `/k/` is covered.** The long URL (`?view=…&game=702`) is outside the service worker's scope, because widening the scope would put a worker in front of the scoreboard and the stage. Tell people to add `/k/<game>`.
- **Private browsing keeps nothing.** Storage throws there instead of returning nothing. The page still works, but closing it loses whatever has not been sent.
- **A device is not a backup.** Forty games are kept, oldest dropped first, and never one with something unsent. **Remove** on a row forgets a game and its code once it has nothing left to send. Clearing site data clears all of it, and the exported file is the backup.
- **Conflicts are not merged.** If somebody else recorded the same point first, theirs stands and this phone says so. [`MATCHCONTROL.md`](MATCHCONTROL.md) §0b covers each case and the three gaps that remain.

## 5. For post-production

A recorded game plus a kept score is what the overlay needs to draw a scoreboard onto footage. The score log gives the sequence of points. It cannot give **when each point happened on the video**: many tournaments record no goal times, and a phone's clock is not the camera's.

Alignment is therefore the operator's: `?at=<seconds>&goals=<n>` draws one deterministic frame and the caller decides which goals have happened by then ([`POSTPRODUCTION.md`](POSTPRODUCTION.md)). Two things at the pitch make it cheaper:

- **Start the clock** when the game starts, even if nobody looks at it. It is the simplest anchor available.
- **Note the camera's timecode at the first pull.** One number, written anywhere, saves scrubbing later. [`SETUP.md`](SETUP.md) argues this belongs on a teardown checklist because it cannot be recovered once everyone has left.

## 6. For whoever set the installation up

- The manifest and the worker are served by the installation. Nothing to configure, no build step.
- **TLS is required.** A service worker will not register over plain HTTP, except on localhost, so an installation without a certificate has no offline mode — and nothing else breaks, so the loss is easy to miss.
- Hosted, the `/k/` short URL comes from the root `.htaccess` snippet. Without it the page is reachable only by its long URL, which is the form offline does not cover. [`../install/root-htaccess-snippet.conf`](../install/root-htaccess-snippet.conf).
- Standalone, `deploy.sh` ships both files. [`DEPLOY.md`](DEPLOY.md) has the instruction to pass on to scorekeepers.
