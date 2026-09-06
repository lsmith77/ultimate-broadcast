<?php

/**
 * Is this request allowed to change what is on air?
 *
 * Three endpoints ask that question — `show.php`, `possession.php` and
 * `colors.php` — and until now all three asked it of Live! directly, in the
 * same three lines: load `Api\ConfigManager`, then `Api\SeasonAccess`. Those
 * two classes were the *entire* PHP coupling between these overlays and their
 * host (`docs/STANDALONE.md` §2).
 *
 * This is that question, once, and it is the seam. Hosted, the answer comes
 * from Live! exactly as before. Standalone, it comes from a session this
 * project sets itself against a hash in `conf/`. The endpoints keep asking
 * "is this an admin" and never learn which regime they are under.
 *
 * WHY THE SESSION KEY IS COMPLICATED
 *
 * It is not, but it has to match. Live! keys its flag by season id and URL
 * prefix so that two installations sharing a domain cannot inherit each
 * other's login — a real fix in UltiOrganizer, not a precaution. The
 * standalone key follows the same shape for the same reason: one host can
 * serve several events, and a login to one must not open another.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO
 *
 * It does not decide what an admin may then do, and it is not a permission
 * system. Every endpoint keeps its own rules — `possession.php` still has its
 * two doors, where a room code authorises tracking without authorising the
 * air. This answers one boolean.
 */

namespace Overlays;

final class Auth
{
    /** Where a standalone installation keeps its password hash. */
    public const LOCAL_CONFIG = __DIR__ . '/../conf/local-config.php';

    /**
     * Hosted when Live! is present, standalone when it is not.
     *
     * Detected rather than configured, because a setting could disagree with
     * reality and the failure mode of guessing wrong is an auth check that
     * silently answers from the wrong place. The classes either exist or they
     * do not.
     */
    public static function isHosted(): bool
    {
        // The standalone front controller says so itself. That is not a setting
        // disagreeing with reality — it is the code that IS the standalone mode
        // reporting that it ran — and it is checked first because it stops the
        // look-around below happening at all on the deployment where looking
        // around is the dangerous thing to do.
        if (defined('OVERLAYS_STANDALONE')) {
            return false;
        }

        // Hosted, `..` is Live!'s own directory, so its autoloader and its entry
        // point are both in it. BOTH are required before anything is executed,
        // because standalone the directory above the installation belongs to
        // the host and has nothing to do with us: a real shared-hosting document
        // root turned out to have an unrelated `vendor/` sitting in it, and the
        // previous version of this would have required a stranger's autoloader
        // into this process on every single auth check.
        $parent = __DIR__ . '/../..';
        if (is_file($parent . '/vendor/autoload.php') && is_file($parent . '/api.php')) {
            require_once $parent . '/vendor/autoload.php';
        }

        return class_exists('\Api\SeasonAccess') && class_exists('\Api\ConfigManager');
    }

    /** Whether this request carries an administrator session. */
    public static function isAdmin(): bool
    {
        if (self::isHosted()) {
            $config = (new \Api\ConfigManager())->getConfig()['config'] ?? [];

            return \Api\SeasonAccess::isLiveAdminAuthenticated($config);
        }

        self::session();

        return isset($_SESSION[self::sessionKey()]) && $_SESSION[self::sessionKey()] === true;
    }

    /**
     * Standalone only: check a password and, if it matches, start the session.
     *
     * Constant-time through `password_verify`, and it answers the same way for
     * a wrong password and for an installation with no hash configured — an
     * unconfigured install must not be distinguishable from a wrong guess.
     */
    public static function attempt(string $password): bool
    {
        if (self::isHosted()) {
            return false;
        }

        $hash = self::hash();
        if ($hash === null || !password_verify($password, $hash)) {
            return false;
        }

        self::session();
        // A fresh id on privilege change, so a session fixed before the login
        // cannot be used after it.
        session_regenerate_id(true);
        $_SESSION[self::sessionKey()] = true;

        return true;
    }

    /** Standalone only: drop the administrator session, keeping the rest. */
    public static function logout(): void
    {
        if (self::isHosted()) {
            return;
        }
        self::session();
        unset($_SESSION[self::sessionKey()]);
    }

    /** Whether a standalone installation has a password set at all. */
    public static function isConfigured(): bool
    {
        return !self::isHosted() && self::hash() !== null;
    }

    /**
     * The bcrypt hash, or null.
     *
     * `conf/` is gitignored and denied over HTTP, which is why the hash lives
     * there rather than beside the code — the same reasoning that puts the
     * desk's prepared notes there.
     */
    private static function hash(): ?string
    {
        if (!is_file(self::LOCAL_CONFIG)) {
            return null;
        }
        $config = require self::LOCAL_CONFIG;
        $hash = is_array($config) ? ($config['admin_hash'] ?? null) : null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /**
     * One installation's key.
     *
     * Mirrors Live!'s shape: an event and a URL prefix, so two installations on
     * one domain cannot inherit each other's administrator session.
     */
    private static function sessionKey(): string
    {
        $config = is_file(self::LOCAL_CONFIG) ? require self::LOCAL_CONFIG : [];
        $event = is_array($config) ? (string) ($config['event'] ?? 'standalone') : 'standalone';

        // OVERLAYS_BASE_URL, not SCRIPT_NAME. The key has to be identical for
        // every request to one installation or a login stops being recognised
        // half the time — and SCRIPT_NAME is not that: PHP's built-in server
        // reports `/index.php` for a request to `/` and `/app.php` for a direct
        // hit, which would be two different keys for the same installation.
        $prefix = str_replace('/', 'x', defined('OVERLAYS_BASE_URL')
            ? (string) OVERLAYS_BASE_URL
            : '');

        return 'overlays_admin_' . $event . '_' . $prefix;
    }

    /**
     * Start a session unless one is already running, with the cookie locked
     * down before it is issued.
     *
     * These three attributes are the only thing standing between an
     * administrator's session and a cross-site request, because **none of the
     * write endpoints here carry a CSRF token**. They read a JSON body from
     * `php://input` and do not check `Content-Type`, so a cross-origin form
     * post with `enctype="text/plain"` can produce a body they will parse —
     * and if the cookie rode along, that would be a write.
     *
     * `SameSite=Lax` is what stops the cookie riding along: it is sent on
     * top-level navigation, which is what a login redirect needs, and not on a
     * cross-site POST, which is what an attack needs. Browsers have defaulted
     * to Lax for some years, so this was already true in practice — but it was
     * true because of somebody else's default rather than because of anything
     * here, and PHP's own `session.cookie_samesite` ships empty. Stated
     * explicitly, it survives a browser that has not caught up and a host with
     * different ini settings.
     *
     * `HttpOnly` keeps it out of reach of script, and `Secure` is set only on
     * HTTPS — set unconditionally it would break `php -S` at a venue, which is
     * a deployment this project actively recommends.
     */
    public static function begin(): void
    {
        if (session_status() !== PHP_SESSION_NONE || headers_sent()) {
            return;
        }

        $https = ($_SERVER['HTTPS'] ?? '') !== ''
            && strtolower((string) $_SERVER['HTTPS']) !== 'off';

        session_set_cookie_params([
            'lifetime' => 0,
            // Scoped to this installation, so two on one host do not hand each
            // other a cookie — the same reasoning as sessionKey().
            'path' => (defined('OVERLAYS_BASE_URL') && OVERLAYS_BASE_URL !== '')
                ? OVERLAYS_BASE_URL . '/'
                : '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $https,
        ]);
        session_start();
    }

    /** Start a session unless one is already running. */
    private static function session(): void
    {
        self::begin();
    }
}
