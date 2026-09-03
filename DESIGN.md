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

**Scoring is client-authoritative.** The browser computes the percentage and
posts it, and in hybrid mode supplies the country-specific field order the
server grades against. The server bounds-checks but does not recompute, so the
Hall of Fame is for fun rather than adjudication.

### Social Sharing
- Players can share their score via an encrypted URL token (AES-256-GCM)
- Share page serves OpenGraph meta tags for Facebook/Twitter previews
- Dynamic 1200×630 PNG share card generated server-side (Imagick, GD fallback)
- Share card features theme-branded colors with decorative balloons
- The card closes with the PMPG lockup on a white plate, with no label above
  it. This is the branding's most public surface — a LinkedIn post shows it to
  people who never open the game — so both render paths draw the same thing
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
- **API**: Public `GET /api/game/facts` endpoint returns all facts

### PMPG Endorsement
- The white welcome card closes with the PMPG lockup above a hairline rule
  (`.card-endorsement` in `app.css`). The lockup stands alone: the `Supported
  by` label that used to sit above it was removed on 2026-09-01 at the
  maintainer's request, on every surface at once
- Rendered by `endorsementHtml()` in `app.js` at the foot of
  `renderWelcomeCard()` — the first screen every player sees
- The logo is **not** a link. A kiosk runs in Guided Access, where an outbound
  navigation strands the player in a browser they cannot leave
- `alt="Payments Market Practice Group"` is load-bearing, not decorative, and
  more so since the label went: the lockup *is* the whole statement now, and a
  screen reader that skipped it would find nothing else on screen saying who
  supports the game
- The page footer carries the same bare lockup at ~24px
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
- **Cache busting**: every asset URL carries `?v={filemtime}.{release commit}`, and the shell that mints them is explicitly `no-store`. All three parts are load-bearing. The mtime alone fails a deploy whose FTP client preserves timestamps — the URL is unchanged, so a browser keeps its stale copy; the release commit from `config/version.php` covers that, and the mtime in turn covers a file edited between releases. The `no-store` on the shell is what stops a cached page from handing out the *previous* set of stamps forever, which no amount of versioning downstream can recover from. `app.js`'s `import`s are versioned through an import map (`layout.php`), since a module specifier the browser resolves itself would otherwise carry no version at all; `tests/AssetCacheBustingTest.php` fails if an import is added without a matching map entry
- **Untrusted numbers at a render boundary**: the Hall of Fame wall polls
  `/board/data` unattended for hours and builds its rows by string
  concatenation. Names are escaped; the numbers pass through `boardNumber()`
  (`lib/board.js`), which returns a finite number or 0, so a field that arrived
  as a string cannot reach `innerHTML` — or the `data-entry-id` attribute — as
  markup. See § *Display modes* for why the server-side `(int)` cast is not
  considered sufficient on its own
- **Client-side HTML sanitisation**: "Did you know?" facts carry admin-authored inline markup, so they are rendered as HTML rather than escaped. `public/assets/js/lib/sanitize.js` applies the same allowlist as `App\Models\HtmlSanitizer` before anything reaches the DOM, parsing with `DOMParser` (an inert document that never runs scripts) and rebuilding from allowed nodes only. The two implementations are deliberately kept in step — same tags, same attributes, same URL schemes, same treatment of `<script>`/`<style>` (dropped with their contents) and of a link whose `href` is rejected (unwrapped, not merely stripped). Sanitising on both ends means a fact written by an older version, or a missed server-side call, still cannot execute in a visitor's browser
- **CSPRNG for index selection**: `lib/random.js` draws from `crypto.getRandomValues` with rejection sampling rather than `Math.random()`. Which fact appears first is not a secret; the point is that every avoidable finding a scanner raises is one more a reviewer must dismiss before reaching a real one

## 6. Branding — the PMPG endorsement

The rule, so the next person does not have to reconstruct it:

**The PMPG supports the game. Xavier Dubois and Niel Buchan wrote it and
maintain it.** Every piece of wording has to leave both halves standing, and
nothing may imply that the PMPG authors, publishes or operates the game.

The agreed phrasing is *"Supported by"*, and it still reads that way wherever
the relationship is stated in prose: the README's legal notice, the Privacy
screen, and the OpenGraph and Twitter descriptions. What it no longer does is
label the logo. The `Supported by` caption that sat above the lockup on the
welcome card, the footer and the share card was removed on 2026-09-01 at the
maintainer's request; the four surfaces now show the mark on its own. If that
ever needs revisiting, note what the caption was doing: it was the thing that
stopped a bare lockup reading as "the PMPG publishes this", which is precisely
the reading the agreement rules out. The prose statements are what carry that
weight now.

Where the mark appears, and who draws it:

| Surface | Code |
|---|---|
| Welcome card | `endorsementHtml()` in `public/assets/js/app.js` |
| Page footer, every screen | `app/Views/layout.php` (`.footer-endorsement`) |
| Apple touch icon | `App\Controllers\AppIconController` — the sunburst on a white disc |
| Share card + home card | `App\Controllers\ShareController` |
| OpenGraph / Twitter meta | `app/Views/layout.php`, `app/Views/share.php` |

Four things that are easy to get wrong:

1. **The mark is not covered by the GPL.** The licence grants rights over this
   project's source code, not over the Payments Market Practice Group's
   trademarks. A fork receives the code and no right to the mark, and must
   strip `pmpg-logo.png`, `pmpg-mark.png` and the endorsement wording that
   remains in prose before redistributing. README § *Third-party assets* says
   so in the place a forker will actually look.
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
├── README.md
├── DESIGN.md
└── LICENSE

Note: `deploy.sh` is referenced by the maintainers' workflow but is gitignored
(it carries production FTP credentials) and is not part of a clone.
```
