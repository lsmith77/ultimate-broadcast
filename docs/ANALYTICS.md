# Counting visitors without collecting anything

How to get a sense of how many people open a public installation and how many run the demo, without adding tracking to software whose imprint page says it has none.

The short version: **read the web server's access log, which already exists, and add nothing to the site.** One command — [`../tools/stats.sh`](../tools/stats.sh) — pulls it from the host and reads it.

## 1. Why not analytics

The obvious answers were all rejected, and the reasons are worth keeping because they will be proposed again.

A third-party script — Plausible, Fathom, GoatCounter, Umami. Two problems, and the second is specific to this project. It puts a third-party request on every page, which the imprint page currently promises there is none of. And the pages it would go on include **the scoreboard and the stage, which are on air**. A graphics surface inside a switcher's browser must not phone anywhere: it is the one device nobody can debug, it is often on a venue network with no uplink to spare, and a blocked or slow request from a third-party host is a frame that does not render. Whatever counts visitors must be incapable of running on a broadcast surface, and a `<script>` in a shared header is exactly the opposite.

**A self-hosted counter in this project.** Genuinely viable — a cookieless endpoint with a daily-rotating salt is a well-understood design, it would fit the flat-file store pattern, and it would give better numbers than a log. It was not built because of what it costs: the imprint says, in bold, **"No accounts, no tracking, no analytics, no third-party requests."** That sentence is worth more than a more accurate number. Adding a counter means rewriting it, and a privacy claim that erodes one qualifier at a time is how privacy notices become lies. If the log numbers ever stop being enough, this is the thing to build — and the imprint is the first file to change, not the last.

**The access log.** It already exists, it is already disclosed on the imprint as the host's, and reading it adds nothing to what is collected. No cookie and no browser storage is touched, so the ePrivacy consent rule is never engaged and no banner is needed. Nothing new is retained: the log's retention is the host's, and the aggregate you keep afterwards is not personal data.

The cost is accuracy, and §4 says how much.

## 2. Running it

```
tools/stats.sh
```

[`../tools/stats.sh`](../tools/stats.sh) finds the access log on the host, streams it here, and reads it — one command and one SSH connection. **Nothing is written on the server and no log is stored here**: it is piped straight through, so the only thing that lands on this machine is the counts.

It takes the host from `deploy.env`, which already names it for `deploy.sh`. The alternative was a second setting holding the same hostname, and two places to change when it moves is how one of them ends up wrong. `deploy.env` is gitignored, so no hostname enters the repository this way either.

```
tools/stats.sh                       # everything the host still has
tools/stats.sh --json > counts.json  # the same numbers as a document
tools/stats.sh --file access.log     # a log already on this machine
tools/stats.sh --log-dir ~/weird/path # when a host puts logs somewhere odd
```

It prints which log files it chose, on stderr, so a wrong guess is visible rather than silent. If it finds nothing it says where it looked and how to find out:

```
find $HOME -maxdepth 3 -iname '*access*' -type f
```

**This works retroactively**, which is the quiet advantage of reading a log rather than installing a tracker: the numbers go back as far as the host still keeps logs, rather than starting from the day analytics were added. How far back is the host's retention policy, not ours.

The tool underneath can also be run directly, which is what `--file` does and what the check uses:

```
php tools/visitors.php access.log
gzip -cdf ~/logs/*.gz | php tools/visitors.php --json
```

It never runs on the server: `tools/` is excluded from `deploy.sh`, and `visitors.php` refuses to run under a web server.

What it prints:

```
  2026-09-07 to 2026-09-08   (2 days with traffic)

  Visitors                                 4
    ... who ran the demo                   3
  Page views                              11
    ... of the demo                        3

  By surface                          people   views
    Studio                                 3       4
    Scoreboard                             1       2
    Stage                                  2       2
    ...

  Read 18 lines: excluded 3 bot, 1 asset, 2 redirect or error, 1 unparsed.
```

**It never prints an address.** A visitor is a hash of the address and the browser string, salted with a value generated fresh on every run and discarded when the process exits — so the counts are reproducible, two runs share no hash, and the output is safe to paste into an issue or a report. The log is personal data; the output is not.

## 3. What makes the demo countable

Unusually, and by accident of a decision made for another reason.

`shared/demo.js` fetches **one** real payload and mutates copies of it in the browser. It does not poll. So a demo run leaves a page view and a single JSON read, and the page view carries `demo=1` in its own URL — a clean, countable event. A live game is the opposite: a stage, a scoreboard, a desk and a phone polling for an hour leave thousands of lines saying nothing about how many people were involved.

This is why "how many people ran the demo" is the one question a log answers *well* here, and it is the question worth asking, since the demo is what the site is for.

Two parsing details the tool handles, both of which would otherwise inflate the numbers:

- **A short URL is two log lines.** `/s/702` answers `302` and the `/app.php?view=scoreboard&game=702` it points at answers `200`. Only the `200` is counted. `app.php` explains at length why the redirect cannot be an internal rewrite.
- **The payload fetch is an asset.** Counting the JSON would double every demo run.

## 4. What the numbers are worth

Less than they look, and the limits are structural rather than fixable.

A "visitor" is one address-and-browser pair. A household, an office or a whole tournament venue behind one address counts once. A phone that moves between wifi and mobile data counts twice. Neither can be corrected without storing something about people, which is the thing not being done.

Cross-day unique people is not answerable privately, by anything. The tool reports distinct pairs over the period, but an address is reassigned, so the same person on Tuesday and Friday may be one or two. Privacy-preserving trackers rotate their salt daily precisely so that this question *cannot* be answered — it is a deliberate limitation there too, not an oversight here.

**Bots are the largest source of error.** [`../robots.txt`](../robots.txt) keeps the well-behaved crawlers off the pages, and `tools/visitors.php` excludes anything whose browser string says it is a bot. A crawler that says nothing is counted as a person, and there is no fixing that from a log. On a small public site this is the number most likely to be wrong.

So: read them as an order of magnitude and a trend. "Roughly forty people looked at it last month and about a dozen ran the demo" is a true and useful sentence. "Forty-three unique visitors" is not.

If a line ever stops parsing, the numbers go quiet rather than wrong — which is why the tool counts unparsed lines and warns when they exceed one in a hundred. A host that changes log format otherwise produces a convincing report of a week when nobody came.

## 5. robots.txt

[`../robots.txt`](../robots.txt) exists for the counts, and for something more important.

The commentary desk can display **prepared notes about named players**. Match control and the stage are live operational surfaces. None of those belongs in a search index. The room code is what actually keeps a note room private — robots.txt is not a security control, and a crawler that ignores it ignores this too — but a search engine indexing a note room is a foreseeable and preventable way for one to escape, and it costs one file to prevent.

It allows the Studio front page and the imprint, and disallows everything else.

**It only works standalone.** A crawler reads `robots.txt` from the domain root, which standalone is this directory. Hosted, the file sits at `/live/overlays/robots.txt` and is never fetched — the host's own root file is the one that counts, and a hosted installation should put the same rules there.

## 6. The check

[`../tests/visitors.mjs`](../tests/visitors.mjs), run by `npm run check`.

[`../fixtures/access-log-sample.log`](../fixtures/access-log-sample.log) is a log with a known answer, carrying one of each thing that has to be handled: a short URL answered `302` then `200`, a virtual host logged before the address, an IPv6 client, a stylesheet, two crawlers, and a line in no format at all. Every address in it is from the ranges reserved for documentation, so the fixture describes nobody.

It also checks that `tools/stats.sh` agrees with the tool it wraps, on a plain log and on a gzipped one — the half of the wrapper that can be tested without somebody's server is the reading, and a wrapper reporting different numbers from the thing under test is the failure that would matter.

The check asserts the counts, that every page view is classified to some surface — which is what catches a route added to `app.php` and not to the tool, whose symptom is otherwise "nobody visited the new page" — and that no address appears anywhere in the output. Each assertion was confirmed by breaking the tool and watching it fail.
