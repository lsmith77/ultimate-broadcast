<?php

/**
 * Where this installation's payloads come from.
 *
 * Hosted, the answer is always Live!. Standalone, it can be a **capture** — a
 * directory of recorded API responses written by `tests/capture.mjs` — which is
 * what lets these pages run with no UltiOrganizer, no database and no network
 * at all (`docs/STANDALONE.md` §8).
 *
 * WHY THIS IS CONFIGURED AND NOT A QUERY PARAMETER
 *
 * A `?capture=` parameter would be far more convenient and would also let
 * anyone who can load an overlay decide what that overlay shows. These pages
 * reach air. What is on air is admin-gated everywhere else in this project, and
 * a switch that silently swaps the whole data source is not the one place to
 * make an exception.
 *
 * So it lives in `conf/local-config.php`, beside the administrator hash, which
 * is gitignored and denied over HTTP.
 */

namespace Overlays;

final class Mode
{
    public const LOCAL_CONFIG = __DIR__ . '/../conf/local-config.php';

    /**
     * The URL prefix this directory is served under.
     *
     * Hosted, the overlays live at `<prefix>/live/overlays/` — a path every
     * page had hard-coded into its own asset helper, which is exactly the
     * assumption that breaks when the directory IS the document root. So it is
     * asked once, here.
     *
     * Standalone is detected from the front controller having defined itself,
     * rather than from `Auth::isHosted()`: this is a question about the URL
     * layout, and a host could in principle be present without serving these
     * pages at that path.
     */
    public static function assetBase(string $base = ''): string
    {
        if (defined('OVERLAYS_BASE_URL')) {
            // Derived by the front controller from this file's position under
            // the document root. Not from SCRIPT_NAME, which PHP's built-in
            // server reports as /index.php for a router-handled request.
            return (string) OVERLAYS_BASE_URL;
        }

        return rtrim($base, '/') . '/live/overlays';
    }

    /**
     * The URL of one of this project's own routed endpoints.
     *
     * Hosted, that is UltiOrganizer's front controller with a `live/overlays/`
     * view. Standalone, it is `app.php` with a bare one. Four pages had the
     * hosted spelling written into them — the line, note, possession, colour
     * and show endpoints — which is the same assumption the asset paths made
     * and breaks in the same place.
     */
    public static function viewUrl(string $view, string $base = ''): string
    {
        if (defined('OVERLAYS_SELF')) {
            return OVERLAYS_SELF . '?view=' . $view;
        }

        return rtrim($base, '/') . '/index.php?view=live/overlays/' . $view;
    }

    /**
     * Write `conf/local-config.php`.
     *
     * One writer, because there were three: `install/make-config.php` creating
     * it, `install/make-event.php --set-capture` and the event editor both
     * updating it. They had already drifted — only the editor invalidated the
     * compiled copy, so the same edit took effect at once through the page and
     * silently did nothing for a couple of seconds through the script.
     *
     * The invalidation is the part worth having in one place. This file is PHP,
     * so it is COMPILED and cached, and opcache revalidates a cached file only
     * every couple of seconds by default. Without the call, a write reports
     * success and the very next request still reads the old settings.
     *
     * Written 0640 and moved into place, so there is no moment at which the
     * password hash inside it is world-readable — on shared hosting that moment
     * has neighbours.
     */
    public static function saveLocalConfig(array $settings, ?string $path = null): bool
    {
        $path = $path ?? self::LOCAL_CONFIG;
        $export = static fn ($v): string => var_export($v, true);

        $body = "<?php\n\n"
            . "/**\n"
            . " * This installation's settings.\n"
            . " *\n"
            . " * Gitignored and denied over HTTP. `admin_hash` is what `Overlays\\Auth`\n"
            . " * checks a login against; `capture` is what `Overlays\\Mode` serves\n"
            . " * payloads from, and leaving it out means reading a live Live! instead.\n"
            . " */\n\n"
            . "return [\n";
        foreach ($settings as $key => $value) {
            $body .= '    ' . $export((string) $key) . ' => ' . $export($value) . ",\n";
        }
        $body .= "];\n";

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $tmp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $body) === false) {
            return false;
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        return true;
    }

    /**
     * Is this installation a public demonstration?
     *
     * `'demo' => true` in `conf/local-config.php`. It changes exactly one
     * thing, and it is not cosmetic: **the two stores that take unauthenticated
     * writes stop taking them.**
     *
     * `notes.php` and `lines.php` are open on purpose — the room code is a
     * namespace rather than a credential, so a commentator can join a room
     * without an operator being in the loop, and `docs/COMMENTATOR.md` makes
     * the case. On a tournament network that is a fair trade against the
     * friction it removes.
     *
     * On a public installation it is not. Anyone who guesses five characters
     * can type into the prepared notes, which are notes about named people and
     * the one store this project treats as sensitive. A demonstration wants
     * visitors to see every surface working; it does not want them writing to
     * one another's.
     *
     * An administrator still writes normally, so the person running the demo
     * can still set it up.
     */
    /**
     * The one code a demonstration publishes, for every store that takes one.
     *
     * A sync code and a scorekeeping code are a namespace and a lock a crew
     * agrees on at an event. A visitor has no crew, and every surface asking
     * them to invent or be given one is a demonstration that shows a locked
     * door. So on a demonstration there is exactly one, it is printed on the
     * screen that wants it, and it is already filled in.
     *
     * Safe precisely because `isDemo()` closes those stores to strangers: a
     * shared room nobody can write to cannot be scribbled in. The score store
     * is the deliberate exception — see `Score::loadCode()` — because a
     * scorekeeper's phone that cannot be pressed demonstrates nothing.
     *
     * Five characters from `Lines::ALPHABET`, which excludes I, L, O, U, 0
     * and 1: TRYME survives that, and says what to do with it.
     */
    public const DEMO_CODE = 'TRYME';

    /**
     * How long this installation keeps desk-authored data about people.
     *
     * Two cases, and they differ because of who owns the squad.
     *
     * **Hosted**, the squad is UltiOrganizer's — registered, accredited, the
     * list the scoresheet is built from — so `roster.php` 404s and nothing
     * here holds names. What this project adds on top is scaffolding for one
     * broadcast: prepared notes, pronouns, pronunciations, matchings. Those go
     * when they are done, and the tournament's own record is untouched.
     *
     * **Standalone** there is no upstream list, so the squad is ours too, and
     * the same rule has to reach it: names as well as matchings.
     *
     * **Which is wrong for one real person.** Somebody tracking their own club
     * on their own machine would re-import the team's CSV every week for ever,
     * which is not a privacy win — it is the same data typed again. So the
     * window is configurable, in days:
     *
     *     'retention_days' => 90,   // a season
     *     'retention_days' => 0,    // keep until deleted by hand
     *
     * **The default is seven days and it ships that way on purpose.** A
     * tournament is two or three; a week covers preparation either side of it
     * and forgets before the next one. Raising it is a deliberate act by
     * whoever runs the installation, about data describing named people — and
     * `0` means this installation never forgets them, which is a promise to
     * make on purpose rather than by leaving a field blank.
     */
    public const RETENTION_DAYS = 7;

    /** Seconds, or 0 for "keep indefinitely". */
    public static function retentionSeconds(): int
    {
        $days = self::RETENTION_DAYS;
        if (is_file(self::LOCAL_CONFIG)) {
            $config = require self::LOCAL_CONFIG;
            $raw = is_array($config) ? ($config['retention_days'] ?? null) : null;
            if ($raw !== null) {
                $days = (int) $raw;
            }
        }
        if ($days <= 0) {
            return 0;
        }

        // Ten years is not a policy, it is a guard against a typo that would
        // otherwise read as "for ever" without anybody choosing that.
        return min($days, 3650) * 86400;
    }

    public static function isDemo(): bool
    {
        if (!is_file(self::LOCAL_CONFIG)) {
            return false;
        }
        $config = require self::LOCAL_CONFIG;

        return is_array($config) && !empty($config['demo']);
    }

    /**
     * How big a run has to be before the stat strip says so.
     *
     * `'fact_thresholds' => ['run' => 4, 'cleanRun' => 5]` in
     * `conf/local-config.php`, merged over the defaults in
     * `shared/facts.js`. Only the keys that module knows are honoured, and the
     * module validates them again on the way in.
     *
     * Deliberately a config file rather than a control in the Studio. These are
     * a tournament's editorial taste — how much of a run is worth a line —
     * settled once for an event and the same for every game in it. Putting
     * eleven number boxes in front of an operator whose job is directing would
     * be eleven more things to get wrong during a broadcast, and the setting
     * they would actually reach for is the on/off switch, which IS in the
     * Studio.
     *
     * @return array<string,int>
     */
    public static function factThresholds(): array
    {
        if (!is_file(self::LOCAL_CONFIG)) {
            return [];
        }
        $config = require self::LOCAL_CONFIG;
        $raw = is_array($config) ? ($config['fact_thresholds'] ?? null) : null;
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $value) {
            // A threshold of zero makes its fact permanently true, which is a
            // strip saying something meaningless after every goal.
            if (is_string($key) && is_numeric($value) && (int) $value >= 1) {
                $out[$key] = (int) $value;
            }
        }

        return $out;
    }

    /**
     * Who is responsible for this installation.
     *
     * `'imprint' => ['Operator' => 'A Name', 'Address' => "…", 'Email' => '…']`
     * in `conf/local-config.php`. Free-form labels on purpose: what a site has
     * to state differs by country, and a fixed set of fields would be wrong
     * somewhere. The page prints what it is given, in the order it is given.
     *
     * This project cannot supply any of it — it is somebody's real name and
     * address — so an unconfigured installation says so plainly rather than
     * showing an empty page that looks like a bug.
     *
     * @return array<string,string>
     */
    public static function imprint(): array
    {
        if (!is_file(self::LOCAL_CONFIG)) {
            return [];
        }
        $config = require self::LOCAL_CONFIG;
        $imprint = is_array($config) ? ($config['imprint'] ?? null) : null;
        if (!is_array($imprint)) {
            return [];
        }

        $out = [];
        foreach ($imprint as $label => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $out[(string) $label] = trim((string) $value);
            }
        }

        return $out;
    }

    /**
     * Where a person goes to sign in.
     *
     * Hosted that is Live!'s own admin page, because Live! owns the session and
     * this project must not offer a second, weaker door beside it. Standalone
     * there is no Live!, so it is `login.php` — the page that 404s under a host
     * for exactly that reason.
     *
     * It is here rather than written into the Studio because the Studio was not
     * the only place that knew: three endpoints tell a refused caller where to
     * log in, and all four said `?view=live/admin`. Standalone that URL is a
     * 404, so the one affordance a read-only visitor is given was a dead link
     * — found on the first real standalone deployment.
     */
    public static function loginUrl(string $base = ''): string
    {
        if (defined('OVERLAYS_SELF')) {
            return self::viewUrl('login', $base);
        }

        return rtrim($base, '/') . '/index.php?view=live/admin';
    }

    /**
     * Whether signing in happens here rather than in Live!.
     *
     * The Studio needs this separately from the URL, because the words differ:
     * "Live! admin" is right under a host and simply wrong without one.
     */
    public static function ownsLogin(): bool
    {
        return defined('OVERLAYS_SELF');
    }

    /**
     * The URL a browser should read a capture from, or null for live.
     *
     * Returns a URL rather than a path because the reader is JavaScript: the
     * capture directory has to be reachable over HTTP, which means it must sit
     * where the server will serve it. `conf/` deliberately will not, so a
     * capture belongs somewhere like `fixtures/payloads/<name>`.
     */
    public static function captureBase(string $base = ''): ?string
    {
        if (!is_file(self::LOCAL_CONFIG)) {
            return null;
        }

        $config = require self::LOCAL_CONFIG;
        $capture = is_array($config) ? ($config['capture'] ?? null) : null;
        if (!is_string($capture) || $capture === '') {
            return null;
        }

        // An absolute URL is taken as given; anything else is relative to this
        // installation, so a config written once works under any URL prefix.
        if (preg_match('#^(https?:)?//#', $capture) === 1) {
            return $capture;
        }

        return self::assetBase($base) . '/' . ltrim($capture, '/');
    }
}
