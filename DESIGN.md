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
- **Branding** (Colour Palette) — sampled from the PMPG logo:
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
- **No URL parameters carry state** — the game itself is driven entirely by
  POSTs, so there is nothing in a URL to tamper with. The one exception is
  `?mode=hof|play`, which selects a display context and nothing else (see
  *Display modes* below); it is read through a strict allowlist and any other
  value falls back silently to the ordinary game
- **One exception to POST-only**: `/board/data` is a public GET without a
  session or a CSRF token, because the wall polls it for a whole evening and a
  session-bound token expires in 24 minutes. It is read-only and returns the
  same public leaderboard the Hall of Fame already shows. Every other API route
  carries `X-Action`, a session and a CSRF token; nothing anywhere acts on an
  unauthenticated request

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
- Both screens also *page* through the same ordering — 20 rows on the public
  Hall of Fame, 50 in the admin dashboard — and the admin listing pages rather
  than truncating for a reason worth stating: it is the only screen that can
  delete an entry, and the entries people ask to have deleted are the ones far
  down the board. It used to answer with the leading 200 rows and nothing
  else, so any installation past its 200th entry had names on the public wall
  that the dashboard could not show, let alone remove. Rank and score are
  rendered from what the server sent; recomputing or re-sorting a single page
  in the browser would number the first row of page four "1"

**Scoring is client-authoritative.** The browser computes the percentage and
posts it, and in hybrid mode supplies the country-specific field order the
server grades against. The server bounds-checks but does not recompute, so the
Hall of Fame is for fun rather than adjudication.

### Social Sharing
- Players can share their score via an encrypted URL token (AES-256-GCM)
- Share page serves OpenGraph meta tags for Facebook/Twitter previews
- Dynamic 1200×630 PNG share card generated server-side (Imagick, GD fallback)
- Share card features theme-branded colours with decorative balloons
- The card closes with the logo on a white plate, with no label above it. This
  is the most public surface the images have — a LinkedIn post shows the card
  to people who never open the game — so both render paths draw the same
  thing
- **Balloons are kept off it.** `ShareController::exclusionZones()` reserves
  the centre text block *and* the strip the logo sits in, and
  `planBalloons()` — extracted from the drawing code precisely so it can be
  tested — honours both. The layout is deterministic per seed, so
  `tests/ShareCardLayoutTest.php` checks 300 seeds rather than the one the
  application uses; without that second zone, seed 4 alone puts a balloon on
  the logo
- Titles stay short: LinkedIn truncates around 70 characters, and anything
  past the ellipsis is worth nothing
- Gzip-encoded image responses for Facebook crawler compatibility
- **Mobile**: Native share button (navigator.share) with clipboard fallback
- **Kiosk Mode**: QR code displayed for scanning and sharing on mobile devices

### Session Management
- 30s inactivity timer triggers a 10s countdown overlay (during active game)
- Global "Stop" button always available for immediate reset
- Custom overlay modals replace native `alert()` / `confirm()` to maintain fullscreen mode

### Access — open to everyone

There is deliberately **no access gate**. An optional event-code gate
(bcrypt-hashed code, rate-limited verification, server-side enforcement in
front of the gameplay endpoints) existed until schema v7 and was removed on
purpose: the game must be playable by anyone who reaches it. Do not
reintroduce a gate casually — its removal also removed the endpoints, the
admin section, the session keys and the stored settings, and
`Database::purgeRemovedEventCodeData()` deletes what older installs still
carry.

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

### Display modes — the wall and the play station

Two deployment contexts sit alongside the three above: a Hall of Fame wall
(`?mode=hof`, a 42" portrait touch panel nobody touches) and a play station
(`?mode=play`, a 42" landscape touch panel played standing), each driven by its
own PC.

Four decisions here are not obvious. They are written down because without the
reasons the next person will undo the work believing they are simplifying.

**The mode lives in the URL, and is resolved server-side.** Not in a session
flag. `kioskMode` is a session flag set from the Admin screen, and that suits an
iPad prepared by hand. It does not suit a PC bolted to a wall: at the first
reload — a Windows update, a power cut, a crashed tab — the screen comes back in
default mode, menus and all, and nobody is standing there to put it right. A URL
parameter survives all of that. It is resolved in `public/index.php` rather than
in JavaScript for two reasons: `app/Views/layout.php` renders the nav, so hiding
it afterwards would flash the menus on every load; and it would still be in the
DOM, reachable by keyboard. **This is a guard rail, not a security boundary** —
the API routes stay open and the leaderboard data is public regardless. Do not
build authentication for it.

**The on-screen keyboard exists because Windows will not show its own.** Windows
only offers its touch keyboard when it detects *no* physical keyboard. The play
station has one, plugged in and tucked away in a corner, so Windows concludes the
user can type and shows nothing. Without `?mode=play`'s keyboard the name field
cannot be filled at all, and no game can be started. It is not a nicety, and it
is scoped to `play` alone: a phone and an iPad both raise a perfectly good
system keyboard, and overriding theirs would be a regression.

**Native sharing is disabled in `play` mode, path and all.** `hasNativeShare()`
returns true as soon as `'ontouchstart' in window`, which is the case on a
Windows touch panel. `navigator.share` then opens the operating system's share
pane *over* the kiosk, and a player will not find their way out of it. Hiding
the button is not enough — `showFinalScore()` returns before the share path is
entered at all.

**The Admin panel is the instructions, not the switch.** Admin → Display modes
gathers the three ways the game reaches a screen, shows both URLs with a Copy
button and a QR code, and prints the `chrome --kiosk` command. It deliberately
contains no control that puts the wall into wall mode: such a control would go
out at that machine's first reboot, which is precisely the problem the URL
solves.

Two supporting choices, for the same reason:

- **`/board/data` is a public GET, declared before `session_start()`.** Every
  other API route is a POST with a CSRF token bound to a session whose default
  lifetime is 24 minutes; a page polling all evening would lose it and fail with
  403s around midnight, silently, in front of nobody. Do not add token refreshing
  to work around what a public route simply does not need. The data is the same
  public leaderboard the Hall of Fame already shows.
- **The wall never blanks on failure.** A failed poll keeps the last good board
  on screen and retries with a backoff capped at 30s; a small dot in the corner
  admits staleness after three consecutive failures. A board frozen two minutes
  ago is worth far more than a blank screen or an error page in front of fifty
  people, and the cap is what brings it back within seconds of the network
  returning.
- **The wall redraws only when what it shows has changed.** A successful poll
  always yields a new response object, so comparing by identity said "changed"
  twelve times a minute and the screen rebuilt its whole DOM each time.
  `wallSignature()` in `lib/board.js` reduces exactly what is rendered — the
  caption, the podium, the rows below it, the highlighted ids, the stale dot —
  to one comparable value, and an unchanged board leaves the DOM alone. This is
  not a performance nicety. Two things on that screen live in the DOM between
  polls and were being destroyed by the rebuild: the arrivals banner, wiped
  about a second into the four it is given, so from the second banner onwards
  every player below the fold lost the only acknowledgement they get; and the
  six-minute anti-burn-in drift, restarted from zero every five seconds, which
  pinned the panel to within two hundredths of a pixel of one position all
  evening. **A rebuild that must happen repaints the banner it interrupted**
  (`paintWallBanner()`), and **the drift animation lives on `.game-main`**, a
  box the wall never replaces — put it back on `.wall-screen` and it stops
  drifting again, invisibly.
- **The wall says which board it is showing.** It is windowed and the Hall of
  Fame on a player's phone is not, so the same run is #1 here with a gold medal
  and 22nd there. Without a caption the two screens simply contradict each
  other in front of the person concerned. The label is derived from the
  `window_hours` the *server* reported — never from a constant here — so a wall
  captioned "Last 24 hours" is one an administrator actually configured.
- **The podium names its own grid columns.** The winner belongs in the middle,
  and the markup is written 2-1-3 to put it there; automatic placement made
  that true only while all three pods existed, so the first score of an evening
  stood off to the left of an empty podium.
- **Every value from `/board/data` is neutralised before it is concatenated
  into the wall's markup.** The player name goes through `escapeHtml()`; the
  four numbers — `game_score`, `time_seconds`, `rank` and the `id` that lands
  inside a `data-entry-id` attribute — go through `boardNumber()` in
  `lib/board.js`, which yields a finite number or 0. `BoardController` already
  casts all four to `(int)`, so this looks redundant and is not: the wall
  trusts a *response*, and an unattended screen polling the same URL all
  evening is the wrong place to assume the far end is still what was deployed.
  A field arriving as a string would otherwise reach `innerHTML` as markup.

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
- **API**: `game/facts`, reached the way every other route is — a POST to `/`
  carrying `X-Action`, a session and a CSRF token. It is public in the sense
  that it needs no admin session, not in the sense of being a GET: there is no
  `/api/game/facts` URL, and asking for one lands on the SPA shell. § 2.3 names
  `/board/data` as the single GET exception in the application, and that is
  still true

### The logo images

Two images ship with the game and are drawn on four surfaces. The notes here
are about how they are drawn, and why each choice is not arbitrary.

- The white welcome card closes with the lockup above a hairline rule
  (`.card-endorsement` in `app.css`), rendered by `endorsementHtml()` in
  `app.js` at the foot of `renderWelcomeCard()`
- The page footer carries the same image at ~24px (`.footer-endorsement`, in
  `app/Views/layout.php`), so it holds on every screen rather than only the
  welcome card. Hidden below 768px, where both would otherwise land in one
  short viewport; the card keeps its own
- **The image is not a link.** A kiosk runs in Guided Access, where an outbound
  navigation strands the player in a browser they cannot leave
- Its `alt` text is load-bearing rather than decorative: a screen reader that
  skipped it would find nothing else on screen carrying the same information
- The `<h1 class="logo">ISO 20022 Address Game</h1>` heading stays text and
  stays the title. The image does not replace it — the game keeps its own name
- The apple-touch-icon (`AppIconController`) composites `pmpg-mark.png` on a
  **white disc** over the themed ground. The disc is not decoration: the mark's
  lower petals fade to near white and vanish against `#8abed9`, and the disc
  holds for any background an admin might choose. Both render paths, Imagick
  and the GD fallback, draw the same thing, so a host without Imagick does not
  quietly serve something different. `emoji-controller.png` stays in the repo
  so reverting is a one-line change
- Served from `public/assets/images/`, never a CDN — the CSP allows
  `img-src 'self' data:` as it stands, and widening it for an image would trade
  security for nothing
- **The marks are not under the AGPL.** The licence covers this project's
  source and grants no rights over a trademark. See README § *Third-party
  assets*

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
| `unstructured_deadline` | admin-set countdown target, `YYYY-MM-DDTHH:MM` |
| `color_primary`, `color_primary_hover`, `color_primary_light`, `color_bg`, `color_text` | theme palette, validated as hex |

Every write to this table goes through `App\Models\SettingsModel`, which is
where the driver-specific upsert lives — MySQL has no `ON CONFLICT` and SQLite
no `ON DUPLICATE KEY`. Writing either spelling inline in a controller is how
`admin/set-deadline` once became a hard error on a SQLite-backed instance.

### 4.3 Session State

| Key | Purpose | Set By | Cleared By |
|-----|---------|--------|------------|
| `admin` | Admin authentication | `admin/login` | `admin/logout`, session expiry |
| `csrf_token` | CSRF protection | Session init | Session expiry |
| `schema_version` | Highest `SCHEMA_VERSION` this session has run `initSchema()` for | `ensureSchema()` in `public/index.php` | Session expiry, `SCHEMA_VERSION` bump |

That is the complete list, apart from `schema_ready` — a boolean written by a
version that predated `schema_version`, which `ensureSchema()` recognises and
discards. Failed-attempt counters are **not** among them: they live in the
`rate_limits` table, keyed on a hash of the caller's address, precisely so that
discarding the session cookie does not reset them.


## 5. Security & GDPR

- **Session cookie**: A single strictly necessary PHPSESSID cookie is used for CSRF protection and admin authentication. No tracking cookies.
- **CSRF protection**: All POST requests validated via `hash_equals()` token comparison; violations logged with IP
- **Pseudonymisation**: Player names encrypted with AES-256-GCM (authenticated encryption) at rest
- **Rate limiting**: Address-keyed and stored in `rate_limits`, so it survives a discarded session cookie. Admin login locks after 5 failed attempts (5-minute lockout); leaderboard submissions throttled to 10 per 5 minutes. Only a keyed hash of the address is stored, never the address
- **Input validation**: Server-side bounds on score (0–100), time_seconds (0–3600), player name (1–50 chars)
- **Input shape**: a JSON body's shape belongs to the caller, so every string
  field read from one goes through `App\Support\Input::string()` (scalars
  coerce exactly as PHP always did; arrays/objects become `''`), instead of
  fataling in `trim()`/`password_verify()` with a visitor-triggerable 500.
  Where `''` is itself a command — `admin/set-deadline`, whose empty string
  clears the deadline — non-strings are rejected outright rather than coerced,
  so a malformed request can never become a destructive one.
  `tests/MalformedJsonInputTest.php` pins all of it
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
- **Security logging**: Failed admin logins and CSRF violations logged with remote IP address
- **Prepared statements**: All SQL queries use parameterised PDO statements (no string interpolation)
- **Cache busting**: every asset URL carries `?v={hash}`, ten hex characters of `md5(filemtime | release commit)`, and the shell that mints them is explicitly `no-store`. Hashed rather than printed: a raw mtime on every URL is a Unix timestamp saying when each file was last touched on the server, which the passive scan reports as timestamp disclosure. Both inputs still decide the value, which is all a cache key needs. All three parts are load-bearing. The mtime alone fails a deploy whose FTP client preserves timestamps — the URL is unchanged, so a browser keeps its stale copy; the release commit from `config/version.php` covers that, and the mtime in turn covers a file edited between releases. The `no-store` on the shell is what stops a cached page from handing out the *previous* set of stamps forever, which no amount of versioning downstream can recover from. `app.js`'s `import`s are versioned through an import map (`layout.php`), since a module specifier the browser resolves itself would otherwise carry no version at all; `tests/AssetCacheBustingTest.php` fails if an import is added without a matching map entry
- **Untrusted numbers at a render boundary**: the Hall of Fame wall polls
  `/board/data` unattended for hours and builds its rows by string
  concatenation. Names are escaped; the numbers pass through `boardNumber()`
  (`lib/board.js`), which returns a finite number or 0, so a field that arrived
  as a string cannot reach `innerHTML` — or the `data-entry-id` attribute — as
  markup. See § *Display modes* for why the server-side `(int)` cast is not
  considered sufficient on its own
- **Client-side HTML sanitisation**: "Did you know?" facts carry admin-authored inline markup, so they are rendered as HTML rather than escaped. `public/assets/js/lib/sanitize.js` applies the same allowlist as `App\Models\HtmlSanitizer` before anything reaches the DOM, parsing with `DOMParser` (an inert document that never runs scripts) and rebuilding from allowed nodes only. The two implementations are deliberately kept in step — same tags, same attributes, same URL schemes, same treatment of `<script>`/`<style>` (dropped with their contents) and of a link whose `href` is rejected (unwrapped, not merely stripped). Sanitising on both ends means a fact written by an older version, or a missed server-side call, still cannot execute in a visitor's browser
- **CSPRNG for index selection**: `lib/random.js` draws from `crypto.getRandomValues` with rejection sampling rather than `Math.random()`. Which fact appears first is not a secret; the point is that every avoidable finding a scanner raises is one more a reviewer must dismiss before reaching a real one

## 6. Branding

The game keeps its own name. `<h1 class="logo">ISO 20022 Address Game</h1>` is
text, and no image replaces it.

The logo images, where they are drawn and why each choice is what it is, are
covered in § 2.2 under *The logo images*. The one point worth repeating here:
the licence over this project's source grants no rights over a trademark, which
is why README § *Third-party assets* names those two files separately.

## 7. Directory Structure

```
/project-root
├── app/
│   ├── .htaccess       # Denies direct web access
│   ├── Controllers/    # API Logic
│   │   ├── AppIconController.php    # Apple Touch Icon: themed ground + PMPG sunburst on a white disc (Imagick, GD fallback)
│   │   ├── BackgroundController.php # Themed Background SVG
│   │   ├── BoardController.php      # GET /board/data — the wall's source; no session, no CSRF, on purpose
│   │   └── ...
│   ├── Models/         # DB, Encryption, Excel Parsing, HTML sanitisation, rate limiting
│   │   ├── RetentionCleanup.php     # The scheduled deletions, shared by cron and its fallback
│   │   └── SettingsModel.php        # The only writer of the settings table; owns the upsert dialect
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
│   ├── e2e-seed-config.php # Writes a scratch config/credentials.php for e2e.sh
│   ├── e2e-coverage-prepend.php # Records PHP coverage per browser request
│   ├── merge-coverage.php  # Merges unit + browser coverage into one Clover report
│   └── serve.sh            # composer serve — local development server
├── storage/            # Runtime data (last_cleanup timestamp)
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
├── sonar-project.properties # SonarCloud analysis + coverage scope
├── .github/workflows/ci.yml # PHP matrix, JS, e2e and SonarCloud
├── release.sh          # Tags, writes config/version.php, waits for release.yml's gates,
│                       # then attaches the deployable artifact to its draft and publishes it
├── README.md           # Install, run, deploy
├── SPECIFICATIONS.md   # Functional and non-functional requirements
├── DESIGN.md           # Why it is built this way, and how it is assured
├── CLAUDE.md           # Instructions for agents working in this repository
└── LICENSE

Note: `deploy.sh` **is** in the repository, and holds no secret. Its
credentials come from `config/deploy.conf`, which is gitignored and excluded
from the upload. It was gitignored until 2026-09-04, and that had a cost worth
recording: the exclusion list deciding what reaches a web server lived on one
laptop, unreviewable, and a second machine deploying from a clone would have
shipped the development toolchain to production.
```

## 8. Assurance — how this project convinces itself

Moved here from `README.md`, which had grown to a thousand lines by absorbing
the reasoning behind every gate. A reader installing the game does not need to
know why the dynamic scan insists on HTTPS; a reader changing how the project
is tested does, and this is where they will look.

`SPECIFICATIONS.md` § 17 states these as requirements. This part says why they
are the ones chosen.

### Security

- **Encryption**: Player names encrypted with AES-256-GCM (authenticated encryption) at rest
- **CSRF protection**: Token-based validation on all POST requests
- **Rate limiting**: Keyed on the client address and stored server-side, so it survives a discarded session cookie. Admin login locks after 5 failed attempts (5-minute lockout); leaderboard submissions throttled to 10 per 5 minutes. Only a keyed hash of the address is stored, and spent rows are deleted by the daily cleanup
- **Session hardening**: HttpOnly, SameSite=Strict, secure cookie flags
- **Security headers**: CSP, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy. The CSP names `frame-ancestors`, `form-action` and `base-uri` explicitly, because none of the three falls back to `default-src` — omitting them leaves them unset rather than restricted. It carries **no `'unsafe-inline'`**: a per-request nonce (`App\Support\Csp`) authorises the few inline `<script>` and `<style>` blocks the application actually serves, so an injected one does not run. A nonce cannot authorise `style="…"` *attributes*, so those were moved into CSS classes rather than bought back with `'unsafe-hashes'`
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


### Static analysis

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

#### SonarCloud findings deliberately not fixed

SonarCloud's own analysis is a third checker, and it is kept at **zero open
findings** — with two standing exceptions, marked *won't fix* in SonarCloud
rather than worked around in code. Both are cases where the rule is right in
general and wrong for this application.

| Rule | Where | Why it stands |
|---|---|---|
| `javascript:S1874` (×7) | `document.execCommand` in `app.js` | Deprecated, and still the only formatting API for a `contentEditable` region that every browser implements. Four calls are the "Did you know?" rich-text editor; one is the clipboard fallback. Replacing them means hand-writing `Range`/`Selection` manipulation on an admin surface whose output is rendered as HTML to every visitor — real regression risk, against a deprecation that has no replacement and no removal date |
| `Web:S7926` (×1) | `user-scalable=no` in `layout.php` | Disabling zoom is a genuine accessibility cost, taken deliberately. The game is a touch drag-and-drop kiosk running under Guided Access, where a mis-started drag would pinch-zoom the page mid-round and the player has no obvious way back |

Neither is a finding to "clean up later": both were considered and declined. If
you disagree, the argument to beat is in this table, not in the linter.

#### There are no baselines

Both commands pass, and they pass because the code is clean — not because a
file forgives what they find. That was not true until recently: PHPStan carried
`phpstan-baseline.neon` with **76** accepted findings and `tsc` carried
`js-typecheck-baseline.json` with **81**. Both were paid off and both files are
gone.

**Green here means "no findings", not "no new findings".** Keep it that way. A
finding is either worth fixing or worth arguing about in review; a baseline is
how it quietly becomes neither.

What paying it off actually involved, since the shape of the debt is the useful
part:

| Debt | Count | What it turned out to be |
|---|---|---|
| `missingType.iterableValue` and friends | 54 | `array` with no value type. Declarative, and worth more than it sounds: writing `list<array{id: int, …}>` down is what let PHPStan check the callers |
| `variable.undefined` | 13 | Views reading variables the controller sets before `include`. A real contract that only existed in a prose comment; now an `assert(isset(…))` the analyser reads |
| Genuine defects | 9 | A loop counter that ran backwards and made its own bound unprovable; a `?? 0` after an `isset`; a `$path &&` that was always true; a property written and never read; a URL guard made redundant by the allowlist narrowing to one literal |
| `Property 'value' does not exist on type 'HTMLElement'` and kin | ~74 | `getElementById()` returns `HTMLElement`, `querySelector()` returns `Element`. The fix is a handful of typed accessors — `inputById`, `canvasById`, `asElement`, `asButton` — which document what each lookup expects instead of asserting it at 74 call sites |
| CDN globals | 11 | `Chart`, `confetti`, `Dropzone`, `qrcode`, reached through `cdnGlobal()`. No `.d.ts`: there is still no `.ts` file in this repository |

One of those found a real latent bug: the screen saver's countdown interval was
stored as an expando property on the overlay element, so it was invisible to
every tool and would have kept firing against a detached node if the overlay
were ever replaced rather than reused. It lives beside its sibling timer now.

The machinery for both baselines is still in place, and
`composer run analyse:baseline` / `npm run typecheck:baseline` still work. That
is deliberate: the alternative to a baseline is not "no baseline", it is
somebody switching the gate off the first time a dependency upgrade produces
fifty findings on a Friday afternoon. Use it if that day comes, and empty it
again afterwards.

##### Regenerating one

> Regenerate a baseline **only** to deliberately accept existing debt you are
> not fixing right now. **Never** to silence a finding your own change just
> introduced — fix that one instead.
>
> A baseline regenerated to hide a regression turns the whole gate into
> decoration while leaving everybody sure it is working.

Neither file exists at the moment, so there is nothing to read that warning in
— which is the point of putting it here instead.


### Dynamic application security testing

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

#### The browser suite is the attack surface

Not ZAP's spider. The end-to-end suite already drives the admin screen from
behind its PIN pad, both dedicated display modes with their token, a full
five-round game, the end-of-game screen and the share flow. A crawler pointed
at this application sees the welcome card and stops. Replaying the suite
through a proxy is the most faithful picture of the real surface that exists.

That has a consequence worth stating: **a failed browser run fails the scan**,
even with no security finding. A scan is only as complete as the traffic it was
given.

#### Why it has to be HTTPS

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

#### The gate

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

#### Why the findings are not in the Security tab

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

#### The report is published in full

The whole `dast-report/` directory — the HTML report and the severity counts —
is uploaded on an evidence run and attached to the Release. That was not always
so: until 2026-09-03 only `dast-severity-summary.json` left the job, and the
release workflow actively refused a pack containing anything more.

The reasoning, so the decision can be re-taken rather than merely inherited.
Against publishing: this repository is public, so Release assets are public,
and so are workflow artifacts — which is less widely known than it should be.
For publishing: the scan target is a throwaway instance on `127.0.0.1` that
`scripts/dast.sh` builds for the occasion, with a generated certificate and a
scratch database. It is not a production host, and the application it probes is
open source, so the report describes code anybody can already read. An evidence
pack that can be audited without a GitHub account is worth something in return.

What does not go away: header and cookie findings on that instance are findings
about the shipped configuration, so they apply to production too. Publishing
them publishes a to-do list before it is done. **That trade is only worth
making while the report stays clean** — the gate at Medium is what keeps it
so, and lowering that gate and publishing the report are not two independent
decisions.

#### What it reports today

**Nothing at Low or above.** The 66 Low findings the scan used to report were
all one of four things, and all four are fixed rather than filtered:

| Finding | What it was |
|---|---|
| `X-Powered-By` leaked | PHP names itself and its exact version unless `expose_php` is off, which this project cannot assume of a shared host. `header_remove()` now covers it whatever the host's `php.ini` says |
| Unix timestamp disclosure | Every asset URL carried `?v={filemtime}`, saying when each file was last touched on the server. The stamp is hashed now — the mtime and the release still decide it, they are just no longer printed |
| `X-Content-Type-Options` missing | On **static** files only. They never reach `public/index.php`, so they were answered with none of its headers — and they are most of what a browser fetches |
| HSTS not set | The same responses, for the same reason |

The last two are set by `public/.htaccess` in production and by
`scripts/e2e-router.php` in the harness, which now serves assets itself rather
than handing them to PHP's built-in server — that discards headers set before
`return false`, so the scan was reporting a site less protected than the
deployed one. A false picture in the safe direction, which is the worse
direction for a scan report to be wrong in.

The remaining 78 findings are Informational: "Modern Web Application",
"Session Management Response Identified" and similar observations that describe
the application rather than fault it.

#### Not an active scan

The passive profile observes traffic and sends nothing of its own. An active
profile would **replay every recorded request with attack payloads, carrying
the session cookies the browser was using** — including an authenticated admin
one, which on this application can purge the leaderboard and overwrite the
scenarios. A passive scan is a gate; an active scan is an attack, and it needs
an exclusion list written before it rather than after.


### Continuous Integration

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

CodeQL runs through GitHub's **default setup**, configured in the repository's
settings rather than by a workflow file in this repo. It covers
`javascript-typescript` and `actions`, and publishes to the Security tab.

There is deliberately no `codeql.yml` here: GitHub refuses a SARIF upload from
an advanced configuration while the default setup is enabled, so a workflow
file would analyse the code and then fail on the upload every single run. The
release pipeline still produces CodeQL's SARIF for its evidence pack, with
`upload: never`, for the same reason.

**CodeQL does not support PHP.** Everything under `app/` — the majority of this
application's logic — is therefore outside it, whichever setup runs. The PHP is
covered by PHPStan, SonarCloud and the passive DAST scan. Worth stating plainly,
because a green badge is a claim somebody will read as more than it is.

#### SonarCloud setup

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


### Releases and the evidence pack

`.github/workflows/release.yml` runs on a `v*` tag **and** on
`workflow_dispatch`. The second matters as much as the first: it is how a set
of evidence is produced for what is already deployed, or how the whole chain is
rehearsed, without cutting a version.

It is deliberately **not** merged with `ci.yml`. That one is the fast loop on
every push and has to stay fast; this one is the slow complete pass. What they
share is the gates, which live in the reusable `checks.yml` both of them call.

#### Ordering

All gates first. The Release is created **only if every one of them is green**,
and the workflow creates it as a **draft**. If a gate fails no Release is
created at all — the tag exists and points at nothing published, which is fixed
by deleting the tag and pushing it again.

`release.sh` is what turns that draft into a published Release, and it is the
only thing that should: it tags, waits for this workflow, attaches the
deployable zip to the draft, and publishes it. So a release is one command, and
a version cannot ship past a red gate — the script reads the run's conclusion
and stops on anything but success.

The script deliberately does **not** create a Release of its own. Both would
target the same tag, the workflow lands last, and it would quietly turn a
published Release back into a draft.

What that trades away is a human reading the evidence *before* the Release goes
public. The pack stays attached to the published Release, so it is still read —
just not as a blocking step. To keep the blocking read instead, push the tag by
hand and finish with `gh release upload` and `gh release edit --draft=false`.

#### The release note

`release.sh` reads `RELEASE_NOTES_FILE` and puts its contents at the head of
the published Release, above the workflow's description of the evidence pack.
The note must cover four things: what changed in the language of somebody
using the game, the bugs fixed said as the symptom that went away, what an
existing installation has to do for backward compatibility — or an explicit
"nothing", because silence there is an oversight rather than an answer — and
the tests that ran.

The test table carries **three** columns: the gate, one line on what it
actually checks, and the result. The middle one is there because the people
most likely to read a release note closely are auditing the project, and have
no reason to know what Vitest or a passive DAST scan is. `Vitest — 111` tells
them nothing they can assess; `Vitest — unit tests for the browser JavaScript,
run without a browser — 111` does.

Below it, `scripts/dependency-inventory.php` appends every dependency, its
version **and its licence**: PHP production and development from
`composer.lock`, JavaScript from `package-lock.json`, and the CDN libraries
scraped from the `<script>` and `<link>` tags in `app/Views/`. It closes with
the compatibility reasoning against this project's own AGPL-3.0 — which is not
decoration, since whether a dependency may be combined with the strongest
copyleft in common use depends entirely on that column. Versions come from the lock files rather than
the manifests, so the list says what shipped and not what a constraint allowed.
The CDN ones are there because they are in no lock file at all and are the only
third-party code a player's browser actually executes.

Without the variable the script warns and keeps the generated commit list. That
is fine for a human cutting a quick patch; it is not fine for an agent, which
has the context to write the note.

#### What is in the pack

Only what each tool emits natively. Nothing in it is written by hand: a
document somebody writes once to describe a pipeline is a document nobody
updates when the pipeline changes, and evidence that has stopped being true is
worse than no evidence.

| Evidence | Where it comes from |
|---|---|
| PHP tests, 8.1 and 8.4 | PHPUnit `--log-junit` |
| JavaScript tests | Vitest `--reporter=junit` |
| End to end | Playwright's own HTML report, one screenshot per test |
| PHP static analysis | PHPStan's output, its version and level, **and the list of the 27 files it analysed** |
| JavaScript static analysis | `tsc`'s output, its version, **and the list of files it checked** |
| CodeQL | the workflow's SARIF |
| SonarCloud | the complete analysis — quality gate, every measure, the same per file, every open issue and every security hotspot, plus a Markdown front page |
| Dynamic scan | the full ZAP report, plus the counts per severity |
| Coverage | the merged Clover report and the JavaScript lcov, computed exactly as the SonarCloud job computes them |
| Coverage note | why the Clover figure and the SonarCloud figure differ, so a reader comparing them is not left guessing |
| Provenance | `manifest.json` and `SHA256SUMS`, and the archive is signed |

#### Why the file lists are there

A clean PHPStan run prints `[OK] No errors` and nothing else. Eighty-three
bytes, which a configuration analysing **zero files** would produce just as
happily — so as evidence it was worth nothing, and it was in the pack for
several releases before anybody looked at it closely.

The list of analysed files is what turns it into a checkable statement. It
comes from `--debug`, which prints each file as it goes; the JSON report was
tried first and lists only files *with* errors, so on a clean run it is an
empty object — exactly as uninformative as the line it was meant to back up.

#### How an auditor checks this pack

The reports are produced by the same pipeline they attest to, and anybody who
can change that pipeline can change what it emits. Self-produced evidence is
worth what it can be cross-checked against, so the pack is built to make that
easy:

1. **`manifest.json`** names the repository, the commit, the workflow run and
   its URL. Nothing in it is set by the repository — every value comes from the
   runner's context.
2. **`SHA256SUMS`** covers every file in the archive. It detects a pack edited
   after the fact.
3. **The archive is signed** by GitHub's own identity through Sigstore, which
   is the part nobody here can forge:

   ```bash
   gh attestation verify evidence.zip --repo xdubois-57/iso20022-address-game
   ```

   That fails if the archive was altered by a byte, or if it was built anywhere
   other than this workflow in this repository.
4. **The run URL** in the manifest leads to a log GitHub timestamps and retains
   and that nobody with write access to this repository can edit. It is the
   independent record; the pack's job is to point at it, not to replace it.

#### One thing it deliberately does not contain

**Videos and traces of tests that passed.** They are what makes an evidence
pack enormous, and a video of a test that behaved is not evidence anybody
watches. Both stay `retain-on-failure` in every mode. Screenshots go to `'on'`
only for a release, driven by `E2E_EVIDENCE` — an ordinary `npm run e2e` stays
light.


