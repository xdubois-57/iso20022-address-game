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

// Autoload
require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Current schema revision. Bump when initSchema() gains a table or column.
 */
const SCHEMA_VERSION = 8;

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
use App\Controllers\BoardController;

// Security headers.
//
// These are sent before the share/asset routes below, which exit early: they
// used to bypass this block entirely, so /share, /share/go, /share/image, /bg
// and /app-icon were served with no CSP, no X-Frame-Options and no nosniff —
// share/go being a full HTML page with inline script and share buttons that
// anyone could frame.
function sendSecurityHeaders(): void
{
    // PHP announces itself in X-Powered-By unless expose_php is off, and this
    // application cannot count on the php.ini of whatever shared host it lands
    // on. Naming the interpreter and its exact version tells an attacker which
    // published vulnerabilities to try first and tells a legitimate visitor
    // nothing at all. Removed here so it holds regardless of the host's
    // configuration.
    header_remove('X-Powered-By');

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    // unpkg.com is no longer referenced: the one script served from it is now
    // bundled locally, so it comes out of every directive.
    //
    // frame-ancestors, form-action and base-uri are spelled out because they
    // DO NOT fall back to default-src. Leaving them out is not "covered by
    // default-src 'self'", it is unset — which the passive scan reports, and
    // is right to.
    //
    //   frame-ancestors 'none'  the modern half of the X-Frame-Options: DENY
    //                           above; that header is obsolete and ignored by
    //                           browsers that read this one.
    //   form-action 'self'      both forms in this application post to their
    //                           own origin (the setup form, and the Dropzone
    //                           upload that posts to index.php). Naming it
    //                           stops an injected form from posting a PIN or
    //                           an upload somewhere else.
    //   base-uri 'self'         there is no <base> tag; this stops an injected
    //                           one from re-pointing every relative URL on the
    //                           page, the import map's module paths included.
    header(
        "Content-Security-Policy: default-src 'self'; "
        // A per-request nonce instead of 'unsafe-inline'. The blanket
        // permission allowed ANY inline script the page ended up containing,
        // an injected one included, which is close to having no script policy
        // at all. Only the few inline blocks this application actually serves
        // carry the nonce (see App\Support\Csp).
        //
        // The CDN host stays: a host-source and a nonce coexist, so the
        // external PicoCSS/Dropzone/Chart.js/QR scripts still load by host
        // while inline blocks need the secret. (Only 'strict-dynamic' would
        // disable the host allowlist, and it is not used here.)
        //
        // Browsers that understand a nonce ignore 'unsafe-inline' when one is
        // present, so leaving it in would have been dead weight that merely
        // looked permissive to a reader and to a scanner.
        . "script-src 'self' 'nonce-" . \App\Support\Csp::nonce() . "' https://cdn.jsdelivr.net; "
        . "style-src 'self' 'nonce-" . \App\Support\Csp::nonce() . "' https://cdn.jsdelivr.net; "
        . "img-src 'self' data:; font-src 'self'; "
        . "connect-src 'self' https://cdn.jsdelivr.net; "
        . "frame-ancestors 'none'; form-action 'self'; base-uri 'self';"
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
/**
 * The Hall of Fame wall's data source, and the reason it sits up here with
 * the share routes rather than down among the POST actions.
 *
 * Every other API route is a POST carrying a CSRF token bound to the PHP
 * session, whose default lifetime is 24 minutes. A wall polling from six in
 * the evening until two in the morning would lose its session and see every
 * call fall to 403 — silently, around midnight, with nobody in front of the
 * screen. A public GET, declared before session_start(), removes that failure
 * mode instead of working around it with token refreshes.
 *
 * Safe because it exposes nothing the Hall of Fame does not already show any
 * anonymous visitor: the same names, scores and ordering.
 */
if ($requestUri === '/board/data') {
    (new BoardController())->data();
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
        error_log('SECURITY: CSRF token mismatch from '
            . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ' action=' . $action);
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

    match ($action) {
        // Game
        'game/check-name' => (new GameController())->checkName(),
        'game/complete' => (new GameController())->complete(),
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
        'admin/get-board-window' => (new AdminController())->getBoardWindow(),
        'admin/set-board-window' => (new AdminController())->setBoardWindow(),
        'admin/get-sharing' => (new AdminController())->getSharing(),
        'admin/set-sharing' => (new AdminController())->setSharing(),
        'admin/get-display-token' => (new AdminController())->getDisplayToken(),
        'admin/regenerate-display-token' => (new AdminController())->regenerateDisplayToken(),
        'admin/get-facts' => (new AdminController())->getFacts(),
        'admin/add-fact' => (new AdminController())->addFact(),
        'admin/update-fact' => (new AdminController())->updateFact(),
        'admin/delete-fact' => (new AdminController())->deleteFact(),
        'admin/game-stats' => (new AdminController())->getGameStats(),
        'admin/reset-game-counter' => (new AdminController())->resetGameCounter(),
        'admin/get-theme' => (new AdminController())->getTheme(),
        'admin/save-theme' => (new AdminController())->saveTheme(),
        'admin/reset-theme' => (new AdminController())->resetTheme(),

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
//
// The "is it due yet" decision lives in RetentionCleanup::claimDueSlot() so it
// can be tested; inline here it never was, and a gate nobody tests is a gate
// that stops opening without telling anyone.
if (\App\Models\RetentionCleanup::claimDueSlot(__DIR__ . '/../storage/last_cleanup.txt')) {
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
 * The display mode, resolved here rather than in the browser.
 *
 * A strict allowlist, and anything else falls silently back to the default —
 * a typo in the URL of an unattended screen has to serve the ordinary game,
 * not an error page nobody is there to read.
 *
 * 'kiosk' is deliberately absent. The iPad kiosk is switched on from the Admin
 * screen and stays a session flag; nothing here touches it.
 *
 * Resolved server-side because layout.php renders the nav: hiding it in
 * JavaScript afterwards would flash the menus on every load and leave the
 * buttons in the DOM, reachable by keyboard. This is a guard rail, not a
 * security boundary — the API routes stay open and the leaderboard data is
 * public either way.
 */
$displayMode = $_GET['mode'] ?? '';
if (!is_string($displayMode) || !in_array($displayMode, ['', 'hof', 'play'], true)) {
    $displayMode = '';
}

/**
 * …and the token that has to accompany it.
 *
 * A mode is honoured only when ?t= matches the stored display_mode_token,
 * compared with hash_equals(). Anything else — no token, a wrong one, a
 * database that cannot be reached to look one up — falls back to '', which is
 * the ordinary game with its menus.
 *
 * THE FALLBACK IS THE DESIGN, not an oversight. An unknown token is worth
 * exactly what an unknown mode is worth today (?mode=nimportequoi already
 * serves the default), and for the same reason: a wall must never show an
 * error page in front of a room. Nobody is standing there to read it.
 *
 * Its price is real and is documented in README § Dedicated screen URLs:
 * regenerating during an event turns both screens back into ordinary pages
 * with menus, silently. That is why the Admin button sits behind a
 * confirmation that says so, and why the panel shows the new URLs at once.
 *
 * And it is a GUARD RAIL, not a barrier. The URLs stop being guessable; they
 * do not become authenticated. /board/data stays public by design, every API
 * route is exactly as reachable as it was, and nothing here should be
 * described as a security control.
 *
 * The token is never written to a log — not on a match, and not on a miss.
 */
if ($displayMode !== '') {
    $suppliedToken = $_GET['t'] ?? '';
    $expectedToken = AdminController::displayModeTokenStatic();

    // $expectedToken can be null when the database is unreachable. Compared
    // as a string it would be '', and hash_equals('', '') is TRUE — so an
    // instance in the middle of an outage would honour ?mode=hof&t= from
    // anyone. Checked explicitly rather than coerced.
    if (
        $expectedToken === null
        || !is_string($suppliedToken)
        || !hash_equals($expectedToken, $suppliedToken)
    ) {
        $displayMode = '';
    }
}

/**
 * Whether the interface offers sharing, resolved here and carried to the
 * browser on <body>, exactly as $displayMode is.
 *
 * One read, on the request that already talks to the database to render the
 * page, rather than an extra round trip from the SPA — and the same mechanism
 * the front end already understands, instead of a second one beside it.
 *
 * INTERFACE ONLY. Every share route above this line — /share, /share/go,
 * /share/image, /share/home-image — and the `share/token` POST action are
 * declared without reference to this flag and answer identically whether it
 * is on or off. That is deliberate and load-bearing: a link a player already
 * posted must keep working, /share/home-image is the site's own OpenGraph
 * image rather than anyone's score, and hiding a feature is a product
 * decision rather than an access control. Do not turn this into one.
 */
$sharingEnabled = AdminController::sharingEnabledStatic();

// Serve the SPA shell
require __DIR__ . '/../app/Views/layout.php';

// Helper
function jsonError(string $message, int $code): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message]);
}
