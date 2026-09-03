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

When Claude runs it, `RELEASE_NOTES_FILE` must point at a written release note.
See the script's header for what that note has to contain.

## Where the reasoning lives

`DESIGN.md` is not a summary of the code — it records **why** things are as they
are: the PMPG branding rules, the client-authoritative scoring trade-off, the
theme reset that deletes rather than writes, the display modes. Read the
relevant section before changing behaviour it describes, and update it in the
same commit when you do.

`README.md` § *Static analysis* lists the SonarCloud findings that are
deliberately not fixed, with the argument for each. Do not "clean them up".
