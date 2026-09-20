<?php

/**
 * How many people opened this installation, and how many ran the demo.
 *
 *   php tools/visitors.php access.log
 *   zcat ~/logs/*.gz | php tools/visitors.php
 *   php tools/visitors.php --json access.log > counts.json
 *
 * A DEVELOPMENT TOOL, run on a laptop against a log downloaded from the host.
 * It is excluded from `deploy.sh` and never runs on the server. Nothing about
 * this file changes what the software stores, which is the whole point of it —
 * see `docs/ANALYTICS.md`.
 *
 * WHY THE LOG AND NOT AN ANALYTICS SCRIPT
 *
 * The imprint page says, in bold, that this installation runs no analytics and
 * makes no third-party requests. That claim is worth more than a precise
 * number, and the web server's access log already exists and is already
 * disclosed there as the host's. Reading it adds nothing to what is collected,
 * touches no cookie or local storage — so the ePrivacy consent rule is never
 * engaged, and no banner is needed — and cannot possibly fire from a scoreboard
 * that is on air, which an injected script could.
 *
 * WHAT IT DELIBERATELY NEVER PRINTS
 *
 * An IP address. A visitor is counted as a hash of the address and the browser
 * string, salted with a value generated fresh on every run and thrown away when
 * the process ends. So two runs over the same log produce the same COUNTS and
 * no way to match a visitor in one against a visitor in the other, and the
 * output is safe to paste into an issue. The log itself is personal data; this
 * program's output is not.
 *
 * WHAT THE NUMBERS ARE WORTH
 *
 * "Visitors" means distinct address-and-browser pairs, which is an
 * approximation in both directions and cannot be made into anything better
 * without storing something about people. A household or an office behind one
 * address counts once. A phone moving between wifi and mobile data counts
 * twice. Read them as an order of magnitude and a trend, never as a headcount.
 * `docs/ANALYTICS.md` is blunter about this.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);

    exit;
}

// ---------------------------------------------------------------------------
// What counts as what.
// ---------------------------------------------------------------------------

/**
 * Requests that are not a person opening a page.
 *
 * The payloads matter here beyond tidiness: `?demo=1` fetches ONE real payload
 * and mutates copies of it in the browser, so a demo run is a page view plus a
 * single JSON read. Counting the JSON would double every demo.
 */
const ASSET = '#\.(css|js|mjs|map|json|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|eot|txt|xml)$#i';

/**
 * Surfaces, in the order they are reported.
 *
 * Both spellings of every route, because a standalone installation answers a
 * short URL with a REDIRECT to the long one — `/s/702` gives a 302 and then
 * `/app.php?view=scoreboard&game=702` gives the 200. Only the 200 is counted
 * (see below), so these patterns mostly match the long form; the short forms
 * are here for a deployment that rewrites internally instead.
 */
const SURFACES = [
    // `/` and `/s/` with ANY query string, because that is how a shared link
    // arrives: a front page reached from Facebook is `/?fbclid=…`, and the
    // end-of-string anchor sent every one of those to no surface at all — the
    // exact traffic this tool exists to notice.
    'Studio' => '#^/(?:$|\?|s/?(?:$|\?)|app\.php\?view=index)#',
    'Scoreboard' => '#^/(?:s/[0-9]+(?:/[0-9a-f]{6}|/green|/blue|/magenta|/black)?/?$|app\.php\?view=scoreboard)#i',
    'Stage' => '#^/(?:s/[0-9]+/overlay|s/field/[^/]+/overlay|app\.php\?view=stage)#',
    // Anchored on purpose. An unanchored `c/?[0-9]*` also matches `/conf/...`,
    // because every part after the `c` is optional — the kind of quietly wrong
    // pattern AGENTS.md warns about, found by checking rather than by reading.
    'Commentary desk' => '#^/(?:c(?:/[0-9]+)?/?$|app\.php\?view=commentator)#',
    // `/k/` with no game is the list of games on a phone, which is a page
    // somebody opened — and the one a home-screen icon lands on, so it is the
    // most-visited match control URL there is.
    'Match control' => '#^/(?:k(?:/[0-9]+)?/?(?:$|\?)|app\.php\?view=matchcontrol)#',
    // The spotter, /p/ with or without a game: it answers with no game too,
    // because a spotter can name a line and start before an event exists.
    'Spotter' => '#^/(?:p(?:/[0-9]+)?/?(?:$|\?)|app\.php\?view=spotter)#',
    'Event editor' => '#^/(?:s/event|app\.php\?view=event)#',
    'Imprint' => '#^/(?:s/imprint|app\.php\?view=imprint)#',
    'Self-test' => '#view=(?:live/overlays/)?tests/selftest#',
    // Not a surface anybody chose to look at, but a page all the same: it is
    // where a login lands, and counting it as nothing made the rows disagree
    // with the total.
    'Sign in' => '#view=(?:live/overlays/)?login#',
];

/**
 * The stores, which surfaces poll rather than people visiting.
 *
 * Every one of these is a page this project already counted asking a question
 * on a timer: possession and the score about once a second, show state the
 * same, lines every two, notes every fifteen, the game payload on the API's
 * own cache life. They are requests from a page that was already counted when
 * somebody opened it.
 *
 * Counting them as page views does not merely inflate the number, it swamps
 * it. A single stage left open overnight produced 27,782 possession polls
 * against about ninety real page loads, and the resulting "27,908 page views"
 * looked like a busy week rather than like one browser on a desk.
 *
 * They are excluded rather than reported per endpoint: this tool answers how
 * many people looked at something, and a poll says nothing about that. The
 * count is printed with the other exclusions so the figure can be checked.
 */
/**
 * The web app manifest, which a browser fetches rather than a person opening.
 *
 * It has no extension to be caught by ASSET — it is a routed view — so it
 * arrived as a page view thirteen times from one phone being added to a home
 * screen. Counted with the assets, where it belongs.
 */
const MANIFEST = '#[?&]view=(?:live/overlays/)?manifest(?:&|$)#';

const POLLS = '#[?&]view=(?:live/overlays/)?(?:possession|score|show|colors|lines|notes|roster|live/api)(?:&|$)#';

/**
 * Crawlers, scanners and monitors.
 *
 * Substring match on the browser string, lowercased. This list is the reason
 * the counts mean anything at all on a small public site, and it is also the
 * least trustworthy part of this program: a crawler that does not say so is
 * counted as a person, and there is no fixing that from a log. `robots.txt`
 * keeps the well-behaved ones off the pages; this keeps them out of the sums.
 */
const BOTS = [
    'bot', 'crawl', 'spider', 'slurp', 'search', 'scan', 'monitor', 'uptime',
    'curl', 'wget', 'python', 'java/', 'go-http', 'okhttp', 'axios', 'node-fetch',
    'headlesschrome', 'phantomjs', 'facebookexternalhit', 'preview', 'fetcher',
    'archiver', 'validator', 'lighthouse', 'pingdom', 'postman', 'insomnia',
    'semrush', 'ahrefs', 'mj12', 'dotbot', 'petalbot', 'bytespider', 'gptbot',
    'claudebot', 'ccbot', 'perplexity', 'applebot', 'amazonbot', 'dataprovider',
    'censys', 'expanse', 'internet-measurement', 'paloalto', 'zgrab', 'masscan',
];

// ---------------------------------------------------------------------------
// Arguments.
// ---------------------------------------------------------------------------

$files = [];
$asJson = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--json') {
        $asJson = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "usage: php tools/visitors.php [--json] [access.log ...]\n"
            . "       zcat ~/logs/*.gz | php tools/visitors.php\n\n"
            . "Reads an Apache access log and reports visitors and demo runs.\n"
            . "Reads standard input when given no files. See docs/ANALYTICS.md.\n");

        exit(0);
    } elseif (str_starts_with($arg, '-')) {
        fwrite(STDERR, "visitors: unknown option {$arg}\n");

        exit(2);
    } else {
        $files[] = $arg;
    }
}

// ---------------------------------------------------------------------------
// Counting.
// ---------------------------------------------------------------------------

/**
 * A per-run salt, so the hashes below cannot be matched against another run's.
 *
 * `random_bytes` rather than anything seeded: a predictable salt would let
 * somebody holding the same log reverse a hash by trying addresses, which is
 * the whole attack this is meant to foreclose.
 */
$salt = random_bytes(32);
$who = static fn (string $ip, string $ua): string => substr(hash('sha256', $salt . $ip . "\0" . $ua), 0, 16);

$people = [];            // visitor => true
$demoPeople = [];        // visitor => true
$perDay = [];            // day => ['people' => [], 'demos' => [], 'views' => n]
$perSurface = [];        // surface => ['people' => [], 'views' => n]
$views = 0;
$demoViews = 0;
$botLines = 0;
$assetLines = 0;
$otherLines = 0;         // redirects, errors, and anything not a page
$unparsed = 0;
$pollLines = 0;
$total = 0;

$handles = [];

if ($files === []) {
    $handles[] = ['-', STDIN];
} else {
    foreach ($files as $file) {
        if (!is_readable($file)) {
            fwrite(STDERR, "visitors: cannot read {$file}\n");

            exit(1);
        }

        // Hosts rotate logs gzipped. `gzopen` reads a plain file too, so it is
        // the one call for both — but zlib is not guaranteed, and falling back
        // is better than refusing to run.
        $handle = str_ends_with(strtolower($file), '.gz') && function_exists('gzopen')
            ? gzopen($file, 'rb')
            : fopen($file, 'rb');

        if ($handle === false) {
            fwrite(STDERR, "visitors: cannot open {$file}\n");

            exit(1);
        }

        $handles[] = [$file, $handle];
    }
}

foreach ($handles as [, $handle]) {
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        ++$total;

        /*
         * Parsed by STRUCTURE rather than by counting fields, because the field
         * count is not stable: some hosts log the virtual host in front of the
         * address, and AGENTS.md's warning about trusting your own regex was
         * written about exactly this kind of parsing. So: find the bracketed
         * date, find the quoted strings, and take the client as the first token
         * that is actually an IP address. A line that does not yield all three
         * is COUNTED as unparsed rather than skipped — a silent drop would
         * understate the traffic and look like a quiet week.
         */
        if (preg_match('/\[([^\]]+)\]/', $line, $when) !== 1) {
            ++$unparsed;

            continue;
        }

        if (preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $line, $quoted) < 1) {
            ++$unparsed;

            continue;
        }

        $ip = '';

        foreach (preg_split('/\s+/', $line, 6) ?: [] as $token) {
            if (filter_var($token, FILTER_VALIDATE_IP) !== false) {
                $ip = $token;

                break;
            }
        }

        if ($ip === '') {
            ++$unparsed;

            continue;
        }

        $request = $quoted[1][0];
        $agent = end($quoted[1]);
        $agent = $agent === $request ? '' : (string) $agent;

        // The status is the token immediately after the closing quote of the
        // request, which is the one field position that IS stable.
        $status = preg_match('/"\s+(\d{3})\s/', $line, $code) === 1 ? (int) $code[1] : 0;

        $day = substr($when[1], 0, 11);   // 07/Sep/2026
        $date = \DateTimeImmutable::createFromFormat('d/M/Y', $day);
        $day = $date instanceof \DateTimeImmutable ? $date->format('Y-m-d') : $day;

        $lowerAgent = strtolower($agent);
        $isBot = $agent === '' || $agent === '-';

        if (!$isBot) {
            foreach (BOTS as $needle) {
                if (str_contains($lowerAgent, $needle)) {
                    $isBot = true;

                    break;
                }
            }
        }

        if ($isBot) {
            ++$botLines;

            continue;
        }

        // "GET /s/702?demo=1 HTTP/1.1"
        $parts = preg_split('/\s+/', $request);
        $method = $parts[0] ?? '';
        $url = $parts[1] ?? '';

        if ($method !== 'GET' || $url === '') {
            ++$otherLines;

            continue;
        }

        if (preg_match(ASSET, (string) parse_url($url, PHP_URL_PATH)) === 1
            || preg_match(MANIFEST, $url) === 1) {
            ++$assetLines;

            continue;
        }

        if (preg_match(POLLS, $url) === 1) {
            ++$pollLines;

            continue;
        }

        /*
         * Only a 200 is a page somebody looked at. This is what stops a short
         * URL being counted twice: standalone, `/s/702` answers 302 and the
         * `/app.php?view=scoreboard&game=702` it points at answers 200.
         */
        if ($status !== 200) {
            ++$otherLines;

            continue;
        }

        $visitor = $who($ip, $agent);
        $isDemo = preg_match('/[?&]demo=1(?:&|$)/', $url) === 1;

        $people[$visitor] = true;
        ++$views;

        $perDay[$day] ??= ['people' => [], 'demos' => [], 'views' => 0];
        $perDay[$day]['people'][$visitor] = true;
        ++$perDay[$day]['views'];

        if ($isDemo) {
            $demoPeople[$visitor] = true;
            $perDay[$day]['demos'][$visitor] = true;
            ++$demoViews;
        }

        foreach (SURFACES as $name => $pattern) {
            if (preg_match($pattern, $url) === 1) {
                $perSurface[$name] ??= ['people' => [], 'views' => 0];
                $perSurface[$name]['people'][$visitor] = true;
                ++$perSurface[$name]['views'];

                break;
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Reporting.
// ---------------------------------------------------------------------------

ksort($perDay);
$days = array_keys($perDay);
$first = $days[0] ?? '—';
$last = $days === [] ? '—' : $days[count($days) - 1];

if ($asJson) {
    $out = [
        'from' => $first,
        'to' => $last,
        'days' => count($days),
        'visitors' => count($people),
        'demo_visitors' => count($demoPeople),
        'page_views' => $views,
        'demo_views' => $demoViews,
        'surfaces' => [],
        'per_day' => [],
        'excluded' => [
            'bot_requests' => $botLines,
            'assets' => $assetLines,
            'redirects_and_errors' => $otherLines,
            'polls' => $pollLines,
            'unparsed' => $unparsed,
            'lines_read' => $total,
        ],
    ];

    foreach (SURFACES as $name => $_) {
        if (isset($perSurface[$name])) {
            $out['surfaces'][$name] = [
                'visitors' => count($perSurface[$name]['people']),
                'views' => $perSurface[$name]['views'],
            ];
        }
    }

    foreach ($perDay as $day => $d) {
        $out['per_day'][$day] = [
            'visitors' => count($d['people']),
            'demo_visitors' => count($d['demos']),
            'views' => $d['views'],
        ];
    }

    fwrite(STDOUT, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    exit(0);
}

/**
 * Nothing read is not nobody visiting.
 *
 * An empty input produced a full report of zeroes — "0 visitors, 0 page views"
 * over a blank date range — which reads exactly like a quiet week and is in
 * fact a missing file, an unreadable one, or a wrapper whose remote half found
 * no log. That is the failure this project refuses everywhere else: a surface
 * stating something it does not know.
 *
 * So a run that read no lines says so and stops. The JSON path above is
 * deliberately left alone: a caller asking for JSON is a program that can see
 * `lines_read: 0` for itself, and zeroed fields are the honest answer in a
 * data format.
 */
if ($total === 0) {
    fwrite(STDERR, "\nvisitors: read no log lines, so there is nothing to report.\n"
        . "visitors: an empty report would say \"no visitors\", which is a different\n"
        . "          claim from \"no data\". Check the file, or the path given to\n"
        . "          tools/stats.sh with --log-dir.\n");

    exit(3);
}

$n = static fn (int $v): string => number_format($v);

printf("\n  %s to %s   (%d %s with traffic)\n\n", $first, $last, count($days), count($days) === 1 ? 'day' : 'days');
printf("  %-34s %7s\n", 'Visitors', $n(count($people)));
printf("  %-34s %7s\n", '  ... who ran the demo', $n(count($demoPeople)));
printf("  %-34s %7s\n", 'Page views', $n($views));
printf("  %-34s %7s\n", '  ... of the demo', $n($demoViews));

$classified = 0;

foreach ($perSurface as $s) {
    $classified += $s['views'];
}

if ($perSurface !== []) {
    printf("\n  %-34s %7s %7s\n", 'By surface', 'people', 'views');

    foreach (SURFACES as $name => $_) {
        if (isset($perSurface[$name])) {
            printf("  %-34s %7s %7s\n", '  ' . $name, $n(count($perSurface[$name]['people'])), $n($perSurface[$name]['views']));
        }
    }
}

/**
 * Views that matched no surface, said out loud.
 *
 * `tests/visitors.mjs` asserts the rows add up to the total, but only against
 * the fixture — and the gap this is for appears in real logs, where no test
 * runs. A route added to app.php and not to SURFACES showed up in the total and
 * in no row, which reads as "nobody visited the new page" rather than as a
 * reader that does not know about it. Every front page reached from a shared
 * link sat in that gap, because the pattern anchored `/` at end of string.
 *
 * Outside the table on purpose: a log whose views ALL matched nothing would
 * otherwise print no table and no warning, which is the worst version of it.
 */
if ($views > $classified) {
    printf("\n  %-34s %7s %7s\n", 'Unclassified views', '', $n($views - $classified));
    fwrite(STDERR, "\n  NOTE: " . $n($views - $classified) . " page views matched no surface.\n"
        . "        A route is missing from SURFACES in tools/visitors.php.\n");
}

if (count($perDay) > 1) {
    $busiest = $perDay;
    uasort($busiest, static fn (array $a, array $b): int => count($b['people']) <=> count($a['people']));
    printf("\n  %-34s %7s %7s\n", 'Busiest days', 'people', 'demos');

    foreach (array_slice($busiest, 0, 7, true) as $day => $d) {
        printf("  %-34s %7s %7s\n", '  ' . $day, $n(count($d['people'])), $n(count($d['demos'])));
    }
}

printf(
    "\n  Read %s lines: excluded %s bot, %s asset, %s poll, %s redirect or error,\n"
    . "  %s unparsed.\n",
    $n($total),
    $n($botLines),
    $n($assetLines),
    $n($pollLines),
    $n($otherLines),
    $n($unparsed)
);

if ($unparsed > 0 && $unparsed > $total / 100) {
    fwrite(STDERR, "\n  WARNING: " . $n($unparsed) . " lines did not parse, which is more than one in a\n"
        . "  hundred. The log is probably not in Apache's combined format, and\n"
        . "  every number above is understated. Check the format before trusting it.\n");
}

printf("\n  A visitor is one address-and-browser pair: an office counts once, a phone\n");
printf("  that changed network counts twice. An order of magnitude, not a headcount.\n\n");
