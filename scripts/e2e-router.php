<?php
/**
 * ISO 20022 Address Structuring Game
 * Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

// Router script for `php -S` (scripts/e2e.sh), standing in for public/.htaccess's
// mod_rewrite rules — the built-in server does not read .htaccess at all, so
// without this, every pretty route (/webhook/github, /share, /bg, ...) 404s
// and only public/index.php itself would ever be reachable.
//
// Mirrors the .htaccess logic exactly: a request for a file that exists under
// the docroot is served as-is (assets, robots.txt, ...); everything else is
// routed through index.php.

$docroot = __DIR__ . '/../public';
$path = urldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = $docroot . $path;

if ($path !== '/' && is_file($file)) {
    return false; // Let the built-in server serve it directly.
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
