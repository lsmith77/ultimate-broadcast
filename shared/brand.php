<?php

/**
 * The tab icon, and what a shared link looks like.
 *
 * ONE PLACE, because it is nine pages. Every surface here opens in its own tab
 * and an operator has several open at once — the Studio, a stage, a scoreboard,
 * the desk, a phone — so "which tab is the scoreboard" is a real question asked
 * many times a day, and it was answered by reading titles.
 *
 * WHY THE ICONS DIFFER IN SHAPE AND NOT ONLY IN COLOUR
 *
 * This is `AGENTS.md`'s rule about colour never being the only carrier of a
 * distinction, applied to the one place it is easiest to forget. A tab icon is
 * 16 pixels; at that size colour is most of what a person perceives, which is
 * exactly why leaning on it alone fails hardest here. So each surface has its
 * own GLYPH — a camera, a target, a microphone, a plus — and the colour is a
 * second cue rather than the only one.
 *
 * The four colours are from the Okabe-Ito palette, which is designed for colour
 * vision deficiency, except match control's, which is a dark slate. That is not
 * an oversight: the reddish purple it started as was simulated under protanopia
 * and deuteranopia and came out a pale grey-green, both washed out against a
 * light tab strip and drifting toward the scoreboard's olive. A dark neutral
 * stays dark under every simulation. See `docs/BRAND.md`.
 *
 * WHY THE SOCIAL CARD IS A PNG
 *
 * Facebook, LinkedIn and Slack do not render SVG for `og:image`. The SVG is the
 * source and `brand/social.png` is generated from it and committed, because the
 * project has no build step — see `docs/BRAND.md` for the regeneration command.
 */

namespace Overlays;

require_once __DIR__ . '/mode.php';

final class Brand
{
    /** Surface => the glyph its tab icon carries. Used by `docs/BRAND.md`. */
    public const ICONS = [
        'studio' => 'camera',
        'onair' => 'target',
        'desk' => 'microphone',
        'score' => 'plus',
    ];

    /**
     * The `<head>` tags for one surface.
     *
     * `$social` is passed only by pages somebody might share a link to. The
     * scoreboard and the stage are browser sources: nobody posts one, and a
     * preview card on a page that goes to air is noise in the markup.
     */
    public static function head(string $icon, string $base = '', ?array $social = null): string
    {
        $icon = isset(self::ICONS[$icon]) ? $icon : 'studio';
        $assets = Mode::assetBase($base);
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        $out = '<link rel="icon" type="image/svg+xml" href="' . $e("{$assets}/brand/icon-{$icon}.svg") . '">' . "\n"
            . '<link rel="apple-touch-icon" href="' . $e("{$assets}/brand/apple-touch-icon.png") . '">' . "\n";

        if ($social === null) {
            return $out;
        }

        // An absolute URL: og:image is fetched by a scraper that has no page to
        // resolve a relative one against, and a relative href here is the
        // commonest reason a preview card comes out blank.
        $scheme = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $origin = $host === '' ? '' : "{$scheme}://{$host}";

        $title = (string) ($social['title'] ?? 'Ultimate Broadcast');
        $description = (string) ($social['description'] ?? '');

        $out .= '<meta name="description" content="' . $e($description) . '">' . "\n"
            . '<meta property="og:type" content="website">' . "\n"
            . '<meta property="og:title" content="' . $e($title) . '">' . "\n"
            . '<meta property="og:description" content="' . $e($description) . '">' . "\n"
            . '<meta property="og:image" content="' . $e("{$origin}{$assets}/brand/social.png") . '">' . "\n"
            . '<meta property="og:image:width" content="1200">' . "\n"
            . '<meta property="og:image:height" content="630">' . "\n"
            . '<meta name="twitter:card" content="summary_large_image">' . "\n";

        return $out;
    }
}
