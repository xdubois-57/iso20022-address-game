<!--
ISO 20022 Address Structuring Game
Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
along with this program. If not, see <https://www.gnu.org/licenses/>.
-->

# Specifications — ISO 20022 Address Structuring Game

## 1. About this document

### 1.1 Purpose

This document states **what** the system does and what qualities it must hold.
It is written to be read on its own, by somebody who has not seen the code.

Three documents divide the work between them, and each answers a different
question:

| Document | Question it answers |
|---|---|
| `README.md` | *How do I install, run and deploy it?* |
| **`SPECIFICATIONS.md`** (this) | *What must it do, and how well?* |
| `DESIGN.md` | *Why is it built the way it is?* |

If a statement here disagrees with the code, the code is not automatically
right — one of the two is a defect, and which one is a judgement call.

### 1.2 Conventions

Requirements are numbered and stated with **shall**. Each is intended to be
atomic and checkable. `FR-` prefixes functional requirements, `NFR-`
non-functional ones.

A **Verified by** line names what actually checks the requirement. Where it
says *not automated*, that is a statement of fact rather than an aspiration.

### 1.3 Scope

In scope: the game as played, the screens it runs on, the administration of its
content, and the handling of the personal data it collects.

Out of scope: the hosting arrangement, the content of the address scenarios
themselves (supplied by an administrator), and any integration with a real
payments system. This is a teaching tool; it processes no payment and connects
to no financial network.

## 2. Definitions

| Term | Meaning |
|---|---|
| **Round** | One address, presented as draggable chips to be placed into ISO 20022 slots. |
| **Game** | A fixed sequence of rounds played in one sitting, ending on a score. |
| **Chip** | A single address component the player drags — a street name, a town, a country. |
| **Slot** | A labelled target a chip is dropped into, corresponding to an ISO 20022 field. |
| **Structured mode** | Every component goes into its own named field. |
| **Hybrid mode** | Town and country are named fields; the rest is grouped into two free address lines. |
| **Game score** | The composite figure the Hall of Fame ranks by. Distinct from the round percentage. |
| **The wall** | A large unattended screen showing the Hall of Fame, reached with `?mode=hof`. |
| **The play station** | A large screen for standing play, reached with `?mode=play`. |
| **Display mode token** | The random value both dedicated screen URLs must carry. |
| **Screen saver** | The idle overlay shown in kiosk mode after inactivity. |

## 3. Actors

| Actor | Description | Authenticated |
|---|---|---|
| **Player** | Anyone who reaches the game and plays it. | No — deliberately. |
| **Administrator** | Manages scenarios, facts, theme, deadline and display settings. | Yes, by PIN. |
| **Auditor** | Reads the released evidence to judge the project's rigour. | No — reads published artefacts. |
| **Deployer** | Installs and updates an instance. | Out of band. |

There is deliberately **no player account**, no registration and no login for
play. See `FR-1.1`.

---

# Part I — Functional requirements

## 4. Access

**FR-1.1 — Open access.** The game shall be playable by anyone who reaches its
URL, with no account, no registration and no access code.
*Verified by:* `tests/e2e/specs/gameplay.spec.js`; the removed event-code
endpoints are asserted to stay removed.

**FR-1.2 — No state in the URL.** Game state shall not be carried in URL
parameters. The only recognised parameters are `?mode=` with `&t=` (§8) and
`?d=` on the share routes (§7).
*Verified by:* `tests/DisplayModeShellTest.php`.

## 5. Gameplay

**FR-2.1 — Rounds per game.** A game shall consist of **five** rounds, drawn
from the uploaded scenarios without repeating a scenario within one game.

**FR-2.2 — Structured mode.** In structured mode each chip shall have exactly
one correct slot: `StrtNm`, `BldgNb`, `PstCd`, `TwnNm`, `Ctry`.

**FR-2.3 — Hybrid mode.** In hybrid mode `TwnNm` and `Ctry` shall remain
individual slots; the remaining components shall be placeable into two address
lines of at most 70 characters each.

**FR-2.4 — Mandatory fields.** `TwnNm` and `Ctry` shall be mandatory in both
modes, and a submission missing either shall be marked incorrect for that
field.

**FR-2.5 — Country-specific ordering.** In hybrid mode the expected order of
components within an address line shall follow the conventions of the
scenario's country rather than a single fixed order.
*Verified by:* `tests/js/address.test.js`; the formatter is bundled locally so
this holds without network access.

**FR-2.6 — Input by touch and by mouse.** Chips shall be placeable by mouse
drag and by touch drag. A double tap on a control shall not perform its action
twice.
*Verified by:* `tests/e2e/specs/gameplay.spec.js`, `touch-keyboard.spec.js`.

**FR-2.7 — On-screen keyboard.** On the play station the player shall be able
to enter their name entirely by touch, without a physical keyboard, including
accented characters.
*Verified by:* `tests/e2e/specs/touch-keyboard.spec.js`.

## 6. Scoring and the Hall of Fame

**FR-3.1 — Round accuracy.** Each round shall yield a percentage of correctly
placed components.

**FR-3.2 — Game score.** The ranking figure shall be
`round(pct × pct × (1 + 500 / max(1, seconds)) / 10)` — accuracy squared, with
an uncapped speed bonus.

**FR-3.3 — One ordering.** The database and the browser shall rank by the same
expression, so that a listing and the client agree about who is ahead.
*Verified by:* `tests/LeaderboardModelTest.php`, `tests/js/scoring.test.js`.

**FR-3.4 — Name required.** A player shall supply a name of 1 to 50 characters
before starting. A name consisting only of markup shall be refused, and a name
that is a single character — including `"0"` — shall be accepted and displayed.

**FR-3.5 — Profanity refusal.** A name matching the profanity list shall be
refused with an explanation rather than silently altered.

**FR-3.6 — Public listing.** The Hall of Fame shall be readable without
authentication and shall page through every stored entry.

**FR-3.7 — Deletion is administrative.** Only an administrator shall be able to
delete an entry, and the administrative listing shall reach **every** entry the
public listing shows.
*Verified by:* `tests/e2e/specs/admin-hall-of-fame.spec.js`.

## 7. Sharing

**FR-4.1 — Share a score.** A player shall be able to share their result by a
link carrying an encrypted token, by a native share sheet where the device
offers one, and by copying the link.

**FR-4.2 — Preview card.** A shared link shall present a 1200×630 PNG preview
and OpenGraph metadata.

**FR-4.3 — Sharing switch.** An administrator shall be able to switch the
sharing interface off. With it off, no sharing control shall be present in the
page and no token shall be minted.

**FR-4.4 — Existing links keep working.** Switching sharing off shall **not**
break links already posted: the share routes shall continue to resolve tokens.
*Rationale:* the switch governs what this installation offers, not what someone
else's feed already contains. It is an interface decision and **not** an access
control.
*Verified by:* `tests/e2e/specs/sharing.spec.js`.

**FR-4.5 — The play station never shares.** The play station's end-of-game
screen shall offer no sharing regardless of `FR-4.3`, because a native share
sheet on a locked kiosk strands the next player.

## 8. Display modes

**FR-5.1 — Five contexts.** The system shall serve: a phone, a desktop browser,
an iPad kiosk (session toggle), the wall (`?mode=hof`) and the play station
(`?mode=play`).

**FR-5.2 — Token-gated modes.** A dedicated mode shall be honoured only when
`&t=` matches this installation's display mode token, compared in constant
time.

**FR-5.3 — Silent fallback.** A wrong, missing or unverifiable token shall
serve the ordinary game **without an error message, and without a log entry**.
*Rationale:* a 42-inch wall must never show an error to a room, because nobody
is standing there to read it.
*Verified by:* `tests/DisplayModeTokenTest.php`, `display-token.spec.js`.

**FR-5.4 — No navigation on dedicated screens.** Both dedicated screens shall
omit the navigation and hamburger from the markup rather than hiding them.
This is a **guard rail, not a security boundary**.

**FR-5.5 — The wall never blanks.** A failed poll shall keep the last good
board on screen, retry with a capped backoff, and admit staleness with a
discreet indicator after repeated failures.

**FR-5.6 — Wall window.** An administrator shall be able to limit the wall to
the last *n* hours (0 = all time, validated 0–8760) without affecting the Hall
of Fame shown anywhere else.

**FR-5.7 — Arrival acknowledgement.** A new entry shall be highlighted if it is
visible, or announced by a banner if it placed below the fold. A first load
shall celebrate nothing.
*Rationale:* an unattended screen that reboots at 3am must not greet an empty
room with confetti for scores set hours earlier.
*Verified by:* `tests/js/board.test.js`.

**FR-5.8 — Token regeneration.** An administrator shall be able to regenerate
the token behind a confirmation, and the panel shall immediately show the new
URLs, QR codes and launch commands.

## 9. Administration

**FR-6.1 — PIN authentication.** The administration area shall require a PIN,
stored only as a hash, never in the database.

**FR-6.2 — Lockout.** Repeated failed attempts shall lock further attempts for
a period, keyed on the caller's address so that discarding a cookie does not
reset it.

**FR-6.3 — Scenario upload.** An administrator shall upload scenarios as a
spreadsheet with the columns `StrtNm`, `BldgNb`, `PstCd`, `TwnNm`, `Ctry`,
`AdtlAdrInf`.

**FR-6.4 — Upload validation.** A row with data but no town or no two-letter
country shall be reported **by row number** and the whole upload refused. A row
empty in all six columns shall be skipped as padding. Extra columns shall be
ignored.
*Verified by:* `tests/ExcelParserTest.php`.

**FR-6.5 — Facts.** An administrator shall manage the rotating "Did you know?"
facts, each at most 500 characters after sanitisation, with a small allowlist
of inline markup.

**FR-6.6 — Theme.** An administrator shall set five colours, and shall be able
to reset to the application defaults. The reset shall **delete** the stored
values rather than write the current defaults into them, so that a reset
installation continues to track future default changes.

**FR-6.7 — Deadline.** An administrator shall set the countdown target. Only a
date that exists shall be accepted — `2027-02-31T25:99` shall be refused rather
than rolled over. An unreadable stored value shall hide the banner rather than
display `NaN`.

**FR-6.8 — Kiosk toggle.** An administrator shall be able to put *this device*
into kiosk mode for the session.

## 10. Content and defaults

**FR-7.1 — Default deadline.** Absent an administrator's choice, the countdown
shall target **2027-11-28T00:00**, held as the constant
`GameController::DEFAULT_DEADLINE` and never written into the database.
*Consequence, intended:* an installation that never chose its own date follows
future changes to that constant.

**FR-7.2 — Seeded facts.** Ten facts shall be seeded once, into an empty table
only, and never rewritten.

## 11. Data retention

**FR-8.1 — Leaderboard retention.** Entries older than 365 days shall be
deleted.

**FR-8.2 — Rate-limit retention.** Rate-limit rows shall be deleted once they
lock nobody out and have been idle for 24 hours, so that hashed addresses are
not kept beyond their purpose.

**FR-8.3 — Runs with or without cron.** The same deletions shall occur whether
or not the host offers cron, via a best-effort fallback on visitor traffic.
A failure in the fallback shall be logged and shall not prevent the page from
being served.
*Verified by:* `tests/RetentionCleanupTest.php`.

---

# Part II — Non-functional requirements

## 12. Security

**NFR-1.1 — Encryption at rest.** Player names shall be encrypted with
AES-256-GCM. A missing or short key shall be a fatal error, never a silent
fallback to an empty key.

**NFR-1.2 — Authenticated encryption for untrusted input.** Share tokens, which
are attacker-supplied, shall be accepted in authenticated (GCM) form only. The
legacy unauthenticated format shall be readable **only** for leaderboard rows
written before the migration.

**NFR-1.3 — CSRF.** Every state-changing request shall carry a session-bound
token, compared with a timing-safe function. The single exception is `NFR-1.4`.

**NFR-1.4 — One public GET.** `/board/data` shall be readable without a session
or token, because a screen polling all evening would otherwise lose a
24-minute session and fail silently in front of nobody. It exposes only the
public leaderboard.

**NFR-1.5 — Content Security Policy.** The policy shall carry a per-request
nonce rather than `unsafe-inline`, and shall name `frame-ancestors`,
`form-action` and `base-uri` explicitly, as those do not fall back to
`default-src`.

**NFR-1.6 — Headers on every response.** Security headers shall be sent on
dynamic responses **and on static files**, including on a `304 Not Modified`.

**NFR-1.7 — No interpreter disclosure.** The server shall not advertise its
language or version in a response header.

**NFR-1.8 — No timestamp disclosure.** Asset URLs shall not expose file
modification times.

**NFR-1.9 — Output escaping.** All dynamic output shall be escaped. The "Did
you know?" facts, which permit inline markup, shall be sanitised against the
same allowlist on the server and independently in the browser.

**NFR-1.10 — Prepared statements.** All SQL shall be parameterised.

**NFR-1.11 — Setup lockdown.** The unauthenticated setup routes shall refuse to
run once the installation is configured, whatever the database is doing, so
that an outage cannot be used to repoint an instance.

**NFR-1.12 — Deployment secrets.** No credential shall be committed, appear on
a command line, or reach a transfer log. The deployment script shall refuse to
run if a credentials file would be included in the upload.

## 13. Privacy and compliance

**NFR-2.1 — Data minimisation.** The only personal data collected shall be a
display name chosen by the player, plus a score and a duration.

**NFR-2.2 — One cookie.** A single strictly necessary session cookie shall be
used. No tracking cookie shall be set.

**NFR-2.3 — Accurate controllership.** The privacy notice shall name the
authors as data controllers and nobody else. A supporting organisation shall
not be described as a controller.

**NFR-2.4 — An honest date.** The privacy notice's "last updated" date shall
reflect its content.
*Verified by:* `tests/PrivacyNoticeContentTest.php`, which fails when the text
changes without the date.

**NFR-2.5 — Address handling.** Rate limiting shall store only a keyed hash of
the caller's address, never the address.

## 14. Usability and accessibility

**NFR-3.1 — Touch-first.** The interface shall be usable by touch on a tablet
in landscape, and controls on the play station shall be large enough to hit
while standing at a 42-inch panel.

**NFR-3.2 — Responsive.** The layout shall adapt to a phone, including a
collapsed navigation.

**NFR-3.3 — Meaningful alternative text.** Images carrying meaning shall have
alternative text stating that meaning.

**NFR-3.4 — Contrast.** Default text and background colours shall meet WCAG AA.
*Known deviation:* zoom is disabled (`user-scalable=no`) for the kiosk drag
interaction. This is a deliberate accessibility cost, recorded in
`README.md` § *Static analysis*.

**NFR-3.5 — No dead controls.** Every control shall do what it appears to do.
Handlers shall be bound in JavaScript, never as inline attributes, which the
Content Security Policy blocks.

## 15. Reliability and resilience

**NFR-4.1 — Degraded network.** Gameplay shall remain correct when the CDN is
unreachable; the address formatter shall be served locally for that reason.

**NFR-4.2 — Unattended operation.** A screen left running for an evening shall
recover from a network interruption without human intervention and without
showing an error.

**NFR-4.3 — Cache correctness.** Asset URLs shall change when either the file
or the release changes, and the shell that mints them shall be uncacheable.

## 16. Compatibility

**NFR-5.1 — PHP.** The application shall run on PHP 8.1 through 8.4.

**NFR-5.2 — Database.** MySQL 5.7+/MariaDB 10.3+ for production; SQLite for
development and testing. Driver-specific SQL shall live in one place.

**NFR-5.3 — Browsers.** Safari on iPad is a first-class target: server date
formats shall be parsed explicitly rather than handed to `new Date()`.

**NFR-5.4 — No build step.** Production JavaScript shall ship unbundled and
unminified, exactly as written. TypeScript shall be used as a checker only.

## 17. Maintainability and assurance

**NFR-6.1 — Gates.** Every change shall pass: PHPUnit on two PHP versions,
Vitest, Playwright end-to-end, PHPStan, `tsc`, a passive OWASP ZAP scan, CodeQL
and the SonarCloud quality gate.

**NFR-6.2 — No baselines.** Neither static analyser shall carry a baseline;
green shall mean *no findings*, not *no new findings*.

**NFR-6.3 — Documentation moves with behaviour.** Documentation shall be
updated in the same change as the behaviour it describes.

**NFR-6.4 — Evidence.** Each release shall carry a signed evidence pack
containing each tool's native output, a manifest naming the commit and workflow
run, and checksums.

**NFR-6.5 — Language.** All human-readable text — code, comments,
documentation, commit messages, release notes — shall be British English.

## 18. Licensing

**NFR-7.1 — AGPL-3.0-or-later.** The project is licensed under the GNU Affero
General Public License v3 or later, because the program is used over a network
rather than distributed.

**NFR-7.2 — Dependency compatibility.** Every dependency shall be compatible
with that licence, and each release shall publish the licence of every
dependency alongside its version.

**NFR-7.3 — Trademarks are not licensed.** The logo images shipped in
`public/assets/images/` are trademarks, used with permission, and are **not**
covered by the AGPL. A copyleft licence over source code grants no rights over
a mark.

**NFR-7.4 — Third-party assets keep their terms.** The bundled fonts (SIL OFL
1.1) and the background map (CC BY-SA 3.0) are not relicensed by this project.

---

# Part III — Boundaries

## 19. Deliberate non-goals

These are decisions, not omissions. Each has been considered and declined.

| Not done | Why |
|---|---|
| **Server-side score recomputation** | The browser computes the percentage and the server bounds-checks it. The Hall of Fame is for fun, not adjudication — see `DESIGN.md`. A determined player can submit a figure they did not earn. |
| **An access gate** | Existed until schema v7 and was removed on purpose. The game must be playable by anyone who reaches it. |
| **Self-update** | Removed. Nothing in the application downloads, installs or schedules code. |
| **Treating the display token as security** | It makes two addresses unguessable. It authenticates nobody, and every API route is as reachable as before. |
| **Treating the sharing switch as access control** | It governs what is offered, not what is reachable. |
| **An outbound link from the logo** | A kiosk under Guided Access cannot come back from an outbound navigation. |
| **Source offer on the wall and play station** | Those two screens drop the footer's source link. Acknowledged against AGPL §13 and accepted by the maintainer. |

## 20. Traceability

Requirements are checked where the checking is cheapest and most honest:

| Area | Where it is verified |
|---|---|
| Server logic, validation, retention | `tests/*.php` (PHPUnit) |
| Pure browser logic | `tests/js/*.test.js` (Vitest) |
| Anything involving a real browser, a session or a real HTTP request | `tests/e2e/specs/*.spec.js` (Playwright) |
| Security posture of a running instance | `npm run dast` (OWASP ZAP, passive) |
| Code-level defects | PHPStan, `tsc`, CodeQL, SonarCloud |

Requirements marked *not automated* above are the honest gaps. A requirement
with no check is a statement of intent, and this document does not pretend
otherwise.
