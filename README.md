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

# ISO 20022 Address Structuring Game

An interactive kiosk-style game to educate users on ISO 20022 postal address structuring (Standard Release 2026). Built for tablets in landscape mode with a touch-first interface.

## Documentation

Three documents, three questions. This one answers the first.

| Document | Question |
|---|---|
| **`README.md`** (this) | How do I install, run and deploy it? |
| [`SPECIFICATIONS.md`](SPECIFICATIONS.md) | What must it do, and how well? Functional and non-functional requirements. |
| [`DESIGN.md`](DESIGN.md) | Why is it built this way? Trade-offs, and how the project assures itself. |

This file used to carry all three. It ran to a thousand lines, and the part a
newcomer needed was buried in the part only a maintainer needed.


## Legal notice

This game was created as an educational tool by **Xavier Dubois** and **Niel Buchan**. It is developed and maintained by its authors.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the [GNU Affero General Public License](https://www.gnu.org/licenses/agpl-3.0.html) for more details.

## Features

- **Drag & Drop Gameplay** — Drag address chips into correct ISO 20022 semantic slots
- **Structured & Hybrid Modes** — Practice both address structuring approaches
- **Hall of Fame** — Encrypted leaderboard ranked by a game score that weights accuracy quadratically and rewards speed, GDPR-compliant 365-day retention
- **Social Sharing** — Encrypted share tokens with OpenGraph meta tags and dynamically generated 1200×630 PNG share cards
- **Dynamic Apple Touch Icon** — Themed PNG home-screen icon, regenerated automatically when the theme changes
- **Theme System** — 5 customisable colours (primary, hover, light, background, text) editable via admin panel
- **Admin Panel** — PIN-protected dashboard for uploading scenarios via Excel
- **Screen Saver** — Displays countdown, fun facts, and touch-to-play CTA when idle
- **Fun Facts** — Rotating educational facts about ISO 20022 (customisable via admin)
- **Privacy by Design** — AES-256-GCM authenticated encryption at rest, GDPR-compliant privacy notice
- **Display modes** — `?mode=hof` drives an unattended Hall of Fame wall that
  refreshes itself and survives a network outage; `?mode=play` drives a standing
  play station with an on-screen keyboard and an end-of-game that hands the
  machine back to the next player. See [Display modes](#running-the-two-dedicated-screens)
- **Responsive** — Mobile hamburger menu, touch-first design for tablets
- **Cache Busting** — Theme-aware cache busting for background images and icons (includes theme colours + file mtimes)

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

### 5. Customise Theme (Optional)

1. Access the Admin panel
2. Navigate to the "Theme" section
3. Adjust any of the 5 colour variables:
   - **Primary** — Main brand colour (buttons, chips, accents)
   - **Primary Hover** — Darker shade for hover states
   - **Primary Light** — Very light tint for filled slots/highlights
   - **Background** — Page background and image background
   - **Text** — Dark text and headings
4. Changes apply immediately and update the Apple Touch Icon automatically

The defaults are `#3d345f` violet and `#8abed9` blue.

**Reset to default colours** discards this installation's saved colours — it
*deletes* them rather than writing today's defaults in. That distinction is
deliberate: an installation with no saved colours follows the application's
defaults, so it will pick up any future change to them, whereas writing the
five values in would pin it to today's palette forever. One click and it is
persisted; reload the page to see it applied.

Upgrading an existing installation: if it never saved a theme, it follows the
new defaults on its own. If it did, it keeps its colours until an administrator
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
saved date always wins. Only a date that actually exists is accepted: a value
such as `2027-02-31T25:99` is refused rather than rolled over to 4 March, and
a browser that is handed an unreadable stored value hides the banner instead
of counting down in `NaN`.

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

## Running the two dedicated screens

Two large screens are driven by a URL rather than by a setting, so that they
come back correctly after a reboot with nobody in the room.

| Screen | URL | Panel |
|---|---|---|
| Hall of Fame wall | `?mode=hof&t=<token>` | 42" portrait, touch, never touched |
| Play station | `?mode=play&t=<token>` | 42" landscape, played standing |

`t=` is this installation's **display mode token**. Admin → *Display modes*
shows both complete URLs with QR codes and ready-to-paste launch commands;
there is nothing to write down.

```bash
chrome --kiosk --app="https://<host>/?mode=hof&t=<token>"
chrome --kiosk --app="https://<host>/?mode=play&t=<token>"
```

The browser's own `--kiosk` is used rather than the Fullscreen API, because
fullscreen triggered from inside a page needs a user gesture — and after a 3am
reboot there is nobody to provide one.

**A wrong or missing token serves the ordinary game, silently.** No error page,
nothing in the log — a wall must never show an error to a room. If both screens
suddenly show menus, the token was regenerated; reopen them with the addresses
the admin panel now prints.

**To reach the admin screen from one of these machines**, load the URL with no
parameters at all. There is no back door in either mode, by design.

### Settings

| Setting | Where | Default | Meaning |
|---|---|---|---|
| `board_window_hours` | Admin → Display modes | `24` | How far back the wall looks. `0` = all time. Validated 0–8760. Applies to the wall only. |
| `sharing_enabled` | Admin → Sharing | on | Whether the end-of-game screen offers sharing. |
| `display_mode_token` | Admin → Display modes | generated on first use | The `&t=` both screen URLs carry. |

The token makes two addresses unguessable. It is a **guard rail, not a security
boundary**: it authenticates nobody, and every API route is as reachable as
before. `SPECIFICATIONS.md` § 8 states the requirements; `DESIGN.md` explains
why it is drawn that way.

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

## Deployment

Deployment is a plain file sync, and it is the only supported way to update
an install: run `composer install --no-dev`, then upload everything except
`tests/`, `.git/` and the local config files. `storage/` and `uploads/` hold
live data — leave the copies on the server alone.

`deploy.sh` does it over FTP, and **is** part of a clone. It holds no secret:

```bash
cp config/deploy.conf.example config/deploy.conf
chmod 600 config/deploy.conf     # then fill in host, user and password
./deploy.sh
```

`config/deploy.conf` is gitignored and excluded from the upload. Before each
transfer the script asks the mirror what it would send and stops if a
credentials file appears in the answer.

Anything already exported in the environment wins over the file, so CI can pass
the three values as secrets and write no file. `DRY_RUN=1 ./deploy.sh` shows exactly what would be uploaded and
deleted without touching the server — worth doing before any `--delete` mirror.

Two things the script does:

- **The password never reaches lftp's command line**, where `ps` would show it
  to every process on the machine. It goes into a private `.netrc` created for
  the run and deleted on exit.
- **The transfer log is filtered.** `--verbose=1` makes lftp echo every
  transfer as `ftp://user:password@host/...`, so the password was printed
  dozens of times per deploy — into a terminal, a log file, or a pasted bug
  report. The verbosity is worth keeping, since the file list is how you see
  what a `--delete` mirror is about to do; the credentials are stripped from
  the stream instead.

`release.sh` refuses to tag while SonarCloud reports any open finding at LOW
severity or above, or any security hotspot awaiting review — and refuses too if
the latest analysis is not of the commit being released. Informational findings
do not block.

Each published release also carries a ready-to-upload zip built by
`release.sh`, which already includes `vendor/`; GitHub's own auto-generated
source zipball does not, so prefer the attached artifact if you deploy from
a release. It sits alongside `evidence.zip`, which the release workflow
attaches — see [Releases and the evidence pack](DESIGN.md#releases-and-the-evidence-pack).

The application never updates itself: nothing in it downloads, installs or
schedules code. An earlier version could install updates from a signed
GitHub webhook; that feature was removed, and an install upgrading past it
deletes the settings it left behind — including its webhook secret — on the
next request. If yours ran it, `storage/backups/` may still hold zips of
previous versions of the site; nothing reads them any more and they can be
deleted.

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

## Credits

This game was built by **Xavier Dubois** and **Niel Buchan**.

### Third-party assets

The third-party terms here are unchanged by this project's move to the AGPL:
each asset keeps the licence it arrived with.

#### Logo images

`public/assets/images/pmpg-logo.png` and `public/assets/images/pmpg-mark.png`
are trademarks, used with permission. Trademarks are not covered by the AGPL v3
licence granted over this project's source code — a copyleft licence over source
grants no rights over a mark. They appear on the welcome card, the page footer,
the app icon and the share card.

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
Colours are replaced at request time to match the application theme; no structural changes are made.

## License

This project is licensed under the
[GNU Affero General Public License v3.0](LICENSE).

It moved from the GPL v3 to the AGPL v3 on 2026-09-03. The difference that
matters for this project is section 13: the GPL is triggered by *distributing*
software, and a hosted game is never distributed — anybody could run a modified
copy as a public kiosk and owe nothing back. The AGPL closes that, by treating
use over a network as the thing that triggers the obligation.
