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
    public static function isDemo(): bool
    {
        if (!is_file(self::LOCAL_CONFIG)) {
            return false;
        }
        $config = require self::LOCAL_CONFIG;

        return is_array($config) && !empty($config['demo']);
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
