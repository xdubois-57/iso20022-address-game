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

// Autoload
require_once __DIR__ . '/../vendor/autoload.php';

use App\Models\Database;
use App\Controllers\GameController;
use App\Controllers\AdminController;
use App\Controllers\LeaderboardController;
use App\Controllers\SetupController;
use App\Controllers\ShareController;

// GET share routes MUST run BEFORE session/CSRF to allow social media crawlers
$requestUri = strtok($_SERVER['REQUEST_URI'], '?');
if ($requestUri === '/share/image') {
    (new ShareController())->shareImage();
    exit;
}
if ($requestUri === '/share') {
    (new ShareController())->sharePage();
    exit;
}

// Secure session
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_start();

// Generate CSRF token if not present
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://unpkg.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com; img-src 'self' data:; font-src 'self';");
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");

// All API communication is via POST with an X-Action header.
// GET requests serve the SPA shell or the setup page.

$method = $_SERVER['REQUEST_METHOD'];

// Handle POST API requests (including setup routes that don't need DB)
if ($method === 'POST') {
    $action = $_SERVER['HTTP_X_ACTION'] ?? '';

    // Setup routes work without a DB connection - allowed if DB is down
    if (str_starts_with($action, 'setup/')) {
        // Check if we can connect to DB - if not, allow setup
        $db = Database::getInstance();
        if ($db->connect()) {
            jsonError('Setup is disabled - database is already connected', 403);
            exit;
        }
        $controller = new SetupController();
        match ($action) {
            'setup/test' => $controller->testConnection(),
            'setup/save' => $controller->saveConfig(),
            default => jsonError('Unknown setup action', 404),
        };
        exit;
    }

    // CSRF verification for all non-setup POST requests
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        error_log('SECURITY: CSRF token mismatch from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ' action=' . $action);
        jsonError('Invalid CSRF token', 403);
        exit;
    }

    // All other API routes require a DB connection
    $db = Database::getInstance();
    if (!$db->connect()) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Database unavailable', 'setup_required' => true]);
        exit;
    }
    // Init schema once per session to avoid repeated DDL (bump version when schema changes)
    $schemaVersion = 4;
    if (isset($_SESSION['schema_ready']) && !isset($_SESSION['schema_version'])) {
        unset($_SESSION['schema_ready']);
    }
    if (($_SESSION['schema_version'] ?? 0) < $schemaVersion) {
        $db->initSchema();
        $_SESSION['schema_version'] = $schemaVersion;
    }

    match ($action) {
        // Game
        'game/check-name' => (new GameController())->checkName(),
        'game/deadline' => (new GameController())->getDeadline(),
        'game/facts' => (new GameController())->getFacts(),
        'game/scenario' => (new GameController())->getScenario(),
        'game/validate' => (new GameController())->validate(),

        // Leaderboard
        'leaderboard/top' => (new LeaderboardController())->getTop(),
        'leaderboard/submit' => (new LeaderboardController())->submit(),

        // Share
        'share/token' => (new ShareController())->generateToken(),

        // Admin
        'admin/login' => (new AdminController())->login(),
        'admin/logout' => (new AdminController())->logout(),
        'admin/upload' => (new AdminController())->upload(),
        'admin/change-pin' => (new AdminController())->changePin(),
        'admin/leaderboard-entries' => (new AdminController())->getLeaderboardEntries(),
        'admin/delete-entry' => (new AdminController())->deleteLeaderboardEntry(),
        'admin/purge-leaderboard' => (new AdminController())->purgeLeaderboard(),
        'admin/set-deadline' => (new AdminController())->setDeadline(),
        'admin/get-deadline' => (new AdminController())->getDeadline(),
        'admin/get-facts' => (new AdminController())->getFacts(),
        'admin/add-fact' => (new AdminController())->addFact(),
        'admin/update-fact' => (new AdminController())->updateFact(),
        'admin/delete-fact' => (new AdminController())->deleteFact(),

        default => jsonError('Unknown action', 404),
    };
    exit;
}

// GET: Try to connect to DB. If it fails, show setup page.
$db = Database::getInstance();
if (!$db->connect()) {
    require __DIR__ . '/../app/Views/setup.php';
    exit;
}
// Init schema once per session to avoid repeated DDL
if (empty($_SESSION['schema_ready'])) {
    $db->initSchema();
    $_SESSION['schema_ready'] = true;
}

// Poor man's cron: run GDPR cleanup once per day on visitor traffic
$cleanupStamp = __DIR__ . '/../storage/last_cleanup.txt';
$cleanupDir = dirname($cleanupStamp);
if (!is_dir($cleanupDir)) {
    @mkdir($cleanupDir, 0755, true);
}
$lastCleanup = @file_get_contents($cleanupStamp);
if ($lastCleanup === false || (time() - (int)$lastCleanup) > 86400) {
    @file_put_contents($cleanupStamp, (string)time());
    $leaderboard = new \App\Models\LeaderboardModel($db->getPdo());
    $leaderboard->purgeExpired(30);
}

// GET export route (requires admin session)
$action = $_GET['action'] ?? '';
if ($action === 'admin/export') {
    (new AdminController())->exportScenarios();
    exit;
}

// Serve the SPA shell
require __DIR__ . '/../app/Views/layout.php';

// Helper
function jsonError(string $message, int $code): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message]);
}
