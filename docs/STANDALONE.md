# Standalone mode — the overlays without UltiOrganizer

These overlays are built for an UltiOrganizer installation with Live! by BULA underneath. **They also run without one.** Every surface — the Studio, the stage, the scoreboard, the commentary desk and match control — will render a game from a recorded payload with no host, no database and no network, and CI proves it on every push.

One of those is not a renderer at all. **Match control keeps the score and the clock here**, in this project's own store, which means the live half of a broadcast does not depend on a recording being current — a standalone installation replays who is playing and keeps what is happening.

[ultimate-broadcast.org](https://ultimate-broadcast.org) is a running one, if you would rather click than read.

This document is the current state: what works, how to run it, what is missing, and what the next step is. The reasoning that produced the design is in the code, next to the code.

## 1. Why it exists

A tournament scored on paper. A club streaming a friendly. A showcase game outside the event that owns the software. A federation running Live! where this particular pitch is not in it. The overlays would work in every one of those, and the only thing missing was something to answer "what is game 702".

## 2. What runs today

| | |
|---|---|
| **Reading Live!** | `shared/provider.js` is the one way any page reads the API — seven call sites across three files became one module with one error contract |
| **Reading a recording** | `Provider.recorded()` answers the same questions from a capture on disk. `Provider.fromConfig()` picks, on one setting |
| **Making a recording** | `tests/capture.mjs` drives `Provider.live()` against an instance and writes down what comes back |
| **Serving the pages** | `app.php` — a front controller with an explicit allow-list, the short URLs (`/s/702`, `/c/702`), and the `conf/` rules restated because `php -S` reads no `.htaccess` |
| **Deciding who may change what is on air** | `shared/auth.php`. One function, two answers: Live!'s session when there is a Live!, a local one against a hash in `conf/` when there is not |
| **Keeping the score and the clock** | `shared/score.php` is the store, `matchcontrol.php` the phone surface at `/k/<game>`, and `shared/score-source.js` puts the result into the payload shape every renderer already reads. Hosted this is one of two possible sources and the Studio chooses per game; standalone there is no other, so it is simply the score. See [`MATCHCONTROL.md`](MATCHCONTROL.md) §0 |
| **Scoring with no signal** | `shared/score-client.js` applies a press locally, queues it, and retries until it lands. Safe only because a goal is written as **the point it creates** rather than as `+1`, so sending it twice is not two goals |
| **Squads** | `shared/roster.php`, written from the commentary desk — typed in, or imported from the team's own sheet. **Standalone only**: hosted, a squad belongs to UltiOrganizer |
| **Signing in** | `login.php`, which 404s under a host because the host owns that door |
| **Knowing where it lives** | `shared/mode.php` — asset and endpoint URLs, rather than `/live/overlays/` written into every page |
| **Configuring an installation** | `install/make-config.php` — prompts for a password, hashes it, writes `conf/local-config.php`. CLI only |
| **Putting it on a domain** | `install/standalone.htaccess` and `deploy.sh`, both covered by [`DEPLOY.md`](DEPLOY.md) |
| **Creating an event** | `event.php` at `/s/event` — teams, games and the pool's rules, in a browser. `install/make-event.php` does the same from a shell, and `shared/event.php` is the one implementation both call |
| **Squads** | `shared/roster.php`, written from the commentary desk — typed in, or imported from the team's own sheet. **Standalone only**: hosted, a squad belongs to UltiOrganizer |
| **Demonstrating it publicly** | `'demo' => true` closes the two stores that take unauthenticated writes; `?demo=1` drives the scoreboard and the stage through a whole game from one real payload, writing nothing and needing no sign-in; and the Studio carries an introduction with direct links into it |

Sixty tests drive it over HTTP against a tree with no UltiOrganizer above it. They cover the routing, the guards, the login, squads, authoring an event, a phone that keeps scoring through an outage and catches up after, and a page rendering real payloads with no API call made at all. It is also the only place the **administrator path** is exercised at all: hosted, those tests skip without `ADMIN_PASS`; here the password is ours to set.

## 3. Running it

```
node tests/capture.mjs --game 702 --out fixtures/payloads/dev   # record a game
php install/make-config.php --capture=fixtures/payloads/dev     # configure
php -S 0.0.0.0:8080 -t . app.php                                # serve
```

`conf/local-config.php` is what the middle command writes, and it holds the settings — gitignored and denied over HTTP:

```php
<?php

return [
    'event' => 'standalone',
    'capture' => 'fixtures/payloads/dev',
    'admin_hash' => '<bcrypt hash>',
];
```

Without `capture` the pages read Live! as usual; without `admin_hash` nothing can change what is on air. `event` names the installation in the session key, so two installs on one domain cannot inherit each other's login.

Putting it on a domain rather than on a laptop is [`DEPLOY.md`](DEPLOY.md): a different `.htaccess`, an rsync script, and a list of what must survive `--delete`.

**`app.php` must be the router**, not just a file in the directory. `php -S` reads no `.htaccess`, so the rules that keep `conf/` off the network live in that router — serve the directory without it and the commentary desk's prepared notes, which are notes about named people, are served on request.

## 4. What is missing

Ordered by what would bite first.

| Gap | Consequence | Size |
|---|---|---|
| **A recorded capture still carries Live!'s logo path** | `entity=config` points into `live/conf/`, which a standalone installation does not have: one 404 per stage load, no tournament logo. Authored events set `"logo"` themselves and are fine; only recordings are affected, and a capture is evidence that must not be hand-edited | small |
| **Nothing checks a capture is current** | `tests/capture-check.mjs` proves a recording is *whole*, not that it matches today's fixture. That needs a live instance, which CI has not got | small |
| **No schedule beyond one pool** | `make-event.php` writes one pool, one series and no standings. A real tournament has brackets, and a bracket is a schedule rather than a list of games | medium |
| **One pool, no standings** | Everything sits in one series and one pool, so there is nothing to rank and no table to show | small |

**The editor is largely built, and by a smaller thing than it looked.** It turned out to split in two: what a person types once (`install/make-event.php`) and what arrives through a door that already existed (the commentary desk's roster import). Neither needed an authoring UI, because **match control already keeps the score and the clock** — so nothing had to author the part of a game that changes while it is played.

Five gaps that were on this list are now closed: the config bootstrap, the standalone `.htaccess`, shipping `tests/selftest.php` alone, the tournament logo (authored per event rather than inherited from a recording), and creating an event at all.

## 5. The next steps

**A tournament can now be run, for a value of tournament.** One pool, a handful of games, squads that arrive through the desk, a score kept on a phone. What it cannot do is a bracket, and it cannot be corrected once the day has started — those are the two entries above and they are what stands between this and a real event.

**What that took, for the record**, because it was less than §5 used to claim:

1. **A store** — `events/<name>/`, in **Live!'s payload shape**, which is the constraint everything else rests on (§7). Written by `install/make-event.php` rather than by a UI.
2. **Rosters through the CSV that already exists.** The bio round trip already sends each team a file with `Number` and `Name` columns and already refuses another team's file. Hosted, those columns are decoration. Standalone they became the source of truth — a promotion, not a new mechanism — and the desk gained a way to type a name in directly for the player who turned up unlisted.
3. **Score and clock**, which were already built. They never reach UltiOrganizer in either mode, because that API is read-only, so a score kept here is parallel to the tournament record rather than a replacement for it.

**What can wait:** accumulating totals across games within a standalone event. It is real — it is every "Tournament" number a player sheet shows — but it turns a per-game store into an event database, which is the thing this mode exists to avoid needing.

**What is deliberately not on this path.** [`RELAY.md`](RELAY.md) explores moving the state into the browsers with the server reduced to a relay, and peer-to-peer between the desks. It is a genuine direction and it is not this one: everything above assumes a small PHP server on the network, holding flat files, exactly as the architecture already does. The relay idea changes what the project *is* rather than how it is deployed, so it belongs after there is something to run, not before.

## 6. What a server needs

The point of this section is that the answer is small. Everything below was read off the code rather than assumed, and the headline is that **standalone needs no database and no Composer** — the two things that make the hosted deployment heavy.

### The floor

| | Requirement | Why |
|---|---|---|
| **PHP** | 8.3 or 8.4 | What CI lints and what the host runs. The code uses no syntax newer than typed properties and arrow functions, so a lower floor is likely and simply untested — do not claim one without testing it. |
| **Extensions** | `json`, `pcre`, `mbstring`, `filter` | The complete list of extension-dependent calls in the project is `json_encode`/`json_decode`, `preg_match`/`preg_replace`, `mb_substr`, `filter_input`, `flock` and `random_int`. All but `mbstring` are bundled and enabled by default. |
| **Composer** | **none** | Only `shared/auth.php` reaches for `vendor/autoload.php`, and only to find Live!'s `Api\ConfigManager` and `Api\SeasonAccess`. Standalone it does not reach at all: the front controller defines `OVERLAYS_STANDALONE` and the lookup returns before touching the filesystem. That is not only tidiness — the directory above a standalone installation belongs to the **host**, and a real shared-hosting document root turned out to have an unrelated `vendor/` in it ([`DEPLOY.md`](DEPLOY.md) §5). |
| **Database** | **none** | Nothing in this project opens one, in either mode. Hosted mode reaches the database only through Live!'s API over HTTP. |
| **Web server** | Apache with `mod_rewrite` and `AllowOverride All`, or nginx with the rules translated | The `.htaccess` does two jobs: routing the short URLs, and refusing HTTP access to `conf/` except for the one file the stage polls as a static asset. On nginx both become `location` blocks, and **the `conf/` denial is the one that must not be forgotten** — it is what keeps the desk's notes about named players out of a browser. |
| **Filesystem** | `conf/` writable by the web server, on a filesystem where `flock` works | `show.php`, `colors.php`, `possession.php` and `notes.php` do their read-modify-write under `flock` on a shared lock file. NFS and some container volume drivers do not implement it faithfully, and the failure is silent interleaving rather than an error. Note that **`lines.php` has no such lock** — a known gap recorded in `AGENTS.md`, not a decision, and one standalone would inherit unchanged. |
| **TLS** | Needed in practice | A browser source loading an overlay over HTTP from a page served over HTTPS is blocked as mixed content, and some switcher browsers refuse plain HTTP outright. |

### What it has to withstand

Not much, but the shape is unusual: **many small polls, no bursts, and a hard latency requirement on one file.**

| Poller | Interval | What it hits |
|---|---|---|
| Stage — what is on air | ~1s | `conf/show.json` as a **static file**, deliberately not through PHP |
| Scoreboard — the local score | ~1s | `conf/score-<game>.json`, on the same static fast channel and for the same reason: a point that reaches air a second late is the one thing a scoreboard cannot be forgiven |
| Possession, and the shared line | 2s | Two routed PHP endpoints |
| Match control — the scorekeeper's phone | 4s | A routed PHP endpoint, plus a retry of anything unsent every 3s |
| Game data | 10s | The payload provider |
| Prepared notes | 15s | A routed PHP endpoint |

One field in use is roughly **one scoreboard browser, one stage browser, one or two commentary desks and a phone**, so about 5–7 pollers per pitch. Ten pitches is at most a few hundred requests a minute, nearly all of them conditional GETs for small JSON. Any PHP host from the last decade handles this; a Raspberry Pi on the venue LAN handles this. **The requirement is not throughput, it is the ~1s file.** `conf/show.json` is served by the web server rather than by PHP precisely so that an operator's click feels instant, and anything in front of it — a proxy, a CDN, an aggressive `Cache-Control` — that adds a second of staleness is a second of a graphic staying on air after it was taken off.

### What standalone adds

- **A writable store for the authored data** — `conf/standalone/`, same permissions and the same HTTP denial as the rest of `conf/`.
- **An admin credential of its own.** The host already keeps a bcrypt hash in a PHP config file (`live/conf/LocalConfig.php`), which is exactly the shape to copy: a hash in `conf/`, never a plaintext password, never a value in the repository.
- **A front controller**, which is a single file that defines `UO_ROUTED_VIEW` and dispatches `?view=`. The ten guards stay as they are.

### Where it could run that hosted mode cannot

Worth stating, because it is most of the practical appeal: with no database and no Composer, the deployable artefact is **a directory of PHP files and a writable `conf/`**. That runs on shared hosting, on a laptop with `php -S` at a venue with no uplink, or in a container built `FROM php:8.3-apache` with one `a2enmod rewrite`. A tournament running standalone on a laptop behind the commentary desk is a realistic deployment, and it is the one that makes the mode worth building.

The offline case deserves care rather than a footnote: if the venue has no uplink, team logos, fonts and any CDN asset have to be local already. Worth auditing before promising it.

## 7. The rules that hold all of this together

Four, and they are the reason the modes have not diverged.

**One renderer, one payload shape, two providers.** Nothing above `shared/provider.js` knows which it is talking to. The local shape is Live!'s, warts included — where Live! is inconsistent, standalone is inconsistent the same way. A store that got to be tidy would be a second contract every consumer branches on, and `docs/PLAN.md` already lists the field names that have caught people out.

**Absent is not zero.** Standalone has no tournament totals, no game-by-game history, no blocks, no seeds, no standings, no spirit — there is no history to have. Those fields are *omitted*, never sent as `0`, and every consumer already does the right thing with an omission. A player sheet showing `0 G · 0 A` for somebody who has scored nine over three days is a lie told on air; one showing this game's numbers and no tournament row is true.

**Never write the URL layout into a page.** Ask `Overlays\Mode`. `/live/overlays/` was in every page's asset helper and in five endpoint URLs, and all of it broke the moment this directory was served from a document root.

**`conf/` is closed by default and opened one file at a time.** Two files are public because the stage polls them as static assets at about one second. Everything else has a PHP front door. That rule exists twice — in `.htaccess` and in `app.php`'s router — because `php -S` reads no `.htaccess`, and both copies are tested by making the request.

## 8. Recording format

A capture is a flat directory, one file per request, named after the request so a person can read it — which matters, because the first thing anyone does with a bug report is look inside. For one game that is the game list, the game's detail, the team list, both rosters, `reference`, `config`, and one `playerevents` per player: around sixty files for two 28-player squads.

`manifest.json` carries **when each game was recorded**, and it is not bookkeeping. `timer_start` is absolute unix seconds and the scoreboard computes `now - timer_start`, so a payload recorded on Saturday and replayed on Tuesday shows a game that has been running for three days. `Provider.recorded()` rebases by the age of the capture on every read, which holds a recording at the minute it was taken — a recording of the 14th minute is the 14th minute whenever it is played, and a test asserting on it cannot go stale overnight. `rebase: 'run'` lets the clock advance instead, which is what a demo wants.

Each game keeps its own instant: one directory can hold several recorded minutes apart, and replaying both from a single timestamp puts one of the clocks out by the gap without anything saying so.

**A capture is not a fixture to hand-edit.** Adjust one and it stops being evidence of what Live! sends and becomes a fake with extra steps. Variation belongs in a mutation layer over the recording, which is how `shared/demo.js` already works.

## 9. Open questions

- **The licence.** Live! by BULA is distributed under a signed Terms of Use, and these overlays were built for it. A mode that runs without it is not obviously a circumvention — it serves games Live! was never going to hold — but that is a question for the people who signed, not one to settle by shipping.
- **Where do game ids come from?** Every store here is keyed by them, so they have to be stable. Operator-assigned integers are fine and boring.
- **Does standalone become the more capable mode for mixed?** Several entries in [`UPSTREAM.md`](UPSTREAM.md) are "Live! does not record X" — possession, the first point's ratio, players per side, FMP/MMP matchings. Standalone *is* the system of record, so it could simply record them. That is an odd position for a bridge to end up in, and it makes the upstream asks stronger rather than weaker.
