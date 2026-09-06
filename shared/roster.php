<?php

/**
 * A team's squad, when nothing upstream is keeping one.
 *
 *   { "players": [ { "id": 30008, "num": 8, "name": "Ari Ace" } ],
 *     "rev": 3, "touched": 1788690000 }
 *
 * STANDALONE ONLY, AND THAT IS THE WHOLE POINT
 *
 * Hosted, a squad belongs to UltiOrganizer. It is registered, it is checked
 * against accreditation, and it is the same list the scoresheet and the
 * statistics are built from. Writing players here would create a second roster
 * that disagrees with the tournament's own, invisibly, on the surface that
 * reaches air — so `roster.php` answers 404 under a host and this class is
 * never reached.
 *
 * Standalone there is no such list. `install/make-event.php` writes an event
 * with named teams and **empty squads**, because a person typing a JSON file
 * should not also be typing forty names into it. The names arrive the way they
 * already do: the commentary desk exports a team's sheet, the team fills it in,
 * and the desk imports it back (`shared/bios.js`). Hosted, that import fills in
 * notes about players who already exist. Here it also creates them.
 *
 * One file, two doors, and no second format to learn.
 *
 * WHY IT IS A LIST AND NOT A MAP
 *
 * Order is roster order, which is what the desk shows and what a team expects
 * to see their sheet come back in. A map keyed by id would lose it, and sorting
 * by number does not recover it — squads have players with no number.
 *
 * WHY IDS ARE ASSIGNED HERE, NEVER REUSED, AND NAMESPACED BY TEAM
 *
 * A player id keys their prepared notes (`shared/notes.php`) and the line
 * selections that name them. Handing a departed player's id to a new one would
 * silently attach somebody's notes — pronouns, name pronunciation — to a
 * different person, on the desk, in front of somebody about to say it. So the
 * counter only ever climbs, including across deletions.
 *
 * And it must be unique across the whole EVENT, not just within a team, because
 * a notes room is shared by both sides of a game and is keyed by player id
 * alone: `players[1234]`. A per-team counter gave both squads a player 1, so the
 * two of them shared one note. The id is therefore `team × 10000 + counter`,
 * which keeps a team's ids to itself — adding somebody to one squad still cannot
 * move another's — while making a collision between squads impossible.
 */

namespace Overlays;

final class Roster
{
    /** A squad, generously. Past this something is wrong with the input. */
    public const MAX_PLAYERS = 120;

    /**
     * How far apart two teams' player ids are kept.
     *
     * Comfortably above MAX_PLAYERS, and above any plausible number of
     * add-then-remove cycles, because the counter never comes back down. A
     * squad that somehow exhausts it is refused rather than allowed to spill
     * into the next team's range, which would be the very collision this
     * exists to prevent.
     */
    private const STRIDE = 10000;

    /** Long enough for a full name with diacritics; short enough not to be a note. */
    public const MAX_NAME = 80;

    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? __DIR__ . '/../conf';
    }

    private function path(int $team): string
    {
        return $this->dir . '/roster-' . $team . '.json';
    }

    /**
     * @return array{players: list<array{id:int,num:?int,name:string}>, rev:int, touched:int}
     */
    public function load(int $team): array
    {
        $empty = ['players' => [], 'rev' => 0, 'touched' => 0, 'next' => 1];
        $path = $this->path($team);
        if (!is_file($path)) {
            return $empty;
        }
        $state = json_decode((string) @file_get_contents($path), true);
        if (!is_array($state) || !is_array($state['players'] ?? null)) {
            return $empty;
        }
        $state['rev'] = (int) ($state['rev'] ?? 0);
        $state['touched'] = (int) ($state['touched'] ?? 0);
        $state['next'] = (int) ($state['next'] ?? (count($state['players']) + 1));

        return $state;
    }

    /** What a page should see: the squad, without the bookkeeping. */
    public function players(int $team): array
    {
        return $this->load($team)['players'];
    }

    /**
     * Add players, ignoring the ones already here.
     *
     * Idempotent on **name**, which is the only identity an imported row has
     * before it has an id — so importing the same sheet twice adds nobody, and
     * a team returning a corrected sheet adds only what is new. That is the
     * same property the score store gets from numbering a goal by its point,
     * and it matters for the same reason: the import is a thing people re-run
     * when they are not sure it worked.
     *
     * A shirt number is not identity. Two players can wear 7 across a
     * tournament, numbers get corrected, and a squad may have none at all.
     *
     * @param list<array{name:string,num?:int|null}> $incoming
     * @return array{ok:bool,added:list<array>,skipped:int,error?:string,state:array}
     */
    public function add(int $team, array $incoming): array
    {
        return $this->withLock($this->path($team), function () use ($team, $incoming): array {
            $state = $this->load($team);
            $have = [];
            foreach ($state['players'] as $p) {
                $have[self::key((string) $p['name'])] = true;
            }

            $added = [];
            $skipped = 0;
            foreach ($incoming as $row) {
                $name = self::cleanName((string) ($row['name'] ?? ''));
                if ($name === '') {
                    $skipped += 1;
                    continue;
                }
                $key = self::key($name);
                if (isset($have[$key])) {
                    $skipped += 1;
                    continue;
                }
                if (count($state['players']) + count($added) >= self::MAX_PLAYERS) {
                    return ['ok' => false, 'added' => [], 'skipped' => $skipped,
                        'error' => 'A squad here is capped at ' . self::MAX_PLAYERS . ' players.',
                        'state' => $state];
                }
                $counter = $state['next'] + count($added);
                if ($counter >= self::STRIDE) {
                    return ['ok' => false, 'added' => [], 'skipped' => $skipped,
                        'error' => 'This team has exhausted its range of player ids.',
                        'state' => $state];
                }
                $num = $row['num'] ?? null;
                $added[] = [
                    'id' => $team * self::STRIDE + $counter,
                    'num' => is_numeric($num) ? (int) $num : null,
                    'name' => $name,
                ];
                $have[$key] = true;
            }

            if ($added === []) {
                return ['ok' => true, 'added' => [], 'skipped' => $skipped, 'state' => $state];
            }

            $state['players'] = array_merge($state['players'], $added);
            $state['next'] += count($added);
            $state['rev'] += 1;
            $state['touched'] = time();

            if (!$this->write($this->path($team), $state)) {
                return ['ok' => false, 'added' => [], 'skipped' => $skipped,
                    'error' => 'Could not write the roster.', 'state' => $this->load($team)];
            }

            return ['ok' => true, 'added' => $added, 'skipped' => $skipped, 'state' => $state];
        });
    }

    /**
     * Remove one player.
     *
     * Their id is not returned to the pool — see the note at the top of this
     * file. A squad list that a mistyped name is stuck in for ever would be
     * worse than the risk, but re-attaching notes is worse than both.
     */
    public function remove(int $team, int $playerId): array
    {
        return $this->withLock($this->path($team), function () use ($team, $playerId): array {
            $state = $this->load($team);
            $kept = array_values(array_filter(
                $state['players'],
                static fn (array $p): bool => (int) $p['id'] !== $playerId
            ));
            if (count($kept) === count($state['players'])) {
                return ['ok' => true, 'removed' => false, 'state' => $state];
            }
            $state['players'] = $kept;
            $state['rev'] += 1;
            $state['touched'] = time();
            if (!$this->write($this->path($team), $state)) {
                return ['ok' => false, 'removed' => false,
                    'error' => 'Could not write the roster.', 'state' => $this->load($team)];
            }

            return ['ok' => true, 'removed' => true, 'state' => $state];
        });
    }

    public function isWritable(): bool
    {
        return is_writable($this->dir);
    }

    /** Collapsed for comparison only; the stored name keeps its own spelling. */
    private static function key(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
    }

    private static function cleanName(string $name): string
    {
        // Control characters out, runs of space collapsed. A name arriving from
        // a spreadsheet routinely carries a non-breaking space or a stray tab.
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? $name;
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        return mb_substr($name, 0, self::MAX_NAME);
    }

    /** Serialise a read-modify-write, exactly as the other stores here do. */
    private function withLock(string $path, callable $body): mixed
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            return $body();
        }
        $handle = @fopen($path . '.lock', 'c');
        if ($handle === false) {
            return $body();
        }

        try {
            @flock($handle, LOCK_EX);

            return $body();
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    private function write(string $path, array $state): bool
    {
        $json = json_encode($state, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        // Temp-file-and-rename: a page reading through a save must never get
        // half a document.
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
