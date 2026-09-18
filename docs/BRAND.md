# The mark, the tab icons, and the social card

What the icons are, why they differ in shape rather than only in colour, and how to regenerate the two PNGs.

## 1. The mark

A stylised old-school movie camera on a disc. The disc does two jobs at once, which is why it is the constant: it is a **frisbee**, and it is the **film reel** an old camera carries on top. The rim inside the edge is what makes it read as a disc rather than as a generic round badge — without it the shape is just a coloured circle, which is every other icon in a tab strip.

The name is spelled out in [`../brand/lockup.svg`](../brand/lockup.svg) rather than reduced to a **UB** monogram. That was tried and measured: at 16 pixels the monogram is legible but says nothing about what the software is, and it costs the camera, which is the only part of the mark that does. Two letters and a camera do not both fit in a tab icon, and the camera is the half worth keeping.

## 2. One icon per surface

An operator has several of these open at once — the Studio, a stage, a scoreboard, the commentary desk, a phone — so "which tab is the scoreboard" is a question asked many times a day, and before this it was answered by reading titles.

| Surface | Glyph | Colour | |
|---|---|---|---|
| Studio | camera | `#0072B2` | blue |
| Scoreboard and stage | target | `#D55E00` | vermillion |
| Commentary desk | microphone | `#009E73` | bluish green |
| Match control | plus | `#3F4A56` | dark slate |

**Each surface differs in shape as well as colour**, and that is `AGENTS.md`'s rule about colour never being the only carrier of a distinction, applied where it is easiest to forget. A tab icon is sixteen pixels; at that size colour is most of what a person perceives, which is exactly why leaning on it alone fails hardest here. The glyphs are distinguishable with no colour information at all.

## 3. The colours were simulated, not chosen

The first three are from the [Okabe-Ito palette](https://jfly.uni-koeln.de/color/), which exists for this purpose. The fourth is not a hue, and that is the finding worth recording.

Match control began as Okabe-Ito's reddish purple `#CC79A7`. Rendered through protanopia and deuteranopia matrices it came out a **pale grey-green** — washed out against a light tab strip, and drifting toward the scoreboard's olive. A dark slate stays dark under every simulation, so it is distinguishable by lightness, which no form of colour vision deficiency takes away.

Two residual convergences, both acceptable because the glyphs carry the distinction:

- Under **deuteranopia**, the Studio's blue and the desk's green both read as purples. Camera versus microphone separates them.
- Under **tritanopia** (rare), the Studio and the desk both read as teal. Same answer.

Re-run the simulation before changing any of these. The method is a `feColorMatrix` per deficiency over the rendered tiles — the four icons at 64, 32 and 16 pixels, plus a mock tab strip, which is the size that actually matters.

## 4. The social card

[`../brand/social.png`](../brand/social.png), 1200×630, is what Facebook, LinkedIn and Slack show when somebody posts a link. It is referenced by `og:image` from the Studio only — a scoreboard and a stage are browser sources, nobody posts one, and a preview card on a page that goes to air is noise in the markup.

Two things that make a preview card come out blank, both handled in [`../shared/brand.php`](../shared/brand.php):

- **`og:image` must be an absolute URL.** The scraper has no page to resolve a relative one against. This is the commonest cause of an empty card.
- **It must not be an SVG.** None of the major scrapers render SVG. The SVG is the source; the PNG is generated from it and committed, because the project has no build step.

## 5. Regenerating the PNGs

`brand/social.svg` and `brand/icon-studio.svg` are the sources. After editing either:

```
node -e "
const {chromium}=require('./node_modules/@playwright/test');const {readFileSync}=require('node:fs');
const jobs=[['brand/social.svg','brand/social.png',1200,630],
            ['brand/icon-studio.svg','brand/apple-touch-icon.png',180,180]];
(async()=>{const b=await chromium.launch({channel:'chrome'});
for(const [src,out,w,h] of jobs){const p=await b.newPage({viewport:{width:w,height:h}});
await p.setContent('<style>html,body{margin:0}svg{display:block;width:'+w+'px;height:'+h+'px}</style>'+readFileSync(src,'utf8'));
await p.waitForTimeout(300);await p.screenshot({path:out});await p.close();console.log('wrote',out);}
await b.close();})();"
```

Chrome is already a dependency of the test suite, so this needs nothing new.

## 6. robots.txt

[`../robots.txt`](../robots.txt) is adjacent and covered by [`ANALYTICS.md`](ANALYTICS.md) §5. It matters most for keeping the commentary desk — which can display prepared notes about named players — out of a search index, and secondarily for keeping crawlers out of the visitor counts.

## 7. Where the mark appears in the UI, and where it must not

The icons are in the pages as well as in the tabs, and that is the point rather than decoration: **a tab icon only means something to somebody who has been taught it.** The same microphone in the commentary desk's own toolbar and in its tab is what makes that tab legible a week later without reading the title. A different graphic in each place would teach nothing.

The Studio does most of the teaching, because it is where somebody meets every other surface for the first time — it hands out the stage URL, the match control link and the commentary desk. Each of those links carries the icon of the surface it opens, so the association is made at the moment of clicking through.

| Where | Mark | Why |
|---|---|---|
| Studio header | camera | the page's own identity |
| Studio introduction | camera + **Ultimate Broadcast** | the project had no visible name anywhere: the tab said "Video overlays" and the heading said "Studio", so a visitor arriving from a posted link could read the whole introduction and still not know what to call it |
| Each game's URL, and the stage URL | target | the link opens a scoreboard or a stage |
| The Match control link | plus | the link opens match control |
| The commentary desk link | microphone | the link opens the desk |
| Commentary desk toolbar | microphone | in the toolbar, not the header — the header is a three-column layout naming each team over its own column, and anything added there breaks the proximity that does the naming |
| Match control header | plus | sixteen pixels, in the quietest part of a page that is otherwise two large buttons |
| Login, imprint, event editor | camera | chrome rather than surfaces, so they carry the project mark |
| `README.md` | the lockup | the project's front door on GitHub |
| **Scoreboard and stage** | **none** | **see below** |

**Nothing visible on the scoreboard or the stage.** Those are rendered to video. A mark there is this project's branding burned into somebody else's broadcast, sitting beside the tournament's own logo — which is the one that belongs on air, has a corner chosen for it in the Studio, and is kept clear of whatever else is in that corner. Their *tab* icons are set, because a tab is not on air, and that is the whole of it.

The wordmark in the introduction is real text rather than part of an image, so it can be selected, translated and read aloud.

Marks in the UI are decorative and carry `alt=""` with `aria-hidden="true"`: the text beside every one of them already names the surface, and a screen reader announcing "Studio" twice is worse than not announcing it at all. [`../shared/brand.php`](../shared/brand.php) is the one place that decides this, for the same reason the head tags are.

## 8. Where the files go

`brand/` is deployed: the icons and the card are requested by browsers and by link scrapers. It is copied into the standalone test tree by `tests/standalone-setup.js` — a new runtime directory has to be added there or every page in that suite requests an icon that is not present.
