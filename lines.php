<?php
/**
 * Shared line-selection endpoint for the commentary position.
 *
 *   GET  ?view=live/overlays/lines&game=702&code=K7QM4
 *        -> {"teams":{"300":[...]},"recorded":{"300":["0-0","1-0"]},
 *            "touched":N,"writable":bool}
 *
 *   GET  ... &history=1
 *        -> the same, plus "points": the line each of those points was played
 *           with. Asked for separately because it is large and slow-moving
 *
 *   POST {"game":702,"code":"K7QM4","team":300,"players":[3,7,12],"score":"9-6"}
 *        -> replace one team's line, and with a score, record it as that
 *           point's line so playing time is derivable afterwards
 *
 *   POST {"game":702,"code":"K7QM4","clearPoints":true}
 *        -> forget the recorded points, keeping the current lines
 *
 * **Unauthenticated on purpose, and the only such write in this project.** The
 * code is a namespace rather than a credential; requiring the Live! admin
 * session here would hand broadcast control to someone who only wants to sync a
 * private reference panel with a colleague. Nothing this endpoint stores reaches
 * a viewer. See docs/COMMENTATOR.md section 6 and shared/lines.php for the guards
 * that come with that decision.
 */

if (!defined('UO_ROUTED_VIEW')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/shared/lines.php';
require_once __DIR__ . '/shared/mode.php';
require_once __DIR__ . '/shared/auth.php';

use Overlays\Auth;
use Overlays\Mode;

use Overlays\Lines;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, must-revalidate');

$store = new Lines();

/** @return never */
function fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
/**
 * A public demonstration does not take writes from strangers.
 *
 * This endpoint is unauthenticated on purpose — the room code is a namespace,
 * not a credential — and `docs/COMMENTATOR.md` makes the case. That trade holds
 * on a tournament network and does not hold on a public installation, where
 * anyone who guesses five characters can write into somebody else's room. See
 * `Overlays\Mode::isDemo()`.
 *
 * Reads are untouched, so every surface still demonstrates what it does.
 */
if ($isPost && Mode::isDemo() && !Auth::isAdmin()) {
    fail(403, 'This is a public demonstration, so it is read-only.');
}

$payload = [];

if ($isPost) {
    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        fail(400, 'Expected a JSON object.');
    }
    $gameId = (int) ($payload['game'] ?? 0);
    $code = strtoupper(trim((string) ($payload['code'] ?? '')));
} else {
    $gameId = (int) filter_input(INPUT_GET, 'game', FILTER_VALIDATE_INT);
    $code = strtoupper(trim((string) filter_input(INPUT_GET, 'code')));
}

if ($gameId <= 0) {
    fail(400, 'Missing or invalid game.');
}
if (!Lines::isCode($code)) {
    fail(400, 'A room code is ' . Lines::CODE_LENGTH . ' characters from ' . Lines::ALPHABET . '.');
}

if (!$isPost) {
    $state = $store->load($gameId, $code);
    $body = [
        'teams' => (object) $state['teams'],
        // WHICH points have been recorded, without the lines themselves.
        //
        // The desk polls this every two seconds for its partner's picks, and it
        // only needs two things from the history at that cadence: whether the
        // point being played has been recorded, and how many points have. Both
        // come from the keys. The lines are the bulk -- a busy room reaches tens
        // of kilobytes -- and they change once a point, so shipping them thirty
        // times a minute to every desk is a tournament's wifi spent on data
        // nobody read.
        'recorded' => (object) Lines::recordedKeys($state['points']),
        'touched' => $state['touched'],
        'writable' => $store->isWritable(),
    ];
    // The lines themselves, for playing time and crossovers. Asked for on a
    // slow timer rather than ridden along with the fast one.
    if (filter_input(INPUT_GET, 'history') === '1') {
        $body['points'] = (object) $state['points'];
    }
    echo json_encode($body);
    exit;
}

// Forgetting the recorded points, which is additive and therefore needs a way
// back. Handled before `team`, because it is about the room rather than a side.
if (!empty($payload['clearPoints'])) {
    $cleared = $store->clearPoints($gameId, $code);
    if (!$cleared['ok']) {
        fail(500, (string) $cleared['error']);
    }
    echo json_encode([
        'teams' => (object) $cleared['state']['teams'],
        'recorded' => (object) Lines::recordedKeys($cleared['state']['points']),
        'touched' => $cleared['state']['touched'],
        'writable' => true,
    ]);
    exit;
}

$teamId = (int) ($payload['team'] ?? 0);
if ($teamId <= 0) {
    fail(400, 'Missing team.');
}
if (!is_array($payload['players'] ?? null)) {
    fail(400, 'Expected {"players": [...]}.');
}

// Absent, or not a score key, means "just update the current line" -- every
// caller written before the history existed keeps working unchanged.
$score = is_string($payload['score'] ?? null) ? trim($payload['score']) : null;

$result = $store->saveTeam($gameId, $code, $teamId, $payload['players'], $score);
if (!$result['ok']) {
    fail(500, (string) $result['error']);
}

echo json_encode([
    'teams' => (object) $result['state']['teams'],
    'recorded' => (object) Lines::recordedKeys($result['state']['points']),
    'touched' => $result['state']['touched'],
    'writable' => true,
]);
