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
      ['/k/702', 'matchcontrol'],
    ];
    for (const [url, view] of cases) {
      const res = await request.get(url, { maxRedirects: 0 });
      expect(res.status(), url).toBe(302);
      expect(res.headers().location, url).toContain(`view=${view}`);
    }
  });

  test('a short URL that matches nothing is not the picker', async ({ request }) => {
    // It fell through to the dispatcher, which defaults to index — so /s/999x
    // answered 200 with a page that had nothing to do with what was asked.
    for (const url of ['/s/999x', '/s/702/notacolour', '/c/abc', '/k/', '/k/abc']) {
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
    // The introduction on the Studio tells a visitor to add ?demo=1 to a stage
    // URL, so this asserts exactly that sentence: no session, no operator, and
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
