# Releases

How a version of this project is cut, numbered and published. [`../CHANGELOG.md`](../CHANGELOG.md) is what the releases *are*; this is the procedure.

## 1. What a release is here

A git tag, the source archive GitHub builds from it, and an entry in the changelog. Nothing is compiled, minified or bundled, so there is no artefact to build: **the directory is the installation**, and the archive of a tag is that directory at a known point.

That makes a release useful to three different people:

| who | what the tag gives them |
|---|---|
| somebody installing it | a version to unzip into `live/overlays/`, or onto a domain, that will not change under them |
| somebody following the project | one page per release saying what changed, instead of 200 commits |
| whoever is running an installation | a name for what is deployed — `version.json` reports the tag as well as the commit |

## 2. Numbering

`0.MINOR.PATCH` while the project is pre-1.0.

- **Minor** — anything an operator, commentator or scorekeeper would notice: a new surface, a new control, a changed default, a removed feature.
- **Patch** — a fix to something already released, with no new behaviour.

**There is no 1.0 yet, and the bar for it is a promise rather than a feature list.** 1.0 means the URLs and the stored formats are stable: a browser source pointed at a scoreboard keeps working, and the JSON in `conf/` is read by later versions. Both still change today — the score store gained timeouts and a paused-duration field within a month — so a version number that implied otherwise would be a lie told to the one person who cannot check it, the operator with a broadcast on.

Until then, **read the changelog before upgrading a live installation**, and do not upgrade during an event.

## 3. Cutting one

Run from a clean checkout of `main`.

1. **Prove it.** `npm run check`, `npm run test:unit`, `npm run test:standalone`, and — if anything touched a store — `ADMIN_PASS=… npm test` against a real instance. Check the exit code, not the last line of output: piping a suite into `tail` reports the exit status of `tail`, which is how a red run was once read as green.
2. **Write the entry.** Move what is under `## Unreleased` in [`../CHANGELOG.md`](../CHANGELOG.md) into a new `## vX.Y.Z — YYYY-MM-DD` heading. Write for somebody who does not read the commits: what they can now do, what changed under them, and what is still missing.
3. **Commit it**, with the version in the subject.
4. **Tag it**, annotated, and push both:

   ```
   git tag -a v0.7.0 -m "v0.7.0"
   git push origin main --follow-tags
   ```

5. **Publish the notes**, so the tag has a page rather than a bare label:

   ```
   gh release create v0.7.0 --title "v0.7.0" --notes-file <(sed -n '/^## v0.7.0/,/^## v0.6/p' CHANGELOG.md)
   ```

6. **Deploy from the tag** if the public installation should run it (`./deploy.sh`), and check the site agrees:

   ```
   curl -s https://ultimate-broadcast.org/version.json
   ```

## 4. Which release is live

`deploy.sh` writes `version.json` into the tree it sends, and it carries `release` — `git describe`, so a deploy from a tagged commit reads `v0.7.0` and one from three commits later reads `v0.7.0-3-g9eca010`. The suffix is the point: it says *this is not a release*, which is the true and useful answer most of the time.

`dirty` remains the field that matters most. A deploy from a tree with uncommitted changes is not the commit it names, and no tag makes that less true. [`DEPLOY.md`](DEPLOY.md) §5.

## 5. What a release does not do

- **It does not upgrade an installation.** There is no updater and no phone-home. Somebody unzips an archive or runs `deploy.sh`.
- **It does not migrate `conf/`.** The stores are written by the code that reads them, and a format change that needs a migration has to say so in the changelog entry, in the imperative, as a step.
- **It does not reach Live! or UltiOrganizer.** Those are separate projects on separate release schedules. A version here says nothing about which Live! it was tested against; `README.md` names that.
