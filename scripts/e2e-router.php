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

// Router script for `php -S` (scripts/e2e.sh), standing in for public/.htaccess's
// mod_rewrite rules — the built-in server does not read .htaccess at all, so
// without this, every pretty route (/share, /bg, /app-icon, ...) 404s
// and only public/index.php itself would ever be reachable.
//
// Mirrors the .htaccess logic exactly: a request for a file that exists under
// the docroot is served as-is (assets, robots.txt, ...); everything else is
// routed through index.php.

$docroot = __DIR__ . '/../public';
$path = urldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = $docroot . $path;

// A .php path is never "static", even though is_file() says it exists: the
// whole API posts to /index.php, and handing that to the built-in server
// directly would run the front controller OUTSIDE this router — bypassing the
// coverage bootstrap below, which is why every API-driven controller looked
// untested while the pretty-URL ones did not.
if ($path !== '/' && is_file($file) && !str_ends_with(strtolower($path), '.php')) {
    // Served from here rather than by `return false`, so that the response
    // carries the headers public/.htaccess sets on a static file in
    // production. Handing the file back to the built-in server discards
    // anything set above the return, which left every asset answered bare —
    // and the passive scan reported a site less protected than the deployed
    // one, which is a false picture in the safe direction and therefore the
    // worse direction to be wrong in.
    //
    // An unrecognised extension falls through to the server rather than being
    // guessed at: a wrong Content-Type on a module script or a stylesheet
    // breaks the page, and getting the header set matters less than that.
    $types = [
        'css'  => 'text/css',
        'js'   => 'text/javascript',
        'mjs'  => 'text/javascript',
        'json' => 'application/json',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'ico'  => 'image/vnd.microsoft.icon',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'  => 'font/ttf',
        'txt'  => 'text/plain',
        'html' => 'text/html',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!isset($types[$extension])) {
        return false; // Unknown type; let the built-in server serve it as-is.
    }

    $type = $types[$extension];
    header_remove('X-Powered-By');
    header('Content-Type: ' . $type . (str_starts_with($type, 'text/') ? '; charset=UTF-8' : ''));
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }

    readfile($file);

    return true;
}

// Coverage is started HERE rather than through auto_prepend_file, which runs
// for every request including each CSS, JS and image the page pulls in. Those
// are served by the branch above and execute no application code, so
// instrumenting them produced ~19 near-empty .cov files per page load and
// slowed the run enough to time the suite out. Only requests that actually
// reach the front controller are measured.
if (getenv('E2E_COVERAGE_DIR')) {
    require __DIR__ . '/e2e-coverage-prepend.php';
}

require $docroot . '/index.php';
