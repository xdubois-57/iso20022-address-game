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

namespace App\Controllers;

use App\Models\Database;
use App\Models\ScenarioModel;
use App\Models\LeaderboardModel;
use App\Models\GameCounterModel;
use App\Models\ExcelParser;
use App\Models\ThemeModel;
use App\Models\HtmlSanitizer;
use App\Models\SettingsModel;
use App\Models\RateLimitModel;
use App\Support\Input;

class AdminController
{
    private ScenarioModel $scenarioModel;
    private LeaderboardModel $leaderboardModel;

    public function __construct()
    {
        $db = Database::getInstance();
        $pdo = $db->getPdo();
        $this->scenarioModel = new ScenarioModel($pdo);
        $this->leaderboardModel = new LeaderboardModel($pdo);
    }

    /**
     * POST /api/admin/login — Verify PIN (bcrypt hashed).
     */
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 300; // 5 minutes

    public function login(): void
    {
        // Rate limiting is keyed on the caller's address rather than the
        // session: a session-scoped counter reset the moment the client dropped
        // its cookie, which is no obstacle at all to brute-forcing four digits.
        $limiter = new RateLimitModel(Database::getInstance()->getPdo());
        $bucket  = RateLimitModel::bucketFor('admin_login');

        $retryAfter = $limiter->retryAfter($bucket);
        if ($retryAfter > 0) {
            $this->jsonResponse(['error' => "Too many attempts. Try again in {$retryAfter}s."], 429);
            return;
        }

        $input = $this->getJsonInput();
        // Input::string: an array here fataled in password_verify() below —
        // a 500 any visitor could trigger. A scalar coerces as PHP always did.
        $pin = Input::string($input['pin'] ?? '');

        $stored = $this->getStoredPin();

        // Check if stored value is already a bcrypt hash
        $isHashed = str_starts_with($stored, '$2y$') || str_starts_with($stored, '$2b$');

        if ($isHashed) {
            $valid = password_verify($pin, $stored);
        } else {
            // Legacy plaintext comparison — upgrade to hash on success
            $valid = ($pin === $stored);
            if ($valid) {
                $this->upgradePinToHash($pin);
            }
        }

        if ($valid) {
            $limiter->clear($bucket);
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $this->jsonResponse(['success' => true]);
        } else {
            $lockedFor = $limiter->recordFailure($bucket, self::MAX_LOGIN_ATTEMPTS, self::LOCKOUT_SECONDS);
            error_log('SECURITY: Failed admin login attempt from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

            if ($lockedFor > 0) {
                $this->jsonResponse(['error' => "Too many attempts. Try again in {$lockedFor}s."], 429);
                return;
            }
            $this->jsonResponse(['error' => 'Invalid PIN'], 401);
        }
    }

    /**
     * Replace a plaintext PIN in config/credentials.php with a bcrypt hash of
     * itself, so a PIN typed into the file by hand is hashed the first time it
     * is used and never sits in clear afterwards.
     *
     * Best effort by design: a config directory that is not writable (common
     * on locked-down shared hosting) must not stop an otherwise correct login,
     * it just means the value stays plaintext until someone makes the file
     * writable.
     */
    private function upgradePinToHash(string $pin): void
    {
        if (!$this->writePinToCredentials(password_hash($pin, PASSWORD_BCRYPT))) {
            error_log(
                'Admin PIN is stored in clear in ' . Database::configDir()
                . '/credentials.php and could not be hashed automatically — the file is not writable.'
            );
        }
    }

    /**
     * The PIN as stored in config/credentials.php — hashed or plaintext — or
     * null when the file has none.
     */
    private function readPinFromCredentials(): ?string
    {
        $credFile = Database::configDir() . '/credentials.php';
        if (!is_file($credFile)) {
            return null;
        }

        try {
            $creds = include $credFile;
        } catch (\Throwable) {
            return null;
        }

        $pin = is_array($creds) ? ($creds['admin']['pin'] ?? null) : null;

        return (is_string($pin) && $pin !== '') ? $pin : null;
    }

    /**
     * Store $value as the admin PIN in config/credentials.php, preserving
     * every other setting.
     *
     * That file also holds the AES key every stored player name is encrypted
     * under, so corrupting it would not merely break a login — it would make
     * the leaderboard permanently undecryptable. Hence: the array is
     * round-tripped through var_export() rather than the value being spliced
     * in by a regex that could match too much or not at all; the result is
     * staged in a temporary file and parsed to prove it is valid PHP that
     * still carries the encryption key; and only then is it moved into place
     * with rename(), which is atomic. Any failure leaves the original file
     * exactly as it was.
     */
    private function writePinToCredentials(string $value): bool
    {
        $credFile = Database::configDir() . '/credentials.php';
        if (!is_file($credFile)) {
            return false;
        }

        try {
            $creds = include $credFile;
        } catch (\Throwable) {
            return false;
        }
        if (!is_array($creds) || $creds === []) {
            return false;
        }

        $originalKeys = array_keys($creds);
        $originalKey = $creds['encryption']['key'] ?? null;

        $creds['admin'] = is_array($creds['admin'] ?? null) ? $creds['admin'] : [];
        $creds['admin']['pin'] = $value;

        $contents = "<?php\n"
            . "/**\n"
            . " * ISO 20022 Address Structuring Game — local credentials.\n"
            . " * Holds the database password, the AES key for stored player names,\n"
            . " * and the admin PIN. Never commit this file.\n"
            . " * Last written by the application on " . date('Y-m-d H:i:s') . ".\n"
            . " */\n\n"
            . 'return ' . var_export($creds, true) . ";\n";

        $tmp = $credFile . '.tmp' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }

        // Prove the staged file parses and kept everything that mattered. A
        // parse error surfaces as a catchable ParseError, so a malformed file
        // can never reach the rename() below.
        try {
            $written = include $tmp;
        } catch (\Throwable) {
            @unlink($tmp);
            return false;
        }
        $intact = is_array($written)
            && ($written['admin']['pin'] ?? null) === $value
            && ($written['encryption']['key'] ?? null) === $originalKey
            && array_diff($originalKeys, array_keys($written)) === [];
        if (!$intact) {
            @unlink($tmp);
            return false;
        }

        // Keep the original's permissions rather than the umask default, so a
        // deliberately tight 0600 is not widened by a PIN change.
        $mode = @fileperms($credFile);
        if ($mode !== false) {
            @chmod($tmp, $mode & 0777);
        }

        if (!@rename($tmp, $credFile)) {
            @unlink($tmp);
            return false;
        }

        // The file is include()d on later requests; without this OPcache can
        // keep serving the previous PIN for up to opcache.revalidate_freq.
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($credFile, true);
        }
        clearstatcache(true, $credFile);

        return true;
    }

    /**
     * POST /api/admin/logout — End admin session.
     */
    public function logout(): void
    {
        $_SESSION['admin'] = false;
        session_regenerate_id(true);
        $this->jsonResponse(['success' => true]);
    }

    /**
     * POST /api/admin/upload — Handle Excel file upload.
     */
    public function upload(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->jsonResponse(['error' => 'No file uploaded or upload error'], 400);
            return;
        }

        $file = $_FILES['file'];

        // File size limit: 5 MB
        if ($file['size'] > 5 * 1024 * 1024) {
            $this->jsonResponse(['error' => 'File exceeds 5 MB limit'], 400);
            return;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            $this->jsonResponse(['error' => 'Only .xlsx files are accepted'], 400);
            return;
        }

        $uploadDir = __DIR__ . '/../../uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $tmpPath = $uploadDir . 'upload_' . bin2hex(random_bytes(8)) . '.xlsx';
        if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
            $this->jsonResponse(['error' => 'Could not store the uploaded file.'], 500);
            return;
        }

        // try/finally so a parser exception cannot leave the upload on disk.
        try {
            $parser = new ExcelParser();
            $result = $parser->parse($tmpPath);

            if (!empty($result['errors'])) {
                $this->jsonResponse(['errors' => $result['errors']], 422);
                return;
            }

            // Replace scenarios in one transaction. deleteAll() followed by a
            // loop of inserts meant a failure part-way through left the kiosk
            // with a partial scenario set — or none at all.
            $db  = Database::getInstance();
            $pdo = $db->getPdo();

            $pdo->beginTransaction();
            try {
                $this->scenarioModel->deleteAll();
                foreach ($result['scenarios'] as $scenario) {
                    $this->scenarioModel->create($scenario['json_data']);
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('ADMIN: scenario import failed, rolled back — ' . $e->getMessage());
                $this->jsonResponse(['error' => 'Import failed; the previous scenarios were kept.'], 500);
                return;
            }

            $this->jsonResponse([
                'success' => true,
                'imported' => [
                    'scenarios' => count($result['scenarios']),
                ],
            ]);
        } finally {
            if (is_file($tmpPath)) {
                unlink($tmpPath);
            }
        }
    }

    /**
     * POST /api/admin/change-pin — Update the admin PIN.
     */
    public function changePin(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = $this->getJsonInput();
        $newPin = Input::string($input['new_pin'] ?? '');

        if (!preg_match('/^\d{4,8}$/', $newPin)) {
            $this->jsonResponse(['error' => 'PIN must be 4-8 digits'], 400);
            return;
        }

        $hash = password_hash($newPin, PASSWORD_BCRYPT);

        if (!$this->writePinToCredentials($hash)) {
            // Reported rather than swallowed: the PIN is stored in exactly one
            // place now, so a failed write means the PIN did NOT change, and
            // an admin told "saved" who then finds the old PIN still working
            // is worse than being told plainly to fix the permissions.
            $this->jsonResponse([
                'error' => 'Could not write config/credentials.php. Grant the web server write '
                    . 'access to the config/ directory and try again.',
            ], 500);
            return;
        }

        // Nothing may shadow the file afterwards.
        $this->deleteLegacyDatabasePin();

        $this->jsonResponse(['success' => true]);
    }

    /**
     * POST /api/admin/leaderboard-entries — Get all leaderboard entries for admin management.
     */
    public function getLeaderboardEntries(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $entries = $this->leaderboardModel->getTopEntries(200);
        $this->jsonResponse(['entries' => $entries]);
    }

    /**
     * POST /api/admin/delete-entry — Delete a single leaderboard entry.
     */
    public function deleteLeaderboardEntry(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = $this->getJsonInput();
        $id = (int) ($input['id'] ?? 0);

        if ($id <= 0) {
            $this->jsonResponse(['error' => 'Invalid entry ID'], 400);
            return;
        }

        $deleted = $this->leaderboardModel->deleteEntry($id);
        $this->jsonResponse(['success' => $deleted]);
    }

    /**
     * POST /api/admin/purge-leaderboard — Delete all leaderboard entries.
     */
    public function purgeLeaderboard(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $this->leaderboardModel->purgeAll();
        $this->jsonResponse(['success' => true]);
    }

    /**
     * POST /api/admin/set-deadline — Set the unstructured address deadline.
     */
    public function setDeadline(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = $this->getJsonInput();
        // Rejected rather than coerced: '' means "clear the deadline" on this
        // endpoint, so quietly turning a malformed value into '' would turn a
        // broken request into a destructive one. (An array here used to be an
        // uncaught TypeError in trim().)
        if (!is_string($input['deadline'] ?? '')) {
            $this->jsonResponse(['error' => 'Invalid date/time format. Use YYYY-MM-DDTHH:MM.'], 400);
            return;
        }
        $deadline = trim($input['deadline'] ?? '');

        // Through SettingsModel rather than a hand-written upsert: the raw
        // statement here was MySQL-only (ON DUPLICATE KEY), so setting a
        // deadline threw a syntax error on SQLite — which the end-to-end
        // instance now runs on. SettingsModel picks the dialect per driver.
        $settings = new SettingsModel(Database::getInstance()->getPdo());

        if ($deadline === '') {
            $settings->delete('unstructured_deadline');
            $this->jsonResponse(['success' => true, 'deadline' => null]);
            return;
        }

        // Validate ISO 8601 date/time
        $dt = \DateTime::createFromFormat('Y-m-d\TH:i', $deadline);
        if (!$dt) {
            $this->jsonResponse(['error' => 'Invalid date/time format. Use YYYY-MM-DDTHH:MM.'], 400);
            return;
        }

        $settings->set('unstructured_deadline', $deadline);
        $this->jsonResponse(['success' => true, 'deadline' => $deadline]);
    }

    /**
     * POST /api/admin/get-deadline — Get the unstructured address deadline (admin).
     */
    public function getDeadline(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $this->jsonResponse(['deadline' => $this->fetchDeadline()]);
    }

    /**
     * POST /api/admin/get-board-window — the wall's time window, in hours.
     */
    public function getBoardWindow(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $stored = (new SettingsModel(Database::getInstance()->getPdo()))->get('board_window_hours');

        $this->jsonResponse([
            'window_hours' => ($stored === null || !is_numeric($stored))
                ? LeaderboardModel::DEFAULT_WINDOW_HOURS
                : max(0, min(BoardController::MAX_WINDOW_HOURS, (int) $stored)),
        ]);
    }

    /**
     * POST /api/admin/set-board-window — set the wall's time window.
     *
     * Applies to ?mode=hof and to nothing else. The Hall of Fame served to
     * phones and to the iPad kiosk stays all-time: an organiser narrowing the
     * wall to the evening's own scores must not thereby erase the record from
     * every other screen.
     *
     * 0 means "since forever" rather than "a window of zero hours" — the
     * Admin field says so, and the model reads it the same way.
     */
    public function setBoardWindow(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = $this->getJsonInput();
        $raw = $input['window_hours'] ?? null;

        // Rejected rather than coerced. (int) '' is 0, and 0 is a meaningful
        // value here — a malformed request would otherwise quietly widen the
        // wall to all time instead of failing.
        if (!is_int($raw) && !(is_string($raw) && $raw !== '' && ctype_digit($raw))) {
            $this->jsonResponse(['error' => 'Window must be a whole number of hours.'], 400);
            return;
        }

        $hours = (int) $raw;
        if ($hours < 0 || $hours > BoardController::MAX_WINDOW_HOURS) {
            $this->jsonResponse(
                ['error' => 'Window must be between 0 and ' . BoardController::MAX_WINDOW_HOURS . ' hours.'],
                400
            );
            return;
        }

        (new SettingsModel(Database::getInstance()->getPdo()))
            ->set('board_window_hours', (string) $hours);

        $this->jsonResponse(['success' => true, 'window_hours' => $hours]);
    }

    /**
     * Fetch the stored deadline value from the settings table.
     */
    public static function fetchDeadlineStatic(): ?string
    {
        return (new SettingsModel(Database::getInstance()->getPdo()))->get('unstructured_deadline');
    }

    /**
     * The settings key behind the sharing switch, and its default.
     *
     * '1' is the default on purpose: a fresh installation behaves exactly as
     * every installation did before this key existed. Turning sharing off is
     * a deliberate act, never something an upgrade does on somebody's behalf.
     */
    public const SHARING_ENABLED_KEY = 'sharing_enabled';

    /**
     * Whether the interface offers sharing. Read by public/index.php, which
     * hands the answer to the shell.
     *
     * This governs WHAT IS RENDERED, and nothing else. The five share routes —
     * /share, /share/go, /share/image, /share/home-image and the
     * `share/token` action — answer identically whatever this returns, and
     * they must keep doing so:
     *
     *  - a link a player has already posted has to keep working, or this
     *    switch breaks something living in somebody's LinkedIn feed;
     *  - /share/home-image is not score sharing at all, it is the site's own
     *    OpenGraph image, so closing it would degrade the preview of every
     *    link to the game, including links that have nothing to do with a
     *    player's score;
     *  - and this is a product decision about what the UI offers, not an
     *    access control. Nothing here is a security boundary, and describing
     *    it as one would invite somebody to rely on it as though it were.
     *
     * Anything other than the stored '0' reads as enabled: an absent key, an
     * empty value and a row somebody typed by hand all fall to the behaviour
     * that was there before.
     */
    public static function sharingEnabledStatic(): bool
    {
        $pdo = Database::getInstance()->getPdo();
        if ($pdo === null) {
            return true;
        }

        return (new SettingsModel($pdo))->get(self::SHARING_ENABLED_KEY) !== '0';
    }

    /**
     * The settings key holding the display-mode token.
     *
     * An opaque random value, not a ciphertext. There is nothing here to
     * encrypt — nothing is ever recovered FROM the token, it is only ever
     * compared against — so App\Models\Encryption is deliberately not used:
     * it would add a key, an IV and an auth tag to a problem that is a string
     * comparison.
     */
    public const DISPLAY_MODE_TOKEN_KEY = 'display_mode_token';

    /** 16 random bytes, hex-encoded: 32 characters, 128 bits. */
    private const DISPLAY_MODE_TOKEN_BYTES = 16;

    /**
     * The current display-mode token, minting one on first read.
     *
     * Lazy rather than seeded, so an installation that already exists gets a
     * token without a migration: the first request that needs one writes it.
     * The write is idempotent — a second reader finds the row and returns it.
     *
     * Returns null when there is no database to ask. The caller must treat
     * that as "no mode", never as an empty token: hash_equals('', '') is TRUE,
     * so a null collapsed into a string would make ?mode=hof&t= match on an
     * instance whose database was momentarily unreachable.
     */
    public static function displayModeTokenStatic(): ?string
    {
        $pdo = Database::getInstance()->getPdo();
        if ($pdo === null) {
            return null;
        }

        $settings = new SettingsModel($pdo);
        $token = $settings->get(self::DISPLAY_MODE_TOKEN_KEY);
        if ($token !== null && $token !== '') {
            return $token;
        }

        $token = bin2hex(random_bytes(self::DISPLAY_MODE_TOKEN_BYTES));
        $settings->set(self::DISPLAY_MODE_TOKEN_KEY, $token);

        return $token;
    }

    /**
     * POST /api/admin/get-display-token — the token the two screen URLs carry.
     *
     * Admin-only, because handing it to anyone would make the URLs guessable
     * again, which is the one thing it exists to prevent.
     */
    public function getDisplayToken(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $this->jsonResponse(['token' => self::displayModeTokenStatic()]);
    }

    /**
     * POST /api/admin/regenerate-display-token — mint a new one.
     *
     * This is a silent breaking change to two screens that nobody is standing
     * in front of. The old URLs do not error, they quietly become the ordinary
     * game with menus — which is the correct behaviour for a wall (an error
     * page in front of a room is worse than a game) and a miserable thing to
     * diagnose. Hence the confirmation the browser puts in front of it, whose
     * text says exactly that, and hence the fact that this response carries
     * the new token so the panel can show the new URLs, QR codes and launch
     * commands immediately.
     *
     * The token is never logged, here or anywhere else. A value whose whole
     * purpose is to be unguessable does not belong in a log file that gets
     * pasted into an issue.
     */
    public function regenerateDisplayToken(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $token = bin2hex(random_bytes(self::DISPLAY_MODE_TOKEN_BYTES));
        (new SettingsModel(Database::getInstance()->getPdo()))
            ->set(self::DISPLAY_MODE_TOKEN_KEY, $token);

        $this->jsonResponse(['success' => true, 'token' => $token]);
    }

    /**
     * POST /api/admin/get-sharing — is the sharing UI offered?
     */
    public function getSharing(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $this->jsonResponse(['sharing_enabled' => self::sharingEnabledStatic()]);
    }

    /**
     * POST /api/admin/set-sharing — show or hide the sharing UI.
     *
     * Stored as '1'/'0' rather than deleted when switched back on, so the
     * setting reads the same whether it was never touched or deliberately
     * re-enabled.
     */
    public function setSharing(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $raw = $this->getJsonInput()['sharing_enabled'] ?? null;

        // Rejected rather than coerced: (bool) of anything is a value, and a
        // malformed request must not silently decide this either way.
        if (!is_bool($raw) && $raw !== 0 && $raw !== 1 && $raw !== '0' && $raw !== '1') {
            $this->jsonResponse(['error' => 'sharing_enabled must be true or false.'], 400);
            return;
        }

        $enabled = $raw === true || $raw === 1 || $raw === '1';

        (new SettingsModel(Database::getInstance()->getPdo()))
            ->set(self::SHARING_ENABLED_KEY, $enabled ? '1' : '0');

        $this->jsonResponse(['success' => true, 'sharing_enabled' => $enabled]);
    }


    private function fetchDeadline(): ?string
    {
        return self::fetchDeadlineStatic();
    }

    /**
     * POST /api/admin/get-facts — Get all "Did You Know" facts.
     */
    public function getFacts(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }
        $this->jsonResponse(['facts' => self::fetchFactsStatic()]);
    }

    /**
     * POST /api/admin/add-fact — Add a new fact.
     */
    public function addFact(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }
        $input = $this->getJsonInput();
        $content = $this->sanitizeFactContent($input['content'] ?? '');
        if ($content === '' || mb_strlen($content) > self::MAX_FACT_LENGTH) {
            $this->jsonResponse(['error' => 'Fact must be 1-' . self::MAX_FACT_LENGTH . ' characters'], 400);
            return;
        }
        $db = Database::getInstance();
        $pdo = $db->getPdo();
        $stmt = $pdo->prepare('INSERT INTO facts (content) VALUES (?)');
        $stmt->execute([$content]);
        $this->jsonResponse(['success' => true, 'id' => (int) $pdo->lastInsertId()]);
    }

    /**
     * POST /api/admin/update-fact — Update an existing fact.
     */
    public function updateFact(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }
        $input = $this->getJsonInput();
        $id = (int) ($input['id'] ?? 0);
        $content = $this->sanitizeFactContent($input['content'] ?? '');
        if ($id <= 0) {
            $this->jsonResponse(['error' => 'Invalid fact ID'], 400);
            return;
        }
        if ($content === '' || mb_strlen($content) > self::MAX_FACT_LENGTH) {
            $this->jsonResponse(['error' => 'Fact must be 1-' . self::MAX_FACT_LENGTH . ' characters'], 400);
            return;
        }
        $db = Database::getInstance();
        $pdo = $db->getPdo();
        $stmt = $pdo->prepare('UPDATE facts SET content = ? WHERE id = ?');
        $stmt->execute([$content, $id]);
        $this->jsonResponse(['success' => $stmt->rowCount() > 0]);
    }

    /**
     * POST /api/admin/delete-fact — Delete a fact.
     */
    public function deleteFact(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }
        $input = $this->getJsonInput();
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            $this->jsonResponse(['error' => 'Invalid fact ID'], 400);
            return;
        }
        $db = Database::getInstance();
        $pdo = $db->getPdo();
        $stmt = $pdo->prepare('DELETE FROM facts WHERE id = ?');
        $stmt->execute([$id]);
        $this->jsonResponse(['success' => $stmt->rowCount() > 0]);
    }

    /**
     * Fetch all facts from the database (public helper).
     *
     * @return list<array{id: int, content: string, created_at: string}>
     */
    public static function fetchFactsStatic(): array
    {
        $db = Database::getInstance();
        $pdo = $db->getPdo();
        $stmt = $pdo->query('SELECT id, content, created_at FROM facts ORDER BY id DESC');
        $facts = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Sanitise on the way out as well as on the way in, so rows stored
        // before the allowlist existed cannot reach the public screens as
        // executable markup.
        foreach ($facts as &$fact) {
            $fact['content'] = HtmlSanitizer::sanitize($fact['content'] ?? '');
        }
        unset($fact);

        return $facts;
    }

    /**
     * GET /api/admin/export — Export all scenarios as Excel file.
     */
    public function exportScenarios(): void
    {
        if (!$this->isAdmin()) {
            http_response_code(401);
            echo 'Unauthorized';
            return;
        }

        $scenarios = $this->scenarioModel->getAll();

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        // Sheet 1: Scenarios
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Scenarios');
        $headers = ['StrtNm', 'BldgNb', 'PstCd', 'TwnNm', 'Ctry', 'AdtlAdrInf'];
        foreach ($headers as $col => $h) {
            $sheet->setCellValue([$col + 1, 1], $h);
        }

        foreach ($scenarios as $rowIdx => $scenario) {
            $data = $scenario['json_data'];
            $row = [
                $data['StrtNm'] ?? '',
                $data['BldgNb'] ?? '',
                $data['PstCd'] ?? '',
                $data['TwnNm'] ?? '',
                $data['Ctry'] ?? '',
                $data['AdtlAdrInf'] ?? '',
            ];
            foreach ($row as $col => $value) {
                $sheet->setCellValue([$col + 1, $rowIdx + 2], $value);
            }
        }

        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Output
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $filename = 'scenarios_export_' . date('Y-m-d_His') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '', $filename) . '"');
        header('Cache-Control: max-age=0');

        $writer->save('php://output');
    }

    /**
     * POST /api/admin/game-stats — Get game counter stats.
     */
    public function getGameStats(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $db = Database::getInstance();
        $counter = new GameCounterModel($db->getPdo());

        $this->jsonResponse([
            'total_games' => $counter->getTotalCount(),
            'weekly_stats' => $counter->getWeeklyStats(52),
        ]);
    }

    /**
     * POST /api/admin/reset-game-counter — Reset game counter from leaderboard history.
     */
    public function resetGameCounter(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $db = Database::getInstance();
        $counter = new GameCounterModel($db->getPdo());
        $newCount = $counter->resetFromLeaderboard();

        $this->jsonResponse([
            'success' => true,
            'total_games' => $newCount,
        ]);
    }

    /**
     * POST /api/admin/get-theme — Return current theme colors.
     */
    public function getTheme(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $db = Database::getInstance();
        $theme = (new ThemeModel($db->getPdo()))->get();
        $this->jsonResponse(['theme' => $theme]);
    }

    /**
     * POST /api/admin/save-theme — Persist theme colors.
     */
    public function saveTheme(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = $this->getJsonInput();
        $colors = $input['theme'] ?? [];

        if (!is_array($colors)) {
            $this->jsonResponse(['error' => 'Invalid theme data'], 400);
            return;
        }

        $db = Database::getInstance();
        (new ThemeModel($db->getPdo()))->save($colors);
        $this->jsonResponse(['success' => true]);
    }

    /**
     * POST /api/admin/reset-theme — Discard every stored theme colour.
     *
     * Deletes the rows rather than writing the PMPG palette into them, so the
     * installation goes back to tracking ThemeModel::DEFAULTS the way a fresh
     * install does. See ThemeModel::reset() for why that distinction matters.
     *
     * Authenticated like its neighbours (get-theme, save-theme): admin session
     * required here, CSRF token checked by public/index.php before dispatch.
     * It is destructive — it discards an admin's customisation — so it must
     * not be reachable by an unauthenticated caller.
     */
    public function resetTheme(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $theme = (new ThemeModel(Database::getInstance()->getPdo()))->reset();
        $this->jsonResponse(['success' => true, 'theme' => $theme]);
    }

    /**
     * Facts accept a little inline markup, so they are sanitised against an
     * allowlist rather than escaped: they are rendered with innerHTML on the
     * public welcome screen, where anything stored runs in every visitor's
     * browser. The length limit is applied to the sanitised text so that markup
     * stripped during cleaning does not eat into the author's budget.
     */
    private const MAX_FACT_LENGTH = 500;

    private function sanitizeFactContent(mixed $raw): string
    {
        if (!is_string($raw)) {
            return '';
        }
        return HtmlSanitizer::sanitize(trim($raw));
    }

    /**
     * Check if current session is authenticated as admin.
     */
    public function isAdmin(): bool
    {
        return !empty($_SESSION['admin']);
    }

    /**
     * Retrieve the stored PIN from DB settings table, fallback to config file.
     */
    private function getStoredPin(): string
    {
        // An install that predates file-only storage still has the PIN in the
        // settings table, and on such an install THAT is the current PIN —
        // changePin() wrote it there, quite possibly long after the value left
        // in credentials.php. Reading the file alone would therefore silently
        // revert the PIN to a stale (often the original, weaker) value, so the
        // row is moved into the file and dropped on first use instead.
        $legacy = $this->legacyDatabasePin();
        if ($legacy !== null && $this->writePinToCredentials($legacy)) {
            $this->deleteLegacyDatabasePin();
            return $legacy;
        }
        if ($legacy !== null) {
            // The file could not be written, so the row is still the only copy
            // — keep honouring it rather than locking the admin out.
            return $legacy;
        }

        return $this->readPinFromCredentials() ?? '0000';
    }

    /**
     * The PIN left in the settings table by a version that stored it there,
     * or null on any install that never did (or has already been migrated).
     */
    private function legacyDatabasePin(): ?string
    {
        $pdo = Database::getInstance()->getPdo();
        if ($pdo === null) {
            return null;
        }

        try {
            return (new SettingsModel($pdo))->get('admin_pin');
        } catch (\Throwable) {
            // A pre-migration install may not even have the settings table.
            return null;
        }
    }

    private function deleteLegacyDatabasePin(): void
    {
        $pdo = Database::getInstance()->getPdo();
        if ($pdo === null) {
            return;
        }

        try {
            (new SettingsModel($pdo))->delete('admin_pin');
        } catch (\Throwable) {
            // Migration is best effort: the file already holds the PIN, so a
            // surviving row only means this runs again next time.
        }
    }

    /**
     * The decoded JSON request body.
     *
     * `protected` rather than `private` purely so tests can supply a body:
     * php://input is not writable from inside the process, so this is the
     * only seam through which the request-body validation on these endpoints
     * (the channel allowlist, the required owner/repo) can be exercised
     * without a browser. Production behaviour is unchanged.
     */
    /**
     * The decoded JSON request body.
     *
     * The shape belongs to the caller, so values are mixed by definition —
     * every string field is read through App\Support\Input::string().
     *
     * @return array<string, mixed>
     */
    protected function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        return json_decode($raw, true) ?? [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
    }
}
