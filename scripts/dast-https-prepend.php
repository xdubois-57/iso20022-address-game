<?php
/**
 * ISO 20022 Address Structuring Game
 * Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

// auto_prepend_file for the DAST harness's backend server, and for nothing
// else. Loaded by scripts/dast.sh through `php -S -d auto_prepend_file=...`,
// exactly as scripts/e2e-coverage-prepend.php is loaded for a coverage run.
//
// WHAT IT DOES AND WHY IT IS NOT IN THE APPLICATION
// ---------------------------------------------------------------------------
// scripts/dast-tls-proxy.php terminates TLS in front of `php -S` and sets
// X-Forwarded-Proto: https. The application does not read that header — it
// reads $_SERVER['HTTPS'], which is what a real deployment behind Apache gets —
// and it should not learn to: trusting a forwarded header is a real
// vulnerability everywhere the header is not set by something you control.
//
// So the translation happens HERE, in harness code, on a server that is only
// ever reachable from the run's own TLS terminator on loopback. The application
// stays exactly as it is; not one line of it is aware the scan exists.
//
// Without this, public/index.php would emit no Strict-Transport-Security
// (~l.97) and no Secure session cookie (~l.155) — and the scan would raise two
// findings that are false, about code that is correct. Silencing those two
// rules with an alert filter is the tempting alternative and the wrong one: two
// rules muted for a harness defect are two rules nobody reads the day one of
// them fires for real.
//
// THIS IS TEST-HARNESS CODE. It ships in no release and no deployment runs it.

if (
    PHP_SAPI === 'cli-server'
    && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
) {
    // The value Apache and nginx both set, and the one public/index.php's two
    // `!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'` checks expect.
    $_SERVER['HTTPS'] = 'on';

    // Kept consistent so anything deriving a URL from the port agrees with the
    // scheme. The terminator preserves Host, so the port in the request's own
    // Host header is already the HTTPS one.
    $_SERVER['REQUEST_SCHEME'] = 'https';
}
