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

namespace App\Models;

use PDO;
use PDOException;

class Database
{
    private static ?Database $instance = null;
    private ?PDO $pdo = null;
    private array $config = [];

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Attempt to connect using credentials.php first, then db_config.json fallback.
     * Returns true on success, false on failure.
     */
    public function connect(): bool
    {
        // Try credentials.php first
        $credFile = __DIR__ . '/../../config/credentials.php';
        if (file_exists($credFile)) {
            $creds = require $credFile;
            if (isset($creds['db']) && $this->tryConnect($creds['db'])) {
                return true;
            }
        }

        // Fallback to db_config.json
        $jsonFile = __DIR__ . '/../../config/db_config.json';
        if (file_exists($jsonFile)) {
            $json = file_get_contents($jsonFile);
            $dbConfig = json_decode($json, true);
            if ($dbConfig && $this->tryConnect($dbConfig)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Try connecting with the given config array.
     */
    public function tryConnect(array $dbConfig): bool
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $dbConfig['host'] ?? '127.0.0.1',
                $dbConfig['port'] ?? '3306',
                $dbConfig['name'] ?? ''
            );
            $this->pdo = new PDO(
                $dsn,
                $dbConfig['username'] ?? '',
                $dbConfig['password'] ?? '',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            $this->config = $dbConfig;
            return true;
        } catch (PDOException $e) {
            $this->pdo = null;
            return false;
        }
    }

    /**
     * Save DB config to the JSON fallback file.
     */
    public function saveJsonConfig(array $dbConfig): bool
    {
        $jsonFile = __DIR__ . '/../../config/db_config.json';
        $json = json_encode($dbConfig, JSON_PRETTY_PRINT);
        return file_put_contents($jsonFile, $json) !== false;
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    public function getPdo(): ?PDO
    {
        return $this->pdo;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Initialize the database schema if tables do not exist.
     */
    public function initSchema(): void
    {
        if (!$this->pdo) {
            return;
        }

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS scenarios (
                id INT AUTO_INCREMENT PRIMARY KEY,
                json_data JSON NOT NULL,
                goal_type ENUM('Structured', 'Hybrid') NOT NULL DEFAULT 'Structured',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS leaderboard (
                id INT AUTO_INCREMENT PRIMARY KEY,
                encrypted_name VARCHAR(512) NOT NULL,
                score INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_score (score DESC),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS facts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message_text TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                setting_key VARCHAR(64) PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    private function __clone()
    {
    }

    public function __wakeup()
    {
        throw new \Exception('Cannot unserialize singleton');
    }
}
