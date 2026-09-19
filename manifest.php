<?php

/**
 * The web app manifest for match control, and only for match control.
 *
 *   /index.php?view=live/overlays/manifest, or ?view=manifest standalone
 *
 * It is PHP rather than a static file because every URL in it — `start_url`,
 * `scope`, the icons — differs between the two modes, and `Overlays\Mode` is
 * the one place allowed to know that (`STANDALONE.md` §7). A static manifest
 * would have `/live/overlays/` written into it, which is the assumption that
 * broke every page in this project the first time the directory was served
 * from a document root.
 *
 * WHY ONLY THIS SURFACE GETS ONE
 *
 * A manifest is what turns "add to home screen" into an app with its own icon
 * that opens without browser chrome. That is worth having for the phone at the
 * pitch and worth having for nothing else here: the scoreboard and the stage
 * are pointed at by a switcher, the Studio and the commentary desk live in a
 * browser on a laptop, and an installable icon for any of them would only
 * invite somebody to run an on-air surface in a window they cannot inspect.
 *
 * `start_url` is the game LIST rather than a game: an icon on a home screen is
 * opened cold, days later, and "which game" is a question only the person
 * holding it can answer.
 */

if (!defined('UO_ROUTED_VIEW')) {
    http_response_code(404);
    exit;
}

if (is_file(__DIR__ . '/../conf/LocalConfig.php')) {
    require_once __DIR__ . '/../conf/LocalConfig.php';
}
require_once __DIR__ . '/shared/mode.php';

use Overlays\Mode;

$base = rtrim(defined('UO_URL_PREFIX') ? UO_URL_PREFIX : '/', '/');
$assets = Mode::assetBase($base);

/**
 * `/k/` in both modes, which is the prefix the service worker is scoped to.
 *
 * Hosted it is a rewrite onto the front controller; standalone `app.php` serves
 * match control in place there rather than redirecting, for exactly this
 * reason — a scope is a path, and sharing `/app.php` with the scoreboard would
 * put a worker in front of a surface that goes on air.
 */
$scope = $base . '/k/';
$start = $base . '/k/';

header('Content-Type: application/manifest+json; charset=UTF-8');
// A manifest is not on air and changes only when this file does, but a phone
// that cached one from another installation would open somebody else's start
// URL. Short and revalidated rather than immutable.
header('Cache-Control: no-cache');

echo json_encode([
    'name' => 'Match control',
    'short_name' => 'Score',
    'description' => 'Keep the score and the clock from a phone at the pitch, '
        . 'with or without a signal.',
    'start_url' => $start,
    'scope' => $scope,
    // Standalone display, because the browser's chrome is a row of controls
    // nobody needs while pressing two buttons, and its back gesture is a way to
    // leave the page mid-point.
    'display' => 'standalone',
    'orientation' => 'portrait',
    // The page's own background, so the launch screen does not flash white at
    // somebody standing in the dark at the end of a day's play.
    'background_color' => '#0d1420',
    'theme_color' => '#0d1420',
    'icons' => [
        [
            'src' => $assets . '/brand/icon-score.svg',
            'sizes' => 'any',
            'type' => 'image/svg+xml',
            'purpose' => 'any',
        ],
        [
            'src' => $assets . '/brand/apple-touch-icon.png',
            'sizes' => '180x180',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
