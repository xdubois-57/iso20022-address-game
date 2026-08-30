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

/**
 * Current schema revision. Bump when initSchema() gains a table or column.
 */
const SCHEMA_VERSION = 6;

/**
 * Run initSchema() at most once per session, and again after a version bump.
 *
 * The GET and POST paths used to disagree: POST tracked $_SESSION['schema_version']
 * while GET only set a boolean $_SESSION['schema_ready'], so a visitor who never
 * issued a POST never picked up a schema change. Both go through here now.
 */
function ensureSchema(\App\Models\Database $db): void
{
    // Legacy boolean from before versioning: treat as "unknown version".
    if (isset($_SESSION['schema_ready']) && !isset($_SESSION['schema_version'])) {
        unset($_SESSION['schema_ready']);
    }

    if (($_SESSION['schema_version'] ?? 0) < SCHEMA_VERSION) {
        $db->initSchema();
        $_SESSION['schema_version'] = SCHEMA_VERSION;
    }
}

/**
 * True once either config file marks the install as already configured,
 * which permanently closes the unauthenticated setup routes below.
 * db_config.json is included because older installs were configured through
 * it alone and have no credentials.php to check. Resolved through
 * Database::configDir() rather than a fixed path so the Playwright E2E
 * harness's scratch config directory (ISO20022_CONFIG_DIR) is checked
 * instead of a developer's real config/ in that one context.
 */
function isAlreadyConfigured(): bool
{
    $configDir = \App\Models\Database::configDir();
    return file_exists($configDir . '/credentials.php') || file_exists($configDir . '/db_config.json');
}

use App\Models\Database;
use App\Controllers\GameController;
use App\Controllers\AdminController;
use App\Controllers\LeaderboardController;
use App\Controllers\SetupController;
use App\Controllers\ShareController;
use App\Controllers\BackgroundController;
use App\Controllers\AppIconController;
use App\Controllers\WebhookController;
use App\Models\SettingsModel;
use App\Models\Updater;

// Security headers.
//
// These are sent before the share/asset routes below, which exit early: they
// used to bypass this block entirely, so /share, /share/go, /share/image, /bg
// and /app-icon were served with no CSP, no X-Frame-Options and no nosniff —
// share/go being a full HTML page with inline script and share buttons that
// anyone could frame.
function sendSecurityHeaders(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    // unpkg.com is no longer referenced: the one script served from it is now
    // bundled locally, so it comes out of every directive.
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
        . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
        . "img-src 'self' data:; font-src 'self'; "
        . "connect-src 'self' https://cdn.jsdelivr.net;"
    );
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

    // HSTS: enforce HTTPS for 1 year, include subdomains, allow preload
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
}

sendSecurityHeaders();

// GET share routes MUST run BEFORE session/CSRF to allow social media crawlers
$requestUri = strtok($_SERVER['REQUEST_URI'], '?');
if ($requestUri === '/share/image') {
    (new ShareController())->shareImage();
    exit;
}
if ($requestUri === '/share/home-image') {
    (new ShareController())->homeShareImage();
    exit;
}
if ($requestUri === '/bg') {
    (new BackgroundController())->generate();
    exit;
}
if ($requestUri === '/app-icon') {
    (new AppIconController())->generate();
    exit;
}
if ($requestUri === '/share/go') {
    (new ShareController())->shareGoPage();
    exit;
}
if ($requestUri === '/share') {
    (new ShareController())->sharePage();
    exit;
}

// POST /webhook/github — the one public, session-free, CSRF-free route.
// GitHub is a machine caller with no cookie to carry a CSRF token or session;
// App\Controllers\WebhookController::github() authenticates it instead via
// the HMAC-SHA256 signature checked against the secret generated on the
// admin panel's Automatic Updates section. Must run before session_start()
// and the CSRF check below for the same reason the share routes above do.
if ($requestUri === '/webhook/github' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $webhookDb = Database::getInstance();
    if (!$webhookDb->connect()) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'db_unavailable']);
        exit;
    }

    // Deliberately NOT ensureSchema() here. That helper caches "schema is
    // current" in $_SESSION, and this branch runs before session_start() —
    // so the cache never hit and initSchema() (six CREATE TABLE statements,
    // two ALTERs and a COUNT) ran on every single request, BEFORE the
    // signature was checked. That handed any unauthenticated caller a
    // cheap way to make the server do real database work on demand.
    //
    // Nothing is lost by skipping it: a webhook only reaches anything
    // meaningful once an admin has generated a secret through the panel,
    // which is impossible on an install whose schema was never created.
    // A genuinely missing table therefore means a broken install, and is
    // answered as 503 (which GitHub retries) rather than a fatal.
    try {
        (new WebhookController())->github();
    } catch (\PDOException $e) {
        error_log('Webhook aborted, database error: ' . $e->getMessage());
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'db_unavailable']);
    }
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

// All API communication is via POST with an X-Action header.
// GET requests serve the SPA shell or the setup page.

$method = $_SERVER['REQUEST_METHOD'];

// Handle POST API requests (including setup routes that don't need DB)
if ($method === 'POST') {
    $action = $_SERVER['HTTP_X_ACTION'] ?? '';

    // Setup routes work without a DB connection - allowed if DB is down
    if (str_starts_with($action, 'setup/')) {
        // Setup is unauthenticated and CSRF-exempt by necessity: on a fresh
        // install there is no session and no database to authenticate against.
        // That makes "is the database down?" far too weak a gate on its own — a
        // transient outage would let any anonymous caller repoint the app at
        // their own server, overwrite config/credentials.php, and take over as
        // admin, destroying the encryption key (and with it every stored player
        // name) on the way. So an install that has already been configured keeps
        // these routes closed no matter what the database is doing.
        if (isAlreadyConfigured()) {
            error_log('SECURITY: setup route refused on a configured install from '
                . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ' action=' . $action);
            jsonError(
                'Setup is disabled — this installation is already configured. To re-run '
                . 'setup, remove config/credentials.php and config/db_config.json on the server.',
                403
            );
            exit;
        }

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
    ensureSchema($db);

    // Event Code gate, enforced here rather than in the browser. Everything that
    // actually plays the game or records a result is behind it; the two
    // event-code endpoints themselves must stay reachable so a player can get
    // through, and admin routes carry their own authentication.
    $eventCodeGatedActions = [
        'game/check-name',
        'game/complete',
        'game/facts',
        'game/scenario',
        'game/validate',
        'leaderboard/submit',
        'share/token',
    ];
    if (in_array($action, $eventCodeGatedActions, true) && !GameController::isEventCodeSatisfied()) {
        jsonError('An event code is required to play.', 403);
        exit;
    }

    match ($action) {
        // Game
        'game/check-name' => (new GameController())->checkName(),
        'game/complete' => (new GameController())->complete(),
        'game/deadline' => (new GameController())->getDeadline(),
        'game/event-code-status' => (new GameController())->eventCodeStatus(),
        'game/facts' => (new GameController())->getFacts(),
        'game/reset-session' => (new GameController())->resetSession(),
        'game/scenario' => (new GameController())->getScenario(),
        'game/validate' => (new GameController())->validate(),
        'game/verify-event-code' => (new GameController())->verifyEventCode(),

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
        'admin/game-stats' => (new AdminController())->getGameStats(),
        'admin/reset-game-counter' => (new AdminController())->resetGameCounter(),
        'admin/get-event-code' => (new AdminController())->getEventCode(),
        'admin/set-event-code' => (new AdminController())->setEventCode(),
        'admin/get-theme' => (new AdminController())->getTheme(),
        'admin/save-theme' => (new AdminController())->saveTheme(),
        'admin/get-update-settings' => (new AdminController())->getUpdateSettings(),
        'admin/save-update-settings' => (new AdminController())->saveUpdateSettings(),
        'admin/generate-webhook-secret' => (new AdminController())->generateWebhookSecret(),
        'admin/install-update-now' => (new AdminController())->installUpdateNow(),

        default => jsonError('Unknown action', 404),
    };
    exit;
}

// GET: Try to connect to DB. If it fails, show setup page.
$db = Database::getInstance();
if (!$db->connect()) {
    // On an install that is already configured the setup form would only lead to
    // a 403, so show the outage for what it is instead of inviting a re-setup.
    $setupLocked = isAlreadyConfigured();
    require __DIR__ . '/../app/Views/setup.php';
    exit;
}
ensureSchema($db);

// Poor man's cron: run the retention cleanup once per day on visitor traffic,
// for installs with no real cron job (see scripts/cleanup.php, which runs the
// identical App\Models\RetentionCleanup).
//
// Wrapped, because this is housekeeping on the way to rendering a page and
// must never be what the visitor sees instead of one. It fataled outright
// until now: LeaderboardModel::purgeExpired() spoke MySQL only, so the very
// first page load of a SQLite-backed instance died here with an empty 500,
// and an install that had lost its encryption key did the same. Both are
// fixed at the source; this stays so that the next such failure degrades to
// a log line rather than an unreachable site.
$cleanupStamp = __DIR__ . '/../storage/last_cleanup.txt';
$cleanupDir = dirname($cleanupStamp);
if (!is_dir($cleanupDir)) {
    @mkdir($cleanupDir, 0755, true);
}
$lastCleanup = @file_get_contents($cleanupStamp);
if ($lastCleanup === false || (time() - (int)$lastCleanup) > \App\Models\RetentionCleanup::INTERVAL_SECONDS) {
    @file_put_contents($cleanupStamp, (string)time());
    try {
        (new \App\Models\RetentionCleanup($db->getPdo()))->run();
    } catch (\Throwable $cleanupError) {
        error_log('CLEANUP: retention cleanup failed — ' . $cleanupError->getMessage());
    }
}

// GET export route (requires admin session)
$action = $_GET['action'] ?? '';
if ($action === 'admin/export') {
    (new AdminController())->exportScenarios();
    exit;
}

/**
 * Poor man's cron, continued: a webhook-triggered install still sitting in
 * `update_pending` a couple of minutes after it was queued means the process
 * that should have run it (App\Controllers\WebhookController::
 * runInstallAfterResponse()) never finished — killed by a host's request time
 * limit, a crashed worker, whatever. Rather than leave it stranded, the next
 * visitor's page load picks it up, after their own response has been sent so
 * it costs them nothing.
 *
 * Decided BEFORE the page is rendered, because the deferred path has to
 * buffer the page to send an explicit Content-Length, and that header must
 * not be set on ordinary requests: it counts uncompressed bytes, so on a
 * server with mod_deflate or zlib.output_compression enabled it contradicts
 * the compressed body actually sent and truncates the page. The common case
 * (nothing pending) therefore renders exactly as it always did — streamed,
 * no buffering, no Content-Length.
 */
function staleInstallIsPending(\App\Models\Database $db): bool
{
    $pendingJson = (new SettingsModel($db->getPdo()))->get('update_pending');
    if ($pendingJson === null) {
        return false;
    }
    $pending = json_decode($pendingJson, true);
    $queuedAt = is_array($pending) ? (int) ($pending['queued_at'] ?? 0) : 0;

    return $queuedAt > 0 && (time() - $queuedAt) > 120;
}

$runPendingInstall = staleInstallIsPending($db);

// Serve the SPA shell
if (!$runPendingInstall) {
    require __DIR__ . '/../app/Views/layout.php';
} else {
    ob_start();
    require __DIR__ . '/../app/Views/layout.php';
    $page = ob_get_clean();

    ignore_user_abort(true);
    set_time_limit(180);

    if (function_exists('fastcgi_finish_request')) {
        // PHP-FPM: the response is closed for us, so no Content-Length of
        // our own is needed (and none is set, keeping compression intact).
        echo $page;
        fastcgi_finish_request();
    } else {
        // Other SAPIs: the client only knows the response is complete once
        // the byte count matches, so Content-Length is what stops the
        // browser waiting on the install below. Compression is disabled for
        // this one response so that count stays truthful.
        @ini_set('zlib.output_compression', '0');
        header('Content-Encoding: identity');
        header('Content-Length: ' . strlen($page));
        echo $page;
        flush();
    }

    // Updater's own flock() makes this safe even if an install really is
    // still running elsewhere — it just reports 'in_progress' and returns.
    (new Updater(dirname(__DIR__), new SettingsModel($db->getPdo())))->run();
}

// Helper
function jsonError(string $message, int $code): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message]);
}
