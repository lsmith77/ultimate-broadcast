# Deploying a standalone installation

How to put these overlays on a domain of their own — a PHP host, a directory of files, and nothing else. Everything here was checked against Apache 2.4 with PHP 8.3 serving the tree `deploy.sh` actually produces, rather than reasoned about.

The hosted deployment is a different thing entirely and is covered by [`README.md`](README.md): there the overlays are a subdirectory of an UltiOrganizer installation, and the install is "make `conf/` writable".

## 1. What this deploys, and what it is not

A **standalone installation.** Every surface renders: the Studio, the stage, the scoreboard, the commentary desk, and match control. The score and clock are real, the squads are real, and the event itself can be authored in a browser — so nothing about the path is a mock.

What it is **not**:

- **It is not a full tournament system.** One pool, no bracket, no standings, and an event that cannot be corrected once the day has started. [`STANDALONE.md`](STANDALONE.md) §4 is the list.
- **It cannot read a remote Live!.** Every page builds its API URL as `<this installation>/index.php?view=live/api` and sends it with `credentials: 'same-origin'` — see `apiBase` in [`../scoreboard.php`](../scoreboard.php) and [`../stage.php`](../stage.php). Pointing an installation at somebody else's Live! is a change to that, plus CORS on their side, plus a story for the session cookie. It is a project, not a setting.

So what goes on a public domain today is a **demo that works**: anyone can open it, click through every surface, and keep score on a phone against the recorded game.

## 2. What the host needs

The short version is in [`STANDALONE.md`](STANDALONE.md) §6 and the headline is that **there is no database and no Composer**. What that leaves:

| | |
|---|---|
| **PHP** | 8.3 or 8.4, with `json`, `pcre`, `mbstring` and `filter`. All but `mbstring` are on by default |
| **Web server** | Apache with `mod_rewrite` and `AllowOverride All` |
| **Filesystem** | a `conf/` the web server can write, on a filesystem where `flock` works |
| **TLS** | needed in practice — a browser source loading an overlay over plain HTTP from an HTTPS page is blocked as mixed content |
| **Shell access** | to run the one-time bootstrap in §5 |

Shared hosting is a fine fit, and is the case this was written against.

## 3. One-time setup on the host

On **cyon**, each domain is a directory under `public_html` named after the domain, and that directory is the document root:

```
/home/<user>/public_html/ultimate-broadcast.org/
```

Which is why the standalone rules assume `RewriteBase /`. Three things to do once, in the control panel and over SSH:

1. **Set PHP to 8.3 or 8.4** for that domain. The account default may be older, and it is per-domain.
2. **Issue the certificate.** Let's Encrypt, and force HTTPS.
3. **Nothing else.** No database, no Composer, no `npm`. The directory is the installation.

## 4. Deploying

```
cp deploy.env.example deploy.env      # fill in REMOTE
./deploy.sh -n                        # dry run: exactly what would change
./deploy.sh
```

[`../deploy.sh`](../deploy.sh) is rsync over SSH and it does two things that are worth knowing about, because both are ways this could go wrong quietly.

**It replaces the `.htaccess`.** The one at the top of the project is for hosted mode: it rewrites onto UltiOrganizer's front controller with `RewriteBase /live/overlays/`, and on a site of its own that means every URL is a 404 — including `/`, because the file the server reaches for is `index.php`, which is the Studio page, which refuses to run unrouted. So that file is excluded and [`../install/standalone.htaccess`](../install/standalone.htaccess) is sent in its place, first, so that a first deployment is never briefly serving `conf/` with no rules in front of it.

**It protects the state that only exists on the server.** `conf/` holds the administrator hash, what is on air, the kit colours and the commentary desk's prepared notes about named players; `logos/` holds a team's own artwork. Both are gitignored, so neither exists locally — and `deploy.sh` uses `--delete`, which without an exclude would take the whole installation apart on every deploy. rsync does not delete excluded paths, which is what makes excluding them the protection.

The same applies to what the **host** puts in a document root and we never see: `cgi-bin/`, the `.well-known/` an ACME challenge is written into during a certificate renewal, and `error_log`. All three are excluded. The first dry run against cyon said `deleting cgi-bin/`, which is how they got onto the list; the one that would actually hurt is a renewal in flight.

## 5. First run

Over SSH, in the installation directory:

```
mkdir -p conf && chmod 775 conf
php install/make-config.php --capture=fixtures/payloads/dev
```

[`../install/make-config.php`](../install/make-config.php) prompts for an administrator password, hashes it, and writes `conf/local-config.php`. It runs under the CLI only — a copy of it reachable over HTTP would be a way to replace the credential it sets — and `install/` is denied by the rules as well.

`--capture` is the directory the payloads are served from, relative to the installation. `fixtures/payloads/dev` is the one this repository ships: two games, 84 players, both rosters and every player history. Leave it out and the pages read a live Live! instead, which only works when there is one.

It refuses a password under twelve characters, refuses to overwrite an existing config without `--force`, and checks the capture directory exists before writing anything — a path with a typo produces pages that load and then say "Loading…" for ever, which looks like a network fault and is not.

### The directory above the installation is not yours

Worth knowing because the first real deployment ran straight into it. `Overlays\Auth` decides hosted-or-standalone by looking one directory up for Live!, and hosted that directory is Live!'s own. **Standalone it belongs to the host**, and a shared-hosting account's `public_html` can have anything in it — this one had an unrelated `vendor/autoload.php`, left by something else entirely.

The old test was "is there a `vendor/autoload.php` up there", which matched it, and would have `require`d a stranger's autoloader into this process on every auth check. Now the standalone front controller says so itself (`OVERLAYS_STANDALONE`), and the fallback wants Live!'s entry point beside its autoloader before executing anything. `tests/standalone-setup.js` plants a decoy that throws if it is ever loaded, so the suite fails loudly if this comes back.

## 6. Checking it worked

In this order, because each rules out a different layer:

| Request | Expected |
|---|---|
| `/` | the Studio, listing the two games |
| `/s/702` | redirects to `/app.php?view=scoreboard&game=702` and renders the bug |
| `/c/702` | the commentary desk, with both rosters |
| `/k/702` | match control |
| `/conf/show.json` | **200** — the stage polls it as a static file |
| `/conf/notes/ANY.json` | **404** — this is the one that matters |
| `/install/make-config.php` | **404** |
| `/commentator.php` | **404** — a page is only ever reached through the front controller |

The short URLs answering with a redirect rather than rendering directly is correct: the pages read their parameters with `filter_input(INPUT_GET, ...)`, which reads the original request, so an internal rewrite would arrive with the game id invisible. `app.php` says so at length. It costs one round trip on a URL that is typed once and then lives in a browser source.

If `/` 404s, `AllowOverride` is not `All` and the `.htaccess` is being ignored — which also means `conf/` is wide open, so treat it as urgent rather than cosmetic.

## 7. Actually using it

The surfaces are for different people and are meant to be open on different devices at once. On a demo installation you are all of them.

| URL | Who | What |
|---|---|---|
| `/` | the operator | The **Studio**. Public read-only; the controls appear once you sign in |
| `/s/702/overlay` | the switcher | The **stage** — the full-frame graphics layer. This is the browser source |
| `/s/702` | the switcher | The **scoreboard** alone, if you want the bug with no stage |
| `/c/702` | commentary | The second screen. Never on air |
| `/k/702` | the scorekeeper | **Match control**, on a phone |

`702` and `703` are the two games in the shipped capture.

### Signing in

`/` shows **Log in to control** at the top right. Standalone that is this project's own login, in the same tab, and it brings you back to the Studio; the password is the one `make-config.php` asked for. Signed in, the header reads **Signed in** and the button becomes **Sign out**.

### Putting something on air

The Studio's stage panel is the control surface. Pick a game, place a card, switch it on — position is setup and the switch is the live action, so a placed card is preloaded even while off and switching it on is instant. Point a browser source at `/s/702/overlay` and leave it there.

With no `conf/show.json` at all the stage runs in **auto mode**: scoreboard only, no operator needed. That is the sensible state for a field nobody is staffing.

### Keeping score from a phone

This is the part that works for real rather than being replayed, and it is a hand-off between two people:

1. **In the Studio**, in the **Match control** bar, press **↺ New code**. The confirmation carries the code for six seconds — read it out. The field itself stays masked, because an operator's station is walked past all day.
2. **The scorekeeper** opens `/k/702` on a phone and enters that code. It is remembered on that device.
3. **Press the score.** Two large buttons, a clock, and an undo. Presses are applied on the phone first and sent afterwards, so a bar of signal never makes anyone wait — an unsent press shows as pending and is retried until it lands.
4. **Switch the board to it.** Back in the Studio, **Score from: upstream** → **match control**. Until you do, the phone shows a banner saying the scoreboard is not reading it, which is the failure worth preventing: a whole game scored carefully into a store nothing reads looks exactly like working.

The bar says **no code set** until one is nominated, because switching the source to a store nobody can write is how a scoreboard freezes on 0-0.

An administrator can skip step 1 and 2 — an admin session may always write — but then there is no hand-off, and the person keeping score can also change what is on air. The code exists so those are separate capabilities.

### Your own event, rather than the shipped one

`fixtures/payloads/dev` is a recording of a development instance — two games, real payload shapes, useful for showing the software and useless for running anything.

**In a browser**, signed in: the Studio's **Edit event** button, or `/s/event`. The event's name, the teams' names, the games and the pool's rules; save and the installation serves it immediately. That is the surface for the person this mode is actually for — somebody running a club's stream, with a laptop and no reason to have SSH into anything.

**Over SSH**, for a scripted install or an event that already exists as a file:

```
php install/make-event.php my-event.json --set-capture
```

Both write the same thing through the same code (`shared/event.php`), so the payload shape — the one thing in this project that must not exist twice — exists once. The description is stored in `conf/event.json` and the capture is derived from it, rebuilt on every save; edit in either place and the other opens on it.

**Squads are deliberately not in that file.** Nobody should type forty names into JSON, and there is already a door for them: the commentary desk exports a team's sheet, the team fills it in, and the desk imports it back. Standalone, that import also **creates** the players it does not recognise — and there is a field on the same bar to type one in directly, for whoever turns up unlisted. Hosted, neither is possible and the endpoint 404s, because a squad belongs to UltiOrganizer and an import must not invent people into somebody's tournament.

Team and game ids are yours to choose and must not change afterwards: `conf/score-<game>.json` is keyed by game id, so is every URL typed into a switcher, and a squad and its prepared notes are keyed by team and player id. Re-running with different ids silently detaches all of it.

`events/` is excluded from `deploy.sh`, like `conf/` and `logos/`, because an event authored on the server exists nowhere else.

One thing worth knowing if you edit `conf/local-config.php` by hand: it is a PHP file, so it is compiled and cached, and opcache revalidates a cached file only every couple of seconds. Edit it and refresh immediately and it looks like the edit did nothing. The editor calls `opcache_invalidate()` itself, so saving through the page takes effect at once.

### Demonstration mode, for an installation on the open internet

```
php install/make-config.php --demo --capture=fixtures/payloads/dev --force
```

It changes one thing, and it is not cosmetic. `notes.php` and `lines.php` take **unauthenticated** writes by design — the room code is a namespace rather than a credential, so a commentator can join without an operator in the loop — and on a public installation that means anyone who guesses five characters can write into the prepared notes. `--demo` closes both to anyone who is not signed in. Reads are untouched, so every surface still shows what it does, and the desk says so before anybody starts typing.

An administrator still writes normally, so the person running the demonstration can still set it up.

### The guided tour

`?demo=1` on the scoreboard or the stage plays a whole game through — hold, break, timeout, cap, halftime, a running clock — from **one real payload**, mutating copies of it in the browser. Nothing is written anywhere, so it is the one showcase that is safe to hand a stranger, and it is the only way to see a moving clock without a game in progress. The Studio links it beside each stage URL.

### What a visitor sees first

The Studio carries a short introduction, standalone only: what this is, that it is running without tournament software behind it, the three surfaces worth clicking, and a link to the repository. Hosted it does not render — whoever reached that page came through an UltiOrganizer installation and knows what they are looking at.

It carries **direct demo links** rather than instructions, filled in from the event's own game list once it loads: a game, a **mixed** game where the event has one, and the commentary desk. The mixed one is offered separately because the gender ratio and the matching bands do not appear at all in an open game, and a visitor looking at an open game would reasonably conclude they do not exist.

Dismissing it is remembered per browser, so an operator reads it once rather than every morning — and the **About** button in the header brings it back, because removing it outright left clearing site data as the only way to read it again.

### The switcher check, before anything matters

`?view=live/overlays/tests/selftest` on the switcher itself, watching the **program output** rather than a laptop. Four panels move independently — a JS timer, requestAnimationFrame, pure CSS, and a network poll — so whichever are frozen tell you which layer that device is not running. "The overlay does not update" has at least five distinct causes and this separates them.

## 8. What a visitor can do on a public installation

Worth knowing before pointing a domain at it, because the answer is not "nothing".

**They cannot change what is on air, and they cannot keep score.** Both need the administrator session, or — for the score — a five-character code that an administrator nominates and hands to a scorekeeper. With no code nominated, `score.php` refuses every write that is not an admin's ([`../score.php`](../score.php)).

**They can write to the line rooms and the prepared notes.** `lines.php` and `notes.php` are unauthenticated by design: the code is a namespace rather than a credential, so that a commentator can join a room without an administrator being in the loop — the reasoning is in [`COMMENTATOR.md`](COMMENTATOR.md). On a private tournament network that is a fair trade. On a public domain it means anybody who guesses a room code can write in it, and prepared notes are the thing in this project most worth not having strangers in. Nothing about a demo installation is harmed by it; an installation holding a real crew's notes should sit behind something.

## 9. Updating

Re-run `./deploy.sh`. `conf/` and `logos/` are untouched, so the password, what is on air and the kit colours all survive; everything else is replaced. `--delete` means a file removed from the repository is removed from the server, which is what keeps a stale page from lingering after a rename.

There is no auto-deploy from CI and that is deliberate for now: this is a demo instance being changed by hand, and a push to `main` is not a decision to change what is live.

## 10. What is deliberately not deployed

`docs/` and the Markdown, the test suite (except `tests/selftest.php`, the switcher diagnostic, which has to be loadable by the device it is diagnosing), the development fixtures that seed a database there is none of, `package.json` and `node_modules` — the project has no build step and npm is only ever the test runner. The exclude list in [`../deploy.sh`](../deploy.sh) is annotated one line at a time.

## 11. The three copies of one rule

`conf/` is closed by default and opened for exactly three files, because the stage polls those as static assets about once a second. That list exists three times — in the hosted `.htaccess`, in `install/standalone.htaccess`, and in `app.php` for PHP's built-in server, which reads no `.htaccess` at all. None can be derived from the others at runtime.

[`../tests/htaccess.mjs`](../tests/htaccess.mjs) checks that they still agree, and runs in CI as part of `npm run check`. The failure it exists for is the one this project has already had to do by hand twice: match control's `score-<game>.json` had to be added to each copy separately, and there was nothing but attention stopping one being missed. Miss one and the result is either a broken stage or the desk's notes being served — on one deployment shape only, and silently.
