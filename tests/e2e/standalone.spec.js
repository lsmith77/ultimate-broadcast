// @ts-check
/**
 * The standalone front controller, exercised against PHP's built-in server.
 *
 * This is the file where getting it wrong exposes `conf/notes/` — the
 * commentary desk's prepared notes, which are notes about named people. So it
 * is tested by making the requests rather than by reading the code, and it is
 * tested against `php -S` specifically, because that server **does not read
 * `.htaccess`** and `docs/STANDALONE.md` §6 recommends it as a deployment.
 *
 * That combination was a real hole: before `app.php` grew its router block, a
 * `php -S` deployment served `conf/notes/<room>.json` to anyone who asked. The
 * tests below are the ones that would have caught it.
 *
 * Not part of `npm test`, which drives a hosted instance. Run with:
 *   npx playwright test --config tests/playwright.standalone.config.js
 */
const { test } = require('@playwright/test');
const { expect } = require('./helpers');

const BASE = process.env.STANDALONE_URL || 'http://127.0.0.1:8099';

/** Status of a plain GET, without following redirects. */
async function status(request, path) {
  const res = await request.get(`${BASE}${path}`, { maxRedirects: 0 });
  return res.status();
}

test.describe('operator state is default closed', () => {
  test('prepared notes are never served over HTTP', async ({ request }) => {
    // The one that matters. These are notes about named players.
    expect(await status(request, '/conf/notes/DDDDD.json')).toBe(404);
    expect(await status(request, '/conf/notes/')).toBe(404);
  });

  test('only the three files the stage polls are public', async ({ request }) => {
    // show.json, possession-<game>.json and score-<game>.json are served as
    // static assets on purpose: at a one-second poll, routing them through PHP
    // would be a bootstrap per second per stage. Everything else has a PHP
    // front door.
    expect(await status(request, '/conf/show.json')).toBe(200);
    expect(await status(request, '/conf/team-colors.json')).toBe(404);
    expect(await status(request, '/conf/lines/DDDDD.json')).toBe(404);
    // A squad is read through roster.php, not as a file. It is not secret, but
    // conf/ is closed by default and a new file dropped in there must not
    // inherit an exemption by looking similar to one.
    expect(await status(request, '/conf/roster-300.json')).toBe(404);
    // The authored event description. Not secret either, but conf/ is closed by
    // default and every file that lands in it inherits that rather than an
    // exemption.
    expect(await status(request, '/conf/event.json')).toBe(404);
  });

  test('a name that merely starts like a public file is not public', async ({ request }) => {
    // The rule anchors on the full name. "show.json.bak" or a nested path that
    // ends in show.json must not inherit its exemption.
    expect(await status(request, '/conf/show.json.bak')).toBe(404);
    expect(await status(request, '/conf/notes/show.json')).toBe(404);
  });
});

test.describe('the view allow-list', () => {
  test('the pages it names are served', async ({ request }) => {
    for (const view of ['index', 'stage', 'commentator']) {
      expect(await status(request, `/app.php?view=${view}`), view).toBe(200);
    }
    // The scoreboard needs a game or a field, and says so with a 400 rather
    // than drawing an empty bug. Asserted with the parameter AND without, so
    // this stays a test of routing rather than of that rule.
    expect(await status(request, '/app.php?view=scoreboard&game=702')).toBe(200);
    expect(await status(request, '/app.php?view=scoreboard')).toBe(400);
  });

  test('the short URLs a switcher would be typing', async ({ request }) => {
    // Not a test convenience: these exist because typing
    // index.php?view=live/overlays/scoreboard&game=702 on a switcher's
    // on-screen keyboard is the problem they were introduced to solve, and a
    // standalone installation has it too. Apache rewrites them internally;
    // here they redirect, because filter_input reads the original request.
    const cases = [
      ['/c/702', 'commentator'], ['/s/702', 'scoreboard'], ['/s/702/green', 'scoreboard'],
      ['/s/702/overlay', 'stage'], ['/s/stage', 'stage'], ['/s/', 'index'],
    ];
    for (const [url, view] of cases) {
      const res = await request.get(url, { maxRedirects: 0 });
      expect(res.status(), url).toBe(302);
      expect(res.headers().location, url).toContain(`view=${view}`);
    }
  });

  test('match control is the one short URL that does NOT redirect', async ({ request }) => {
    /**
     * A deliberate exception, and the reason is a service worker's scope.
     *
     * A scope is a path. Redirecting `/k/702` to `/app.php?view=matchcontrol`
     * puts the phone on the same path as the scoreboard and the stage, so a
     * worker covering match control would necessarily cover the surfaces that
     * go on air — which this project will not do. Served in place, the phone
     * stays under `/k/` and the worker's scope excludes everything else.
     *
     * The cost is one page reading `$_GET` instead of `filter_input`, stated at
     * that call site in `matchcontrol.php`.
     */
    for (const url of ['/k/702', '/k/']) {
      const res = await request.get(url, { maxRedirects: 0 });
      expect(res.status(), url).toBe(200);
      expect(await res.text(), url).toContain('Match control');
    }
  });

  test('a short URL that matches nothing is not the picker', async ({ request }) => {
    // It fell through to the dispatcher, which defaults to index — so /s/999x
    // answered 200 with a page that had nothing to do with what was asked.
    // `/k/` is not here: it is the games a phone is carrying, which is where a
    // home screen icon lands. `/k/abc` is still nothing.
    for (const url of ['/s/999x', '/s/702/notacolour', '/c/abc', '/k/abc']) {
      expect(await status(request, url), url).toBe(404);
    }
  });

  test('extra parameters survive a short URL, as [QSA] does', async ({ request }) => {
    const res = await request.get('/s/702?debug=1', { maxRedirects: 0 });
    expect(res.headers().location).toMatch(/debug=1/);
  });

  test('a URL copied from a hosted installation still works', async ({ request }) => {
    expect(await status(request, '/app.php?view=live/overlays/stage')).toBe(200);
  });

  test('no view means the picker, not an error', async ({ request }) => {
    expect(await status(request, '/app.php')).toBe(200);
  });

  test('anything not on the list is a 404, traversal included', async ({ request }) => {
    // There is no path resolution to defend: the request never becomes part of
    // a filename. These assert that, rather than asserting a filter works.
    const denied = [
      '../../../etc/passwd',
      '../conf/LocalConfig',
      'shared/auth',
      'conf/show',
      'app',
      'nope',
      '%2e%2e%2fconf%2fnotes',
    ];
    for (const view of denied) {
      expect(await status(request, `/app.php?view=${encodeURIComponent(view)}`), view).toBe(404);
    }
  });
});

test.describe('the pages keep their own guard', () => {
  test('a direct request to a page file is refused', async ({ request }) => {
    // UO_ROUTED_VIEW is undefined on a direct hit, so each page 404s itself.
    // The router refuses .php as well, so this is two locks rather than one.
    for (const file of ['/commentator.php', '/stage.php', '/show.php', '/possession.php']) {
      expect(await status(request, file), file).toBe(404);
    }
  });

  test('shared PHP is not reachable either', async ({ request }) => {
    expect(await status(request, '/shared/auth.php')).toBe(404);
    expect(await status(request, '/shared/show.php')).toBe(404);
  });

  test('but static assets still are, or every page breaks', async ({ request }) => {
    expect(await status(request, '/shared/provider.js')).toBe(200);
    expect(await status(request, '/shared/overlay-base.css')).toBe(200);
  });
});

test.describe('a page renders from a capture, with no Live! at all', () => {
  const { hasCapture } = require('../standalone-setup.js');
  test.skip(!hasCapture(), 'no capture in fixtures/payloads/dev — run tests/capture.mjs');

  test('the commentator page shows the recorded teams and score', async ({ page }) => {
    // The whole point of milestone 2, asserted end to end: real payloads, no
    // UltiOrganizer, no database, no network. If this passes, the browser suite
    // can in principle run here too.
    await page.goto('/app.php?view=commentator&game=702');
    await expect(page.locator('.roster').first()).toBeVisible();

    // Names off the recording, not a placeholder.
    await expect(page.locator('#headHome .nm')).not.toHaveText('—');
    await expect(page.locator('#headAway .nm')).not.toHaveText('—');
    await expect(page.locator('#score')).toHaveText(/^\d+ – \d+$/);

    // A roster with people in it, which is the part that needs entity=teams.
    expect(await page.locator('.roster tbody tr').count()).toBeGreaterThan(5);
  });

  test('the scoreboard draws the recorded game', async ({ page }) => {
    // The scoreboard is the one overlay that polls through OverlayDataClient
    // rather than calling the provider directly, and it was therefore the one
    // page still looking for an API that is not there — it drew "Unparseable
    // response (HTTP 404)" over the canvas while every other page was fine.
    const errors = [];
    page.on('response', (r) => { if (r.status() >= 400) errors.push(r.url()); });

    await page.goto('/app.php?view=scoreboard&game=702');
    await expect(page.locator('#homeName, .team .name').first()).toBeVisible();

    const text = await page.locator('body').innerText();
    expect(text, 'the recorded teams').toMatch(/MOSQUITOS|LEAMINGTON/i);
    expect(text, 'no diagnostics on the canvas').not.toMatch(/Unparseable|No game data|Invalid ID/i);
    expect(errors.filter((u) => u.includes('view=live/api')), 'no API calls').toEqual([]);
  });

  test('every page loads without reaching for an API that is not there', async ({ page }) => {
    for (const url of ['/app.php?view=index', '/app.php?view=stage',
      '/app.php?view=commentator&game=702', '/app.php?view=scoreboard&game=702']) {
      const api = [];
      page.removeAllListeners('response');
      page.on('response', (r) => { if (r.url().includes('view=live/api')) api.push(r.url()); });
      await page.goto(url);
      await page.waitForLoadState('networkidle');
      expect(api, url).toEqual([]);
    }
  });

  test('the stage draws its scoreboard card, not an empty frame', async ({ page }) => {
    // The card is an iframe pointed at the scoreboard as a PAGE, and its URL
    // used to be built by rewriting the API URL — which produces the hosted
    // spelling, `/index.php?view=live/overlays/scoreboard`. Standalone that is
    // UltiOrganizer's front controller and it is not there, so the stage
    // mounted a frame around a 404 and showed nothing.
    //
    // Nothing failed loudly: the stage is built to degrade quietly so that one
    // bad card cannot take a broadcast down. It took looking at the DOM.
    await page.goto('/app.php?view=stage&game=702');

    const frame = page.frameLocator('iframe').first();
    await expect(frame.locator('#homeScore'), 'the card loaded')
      .toBeVisible({ timeout: 15000 });
    await expect(frame.locator('#homeScore')).toHaveText('8');
  });

  test('the clock reads the minute the capture was taken', async ({ page }) => {
    // The rebase, through the whole stack rather than in a unit test. A
    // recording of the 14th minute must not report three days.
    await page.goto('/app.php?view=scoreboard&game=702');
    const clock = page.locator('#clock, .clock').first();
    if (await clock.count()) {
      const text = (await clock.textContent()) || '';
      // Whatever it says, it must not be an hours-long figure.
      expect(text, 'a rebased clock is minutes, not days').not.toMatch(/\d{3,}:/);
    }
  });
});

test.describe('the standalone login', () => {
  const { ADMIN_PASSWORD } = require('../standalone-setup.js');

  /** Sign in through the page a person would use, and keep the cookies. */
  async function signIn(page, password) {
    await page.goto('/app.php?view=login');
    await page.locator('#password').fill(password);
    await page.locator('button[type=submit]').click();
    await page.waitForLoadState('networkidle');
  }

  test('the Studio points a read-only visitor at a login that exists', async ({ page }) => {
    // The one affordance somebody arriving read-only is given. It was hardcoded
    // to Live!'s admin page, which standalone is a 404 — so the only thing the
    // Studio told a visitor to do was the one thing that could not work. Found
    // on the first real deployment, not by a test, which is why this is here.
    await page.goto('/app.php?view=index');
    const link = page.locator('#authAction a');
    await expect(link).toHaveText('Log in to control');

    await link.click();
    await expect(page.locator('#password'), 'and it is the login').toBeVisible();
  });

  test('a stranger\'s vendor/ next door is not a host', async ({ request }) => {
    // The bug this encodes was found by the first real deployment. Hosted mode
    // is detected by looking one directory up for Live!'s autoloader — and the
    // document root's parent on shared hosting is the HOST's, not ours. It had
    // an unrelated vendor/autoload.php in it, which the old check matched.
    //
    // Two things would then have gone wrong, and this asserts both are not:
    // a stranger's autoloader executed inside every auth check (the decoy in
    // standalone-setup.js throws, so that shows up as a 500), and login.php
    // 404ing because the installation believed it had a host to log in through.
    const response = await request.get('/app.php?view=login');
    expect(response.status(), 'the login is served, so this is not hosted').toBe(200);
    expect(await response.text()).toContain('id="password"');
  });

  test('the right password opens the door, and the wrong one does not', async ({ page }) => {
    // Auth::attempt() is the one piece of security code this project owns
    // rather than borrows from Live!, and hosted it can never be exercised —
    // those tests skip without ADMIN_PASS. Here the password is ours.
    await signIn(page, 'not the password');
    await expect(page.locator('.msg.bad')).toContainText('was not accepted');

    await signIn(page, ADMIN_PASSWORD);
    await expect(page.locator('.msg.good')).toContainText('Logged in');
  });

  test('a session earned at the login is honoured by the endpoints', async ({ page }) => {
    // The whole point: signing in must actually let this browser change what is
    // on air. A session key that varied between requests would pass the test
    // above and fail this one.
    await signIn(page, ADMIN_PASSWORD);

    const before = await page.request.get('/app.php?view=show');
    expect((await before.json()).admin).toBe(true);

    const res = await page.request.post('/app.php?view=show', {
      data: { rev: 0, cards: [] },
    });
    expect(res.status(), 'an admin may write').not.toBe(403);
  });

  test('the session survives arriving by a different URL', async ({ page }) => {
    // The key is derived from where this installation lives, not from
    // SCRIPT_NAME — which the built-in server reports differently for `/` and
    // for `/app.php`, and which would therefore drop the login on one of them.
    await signIn(page, ADMIN_PASSWORD);
    for (const url of ['/app.php?view=show', '/?view=show']) {
      const res = await page.request.get(url);
      expect((await res.json()).admin, url).toBe(true);
    }
  });

  test('signing out closes it again', async ({ page }) => {
    await signIn(page, ADMIN_PASSWORD);
    await page.locator('button[name=logout]').click();
    await page.waitForLoadState('networkidle');
    const res = await page.request.get('/app.php?view=show');
    expect((await res.json()).admin).toBe(false);
  });
});

test.describe('authoring an event in a browser', () => {
  const { ADMIN_PASSWORD } = require('../standalone-setup.js');
  const fs = require('node:fs');
  const path = require('node:path');

  /**
   * Put the shipped capture back afterwards.
   *
   * Saving an event POINTS the installation at it, which is the behaviour under
   * test — an editor that saves an event nothing serves appears not to work.
   * That makes it residue for every test after this one, which is the failure
   * AGENTS.md names: a test reading state it did not set passes or fails on
   * history rather than on the code.
   */
  let CONFIG;
  let original;
  test.beforeAll(({}, testInfo) => {
    CONFIG = path.join(testInfo.config.metadata.root, 'conf', 'local-config.php');
    original = fs.readFileSync(CONFIG, 'utf8');
  });
  test.afterAll(async ({}, testInfo) => {
    fs.writeFileSync(CONFIG, original);
    // opcache revalidates a cached file every couple of seconds, so the next
    // describe would otherwise start against the event this one authored.
    await new Promise((resolve) => { setTimeout(resolve, 3000); });
    expect(testInfo).toBeTruthy();
  });

  async function signIn(page) {
    await page.goto('/app.php?view=login');
    await page.locator('#password').fill(ADMIN_PASSWORD);
    await page.locator('button[type=submit]').click();
    await page.waitForLoadState('networkidle');
  }

  test('a visitor sees the editor read-only, and cannot save', async ({ page, browser }) => {
    await page.goto('/app.php?view=event');
    await expect(page.locator('.msg.bad')).toContainText('Read-only');
    await expect(page.locator('#save')).toBeDisabled();

    // The disabled button is a courtesy; the refusal is the endpoint's.
    const anon = await browser.newContext();
    const origin = new URL(page.url()).origin;
    const refused = await anon.request.post(`${origin}/app.php?view=event`, {
      data: { event: 'Not Yours', teams: [], games: [] },
    });
    expect(refused.status()).toBe(403);
    await anon.close();
  });

  test('an operator writes an event and the installation serves it',
    async ({ page }) => {
      // The whole point, end to end: nothing was recorded from a Live! and
      // there is no Live! to record from, yet the Studio lists the games.
      await signIn(page);

      const saved = await page.request.post('/app.php?view=event', {
        data: {
          event: 'Spring Showcase',
          season: 'SHOW27',
          place: 'Riverside',
          teams: [
            { id: 1, name: 'Mosquitos', short: 'MOS' },
            { id: 2, name: 'Lemmings', short: 'LEM' },
          ],
          games: [
            { id: 1, home: 1, visitor: 2, field: '1', name: 'Semi-final',
              time: '2027-05-01 10:00', status: 'ongoing' },
          ],
        },
      });
      expect(saved.status()).toBe(200);
      const body = await saved.json();
      expect(body.capture).toBe('events/spring-showcase');
      expect(body.pointed, 'and the installation now serves it').toBe(true);

      // Rendered, not merely written. A capture that a page cannot draw is a
      // directory of JSON with the wrong field names in it.
      await page.goto('/app.php?view=scoreboard&game=1');
      await expect(page.locator('#homeScore')).toHaveText('0');
      await expect(page.locator('#homeName'), 'drawn from the authored event')
        .toHaveText('Mosquitos');
    });

  test('a squad added afterwards reaches the authored event', async ({ page }) => {
    // The two halves meeting: the editor writes teams with empty squads on
    // purpose, and the desk fills them in. Neither is any use without the other.
    await signIn(page);
    await page.request.post('/app.php?view=roster', {
      data: { team: 1, add: [{ num: 8, name: 'Ari Ace' }] },
    });

    await page.goto('/app.php?view=commentator&game=1');
    await expect(
      page.locator('.roster td.who', { hasText: 'Ace' }).first(),
    ).toBeVisible();
  });

  test('every control in the editor has a name a screen reader can read',
    async ({ page }) => {
      // The schedule is a TABLE of inputs, and the column heading is not the
      // control's accessible name — a `<th>` does not label a cell's input. The
      // first version passed an empty string as the label, which rendered an
      // empty `<label for=…>`: worse than none, because the control ends up
      // with no name at all and every cell announces as "edit text, blank".
      await signIn(page);
      await page.goto('/app.php?view=event');

      const bad = await page.evaluate(() => {
        const unnamed = [];
        document.querySelectorAll('input, select, textarea').forEach((el) => {
          const labelled = el.labels && [...el.labels].some((l) => l.textContent.trim());
          if (!labelled && !el.getAttribute('aria-label')
            && !el.getAttribute('aria-labelledby')) {
            unnamed.push(el.id || el.className || el.tagName);
          }
        });
        const empty = [...document.querySelectorAll('label')]
          .filter((l) => !l.textContent.trim()).length;

        return { unnamed, empty };
      });

      expect(bad.unnamed, 'controls with no accessible name').toEqual([]);
      expect(bad.empty, 'empty label elements').toBe(0);
    });

  test('a bad schedule is refused with every problem at once', async ({ page }) => {
    // One problem per attempt is six attempts to fix six typos, and this is a
    // form somebody fills in once under time pressure.
    await signIn(page);
    const refused = await page.request.post('/app.php?view=event', {
      data: {
        event: '',
        teams: [{ id: 1, name: 'Only One' }],
        games: [{ id: 1, home: 1, visitor: 9 }],
      },
    });
    expect(refused.status()).toBe(400);
    const problems = (await refused.json()).problems;
    expect(problems.length).toBeGreaterThan(2);
    expect(problems.join(' ')).toMatch(/name/i);
    expect(problems.join(' ')).toMatch(/two teams/i);
  });

  test('a game removed from the event loses its payload too', async ({ page }) => {
    // Otherwise the Studio keeps offering a game nothing else knows about, and
    // a switcher pointed at it draws a scoreboard for a fixture that is gone.
    await signIn(page);
    const two = {
      event: 'Spring Showcase',
      season: 'SHOW27',
      teams: [{ id: 1, name: 'Mosquitos' }, { id: 2, name: 'Lemmings' }],
      games: [
        { id: 1, home: 1, visitor: 2, name: 'Semi', status: 'ongoing' },
        { id: 2, home: 2, visitor: 1, name: 'Final' },
      ],
    };
    await page.request.post('/app.php?view=event', { data: two });
    expect((await page.request.get('/events/spring-showcase/games-2.json')).status())
      .toBe(200);

    two.games = [two.games[0]];
    await page.request.post('/app.php?view=event', { data: two });
    expect((await page.request.get('/events/spring-showcase/games-2.json')).status())
      .toBe(404);
  });

  test('the editor does not exist under a host', async ({ request }) => {
    // Hosted, an event is UltiOrganizer's: scheduled there, teams registered
    // there. A second copy authored here would disagree with it silently on the
    // surface that reaches air. Asserted through the route rather than the
    // guard, because the route is what somebody would find.
    expect(await status(request, '/app.php?view=event')).toBe(200);
    // ...and this tree is hostless, which the decoy vendor/ above proves. The
    // hosted 404 is the same `Auth::isHosted()` gate login.php and roster.php
    // use, exercised there.
  });
});

test.describe('squads kept here, because nothing upstream keeps one', () => {
  const { ADMIN_PASSWORD } = require('../standalone-setup.js');
  const ROSTER = '/app.php?view=roster';

  async function signIn(page) {
    await page.goto('/app.php?view=login');
    await page.locator('#password').fill(ADMIN_PASSWORD);
    await page.locator('button[type=submit]').click();
    await page.waitForLoadState('networkidle');
  }

  test('a squad is public to read and an operator to change', async ({ page, browser }) => {
    // Unlike notes and lines, which take unauthenticated writes because the
    // room code is a namespace and nothing in them reaches a viewer. A roster
    // does reach a viewer: the stage draws squad cards from it.
    const anon = await browser.newContext();
    const origin = new URL(await page.goto('/app.php?view=index').then((r) => r.url())).origin;

    const open = await anon.request.get(`${origin}${ROSTER}&team=940`);
    expect(open.status(), 'reading is open').toBe(200);
    expect((await open.json()).players).toEqual([]);

    const refused = await anon.request.post(`${origin}${ROSTER}`, {
      data: { team: 940, add: [{ name: 'Nobody At All' }] },
    });
    expect(refused.status(), 'writing is not').toBe(403);
    await anon.close();

    await signIn(page);
    const added = await page.request.post(ROSTER, {
      data: { team: 940, add: [{ num: 8, name: 'Ari Ace' }, { name: 'Bo Break' }] },
    });
    expect(added.status()).toBe(200);
    const body = await added.json();
    expect(body.added.map((p) => p.name)).toEqual(['Ari Ace', 'Bo Break']);
    expect(body.added[1].num, 'a squad may have no numbers').toBeNull();
  });

  test('importing the same sheet twice does not add everybody twice', async ({ page }) => {
    // The property the whole flow rests on. An import is the thing people
    // re-run when they are not sure it worked, and a squad with two of
    // everybody is worse than one that is missing somebody.
    await signIn(page);
    const post = (d) => page.request.post(ROSTER, { data: d });
    const rows = [{ num: 3, name: 'Cy Cutter' }, { num: 4, name: 'Dee Deep' }];

    const first = await (await post({ team: 941, add: rows })).json();
    expect(first.added).toHaveLength(2);

    const again = await (await post({ team: 941, add: rows })).json();
    expect(again.added, 'nobody added').toHaveLength(0);
    expect(again.skipped, 'and said so, rather than looking like a failure').toBe(2);
    expect(again.players).toHaveLength(2);

    // Identity is the NAME, not the number: numbers get corrected, and two
    // players wear 7 across a tournament.
    const renumbered = await (await post({
      team: 941, add: [{ num: 9, name: 'Cy Cutter' }],
    })).json();
    expect(renumbered.added).toHaveLength(0);
  });

  test('two squads never share a player id', async ({ page }) => {
    // A notes room is shared by both sides of a game and is keyed by player id
    // ALONE — `players[1234]`. A per-team counter gave each squad a player 1,
    // so one note was two people's: their pronouns and their name
    // pronunciation, on the desk, in front of somebody about to say it.
    await signIn(page);
    const post = (d) => page.request.post(ROSTER, { data: d });

    const a = await (await post({ team: 930, add: [{ name: 'Ari Ace' }] })).json();
    const b = await (await post({ team: 931, add: [{ name: 'Bo Break' }] })).json();

    expect(a.added[0].id).not.toBe(b.added[0].id);
    // And a team's ids stay inside its own range, so adding to one squad still
    // cannot move another's.
    expect(Math.floor(a.added[0].id / 10000)).toBe(930);
    expect(Math.floor(b.added[0].id / 10000)).toBe(931);
  });

  test('a removed player never gets their id handed to somebody else', async ({ page }) => {
    // Player ids key the desk's prepared notes. Reusing one would attach
    // somebody's pronouns and name pronunciation to a different person, on the
    // desk, in front of somebody about to say it.
    await signIn(page);
    const post = (d) => page.request.post(ROSTER, { data: d });

    const made = await (await post({ team: 942, add: [{ name: 'Eli Edge' }] })).json();
    const id = made.added[0].id;

    await post({ team: 942, remove: id });
    const after = await (await post({ team: 942, add: [{ name: 'Fay Flick' }] })).json();
    expect(after.added[0].id, 'a fresh id, not the departed one').not.toBe(id);
    expect(after.players.map((p) => p.name)).toEqual(['Fay Flick']);
  });

  test('a locally added player reaches the page through the same provider', async ({ page }) => {
    // The seam that matters: three call sites read a team payload and none of
    // them knows this happened. Asserted through the provider rather than
    // through the endpoint, because the endpoint working proves nothing about
    // whether a roster reaches a renderer.
    await signIn(page);
    await page.request.post(ROSTER, {
      data: { team: 300, add: [{ num: 77, name: 'Gus Guard' }] },
    });

    await page.goto('/app.php?view=commentator&game=702');
    await expect(page.locator('.roster').first()).toBeVisible();
    await expect(
      page.locator('.roster td.who', { hasText: 'Guard' }).first(),
      'the added player is on the desk',
    ).toBeVisible();

    // And the recorded squad is still there: added, not substituted.
    expect(await page.locator('.roster tbody tr').count()).toBeGreaterThan(5);
  });
});

test.describe('a public demonstration', () => {
  // The two stores this project leaves open on purpose — the room code is a
  // namespace, not a credential — and the one setting that closes them when the
  // installation is on the open internet.
  const fs = require('node:fs');
  const path = require('node:path');

  let CONFIG;
  let original;
  test.beforeAll(({}, testInfo) => {
    CONFIG = path.join(testInfo.config.metadata.root, 'conf', 'local-config.php');
    original = fs.readFileSync(CONFIG, 'utf8');
  });
  test.afterAll(() => { if (CONFIG) { fs.writeFileSync(CONFIG, original); } });

  /**
   * Turn the flag on, in the file the installation actually reads — and wait
   * for the server to agree.
   *
   * The wait is not politeness. `conf/local-config.php` is a PHP file, so it is
   * compiled and cached, and opcache revalidates a cached file only every
   * couple of seconds by default. A request made immediately after the write
   * gets the OLD settings. That is true of the real deployment too: changing
   * this file takes a moment to take effect, and somebody who edits it and
   * refreshes at once will conclude it did not work.
   *
   * Polled on a page that renders the flag rather than on a fixed delay, so it
   * costs nothing when the cache has already turned over.
   */
  const setDemo = async (request, on) => {
    fs.writeFileSync(CONFIG, on
      ? original.replace(/^return \[/m, "return [\n  'demo' => true,")
      : original);

    await expect
      .poll(async () => (await (await request.get('/app.php?view=commentator&game=702'))
        .text()).includes('Demonstration'),
      { timeout: 15000, message: 'the installation picks up local-config.php' })
      .toBe(on);
  };

  test('closes the two stores that take unauthenticated writes',
    async ({ browser, request }) => {
    const anon = await browser.newContext();
    const origin = new URL(BASE).origin;
    const write = () => anon.request.post(`${origin}/app.php?view=notes`, {
      data: { code: 'DDDDD', player: 1, text: 'a stranger wrote this' },
    });

    await setDemo(request, false);
    expect((await write()).status(), 'open by default, which is the design').toBe(200);

    await setDemo(request, true);
    const refused = await write();
    expect(refused.status(), 'and closed on a demonstration').toBe(403);
    expect((await refused.json()).error).toMatch(/read-only/i);

    const line = await anon.request.post(`${origin}/app.php?view=lines`, {
      data: { game: 702, code: 'DDDDD', team: 300, players: [1] },
    });
    expect(line.status(), 'the shared line too').toBe(403);
    await anon.close();
    await setDemo(request, false);
  });

  test('leaves every read alone, or it would showcase nothing', async ({ page, request }) => {
    await setDemo(request, true);
    // The whole point: a visitor still sees the desk, the rosters and the
    // score. Only saving is refused.
    await page.goto('/app.php?view=commentator&game=702');
    await expect(page.locator('.roster').first()).toBeVisible();
    await expect(page.locator('.flashline.on')).toContainText('Demonstration');
    await setDemo(request, false);
  });

  test('an administrator still writes, or nobody could set the demo up',
    async ({ page, request }) => {
      await setDemo(request, true);
      await page.goto('/app.php?view=login');
      await page.locator('#password').fill(
        require('../standalone-setup.js').ADMIN_PASSWORD,
      );
      await page.locator('button[type=submit]').click();
      await page.waitForLoadState('networkidle');

      const ok = await page.request.post('/app.php?view=notes', {
        data: { code: 'DDDDD', player: 2, text: 'the operator wrote this' },
      });
      expect(ok.status()).toBe(200);
      await setDemo(request, false);
    });

  test('the guided tour writes nothing and needs nobody', async ({ browser, request }) => {
    // It drives every state from one real payload, in the browser. That is what
    // makes it the one showcase safe to hand a stranger — and it must not
    // depend on being signed in, or the demo needs the password it exists to
    // avoid handing out.
    await setDemo(request, true);
    const anon = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const visitor = await anon.newPage();
    await visitor.goto(
      `${new URL(BASE).origin}/app.php?view=scoreboard&game=702&demo=1&step=1000`,
    );

    await expect(visitor.locator('#demoLabel')).toBeVisible();
    const first = await visitor.locator('#homeScore').textContent();
    await expect
      .poll(() => visitor.locator('#homeScore').textContent(),
        { timeout: 20000, message: 'the score moves on its own' })
      .not.toBe(first);

    await anon.close();
    await setDemo(request, false);
  });

  test('the imprint is reachable, and honest when nobody has filled it in',
    async ({ browser }) => {
      // A publicly reachable site run from Switzerland, Germany or Austria has
      // to say who is behind it, and this project cannot supply that — it is
      // somebody's real name and address. So an unconfigured installation says
      // so, rather than showing a blank page that reads as a bug.
      const anon = await browser.newContext();
      const visitor = await anon.newPage();
      await visitor.goto(`${new URL(BASE).origin}/app.php?view=index`);

      await visitor.locator('#intro a', { hasText: 'Imprint and data' }).click();
      await expect(visitor.locator('h1')).toHaveText('Imprint');
      await expect(visitor.locator('.missing'),
        'and says nobody has been named').toBeVisible();

      // The half this project CAN state, because it is a fact about the code:
      // what is stored about named people, and for how long.
      await expect(visitor.locator('body')).toContainText('Prepared notes about players');
      await expect(visitor.locator('body')).toContainText('7 days');
      await anon.close();
    });

  test('every game row offers match control, and not a host that is not there',
    async ({ page }) => {
      // Two bugs in one cell. It linked UltiOrganizer's own Scorekeeper —
      // which standalone is a 404, the same class of dead link the login
      // affordance had — and it never offered THIS project's match control at
      // all, so the surface that actually keeps the score was unreachable from
      // the page that tells an operator where everything is.
      await page.goto('/app.php?view=index');
      const row = page.locator('tbody tr').first();
      await expect(row.locator('a', { hasText: 'Match control' }))
        .toHaveAttribute('href', /\/k\/\d+$/);
      await expect(row.locator('a', { hasText: 'Scorekeeper' }),
        'and no link to an UltiOrganizer that is not there').toHaveCount(0);
    });

  test('the introduction offers a mixed game, and can be brought back',
    async ({ browser }) => {
      // The introduction is the only thing telling a visitor what any of this
      // is, so the two ways it fails are: nothing to click, and dismissed for
      // good with no way back.
      const anon = await browser.newContext();
      const visitor = await anon.newPage();
      await visitor.goto(`${new URL(BASE).origin}/app.php?view=index`);

      const intro = visitor.locator('#intro');
      await expect(intro).toBeVisible();

      // 703 in the shipped capture is the mixed semi-final. Offered separately
      // because the gender ratio and the matching bands do not appear at all in
      // an open game, and a visitor would conclude they do not exist.
      const mixed = intro.locator('a', { hasText: 'a mixed game' });
      await expect(mixed).toHaveAttribute('href', /\/s\/703\/overlay\?demo=1$/);
      await expect(intro.locator('a', { hasText: /^a game$/ }))
        .toHaveAttribute('href', /\/s\/\d+\/overlay\?demo=1$/);

      // Dismiss, and get it back. Removing it outright meant the only route
      // back was clearing site data.
      await visitor.locator('#introClose').click();
      await expect(intro).toBeHidden();

      await visitor.reload();
      await expect(visitor.locator('#intro'), 'and it stays dismissed').toBeHidden();

      await visitor.locator('#aboutBtn').click();
      await expect(visitor.locator('#intro')).toBeVisible();

      await visitor.reload();
      await expect(visitor.locator('#intro'), 'and stays back').toBeVisible();
      await anon.close();
    });

  test('a logged-out visitor sees the stage tour actually move', async ({ browser }) => {
    // The introduction's demo buttons are a ?demo=1 stage URL and nothing else,
    // so this asserts what pressing one has to do: no session, no operator, and
    // something moving on the screen.
    const anon = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const visitor = await anon.newPage();
    await visitor.goto(`${new URL(BASE).origin}/app.php?view=stage&game=702&demo=1&step=1000`);

    const frame = visitor.frameLocator('iframe').first();
    await expect(frame.locator('#homeScore'),
      'the stage has a scoreboard on it').toBeVisible({ timeout: 15000 });

    const first = await frame.locator('#homeScore').textContent();
    await expect
      .poll(() => frame.locator('#homeScore').textContent(),
        { timeout: 20000, message: 'and it is playing a game' })
      .not.toBe(first);
    await anon.close();
  });

  test('the stage ships the tour too, and only when asked', async ({ request }) => {
    // The stage is the surface a visitor actually looks at, and it could only
    // ever show whatever the recording was frozen at. The driver is loaded on
    // demand, like the scoreboard's: a broadcast page must not carry a demo
    // script it will never run.
    const tour = await (await request.get('/app.php?view=stage&game=702&demo=1')).text();
    expect(tour, 'the driver is there').toContain('shared/demo.js');

    const plain = await (await request.get('/app.php?view=stage&game=702')).text();
    expect(plain, 'and not otherwise').not.toContain('shared/demo.js');
  });
});

test.describe('keeping score', () => {
  const { ADMIN_PASSWORD } = require('../standalone-setup.js');
  const SCORE = '/app.php?view=score';

  async function signIn(page) {
    await page.goto('/app.php?view=login');
    await page.locator('#password').fill(ADMIN_PASSWORD);
    await page.locator('button[type=submit]').click();
    await page.waitForLoadState('networkidle');
  }

  test('an operator can hand a scorekeeper a code from the Studio', async ({ page, browser }) => {
    // The whole hand-off, through the controls rather than through the API.
    // `score.php` has always accepted a nominated code and match control has
    // always told the scorekeeper to ask the operator for one — but nothing in
    // the Studio could set it, so the only person who could keep score was an
    // administrator. That is not a hand-off, and it was found by trying to use
    // a real installation rather than by any test here.
    await signIn(page);
    await page.goto('/app.php?view=index');

    const bar = page.locator('.stagebar.scorekeeper');
    await expect(bar, 'match control has its own bar').toBeVisible();
    await expect(bar.locator('.connected'), 'and says nobody can score yet')
      .toHaveText('no code set');

    await bar.locator('button', { hasText: 'New code' }).click();
    await expect(bar.locator('.connected')).toHaveCount(0);

    // Read it the way the operator does — the flash carries it, the field stays
    // masked — then use it from a phone with no session at all.
    await expect(page.locator('#stageFlash')).toHaveText(/^Code is [A-Z0-9]{5}\.$/);
    const code = (await page.locator('#stageFlash').textContent() || '')
      .replace(/[^A-Z0-9]/g, '').slice(-5);
    expect(code, 'the operator has something to read out').toMatch(/^[A-Z0-9]{5}$/);

    // The code belongs to the game the stage is on, which conf/show.json names
    // and which is public precisely so the stage can poll it.
    const onAir = await (await page.request.get('/conf/show.json')).json();

    // From a session-less context, which is what a scorekeeper's phone is: an
    // administrator may always write, so asking this from the operator's own
    // page would be asking the wrong seat.
    //
    // It asks whether the phone MAY keep score rather than keeping one. A goal
    // here would be residue in a game other tests assert the score of — the
    // failure AGENTS.md names, and one this test caused before it was written
    // this way.
    const phone = await browser.newContext();
    const origin = new URL(page.url()).origin;
    const ask = (c) => phone.request.get(
      `${origin}${SCORE}&game=${onAir.game}&code=${c}`,
    ).then((r) => r.json());

    expect((await ask(code)).canWrite, 'the phone can now keep score').toBe(true);
    expect((await ask('ZZZZZ')).canWrite, 'and only with that code').toBe(false);
    expect((await ask(code)).code, 'which is never echoed to a phone').toBeNull();
    await phone.close();
  });

  test('a write needs the code an administrator nominated', async ({ page }) => {
    // Harder than the line store on purpose: that one takes unauthenticated
    // writes because nothing in it reaches a viewer. A score does.
    const anon = await page.request.post(SCORE, {
      data: { game: 900, code: 'ABCDE', goal: { home: true } },
    });
    expect(anon.status(), 'nothing nominated yet').toBe(403);

    await signIn(page);
    const named = await page.request.post(SCORE, { data: { game: 900, code: 'ABCDE' } });
    expect((await named.json()).nominated).toBe(true);

    // From a session-less context, which is what a scorekeeper's phone is. An
    // administrator may always write, so asking this from the admin's own page
    // would be asking the wrong seat.
    const phone = await page.context().browser().newContext();
    const base = new URL(page.url()).origin;
    const wrong = await phone.request.post(`${base}${SCORE}`, {
      data: { game: 900, code: 'ZZZZZ', goal: { home: true } },
    });
    expect(wrong.status(), 'another code still cannot').toBe(403);
    const right = await phone.request.post(`${base}${SCORE}`, {
      data: { game: 900, code: 'ABCDE', goal: { home: true } },
    });
    expect(right.status(), 'the nominated one can').toBe(200);
    expect((await right.json()).home).toBe(1);
    await phone.close();
  });

  test('a goal is the point it creates, so a retry is not a second goal', async ({ page }) => {
    // The rule the whole design rests on. A scorekeeper on a failing connection
    // retries constantly; +1 pressed twice is a real 2-0 from one point.
    await signIn(page);
    await page.request.post(SCORE, { data: { game: 901, code: 'ABCDE' } });

    const post = (body) => page.request.post(SCORE, { data: { game: 901, code: 'ABCDE', ...body } });
    await post({ goal: { home: true } });
    await post({ goal: { home: false } });
    let state = await (await post({ goal: { home: true, num: 3 } })).json();
    expect([state.home, state.away]).toEqual([2, 1]);
    const revAfterThree = state.rev;

    // The same point again, three times, as a flaky connection would.
    for (let i = 0; i < 3; i += 1) {
      state = await (await post({ goal: { home: true, num: 3 } })).json();
    }
    expect([state.home, state.away], 'the score did not move').toEqual([2, 1]);
    expect(state.rev, 'and neither did the revision').toBe(revAfterThree);
    expect(state.warning).toBe('Already recorded.');
  });

  test('a gap is refused rather than guessed', async ({ page }) => {
    await signIn(page);
    await page.request.post(SCORE, { data: { game: 902, code: 'ABCDE' } });
    const res = await page.request.post(SCORE, {
      data: { game: 902, code: 'ABCDE', goal: { home: true, num: 7 } },
    });
    // 409, not 500: re-read and reapply, do not retry this body.
    expect(res.status()).toBe(409);
  });

  test('undo takes back the last point, and only once', async ({ page }) => {
    await signIn(page);
    await page.request.post(SCORE, { data: { game: 903, code: 'ABCDE' } });
    const post = (body) => page.request.post(SCORE, { data: { game: 903, code: 'ABCDE', ...body } });
    await post({ goal: { home: true } });
    await post({ goal: { home: true } });

    let state = await (await post({ undo: { num: 2 } })).json();
    expect(state.home).toBe(1);
    // A retried undo must not eat the goal before it.
    state = await (await post({ undo: { num: 2 } })).json();
    expect(state.home, 'the retry did nothing').toBe(1);
  });

  test('the clock is three integers a scoreboard already knows how to draw', async ({ page }) => {
    await signIn(page);
    await page.request.post(SCORE, { data: { game: 904, code: 'ABCDE' } });
    const post = (body) => page.request.post(SCORE, { data: { game: 904, code: 'ABCDE', ...body } });

    let state = await (await post({ clock: 'start' })).json();
    const started = state.timer_start;
    expect(started).toBeGreaterThan(0);

    // Pressing start again is somebody checking, not somebody meaning to lose
    // the first half of the game.
    state = await (await post({ clock: 'start' })).json();
    expect(state.timer_start, 'a running clock is not restarted').toBe(started);

    state = await (await post({ clock: 'pause' })).json();
    expect(state.timer_pause_start).toBeGreaterThan(0);
    state = await (await post({ clock: 'start' })).json();
    expect(state.timer_pause_start, 'resuming clears the pause').toBe(0);
  });

  test('the nominated code is never served to whoever is not an admin', async ({ page }) => {
    await signIn(page);
    await page.request.post(SCORE, { data: { game: 905, code: 'ABCDE' } });

    const asAdmin = await (await page.request.get(`${SCORE}&game=905`)).json();
    expect(asAdmin.code).toBe('ABCDE');

    // A fresh context has no session, which is what a scorekeeper's phone is.
    const anon = await page.context().browser().newContext();
    const seen = await (await anon.request.get(
      `http://127.0.0.1:${new URL(page.url()).port}${SCORE}&game=905`,
    )).json();
    expect(seen.code, 'told whether their code counts, never what it is').toBeNull();
    expect(seen.nominated).toBe(true);
    await anon.close();
  });
});

test.describe('the rest of what only a person at the pitch knows', () => {
  const { ADMIN_PASSWORD } = require('../standalone-setup.js');
  const SCORE = '/app.php?view=score';
  const POSS = '/app.php?view=possession';

  async function nominate(page, game) {
    await page.goto('/app.php?view=login');
    await page.locator('#password').fill(ADMIN_PASSWORD);
    await page.locator('button[type=submit]').click();
    await page.waitForLoadState('networkidle');
    await page.request.post(SCORE, { data: { game, code: 'QQQQQ' } });
    await page.request.post(POSS, { data: { game, enabled: true, code: null } });
  }

  test('the scorekeeping code also writes possession, stoppage and the ratio',
    async ({ page, browser }) => {
      // One code on the phone. The alternative was a second five-character code
      // for the same person, which is a code nobody types correctly at a pitch.
      await nominate(page, 960);

      const phone = await browser.newContext();
      const origin = new URL(page.url()).origin;
      const post = (d) => phone.request.post(`${origin}${POSS}`,
        { data: { game: 960, code: 'QQQQQ', ...d } });

      expect((await post({ score: '0-0', defence: true })).status(),
        'possession').toBe(200);
      expect((await post({ score: '0-0', stoppage: true })).status(),
        'an injury stoppage').toBe(200);
      expect((await post({ ratio1: '4MMP/3FMP' })).status(), 'the ratio').toBe(200);

      const state = await (await post({ size: 7 })).json();
      expect(state.ratio1).toBe('4MMP/3FMP');
      expect(state.stoppage).toBeTruthy();

      // But it grants nothing upward: the mode and the code stay the operator's.
      const grab = await phone.request.post(`${origin}${POSS}`,
        { data: { game: 960, code: 'QQQQQ', enabled: false } });
      const after = await grab.json();
      expect(after.enabled, 'the mode is still the operator\'s').toBe(true);
      await phone.close();
    });

  test('a wrong code still cannot touch possession', async ({ browser }) => {
    const anon = await browser.newContext();
    const refused = await anon.request.post(`${BASE}${POSS}`,
      { data: { game: 960, code: 'ZZZZZ', score: '0-0', defence: true } });
    expect(refused.status()).toBe(403);
    await anon.close();
  });

  test('a timeout is recorded here, and reaches the ticks on air',
    async ({ page, browser }) => {
      // UltiOrganizer keeps timeouts as game events; standalone had nowhere to
      // put one, so the allowance drawn on air never moved however many were
      // called.
      await nominate(page, 961);

      const phone = await browser.newContext();
      const origin = new URL(page.url()).origin;
      const call = (d) => phone.request.post(`${origin}${SCORE}`,
        { data: { game: 961, code: 'QQQQQ', timeout: d } });

      const one = await (await call({ home: true })).json();
      expect(one.timeouts).toHaveLength(1);

      // Numbered per side, so the same press twice is one timeout — the rule
      // that lets it be pressed with no signal and sent later.
      await phone.request.post(`${origin}${SCORE}`,
        { data: { game: 961, code: 'QQQQQ', timeout: { home: true, num: 1 } } });
      const still = await (await phone.request.get(`${origin}${SCORE}&game=961`)).json();
      expect(still.timeouts, 'a retry is not a second timeout').toHaveLength(1);

      const undone = await (await call({ home: true, undo: true })).json();
      expect(undone.timeouts).toHaveLength(0);
      await phone.close();
    });

  test('pressing possession changes what the panel shows, and what the desk reads',
    async ({ page, browser }) => {
      // The test that was missing. The endpoint answering 200 proves nothing
      // about the panel: it read `defence` off an event the store writes as
      // `d`, so every press looked like no change at all — and it read the last
      // event in the whole log rather than the last one for the point being
      // played, so after a goal it showed the previous point's holder.
      //
      // So this presses the button and asserts BOTH ends: what the scorekeeper
      // sees, and what `shared/possession.js` — the reading every other surface
      // does — makes of the log afterwards.
      await nominate(page, 963);

      const phone = await browser.newContext();
      const keeper = await phone.newPage();
      const origin = new URL(page.url()).origin;
      await keeper.goto(`${origin}/app.php?view=matchcontrol&game=963`);
      await keeper.locator('#code').fill('QQQQQ');
      await keeper.locator('#useCode').click();
      if (await keeper.locator('#more').isHidden()) {
        await keeper.locator('#moreBtn').click();
      }

      // A point starts with the receiving team on offence and nobody having
      // said otherwise, so the offence is lit before anything is pressed.
      await expect(keeper.locator('#possOff')).toHaveClass(/\bon\b/);
      await expect(keeper.locator('#possDef')).not.toHaveClass(/\bon\b/);

      await keeper.locator('#possDef').click();
      await expect(keeper.locator('#possDef'), 'the press is reflected')
        .toHaveClass(/\bon\b/);
      await expect(keeper.locator('#possOff')).not.toHaveClass(/\bon\b/);

      // And the log says the same thing to everybody else.
      const read = async () => {
        const body = await (await phone.request.get(
          `${origin}/app.php?view=possession&game=963&code=QQQQQ`)).json();

        return body.events;
      };
      const Possession = require('../../shared/possession.js');
      expect(Possession.defenceHasDisc(await read(), 0, 0),
        'the desk reads a turnover too').toBe(true);

      await keeper.locator('#possOff').click();
      await expect(keeper.locator('#possOff')).toHaveClass(/\bon\b/);
      expect(Possession.defenceHasDisc(await read(), 0, 0)).toBe(false);
      await phone.close();
    });

  test('possession follows the point, so a goal starts it clean',
    async ({ page, browser }) => {
      // Events are filed under the score they were declared at. Reading the
      // last event in the log rather than the last for THIS point meant the
      // panel carried the previous point's holder across a goal — and the
      // scorekeeper would have pressed to "correct" something already correct.
      await nominate(page, 964);

      const phone = await browser.newContext();
      const keeper = await phone.newPage();
      const origin = new URL(page.url()).origin;
      await keeper.goto(`${origin}/app.php?view=matchcontrol&game=964`);
      await keeper.locator('#code').fill('QQQQQ');
      await keeper.locator('#useCode').click();
      if (await keeper.locator('#more').isHidden()) {
        await keeper.locator('#moreBtn').click();
      }

      await keeper.locator('#possDef').click();
      await expect(keeper.locator('#possDef')).toHaveClass(/\bon\b/);

      // Somebody scores. The next point has no declarations yet, which IS the
      // state a point starts in.
      await keeper.locator('#homeBtn').click();
      await expect(keeper.locator('#homeScore')).toHaveText('1');
      await expect(keeper.locator('#possOff'), 'a fresh point is the offence\'s')
        .toHaveClass(/\bon\b/);
      await expect(keeper.locator('#possDef')).not.toHaveClass(/\bon\b/);
      await phone.close();
    });

  test('the advanced panel is behind a toggle, and remembers being opened',
    async ({ page, browser }) => {
      // Not the job. The two big buttons are the job, and eight more controls in
      // front of them is how somebody presses the wrong one at 13-12.
      await nominate(page, 962);

      const phone = await browser.newContext();
      const keeper = await phone.newPage();
      const origin = new URL(page.url()).origin;
      await keeper.goto(`${origin}/app.php?view=matchcontrol&game=962`);
      await keeper.locator('#code').fill('QQQQQ');
      await keeper.locator('#useCode').click();

      await expect(keeper.locator('#moreBtn')).toBeVisible();
      await expect(keeper.locator('#more')).toBeHidden();

      await keeper.locator('#moreBtn').click();
      await expect(keeper.locator('#more')).toBeVisible();
      await expect(keeper.locator('#stopBtn')).toBeVisible();

      await keeper.reload();
      await expect(keeper.locator('#more'), 'still open after a reload').toBeVisible();
      await phone.close();
    });
});

test.describe('the scorekeeper on a phone', () => {
  const { ADMIN_PASSWORD } = require('../standalone-setup.js');

  test('a phone keeps scoring through an outage and catches up after', async ({ page, browser }) => {
    // The reason this surface exists: pitches are in parks. What the person
    // pressing sees must be what they entered, immediately, and the network
    // catching up later is the machine's problem rather than theirs.
    await page.goto('/app.php?view=login');
    await page.locator('#password').fill(ADMIN_PASSWORD);
    await page.locator('button[type=submit]').click();
    await page.waitForLoadState('networkidle');
    const base = new URL(page.url()).origin;
    await page.request.post('/app.php?view=score', { data: { game: 910, code: 'ABCDE' } });

    // Session-less, which is what a scorekeeper's phone is.
    const phone = await browser.newContext({ viewport: { width: 390, height: 844 } });
    await phone.addInitScript(() => localStorage.setItem('uo-score-code-910', 'ABCDE'));
    const q = await phone.newPage();
    await q.goto(`${base}/app.php?view=matchcontrol&game=910`);
    await expect(q.locator('#homeBtn')).toBeVisible();

    await q.locator('#homeBtn').click();
    await expect(q.locator('#homeScore')).toHaveText('1');
    await expect(q.locator('#state')).toHaveText('saved');

    await phone.setOffline(true);
    await q.locator('#homeBtn').click();
    await q.locator('#awayBtn').click();
    // Applied here first: the count in front of the scorekeeper is the count
    // they entered, and they are told plainly it has not landed.
    await expect(q.locator('#homeScore')).toHaveText('2');
    await expect(q.locator('#awayScore')).toHaveText('1');
    await expect(q.locator('#state')).toHaveText('2 unsent');

    await phone.setOffline(false);
    await expect(q.locator('#state')).toHaveText('saved', { timeout: 15000 });

    // And the server ended up with exactly what was pressed — not more, which
    // is what an outbox of `+1` messages would have produced.
    const server = await (await page.request.get('/app.php?view=score&game=910')).json();
    expect([server.home, server.away]).toEqual([2, 1]);
    await phone.close();
  });

  test('the phone says plainly when the scoreboard is not reading it', async ({ page, browser }) => {
    // The failure this prevents: somebody keeps a whole game's score carefully
    // into a store nothing consumes. That looks exactly like working, right up
    // until somebody watches the broadcast.
    await page.goto('/app.php?view=login');
    await page.locator('#password').fill(ADMIN_PASSWORD);
    await page.locator('button[type=submit]').click();
    await page.waitForLoadState('networkidle');
    const base = new URL(page.url()).origin;
    await page.request.post('/app.php?view=score', { data: { game: 912, code: 'ABCDE' } });

    const phone = await browser.newContext();
    await phone.addInitScript(() => localStorage.setItem('uo-score-code-912', 'ABCDE'));
    const q = await phone.newPage();
    await q.goto(`${base}/app.php?view=matchcontrol&game=912`);

    await expect(q.locator('#offair'), 'off by default').toBeVisible();
    await expect(q.locator('#offair')).toContainText('Not on the scoreboard');
    // And it can still be used: the warning is about where it goes, not about
    // whether it works.
    await expect(q.locator('#homeBtn')).toBeEnabled();

    await page.request.post('/app.php?view=score', { data: { game: 912, enabled: true } });
    await q.reload();
    await expect(q.locator('#offair'), 'gone once it is the source').toBeHidden();
    await phone.close();
  });

  test('a goal reaches the scoreboard in about a second', async ({ page, browser }) => {
    // The reason any of this exists. Live! serves a game with a flat 30-second
    // cache, so a goal is on air somewhere between at once and half a minute
    // late and no polling rate improves it. Switched to match control, the same
    // goal is on the board on the next read of a file this project owns.
    test.setTimeout(60000);
    await page.goto('/app.php?view=login');
    await page.locator('#password').fill(ADMIN_PASSWORD);
    await page.locator('button[type=submit]').click();
    await page.waitForLoadState('networkidle');
    const base = new URL(page.url()).origin;
    const post = (d) => page.request.post('/app.php?view=score', { data: d });
    await post({ game: 702, code: 'ABCDE' });

    const board = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const s = await board.newPage();
    await s.goto(`${base}/app.php?view=scoreboard&game=702`);
    const shown = async () => `${await s.locator('#homeScore').textContent()}-`
      + `${await s.locator('#awayScore').textContent()}`;

    // The capture's own score, from upstream.
    await expect(s.locator('#homeScore')).toHaveText('8');

    await post({ game: 702, enabled: true });
    await expect(s.locator('#homeScore'), 'the switch is obeyed').toHaveText('0', { timeout: 5000 });

    const started = Date.now();
    await post({ game: 702, code: 'ABCDE', goal: { home: true } });
    await expect(s.locator('#homeScore')).toHaveText('1', { timeout: 5000 });
    // Generous: the assertion is "seconds, not half a minute", and a loaded CI
    // runner is not the place to measure a tight number.
    expect(Date.now() - started, 'seconds, not thirty').toBeLessThan(5000);

    // And switching back must not leave the local score on air.
    await post({ game: 702, enabled: false });
    await expect(s.locator('#homeScore'), 'upstream again').toHaveText('8', { timeout: 5000 });
    expect(await shown()).toBe('8-6');
    await board.close();
  });

  test('only an operator chooses where the scoreboard reads from', async ({ page, browser }) => {
    await page.goto('/app.php?view=login');
    await page.locator('#password').fill(ADMIN_PASSWORD);
    await page.locator('button[type=submit]').click();
    await page.waitForLoadState('networkidle');
    const base = new URL(page.url()).origin;
    await page.request.post('/app.php?view=score', { data: { game: 913, code: 'ABCDE' } });

    // A scorekeeper holds a code that lets them keep score. It must not let
    // them decide what reaches air.
    const phone = await browser.newContext();
    const res = await phone.request.post(`${base}/app.php?view=score`, {
      data: { game: 913, code: 'ABCDE', enabled: true },
    });
    expect(res.status()).toBe(403);
    const after = await (await page.request.get('/app.php?view=score&game=913')).json();
    expect(after.enabled).toBe(false);
    await phone.close();
  });

  test('a phone without the code is read only', async ({ browser, page }) => {
    await page.goto('/app.php?view=matchcontrol&game=911');
    const base = new URL(page.url()).origin;
    const phone = await browser.newContext();
    const q = await phone.newPage();
    await q.goto(`${base}/app.php?view=matchcontrol&game=911`);
    await expect(q.locator('#state')).toHaveText('read only');
    await expect(q.locator('#teams')).toBeHidden();
    await expect(q.locator('#setup')).toBeVisible();
    await phone.close();
  });
});

/**
 * Empty a game's match-control score.
 *
 * The suite writes to the files a broadcast reads, so a test that adds goals
 * leaves them for everything after it — and `enabled: false` is not enough,
 * because the goals are still in the store the moment anything switches that
 * game's source back on. Two tests here drive a score to make something appear
 * on the board, and both must leave the store as they found it.
 *
 * Undo pops the last goal and names the point it is undoing, so this is safe to
 * repeat and stops of its own accord when the store is already empty.
 *
 * **The clock is part of that state**, and was not cleared here for as long as
 * there was no way to clear it. A test that started 703's clock to get a
 * broadcast into the branch it is actually in left it running for everything
 * after it, and the next test to assume a game begins with no clock failed on
 * a `Resume` button it had no reason to expect. `clock: 'reset'` exists now, so
 * emptying a game empties all of it.
 */
async function clearLocalScore(request, game) {
  let emptied = false;
  for (let i = 0; i < 64 && !emptied; i += 1) {
    const state = await (await request.get(`/app.php?view=score&game=${game}`)).json();
    const count = (state.goals || []).length;
    if (count === 0) { emptied = true; break; }
    await request.post('/app.php?view=score', { data: { game, undo: { num: count } } });
  }
  if (!emptied) {
    throw new Error(`could not empty the score store for game ${game}`);
  }
  await request.post('/app.php?view=score', { data: { game, clock: 'reset' } });
}

test.describe('the point that decides it', () => {
  const { ADMIN_PASSWORD } = require('../standalone-setup.js');

  test('universe point, and galaxy point at the half', async ({ page, browser }) => {
    // Driven through the score store because that is the only way to reach
    // 14-14: the captured payloads are the games that were played, and no
    // fixture sits on a decider. The derivation itself is proved in
    // `target.spec.js`; what this adds is that the board actually paints it,
    // over the cap and status text that share that line.
    test.setTimeout(90000);
    await page.goto('/app.php?view=login');
    await page.locator('#password').fill(ADMIN_PASSWORD);
    await page.locator('button[type=submit]').click();
    await page.waitForLoadState('networkidle');
    const base = new URL(page.url()).origin;
    const post = (d) => page.request.post('/app.php?view=score', { data: d });

    // Game 703's pool is game to 15 with NO halftimescore, which is the common
    // state of a real installation — so the 8 that makes 7-7 a decider is
    // derived, and this is the case that proves the derivation reaches air.
    await post({ game: 703, code: 'ABCDE' });
    await clearLocalScore(page.request, 703);
    await post({ game: 703, enabled: true });

    const board = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const s = await board.newPage();
    try {
      await s.goto(`${base}/app.php?view=scoreboard&game=703`);
      await expect(s.locator('#homeScore')).toHaveText('0', { timeout: 5000 });
      // A running clock, so this is the branch a broadcast is actually in.
      await post({ game: 703, code: 'ABCDE', clock: 'start' });

      /** Level at n-n, by the point number each goal completes. */
      const levelAt = async (n) => {
        for (let num = 1; num <= n * 2; num += 1) {
          await post({ game: 703, code: 'ABCDE', goal: { home: num % 2 === 1, num } });
        }
        await expect(s.locator('#homeScore')).toHaveText(String(n), { timeout: 5000 });
      };

      await levelAt(6);
      await expect(s.locator('#segment'), 'not a decider yet').toHaveText('Live');

      await levelAt(7);
      await expect(s.locator('#segment')).toHaveText('Galaxy point');
      await expect(s.locator('#centre')).toHaveClass(/decider/);

      await levelAt(14);
      await expect(s.locator('#segment')).toHaveText('Universe point');

      // Measured, not eyeballed: it is the longest string this line ever
      // carries, and a clipped one reads as "UNIVERSE POIN".
      const fit = await s.locator('#segment').evaluate((n) => ({
        scroll: n.scrollWidth, client: n.clientWidth
      }));
      expect(fit.scroll, 'the badge is not clipped').toBeLessThanOrEqual(fit.client + 1);
    } finally {
      // Restore in a finally, not after the assertions: a failure here would
      // otherwise leave a made-up score on air for every test that follows —
      // and the goals have to go as well as the switch, or the next test to
      // enable that source inherits a 14-14 game.
      await post({ game: 703, enabled: false });
      await clearLocalScore(page.request, 703);
      await board.close();
    }
  });
});

test.describe('the line a point was played with', () => {
  // The room keeps a history now, and playing time is derived from it. What
  // makes it trustworthy is what it REFUSES to record: the desk's line carries
  // over between points, so a point nobody confirmed must not inherit the
  // previous one and read afterwards as seven players who were on.
  const ROOM = { game: 970, code: 'K7QM4', team: 300 };

  const save = (request, over) => request.post('/app.php?view=lines', {
    data: Object.assign({}, ROOM, over),
  });
  const read = async (request) => (await request.get(
    `/app.php?view=lines&game=${ROOM.game}&code=${ROOM.code}&history=1`,
  )).json();

  test('a line sent WITH a point is recorded under it', async ({ request }) => {
    await save(request, { players: [3, 7, 12], score: '0-0' });
    const state = await read(request);
    expect(state.points[String(ROOM.team)]['0-0']).toEqual([3, 7, 12]);
    // And it is still the current line, which is what the desk reads back.
    expect(state.teams[String(ROOM.team)]).toEqual([3, 7, 12]);
  });

  test('a line sent WITHOUT one changes the line and records no point', async ({ request }) => {
    const before = await read(request);
    await save(request, { players: [3, 7, 15] });
    const after = await read(request);
    expect(after.teams[String(ROOM.team)]).toEqual([3, 7, 15]);
    expect(Object.keys(after.points[String(ROOM.team)] || {}))
      .toEqual(Object.keys(before.points[String(ROOM.team)] || {}));
  });

  test('confirming an UNCHANGED line records the point, rather than being dropped', async ({ request }) => {
    // The write that "changes nothing" is exactly the one the same-line button
    // makes, and dropping it would make that button do nothing at all.
    //
    // The precondition is set here rather than inherited from the test above:
    // run alone, this would otherwise be a CHANGED line and would pass without
    // touching the case it is named for.
    await save(request, { players: [3, 7, 15] });
    await save(request, { players: [3, 7, 15], score: '1-0' });
    const state = await read(request);
    expect(state.points[String(ROOM.team)]['1-0']).toEqual([3, 7, 15]);
  });

  test('an empty selection is never a point', async ({ request }) => {
    // A desk that has not picked yet is not a point where nobody played.
    await save(request, { players: [], score: '2-0' });
    const state = await read(request);
    expect(state.points[String(ROOM.team)]['2-0']).toBeUndefined();
  });

  test('the fast poll carries the KEYS, not the lines', async ({ request }) => {
    /**
     * A bandwidth bug, found by arithmetic rather than by a test failing.
     *
     * The desk polls this room every two seconds for its partner's picks. With
     * the lines of every point riding along, a busy room reaches tens of
     * kilobytes and is sent thirty times a minute to every desk — a
     * tournament's wifi spent on data that changes once a point and that the
     * poll does not read. Both things the poll DOES need — is this point
     * recorded, how many are — come from the keys.
     */
    const fast = await (await request.get(
      `/app.php?view=lines&game=${ROOM.game}&code=${ROOM.code}`,
    )).json();
    expect(fast.points, 'no lines on the fast poll').toBeUndefined();
    expect(fast.recorded[String(ROOM.team)], 'the keys, which are tiny')
      .toContain('0-0');

    const full = await (await request.get(
      `/app.php?view=lines&game=${ROOM.game}&code=${ROOM.code}&history=1`,
    )).json();
    expect(full.points[String(ROOM.team)]['0-0'], 'asked for, and there').toEqual([3, 7, 12]);
  });

  test('a save answers with the keys too, not the whole history', async ({ request }) => {
    const res = await save(request, { players: [3, 7, 12], score: '5-5' });
    const body = await res.json();
    expect(body.points).toBeUndefined();
    expect(body.recorded[String(ROOM.team)]).toContain('5-5');
  });

  test('a junk point key is ignored rather than stored', async ({ request }) => {
    await save(request, { players: [3, 7], score: 'yesterday' });
    const state = await read(request);
    expect(state.points[String(ROOM.team)].yesterday).toBeUndefined();
  });
});

test.describe('the desk recording who was on', () => {
  test('picking a line files it under the point being played', async ({ page, request }) => {
    // The end of the chain the pure specs cover in pieces: a commentator picks
    // seven, and that becomes the room's record of who played that point —
    // which is the only place playing time can come from, because nothing
    // upstream records a line at all.
    test.setTimeout(60000);
    const GAME = 702;
    const CODE = 'QTPT7';
    await request.post('/app.php?view=lines', {
      data: { game: GAME, code: CODE, clearPoints: true },
    });

    await page.addInitScript(({ game, code }) => {
      localStorage.setItem(`uo-lines-code-${game}`, code);
      localStorage.setItem('uo-commentator-name', 'Desk');
    }, { game: GAME, code: CODE });
    await page.goto(`/app.php?view=commentator&game=${GAME}`);
    await expect(page.locator('.roster').first()).toBeVisible();
    await page.locator('#tabPlay').click();

    // The picker sets aside anything that would make the line illegal, so the
    // first free chip is always a legal pick and this ends when it is full.
    const panel = page.locator('.cols .panel').nth(0);
    const free = panel.locator('.nums button:not(.on):not(.out)');
    while (await free.count()) { await free.first().click(); }
    await page.locator('#steps .tbtn.primary').click();
    await expect(page.locator('.onfield .p').first()).toBeVisible();

    // The room now holds that line under the score the point started at, which
    // is the same key the possession store uses for the same point.
    // &history=1: the fast poll carries the keys only, and this asserts on the
    // lines themselves.
    const state = await (await request.get(
      `/app.php?view=lines&game=${GAME}&code=${CODE}&history=1`,
    )).json();
    const teamId = Object.keys(state.teams)[0];
    const recorded = Object.keys(state.points[teamId] || {});
    expect(recorded.length, 'the point was recorded').toBeGreaterThan(0);
    expect(recorded[0], 'keyed by the score the point started at')
      .toMatch(/^\d{1,3}-\d{1,3}$/);
    expect(state.points[teamId][recorded[0]].length)
      .toBe(state.teams[teamId].length);

    // And the panel says so, rather than offering to record it again.
    await expect(page.locator('.onfield .confirmline').first())
      .toHaveText('Point recorded');
    await expect(page.locator('.onfield .linepoint').first())
      .toContainText(/of \d+ points recorded/);

    await request.post('/app.php?view=lines', {
      data: { game: GAME, code: CODE, clearPoints: true },
    });
  });
});

test.describe('the desk reads the split correctly', () => {
  test('the point badge works from the fast poll alone', async ({ page, request }) => {
    // The badge is repainted on every render and must be current, so it reads
    // the keys the two-second poll carries — not the history, which now arrives
    // on a twenty-second timer and would leave a freshly recorded point looking
    // unrecorded for up to twenty seconds. That button would get pressed again.
    test.setTimeout(60000);
    const GAME = 702;
    const CODE = 'FASTP';
    await request.post('/app.php?view=lines', {
      data: { game: GAME, code: CODE, clearPoints: true },
    });
    await page.addInitScript(({ game, code }) => {
      localStorage.setItem(`uo-lines-code-${game}`, code);
      localStorage.setItem('uo-commentator-name', 'Desk');
    }, { game: GAME, code: CODE });
    await page.goto(`/app.php?view=commentator&game=${GAME}`);
    await expect(page.locator('.roster').first()).toBeVisible();
    await page.locator('#tabPlay').click();

    const panel = page.locator('.cols .panel').nth(0);
    const free = panel.locator('.nums button:not(.on):not(.out)');
    while (await free.count()) { await free.first().click(); }
    await page.locator('#steps .tbtn.primary').click();

    // Immediately, without waiting for any slow poll.
    await expect(page.locator('.onfield .confirmline').first()).toHaveText('Point recorded');
    await expect(page.locator('.onfield .linepoint').first())
      .toContainText(/1 of \d+ points recorded/);

    await request.post('/app.php?view=lines', {
      data: { game: GAME, code: CODE, clearPoints: true },
    });
  });
});

test.describe('the desk under match control', () => {
  const { ADMIN_PASSWORD } = require('../standalone-setup.js');

  test('a line is filed under the point EVERYBODY ELSE means', async ({ page, browser }) => {
    /**
     * The desk reads Live!, and standalone that is a capture whose score never
     * moves. So every point would be filed under one key, each overwriting the
     * last, and a whole game of line-keeping would come back as one recorded
     * point — a feature that looks like it is working and silently is not.
     *
     * The fix is narrow on purpose: the page still DISPLAYS the upstream game,
     * and reads the match-control score for one thing only, which is which
     * point a line belongs to.
     */
    test.setTimeout(60000);
    const GAME = 703;
    const CODE = 'MCPT9';
    await page.goto('/app.php?view=login');
    await page.locator('#password').fill(ADMIN_PASSWORD);
    await page.locator('button[type=submit]').click();
    await page.waitForLoadState('networkidle');
    const base = new URL(page.url()).origin;
    const score = (d) => page.request.post('/app.php?view=score', { data: d });
    const lines = (d) => page.request.post('/app.php?view=lines', { data: d });

    await lines({ game: GAME, code: CODE, clearPoints: true });
    await score({ game: GAME, code: 'ABCDE' });
    await clearLocalScore(page.request, GAME);
    await score({ game: GAME, enabled: true });

    const desk = await browser.newContext();
    const d = await desk.newPage();
    try {
      await d.addInitScript(({ game, code }) => {
        localStorage.setItem(`uo-lines-code-${game}`, code);
        localStorage.setItem('uo-commentator-name', 'Desk');
      }, { game: GAME, code: CODE });
      await d.goto(`${base}/app.php?view=commentator&game=${GAME}`);
      await expect(d.locator('.roster').first()).toBeVisible();
      await d.locator('#tabPlay').click();

      const fill = async () => {
        const panel = d.locator('.cols .panel').nth(0);
        const free = panel.locator('.nums button:not(.on):not(.out)');
        while (await free.count()) { await free.first().click(); }
      };
      await fill();
      await d.locator('#steps .tbtn.primary').click();
      await expect(d.locator('.onfield .p').first()).toBeVisible();

      // A goal is scored in match control, so the next line belongs to a
      // DIFFERENT point. Upstream's score does not move at all here.
      await score({ game: GAME, code: 'ABCDE', goal: { home: true, num: 1 } });
      // The desk's score poll is on its own loop; give it a turn.
      await d.waitForTimeout(6000);
      await d.locator('#steps .tbtn', { hasText: 'Change line' }).click();
      const panel = d.locator('.cols .panel').nth(0);
      const on = panel.locator('.nums button.on');
      await on.first().click();
      const free = panel.locator('.nums button:not(.on):not(.out)');
      await free.first().click();

      const state = await (await page.request.get(
        `/app.php?view=lines&game=${GAME}&code=${CODE}&history=1`,
      )).json();
      const teamId = Object.keys(state.points)[0];
      const keys = Object.keys(state.points[teamId]);
      expect(keys.length, 'two points, not one overwritten twice').toBe(2);
      expect(keys.sort()).toEqual(['0-0', '1-0']);
    } finally {
      await score({ game: GAME, enabled: false });
      await clearLocalScore(page.request, GAME);
      await lines({ game: GAME, code: CODE, clearPoints: true });
      await desk.close();
    }
  });
});

test.describe('a phone with no signal at all', () => {
  const { ADMIN_PASSWORD } = require('../standalone-setup.js');

  test('the manifest describes an installable match control, and nothing else', async ({ request }) => {
    const res = await request.get('/app.php?view=manifest');
    expect(res.status()).toBe(200);
    expect(res.headers()['content-type']).toContain('application/manifest+json');
    const m = await res.json();
    // The LIST, not a game: an icon on a home screen is opened cold, days
    // later, and which game is a question only the person holding it can
    // answer.
    expect(m.start_url).toMatch(/\/k\/$/);
    expect(m.display).toBe('standalone');
    expect(m.icons.length).toBeGreaterThan(0);
  });

  test('the worker is served, and only match control links it', async ({ page, request }) => {
    const sw = await request.get('/sw.js');
    expect(sw.status()).toBe(200);
    const body = await sw.text();
    // The guard that matters more than the scope: standalone this worker is
    // allowed the whole origin, so it has to refuse air-facing URLs itself.
    expect(body).toContain('view=scoreboard');

    await page.goto('/app.php?view=matchcontrol&game=702');
    await expect(page.locator('link[rel=manifest]')).toHaveCount(1);

    // Never on a surface that reaches air.
    await page.goto('/app.php?view=scoreboard&game=702');
    await expect(page.locator('link[rel=manifest]')).toHaveCount(0);
    const registered = await page.evaluate(
      () => Boolean(navigator.serviceWorker && navigator.serviceWorker.controller),
    );
    expect(registered, 'the scoreboard is never under a worker').toBe(false);
  });

  test('/k/ lists the games this phone is carrying', async ({ page }) => {
    await page.goto('/k/702');
    await expect(page.locator('#teams, #setup')).not.toHaveCount(0);
    // Opening a game is what puts it on the list, and the names arrive with the
    // payload rather than being typed by anybody.
    await page.goto('/k/');
    await expect(page.locator('#games')).toBeVisible();
    await expect(page.locator('#gameList li')).toHaveCount(1);
    await expect(page.locator('#gameList li').first()).toContainText('Mosquitos');
    await expect(page.locator('#gameList a').first()).toHaveAttribute('href', /\/k\/702$/);
    // Nothing owed, so the list says so rather than showing a count.
    await expect(page.locator('#gamesHint')).toContainText(/has been sent/i);
  });

  test('the short URL is served in place, so the worker scope can exclude air', async ({ page }) => {
    // Every other short URL redirects to the long form. This one must not: a
    // worker's scope is a path, and `/app.php` is shared with the scoreboard.
    const res = await page.goto('/k/702');
    expect(new URL(page.url()).pathname, 'still at /k/702').toBe('/k/702');
    expect(res.status()).toBe(200);
  });

  test('the home screen icon opens OFFLINE after one game visit', async ({ page, context }) => {
    // `/k/` is the manifest's start_url — what a home screen icon opens. A
    // phone that had only ever opened a GAME would tap its own icon in a car
    // park and get a network error, so a game visit caches the list too.
    test.setTimeout(60000);
    await page.goto('/k/702');
    await page.waitForFunction(
      () => navigator.serviceWorker && navigator.serviceWorker.controller,
      null, { timeout: 20000 },
    );
    // Deliberately never visiting /k/ while online.
    await context.setOffline(true);
    await page.goto('/k/');
    await expect(page.locator('#games')).toBeVisible({ timeout: 20000 });
    await expect(page.locator('#gameList li')).not.toHaveCount(0);
    await context.setOffline(false);
  });

  test('a phone alone can find the event\'s games and set itself up', async ({ page, context }) => {
    /**
     * The person filming their own club's game is operator, scorekeeper and
     * camera at once, and the phone is often the only device at the pitch. The
     * device list is no help to them until a game is on it, so the way in was
     * knowing a game id and typing a URL — from a laptop they may not have.
     */
    test.setTimeout(60000);
    await page.goto('/k/');
    await expect(page.locator('#pick')).toBeVisible({ timeout: 15000 });
    const first = page.locator('#pickList li a').first();
    await expect(first).toContainText(/ v /);
    await expect(first).toHaveAttribute('href', /\/k\/\d+$/);

    // Tapping one sets it up, after which it is on the device list instead.
    const href = await first.getAttribute('href');
    const id = href.split('/').pop();
    await page.goto(href);
    await expect(page.locator('#teams, #setup')).not.toHaveCount(0);
    await page.goto('/k/');
    await expect(page.locator('#gameList li')).toContainText([new RegExp(String(id) + '|v ')]);
    // And it is no longer offered as something to set up.
    const offered = await page.locator('#pickList li a').evaluateAll(
      (as, want) => as.some((a) => a.getAttribute('href').endsWith('/' + want)), id,
    );
    expect(offered, 'a game already on the phone is not offered again').toBe(false);

    // With no signal the picker stays out of the way; the device list does not.
    await context.setOffline(true);
    await page.goto('/k/');
    await expect(page.locator('#games')).toBeVisible({ timeout: 15000 });
    await expect(page.locator('#pick')).toBeHidden();
    await context.setOffline(false);
  });

  test('the deployed version is readable, and never from a cache', async ({ page, request }) => {
    /**
     * `version.json` answers "is my fix live", which a page grep cannot: a
     * stale worker cache, a half-finished deploy and the wrong host in
     * deploy.env all look the same from outside. It is written by deploy.sh, so
     * a checkout does not have one — what is asserted here is the part that
     * would quietly break it: the worker must never answer for it, because it
     * matches the extension list that is served cache-first.
     */
    const worker = await (await request.get('/sw.js')).text();
    expect(worker, 'the worker refuses to cache it').toMatch(/version\\.json/);

    // And if one has been generated, it is served and parses.
    const res = await request.get('/version.json');
    if (res.status() === 200) {
      const body = await res.json();
      expect(typeof body.short).toBe('string');
      expect(typeof body.dirty).toBe('boolean');
    }
    await page.goto('/k/');
    await expect(page.locator('#games, #pick')).not.toHaveCount(0);
  });

  test('the phone offers signing in, for whoever is also the operator', async ({ browser }) => {
    // An administrator may write any game without a code, and on a one-person
    // rig the operator and the scorekeeper are the same person typing a code
    // they nominated themselves minutes earlier.
    const phone = await browser.newContext();
    const q = await phone.newPage();
    try {
      await q.goto('/k/703');
      await expect(q.locator('#setup')).toBeVisible();
      await expect(q.locator('#signin')).toBeVisible();
      await expect(q.locator('#signin')).toHaveAttribute('href', /login.*next=%2Fk%2F703/);
    } finally {
      await phone.close();
    }
  });

  test('the list SENDS what it says is unsent, and says so afterwards',
    async ({ page, context }) => {
      /**
       * The list is the screen that answers "have I handed everything over",
       * and it used to be a display only: no client was built there, so
       * somebody in signal could sit on it while it handed nothing over. The
       * documentation said otherwise, which made it a false promise rather
       * than a missing feature.
       */
      test.setTimeout(60000);
      await page.goto('/app.php?view=login');
      await page.locator('#password').fill(ADMIN_PASSWORD);
      await page.locator('button[type=submit]').click();
      await page.waitForLoadState('networkidle');
      await page.request.post('/app.php?view=score', { data: { game: 702, code: 'ABCDE' } });
      await clearLocalScore(page.request, 702);
      await page.addInitScript(() => localStorage.setItem('uo-score-code-702', 'ABCDE'));

      // Two presses made with no network, so they are still on the phone.
      await page.goto('/k/702');
      await expect(page.locator('#homeBtn')).toBeVisible();
      await context.setOffline(true);
      await page.locator('#homeBtn').click();
      await page.locator('#awayBtn').click();
      await expect(page.locator('#state')).toHaveText('2 unsent');

      // Back in signal, and the scorekeeper opens the list rather than the game.
      await context.setOffline(false);
      await page.goto('/k/');
      await expect(page.locator('#gamesHint')).toContainText(/has been sent/i, { timeout: 15000 });
      // The row reports the SERVER's score, not the phone's, and has nothing
      // queued and nothing refused.
      const row = page.locator('#gameList li').first();
      await expect(row).toContainText(/1–1 sent|1\u20131 sent/);
      await expect(row).not.toContainText(/to send|not accepted/i);

      const state = await (await page.request.get('/app.php?view=score&game=702')).json();
      expect(state.goals.length, 'the server has both presses').toBe(2);

      await clearLocalScore(page.request, 702);
    });

  test('the list distinguishes what landed from what was refused', async ({ page, context }) => {
    /**
     * "Nothing left to send" is not "everything got through". A conflict
     * empties the queue exactly as a success does, so a row that counted only
     * the queue would read "Sent" over a press that was never stored anywhere.
     */
    test.setTimeout(60000);
    await page.goto('/app.php?view=login');
    await page.locator('#password').fill(ADMIN_PASSWORD);
    await page.locator('button[type=submit]').click();
    await page.waitForLoadState('networkidle');
    await page.request.post('/app.php?view=score', { data: { game: 702, code: 'ABCDE' } });
    await clearLocalScore(page.request, 702);
    await page.addInitScript(() => localStorage.setItem('uo-score-code-702', 'ABCDE'));

    // The phone records point 1 with no signal.
    await page.goto('/k/702');
    await expect(page.locator('#homeBtn')).toBeVisible();
    await context.setOffline(true);
    await page.locator('#homeBtn').click();
    await expect(page.locator('#state')).toHaveText('1 unsent');

    // Meanwhile somebody else records point 1, so the phone's press cannot land.
    await context.setOffline(false);
    await page.request.post('/app.php?view=score', {
      data: { game: 702, code: 'ABCDE', goal: { home: false, num: 1 } },
    });

    await page.goto('/k/');
    const row = page.locator('#gameList li').first();
    await expect(row).toContainText(/not accepted/i, { timeout: 15000 });
    await expect(row, 'and what DID land, from the server').toContainText(/0–1 sent|0\u20131 sent/);
    await expect(page.locator('#gamesHint')).toContainText(/not accepted/i);

    /**
     * Measured, not eyeballed, at phone width.
     *
     * The first version of this row pushed the score and the Remove button off
     * the side of the screen whenever a fixture had two long club names — which
     * every assertion above passed straight through, because the text was in
     * the DOM. A screenshot caught it.
     */
    await page.setViewportSize({ width: 390, height: 700 });
    const fits = await page.locator('#gameList li').first().evaluate((li) => {
      const rect = li.getBoundingClientRect();
      const drop = li.querySelector('.drop').getBoundingClientRect();
      return {
        overflow: li.scrollWidth - li.clientWidth,
        dropRight: Math.round(drop.right),
        rowRight: Math.round(rect.right),
      };
    });
    expect(fits.overflow, 'the row does not scroll sideways').toBeLessThanOrEqual(1);
    expect(fits.dropRight, 'Remove is inside the row').toBeLessThanOrEqual(fits.rowRight + 1);

    await clearLocalScore(page.request, 702);
    await page.evaluate(() => localStorage.removeItem('uo-score-declined-702'));
  });

  test('a phone with no signal says why a code cannot be used yet', async ({ page, context }) => {
    /**
     * The trap in the offline flow. A code typed at a pitch is checked against
     * the store, and with no signal there is nothing to check it against — so
     * the buttons stay disabled and the screen used to repeat "enter the code"
     * at somebody who just had.
     */
    test.setTimeout(60000);
    await page.goto('/app.php?view=login');
    await page.locator('#password').fill(ADMIN_PASSWORD);
    await page.locator('button[type=submit]').click();
    await page.waitForLoadState('networkidle');
    await page.request.post('/app.php?view=score', { data: { game: 703, code: 'ABCDE' } });

    // A phone that has never been set up for this game.
    const phone = await page.context().browser().newContext();
    const q = await phone.newPage();
    try {
      await q.goto(new URL(page.url()).origin + '/k/703');
      await expect(q.locator('#setup')).toBeVisible();
      await expect(q.locator('#setupWhy')).toContainText(/Enter the code/i);

      await phone.setOffline(true);
      await q.locator('#code').fill('ABCDE');
      await q.locator('#useCode').click();
      await expect(q.locator('#setupWhy'), 'not "enter the code" again')
        .toContainText(/No signal.*where there is signal/is);
      await expect(q.locator('#homeBtn')).toBeHidden();
    } finally {
      await phone.setOffline(false);
      await phone.close();
    }
  });

  test('a game can be removed once it has nothing left to send', async ({ page, context }) => {
    // `forget()` existed, was documented and was tested, and no screen called
    // it — so a phone accumulated games, and their scorekeeping codes, with no
    // way to clear one.
    test.setTimeout(60000);
    await page.goto('/k/703');
    await expect(page.locator('#teams, #setup')).not.toHaveCount(0);
    await page.evaluate(() => localStorage.setItem('uo-score-code-703', 'ABCDE'));

    await page.goto('/k/');
    const row = page.locator('#gameList li').filter({ hasText: '703' }).first();
    const any = (await row.count()) ? row : page.locator('#gameList li').first();
    await expect(any.locator('.drop')).toBeEnabled();
    await any.locator('.drop').click();

    await expect(page.locator('#gameList li')).toHaveCount(0);
    const left = await page.evaluate(() => localStorage.getItem('uo-score-code-703'));
    expect(left, 'the code goes with it').toBeNull();
  });

  test('offline, the buttons carry the team names this phone already knows',
    async ({ page, context }) => {
      // The payload never arrives with no signal, so the two big buttons said
      // "Home" and "Away" — on a phone holding the real names in its own index
      // from the last time it opened this game. Whose game it is matters at a
      // pitch with two matches in earshot.
      test.setTimeout(60000);
      await page.goto('/k/702');
      await expect(page.locator('#homeName')).toHaveText(/Mosquitos/);
      await page.waitForFunction(
        () => navigator.serviceWorker && navigator.serviceWorker.controller,
        null, { timeout: 20000 },
      );

      await context.setOffline(true);
      await page.reload();
      await expect(page.locator('#homeName'), 'remembered, not "Home"')
        .toHaveText(/Mosquitos/, { timeout: 20000 });
      await expect(page.locator('#awayName')).toHaveText(/Lemmings/);
      await context.setOffline(false);
    });

  test('the cache is never consulted without the query that names the game',
    async ({ page }) => {
      /**
       * A trap that was in the worker and is now out of it.
       *
       * Match control is also reachable as `?view=matchcontrol&game=702`, where
       * the game id is in the QUERY. An `ignoreSearch` fallback — which the
       * offline path had — would happily answer a request for game 702 with a
       * cached page for 703, and a scorekeeper would enter a whole game against
       * the wrong fixture. Those URLs are out of scope today, so this guards
       * the thing that would make widening the scope dangerous rather than
       * merely a decision.
       */
      const worker = await (await page.request.get('/sw.js')).text();
      // The OPTION, not the word: the file explains at length why it is not
      // here, and a checker that cannot tell the two apart would make the
      // explanation unwritable.
      expect(worker).not.toMatch(/ignoreSearch\s*:/);
    });

  test('a reload with the network OFF still opens the game and its score',
    async ({ page, context }) => {
      /**
       * The whole point of the worker, and the one thing the outbox could not
       * do on its own: an unsent press survived a reload only if the page could
       * LOAD, and with no signal a reload was a dead page — at exactly the
       * moment somebody pulls to refresh to see whether that helps.
       */
      test.setTimeout(90000);
      await page.goto('/app.php?view=login');
      await page.locator('#password').fill(ADMIN_PASSWORD);
      await page.locator('button[type=submit]').click();
      await page.waitForLoadState('networkidle');
      // page.request, not the bare fixture: these are admin writes and the
      // session lives on the page's context.
      await page.request.post('/app.php?view=score', { data: { game: 702, code: 'ABCDE' } });
      await clearLocalScore(page.request, 702);

      await page.addInitScript(() => localStorage.setItem('uo-score-code-702', 'ABCDE'));
      // The short URL, which is the one a phone is told to add to its home
      // screen and the only one a worker covers.
      await page.goto('/k/702');
      await expect(page.locator('#homeBtn')).toBeVisible();
      // Wait until a worker is actually in control, which is the state a phone
      // reaches after its first visit with signal.
      await page.waitForFunction(
        () => navigator.serviceWorker && navigator.serviceWorker.controller,
        null, { timeout: 20000 },
      );

      await page.locator('#homeBtn').click();
      await expect(page.locator('#homeScore')).toHaveText('1');
      await expect(page.locator('#state')).toHaveText('saved');

      // The signal goes. A press still registers, and still shows.
      await context.setOffline(true);
      await page.locator('#homeBtn').click();
      await expect(page.locator('#homeScore')).toHaveText('2');

      // And the reload that used to be a dead page.
      await page.reload();
      await expect(page.locator('#homeBtn'), 'the page loaded from the worker')
        .toBeVisible({ timeout: 20000 });
      await expect(page.locator('#homeScore'),
        'the synced point AND the unsent one').toHaveText('2');

      // Away from the page before clearing up: back online it drains its own
      // outbox, which would re-post goals as fast as this undid them.
      await context.setOffline(false);
      await page.goto('about:blank');
      await clearLocalScore(page.request, 702);
    });
});

test.describe('easy mode, forced', () => {
  const { ADMIN_PASSWORD } = require('../standalone-setup.js');

  test('?auto=1 runs the default director whatever the show state says', async ({ page, browser }) => {
    /**
     * `STUDIO.md` §8 has documented this URL since before there was a Studio,
     * and the page never read it: a tournament with no operator got "whatever
     * is in conf/show.json", which on a shared installation is whatever the
     * last person left there. The default layout was reached only when the file
     * did not exist at all.
     */
    test.setTimeout(60000);
    await page.goto('/app.php?view=login');
    await page.locator('#password').fill(ADMIN_PASSWORD);
    await page.locator('button[type=submit]').click();
    await page.waitForLoadState('networkidle');
    const base = new URL(page.url()).origin;

    const before = await (await page.request.get('/app.php?view=show')).json();
    try {
      // An operator leaves the stage with the scoreboard off and a card on.
      await page.request.post('/app.php?view=show', {
        data: {
          rev: before.rev,
          cards: [
            { id: 'scoreboard', slot: 'lower-left', visible: false, params: {} },
            { id: 'progression', slot: 'center', visible: true, params: {} },
          ],
        },
      });

      const board = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
      const s = await board.newPage();
      try {
        /**
         * Asserted on what is SHOWN, not on what is mounted. A card is armed
         * and framed while still off air — that is the arm/show rule — so the
         * scoreboard's iframe exists either way and only the `shown` class says
         * whether a viewer can see it.
         */
        const shownScoreboard = '.shown iframe[src*="scoreboard"]';

        // Without the switch: the stored state, scoreboard off air.
        await s.goto(`${base}/app.php?view=stage&game=702`);
        await expect(s.locator('.shown').first()).toBeVisible({ timeout: 15000 });
        await expect(s.locator(shownScoreboard)).toHaveCount(0);

        // With it: the default director, whatever the file says.
        await s.goto(`${base}/app.php?view=stage&game=702&auto=1`);
        await expect(s.locator(shownScoreboard)).toHaveCount(1, { timeout: 15000 });
      } finally {
        await board.close();
      }
    } finally {
      const now = await (await page.request.get('/app.php?view=show')).json();
      await page.request.post('/app.php?view=show', {
        data: { rev: now.rev, cards: before.cards, logo: before.logo, game: before.game },
      });
    }
  });
});

test.describe('the statistic strip', () => {
  const { ADMIN_PASSWORD } = require('../standalone-setup.js');

  test('an operator switches it on, and only an operator can', async ({ page, browser }) => {
    test.setTimeout(90000);
    await page.goto('/app.php?view=login');
    await page.locator('#password').fill(ADMIN_PASSWORD);
    await page.locator('button[type=submit]').click();
    await page.waitForLoadState('networkidle');
    const base = new URL(page.url()).origin;
    const score = (d) => page.request.post('/app.php?view=score', { data: d });
    const poss = (d) => page.request.post('/app.php?view=possession', { data: d });

    await score({ game: 702, code: 'ABCDE' });
    await clearLocalScore(page.request, 702);
    await score({ game: 702, enabled: true });

    const board = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const s = await board.newPage();
    try {
      await s.goto(`${base}/app.php?view=scoreboard&game=702`);
      await expect(s.locator('#homeScore')).toHaveText('0', { timeout: 5000 });

      // Off by default: a strip nobody asked for must not appear on air.
      await expect(s.locator('#statline')).toBeHidden();

      await poss({ game: 702, statline: true });
      // Still nothing to say at 0-0 — an empty answer is a normal one, and the
      // strip stays hidden rather than reaching for filler.
      await expect(s.locator('#statline')).toBeHidden();

      // Three in a row, which is a fact it will state -- and the fixture game
      // records the starting offence, so two of the three are breaks and the
      // ranking prefers saying that over the plain run.
      for (let num = 1; num <= 3; num += 1) {
        await score({ game: 702, code: 'ABCDE', goal: { home: true, num } });
      }
      await expect(s.locator('#statline')).toBeVisible({ timeout: 5000 });
      await expect(s.locator('#statline')).toContainText(/2 breaks in a row/i);

      // Measured rather than eyeballed: the facts are sentences of varying
      // length, and one that overflows its row is a graphic with a word cut off.
      const fit = await s.locator('#statline').evaluate((n) => ({
        scroll: n.scrollWidth, client: n.clientWidth,
      }));
      expect(fit.scroll, 'the strip is not clipped').toBeLessThanOrEqual(fit.client + 1);

      /**
       * It must keep describing the game ON SCREEN, not the upstream one.
       *
       * The capture's own game is 8-6 with fourteen goals; match control is the
       * source here and the board is showing three. The strip is repainted from
       * the ~1s possession channel as well as from the game poll, and that
       * channel used to hand it the UNMERGED payload — so a second after a
       * correct strip appeared, it would start stating facts about a fourteen
       * goal game beside a score of three. Two answers about one game, which is
       * exactly what switching the source is supposed to prevent.
       */
      // Turning possession tracking on changes the possession document, which
      // is what makes the board repaint the strip from the ~1s channel rather
      // than from the game poll. That is the path with the bug in it.
      await poss({ game: 702, enabled: true });
      // SAMPLED rather than awaited, because the wrong state is transient: the
      // game poll repairs it within a few seconds, so a retrying assertion
      // would wait the defect out and pass. Upstream's game would say "6
      // straight holds" here -- it is 8-6 with fourteen goals, and the board is
      // showing three.
      const seen = new Set();
      for (let i = 0; i < 15; i += 1) {
        seen.add(await s.locator('#statline').textContent());
        await s.waitForTimeout(200);
      }
      expect([...seen].join(' | '), 'never upstream\'s game beside a local score')
        .not.toMatch(/straight holds/i);
      await poss({ game: 702, enabled: false });

      await poss({ game: 702, statline: false });
      await expect(s.locator('#statline')).toBeHidden({ timeout: 5000 });
    } finally {
      await poss({ game: 702, statline: false });
      await score({ game: 702, enabled: false });
      await clearLocalScore(page.request, 702);
      await board.close();
    }
  });

  test('the demo shows it, because the demo is the showcase of every state', async ({ browser }) => {
    // The poll that carries the operator's switch is exactly the one the demo
    // does not run, so without a deliberate nudge the newest display state is
    // the one state `?demo=1` cannot show.
    const board = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const s = await board.newPage();
    try {
      // A short step so the walk reaches a point with something to say quickly.
      await s.goto('/app.php?view=scoreboard&game=702&demo=1&step=600');
      await expect(s.locator('#statline')).toBeVisible({ timeout: 20000 });
      await expect(s.locator('#statline')).not.toBeEmpty();
    } finally {
      await board.close();
    }
  });

  test('never on a post-production frame, which has to be reproducible', async ({ page, browser }) => {
    // `?at=` draws ONE deterministic frame, and the strip is the only thing on
    // the bug that is not a function of the payload: it is switched on by an
    // operator in the present and arrives on a poll, so the same command would
    // sometimes carry a statistic and sometimes not.
    await page.goto('/app.php?view=login');
    await page.locator('#password').fill(ADMIN_PASSWORD);
    await page.locator('button[type=submit]').click();
    await page.waitForLoadState('networkidle');
    const base = new URL(page.url()).origin;
    await page.request.post('/app.php?view=possession', { data: { game: 702, statline: true } });

    const board = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const s = await board.newPage();
    try {
      // Goals enough that the live strip would have plenty to say.
      await s.goto(`${base}/app.php?view=scoreboard&game=702&at=600&goals=8`);
      await s.waitForFunction(() => document.documentElement.dataset.rendered === '1',
        null, { timeout: 15000 });
      await expect(s.locator('#homeScore')).not.toHaveText('0');
      // Sampled, because the thing being excluded arrives on a timer.
      for (let i = 0; i < 10; i += 1) {
        await expect(s.locator('#statline')).toBeHidden();
        await s.waitForTimeout(200);
      }
    } finally {
      await page.request.post('/app.php?view=possession', { data: { game: 702, statline: false } });
      await board.close();
    }
  });

  test('a scorekeeping code cannot turn it on', async ({ page, browser }) => {
    // Same line match control draws: the code lets somebody keep the score, and
    // grants nothing upward about what reaches air.
    await page.goto('/app.php?view=login');
    await page.locator('#password').fill(ADMIN_PASSWORD);
    await page.locator('button[type=submit]').click();
    await page.waitForLoadState('networkidle');
    const base = new URL(page.url()).origin;
    await page.request.post('/app.php?view=possession', { data: { game: 914, code: 'ABCDE' } });

    const phone = await browser.newContext();
    const res = await phone.request.post(`${base}/app.php?view=possession`, {
      data: { game: 914, code: 'ABCDE', statline: true },
    });
    // The write itself is accepted — the code may record possession — but the
    // strip is not in the fields it may set, so it must not have changed.
    expect(res.status()).toBe(200);
    const after = await (await page.request.get('/app.php?view=possession&game=914')).json();
    expect(after.statline, 'the code holder did not change what is on air').toBe(false);
    await phone.close();
  });
});

test.describe('the operator on a phone', () => {
  const { ADMIN_PASSWORD } = require('../standalone-setup.js');

  test('is offered the switch, not told to go and find themselves', async ({ browser }) => {
    /**
     * "Not on the scoreboard — ask the operator to switch it" is right for a
     * scorekeeper and absurd for the operator, who on a one-person rig is the
     * person reading it, signed in, with no way to act on their own
     * instruction. Switching the source is administrator-only server-side, so
     * the button appears for exactly the people who can use it.
     */
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    try {
      await page.goto('/app.php?view=login&next=%2Fk%2F703');
      await page.locator('#password').fill(ADMIN_PASSWORD);
      await page.locator('button[type=submit]').click();
      await expect(page.locator('#homeBtn')).toBeVisible();

      // Signed in, so the code is irrelevant: an administrator may write any
      // game without one, and the prompt for it is not shown at all.
      await expect(page.locator('#setup')).toBeHidden();

      // Reading upstream, so the banner is up — with a button rather than an
      // instruction to fetch somebody.
      await expect(page.locator('#offair')).toBeVisible();
      await expect(page.locator('#offairWhy')).not.toContainText(/Ask the operator/i);
      await expect(page.locator('#offairDo')).toBeVisible();

      await page.locator('#offairDo').click();
      await expect(page.locator('#offair'), 'and the banner goes').toBeHidden({ timeout: 10000 });

      const state = await (await page.request.get('/app.php?view=score&game=703')).json();
      expect(state.enabled, 'the board now reads this score').toBe(true);
      await page.request.post('/app.php?view=score', { data: { game: 703, enabled: false } });
    } finally {
      await ctx.close();
    }
  });

  test('the notice can be put away, and comes back when the situation changes', async ({ page, browser }) => {
    /**
     * It exists to stop somebody keeping a whole game into a store nothing
     * reads, which is worth saying — but a notice that cannot be dismissed is
     * one people learn to read past, including on the day it matters. So it
     * hides per game, and going on air and coming off again is a new situation
     * rather than the one that was waved away.
     */
    await page.goto('/app.php?view=login');
    await page.locator('#password').fill(ADMIN_PASSWORD);
    await page.locator('button[type=submit]').click();
    await page.waitForLoadState('networkidle');
    const base = new URL(page.url()).origin;
    const source = (on) => page.request.post('/app.php?view=score',
      { data: { game: 702, enabled: on } });
    await source(false);

    const phone = await browser.newContext();
    const q = await phone.newPage();
    try {
      await q.goto(`${base}/k/702`);
      await expect(q.locator('#offair')).toBeVisible();
      await q.locator('#offairHide').click();
      await expect(q.locator('#offair')).toBeHidden();

      // Still gone after a reload: a dismissal that forgets itself is not one.
      await q.reload();
      await expect(q.locator('#homeBtn, #setup')).not.toHaveCount(0);
      await expect(q.locator('#offair')).toBeHidden();

      // On air, then off again — which is a new situation, so it speaks up.
      await source(true);
      await q.waitForTimeout(6000);
      await source(false);
      await expect(q.locator('#offair')).toBeVisible({ timeout: 15000 });
    } finally {
      await source(false);
      await phone.close();
    }
  });

  test('a scorekeeper with only a code is still told to ask', async ({ page, browser }) => {
    // The switch decides what a viewer sees, and a code grants nothing upward.
    await page.goto('/app.php?view=login');
    await page.locator('#password').fill(ADMIN_PASSWORD);
    await page.locator('button[type=submit]').click();
    await page.waitForLoadState('networkidle');
    await page.request.post('/app.php?view=score', { data: { game: 703, code: 'ABCDE' } });

    const phone = await browser.newContext();
    await phone.addInitScript(() => localStorage.setItem('uo-score-code-703', 'ABCDE'));
    const q = await phone.newPage();
    try {
      await q.goto(new URL(page.url()).origin + '/k/703');
      await expect(q.locator('#homeBtn')).toBeVisible();
      await expect(q.locator('#offairWhy')).toContainText(/Ask the operator/i);
      await expect(q.locator('#offairDo')).toBeHidden();
    } finally {
      await phone.close();
    }
  });
});

test.describe('resetting the clock', () => {
  const { ADMIN_PASSWORD } = require('../standalone-setup.js');

  test('takes two presses, and the first one only asks', async ({ page }) => {
    /**
     * The store has always had `clock: reset`; nothing exposed it, so a clock
     * started by mistake or on the wrong game could be paused and never
     * cleared. It is the one destructive control on the page, so it asks
     * first — and a dialog is the wrong way to ask on a phone at a pitch.
     */
    test.setTimeout(60000);
    await page.goto('/app.php?view=login&next=%2Fk%2F703');
    await page.locator('#password').fill(ADMIN_PASSWORD);
    await page.locator('button[type=submit]').click();
    await expect(page.locator('#homeBtn')).toBeVisible();

    // A clock this test did not start is a clock it cannot reason about: the
    // first version pressed Start on one another test had left running and
    // asserted on a button that said Resume. Set the state, then assert on it.
    await clearLocalScore(page.request, 703);
    await expect(page.locator('#startBtn')).toHaveText('Start');

    await page.locator('#startBtn').click();
    await expect(page.locator('#startBtn')).toHaveText('Pause');

    await page.locator('#moreBtn').click();
    const reset = page.locator('#clockReset');
    await expect(reset).toBeVisible();

    // One press asks; the clock is untouched.
    await reset.click();
    await expect(reset).toHaveText(/Tap again/i);
    await expect(page.locator('#startBtn'), 'still running').toHaveText('Pause');

    // The second does it.
    await reset.click();
    await expect(reset).toHaveText('Reset clock');
    await expect(page.locator('#startBtn')).toHaveText('Start');
    await expect(page.locator('#clock')).toHaveText('--:--');

    const state = await (await page.request.get('/app.php?view=score&game=703')).json();
    expect(state.timer_start, 'the store agrees').toBeNull();
  });

  test('an open panel scrolls; the two presses never give way', async ({ page }) => {
    /**
     * The panel grew by one section and the page had no room for it. `.teams`
     * takes what is left of a height-constrained column, what was left went to
     * zero, and the clock row was then drawn over the remains of the score
     * buttons — so a tap aimed at the home team hit the clock instead. It read
     * as a flaky test ("element intercepts pointer events") and was a real
     * phone bug: on a short screen the job of the page had disappeared.
     *
     * Geometry, not appearance. A screenshot of this looks fine.
     */
    test.setTimeout(60000);
    await page.goto('/app.php?view=login&next=%2Fk%2F703');
    await page.locator('#password').fill(ADMIN_PASSWORD);
    await page.locator('button[type=submit]').click();
    await expect(page.locator('#homeBtn')).toBeVisible();
    if (await page.locator('#more').isHidden()) {
      await page.locator('#moreBtn').click();
    }
    await expect(page.locator('#clockReset')).toBeVisible();

    // The smallest phone still in use, and the one that fails first.
    for (const size of [{ width: 375, height: 667 }, { width: 320, height: 568 }]) {
      await page.setViewportSize(size);
      const seen = await page.evaluate(() => {
        const rect = (id) => document.getElementById(id).getBoundingClientRect();
        const home = rect('homeBtn');
        const at = document.elementFromPoint(home.left + home.width / 2,
          home.top + home.height / 2);
        const panel = document.getElementById('moreWrap');

        return {
          home: Math.round(home.height),
          away: Math.round(rect('awayBtn').height),
          undo: Math.round(rect('undoBtn').height),
          hits: at ? (at.closest('#homeBtn') ? 'homeBtn' : at.id || at.className) : null,
          overflows: document.body.scrollHeight > window.innerHeight + 1,
          panelScrolls: panel.scrollHeight > panel.clientHeight,
        };
      });

      const where = `${size.width}x${size.height}`;
      expect(seen.hits, `a tap on the home button reaches it at ${where}`).toBe('homeBtn');
      expect(seen.home, `the home press is still a press at ${where}`).toBeGreaterThan(100);
      expect(seen.away, `the away press is still a press at ${where}`).toBeGreaterThan(100);
      expect(seen.undo, `undo survives too at ${where}`).toBeGreaterThan(30);
      expect(seen.overflows, `the page itself does not scroll at ${where}`).toBe(false);
      expect(seen.panelScrolls, `the panel is what gives at ${where}`).toBe(true);
    }
  });
});

test.describe('signing in is a detour, not a destination', () => {
  const { ADMIN_PASSWORD } = require('../standalone-setup.js');

  test('it returns to the page that sent you', async ({ browser }) => {
    // Somebody who signed in from a game landed on a page whose largest control
    // signed them out, and pressed it.
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    try {
      await page.goto('/app.php?view=login&next=%2Fk%2F703');
      await page.locator('#password').fill(ADMIN_PASSWORD);
      await page.locator('button[type=submit]').click();
      await page.waitForLoadState('networkidle');
      expect(new URL(page.url()).pathname, 'back at the game').toBe('/k/703');
      // And signed in, so the code prompt is gone.
      await expect(page.locator('#homeBtn')).toBeVisible();
    } finally {
      await ctx.close();
    }
  });

  test('a next that leaves this site is ignored', async ({ browser }) => {
    // A redirect target from a URL is an open redirect unless it is pinned to
    // this site, and a bad one is somebody probing rather than a person to help.
    for (const bad of ['https://example.org/', '//example.org/', '/\\example.org']) {
      const ctx = await browser.newContext();
      const page = await ctx.newPage();
      try {
        await page.goto(`/app.php?view=login&next=${encodeURIComponent(bad)}`);
        await page.locator('#password').fill(ADMIN_PASSWORD);
        await page.locator('button[type=submit]').click();
        await page.waitForLoadState('networkidle');
        // The rejected target is still in the address bar, because the form
        // posts to the same URL — what matters is that nothing navigated away
        // from this origin, and that the login page is still the one shown.
        expect(new URL(page.url()).origin, bad).toBe(BASE);
        await expect(page.locator('.onward'), bad).toBeVisible();
      } finally {
        await ctx.close();
      }
    }
  });

  test('the way onward is the button, and signing out is not', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    try {
      await page.goto('/app.php?view=login');
      await page.locator('#password').fill(ADMIN_PASSWORD);
      await page.locator('button[type=submit]').click();
      await expect(page.locator('.onward')).toBeVisible();
      await expect(page.locator('button.quiet')).toHaveText(/Sign out/);
      // Measured: the onward control is the wider of the two.
      const sizes = await page.evaluate(() => ({
        onward: document.querySelector('.onward').getBoundingClientRect().width,
        out: document.querySelector('button.quiet').getBoundingClientRect().width,
      }));
      expect(sizes.onward).toBeGreaterThanOrEqual(sizes.out);
    } finally {
      await ctx.close();
    }
  });
});

test.describe('admin gating without Live!', () => {
  test('this really is a hostless tree', async ({ request }) => {
    // The assertion that gives the rest of this block its meaning. If an
    // UltiOrganizer autoloader is reachable, Auth delegates to Live! and these
    // tests silently become hosted-mode tests. The login page 404s under a
    // host and renders without one, so it reports the mode.
    expect(await status(request, '/app.php?view=login')).toBe(200);
  });

  test('changing what is on air is refused without a session', async ({ request }) => {
    const res = await request.post(`${BASE}/app.php?view=show`, {
      data: { rev: 0, cards: [] },
    });
    expect(res.status()).toBe(403);
  });

  test('reading what is on air is not gated', async ({ request }) => {
    const res = await request.get(`${BASE}/app.php?view=show`);
    expect(res.status()).toBe(200);
    expect((await res.json()).admin).toBe(false);
  });
});

test.describe('the mark', () => {
  /*
   * The tab icons only mean something to somebody who has been taught them, and
   * the Studio is where that teaching happens: it hands out the stage URL, the
   * match control link and the commentary desk, each carrying the icon of the
   * surface it opens. `docs/BRAND.md` §7.
   */
  test('each surface carries its own mark, and the Studio teaches them',
    async ({ page }) => {
      const origin = new URL(BASE).origin;

      for (const [path, icon] of [
        ['/app.php?view=index', 'studio'],
        ['/c/702', 'desk'],
        ['/k/702', 'score'],
      ]) {
        await page.goto(origin + path);
        await expect(page.locator(`img.mark[src*="icon-${icon}.svg"]`).first(),
          `${path} wears its own mark`).toBeVisible();
      }

      // Every mark must actually load. A 404 here is an invisible failure: the
      // alt text is empty by design, so a broken one leaves nothing at all.
      await page.goto(origin + '/app.php?view=index');
      await expect.poll(async () => page.evaluate(() =>
        [...document.querySelectorAll('img.mark')]
          .filter((i) => !i.complete || i.naturalWidth === 0).length),
      { message: 'no mark 404s' }).toBe(0);

      // The teaching itself: the stage URL wears the stage's mark, and the
      // match control link wears match control's.
      await expect(page.locator('a.url img.mark[src*="icon-onair.svg"]').first(),
        'the stage URL is marked as a stage').toBeVisible();
      await expect(page.locator('a.action img.mark[src*="icon-score.svg"]').first(),
        'the match control link is marked as match control').toBeVisible();
    });

  test('nothing visible is branded onto a page that goes to air',
    async ({ page }) => {
      /*
       * The scoreboard and the stage are rendered to video. A mark there is this
       * project's branding burned into somebody else's broadcast, beside the
       * tournament's own logo — which is the one that belongs on air and has a
       * corner chosen for it in the Studio.
       *
       * Their TAB icons are set, because a tab is not on air, so this asserts the
       * absence in the body rather than the absence of a <link rel="icon">.
       */
      const origin = new URL(BASE).origin;

      for (const path of ['/s/702', '/s/702/overlay']) {
        await page.goto(origin + path);
        await expect(page.locator('img.mark'),
          `${path} is on air and carries no mark`).toHaveCount(0);
        await expect(page.locator('link[rel="icon"]'),
          `${path} still has a tab icon, which is not on air`).toHaveCount(1);
      }
    });
});

test.describe('what an overlay is allowed to say', () => {
  const { ADMIN_PASSWORD } = require('../standalone-setup.js');
  const SHOW = '/app.php?view=show';

  /** Diagnostics are off unless an operator says otherwise, from the Studio. */
  async function setDiagnostics(page, on) {
    await page.goto('/app.php?view=login');
    // The login page shows the signed-in view once there is a session, and no
    // password field with it — so a helper called twice in one test must not
    // assume the form is there. It did, and the second call timed out with
    // diagnostics left on for every test after it.
    if (await page.locator('#password').count()) {
      await page.locator('#password').fill(ADMIN_PASSWORD);
      await page.locator('button[type=submit]').click();
      await page.waitForLoadState('networkidle');
    }
    const now = await (await page.request.get(SHOW)).json();
    const r = await page.request.post(SHOW, {
      data: {
        rev: now.rev, game: now.game, logo: now.logo, cards: now.cards, diagnostics: on,
      },
    });
    expect(r.ok(), 'the switch was written').toBe(true);
  }

  /** What is actually on the canvas, by geometry rather than by class. */
  function visible(page, id) {
    return page.evaluate((el) => {
      const node = document.getElementById(el);
      if (!node) { return false; }

      return getComputedStyle(node).display !== 'none'
        && node.getBoundingClientRect().height > 0;
    }, id);
  }

  test('a board that cannot load its game shows nothing at all', async ({ page }) => {
    /**
     * The defect this replaces: a bad game id in a browser-source URL put
     * white "Invalid ID" over the live picture, and the page had no way to
     * tell a laptop from a broadcast. Measured on /s/999999 as three opaque
     * white text nodes.
     */
    await page.goto('/app.php?view=scoreboard&game=999999');
    await page.waitForTimeout(3000);

    expect(await visible(page, 'errorDisplay'), 'no error text').toBe(false);
    expect(await visible(page, 'loadingState'), 'no loading text').toBe(false);
    expect(await visible(page, 'connectionStatus'), 'no connection chip').toBe(false);
    expect(await visible(page, 'scoreboard'), 'and no board either').toBe(false);
  });

  test('?debug=1 is the laptop case, and says why', async ({ page }) => {
    await page.goto('/app.php?view=scoreboard&game=999999&debug=1');
    await expect(page.locator('#errorDisplay')).toBeVisible();
    await expect(page.locator('#errorMessage')).not.toBeEmpty();
  });

  test('a working board stays silent, even with diagnostics on', async ({ page }) => {
    /*
     * The rule that makes ONE switch safe for a whole broadcast. Without it,
     * turning diagnostics on to inspect a dead board on field 2 would put a
     * connection chip on every healthy board on every other field.
     *
     * The rule itself is asserted in `diagnostics.spec.js`, where it can be
     * stated directly; this is the end of the wire.
     */
    await page.goto('/app.php?view=scoreboard&game=702&debug=1');
    await expect(page.locator('#scoreboard')).toBeVisible();

    expect(await visible(page, 'connectionStatus'), 'no chip on a healthy board').toBe(false);
    expect(await visible(page, 'errorDisplay')).toBe(false);
  });

  test('a painted board is never replaced by an error message', async ({ page }) => {
    /**
     * The mid-broadcast case, and the one the old code got wrong in the most
     * expensive way: five consecutive failed polls hid a working scoreboard
     * and put white text over the live picture, in front of an audience that
     * cannot refresh.
     *
     * Staged as a 403 rather than a dropped connection, for two reasons: it
     * is what an event being unpublished mid-broadcast looks like, and
     * `provider.js` marks it FATAL, which is the branch that used to blank the
     * board on the very first failure. A dropped connection takes a full
     * minute to reach the five-failure threshold, because the client backs off
     * exponentially — a test written that way passed against the old code.
     *
     * `?debug=1` is on throughout, so this also pins the other half of the
     * rule: with diagnostics ALLOWED and the poll failing, the board is still
     * not replaced — the chip appears, the scoreboard stays.
     */
    await page.goto('/app.php?view=scoreboard&game=702&debug=1&stale=3600');
    await expect(page.locator('#scoreboard')).toBeVisible();

    await page.route('**/games-702.json', (route) => route.fulfill({
      status: 403,
      contentType: 'application/json',
      body: JSON.stringify({ error: 'Event is not published.' }),
    }));

    await page.waitForTimeout(6000);
    expect(await visible(page, 'scoreboard'), 'the board somebody is watching').toBe(true);
    expect(await visible(page, 'errorDisplay'), 'not replaced by a message').toBe(false);
    // And because diagnostics are on, the failure is visible to the operator
    // rather than silent — this is what the chip is for.
    expect(await visible(page, 'connectionStatus'), 'the chip does appear').toBe(true);
  });

  test('an operator turns them on from the Studio, with no URL to edit',
    async ({ page, browser }) => {
      /*
       * A URL parameter alone was not an answer: the source that most needs
       * diagnosing is a switcher in a rack, where editing a URL means a
       * virtual keyboard. Show state is a static file the board already polls,
       * served by our own server — so the switch arrives even when the thing
       * that broke is the game API.
       */
      await setDiagnostics(page, true);

      const source = await browser.newContext();
      const board = await source.newPage();
      try {
        const origin = new URL(page.url()).origin;
        await board.goto(`${origin}/app.php?view=scoreboard&game=999999`);
        // Longer than the board's diagnostics poll, which is deliberately slow:
        // it decides whether a message may be painted, not what is on air.
        await expect(board.locator('#errorDisplay')).toBeVisible({ timeout: 20000 });
      } finally {
        await source.close();
        await setDiagnostics(page, false);
      }
    });

  test('a board withdraws rather than keep a score nothing has confirmed',
    async ({ page }) => {
      /**
       * The case none of the three options in STUDIO.md §11 covered. "Keep the
       * last good frame" fixes the mid-broadcast error message and leaves a
       * plausible, wrong score on air for the rest of the game — which is the
       * failure this project guards against hardest.
       *
       * `stale=1` with a five-minute poll is that situation compressed: one
       * payload arrives, and nothing confirms it afterwards.
       */
      // Set the state this asserts on: diagnostics are global, and a test
      // that ran before this one may have left them on.
      await setDiagnostics(page, false);

      await page.goto('/app.php?view=scoreboard&game=702&stale=1&interval=300000');
      await expect(page.locator('#scoreboard'), 'it paints first').toBeVisible();

      await expect
        .poll(() => visible(page, 'scoreboard'), { timeout: 10000 })
        .toBe(false);
      // Silently. A blank corner claims nothing; an error message is a defect
      // broadcast to viewers.
      expect(await visible(page, 'errorDisplay'), 'and says nothing about it').toBe(false);
    });

  test('the Studio reports the feed and owns the switch', async ({ page, browser }) => {
    const panel = page.locator('#healthPanel');
    await page.goto('/app.php?view=index');
    const origin = new URL(page.url()).origin;

    // A visitor sees the state and cannot change it — the Studio's standing
    // rule that the information is not secret and the controls are.
    const guest = await browser.newContext();
    const anon = await guest.newPage();
    try {
      await anon.goto(`${origin}/app.php?view=index`);
      await expect(anon.locator('#healthPanel')).toContainText(/Diagnostics off/i);
      await expect(anon.locator('#healthPanel button')).toHaveCount(0);
    } finally {
      await guest.close();
    }

    await page.goto('/app.php?view=login');
    await page.locator('#password').fill(ADMIN_PASSWORD);
    await page.locator('button[type=submit]').click();
    await page.goto('/app.php?view=index');

    await expect(panel).toContainText(/Game data is answering/i);
    const toggle = panel.locator('button');
    await expect(toggle).toHaveText(/Show diagnostics/i);

    try {
      await toggle.click();
      // It expires on its own: "turn it on, fix it, forget to turn it off" is
      // the failure, and the consequence is text on air during the next fault.
      await expect(panel).toContainText(/Diagnostics on/i);
      await expect(panel).toContainText(/10 min more/i);

      const state = await (await page.request.get(SHOW)).json();
      const left = state.diagnostics - Math.floor(Date.now() / 1000);
      expect(left, 'the store holds an expiry, not a flag').toBeGreaterThan(500);
      expect(left).toBeLessThanOrEqual(600);
    } finally {
      await setDiagnostics(page, false);
    }
  });
});
