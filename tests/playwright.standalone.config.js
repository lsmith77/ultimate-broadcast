// @ts-check
/**
 * The standalone front controller, against PHP's own built-in server.
 *
 * Separate from the main config because it tests the opposite arrangement: no
 * UltiOrganizer, no Live!, no database, no Apache — which is the entire point,
 * and which the main config's `globalSetup` would fail to seed.
 *
 * It starts the server itself, with `app.php` as the router, because the
 * routing rules under test only exist in router mode. Serving the directory
 * without one is exactly the misconfiguration these tests are here to catch.
 *
 * Run with:
 *   npx playwright test --config tests/playwright.standalone.config.js
 */
const { defineConfig } = require('@playwright/test');
const path = require('node:path');
const { build } = require('./standalone-setup.js');

const PORT = Number(process.env.STANDALONE_PORT || 8099);

// NOT the working copy. A hosted checkout has live/vendor/autoload.php two
// directories above shared/auth.php, which makes Auth::isHosted() answer true
// and turns every assertion below into a test of the hosted path wearing a
// standalone label. It did, until this was noticed. See tests/standalone-setup.js.
/**
 * Built ONCE, and reused by every worker.
 *
 * This config module is evaluated in the runner and again in each worker
 * process, so an unguarded `build()` made a fresh tree per worker — the server
 * served the runner's and the workers looked at their own, and only one was
 * ever torn down. Workers inherit the environment they are spawned with, so
 * this is where they find the tree that is actually being served.
 */
const root = process.env.STANDALONE_ROOT || build();
process.env.STANDALONE_ROOT = root;

module.exports = defineConfig({
  testDir: './e2e',
  // Where the throwaway tree is, for the tests that have to look at the
  // installation rather than at a page.
  metadata: { root },
  globalTeardown: require.resolve('./standalone-teardown.js'),
  testMatch: ['standalone.spec.js'],

  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 0,
  reporter: process.env.CI ? [['github'], ['list']] : [['list']],

  use: { baseURL: `http://127.0.0.1:${PORT}` },

  // `-t <root>` sets the document root and `app.php` is the router. Both
  // matter: the router is what denies conf/, and without it this suite would
  // pass against a server that leaks. The root is the throwaway tree above.
  webServer: {
    command: `php -S 127.0.0.1:${PORT} -t ${JSON.stringify(root)} ${JSON.stringify(path.join(root, 'app.php'))}`,
    url: `http://127.0.0.1:${PORT}/app.php?view=index`,
    reuseExistingServer: false,
    timeout: 20000,
  },
});
