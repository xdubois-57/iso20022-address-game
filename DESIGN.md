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
- **Encryption**: AES-256-GCM (authenticated encryption) via `openssl_encrypt` for player names (PII), with legacy AES-256-CTR decryption support
- **Data Parsing**: `PhpOffice\PhpSpreadsheet` for Excel scenario extraction
- **Validation**: Chip-to-Slot accuracy based on PMPG rules

### 2.2 View (UI/UX)

- **Framework**: PicoCSS (semantic HTML, minimal footprint)
- **Branding** (Swift Palette):
  - Primary: `#01a990` (Emerald)
  - Accent: `#acf9e9` (Peppermint)
  - Text/Headers: `#333d3e` (Dark Green)
  - Muted: `#698287` (Grey Green)
  - Background: `#ffffff` (White)
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

### Session Management
- 30s inactivity timer triggers a 10s countdown overlay (during active game)
- Global "Stop" button always available for immediate reset
- Custom overlay modals replace native `alert()` / `confirm()` to maintain fullscreen mode

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
- **Admin Management**: Add, edit, delete facts (max 100 chars, HTML links supported)
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
settings: setting_key, setting_value, updated_at
facts: id, content, created_at
```

## 5. Security & GDPR

- **Session cookie**: A single strictly necessary PHPSESSID cookie is used for CSRF protection and admin authentication. No tracking cookies.
- **CSRF protection**: All POST requests validated via `hash_equals()` token comparison
- **Pseudonymisation**: Player names encrypted with AES-256-GCM (authenticated encryption) at rest
- **Rate limiting**: Admin login locked after 5 failed attempts (5-minute lockout)
- **Retention**: Auto-deletion of leaderboard entries after 30 days (cron + poor man's cron fallback)
- **Input sanitisation**: All inputs validated and sanitised; XSS prevention via `escapeHtml()` on client and `htmlspecialchars()` on server
- **Sessions**: Secure PHP sessions with `session_regenerate_id()`, HttpOnly, SameSite=Strict flags
- **Security headers**: CSP, X-Content-Type-Options, X-Frame-Options
- **Credentials**: `config/credentials.php` excluded from version control, protected by `.htaccess`
- **Admin PIN**: Stored as bcrypt hash; legacy plaintext PINs auto-upgraded on login
- **Cache busting**: CSS/JS URLs include `?v={filemtime}` to force browser refresh on changes

## 6. Directory Structure

```
/project-root
├── app/
│   ├── Controllers/    # API Logic
│   ├── Models/         # DB, Encryption, Excel Parsing
│   └── Views/          # SPA Template Fragments
├── config/
│   ├── .htaccess       # Protects config files
│   ├── credentials.php # DB Passwords & AES Keys (gitignored)
│   └── db_config.json  # Fallback DB config (gitignored)
├── public/
│   ├── index.php       # Front Controller
│   ├── .htaccess       # URL rewriting
│   └── assets/         # CSS, JS
├── scripts/
│   ├── cleanup.php     # GDPR retention cron job
│   └── schema.sql      # Database schema
├── storage/            # Runtime data (last_cleanup timestamp)
├── tests/              # PHPUnit tests
├── vendor/             # Composer dependencies
├── composer.json
├── phpunit.xml
├── deploy.sh           # FTP deployment script
├── README.md
├── DESIGN.md
└── LICENSE
```
