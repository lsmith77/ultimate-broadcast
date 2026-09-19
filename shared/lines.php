<?php
/**
 * Shared line selection for the commentary position.
 *
 * Who is on the field, per team, shared between the people calling the game:
 *
 *   { "teams": { "300": [3,7,12,18,22,24,31], "301": [1,4,9,11,15,20,27] },
 *     "touched": 1787420000 }
 *
 * A room is a GAME plus a CODE, and the code is a namespace rather than a
 * credential — it answers "which shared session am I in", not "am I allowed in".
 * That is acceptable here and nowhere else in this system because nothing on the
 * commentator page reaches a viewer: the worst case of a guessed code is that a
 * stranger edits a line selection on somebody's private reference screen. Show
 * state is admin-gated precisely because a bad write there changes what is on
 * air. The gate matches the consequence.
 *
 * Writes are therefore UNAUTHENTICATED, which is the only place in this project
 * that is true. Everything below is the cost of that: a fixed alphabet and
 * length for codes, a small payload cap, a cap on rooms per game with the oldest
 * pruned, and a write that stores nothing when it would change nothing.
 *
 * See docs/COMMENTATOR.md section 6.
 */

namespace Overlays;

final class Lines
{
    /**
     * Unambiguous alphabet: no O/0, no I/1/L. Codes get read aloud across a
     * commentary desk and typed on a phone, and a 5-character code from 30
     * symbols is still about 24 million rooms.
     */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';
    public const CODE_LENGTH = 5;

    /** A line is seven, but rosters get mis-tapped; this is a guard, not a rule. */
    private const MAX_PER_TEAM = 30;

    /**
     * Teams kept in one room.
     *
     * A game has two. This is not a data rule but a growth bound: `saveTeam()`
     * merges one team into whatever the room already holds, so without a cap an
     * unauthenticated caller can post team 1, team 2, team 3 … and grow a single
     * room's document without limit, one request at a time.
     */
    private const MAX_TEAMS_PER_ROOM = 8;

    /**
     * Points of line history kept per team.
     *
     * A game is forty points at the outside, so this is slack rather than a
     * limit anybody meets — and it exists for the same reason every other cap
     * in this file does: writes here are UNAUTHENTICATED, so anything that
     * grows has to stop growing somewhere. The oldest point goes first.
     */
    private const MAX_POINTS_PER_TEAM = 60;

    /** Rooms kept per game before the least recently touched are dropped. */
    private const MAX_ROOMS_PER_GAME = 40;

    /**
     * Total rooms kept across all games.
     *
     * MAX_ROOMS_PER_GAME bounds one game, and `prune()` only ever globs one
     * game's files — so on its own it bounds nothing: any positive integer is an
     * acceptable game id, and each one gets its own allowance of 40. This is the
     * bound that actually holds for the directory.
     */
    private const MAX_ROOMS_TOTAL = 400;

    /** A room untouched for this long is fair game for pruning. */
    private const STALE_SECONDS = 43200;   // 12 hours: longer than any game day

    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? __DIR__ . '/../conf/lines';
    }

    public static function isCode(string $code): bool
    {
        return strlen($code) === self::CODE_LENGTH
            && strspn($code, self::ALPHABET) === self::CODE_LENGTH;
    }

    /** Uppercase and map the characters people typically mistype. */
    public static function normalise(string $code): string
    {
        $code = strtoupper(trim($code));
        return strtr($code, ['O' => '0', '0' => '', 'I' => 'J', 'L' => 'J', '1' => '']);
    }

    public function generate(): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    }

    /**
     * @return array{teams: array<string,int[]>, points: array<string,array<string,int[]>>, touched: int}
     */
    public function load(int $gameId, string $code): array
    {
        $empty = ['teams' => (object) [], 'points' => (object) [], 'touched' => 0];
        $path = $this->pathFor($gameId, $code);
        if ($path === null || !is_readable($path)) {
            return $empty;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return $empty;
        }
        return [
            'teams' => self::cleanTeams($decoded['teams'] ?? []),
            'points' => self::cleanPoints($decoded['points'] ?? []),
            'touched' => (int) ($decoded['touched'] ?? 0),
        ];
    }

    /**
     * Replace one team's line.
     *
     * Deliberately per team, not per room: a commentary pair splits the teams,
     * so their writes touch disjoint data and cannot conflict. Where they do
     * overlap the last write wins — a commentator cannot stop to resolve a
     * conflict mid-point, and the thing at stake is a private reference panel.
     *
     * WITH A SCORE, IT ALSO RECORDS THE POINT.
     *
     * `$score` is the score the point started at — the same key the possession
     * store files its events under, so the two agree about what a point is.
     * Given one, this line is filed under it as well as replacing the current
     * selection, which is what makes playing time derivable afterwards.
     *
     * The desk's line deliberately CARRIES OVER between points (`resetFor()` in
     * commentator.php clears the injury prompt at a goal, not the line), because
     * substitutions are edited incrementally rather than seven players being
     * re-picked every point. So a point is recorded only where somebody said it
     * was this point's line — an edit during it, or the desk confirming the same
     * seven again. A snapshot taken at every goal regardless would copy the
     * previous point's line into points nobody was watching, and playing time
     * built on that over-counts exactly the players who were on earlier.
     *
     * @param  int[] $players
     * @return array{ok: bool, error: ?string, state: array}
     */
    public function saveTeam(
        int $gameId,
        string $code,
        int $teamId,
        array $players,
        ?string $score = null,
    ): array {
        $path = $this->pathFor($gameId, $code);
        if ($path === null || $gameId <= 0 || $teamId <= 0) {
            return ['ok' => false, 'error' => 'Bad room.', 'state' => $this->load($gameId, $code)];
        }

        $state = $this->load($gameId, $code);
        $teams = (array) $state['teams'];
        $points = (array) $state['points'];
        $clean = self::cleanPlayers($players);

        // An EMPTY line is not a point where nobody played -- it is a desk that
        // has not picked yet. Recorded, it would read as seven absences.
        $key = ($score !== null && $clean !== [] && self::isScoreKey($score)) ? $score : null;
        $mine = isset($points[(string) $teamId]) ? (array) $points[(string) $teamId] : [];

        /**
         * Skip a write that would change nothing, rather than one that arrives
         * too soon.
         *
         * This was a time-based throttle: refuse anything landing within 0.2s
         * of the last write and report success, on the reasoning that the next
         * poll carries the same state. It does not -- the state was never
         * stored, so the next poll REVERTS the change and the caller was told
         * it saved. `filemtime()` has one-second granularity, so the real
         * window was unpredictable up to a second rather than the 0.2s it
         * looked like.
         *
         * It cost a real edit: picking a player and immediately taking somebody
         * off injured is two writes in well under a second, and the second one
         * -- the substitution -- vanished from the room while the desk that
         * made it went on showing it. The identical bug was found and fixed in
         * the notes store (`shared/notes.php`); this is the same fix, and the
         * reason to state it twice is that one copy having it and the other not
         * is exactly how it survived.
         *
         * Comparing content drops exactly the writes worth dropping -- a poll
         * echoing a line back unchanged -- and never loses one that would have
         * changed anything.
         */
        // The history is part of "would this change anything": confirming an
        // unchanged line IS the write, and dropping it would make the desk's
        // "same line again" button do nothing at all.
        $sameLine = isset($teams[(string) $teamId]) && $teams[(string) $teamId] === $clean;
        $samePoint = $key === null || (isset($mine[$key]) && $mine[$key] === $clean);
        if ($sameLine && $samePoint) {
            return ['ok' => true, 'error' => null, 'state' => $state];
        }

        $teams[(string) $teamId] = $clean;

        if ($key !== null) {
            $mine[$key] = $clean;
            // Oldest first, which for an append-in-play-order map is insertion
            // order. A game cannot reach this, so it only bites a caller
            // inventing score keys -- which is the caller this cap is for.
            while (count($mine) > self::MAX_POINTS_PER_TEAM) {
                array_shift($mine);
            }
            $points[(string) $teamId] = $mine;
        }

        $next = [
            'teams' => self::cleanTeams($teams),
            'points' => self::cleanPoints($points),
            'touched' => time(),
        ];

        $this->prune($gameId);
        if (!$this->write($path, $next)) {
            return ['ok' => false, 'error' => 'Could not write the room.', 'state' => $state];
        }
        return ['ok' => true, 'error' => null, 'state' => $next];
    }

    public function isWritable(): bool
    {
        return is_dir($this->dir) ? is_writable($this->dir) : is_writable(dirname($this->dir));
    }

    /**
     * Drop the recorded points for a room, keeping the current lines.
     *
     * Two callers, and the second is the reason it is not merely nice to have.
     * A desk that recorded a game badly — wrong room, wrong game, half a match
     * of points confirmed by somebody leaning on a button — wants the playing
     * time gone rather than shown with a denominator that lies about it. And
     * the committed screenshots have to regenerate byte-identically, which
     * means the recipe must be able to put a room back exactly as it found it;
     * saves here are additive, so without this each run would render its own
     * history on top of the last one's.
     *
     * @return array{ok: bool, error: ?string, state: array}
     */
    public function clearPoints(int $gameId, string $code): array
    {
        $path = $this->pathFor($gameId, $code);
        if ($path === null) {
            return ['ok' => false, 'error' => 'Bad room.', 'state' => $this->load($gameId, $code)];
        }
        $state = $this->load($gameId, $code);
        // `(array)` first: an empty history loads as an empty OBJECT so that it
        // encodes as `{}` rather than `[]`, and `===` against a fresh object
        // compares identity, which is never true. It would have written the
        // file on every call -- harmless, and exactly the kind of "unchanged
        // write" the rest of this store goes out of its way to avoid.
        if ((array) $state['points'] === []) {
            return ['ok' => true, 'error' => null, 'state' => $state];
        }

        $next = ['teams' => $state['teams'], 'points' => [], 'touched' => time()];
        if (!$this->write($path, $next)) {
            return ['ok' => false, 'error' => 'Could not write the room.', 'state' => $state];
        }
        return ['ok' => true, 'error' => null, 'state' => $next];
    }

    // -- internals ----------------------------------------------------------

    private function pathFor(int $gameId, string $code): ?string
    {
        if ($gameId <= 0 || !self::isCode($code)) {
            return null;
        }
        // Both components are validated above, so the name cannot escape the
        // directory whatever arrives on the wire.
        return $this->dir . '/' . $gameId . '-' . $code . '.json';
    }

    /** @return array<string,int[]> */
    private static function cleanTeams(mixed $raw): array
    {
        if (!is_array($raw) && !is_object($raw)) {
            return [];
        }
        $clean = [];
        foreach ((array) $raw as $teamId => $players) {
            $id = (int) $teamId;
            if ($id <= 0 || !is_array($players)) {
                continue;
            }
            $clean[(string) $id] = self::cleanPlayers($players);
            if (count($clean) >= self::MAX_TEAMS_PER_ROOM) {
                break;
            }
        }
        return $clean;
    }

    /**
     * Which points each team has a recorded line for, without the lines.
     *
     * The cheap half of the history, for the poll that runs every two seconds.
     * Everything a desk needs at that cadence — is this point recorded, how
     * many are — is answerable from the keys, and the keys are a hundredth of
     * the size.
     *
     * @param  array<string,array<string,int[]>> $points
     * @return array<string,string[]>
     */
    public static function recordedKeys(mixed $points): array
    {
        $out = [];
        foreach ((array) $points as $teamId => $byScore) {
            $keys = array_keys((array) $byScore);
            if ($keys !== []) {
                $out[(string) $teamId] = $keys;
            }
        }

        return $out;
    }

    /**
     * A point key: the score the point started at, as "9-6".
     *
     * The same shape the possession store validates, and deliberately the same
     * convention — a point is identified by the score it began at, in both
     * stores, so a reader joining them cannot be off by one point.
     */
    public static function isScoreKey(string $value): bool
    {
        return preg_match('/^\d{1,3}-\d{1,3}$/', $value) === 1;
    }

    /**
     * The per-point history, validated on the way in and out.
     *
     * Read back through the same cleaner it is written through, because this
     * file is written by unauthenticated requests: a document edited by hand,
     * or written by an older version of this class, must not be able to put a
     * shape into a reader that the reader does not expect.
     *
     * @return array<string,array<string,int[]>>
     */
    private static function cleanPoints(mixed $raw): array
    {
        if (!is_array($raw) && !is_object($raw)) {
            return [];
        }
        $clean = [];
        foreach ((array) $raw as $teamId => $byScore) {
            $id = (int) $teamId;
            if ($id <= 0 || (!is_array($byScore) && !is_object($byScore))) {
                continue;
            }
            $points = [];
            foreach ((array) $byScore as $score => $players) {
                if (!is_string($score) || !self::isScoreKey($score) || !is_array($players)) {
                    continue;
                }
                $line = self::cleanPlayers($players);
                if ($line === []) {
                    continue;
                }
                $points[$score] = $line;
                if (count($points) >= self::MAX_POINTS_PER_TEAM) {
                    break;
                }
            }
            if ($points !== []) {
                $clean[(string) $id] = $points;
            }
            if (count($clean) >= self::MAX_TEAMS_PER_ROOM) {
                break;
            }
        }
        return $clean;
    }

    /** @return int[] */
    private static function cleanPlayers(array $raw): array
    {
        $out = [];
        foreach ($raw as $value) {
            $id = (int) $value;
            if ($id > 0 && !in_array($id, $out, true)) {
                $out[] = $id;
            }
            if (count($out) >= self::MAX_PER_TEAM) {
                break;
            }
        }
        return $out;
    }

    /**
     * Keep the room count bounded.
     *
     * Anyone can create a room by naming a code, so without this the directory
     * grows with every typo and every passer-by. Stale rooms go first, then the
     * least recently touched.
     *
     * Bounded twice, per game and overall. Per game alone is not a bound: the
     * game id is never checked against a real game — it cannot be, without
     * giving an unauthenticated endpoint a database lookup to do on demand — so
     * a caller walking `game=1, 2, 3 …` gets a fresh allowance every time.
     */
    private function prune(int $gameId): void
    {
        self::dropOldest(glob($this->dir . '/' . $gameId . '-*.json') ?: [], self::MAX_ROOMS_PER_GAME);
        self::dropOldest(glob($this->dir . '/*.json') ?: [], self::MAX_ROOMS_TOTAL);
    }

    /**
     * Drop stale files, then the least recently touched over the limit.
     *
     * @param string[] $files
     */
    private static function dropOldest(array $files, int $keep): void
    {
        $now = time();
        foreach ($files as $i => $file) {
            if (($now - (int) filemtime($file)) > self::STALE_SECONDS) {
                @unlink($file);
                unset($files[$i]);
            }
        }

        if (count($files) <= $keep) {
            return;
        }
        usort($files, static fn ($a, $b) => filemtime($a) <=> filemtime($b));
        foreach (array_slice($files, 0, count($files) - $keep) as $old) {
            @unlink($old);
        }
    }

    private function write(string $path, array $state): bool
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            return false;
        }
        $json = json_encode($state, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        // Same temp-file-and-rename as the other stores: a partner polling
        // through a save must never read half a document.
        $tmp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}
