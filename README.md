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

This game was created as an educational tool by **Xavier Dubois** and **Niel Buchan**, and is supported by the **Payments Market Practice Group (PMPG)**. It is developed and maintained by its authors; the PMPG endorses it but does not operate it.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the [GNU General Public License](https://www.gnu.org/licenses/gpl-3.0.html) for more details.

## Features

- **Supported by the PMPG** — The Payments Market Practice Group endorses the
  game. Its lockup appears on the welcome card, the page footer, the app icon
  and the share card. The game remains the work of its authors, and the mark is
  not covered by the GPL — see [Legal notice](#legal-notice) and
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
2. Toggle "Kiosk Mode" at the top of the dashboard
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

**Note:** Kiosk mode is session-only and resets on page reload.

**iPad Setup Guide:**
For an optimal kiosk experience on iPad, add the app to your home screen and enable Guided Access:

1. Open this page in **Safari** on the iPad
2. Tap the **Share** button (square with arrow) in Safari toolbar
3. Choose **Add to Home Screen**, then tap **Add**
4. Open the app from the home screen icon (launches fullscreen)
5. Enable **Guided Access**: Settings → Accessibility → Guided Access → set passcode
6. Triple-click the Side button to start Guided Access (locks to this app)
7. Triple-click again and enter passcode to stop Guided Access

### 7. The Hall of Fame wall (`?mode=hof`)

A public GET route, `/board/data`, feeds the wall display. It answers JSON
without a session and without a CSRF token, unlike every other API route here.

That is deliberate. The rest of the API is POST-only with a CSRF token bound to
the PHP session, whose default lifetime is 24 minutes. A screen that polls the
server from six in the evening until the room empties would lose its session
and see every call fall to 403 — silently, around midnight, with nobody
standing in front of it. A public GET removes that failure mode at the root.
Nothing is exposed that the Hall of Fame does not already show any anonymous
visitor: the same names, the same scores, the same ordering.

| Setting | Where | Default | Meaning |
|---|---|---|---|
| `board_window_hours` | Admin → Wall Window | `24` | How far back the wall looks, in hours. `0` means since forever. Validated server-side to 0–8760. |

`board_window_hours` applies to `?mode=hof` and to nothing else. The Hall of
Fame served to phones, to desktop browsers and to the iPad kiosk stays
all-time, so narrowing the wall to one evening never erases the record
everywhere else.

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
- **Security headers**: CSP, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy
- **Subresource Integrity (SRI)**: All CDN resources loaded with `integrity` hashes to prevent supply-chain attacks
- **Host header validation**: `HTTP_HOST` validated against safe patterns to prevent host injection
- **Admin PIN**: Stored only in `config/credentials.php` — never in the database. A PIN typed into that file in clear is accepted once and then replaced in place by a bcrypt hash of itself, so it does not stay readable. The file is rewritten atomically and the write is abandoned unless the AES encryption key alongside it survives intact. Installs that predate this have their PIN migrated out of the `settings` table on first use and the row removed
- **Prepared statements**: All database queries use parameterised PDO statements
- **Input validation**: Server-side bounds on all inputs (score 0–100, time 0–3600s, name 1–50 chars). Note that these are *bounds*, not proof of authenticity — see "Scoring is client-authoritative" below
- **XSS prevention**: `escapeHtml()` on client, `htmlspecialchars()` on server for all dynamic output. "Did you know?" facts accept a little inline markup and are therefore run through an allowlist sanitiser (`<a href>`, `<b>`, `<strong>`, `<i>`, `<em>`, `<br>`) on both write and read. The browser enforces the same allowlist independently before rendering a fact, so a row written by an older version cannot reach the DOM as markup
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

## Continuous Integration

`.github/workflows/ci.yml` runs on every push and pull request to `main`:

| Job | What it does |
|---|---|
| **PHP** | PHPUnit on 8.1 and 8.4 — the floor this project advertises and the current release |
| **JavaScript** | Vitest unit suite |
| **End-to-end** | Playwright against a throwaway SQLite instance |
| **SonarCloud** | Static analysis with merged PHP + JavaScript coverage |

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
the mark. Remove the logo assets and the "Supported by" wording before
redistributing a modified version.

Concretely, that means `public/assets/images/pmpg-logo.png`,
`public/assets/images/pmpg-mark.png`, and every "Supported by" block that
references them — the welcome card, the page footer, the app icon and the
share card.

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
