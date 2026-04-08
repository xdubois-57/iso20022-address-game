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
use App\Models\FactModel;
use App\Models\LeaderboardModel;
use App\Models\ExcelParser;

class AdminController
{
    private ScenarioModel $scenarioModel;
    private FactModel $factModel;
    private LeaderboardModel $leaderboardModel;

    public function __construct()
    {
        $db = Database::getInstance();
        $pdo = $db->getPdo();
        $this->scenarioModel = new ScenarioModel($pdo);
        $this->factModel = new FactModel($pdo);
        $this->leaderboardModel = new LeaderboardModel($pdo);
    }

    /**
     * POST /api/admin/login — Verify PIN.
     */
    public function login(): void
    {
        $input = $this->getJsonInput();
        $pin = $input['pin'] ?? '';

        $storedPin = $this->getStoredPin();

        if ($pin === $storedPin) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $this->jsonResponse(['success' => true]);
        } else {
            $this->jsonResponse(['error' => 'Invalid PIN'], 401);
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
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            $this->jsonResponse(['error' => 'Only .xlsx files are accepted'], 400);
            return;
        }

        $uploadDir = __DIR__ . '/../../uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $tmpPath = $uploadDir . 'upload_' . time() . '.xlsx';
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
            $this->scenarioModel->create($s['json_data'], $s['goal_type']);
        }

        // Replace facts
        $this->factModel->deleteAll();
        foreach ($result['facts'] as $factText) {
            $this->factModel->create($factText);
        }

        unlink($tmpPath);

        $this->jsonResponse([
            'success' => true,
            'imported' => [
                'scenarios' => count($result['scenarios']),
                'facts' => count($result['facts']),
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

        $db = Database::getInstance();
        $pdo = $db->getPdo();
        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute(['admin_pin', $newPin]);

        $this->jsonResponse(['success' => true]);
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
