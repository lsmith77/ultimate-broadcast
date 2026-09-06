<?php

/**
 * Score and clock endpoint.
 *
 *   GET  ?view=live/overlays/score&game=702[&code=ABCDE]
 *        -> {"rev":12,"goals":[...],"home":8,"away":6,"timer_start":...,
 *            "canWrite":bool,"admin":bool,"writable":bool}
 *
 *   POST {"game":702,"code":"ABCDE","goal":{"home":true,"num":9}}
 *   POST {"game":702,"code":"ABCDE","undo":{"num":9}}
 *   POST {"game":702,"code":"ABCDE","clock":"start|pause|half|reset"}
 *   POST {"game":702,"code":"ABCDE"}                 (admin only: nominate)
 *   POST {"game":702,"code":null}                    (admin only: revoke)
 *
 * WHY THE WRITE IS GATED HARDER THAN THE LINE STORE
 *
 * `lines.php` and `notes.php` accept unauthenticated writes because nothing in
 * them reaches a viewer. **This reaches air.** So a write needs either the
 * administrator session or the code an administrator nominated — possession's
 * model, and for the same reason.
 *
 * The nominated code is returned only to an administrator. A scorekeeper does
 * not need to be told what it is; they hold one already and only need to know
 * whether it is the one that counts, which `canWrite` answers for the code they
 * actually presented.
 *
 * WHY EVERY WRITE NAMES ITS POINT
 *
 * `goal.num` is the point the goal completes. Sending it makes the write safe
 * to retry: the same number twice stores one goal. A scorekeeper on a failing
 * connection retries constantly, which is the entire reason this endpoint can
 * be used at a pitch — see `docs/MATCHCONTROL.md` §4.
 */

if (!defined('UO_ROUTED_VIEW')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/shared/auth.php';
require_once __DIR__ . '/shared/score.php';

use Overlays\Auth;
use Overlays\Score;

header('Content-Type: application/json; charset=UTF-8');
// A scorekeeper must never act on a cached view of the score.
header('Cache-Control: no-store, must-revalidate');

$isAdmin = Auth::isAdmin();

$payload = [];
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($isPost) {
    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['error' => 'Expected a JSON object.']);
        exit;
    }
}

$game = (int) ($isPost ? ($payload['game'] ?? 0) : (filter_input(INPUT_GET, 'game') ?? 0));
if ($game <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid game.']);
    exit;
}

$presented = $isPost
    ? ($payload['code'] ?? null)
    : filter_input(INPUT_GET, 'code');
$presented = is_string($presented) ? strtoupper(trim($presented)) : null;

$store = new Score($game);

/** @return never */
function respond(Score $store, bool $isAdmin, ?string $presented, ?string $warning = null): void
{
    $state = $store->load();
    $tally = Score::tally($state);
    echo json_encode([
        'rev' => $state['rev'],
        'enabled' => $state['enabled'],
        'goals' => $state['goals'],
        'timeouts' => $state['timeouts'],
        'home' => $tally['home'],
        'away' => $tally['away'],
        'timer_start' => $state['timer_start'],
        'timer_paused_duration' => $state['timer_paused_duration'],
        'timer_pause_start' => $state['timer_pause_start'],
        'half_at' => $state['half_at'],
        // Only an administrator is told the code itself; everyone else is told
        // whether the one they presented is the one that counts.
        'code' => $isAdmin ? $state['code'] : null,
        'nominated' => $state['code'] !== null,
        'canWrite' => $isAdmin || $store->canWrite($presented),
        'admin' => $isAdmin,
        'writable' => $store->isWritable(),
        'warning' => $warning,
    ]);
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
    respond($store, $isAdmin, $presented);
}

// Nominating a code is an administrator's act, and it is the only write that
// does not need one already: it is how the first one is granted.
// Switching the source is an operator's decision about what reaches air, so it
// needs the administrator session rather than a scorekeeping code.
if (array_key_exists('enabled', $payload)) {
    if (!$isAdmin) {
        fail(403, 'Only an operator can choose where the scoreboard reads from.');
    }
    $result = $store->setEnabled((bool) $payload['enabled']);
    if (!$result['ok']) {
        fail(500, (string) $result['error']);
    }
    respond($store, $isAdmin, $presented);
}

$isAction = isset($payload['goal']) || isset($payload['undo']) || isset($payload['clock'])
    || isset($payload['timeout']);
if ($isAdmin && array_key_exists('code', $payload) && !$isAction) {
    $next = $payload['code'];
    // "new" asks for one to be generated, exactly as possession.php does — an
    // operator reading a code out should not have to invent it, because the
    // codes people invent are the ones people guess.
    if ($next === 'new') {
        $next = Score::generate();
    }
    if ($next !== null && (!is_string($next) || !Score::isCode(strtoupper(trim($next))))) {
        fail(400, 'A code is ' . Score::CODE_LENGTH . ' characters from ' . Score::ALPHABET . '.');
    }
    if (!$store->setCode($next === null ? null : strtoupper(trim($next)))) {
        fail(500, 'Could not store the code.');
    }
    respond($store, $isAdmin, $presented);
}

if (!$isAdmin && !$store->canWrite($presented)) {
    fail(403, $store->load()['code'] === null
        ? 'No code has been nominated for this game yet.'
        : 'That code cannot keep score for this game.');
}

$result = null;
// When the press happened, which over a bad connection is not when it arrived.
// The outbox sends it; a caller that does not is timed on arrival as before.
$at = array_key_exists('at', $payload) && is_numeric($payload['at'])
    ? (int) $payload['at']
    : null;

if (isset($payload['goal'])) {
    $goal = is_array($payload['goal']) ? $payload['goal'] : [];
    $num = array_key_exists('num', $goal) ? (int) $goal['num'] : null;
    $result = $store->addGoal(!empty($goal['home']), $num, $at);
} elseif (isset($payload['undo'])) {
    $undo = is_array($payload['undo']) ? $payload['undo'] : [];
    $result = $store->undoGoal(array_key_exists('num', $undo) ? (int) $undo['num'] : null);
} elseif (isset($payload['clock'])) {
    $result = $store->clock((string) $payload['clock'], $at);
} elseif (isset($payload['timeout'])) {
    // Recorded nowhere else. UltiOrganizer keeps timeouts as game events and a
    // standalone installation has none, so the allowance drawn on air never
    // moved — see docs/MATCHCONTROL.md.
    $t = is_array($payload['timeout']) ? $payload['timeout'] : [];
    $home = !empty($t['home']);
    $result = !empty($t['undo'])
        ? $store->undoTimeout($home)
        : $store->timeout($home, array_key_exists('num', $t) ? (int) $t['num'] : null, $at);
} else {
    fail(400, 'Expected one of goal, undo, clock or timeout.');
}

if (!$result['ok']) {
    // 409 rather than 500 for a refused point: the caller should re-read and
    // reapply rather than retry the same body, which is a different instruction.
    $conflict = str_contains((string) $result['error'], 'has not been recorded');
    fail($conflict ? 409 : 500, (string) $result['error']);
}

respond($store, $isAdmin, $presented, $result['applied'] ? null : 'Already recorded.');
