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
- **Branding** (Color Palette):
  - Primary: `#00364a` (Dark Teal)
  - Primary Hover: `#00a3d7` (Bright Blue)
  - Primary Light: `#caf0fe` (Light Blue)
  - Background: `#94e3fe` (Sky Blue)
  - Text/Headers: `#00364a` (Dark Teal)
- **Animations**: `canvas-confetti` for high-score celebrations

### 2.3 Controller (Traffic & API)

- **Front Controller**: `public/index.php` serves as SPA entry point
- **API Routes**: All communication via POST with `X-Action` header
- **No URL parameters** — prevents state-tampering and maintains clean kiosk URL

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
- Dynamic 1200×630 PNG share card generated server-side with GD library
- Share card features theme-branded colors with decorative balloons
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
  reduced to its text by `HtmlSanitizer`, on write *and* on read. Facts render
  through `innerHTML` on public screens, so this is the boundary that keeps an
  admin-authored fact from executing in every visitor's browser
- **Display**: Rotates on welcome screen and screen saver (20s interval)
- **API**: Public `GET /api/game/facts` endpoint returns all facts

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
scenarios: id, json_data, created_at
leaderboard: id, encrypted_name, score, time_seconds, created_at
settings: setting_key, setting_value, updated_at  -- event_code (bcrypt hash), event_code_timestamp (int)
facts: id, content, created_at
```

### 4.3 Session State

| Key | Purpose | Set By | Cleared By |
|-----|---------|--------|------------|
| `admin` | Admin authentication | `admin/login` | `admin/logout`, session expiry |
| `event_code_ok` | Event code unlocked | `game/verify-event-code` | Code change, session expiry |
| `event_code_verified_at` | Timestamp when verified | `game/verify-event-code` | Code change, admin set/clear |
| `event_code_attempts` | Failed code attempts | `game/verify-event-code` | Success, code change, lockout expiry |
| `event_code_lock_until` | Rate limit lockout timestamp | `game/verify-event-code` | Code change, lockout expiry |
| `csrf_token` | CSRF protection | Session init | Session expiry |

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
- **Retention**: Auto-deletion of leaderboard entries after 365 days (cron + poor man's cron fallback)
- **XSS prevention**: `escapeHtml()` on client and `htmlspecialchars()` on server for all dynamic output
- **Sessions**: Secure PHP sessions with `session_regenerate_id()`, HttpOnly, SameSite=Strict flags
- **Security headers**: CSP, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy
- **Subresource Integrity (SRI)**: Every CDN resource (PicoCSS, Dropzone, canvas-confetti, Chart.js, qrcode-generator) is loaded with a pinned `integrity` hash and an exact version — never a floating tag, whose contents would change out from under the hash. The address formatter is served locally instead
- **Host header validation**: `HTTP_HOST` validated against `[a-zA-Z0-9.-]+(:\d+)?` pattern to prevent injection
- **Credentials**: `config/credentials.php` excluded from version control, protected by `.htaccess`. `app/`, `scripts/`, `storage/` and `uploads/` carry the same deny rules, for installs whose DocumentRoot is the project root
- **Setup lockdown**: the unauthenticated, CSRF-exempt `setup/*` routes refuse to run once `config/credentials.php` or `config/db_config.json` exists. A database outage therefore cannot be used to repoint the installation or overwrite its encryption key
- **Admin PIN**: Stored as bcrypt hash; legacy plaintext PINs auto-upgraded on login
- **Event Code**: Hashed with bcrypt (never in plaintext), rate limited (5 attempts / 30 sec), constant-time comparison via `password_verify()`, enforced server-side, and never returned to the client — `admin/get-event-code` reports only whether one is set
- **Security logging**: Failed admin logins, event code attempts, and CSRF violations logged with remote IP address
- **Prepared statements**: All SQL queries use parameterised PDO statements (no string interpolation)
- **Cache busting**: CSS/JS URLs include `?v={filemtime}` to force browser refresh on changes

## 6. Directory Structure

```
/project-root
├── app/
│   ├── .htaccess       # Denies direct web access
│   ├── Controllers/    # API Logic
│   │   ├── AppIconController.php    # Dynamic Apple Touch Icon (Imagick, GD fallback)
│   │   ├── BackgroundController.php # Themed Background SVG
│   │   └── ...
│   ├── Models/         # DB, Encryption, Excel Parsing, HTML sanitisation, rate limiting
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
│       │   ├── app.js  # The SPA
│       │   └── vendor/ # Bundled @fragaria/address-formatter (see its README)
│       ├── fonts/      # Bundled fonts (Liberation Sans)
│       └── images/
│           ├── emoji-controller.png    # Color 🎮 emoji for icon
│           └── world_map.svg          # Background map SVG
├── scripts/
│   ├── .htaccess       # Denies direct web access
│   ├── cleanup.php     # GDPR retention cron job
│   └── schema.sql      # Database schema for manual installs
├── storage/            # Runtime data (last_cleanup timestamp)
├── tests/              # PHPUnit tests; DB-backed ones use in-memory SQLite
│   └── Support/        # UsesInMemoryDatabase trait
├── uploads/            # Transient Excel uploads, deleted after parsing
├── vendor/             # Composer dependencies
├── composer.json
├── phpunit.xml
├── release.sh          # Tags a release and writes config/version.php
├── README.md
├── DESIGN.md
└── LICENSE

Note: `deploy.sh` is referenced by the maintainers' workflow but is gitignored
(it carries production FTP credentials) and is not part of a clone.
```
