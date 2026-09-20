# Changelog

What changed, release by release, for somebody following the project rather than reading its commits.

Versions are `0.MINOR.PATCH` while the project is pre-1.0. A **minor** is anything an operator, a commentator or a scorekeeper would notice; a **patch** is a fix to something already released. There is no 1.0 yet because the store formats and the URLs are still allowed to change — [`docs/RELEASES.md`](docs/RELEASES.md) says what 1.0 would have to mean.

A release is a git tag and the source archive GitHub builds from it. There is no build step, so that archive is the installation: unzip it into `live/overlays/` of a Live! install, or onto a domain of its own. [`docs/README.md`](docs/README.md) has both routes.

## Unreleased

- Stated as a principle what the code already did: **nobody is required.** The crew scale runs from nobody at the field — a camera and post-production — to as many as an event can staff, with no configuration in that range privileged over the others. Now a rule in `AGENTS.md`, a row at each end of the crew table, and a sentence on both front doors.
- **Squads now expire the way prepared notes always have.** Standalone, this project holds a team's names because nothing upstream does — and they sat on disk indefinitely while notes about the same people went after a week. Both are now forgotten seven days after their last write, configurable with `retention_days` (or `--retention-days` at install): raise it for a club tracking one team all season, `0` to keep until deleted by hand. Hosted is unchanged, because there the squad is UltiOrganizer's. [`docs/DEPLOY.md`](docs/DEPLOY.md) §5.
- The welcome message offers **keep score on a phone** alongside the game, mixed game and desk links — on a demonstration, where the code is published; elsewhere it is left out rather than pointing at a prompt a visitor cannot answer. A prepared room also survives an administrator editing it: the shipped squad is the floor, so clearing one note no longer takes that player's matching with it.
- **A demonstration installation now demonstrates itself.** Every store here takes a five-character code a crew agrees on at an event, and a visitor has no crew — so the commentary desk opened in an empty room with no FMP/MMP data, leaving a mixed game with no bands, no quota counts and no grouped picker, and match control said "ask the operator to set a code" to somebody with no operator. On a demonstration there is now one published code, `TRYME`, filled in by the surfaces that want it: the desk opens in a prepared room with matchings for both mixed squads, it is linked without an operator, and match control can actually be pressed. Gated on demo mode; a real installation is unchanged. [`docs/DEPLOY.md`](docs/DEPLOY.md) §5.
- **Overlays no longer put error text on air.** A board that cannot load its game shows nothing at all, a working board is never replaced by a message, and a board that nothing has confirmed for about two minutes hides itself rather than keep showing a score the game may have moved past. Diagnostics are turned on by an operator — from the Studio, which needs no URL editing on a switcher and expires after ten minutes, or with `?debug=1` on a laptop — and a board that is working stays silent even then. The Studio's new **Feed and diagnostics** panel reports whether game data is answering. [`docs/STUDIO.md`](docs/STUDIO.md) §11a.
- `deploy.sh --version v0.7.0` and `deploy.sh --latest` deploy a release rather than the working directory, from a temporary git worktree, so this directory is untouched and `dirty` stays honest. `--show` prints what would be sent without sending it. [`docs/DEPLOY.md`](docs/DEPLOY.md) §4.

## v0.7.0 — 2026-09-19

The first tagged release. It covers everything since the project began on 2026-08-22, so this entry describes what is *in* it rather than what changed.

### The four surfaces

- **Scoreboard** — a broadcast bug on a transparent 1920×1080 canvas: score, clock, timeouts, hold/break, a turnover count, and a statistic strip that says something true about the game or nothing at all. Point a browser source at one URL. It can follow a field rather than a game, so a fixed source keeps working when the schedule moves.
- **Studio** — a full-frame stage hosting several cards at once, and the page that decides what is on it: card placement per slot, arming before showing, automatic half-time, full-time and post-goal cards, and an export of the whole configuration. `?auto=1` runs a stage with no operator at all.
- **Commentator** — a second screen, never on air, so it can hold numbers a graphic must refuse: rosters, line sharing between desks, prepared notes, pronunciation and pronouns as declared fields, timeouts left, and a per-point view of who is on the field.
- **Match control** — the score and the clock from a phone at the pitch, applied on screen first and sent afterwards, plus possession, timeouts, an injury stoppage and the opening ratio behind a **More** panel.

### Two deployment modes

Hosted on [Live! by BULA](https://github.com/layoutd/live-by-bula), and standalone with nothing underneath — no database, no Composer, no build step. The same renderer and the same payload shape serve both, with one provider seam between them, and a fact one mode cannot know is omitted rather than sent as zero.

Standalone brings its own event authoring in a browser, squads through the commentary desk, an administrator login, and a recorded capture so every page renders with no host at all. [ultimate-broadcast.org](https://ultimate-broadcast.org) is one, on ordinary shared hosting.

### Keeping score with no signal

A whole game, or a weekend of them, on a phone with no network. `/k/<game>` installs to the home screen, the queue and the last server answer both survive being closed, and `/k/` lists every game the device is carrying with what each still owes. A goal is written as **the point it completes**, never as `+1`, which is what makes a retry, a replay after a reload or a delivery a day late safe. What cannot be delivered exports as a file. [`docs/OFFLINE.md`](docs/OFFLINE.md).

### Post-production

`?at=<seconds>&goals=<n>` draws one deterministic frame, through the same renderer a live broadcast uses rather than a second one, for adding a scoreboard to footage recorded without a switcher. [`docs/POSTPRODUCTION.md`](docs/POSTPRODUCTION.md).

### Running an installation

`deploy.sh` for a standalone site, `install/` for the host rules and the first configuration, `version.json` so a site can be asked what it is running, a brand with a per-surface tab icon, and visitor counting that reads the web server's access log and adds nothing to the software. Relicensed to **AGPL-3.0-only** with a section 7 permission scoped to combination with Live! and UltiOrganizer.

### Known limits, carried into this release

- `lines.php` has no lock around its read-modify-write, so two commentators saving different teams in the same moment can still lose one. `AGENTS.md` records it as a gap rather than a decision.
- Diagnostics are painted onto the broadcast canvas, which is useful during setup and unacceptable on air, and the page cannot tell the two apart. [`docs/STUDIO.md`](docs/STUDIO.md) §11 has the three options.
- Conflicts between two people scoring one game are detected and reported, never merged. [`docs/MATCHCONTROL.md`](docs/MATCHCONTROL.md) §0b is case by case.
- A score **kept in match control** has no route back. Hosted, the integration is the point: brackets, standings, rosters, histories and the score all come from UltiOrganizer, and a game its own Scorekeeper records is on the tournament record already. What cannot go back is a score this project kept itself — the API is six endpoints and all GET — so match control's log sits beside the record rather than in it. [`docs/UPSTREAM.md`](docs/UPSTREAM.md) is the ask.
