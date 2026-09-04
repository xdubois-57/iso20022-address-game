# CLAUDE.md

Instructions for Claude Code working in this repository. Read them before
touching anything; they encode decisions that are easy to undo by accident.

## Language: British English, everywhere

**Every word this project produces is British English.** Not a preference — a
project rule, and it applies to all of it:

- source code: identifiers, string literals, user-facing text
- comments and docblocks
- `README.md`, `DESIGN.md`, and everything under `docs/`
- commit messages and pull request descriptions
- release notes
- test names and assertion messages

So: *colour*, *behaviour*, *initialise*, *customise*, *recognise*,
*sanitise*, *organisation*, *licence* (the noun; *license* stays the verb),
*neutralise*, *centre* (the noun).

**Three exceptions, and only three.** These are identifiers from outside the
project and changing them breaks code:

1. **Language and platform APIs.** CSS `color`, `text-align: center`;
   JavaScript `scrollIntoView({ behavior, block: 'center' })`; PHP
   `serialize()`; Playwright `route.fulfill()`; Imagick `GRAVITY_CENTER`;
   Composer's `--optimize-autoloader`.
2. **This project's own established identifiers.** The theme keys
   `color_primary`, `color_primary_hover`, `color_primary_light`, `color_bg`,
   `color_text`, the CSS custom properties that mirror them, and helpers such
   as `ttfCentered()`. They are a stored schema and a public contract; renaming
   them would be a migration, not a spelling fix.
3. **Proper nouns.** *Payments Market Practice Group*, *GNU Affero General Public
   License*.

The rule of thumb: **if a machine reads it, leave it; if a person reads it,
British English.**

## Workflow

- **Every change goes on a branch with a pull request.** Never commit directly
  to `main`, not even a one-line fix, and regardless of what earlier history
  shows.
- **Nothing is pushed until all gates are green**, run locally first:
  ```bash
  composer test        # PHPUnit
  npm test             # Vitest
  npm run e2e          # Playwright / Chromium
  composer run analyse # PHPStan
  npm run typecheck    # tsc
  ```
  **Neither static analyser has a baseline.** Green means no findings at all,
  not no new ones. Do not regenerate one to get past your own change — see
  README § *Static analysis*.
- **Documentation moves with the behaviour it describes**, in the same commit.
  `README.md` and `DESIGN.md` are the reference documentation and are currently
  accurate — keep them that way.
- **Never touch `config/`, `storage/` or `uploads/`.** They are runtime
  directories, gitignored or holding secrets.
- Every new source file (PHP, JS, CSS) carries the AGPL v3 header used
  throughout the repository.

## Releasing

`./release.sh [patch|minor|major]` is the whole release. It tags, waits for
`.github/workflows/release.yml` to run every gate, attaches the deployable zip
to the draft that workflow creates, and publishes it. It deliberately does not
create a Release of its own — both would target the same tag and the workflow,
landing last, would turn a published Release back into a draft.

### The release note is not optional

`RELEASE_NOTES_FILE` must point at a Markdown file you wrote. Without it the
script warns and keeps GitHub's generated commit list, which says what was
touched and never what it means. An agent has the context to write the note and
no excuse not to.

**Write it for the person reading the Releases page, not for the person who
wrote the diff.** Required sections, in this order:

1. **What changes.** In the language of somebody using the game. If nothing is
   visible to a player, open by saying exactly that — it is useful information,
   not an apology.
2. **Bugs fixed.** Each one as *the symptom that went away*, not the patch.
   "Every Hall of Fame row read Invalid Date on an iPad", not "fixed date
   parsing". If a fix came from someone else's work, say so rather than
   absorbing it.
3. **Backward compatibility.** What an existing installation must do, or an
   explicit **"nothing"** — silence there is an oversight, not an answer. Call
   out anything that changes shape even when it needs no action: a changed
   asset URL, a stricter validation, a new server requirement.
4. **Tests.** Every suite with its count, and any gate that did not run.
5. **Verifying the release**, when the evidence pack matters to the reader:
   the `gh attestation verify` command, and what is inside the pack.

Put anything a reader must not miss — a licence change, a breaking change, a
security fix — **at the very top**, above everything else, with a warning
marker. Assume the reader stops after the first screen.

**Do not write a dependency list.** `scripts/dependency-inventory.php` appends
one automatically, with every version read from the lock files and every
licence beside it. Adding a CDN library means adding it to that script's
`CDN_LICENCES` map, or the inventory will mark it unknown.

Claims in the note are load-bearing: they are read by people deciding whether
to upgrade. Do not state a test count you have not seen, and do not claim a
behaviour is fixed unless a test covers it — v0.2.11 claimed an
impossible-date guard that turned out to have no test at all.

## Licensing

The project is **AGPL-3.0-or-later** since 2026-09-03, and its dependencies
were audited then: everything shipped is MIT or AGPL-3.0, the development
toolchain adds Apache-2.0, MPL-2.0 and BSD, and all of it is compatible in the
direction that matters. `scripts/dependency-inventory.php` prints the whole
picture with the reasoning, and it runs on every release.

Two things are **not** covered by the project's licence and must not be
described as if they were: the Liberation Sans fonts (SIL OFL 1.1) and the
world map background (CC BY-SA 3.0) keep their own terms, and the PMPG name
and logo are trademarks used with permission.

Adding a production dependency under a licence other than MIT, BSD, ISC or
Apache-2.0 is a decision to raise, not to make quietly.

## Where the reasoning lives

`DESIGN.md` is not a summary of the code — it records **why** things are as they
are: the PMPG branding rules, the client-authoritative scoring trade-off, the
theme reset that deletes rather than writes, the display modes. Read the
relevant section before changing behaviour it describes, and update it in the
same commit when you do.

`README.md` § *Static analysis* lists the SonarCloud findings that are
deliberately not fixed, with the argument for each. Do not "clean them up".
