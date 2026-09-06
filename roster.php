<?php

/**
 * A team's squad, for a standalone installation that has nowhere else to keep
 * one.
 *
 *   GET  ?view=roster&team=300          -> {"players":[...],"rev":n,"admin":bool,"writable":bool}
 *   POST {"team":300,"add":[{"num":8,"name":"Ari Ace"}]}
 *   POST {"team":300,"remove":1001}
 *
 * **404 under a host, always.** Hosted, a squad belongs to UltiOrganizer: it is
 * registered, accredited, and the same list the scoresheet and the statistics
 * are built from. A second roster kept here would disagree with it silently, on
 * the surface that reaches air. So this door does not exist there at all —
 * `login.php` 404s hosted for the mirror-image reason.
 *
 * WRITES NEED THE ADMINISTRATOR SESSION
 *
 * Unlike `notes.php` and `lines.php`, which take unauthenticated writes because
 * the room code is a namespace and nothing in them reaches a viewer. A roster
 * does reach a viewer: the stage draws squad cards from it and the scoreboard's
 * derivations count from it. It is also a setup act rather than something done
 * during a game, so requiring the person who set the installation up is no
 * friction at the moment it matters.
 *
 * Reads are open, like every other read here. A roster is not a secret — it is
 * the list of people about to play in front of a camera.
 */

if (!defined('UO_ROUTED_VIEW')) {
    http_response_code(404);

    exit;
}

require_once __DIR__ . '/shared/auth.php';
require_once __DIR__ . '/shared/roster.php';

use Overlays\Auth;
use Overlays\Roster;

// Before anything else, including reads: under a host this endpoint is not a
// thing that exists.
if (Auth::isHosted()) {
    http_response_code(404);

    exit;
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, must-revalidate');

$isAdmin = Auth::isAdmin();

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$payload = [];
if ($isPost) {
    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['error' => 'Expected a JSON object.']);

        exit;
    }
}

$team = (int) ($isPost ? ($payload['team'] ?? 0) : (filter_input(INPUT_GET, 'team') ?? 0));
if ($team <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid team.']);

    exit;
}

$store = new Roster();

/** @return never */
function respond(Roster $store, int $team, bool $isAdmin, array $extra = []): void
{
    $state = $store->load($team);
    echo json_encode($extra + [
        'team' => $team,
        'players' => $state['players'],
        'rev' => $state['rev'],
        'admin' => $isAdmin,
        'writable' => $store->isWritable(),
    ], JSON_UNESCAPED_SLASHES);

    exit;
}

/** @return never */
function fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['error' => $message]);

    exit;
}

if (!$isPost) {
    respond($store, $team, $isAdmin);
}

if (!$isAdmin) {
    require_once __DIR__ . '/shared/mode.php';

    fail(403, 'Sign in at ' . \Overlays\Mode::loginUrl() . ' to change a squad.');
}

if (!$store->isWritable()) {
    fail(500, 'conf/ is not writable by the web server, so a squad cannot be saved.');
}

if (array_key_exists('remove', $payload)) {
    $playerId = (int) $payload['remove'];
    if ($playerId <= 0) {
        fail(400, 'Which player?');
    }
    $result = $store->remove($team, $playerId);
    if (!$result['ok']) {
        fail(500, (string) ($result['error'] ?? 'Could not write the roster.'));
    }
    respond($store, $team, $isAdmin, ['removed' => $result['removed']]);
}

if (!is_array($payload['add'] ?? null)) {
    fail(400, 'Expected "add" (a list of players) or "remove" (a player id).');
}

// Normalised here rather than in the store, so the store's contract stays "a
// list of {name, num}" whatever shape the caller had — a form sends one row, an
// import sends thirty.
$incoming = [];
foreach ($payload['add'] as $row) {
    if (is_string($row)) {
        $incoming[] = ['name' => $row, 'num' => null];
        continue;
    }
    if (!is_array($row)) {
        continue;
    }
    $incoming[] = [
        'name' => (string) ($row['name'] ?? ''),
        'num' => $row['num'] ?? null,
    ];
}
if ($incoming === []) {
    fail(400, 'No players in "add".');
}

$result = $store->add($team, $incoming);
if (!$result['ok']) {
    fail(400, (string) ($result['error'] ?? 'Could not write the roster.'));
}

respond($store, $team, $isAdmin, [
    'added' => $result['added'],
    // How many rows named somebody already on the squad. The import re-runs
    // this whenever anybody is unsure it worked, so "nothing happened" needs to
    // be reported as a fact rather than looking like a failure.
    'skipped' => $result['skipped'],
]);
