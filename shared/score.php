<?php

/**
 * The score and the clock, kept here rather than read from upstream.
 *
 *   { "rev": 12,
 *     "goals": [ {"num": 1, "home": true, "at": 1787420000}, ... ],
 *     "timer_start": 1787420000, "timer_paused_duration": 0,
 *     "timer_pause_start": 0, "half_at": null,
 *     "touched": 1787420600 }
 *
 * WHY THIS EXISTS AT ALL, GIVEN LIVE! ALREADY HAS A SCORE
 *
 * Latency. Live! serves a single game's payload with a flat 30-second cache
 * (`meta.cache_lifetime`), so a goal reaches an overlay somewhere between
 * instantly and half a minute late, and no amount of polite polling improves
 * that — the copy being polled is the stale one. For a scoreboard on air, half
 * a minute is the difference between a graphic that reports the game and one
 * that argues with it.
 *
 * A score entered here is on the overlay on the next poll of a file this
 * project owns, which is about a second. That is the whole reason.
 *
 * It also happens to be what standalone mode needs (`docs/STANDALONE.md`), and
 * what a scorekeeper with no signal needs (`docs/UPSTREAM.md`, shared ground) —
 * but the latency is the reason, and those two are why it is worth more than it
 * costs.
 *
 * A GOAL IS THE POINT IT CREATES
 *
 * Every goal carries `num`: the point number it completes, 1-based. Writing
 * "goal 10" twice stores one goal, because the second write says the same thing
 * as the first. That is not a nicety — it is what makes this safe to retry on a
 * flaky connection, safe for two people to press at once, and safe to replay
 * from a log later. `+1` has none of those properties: pressed twice it is a
 * real 2-0 from one point, and there is no way to tell afterwards.
 *
 * The rule is `docs/MATCHCONTROL.md` §4, and it is the reason the rest of that
 * document works.
 *
 * WHO MAY WRITE
 *
 * An administrator, or the holder of the code an administrator nominated. This
 * is possession's model rather than the line store's, and the difference
 * matters: `lines.php` and `notes.php` take unauthenticated writes because
 * nothing in them reaches a viewer, and the worst case of a guessed code is an
 * edited reference screen. **A score reaches air.** So the code here is one an
 * operator handed out on purpose, not one anybody may present.
 *
 * The nominated code lives in a separate `.private.json`, for the same reason
 * it does in `shared/possession.php`: the public file is polled as a static
 * asset by the overlay, and the thing that authorises writing must not be
 * served to everyone who can read the score.
 */

namespace Overlays;

final class Score
{
    /** Matches the line store, because a desk types one code for everything. */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';
    public const CODE_LENGTH = 5;

    /**
     * A guard, not a rule. Games are played to 15 and capped well below this;
     * anything approaching it is a stuck button rather than a long game.
     */
    private const MAX_GOALS = 200;

    private string $path;
    private string $codePath;
    private string $lockPath;

    public function __construct(private int $game, ?string $dir = null)
    {
        $base = $dir ?? __DIR__ . '/../conf';
        $this->path = $base . '/score-' . $game . '.json';
        $this->codePath = $base . '/score-' . $game . '.private.json';
        $this->lockPath = $base . '/score-' . $game . '.json.lock';
    }

    /** @return array{rev:int,goals:list<array{num:int,home:bool,at:int}>,timer_start:?int,timer_paused_duration:int,timer_pause_start:int,half_at:?int,code:?string,touched:int} */
    public static function empty(): array
    {
        return [
            'rev' => 0,
            // Whether the overlay takes its score from HERE rather than from
            // upstream. Off by default and switched on by an operator, because
            // it decides what reaches air: a game where somebody is diligently
            // pressing buttons that nothing reads is worse than one where
            // nobody is pressing anything, since the first looks like it is
            // working. `matchcontrol.php` says so on the phone in as many words.
            'enabled' => false,
            'goals' => [],
            // Timeouts, numbered per side for exactly the reason goals are:
            // "home's second timeout" written twice is one timeout, so the
            // press is safe to retry from a phone with no signal. UltiOrganizer
            // records these as game events and standalone records them nowhere
            // at all, which is why the allowance on air never moved.
            'timeouts' => [],
            // Mirrors UltiOrganizer's own three fields exactly, so a consumer
            // that already knows how to draw a clock needs no second rule:
            // elapsed = now - start - paused, less the running pause.
            'timer_start' => null,
            'timer_paused_duration' => 0,
            'timer_pause_start' => 0,
            'half_at' => null,
            'code' => null,
            'touched' => 0,
        ];
    }

    /** The public document, plus whichever code is nominated. */
    public function load(): array
    {
        $state = self::empty();
        if (is_file($this->path)) {
            $decoded = json_decode((string) file_get_contents($this->path), true);
            if (is_array($decoded)) {
                $state = self::clean($decoded);
            }
        }
        $state['code'] = $this->loadCode();

        return $state;
    }

    /** The score as two numbers, derived rather than stored. */
    public static function tally(array $state): array
    {
        $home = 0;
        $away = 0;
        foreach ($state['goals'] ?? [] as $goal) {
            if (!empty($goal['home'])) {
                $home += 1;
            } else {
                $away += 1;
            }
        }

        return ['home' => $home, 'away' => $away];
    }

    /**
     * How long ago a press may claim to have happened.
     *
     * Matches the possession store's own horizon. A press older than this is
     * not a scorekeeper catching up, it is a stale queue from another day.
     */
    private const MAX_BACKDATE = 43200;

    /**
     * When a press actually happened.
     *
     * The client sends the moment the button was pressed; this is the moment it
     * arrived. **They are not the same over a bad connection, and that gap is
     * the whole point of the outbox.** A goal pressed at 14:03 and delivered at
     * 14:09 belongs at 14:03 — and a CLOCK started at 14:03 and delivered at
     * 14:09 is worse than mis-filed, because `timer_start` is absolute: take the
     * arrival time and the clock reads six minutes short for the rest of the
     * game, on air.
     *
     * Trusting the caller here grants nothing new. Whoever holds the code can
     * already write any score they like; a time is less than that. It is bounded
     * only so an absurd value cannot break the clock arithmetic — a little into
     * the future for an unsynchronised phone, and not so far back that a queue
     * left over from yesterday replays into today's game.
     */
    private static function when(?int $claimed): int
    {
        $now = time();
        if ($claimed === null || $claimed <= 0) {
            return $now;
        }
        if ($claimed > $now + 300 || $claimed < $now - self::MAX_BACKDATE) {
            return $now;
        }

        return $claimed;
    }

    /**
     * Record a goal.
     *
     * `$num` is the point it completes. Omitted, it means "the next one", which
     * is what a button press means. Given explicitly, it is what makes a retry
     * safe: the same number twice is the same goal twice, and the second is not
     * an event.
     *
     * `$at` is when it was pressed, which is not when it arrived — see when().
     *
     * @return array{ok:bool,error:?string,state:array,applied:bool}
     */
    public function addGoal(bool $home, ?int $num = null, ?int $at = null): array
    {
        return $this->write(function (array $state) use ($home, $num, $at) {
            $next = count($state['goals']) + 1;
            $num = $num ?? $next;

            if ($num < 1 || $num > self::MAX_GOALS) {
                return ['error' => 'Point number out of range.'];
            }

            // Already recorded. Not an error: a retry, a double press, or two
            // people pressing for the same goal all arrive here, and all of
            // them mean the score is already what the caller wanted.
            foreach ($state['goals'] as $existing) {
                if ((int) $existing['num'] === $num) {
                    return ['state' => $state, 'applied' => false];
                }
            }

            // A gap would mean the log is missing a point, and guessing which
            // side scored it is exactly the sort of invention this project
            // refuses. The caller re-reads and tries again.
            if ($num !== $next) {
                return ['error' => 'Point ' . $next . ' has not been recorded yet.'];
            }

            $state['goals'][] = ['num' => $num, 'home' => $home, 'at' => self::when($at)];

            return ['state' => $state, 'applied' => true];
        });
    }

    /**
     * Record a timeout, or take one back.
     *
     * `$num` is which of that side's timeouts it is, 1-based — the same
     * discipline as a goal, and safe to retry for the same reason. Omitted, it
     * means "their next one", which is what a button press means.
     *
     * Numbered per side rather than across the game because that is how the
     * allowance is counted, and because two sides can call one at the same
     * break without either press having to know about the other.
     *
     * @return array{ok:bool,error:?string,state:array,applied:bool}
     */
    public function timeout(bool $home, ?int $num = null, ?int $at = null): array
    {
        return $this->write(function (array $state) use ($home, $num, $at) {
            $mine = array_values(array_filter(
                $state['timeouts'],
                static fn (array $t): bool => !empty($t['home']) === $home
            ));
            $next = count($mine) + 1;
            $num = $num ?? $next;

            // Generous: a pool allows two a half, and anything near this is a
            // stuck button rather than a long game.
            if ($num < 1 || $num > 20) {
                return ['error' => 'Timeout number out of range.'];
            }
            foreach ($mine as $existing) {
                if ((int) $existing['num'] === $num) {
                    return ['state' => $state, 'applied' => false];
                }
            }
            if ($num !== $next) {
                return ['error' => 'Timeout ' . $next . ' has not been recorded yet.'];
            }

            $state['timeouts'][] = ['num' => $num, 'home' => $home, 'at' => self::when($at)];

            return ['state' => $state, 'applied' => true];
        });
    }

    /** Take back a side's most recent timeout, and only that one. */
    public function undoTimeout(bool $home): array
    {
        return $this->write(function (array $state) use ($home) {
            for ($i = count($state['timeouts']) - 1; $i >= 0; $i--) {
                if (!empty($state['timeouts'][$i]['home']) === $home) {
                    array_splice($state['timeouts'], $i, 1);

                    return ['state' => $state, 'applied' => true];
                }
            }

            return ['state' => $state, 'applied' => false];
        });
    }

    /**
     * Take back the last goal, and only the last.
     *
     * Correcting the point being played changes a number nobody has read out.
     * Rewriting an earlier one silently contradicts something a commentator
     * already said, so it is not offered here — the same reasoning the
     * possession log uses for its own corrections.
     */
    public function undoGoal(?int $num = null): array
    {
        return $this->write(function (array $state) use ($num) {
            $count = count($state['goals']);
            if ($count === 0) {
                return ['state' => $state, 'applied' => false];
            }
            // Naming the point being undone makes this idempotent too: a retry
            // that arrives after the undo already happened is a no-op rather
            // than a second undo eating a good goal.
            if ($num !== null && $num !== $count) {
                return ['state' => $state, 'applied' => false];
            }
            array_pop($state['goals']);

            return ['state' => $state, 'applied' => true];
        });
    }

    /**
     * Start, pause, resume, or mark the break.
     *
     * The three timer fields are UltiOrganizer's, so anything that can already
     * draw a clock from a Live! payload can draw this one unchanged.
     */
    public function clock(string $action, ?int $at = null): array
    {
        return $this->write(function (array $state) use ($action, $at) {
            // When the button was pressed, not when it arrived. On a clock that
            // difference does not fade: `timer_start` is absolute, so a start
            // delivered late runs the whole game short by the delay.
            $now = self::when($at);
            switch ($action) {
                case 'start':
                    // Starting an already-running clock must not restart it: a
                    // second press is somebody checking, not somebody meaning
                    // to lose the first half of the game.
                    if ($state['timer_start'] === null) {
                        $state['timer_start'] = $now;
                        $state['timer_paused_duration'] = 0;
                        $state['timer_pause_start'] = 0;
                    } elseif ($state['timer_pause_start'] > 0) {
                        $state['timer_paused_duration'] += $now - $state['timer_pause_start'];
                        $state['timer_pause_start'] = 0;
                    }
                    break;
                case 'pause':
                    if ($state['timer_start'] !== null && $state['timer_pause_start'] === 0) {
                        $state['timer_pause_start'] = $now;
                    }
                    break;
                case 'half':
                    // Recorded rather than acted on. Halftime is a fact about
                    // the game that the gender ratio, the progression card and
                    // the timeout allowance all need, and UltiOrganizer does
                    // not record it distinctly either (docs/UPSTREAM.md).
                    $state['half_at'] = $state['half_at'] === null ? $now : null;
                    break;
                case 'reset':
                    $state['timer_start'] = null;
                    $state['timer_paused_duration'] = 0;
                    $state['timer_pause_start'] = 0;
                    break;
                default:
                    return ['error' => 'Unknown clock action.'];
            }

            return ['state' => $state, 'applied' => true];
        });
    }

    /**
     * Make this store the overlay's score for this game, or stop being it.
     *
     * An operator's decision rather than a scorekeeper's: it changes what a
     * viewer sees. Kept in the public document because the overlay reads it —
     * the whole point is that a scoreboard can tell, without asking anything
     * that costs a database.
     */
    public function setEnabled(bool $on): array
    {
        return $this->write(function (array $state) use ($on) {
            if ((bool) $state['enabled'] === $on) {
                return ['state' => $state, 'applied' => false];
            }
            $state['enabled'] = $on;

            return ['state' => $state, 'applied' => true];
        });
    }

    /**
     * A code to read out to a scorekeeper.
     *
     * The same shape and the same alphabet as the commentator room code, and
     * generated for the same reason: left to invent one, people pick "12345",
     * and this one authorises writing a score that reaches air.
     */
    public static function generate(): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }

    /** Nominate the code that may write here, or clear it. Admin only. */
    public function setCode(?string $code): bool
    {
        $code = $code === null ? null : strtoupper(trim($code));
        if ($code !== null && !self::isCode($code)) {
            return false;
        }
        if ($code === null) {
            return !is_file($this->codePath) || @unlink($this->codePath);
        }

        return @file_put_contents(
            $this->codePath,
            json_encode(['code' => $code], JSON_UNESCAPED_SLASHES),
            LOCK_EX
        ) !== false;
    }

    /** Whether this presented code is the nominated one. */
    public function canWrite(?string $presented): bool
    {
        $nominated = $this->loadCode();

        return $nominated !== null
            && $presented !== null
            && hash_equals($nominated, strtoupper(trim($presented)));
    }

    public static function isCode(string $code): bool
    {
        return strlen($code) === self::CODE_LENGTH
            && strspn($code, self::ALPHABET) === self::CODE_LENGTH;
    }

    public function isWritable(): bool
    {
        return is_writable(dirname($this->path));
    }

    private function loadCode(): ?string
    {
        if (!is_file($this->codePath)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($this->codePath), true);
        $code = is_array($decoded) ? ($decoded['code'] ?? null) : null;

        return is_string($code) && self::isCode($code) ? $code : null;
    }

    /**
     * Read, change, write, under one lock.
     *
     * The same shape every store here uses: a mutation that read a document,
     * decided, and wrote it back without holding a lock would lose one of two
     * simultaneous goals — which is precisely the case this is for.
     */
    private function write(callable $mutate): array
    {
        if (!$this->isWritable()) {
            return ['ok' => false, 'error' => 'conf/ is not writable.',
                'state' => $this->load(), 'applied' => false];
        }

        $handle = @fopen($this->lockPath, 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if ($handle !== false) {
                fclose($handle);
            }

            return ['ok' => false, 'error' => 'Could not lock the score.',
                'state' => $this->load(), 'applied' => false];
        }

        try {
            $state = $this->load();
            $result = $mutate($state);
            if (isset($result['error'])) {
                return ['ok' => false, 'error' => $result['error'],
                    'state' => $state, 'applied' => false];
            }

            $next = $result['state'];
            $applied = (bool) ($result['applied'] ?? false);
            if (!$applied) {
                // Nothing changed, so nothing is written and `rev` does not
                // move. A poller must not see a new revision for a no-op.
                return ['ok' => true, 'error' => null, 'state' => $next, 'applied' => false];
            }

            $next['rev'] = (int) $state['rev'] + 1;
            $next['touched'] = time();
            unset($next['code']);

            $tmp = $this->path . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, json_encode($next, JSON_UNESCAPED_SLASHES)) === false
                || !@rename($tmp, $this->path)) {
                @unlink($tmp);

                return ['ok' => false, 'error' => 'Could not write the score.',
                    'state' => $state, 'applied' => false];
            }

            $next['code'] = $this->loadCode();

            return ['ok' => true, 'error' => null, 'state' => $next, 'applied' => true];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Everything a stored document may contain, and nothing else. */
    private static function clean(array $raw): array
    {
        $state = self::empty();
        $state['rev'] = max(0, (int) ($raw['rev'] ?? 0));
        $state['enabled'] = (bool) ($raw['enabled'] ?? false);
        $state['touched'] = max(0, (int) ($raw['touched'] ?? 0));

        $goals = [];
        $seen = [];
        foreach (is_array($raw['goals'] ?? null) ? $raw['goals'] : [] as $goal) {
            if (!is_array($goal)) {
                continue;
            }
            $num = (int) ($goal['num'] ?? 0);
            // Numbers must be unique and contiguous from 1, or the tally and
            // the point number disagree and every consumer downstream inherits
            // the disagreement.
            if ($num !== count($goals) + 1 || isset($seen[$num]) || $num > self::MAX_GOALS) {
                continue;
            }
            $seen[$num] = true;
            $goals[] = [
                'num' => $num,
                'home' => (bool) ($goal['home'] ?? false),
                'at' => max(0, (int) ($goal['at'] ?? 0)),
            ];
        }
        $state['goals'] = $goals;

        // Same contiguity rule as goals, but PER SIDE: the allowance is counted
        // per side, so a gap in one team's numbering is what would put their
        // ticks out rather than the other's.
        $timeouts = [];
        $count = ['0' => 0, '1' => 0];
        foreach (is_array($raw['timeouts'] ?? null) ? $raw['timeouts'] : [] as $t) {
            if (!is_array($t)) {
                continue;
            }
            $home = !empty($t['home']);
            $key = $home ? '1' : '0';
            $num = (int) ($t['num'] ?? 0);
            if ($num !== $count[$key] + 1 || $num > 20) {
                continue;
            }
            $count[$key] = $num;
            $timeouts[] = [
                'num' => $num,
                'home' => $home,
                'at' => max(0, (int) ($t['at'] ?? 0)),
            ];
        }
        $state['timeouts'] = $timeouts;

        $start = $raw['timer_start'] ?? null;
        $state['timer_start'] = is_numeric($start) && (int) $start > 0 ? (int) $start : null;
        $state['timer_paused_duration'] = max(0, (int) ($raw['timer_paused_duration'] ?? 0));
        $state['timer_pause_start'] = max(0, (int) ($raw['timer_pause_start'] ?? 0));
        $half = $raw['half_at'] ?? null;
        $state['half_at'] = is_numeric($half) && (int) $half > 0 ? (int) $half : null;

        return $state;
    }
}
