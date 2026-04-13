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
use App\Models\ExcelParser;

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
        // Rate limiting
        $attempts = $_SESSION['login_attempts'] ?? 0;
        $lockUntil = $_SESSION['login_lock_until'] ?? 0;

        if ($attempts >= self::MAX_LOGIN_ATTEMPTS && time() < $lockUntil) {
            $remaining = $lockUntil - time();
            $this->jsonResponse(['error' => "Too many attempts. Try again in {$remaining}s."], 429);
            return;
        }

        // Reset if lockout expired
        if (time() >= $lockUntil && $attempts >= self::MAX_LOGIN_ATTEMPTS) {
            $_SESSION['login_attempts'] = 0;
            $attempts = 0;
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
            $_SESSION['login_attempts'] = 0;
            unset($_SESSION['login_lock_until']);
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $this->jsonResponse(['success' => true]);
        } else {
            $_SESSION['login_attempts'] = $attempts + 1;
            if ($attempts + 1 >= self::MAX_LOGIN_ATTEMPTS) {
                $_SESSION['login_lock_until'] = time() + self::LOCKOUT_SECONDS;
            }
            $this->jsonResponse(['error' => 'Invalid PIN'], 401);
        }
    }

    /**
     * Upgrade a plaintext PIN to a bcrypt hash in both DB and config file.
     */
    private function upgradePinToHash(string $pin): void
    {
        $hash = password_hash($pin, PASSWORD_BCRYPT);

        // Store in DB
        $db = Database::getInstance();
        $pdo = $db->getPdo();
        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute(['admin_pin', $hash]);

        // Also update config file if it has plaintext
        $credFile = __DIR__ . '/../../config/credentials.php';
        if (file_exists($credFile)) {
            $content = file_get_contents($credFile);
            $escaped = preg_quote($pin, '/');
            $updated = preg_replace(
                "/'pin'\s*=>\s*'" . $escaped . "'/",
                "'pin' => '" . addcslashes($hash, "'") . "'",
                $content
            );
            if ($updated !== $content) {
                file_put_contents($credFile, $updated);
            }
        }
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
        move_uploaded_file($file['tmp_name'], $tmpPath);

        $parser = new ExcelParser();
        $result = $parser->parse($tmpPath);

        if (!empty($result['errors'])) {
            unlink($tmpPath);
            $this->jsonResponse(['errors' => $result['errors']], 422);
            return;
        }

        // Replace scenarios
        $this->scenarioModel->deleteAll();
        foreach ($result['scenarios'] as $s) {
            $this->scenarioModel->create($s['json_data']);
        }

        unlink($tmpPath);

        $this->jsonResponse([
            'success' => true,
            'imported' => [
                'scenarios' => count($result['scenarios']),
            ],
        ]);
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

        $db = Database::getInstance();
        $pdo = $db->getPdo();
        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute(['admin_pin', $hash]);

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

        $db = Database::getInstance();
        $pdo = $db->getPdo();
        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );

        if ($deadline === '') {
            // Clear the deadline
            $pdo->prepare('DELETE FROM settings WHERE setting_key = ?')->execute(['unstructured_deadline']);
            $this->jsonResponse(['success' => true, 'deadline' => null]);
            return;
        }

        // Validate ISO 8601 date/time
        $dt = \DateTime::createFromFormat('Y-m-d\TH:i', $deadline);
        if (!$dt) {
            $this->jsonResponse(['error' => 'Invalid date/time format. Use YYYY-MM-DDTHH:MM.'], 400);
            return;
        }

        $stmt->execute(['unstructured_deadline', $deadline]);
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
        $db = Database::getInstance();
        $pdo = $db->getPdo();
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute(['unstructured_deadline']);
        $row = $stmt->fetch();
        return $row ? $row['setting_value'] : null;
    }

    private function fetchDeadline(): ?string
    {
        return self::fetchDeadlineStatic();
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
            $sheet->setCellValueByColumnAndRow($col + 1, 1, $h);
        }

        foreach ($scenarios as $rowIdx => $scenario) {
            $data = json_decode($scenario['json_data'], true);
            $row = [
                $data['StrtNm'] ?? '',
                $data['BldgNb'] ?? '',
                $data['PstCd'] ?? '',
                $data['TwnNm'] ?? '',
                $data['Ctry'] ?? '',
                $data['AdtlAdrInf'] ?? '',
            ];
            foreach ($row as $col => $value) {
                $sheet->setCellValueByColumnAndRow($col + 1, $rowIdx + 2, $value);
            }
        }

        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Output
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $filename = 'scenarios_export_' . date('Y-m-d_His') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer->save('php://output');
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
        $db = Database::getInstance();
        $pdo = $db->getPdo();
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute(['admin_pin']);
        $row = $stmt->fetch();
        if ($row) {
            return $row['setting_value'];
        }

        // Fallback to credentials.php
        $credFile = __DIR__ . '/../../config/credentials.php';
        if (file_exists($credFile)) {
            $creds = require $credFile;
            return $creds['admin']['pin'] ?? '0000';
        }

        return '0000';
    }

    private function getJsonInput(): array
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
