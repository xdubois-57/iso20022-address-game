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
use App\Models\GitHubWebhook;
use App\Models\Updater;

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
        $pin = $input['pin'] ?? '';

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
        $newPin = $input['new_pin'] ?? '';

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
        $deadline = trim($input['deadline'] ?? '');

        // Through SettingsModel, which is where the driver-specific upsert
        // lives. Writing the MySQL spelling inline here meant this endpoint
        // — and clearing the deadline with it, since the statement was
        // prepared before the early return — was a hard error on SQLite.
        $settings = new SettingsModel(Database::getInstance()->getPdo());

        if ($deadline === '') {
            // Clear the deadline
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
     * Fetch the stored deadline value from the settings table.
     */
    public static function fetchDeadlineStatic(): ?string
    {
        return (new SettingsModel(Database::getInstance()->getPdo()))->get('unstructured_deadline');
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
     * POST /api/admin/get-event-code — Return the current event code (or empty if none).
     */
    public function getEventCode(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }
        // Return only whether a code exists. This used to return the stored
        // bcrypt hash, letting any admin session carry it off for offline
        // cracking; the masking the README describes was purely cosmetic and
        // happened in the browser.
        $this->jsonResponse(['has_code' => self::fetchEventCodeStatic() !== null]);
    }

    /**
     * POST /api/admin/set-event-code — Save or clear the event code.
     * Event codes are hashed with bcrypt (like admin PIN) for secure storage.
     */
    public function setEventCode(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = $this->getJsonInput();
        $code  = trim($input['event_code'] ?? '');

        $db  = Database::getInstance();
        $pdo = $db->getPdo();
        // Same reason as setDeadline(): the upsert dialect belongs to
        // SettingsModel, and the MySQL spelling written inline here made this
        // endpoint a hard error on SQLite.
        $settings = new SettingsModel($pdo);

        if ($code === '') {
            $settings->delete('event_code');
            $settings->delete('event_code_timestamp');
            // Removing the code releases everyone currently locked out.
            (new RateLimitModel($pdo))->clearScope('event_code');
            unset($_SESSION['event_code_verified_at']);
            $this->jsonResponse(['success' => true, 'has_code' => false]);
            return;
        }

        if (mb_strlen($code) > 64) {
            $this->jsonResponse(['error' => 'Event code must be 64 characters or less'], 400);
            return;
        }

        // Hash the event code with bcrypt (same as admin PIN), and store it
        // alongside its timestamp in one transaction — a code without the
        // matching timestamp would leave every existing session judged
        // against the previous code. setMany() opens the transaction.
        $settings->setMany([
            'event_code' => password_hash($code, PASSWORD_BCRYPT),
            'event_code_timestamp' => (string) time(),
        ]);

        // A new code releases anyone locked out under the old one, for every
        // caller rather than just this session.
        (new RateLimitModel($pdo))->clearScope('event_code');
        // Note: We don't set event_code_verified_at here because the admin should still enter the code

        $this->jsonResponse(['success' => true, 'has_code' => true]);
    }

    /**
     * Return the stored event code, or null if none is set.
     */
    public static function fetchEventCodeStatic(): ?string
    {
        return (new SettingsModel(Database::getInstance()->getPdo()))->get('event_code');
    }

    /**
     * Return the timestamp when event code was last changed, or 0 if never set.
     */
    public static function fetchEventCodeTimestampStatic(): int
    {
        return (int) ((new SettingsModel(Database::getInstance()->getPdo()))->get('event_code_timestamp') ?? 0);
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

    private const UPDATE_CHANNELS = ['release', 'main'];

    /**
     * POST /api/admin/get-update-settings — Automatic Updates panel state.
     *
     * The webhook secret itself is never returned once set — same rule as
     * getEventCode() above: it is shown once, when generated, and never
     * again, so an admin session that later leaks can't carry it off.
     */
    public function getUpdateSettings(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $settings = new SettingsModel(Database::getInstance()->getPdo());
        $values = $settings->getMany([
            'update_enabled', 'update_channel', 'update_webhook_secret',
            'update_github_owner', 'update_github_repo',
            'update_last_event_at', 'update_last_event_result',
            'update_last_install_at', 'update_last_install_status', 'update_last_install_error',
            'update_dependencies_changed', 'update_pending',
        ]);

        $this->jsonResponse([
            'enabled' => ($values['update_enabled'] ?? '0') === '1',
            'channel' => $values['update_channel'] ?? 'release',
            'has_secret' => !empty($values['update_webhook_secret']),
            'github_owner' => $values['update_github_owner'] ?? 'xdubois-57',
            'github_repo' => $values['update_github_repo'] ?? 'iso20022-address-game',
            'webhook_path' => '/webhook/github',
            'last_event_at' => isset($values['update_last_event_at']) ? (int) $values['update_last_event_at'] : null,
            'last_event_result' => $values['update_last_event_result'] ?? null,
            'last_install_at' => isset($values['update_last_install_at']) ? (int) $values['update_last_install_at'] : null,
            'last_install_status' => $values['update_last_install_status'] ?? null,
            'last_install_error' => $values['update_last_install_error'] ?? null,
            'dependencies_changed' => ($values['update_dependencies_changed'] ?? '0') === '1',
            'install_pending' => isset($values['update_pending']),
            'version' => $this->currentVersion(),
        ]);
    }

    /**
     * POST /api/admin/save-update-settings — Persist enable/channel/repo.
     */
    public function saveUpdateSettings(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = $this->getJsonInput();
        $channel = $input['channel'] ?? '';
        if (!in_array($channel, self::UPDATE_CHANNELS, true)) {
            $this->jsonResponse(['error' => 'Invalid channel'], 400);
            return;
        }

        $owner = trim((string) ($input['github_owner'] ?? ''));
        $repo = trim((string) ($input['github_repo'] ?? ''));
        if ($owner === '' || $repo === '' || mb_strlen($owner) > 100 || mb_strlen($repo) > 100) {
            $this->jsonResponse(['error' => 'GitHub owner and repository are required'], 400);
            return;
        }

        $settings = new SettingsModel(Database::getInstance()->getPdo());
        $settings->setMany([
            'update_enabled' => !empty($input['enabled']) ? '1' : '0',
            'update_channel' => $channel,
            'update_github_owner' => $owner,
            'update_github_repo' => $repo,
        ]);

        $this->jsonResponse(['success' => true]);
    }

    /**
     * POST /api/admin/generate-webhook-secret — Issue a new webhook secret.
     *
     * Returned in full exactly once, in this response, for the admin to
     * paste into GitHub's webhook configuration. Regenerating invalidates
     * the previous secret immediately — any webhook still configured with
     * the old one starts failing signature verification.
     */
    public function generateWebhookSecret(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $secret = bin2hex(random_bytes(32));
        (new SettingsModel(Database::getInstance()->getPdo()))->set('update_webhook_secret', $secret);

        $this->jsonResponse(['success' => true, 'secret' => $secret]);
    }

    /**
     * POST /api/admin/install-update-now — Manual trigger, bypassing the
     * webhook entirely: asks GitHub for the latest release or latest main
     * commit (whichever the configured channel means) and installs it
     * through the same App\Models\Updater path a webhook delivery uses.
     *
     * Runs synchronously — unlike the webhook path, an admin clicking this
     * button is already watching the page for the result, so there is
     * nothing to defer.
     */
    public function installUpdateNow(): void
    {
        if (!$this->isAdmin()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $pdo = Database::getInstance()->getPdo();
        $settings = new SettingsModel($pdo);

        $queued = (new GitHubWebhook($settings))->checkAndQueueLatest();
        if ($queued['status'] !== 'ok') {
            $this->jsonResponse(['success' => false, 'reason' => $queued['reason'] ?? 'unknown']);
            return;
        }

        // Unlike the webhook path, this one answers the caller only once the
        // install is done, so it needs the same headroom that path grants
        // itself: a default 30s max_execution_time is not enough for a
        // multi-megabyte download plus a full file-tree copy, and the admin
        // closing the tab mid-install must not abort a half-applied update
        // and leave the site on a mixed tree.
        ignore_user_abort(true);
        set_time_limit(300);

        $result = (new Updater(dirname(__DIR__, 2), $settings))->run();
        $this->jsonResponse(['success' => $result['status'] === 'completed', 'result' => $result]);
    }

    private function currentVersion(): array
    {
        $versionFile = __DIR__ . '/../../config/version.php';
        if (file_exists($versionFile)) {
            $info = include $versionFile;
            if (is_array($info) && !empty($info['tag']) && !empty($info['commit'])) {
                return ['tag' => $info['tag'], 'commit' => $info['commit']];
            }
        }
        return ['tag' => 'dev', 'commit' => 'unknown'];
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
    protected function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        return json_decode($raw, true) ?? [];
    }

    private function jsonResponse(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
    }
}
