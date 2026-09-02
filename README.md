<!--
ISO 20022 Address Structuring Game
Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <https://www.gnu.org/licenses/>.
-->

# ISO 20022 Address Structuring Game

An interactive kiosk-style game to educate users on ISO 20022 postal address structuring (Standard Release 2026). Built for tablets in landscape mode with a touch-first interface.

## Legal notice

This game was created as an educational tool by **Xavier Dubois** and **Niel Buchan**. It is developed and maintained by its authors.

This notice names no supporting organisation, and does not deny one either — the
same silence the Privacy screen keeps. Both said more at different times, and
both said something that later stopped being true. Do not reintroduce either
sentence: neither "supported by the PMPG" here, nor "not affiliated with or
endorsed by any organisation" anywhere.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the [GNU General Public License](https://www.gnu.org/licenses/gpl-3.0.html) for more details.

## Features

- **PMPG lockup** — The mark appears on its own — no caption above it — on the
  welcome card, the page footer, the app icon and the share card, and the
  endorsement is stated in words in the page's OpenGraph and Twitter
  descriptions. The legal notice and the Privacy screen name no supporting
  organisation. The game remains the work of its authors, and the mark is not
  covered by the GPL — see [Legal notice](#legal-notice) and
  [Third-party assets](#third-party-assets)
- **Drag & Drop Gameplay** — Drag address chips into correct ISO 20022 semantic slots
- **Structured & Hybrid Modes** — Practice both address structuring approaches
- **Hall of Fame** — Encrypted leaderboard ranked by a game score that weights accuracy quadratically and rewards speed, GDPR-compliant 365-day retention
- **Social Sharing** — Encrypted share tokens with OpenGraph meta tags and dynamically generated 1200×630 PNG share cards carrying the PMPG endorsement
- **Dynamic Apple Touch Icon** — Themed PNG icon carrying the PMPG sunburst on a white disc, regenerated automatically when the theme changes
- **Theme System** — 5 customizable colors (primary, hover, light, background, text) editable via admin panel, defaulting to the PMPG palette
- **Admin Panel** — PIN-protected dashboard for uploading scenarios via Excel
- **Screen Saver** — Displays countdown, fun facts, and touch-to-play CTA when idle
- **Fun Facts** — Rotating educational facts about ISO 20022 (customizable via admin)
- **Privacy by Design** — AES-256-GCM authenticated encryption at rest, GDPR-compliant privacy notice
- **Display modes** — `?mode=hof` drives an unattended Hall of Fame wall that
  refreshes itself and survives a network outage; `?mode=play` drives a standing
  play station with an on-screen keyboard and an end-of-game that hands the
  machine back to the next player. See [Display modes](#display-modes)
- **Responsive** — Mobile hamburger menu, touch-first design for tablets
- **Cache Busting** — Theme-aware cache busting for background images and icons (includes theme colors + file mtimes)

## Requirements

- PHP >= 8.1, with the `gd` extension (share cards and the app icon). `imagick`
  is used instead when present and produces richer share images.
- MySQL 5.7+ or MariaDB 10.3+
- Composer
- Apache with mod_rewrite (or equivalent)
- Outbound HTTPS to `cdn.jsdelivr.net` for PicoCSS, Dropzone, canvas-confetti,
  Chart.js and the QR code library. The address formatter — which hybrid mode
  grades against — is bundled locally, so gameplay stays correct on a restricted
  network even though styling and confetti will not load.

## Quick Start

### 1. Clone & Install Dependencies

```bash
git clone https://github.com/xdubois-57/iso20022-address-game.git
cd iso20022-address-game
composer install
```

### 2. Configure Database

**Option A: Edit credentials file**

```bash
cp config/credentials.php.example config/credentials.php
# Edit config/credentials.php with your DB details
```

**Option B: Browser setup**

Simply visit the app in your browser. If the database cannot be reached, you'll be shown a setup page to enter connection details. These are saved to `config/db_config.json` (protected by `.htaccess`).

### 3. Point Web Server to `public/`

Configure your web server's document root to the `public/` directory.

**Apache example** (already includes `.htaccess`):
```apache
<VirtualHost *:80>
    DocumentRoot /path/to/iso20022-address-game/public
    <Directory /path/to/iso20022-address-game/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### 4. Upload Scenarios

1. Access the Admin panel (default PIN: `1234`)
2. Upload a `Scenarios.xlsx` file with the required columns (see DESIGN.md)

### 5. Customize Theme (Optional)

1. Access the Admin panel
2. Navigate to the "Theme" section
3. Adjust any of the 5 color variables:
   - **Primary** — Main brand color (buttons, chips, accents)
   - **Primary Hover** — Darker shade for hover states
   - **Primary Light** — Very light tint for filled slots/highlights
   - **Background** — Page background and image background
   - **Text** — Dark text and headings
4. Changes apply immediately and update the Apple Touch Icon automatically

The defaults are the PMPG palette (`#3d345f` violet, `#8abed9` sunburst blue).

**Reset to PMPG colours** discards this installation's saved colours — it
*deletes* them rather than writing the PMPG values in. That distinction is
deliberate: an installation with no saved colours follows the application's
defaults, so it will pick up any future change to them, whereas writing the
five values in would pin it to today's palette forever. One click and it is
persisted; reload the page to see it applied.

Upgrading an existing installation: if it never saved a theme, it moves to the
PMPG palette on its own. If it did, it keeps its colours until an administrator
presses that button — nothing overwrites a deliberate choice automatically.

### 6. Kiosk Mode (Optional)

Enable **Kiosk Mode** for unattended public displays:

1. Go to Admin panel
2. Under **Display modes**, switch on *Kiosk mode — this device*
3. The app will:
   - Enter fullscreen automatically
   - Re-enter fullscreen if user exits
   - Show screen saver after 60 seconds of inactivity
   - Display countdown, fun facts, and "Touch to play" CTA

**Screen Saver Features:**
- Auto-detects touchscreen (shows "Touch" or "Click" accordingly)
- Displays ISO 20022 deadline countdown
- Rotates fun facts every 20 seconds
- Dismisses on any touch/click interaction

**Note:** Kiosk mode is session-only and resets on page reload. That is fine
for an iPad you prepare by hand, and exactly wrong for an unattended screen —
which is why the wall and the play station use a URL instead. See
**Display modes** below.

### The deadline countdown

The countdown targets **28 November 2027 at 00:00** unless an administrator
saves a date of their own under Admin → *Unstructured Address Deadline*. A
saved date always wins.

Nothing writes that default into the database at install time, so it is the
constant `GameController::DEFAULT_DEADLINE` that is in force until somebody
saves one. Two consequences, both intended:

- An installation that never set its own date **moves to the new value when it
  is updated**. There is no migration to prevent that, on purpose — an install
  that had accepted the previous default had accepted "whatever this project
  says the deadline is".
- The ten "Did You Know" facts are seeded **once**, on an empty table. An
  installation created before this change keeps the facts it was seeded with,
  two of which still name November 2026. Nothing rewrites them — edit them from
  Admin → *Did You Know — Quick Facts*, or the screen saver will show a
  countdown to one date beside a fact naming another.

**iPad Setup Guide:**
For an optimal kiosk experience on iPad, add the app to your home screen and enable Guided Access:

1. Open this page in **Safari** on the iPad
2. Tap the **Share** button (square with arrow) in Safari toolbar
3. Choose **Add to Home Screen**, then tap **Add**
4. Open the app from the home screen icon (launches fullscreen)
5. Enable **Guided Access**: Settings → Accessibility → Guided Access → set passcode
6. Triple-click the Side button to start Guided Access (locks to this app)
7. Triple-click again and enter passcode to stop Guided Access

## Display modes

The game runs in five contexts. Three of them need no setup at all; the two
dedicated screens are switched on by a URL.

| Context | How you get there | Screen |
|---|---|---|
| Mobile / shared link | plain URL | whatever the player is holding |
| Desktop browser | plain URL | a window |
| iPad kiosk | toggle in Admin → Display modes | an iPad, prepared by hand |
| **Hall of Fame wall** | `?mode=hof` | 42" **portrait**, touch, never touched |
| **Play station** | `?mode=play` | 42" **landscape**, touch, played standing |

```
https://<host>/?mode=hof&t=<token>     the wall
https://<host>/?mode=play&t=<token>    the play station
```

The `t=` is the **display mode token**, a 32-character random value this
installation generates for itself. Both complete URLs — with QR codes and
ready-to-paste launch commands — are shown in Admin → *Display modes*. There is
no need to write the token down anywhere; the panel always shows the current
one.

Both dedicated screens are served without the navigation bar and without the
hamburger — omitted from the markup, not hidden after rendering. Understand
this as a **guard rail, not a security boundary**: the API routes stay open and
the leaderboard is public either way. The goal is that a player does not wander
off into the Hall of Fame, not that a determined visitor cannot.

### The screen address token

`?mode=` alone is not enough: the mode is honoured only when `&t=` matches the
`display_mode_token` this installation holds, compared with `hash_equals()`.

**A wrong token, a missing one, or a database that cannot be reached serves the
ordinary game with its menus.** No error page, no message, nothing in the
logs — exactly what `?mode=nimportequoi` already did. That is deliberate: a
42-inch wall must never show an error to a room, because nobody is standing
there to read it.

The token is generated the first time it is needed, so an existing installation
acquires one on its next request with no migration to run. It is stored as an
opaque random value and never encrypted — nothing is ever recovered from it,
only compared against — and it is **never written to a log**, on a match or a
miss.

#### What it is not

It makes the two addresses unguessable. It does not make them private, and it
authenticates nobody:

- `/board/data` stays a public, unauthenticated GET, by design — see below.
- Every API route is exactly as reachable as it was.
- Anyone holding a URL holds the token.

This is a hardened guard rail. Do not present it as a security boundary, and do
not build anything on top of it that needs one.

#### Regenerating

Admin → *Display modes* → *Screen address token* → **Regenerate**, behind a
confirmation.

Read the confirmation before pressing it, because the failure mode is quiet:
the moment a new token is in force, **both screens fall back to the ordinary
game with menus, with nothing on their own displays to say why**. They will sit
there looking wrong to anyone in the room and looking fine to anyone reading a
log. Reopen both with the new addresses, which the panel prints — URLs, QR
codes and `chrome --kiosk` commands — the instant the confirmation is accepted,
without a page reload.

Regenerate between events, not during one.

### Launching the two screens

Use the browser's own kiosk switch, not the Fullscreen API:

```
chrome --kiosk --app="https://<host>/?mode=hof"
chrome --kiosk --app="https://<host>/?mode=play"
```

Fullscreen triggered from inside the page requires a user gesture. After a
reboot at three in the morning there is nobody there to provide one, and the
screen comes back as an ordinary window with menus. `--kiosk` returns to
fullscreen on its own and keeps the address bar out of reach.

### Reaching the admin screen from one of these machines

Load the URL with no parameter — dropping `?mode=` and `&t=` both. There is no
back door in either display mode, by design.

### Settings

| Setting | Where | Default | Meaning |
|---|---|---|---|
| `board_window_hours` | Admin → Display modes → Wall window | `24` | How far back the wall looks, in hours. `0` means since forever. Validated server-side to 0–8760. |
| `sharing_enabled` | Admin → Sharing | `1` | Whether the end-of-game screen offers the sharing controls. See [Sharing](#sharing). |
| `display_mode_token` | Admin → Display modes → Screen address token | generated on first use | The `&t=` both dedicated screen URLs carry. Never seeded, never logged. |

`board_window_hours` applies to `?mode=hof` and to nothing else. The Hall of
Fame served to phones, to desktop browsers and to the iPad kiosk stays
all-time, so narrowing the wall to one evening never erases the record
everywhere else.

### `/board/data`

The wall reads a public GET route, `/board/data`, which answers JSON without a
session and without a CSRF token — unlike every other API route here.

That is deliberate. The rest of the API is POST-only with a CSRF token bound to
the PHP session, whose default lifetime is 24 minutes. A screen that polls the
server from six in the evening until the room empties would lose its session
and see every call fall to 403 — silently, around midnight, with nobody
standing in front of it. A public GET removes that failure mode at the root.
Nothing is exposed that the Hall of Fame does not already show any anonymous
visitor: the same names, the same scores, the same ordering.

It takes an optional `?limit=`, capped server-side at 50, and returns
`window_hours`, `total_count`, `server_time`, `entries[]` and `recent[]`. Every
entry carries a rank computed by the server.

### Before the event

Worth doing once, on the real machines, and not on the day:

- Launch both screens with the `--kiosk` commands above — copied from the
  admin panel, so they carry the current token — then **reboot both machines**
  and check they come back on their own in the right mode. A screen that comes
  back with menus has the wrong token, not a broken mode.
- Set `board_window_hours`, play three games, and watch the wall react.
- **Unplug the wall machine's network for thirty seconds**, then plug it back
  in. The board must stay on screen throughout and resume by itself. This is
  the one rehearsal that matters on the night.
- Play a full game **with a finger only**, name included, without touching the
  physical keyboard.
- Confirm that pressing the wall does nothing whatsoever.

## Sharing

Sharing is **on** by default and can be switched off from Admin → *Sharing*.

With it off, the end-of-game screen renders none of the four sharing surfaces:
the "Challenge a Friend" button, the LinkedIn link, the copy-link button, and
the kiosk QR block. They are not hidden with CSS — they are not in the DOM at
all, no handler is bound to them, and no share token is minted.

### What it does **not** do

The switch is an **interface decision, not an access control**, and it is worth
being blunt about that because the difference is where the damage would be. The
five server routes keep answering exactly as before, whatever the setting says:

| Route | Still answers | Why |
|---|---|---|
| `/share?d=…` | yes | A link a player already posted lives in somebody's feed. Breaking it breaks their post, not this installation's future. |
| `/share/go?d=…` | yes | Same, for a QR code somebody has already photographed. |
| `/share/image?d=…` | yes | The preview image those two links point at. |
| `/share/home-image` | yes | **Not score sharing.** This is the site's own OpenGraph image, used by every link to the game — closing it would degrade the preview of a link that has nothing to do with anyone's score. |
| `share/token` (POST) | yes | Nothing in the UI calls it with sharing off; it stays available so the route surface does not change under an existing client. |

So: do not describe this switch as a security measure, and do not use it as
one. Anyone holding a token can still resolve it. What the switch controls is
whether this installation *offers* sharing to the player in front of it.

### Relationship to `?mode=play`

Two independent mechanisms, both of which have to hold. The play station has
never shared, and still never shares whatever `sharing_enabled` says: its
end-of-game screen is a different screen entirely, because `navigator.share`
opens an operating-system sheet on top of a locked kiosk that the next player
then has to dismiss. Switching sharing back on does not give the play station
share buttons.

## Excel File Format

### Sheet 1: Scenarios

| StrtNm | BldgNb | PstCd | TwnNm | Ctry | AdtlAdrInf |
|--------|--------|-------|--------|------|------------|
| Main St | 123 | 10001 | New York | US | Floor 10 |

## Scoring is client-authoritative

The browser computes the round percentage and posts it, and in hybrid mode it
also derives the country-specific field order the server grades against. The
server validates ranges and rejects nonsense, but it does not recompute the
score from first principles, so a determined player can submit a figure they did
not earn.

This is a deliberate trade-off for an educational kiosk game — the Hall of Fame
is for fun, not for adjudication. Do not treat it as a competition of record.

## Security

- **Encryption**: Player names encrypted with AES-256-GCM (authenticated encryption) at rest
- **CSRF protection**: Token-based validation on all POST requests
- **Rate limiting**: Keyed on the client address and stored server-side, so it survives a discarded session cookie. Admin login locks after 5 failed attempts (5-minute lockout); leaderboard submissions throttled to 10 per 5 minutes. Only a keyed hash of the address is stored, and spent rows are deleted by the daily cleanup
- **Session hardening**: HttpOnly, SameSite=Strict, secure cookie flags
- **Security headers**: CSP, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy. The CSP names `frame-ancestors`, `form-action` and `base-uri` explicitly, because none of the three falls back to `default-src` — omitting them leaves them unset rather than restricted
- **Subresource Integrity (SRI)**: All CDN resources loaded with `integrity` hashes to prevent supply-chain attacks
- **Host header validation**: `HTTP_HOST` validated against safe patterns to prevent host injection
- **Admin PIN**: Stored only in `config/credentials.php` — never in the database. A PIN typed into that file in clear is accepted once and then replaced in place by a bcrypt hash of itself, so it does not stay readable. The file is rewritten atomically and the write is abandoned unless the AES encryption key alongside it survives intact. Installs that predate this have their PIN migrated out of the `settings` table on first use and the row removed
- **Prepared statements**: All database queries use parameterised PDO statements
- **Input validation**: Server-side bounds on all inputs (score 0–100, time 0–3600s, name 1–50 chars). Note that these are *bounds*, not proof of authenticity — see "Scoring is client-authoritative" below
- **XSS prevention**: `escapeHtml()` on client, `htmlspecialchars()` on server for all dynamic output. The Hall of Fame wall additionally coerces every number it receives from `/board/data` to a finite number before concatenating it into its markup, so a compromised response cannot inject through a field the server declares as an integer. "Did you know?" facts accept a little inline markup and are therefore run through an allowlist sanitiser (`<a href>`, `<b>`, `<strong>`, `<i>`, `<em>`, `<br>`) on both write and read. The browser enforces the same allowlist independently before rendering a fact, so a row written by an older version cannot reach the DOM as markup
- **Setup lockdown**: The unauthenticated setup routes refuse to run once the installation is configured, whatever the database is doing — a database outage cannot be used to repoint the app or overwrite its encryption key
- **Authenticated encryption only for untrusted input**: share tokens are accepted in AES-256-GCM form only; the legacy unauthenticated AES-256-CTR format is read for pre-migration leaderboard rows and nothing else
- **Security logging**: Failed login attempts and CSRF violations logged with IP address
- **Session cookie**: A single strictly necessary PHPSESSID cookie for CSRF protection (no tracking)

## Running Locally

```bash
composer install
composer serve            # http://localhost:8000
composer serve -- 8080    # a specific port
```

`scripts/serve.sh` checks the prerequisites and starts PHP's built-in server
on `public/`. On a clone with **no database configured yet** it provisions a
local SQLite instance automatically (`storage/dev.sqlite`) and writes a
gitignored `config/credentials.php` with a generated encryption key and the
default admin PIN `1234` — so a fresh clone gets a working instance in one
command. An install that is already configured is never touched.

To use MySQL instead, follow [Configure Database](#2-configure-database) and
delete `config/credentials.php` first: it takes precedence over
`config/db_config.json`.

This is a development server only. It handles one request at a time and is
not hardened — don't expose it beyond localhost.

## Running Tests

```bash
composer install --dev
composer test
```

### JavaScript unit tests

Development-only, requires Node.js (>=20) and never runs in production —
production JavaScript stays plain, unbundled, and Node-free.

```bash
npm install
npm test
```

### Coverage

```bash
composer test:coverage      # PHP unit coverage  -> coverage/php/raw/unit.cov
E2E_COVERAGE=1 npm run e2e  # browser coverage   -> coverage/php/raw/*.raw
composer coverage:merge     # both              -> coverage/php/clover.xml
npm run test:coverage       # JavaScript        -> coverage/js/lcov.info
```

Coverage is measured across **all three** suites. The browser tests drive the
real front controller over HTTP, so `E2E_COVERAGE=1` records what they execute
and `coverage:merge` combines it with the PHPUnit report — a line covered only
by a browser test still counts. Needs the `pcov` extension (CI installs it;
locally, point `E2E_PCOV_EXTENSION` at `pcov.so` if it is built but not
enabled in `php.ini`).

Current: **PHP 80%**, **JavaScript 99%**.

Some paths are excluded from the coverage metric (never from analysis) in
`sonar-project.properties`, because unit tests cannot reach them: shell and CLI
helpers, the front controller, view templates, and `public/assets/js/app.js` —
the SPA bootstrap, a single large IIFE that wires the UI to the DOM on load.
Importing it under jsdom would execute the whole application against a fake
document rather than test it; it is covered end to end by Playwright, which
produces no lcov. The logic worth asserting on has been moved out of it into
`public/assets/js/lib/`, which **is** measured and sits at 98-100%. Continuing
that extraction is what will genuinely cover the rest.

### End-to-end tests

Drives a real headless Chromium browser against a throwaway instance
(SQLite-backed, no MySQL required) via [Playwright](https://playwright.dev/):

```bash
npm install
npx playwright install --with-deps chromium   # once
npm run e2e
```

`npm run e2e` (`scripts/e2e.sh`) provisions the throwaway instance, starts a
PHP built-in server, runs the suite, and tears everything down — including on
failure or Ctrl-C. It never touches a real `config/` directory or database.

## Static analysis

Two checkers, both looking for **defects rather than style**: unresolved
identifiers, wrong argument counts, properties that do not exist on the thing
being touched. This is the class of mistake the three test suites cannot see,
because it lives in code they never run.

```bash
composer run analyse    # PHPStan level 6 over app/ and public/index.php
npm run typecheck       # tsc --noEmit over public/assets/js/
```

There is deliberately **no ESLint and no PHP_CodeSniffer**. Reformatting a
codebase produces a very large diff, no fewer bugs, and a review nobody can
read.

TypeScript here is a **checker, never a build step**. `tsconfig.json` sets
`allowJs`, `checkJs` and `noEmit`; nothing is compiled, nothing is bundled, and
production still ships the plain unbundled JavaScript in `public/assets/js/`
exactly as it is written. There is no `.ts` file in this repository and there
should not be one.

### The baselines

Both commands pass today, and neither of them passes because the code is clean.

| File | Findings accepted | Produced by |
|---|---|---|
| `phpstan-baseline.neon` | **76** | `composer run analyse:baseline` |
| `js-typecheck-baseline.json` | **82** (30 distinct file/code/message groups) | `npm run typecheck:baseline` |

Most of the JavaScript ones are one shape: `app.js` is close to three thousand
lines and reaches for `.value`, `.disabled` or `.tagName` on the generic
`Element` that `getElementById()` and `querySelector()` return. That is real
debt, it is worth paying down, and paying it down is a different piece of work
from installing the tools. Without a baseline the job would be red permanently,
everybody would learn to ignore it, and a permanently ignored gate is worse
than no gate — it costs a minute on every push and buys nothing.

So **green means "no NEW finding", never "no findings"**.

PHPStan has this mechanism built in. `tsc` does not, so
`scripts/js-typecheck.mjs` adds the same contract on top of it: it runs `tsc`,
groups what comes back, and fails only on an occurrence beyond what the
baseline accepts. It indexes by **file + error code + message, never by line
number** — a line-keyed baseline would report every pre-existing finding below
an edit as new, which is how a baseline mechanism becomes useless in one
afternoon.

#### Regenerating one

> Regenerate a baseline **only** to deliberately accept existing debt you are
> not fixing right now. **Never** to silence a finding your own change just
> introduced — fix that one instead.
>
> A baseline regenerated to hide a regression turns the whole gate into
> decoration while leaving everybody sure it is working.

Both files carry that warning in their own header, where somebody about to
regenerate one will actually read it.

## Dynamic application security testing

A **passive** OWASP ZAP scan of the running application, on every push and on
demand:

```bash
npm install
npx playwright install --with-deps chromium
docker pull ghcr.io/zaproxy/zaproxy:stable   # once, about 1.2 GB
npm run dast                                 # scripts/dast.sh
```

`scripts/dast.sh` provisions a throwaway SQLite-backed instance, serves it over
**HTTPS** through the real entry point (`public/index.php`), replays the
Playwright suite through ZAP acting as a proxy, gates on the findings, and
tears the whole thing down from an `EXIT` trap — on success, on failure and on
Ctrl-C. It uses its own temporary directory, its own database and its own
ports, so it cannot collide with `npm run e2e`.

### The browser suite is the attack surface

Not ZAP's spider. The end-to-end suite already drives the admin screen from
behind its PIN pad, both dedicated display modes with their token, a full
five-round game, the end-of-game screen and the share flow. A crawler pointed
at this application sees the welcome card and stops. Replaying the suite
through a proxy is the most faithful picture of the real surface that exists.

That has a consequence worth stating: **a failed browser run fails the scan**,
even with no security finding. A scan is only as complete as the traffic it was
given.

### Why it has to be HTTPS

`scripts/e2e.sh` serves plain HTTP, and two of this application's protections
are conditional on the request having arrived over TLS — the HSTS header
(`public/index.php` ~l.97) and `session.cookie_secure` (~l.155). A scan in the
clear would report *"no HSTS"* and *"cookie without the Secure flag"*: two
findings that are **false**, about code that is **correct**.

The tempting fix is an alert filter silencing both rules. That is exactly how a
report stops being read — two rules muted for a harness defect, and the day one
of them fires for real nobody notices. So the harness is fixed instead:

- `scripts/dast-tls-proxy.php` terminates TLS in front of `php -S` and sets
  `X-Forwarded-Proto`, stripping any copy the client sent first;
- `scripts/dast-https-prepend.php` translates that into `$_SERVER['HTTPS']` for
  the backend process only, through `auto_prepend_file` — **the application
  itself is untouched and does not trust any forwarded header**;
- both ZAP rules stay fully armed, and `scripts/dast.sh` proves HSTS and the
  `Secure` cookie are live *before* the scan starts, so a broken harness fails
  in ten seconds rather than producing twenty minutes of false findings.

### The gate

`DAST_THRESHOLD` defaults to `Medium`: the run fails on any finding at Medium
or above. Informational and Low are printed but do not fail.

There is deliberately **no baseline of accepted findings** — the opposite
choice from the PHPStan and `tsc` baselines, and the difference is the point.
Those record thousands of pre-existing type findings nobody introduced today.
This records live security findings against a running instance, and a growing
list of "accepted" ones is how a scan stops meaning anything. A finding is
either fixed, or filtered as a false positive **with the reason written into
`tests/dast/zap-passive.yaml`** where a reviewer will see it. Today nothing is
filtered.

### Why the findings are not in the Security tab

There is no SARIF upload, and that is a decision rather than an omission.

Code scanning anchors a result to a path inside the checkout so it can blame a
commit and a line. A DAST result is an observation about a **running
instance**: ZAP records the URL it actually requested, and the harness serves
that instance on a free port picked at run time, so every location reads
`https://localhost:<random>/…` — an origin that never existed outside that one
job. Rewriting those URLs into repository paths would make the upload succeed
by inventing a source location for a finding that has none.

The gate loses nothing by it: the exit code fails the job on the push that
introduced the finding, which the Security tab never did.

### The report is not published

The HTML report stays in the job's log. It is **not** uploaded as an artifact
and **not** attached to a Release — only the counts by severity are, in
`dast-severity-summary.json`. This repository is public, so its Release assets
are public, and so are its workflow artifacts, which is less widely known. A
detailed DAST report on a public repository is a map drawn for whoever wants
one.

### Not an active scan

The passive profile observes traffic and sends nothing of its own. An active
profile would **replay every recorded request with attack payloads, carrying
the session cookies the browser was using** — including an authenticated admin
one, which on this application can purge the leaderboard and overwrite the
scenarios. A passive scan is a gate; an active scan is an attack, and it needs
an exclusion list written before it rather than after.

## Continuous Integration

`.github/workflows/ci.yml` runs on every push and pull request to `main`. The
gates themselves live in `.github/workflows/checks.yml`, a reusable workflow
that CI and the release pipeline both call — so the two can never drift apart
on what "green" means, and the `setup-php` block exists once rather than six
times:

| Job | What it does |
|---|---|
| **PHP** | PHPUnit on 8.1 and 8.4 — the floor this project advertises and the current release |
| **Static analysis** | PHPStan and `tsc`, both against their baselines — see [Static analysis](#static-analysis) |
| **JavaScript** | Vitest unit suite |
| **End-to-end** | Playwright against a throwaway SQLite instance |
| **Dynamic scan** | Passive OWASP ZAP scan over HTTPS, gated at Medium — see [Dynamic application security testing](#dynamic-application-security-testing) |
| **SonarCloud** | Static analysis with merged PHP + JavaScript coverage |

`.github/workflows/codeql.yml` runs separately, on push, on pull request and
weekly. **CodeQL does not support PHP**, so the only language it analyses is
`javascript-typescript` — everything under `app/`, which is the majority of
this application's logic, is not covered by it. Its results land in the
repository's Security tab; the PHP is covered by PHPStan, SonarCloud and the
passive DAST scan instead. The workflow says all of this in its own header,
because a green badge is a claim somebody will read as more than it is.

### SonarCloud setup

Analysis is configured by `sonar-project.properties`
([dashboard](https://sonarcloud.io/project/overview?id=xdubois-57_iso20022-address-game)).
Two things are needed for the job to run:

1. **A `SONAR_TOKEN` repository secret.** Generate it in SonarCloud (My Account
   → Security) and add it under Settings → Secrets and variables → Actions.
   Without it the scan step is *skipped* rather than failed, so pull requests
   from forks — which cannot read secrets — still run the rest of CI.
2. **Automatic Analysis turned off**, in SonarCloud under Administration →
   Analysis Method. SonarCloud refuses a CI-based analysis while its own
   automatic analysis is enabled, and only the CI one can carry coverage.

## Releases and the evidence pack

`.github/workflows/release.yml` runs on a `v*` tag **and** on
`workflow_dispatch`. The second matters as much as the first: it is how a set
of evidence is produced for what is already deployed, or how the whole chain is
rehearsed, without cutting a version.

It is deliberately **not** merged with `ci.yml`. That one is the fast loop on
every push and has to stay fast; this one is the slow complete pass. What they
share is the gates, which live in the reusable `checks.yml` both of them call.

### Ordering

All gates first. The Release is created **only if every one of them is green**,
and it is created as a **draft**, so the evidence can be read before it becomes
public. If a gate fails no Release is created at all — the tag exists and points
at nothing published, which is fixed by deleting the tag and pushing it again.

### What is in the pack

Only what each tool emits natively. Nothing in it is written by hand: a
document somebody writes once to describe a pipeline is a document nobody
updates when the pipeline changes, and evidence that has stopped being true is
worse than no evidence.

| Evidence | Where it comes from |
|---|---|
| PHP tests, 8.1 and 8.4 | PHPUnit `--log-junit` |
| JavaScript tests | Vitest `--reporter=junit` |
| End to end | Playwright's own HTML report, one screenshot per test |
| PHP static analysis | PHPStan's output |
| JavaScript static analysis | `tsc`, through `npm run typecheck` |
| CodeQL | the workflow's SARIF |
| SonarCloud | the quality gate, as their API returns it |
| Dynamic scan | **counts per severity only** |

### Two things it deliberately does not contain

**The detailed DAST report.** This repository is public, so its Release assets
are public — and so are its workflow artifacts, which is less widely known.
Publishing a dynamic-scan report of a running instance is publishing a map.
Only `dast-severity-summary.json` leaves the job; the full report stays in the
scan job's log, where whoever is debugging a red build can read it. A step in
the release job checks the pack for a detailed report and fails the release
rather than letting one out.

**Videos and traces of tests that passed.** They are what makes an evidence
pack enormous, and a video of a test that behaved is not evidence anybody
watches. Both stay `retain-on-failure` in every mode. Screenshots go to `'on'`
only for a release, driven by `E2E_EVIDENCE` — an ordinary `npm run e2e` stays
light.

## GDPR Cleanup (Cron Job)

Schedule the cleanup script to run daily:

```bash
0 3 * * * php /path/to/scripts/cleanup.php
```

This deletes:

- leaderboard entries older than 365 days, and
- rate-limit rows that no longer lock anyone out and have been idle for 24
  hours, so the hashed caller addresses in them are not kept past their purpose.

A fallback "poor man's cron" runs the same job (`App\Models\RetentionCleanup`)
automatically once per day on visitor traffic, so an install with no cron
deletes exactly the same things. It is best-effort: a failure there is logged
and the page is still served.

## Deployment

Deployment is a plain file sync, and it is the only supported way to update
an install: run `composer install --no-dev`, then upload everything except
`tests/`, `.git/` and the local config files. `storage/` and `uploads/` hold
live data — leave the copies on the server alone.

The maintainers use an FTP script (`deploy.sh`) for this, but it holds
production credentials and is therefore gitignored — it is **not** part of a
clone. Write your own, or deploy however suits your host.

Each published release also carries a ready-to-upload zip built by
`release.sh`, which already includes `vendor/`; GitHub's own auto-generated
source zipball does not, so prefer the attached artifact if you deploy from
a release.

The application never updates itself: nothing in it downloads, installs or
schedules code. An earlier version could install updates from a signed
GitHub webhook; that feature was removed, and an install upgrading past it
deletes the settings it left behind — including its webhook secret — on the
next request. If yours ran it, `storage/backups/` may still hold zips of
previous versions of the site; nothing reads them any more and they can be
deleted.

## Credits

This game was built by **Xavier Dubois** and **Niel Buchan**.

### Third-party assets

#### PMPG logo

The PMPG name and logo are trademarks of the Payments Market Practice Group,
used with permission. They are **not** covered by the GPL v3 licence granted
over this project's source code: a fork receives the code, not the right to use
the mark. Remove the logo assets and the endorsement wording before
redistributing a modified version.

Concretely, that means `public/assets/images/pmpg-logo.png`,
`public/assets/images/pmpg-mark.png`, every block that renders them — the
welcome card, the page footer, the app icon and the share card — and the
sentences naming the PMPG in the OpenGraph and Twitter descriptions. The legal
notice and the Privacy screen no longer name it.

#### Address formatter

[`@fragaria/address-formatter`](https://github.com/fragaria/address-formatter)
v7.0.0, MIT licensed, bundled verbatim at
`public/assets/js/vendor/address-formatter.js` (see the README there). It
supplies the country-specific address layouts hybrid mode grades against, so
it is committed rather than loaded from a CDN — a kiosk that could not reach
the network would otherwise use one layout for every country and mark correct
answers wrong.

#### Background image

Derived from [Simplified World Map.svg](https://commons.wikimedia.org/wiki/File:Simplified_World_Map.svg)
by Guilherme de Souza Vieira and Hogweard (Wikimedia Commons), licensed under
[CC BY-SA 3.0](https://creativecommons.org/licenses/by-sa/3.0/deed.en).
Colors are replaced at request time to match the application theme; no structural changes are made.

## License

This project is licensed under the [GNU General Public License v3.0](LICENSE).
