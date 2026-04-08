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

class SetupController
{
    /**
     * POST /api/setup/test — Test a DB connection with provided credentials.
     */
    public function testConnection(): void
    {
        $input = $this->getJsonInput();
        $dbConfig = [
            'host' => trim($input['host'] ?? '127.0.0.1'),
            'port' => trim($input['port'] ?? '3306'),
            'name' => trim($input['name'] ?? ''),
            'username' => trim($input['username'] ?? ''),
            'password' => $input['password'] ?? '',
        ];

        if (empty($dbConfig['name'])) {
            $this->jsonResponse(['error' => 'Database name is required'], 400);
            return;
        }

        $db = Database::getInstance();
        if ($db->tryConnect($dbConfig)) {
            $this->jsonResponse(['success' => true, 'message' => 'Connection successful']);
        } else {
            $this->jsonResponse(['error' => 'Could not connect to the database with the provided credentials'], 400);
        }
    }

    /**
     * POST /api/setup/save — Save DB config to JSON and initialize schema.
     */
    public function saveConfig(): void
    {
        $input = $this->getJsonInput();
        $dbConfig = [
            'host' => trim($input['host'] ?? '127.0.0.1'),
            'port' => trim($input['port'] ?? '3306'),
            'name' => trim($input['name'] ?? ''),
            'username' => trim($input['username'] ?? ''),
            'password' => $input['password'] ?? '',
        ];

        if (empty($dbConfig['name'])) {
            $this->jsonResponse(['error' => 'Database name is required'], 400);
            return;
        }

        $db = Database::getInstance();
        if (!$db->tryConnect($dbConfig)) {
            $this->jsonResponse(['error' => 'Connection failed. Cannot save invalid credentials.'], 400);
            return;
        }

        // Save to JSON file
        if (!$db->saveJsonConfig($dbConfig)) {
            $this->jsonResponse(['error' => 'Failed to write config file. Check directory permissions.'], 500);
            return;
        }

        // Initialize schema
        $db->initSchema();

        $this->jsonResponse(['success' => true, 'message' => 'Configuration saved and database initialized']);
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
