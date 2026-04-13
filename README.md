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

## Features

- **Drag & Drop Gameplay** — Drag address chips into correct ISO 20022 semantic slots
- **Structured & Hybrid Modes** — Practice both address structuring approaches
- **Hall of Fame** — Encrypted leaderboard with GDPR-compliant 30-day retention
- **Admin Panel** — PIN-protected dashboard for uploading scenarios via Excel
- **Privacy by Design** — AES-256-GCM authenticated encryption at rest, GDPR-compliant privacy notice
- **Responsive** — Mobile hamburger menu, touch-first design for tablets
- **Cache Busting** — Automatic browser refresh when CSS/JS files change

## Requirements

- PHP >= 8.1
- MySQL 5.7+ or MariaDB 10.3+
- Composer
- Apache with mod_rewrite (or equivalent)

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

## Excel File Format

### Sheet 1: Scenarios

| StrtNm | BldgNb | PstCd | TwnNm | Ctry | AdtlAdrInf |
|--------|--------|-------|--------|------|------------|
| Main St | 123 | 10001 | New York | US | Floor 10 |

## Security

- **Encryption**: Player names encrypted with AES-256-GCM (authenticated encryption) at rest
- **CSRF protection**: Token-based validation on all POST requests
- **Rate limiting**: Admin login locked after 5 failed attempts (5-minute lockout)
- **Session hardening**: HttpOnly, SameSite=Strict, secure cookie flags
- **Security headers**: CSP, X-Content-Type-Options, X-Frame-Options
- **Admin PIN**: Stored as bcrypt hash; legacy plaintext auto-upgraded on login
- **Prepared statements**: All database queries use parameterised PDO statements
- **Session cookie**: A single strictly necessary PHPSESSID cookie for CSRF protection (no tracking)

## Running Tests

```bash
composer install --dev
composer test
```

## GDPR Cleanup (Cron Job)

Schedule the cleanup script to run daily:

```bash
0 3 * * * php /path/to/scripts/cleanup.php
```

This deletes leaderboard entries older than 30 days. A fallback "poor man's cron" also runs cleanup automatically once per day on visitor traffic.

## Deployment

An FTP deployment script is included:

```bash
./deploy.sh
```

This script runs `composer install --no-dev`, then syncs files to the production server via `lftp`.

## License

This project is licensed under the [GNU General Public License v3.0](LICENSE).
