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
     * Directory config/credentials.php and config/db_config.json live in.
     *
     * Defaults to the real config/ directory (production, and every
     * developer's normal local run — behaviour here is byte-identical to
     * before this existed). The ISO20022_CONFIG_DIR override exists solely
     * for the Playwright E2E harness (scripts/e2e.sh): it points a
     * throwaway instance at its own scratch config directory — a SQLite
     * db_config.json, its own credentials.php — without ever touching a
     * developer's real config/db_config.json. Never set in production.
     */
    public static function configDir(): string
    {
        $override = getenv('ISO20022_CONFIG_DIR');
        return $override !== false && $override !== '' ? $override : __DIR__ . '/../../config';
    }

    /**
     * Attempt to connect using credentials.php first, then db_config.json fallback.
     * Returns true on success, false on failure.
     */
    public function connect(): bool
    {
        $configDir = self::configDir();

        // Try credentials.php first
        $credFile = $configDir . '/credentials.php';
        if (file_exists($credFile)) {
            $creds = require $credFile;
            if (isset($creds['db']) && $this->tryConnect($creds['db'])) {
                return true;
            }
        }

        // Fallback to db_config.json
        $jsonFile = $configDir . '/db_config.json';
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
     * Try connecting with the given config array. MySQL unless the config
     * explicitly asks for SQLite ('driver' => 'sqlite', 'path' => '...') —
     * the same driver the PHPUnit suite already runs every test against
     * (Tests\Support\UsesInMemoryDatabase), now reachable through a config
     * file too so the Playwright E2E harness can boot a real HTTP server
     * against a throwaway file-backed SQLite database with no MySQL
     * involved. Never used by a production install: db_config.json is only
     * ever written by SetupController, which never offers this driver.
     */
    public function tryConnect(array $dbConfig): bool
    {
        try {
            if (($dbConfig['driver'] ?? 'mysql') === 'sqlite') {
                $dsn = 'sqlite:' . (string) ($dbConfig['path'] ?? '');
                $this->pdo = new PDO($dsn, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                return true;
            }

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
            return true;
        } catch (PDOException $e) {
            $this->pdo = null;
            return false;
        }
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    public function getPdo(): ?PDO
    {
        return $this->pdo;
    }

    /**
     * Inject a PDO connection directly, bypassing credential discovery.
     *
     * This is the seam the test suite uses to run against an in-memory SQLite
     * database instead of the developer's configured MySQL server, so that
     * `composer test` can never touch — let alone drop tables in — a real
     * installation. Production code always goes through connect().
     */
    public function setPdo(?PDO $pdo): void
    {
        $this->pdo = $pdo;
    }

    /**
     * Discard the singleton so each test starts from a clean connection.
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Name of the PDO driver currently connected ('mysql', 'sqlite', ...).
     */
    public function getDriver(): string
    {
        if (!$this->pdo) {
            return '';
        }
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Initialize the database schema if tables do not exist.
     *
     * The DDL is emitted per driver: MySQL in production, SQLite for the test
     * suite. Keep the two branches in step — and mirror any change into
     * scripts/schema.sql, which documents the same schema for manual installs.
     */
    public function initSchema(): void
    {
        if (!$this->pdo) {
            return;
        }

        if ($this->getDriver() === 'sqlite') {
            $this->initSchemaSqlite();
        } else {
            $this->initSchemaMysql();
        }

        $this->seedDefaultFacts();
        $this->purgeRemovedEventCodeData();
    }

    /**
     * Migration (schema v7): the event-code gate was removed — the game is
     * open to everyone — so an install that once configured a code must not
     * keep its bcrypt hash in `settings`, nor the hashed caller addresses of
     * people it locked out in `rate_limits`, forever. Plain DELETEs, portable
     * across both drivers, and a no-op on installs that never used the
     * feature.
     */
    private function purgeRemovedEventCodeData(): void
    {
        $this->pdo->prepare('DELETE FROM settings WHERE setting_key IN (?, ?)')
            ->execute(['event_code', 'event_code_timestamp']);
        $this->pdo->prepare('DELETE FROM rate_limits WHERE bucket LIKE ?')
            ->execute(['event_code:%']);
    }

    private function initSchemaMysql(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS scenarios (
                id INT AUTO_INCREMENT PRIMARY KEY,
                json_data JSON NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Migration: drop goal_type column if present (existing installs)
        try {
            $this->pdo->exec("ALTER TABLE scenarios DROP COLUMN goal_type");
        } catch (\PDOException $e) {
            // Column already removed — ignore
        }

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS leaderboard (
                id INT AUTO_INCREMENT PRIMARY KEY,
                encrypted_name VARCHAR(512) NOT NULL,
                score INT NOT NULL DEFAULT 0,
                time_seconds INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_score (score DESC),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Migration: add time_seconds if missing (existing installs)
        try {
            $this->pdo->exec("ALTER TABLE leaderboard ADD COLUMN time_seconds INT NOT NULL DEFAULT 0 AFTER score");
        } catch (\PDOException $e) {
            // Column already exists — ignore
        }

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                setting_key VARCHAR(64) PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS facts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                content TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS game_counter (
                id INT AUTO_INCREMENT PRIMARY KEY,
                played_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Rate limiting keyed on a hash of the caller's address, so counters
        // survive a client discarding its session cookie. No address is stored.
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS rate_limits (
                bucket VARCHAR(191) PRIMARY KEY,
                attempts INT NOT NULL DEFAULT 0,
                updated_at INT NOT NULL DEFAULT 0,
                locked_until INT NOT NULL DEFAULT 0,
                INDEX idx_locked_until (locked_until)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    private function initSchemaSqlite(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS scenarios (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                json_data TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS leaderboard (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                encrypted_name TEXT NOT NULL,
                score INTEGER NOT NULL DEFAULT 0,
                time_seconds INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_score ON leaderboard (score DESC)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_created ON leaderboard (created_at)');

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                setting_key VARCHAR(64) PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS facts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                content TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS game_counter (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                played_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS rate_limits (
                bucket VARCHAR(191) PRIMARY KEY,
                attempts INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0,
                locked_until INTEGER NOT NULL DEFAULT 0
            )
        ");
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_locked_until ON rate_limits (locked_until)');
    }

    private function seedDefaultFacts(): void
    {
        // Seed default facts only if the table is empty (first-time setup)
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM facts')->fetchColumn();
        if ($count === 0) {
            $defaultFacts = [
                'ISO 20022 Standard Release 2026 marks the end of unstructured address support globally',
                'Over 70 countries have already adopted ISO 20022 for cross-border payments',
                'The transition to structured addresses improves payment processing speed by up to 40%',
                'Unstructured addresses will be phased out starting November 14, 2026',
                'ISO 20022 enables richer data exchange between financial institutions worldwide',
                'The new standard supports 207 address formats across all world regions',
                'Structured addresses reduce payment failures and processing errors significantly',
                'November 2026 is the deadline for complete migration to ISO 20022 structured addresses',
                'ISO 20022 provides a common language for financial messaging globally',
                'The 2026 release ensures interoperability between all payment systems worldwide'
            ];

            $insert = $this->pdo->prepare('INSERT INTO facts (content) VALUES (?)');
            foreach ($defaultFacts as $fact) {
                $insert->execute([$fact]);
            }
        }
    }

    private function __clone()
    {
    }

    public function __wakeup()
    {
        throw new \Exception('Cannot unserialize singleton');
    }
}
