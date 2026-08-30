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

# DESIGN — ISO 20022 Address Structuring Game

## 1. Project Vision

A secure, high-performance Single Page Application (SPA) built to educate users on ISO 20022 address structuring (Standard Release 2026). Designed for kiosk-style deployment on tablets in landscape mode, featuring a touch-first interface and a "zero-refresh" dynamic experience.

## 2. Technical Architecture (MVC)

### 2.1 Model (Data & Logic)

- **Database**: MySQL with PDO
- **Encryption**: AES-256-GCM (authenticated encryption) via `openssl_encrypt` for player names (PII). The legacy AES-256-CTR format is still *readable*, but only for leaderboard rows written before the GCM migration: it carries no MAC, so share tokens — which are attacker-supplied — are accepted in GCM form only. A missing or too-short key is a fatal error rather than a silent fallback to an empty key
- **Data Parsing**: `PhpOffice\PhpSpreadsheet` for Excel scenario extraction
- **Validation**: Chip-to-Slot accuracy based on PMPG rules

### 2.2 View (UI/UX)

- **Framework**: PicoCSS (semantic HTML, minimal footprint)
- **Branding** (Color Palette) — sampled from the PMPG logo:
  - Primary: `#3d345f` (PMPG Violet)
  - Primary Hover: `#2c2646` (Violet, darkened)
  - Primary Light: `#dceaf3` (Pale Blue)
  - Background: `#8abed9` (Sunburst Blue)
  - Text/Headers: `#3d345f` (PMPG Violet)

  Contrast, measured rather than assumed: `#3d345f` on `#8abed9` ≈ 5.7:1 and on
  white ≈ 12:1; white on `#3d345f` ≈ 12:1 — all above WCAG AA. `color_text` and
  `color_bg` are used together as body text on the page background, so do not
  change either without re-checking.

  The palette exists in **three** places and they must agree:
  `App\Models\ThemeModel::DEFAULTS`, `themeDefaults` in
  `public/assets/js/app.js`, and the `:root` block in
  `public/assets/css/app.css`. `tests/ThemeDefaultsSyncTest.php` parses the
  latter two out of their real source files and fails on any drift — a
  divergence is otherwise close to invisible.

- **Resetting the theme** — `admin/reset-theme` (`AdminController::resetTheme()`
  → `ThemeModel::reset()`) **deletes** the five `color_*` rows rather than
  writing the palette back into them. `get()` starts from `DEFAULTS` and only
  overrides keys the database holds, so an installation with no rows tracks the
  defaults exactly as a fresh install does. Writing the hex values back would
  instead pin the installation to today's palette, and it would then silently
  ignore every future change of defaults.

  This is the only migration path for an already-deployed installation. One
  that never saved a theme picks up the PMPG palette on update by itself; one
  that saved a theme keeps its colours until an admin presses the button.
  Nothing migrates automatically — overwriting colours an admin deliberately
  chose is not the update's call.
- **Animations**: `canvas-confetti` for high-score celebrations

### 2.3 Controller (Traffic & API)

- **Front Controller**: `public/index.php` serves as SPA entry point
- **API Routes**: All communication via POST with `X-Action` header
- **No URL parameters** — prevents state-tampering and maintains clean kiosk URL
- **One exception**: `POST /webhook/github` — GitHub's webhook delivery. No `X-Action`
  header, no session, no CSRF token (GitHub is a machine caller with neither);
  authenticated instead by an HMAC-SHA256 signature. See § 5 and § 6

## 3. Game Mechanics

### Structured Mode
Each chip must match its specific semantic slot:
- `StrtNm` → `<StrtNm>`
- `BldgNb` → `<BldgNb>`
- `PstCd` → `<PstCd>`
- `TwnNm` → `<TwnNm>` (mandatory)
- `Ctry` → `<Ctry>` (mandatory)

### Hybrid Mode
- `TwnNm` and `Ctry` are mandatory slots
- Other components can be grouped into two `<AdrLine>` slots (max 70 chars each)

### Scoring
- **Composite Game Score**: `Math.round(pct × pct × (1 + 500 / Math.max(1, seconds)) / 10)`
- Accuracy dominates: it is squared, so 90% scores 81% of what 100% would
- Speed is an inverse bonus with no cap — a fast run multiplies the accuracy
  term substantially, and there is no cut-off at five minutes
- Hall of Fame and admin leaderboard both sort by this score, computed in SQL
  (`LeaderboardModel::GAME_SCORE_EXPR`) so the ordering matches the JavaScript
  in `computeGameScore()`. Keep the two in step when changing either

**Scoring is client-authoritative.** The browser computes the percentage and
posts it, and in hybrid mode supplies the country-specific field order the
server grades against. The server bounds-checks but does not recompute, so the
Hall of Fame is for fun rather than adjudication.

### Social Sharing
- Players can share their score via an encrypted URL token (AES-256-GCM)
- Share page serves OpenGraph meta tags for Facebook/Twitter previews
- Dynamic 1200×630 PNG share card generated server-side (Imagick, GD fallback)
- Share card features theme-branded colors with decorative balloons
- The card closes with `Supported by` and the PMPG lockup on a white plate.
  This is the branding's most public surface — a LinkedIn post shows it to
  people who never open the game — so both render paths draw it
- **Balloons are kept off it.** `ShareController::exclusionZones()` reserves
  the centre text block *and* the endorsement strip, and
  `planBalloons()` — extracted from the drawing code precisely so it can be
  tested — honours both. The layout is deterministic per seed, so
  `tests/ShareCardLayoutTest.php` checks 300 seeds rather than the one the
  application uses; without the endorsement zone, seed 4 alone puts a balloon
  on the logo
- `og:site_name` and the descriptions name the PMPG. Titles stay short —
  LinkedIn truncates around 70 characters, and an endorsement past the
  ellipsis is worth nothing
- Gzip-encoded image responses for Facebook crawler compatibility
- **Mobile**: Native share button (navigator.share) with clipboard fallback
- **Kiosk Mode**: QR code displayed for scanning and sharing on mobile devices

### Session Management
- 30s inactivity timer triggers a 10s countdown overlay (during active game)
- Global "Stop" button always available for immediate reset
- Custom overlay modals replace native `alert()` / `confirm()` to maintain fullscreen mode

### Event Code Gate (Optional)

- **Configuration**: Admin sets a plaintext code (max 64 chars) which is hashed with bcrypt on save
- **Status Check**: `POST game/event-code-status` returns `{required: true/false}` without revealing the code
- **Verification**: `POST game/verify-event-code` with rate limiting:
  - 5 failed attempts → 30-second lockout, keyed on a hash of the caller's
    address in the `rate_limits` table, so discarding the session cookie does
    not reset the counter
  - `password_verify()` for secure constant-time comparison
  - Success sets `$_SESSION['event_code_ok'] = true`
- **Enforcement**: `GameController::isEventCodeSatisfied()` is consulted by
  `public/index.php` before dispatching any gameplay, scoring or share action.
  The gate is *not* a client-side screen: calling the endpoints directly without
  a verified session returns 403. Admin sessions are exempt so an administrator
  cannot be locked out of their own installation
- **Session Persistence**: Once unlocked, the session can access the game until
  `POST game/reset-session` clears it — which the client calls when the player
  presses Stop or the inactivity timer fires. On a shared kiosk this re-locks
  the gate for the next player
- **Frontend**: Gate screen uses same `.welcome-card` styling as the main game box; error messages display inline with rate limit feedback

### Kiosk Mode (Optional)
- **Toggle**: Admin dashboard includes session-based kiosk mode switch
- **Fullscreen**: Auto-enters fullscreen when enabled; re-enters if user exits
- **Screen Saver**: After 60s of inactivity (no click/touch/key):
  - Displays full-screen overlay with same background as game
  - Shows countdown to ISO 20022 deadline
  - Displays pulsing CTA: "Touch to play" or "Click to play" (auto-detected)
  - Rotates fun facts every 20 seconds
  - Dismisses on any touch/click interaction
- **Reset**: Kiosk mode is session-only and resets on page reload

### Fun Facts
- **Database**: 10 default facts about ISO 20022 created on fresh install
- **Admin Management**: Add, edit, delete facts (max 500 characters, measured
  after sanitisation). A small allowlist of inline markup is permitted —
  `<a href>`, `<b>`, `<strong>`, `<i>`, `<em>`, `<br>` — and everything else is
  reduced to its text by `HtmlSanitizer`, on write *and* on read — except for
  elements whose contents are code rather than prose (`<script>`, `<style>`,
  `<iframe>`, `<object>`, `<template>`, `<title>`), which go entirely.
  `public/assets/js/lib/sanitize.js` enforces the same allowlist independently
  in the browser, and the two must agree element for element; both test suites
  assert the drop list with content inside, which is what catches a drift.
  Facts render through `innerHTML` on public screens, so this is the boundary
  that keeps an admin-authored fact from executing in every visitor's browser
- **Display**: Rotates on welcome screen and screen saver (20s interval)
- **API**: Public `GET /api/game/facts` endpoint returns all facts

### PMPG Endorsement
- The white welcome card closes with a `Supported by` label and the PMPG
  lockup, above a hairline rule (`.card-endorsement` in `app.css`)
- Rendered by `endorsementHtml()` in `app.js`, called from **both**
  `.welcome-card` renders — `renderWelcomeCard()` and `renderEventCodeGate()`.
  The gate is the first screen a player sees whenever an event code is set, so
  a block present on only one of them would be missing at exactly the events
  this game is run at
- The logo is **not** a link. A kiosk runs in Guided Access, where an outbound
  navigation strands the player in a browser they cannot leave
- `alt="Payments Market Practice Group"` is load-bearing, not decorative: the
  logo *is* the statement of support, so a screen reader must announce it
- The page footer carries the same `Supported by` + lockup pair at ~24px
  (`.footer-endorsement`), in `app/Views/layout.php` — so it holds on every
  screen, not only the welcome card. Hidden below 768px, where both logos
  would otherwise land in one short viewport; the card keeps its own
- The `<h1 class="logo">ISO 20022 Address Game</h1>` heading stays text and
  stays the title. The PMPG lockup does not replace it — the game keeps its
  own name
- The apple-touch-icon (`AppIconController`) composites `pmpg-mark.png` — the
  sunburst alone — on a **white disc** over the themed ground. The disc is not
  decoration: the sunburst's lower petals fade to near white and vanish
  against `#8abed9`, and it holds for any background an admin might choose.
  Both render paths, Imagick and the GD fallback, draw the same thing; a host
  without Imagick still serving the old icon would be a half-applied rebrand.
  `emoji-controller.png` stays in the repo so reverting is a one-line change
- Served from `public/assets/images/`, never a CDN — the CSP allows
  `img-src 'self' data:` as it stands, and widening it for a logo would trade
  security for nothing
- **The mark is not under the GPL.** The licence covers this project's source;
  it grants no right to the PMPG's trademarks. A fork must strip the logo
  assets and the "Supported by" wording — see README § *Third-party assets*
- The endorsement wording is load-bearing in one more place: the Privacy
  screen. It says the PMPG *endorses* the game and does not *operate* it,
  while § 1 Data Controller continues to name only Xavier Dubois and Niel
  Buchan. The PMPG processes no personal data, and naming it there would be an
  inaccurate GDPR declaration — do not "tidy" the two paragraphs together

### Responsive Design
- Hamburger menu on mobile (≤768px) collapses header navigation
- Grid layout adapts to single-column on smaller screens

## 4. Data Structures

### 4.1 Excel Specification (Scenarios.xlsx)

**Sheet 1 — Scenarios:**

| Column | Description |
|--------|-------------|
| StrtNm | Street Name |
| BldgNb | Building Number |
| PstCd | Postal Code |
| TwnNm | Town Name (mandatory) |
| Ctry | Country Code — ISO 2-letter (mandatory) |
| AdtlAdrInf | Additional address info (e.g., "Floor 10") |

### 4.2 Database Schema

```sql
scenarios:    id, json_data, created_at
leaderboard:  id, encrypted_name, score, time_seconds, created_at
settings:     setting_key, setting_value, updated_at
facts:        id, content, created_at
game_counter: id, played_at                       -- one row per completed game
rate_limits:  bucket, attempts, updated_at, locked_until
```

`App\Models\Database::initSchema()` is the authoritative DDL (MySQL and SQLite
variants); `scripts/schema.sql` mirrors it for manual installs.

Keys stored in `settings`:

| Key | Value |
|-----|-------|
| `event_code` | bcrypt hash of the event code |
| `event_code_timestamp` | when it was last changed (int), used to invalidate older sessions |
| `unstructured_deadline` | admin-set countdown target, `YYYY-MM-DDTHH:MM` |
| `color_primary`, `color_primary_hover`, `color_primary_light`, `color_bg`, `color_text` | theme palette, validated as hex |
| `update_enabled`, `update_channel`, `update_github_owner`, `update_github_repo` | Automatic Updates configuration |
| `update_webhook_secret` | 32-byte webhook secret, shown to the admin exactly once |
| `update_pending` | JSON target queued for `App\Models\Updater` |
| `update_last_event_*`, `update_last_install_*`, `update_dependencies_changed` | Automatic Updates diagnostics |

Every write to this table goes through `App\Models\SettingsModel`, which is
where the driver-specific upsert lives — MySQL has no `ON CONFLICT` and SQLite
no `ON DUPLICATE KEY`. Writing either spelling inline in a controller is how
`admin/set-deadline` and `admin/set-event-code` came to be hard errors on a
SQLite-backed instance.

### 4.3 Session State

| Key | Purpose | Set By | Cleared By |
|-----|---------|--------|------------|
| `admin` | Admin authentication | `admin/login` | `admin/logout`, session expiry |
| `event_code_ok` | Event code unlocked | `game/verify-event-code` | `game/reset-session`, session expiry |
| `event_code_verified_at` | Timestamp when verified | `game/verify-event-code` | `game/reset-session`, session expiry |
| `csrf_token` | CSRF protection | Session init | Session expiry |
| `schema_version` | Highest `SCHEMA_VERSION` this session has run `initSchema()` for | `ensureSchema()` in `public/index.php` | Session expiry, `SCHEMA_VERSION` bump |

That is the complete list, apart from `schema_ready` — a boolean written by a
version that predated `schema_version`, which `ensureSchema()` recognises and
discards. Failed-attempt counters are **not** among them: they live in the
`rate_limits` table, keyed on a hash of the caller's address, precisely so that
discarding the session cookie does not reset them.

**Session Persistence:** Once verified, the session remains valid across page refreshes until:
- Admin changes the event code (invalidates all sessions, via the stored timestamp)
- Session expires
- The player presses "Stop", or the inactivity timer fires — both call
  `game/reset-session`, which clears the unlock on the server as well as in the
  browser

**Rate Limiting Reset:** When admin changes the event code, all rate limiting counters are reset for all users.

## 5. Security & GDPR

- **Session cookie**: A single strictly necessary PHPSESSID cookie is used for CSRF protection and admin authentication. No tracking cookies.
- **CSRF protection**: All POST requests validated via `hash_equals()` token comparison; violations logged with IP
- **Pseudonymisation**: Player names encrypted with AES-256-GCM (authenticated encryption) at rest
- **Rate limiting**: Address-keyed and stored in `rate_limits`, so it survives a discarded session cookie. Admin login locks after 5 failed attempts (5-minute lockout); event code after 5 (30-second lockout); leaderboard submissions throttled to 10 per 5 minutes. Only a keyed hash of the address is stored, never the address
- **Input validation**: Server-side bounds on score (0–100), time_seconds (0–3600), player name (1–50 chars)
- **Retention**: `App\Models\RetentionCleanup` deletes leaderboard entries after
  365 days and rate-limit rows once they lock nobody out and have been idle for
  24 hours, so hashed addresses are not kept beyond their purpose. Run by
  `scripts/cleanup.php` (cron) and by the poor man's cron fallback in
  `public/index.php`, which run the same class so a host with cron and a host
  without delete exactly the same things
- **XSS prevention**: `escapeHtml()` on client and `htmlspecialchars()` on server for all dynamic output
- **Sessions**: Secure PHP sessions with `session_regenerate_id()`, HttpOnly, SameSite=Strict flags
- **Security headers**: CSP, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy
- **Subresource Integrity (SRI)**: Every CDN resource (PicoCSS, Dropzone, canvas-confetti, Chart.js, qrcode-generator) is loaded with a pinned `integrity` hash and an exact version — never a floating tag, whose contents would change out from under the hash. The address formatter is served locally instead
- **Host header validation**: `HTTP_HOST` validated against `[a-zA-Z0-9.-]+(:\d+)?` pattern to prevent injection
- **Credentials**: `config/credentials.php` excluded from version control, protected by `.htaccess`. `app/`, `scripts/`, `storage/` and `uploads/` carry the same deny rules, for installs whose DocumentRoot is the project root
- **Setup lockdown**: the unauthenticated, CSRF-exempt `setup/*` routes refuse to run once `config/credentials.php` or `config/db_config.json` exists. A database outage therefore cannot be used to repoint the installation or overwrite its encryption key
- **Admin PIN**: Stored only in `config/credentials.php` — never in the database. A PIN typed into that file in clear is accepted once and then replaced in place by a bcrypt hash of itself, so it does not stay readable. The file is rewritten atomically and the write is abandoned unless the AES encryption key alongside it survives intact. Installs that predate this have their PIN migrated out of the `settings` table on first use and the row removed
- **Event Code**: Hashed with bcrypt (never in plaintext), rate limited (5 attempts / 30 sec), constant-time comparison via `password_verify()`, enforced server-side, and never returned to the client — `admin/get-event-code` reports only whether one is set
- **Security logging**: Failed admin logins, event code attempts, and CSRF violations logged with remote IP address
- **Prepared statements**: All SQL queries use parameterised PDO statements (no string interpolation)
- **Cache busting**: CSS/JS URLs include `?v={filemtime}` to force browser refresh on changes
- **Webhook signature**: `/webhook/github` requires `hash_equals('sha256=' . hash_hmac('sha256', $body, $secret), $header)` — the only CSRF-exempt, session-free route in the app, and the one place a bad signature is refused with 403 rather than a generic error
- **Webhook secret**: `bin2hex(random_bytes(32))`, returned to the admin exactly once (in the generate response) and never again — `admin/get-update-settings` reports only whether one is set, same pattern as the event code
- **Update artifact source**: every download URL — the initial one and every redirect hop — is checked against a GitHub-only host allowlist (`App\Models\GitHubUrlValidator`) before a single byte is fetched

## 6. Automatic Updates

- **Trigger**: A GitHub webhook (`POST /webhook/github`, `App\Controllers\WebhookController`)
  or, for a manual check, the admin panel's "Install now" button
  (`admin/install-update-now` → `App\Models\GitHubWebhook::checkAndQueueLatest()`)
- **Two channels only**, mutually exclusive, chosen in the admin panel: `release`
  (a formally published GitHub release) or `main` (every push to the `main`
  branch). `App\Models\GitHubWebhook` decides what a delivery means — repository
  match, channel match, action/branch filters — and never downloads anything
  itself; it only writes the resolved target into the `update_pending` setting
- **Authentication**: HMAC-SHA256 over the raw request body, constant-time
  compared (`hash_equals`), against a 32-byte secret generated in the admin
  panel and shown exactly once. A bad or missing signature is refused (403)
  before the payload is even parsed
- **Execution**: the webhook responds to GitHub immediately, then continues
  after the response is flushed (`fastcgi_finish_request()` where available)
  to run `App\Models\Updater`. A `flock()` on `storage/update.lock` means a
  second delivery arriving mid-install reports `in_progress` and leaves
  `update_pending` untouched, rather than racing the first
- **Install steps** (`Updater::run()`): backup the live tree to
  `storage/backups/` (excluding `storage/`, `uploads/`, and dev-only
  directories) → download (GitHub-host allowlist enforced on every redirect
  hop, `App\Models\GitHubUrlValidator`) → extract → copy over the live tree,
  skipping `config/credentials.php`/`config/db_config.json` → write
  `config/version.php` → invalidate OPcache for every replaced file. Any
  failure from the download step onward restores the backup automatically;
  `update_pending` is cleared only once a definitive outcome (success or a
  recorded failure) is reached, so a process killed mid-install is retried by
  the next visitor's request rather than stranded
- **Dependencies**: the `release` channel's artifact (built by `release.sh`)
  ships `vendor/`, since there is no shell access to run Composer on typical
  shared hosting. The `main` channel's GitHub-generated source zipball does
  not; `Updater` compares `composer.lock` before/after and flags the admin
  panel when it changed
- **Trust model**: this authenticates the *source* (GitHub, HTTPS, a signed
  webhook, the configured owner/repo) but not the artifact's *contents* —
  push access to the configured repository is push access to the install

## 7. Branding — the PMPG endorsement

The rule, so the next person does not have to reconstruct it:

**The PMPG supports the game. Xavier Dubois and Niel Buchan wrote it and
maintain it.** Every piece of wording has to leave both halves standing. The
agreed phrasing is *"Supported by"*, and nothing may imply that the PMPG
authors, publishes or operates the game.

Where the mark appears, and who draws it:

| Surface | Code |
|---|---|
| Welcome card, and the event-code gate | `endorsementHtml()` in `public/assets/js/app.js` |
| Page footer, every screen | `app/Views/layout.php` (`.footer-endorsement`) |
| Apple touch icon | `App\Controllers\AppIconController` — the sunburst on a white disc |
| Share card + home card | `App\Controllers\ShareController` |
| OpenGraph / Twitter meta | `app/Views/layout.php`, `app/Views/share.php` |

Four things that are easy to get wrong:

1. **The mark is not covered by the GPL.** The licence grants rights over this
   project's source code, not over the Payments Market Practice Group's
   trademarks. A fork receives the code and no right to the mark, and must
   strip `pmpg-logo.png`, `pmpg-mark.png` and the "Supported by" wording
   before redistributing. README § *Third-party assets* says so in the place a
   forker will actually look.
2. **The PMPG is not a data controller.** The Privacy screen says it endorses
   the game and does not operate it; § 1 Data Controller names only the two
   authors. Naming the PMPG there would be an inaccurate GDPR declaration. The
   two paragraphs sit close together and must not be merged.
3. **The logo is never a link.** A kiosk runs in Guided Access, where an
   outbound navigation strands the player in a browser they cannot leave.
4. **The lockup keeps its own colours, on a light ground.** The sunburst fades
   to near white at the bottom, so it needs the white disc on the icon and the
   white plate on the share card. Do not recolour it and do not stretch it —
   both are trademark problems, not merely ugly.

The game keeps its own name. `<h1 class="logo">ISO 20022 Address Game</h1>` is
text, and the PMPG lockup does not replace it.

## 8. Directory Structure

```
/project-root
├── app/
│   ├── .htaccess       # Denies direct web access
│   ├── Controllers/    # API Logic
│   │   ├── AppIconController.php    # Apple Touch Icon: themed ground + PMPG sunburst on a white disc (Imagick, GD fallback)
│   │   ├── BackgroundController.php # Themed Background SVG
│   │   ├── WebhookController.php    # POST /webhook/github — signature-verified, session-free
│   │   └── ...
│   ├── Models/         # DB, Encryption, Excel Parsing, HTML sanitisation, rate limiting
│   │   ├── GitHubWebhook.php        # Decides what a webhook delivery means; queues the install
│   │   ├── GitHubUrlValidator.php   # GitHub-only host allowlist for download URLs
│   │   ├── RetentionCleanup.php     # The scheduled deletions, shared by cron and its fallback
│   │   ├── SettingsModel.php        # The only writer of the settings table; owns the upsert dialect
│   │   └── Updater.php              # Backup → download → extract → install → rollback
│   ├── Support/        # Url — request-derived URLs, host validation
│   └── Views/          # Server-rendered pages only: layout, setup, share, share-go.
│                       # Game, admin, leaderboard and privacy screens are rendered
│                       # client-side by app.js.
├── config/
│   ├── .htaccess       # Protects config files
│   ├── credentials.php # DB Passwords & AES Keys (gitignored)
│   ├── db_config.json  # Legacy DB config, still read (gitignored)
│   └── version.php     # Release tag/commit, written by release.sh
├── public/
│   ├── index.php       # Front Controller
│   ├── .htaccess       # URL rewriting
│   └── assets/
│       ├── css/        # Stylesheets
│       ├── js/
│       │   ├── app.js           # The SPA (loaded as an ES module)
│       │   ├── admin-update.js  # Admin panel's Automatic Updates section
│       │   ├── lib/             # Pure logic extracted from app.js for tests/js/*.test.js
│       │   └── vendor/          # Bundled @fragaria/address-formatter (see its README)
│       ├── fonts/      # Bundled fonts (Liberation Sans)
│       └── images/
│           ├── pmpg-logo.png           # PMPG lockup — welcome card, footer, share card
│           ├── pmpg-mark.png           # PMPG sunburst alone — apple-touch-icon
│           ├── emoji-controller.png    # Previous icon glyph, kept so a revert is one line
│           └── world_map.svg           # Background map SVG
├── scripts/
│   ├── .htaccess           # Denies direct web access
│   ├── cleanup.php         # GDPR retention cron job
│   ├── schema.sql          # Database schema for manual installs
│   ├── e2e.sh              # Playwright harness: throwaway instance, run, teardown
│   ├── e2e-router.php      # php -S router standing in for public/.htaccess
│   └── e2e-seed-config.php # Writes a scratch config/credentials.php for e2e.sh
├── storage/            # Runtime data (last_cleanup timestamp, update.lock, backups/)
├── tests/              # PHPUnit tests; DB-backed ones use in-memory SQLite
│   ├── ThemeDefaultsSyncTest.php  # Fails if the PHP/JS/CSS palettes drift apart
│   ├── ShareCardLayoutTest.php    # 300 seeds: no balloon may cover the logo or the text
│   ├── Support/        # UsesInMemoryDatabase trait
│   ├── js/             # Vitest unit tests for public/assets/js/**
│   └── e2e/            # Playwright end-to-end tests (specs/, playwright.config.js)
├── uploads/            # Transient Excel uploads, deleted after parsing
├── vendor/             # Composer dependencies
├── node_modules/       # JS test tooling only (gitignored) — see package.json
├── composer.json
├── package.json        # Dev-only: Vitest + Playwright, never required in production
├── phpunit.xml
├── vitest.config.js
├── release.sh          # Tags a release, builds+publishes the update artifact, writes config/version.php
├── README.md
├── DESIGN.md
└── LICENSE

Note: `deploy.sh` is referenced by the maintainers' workflow but is gitignored
(it carries production FTP credentials) and is not part of a clone.
```
