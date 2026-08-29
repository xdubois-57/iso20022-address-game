-- ISO 20022 Address Structuring Game
-- Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.

-- Database schema for ISO 20022 Address Game
--
-- Database::initSchema() creates the same tables automatically on first run, so
-- this script is only needed for a manual install. Keep the two in step: the
-- authoritative DDL lives in app/Models/Database.php, which also carries a
-- SQLite variant used by the test suite.

CREATE TABLE IF NOT EXISTS scenarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    json_data JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leaderboard (
    id INT AUTO_INCREMENT PRIMARY KEY,
    encrypted_name VARCHAR(512) NOT NULL,
    score INT NOT NULL DEFAULT 0,
    time_seconds INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_score (score DESC),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(64) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- "Did you know?" facts shown on the welcome screen and screen saver.
-- Ten defaults are seeded by Database::initSchema() when this table is empty.
CREATE TABLE IF NOT EXISTS facts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per completed game, for the admin statistics chart.
CREATE TABLE IF NOT EXISTS game_counter (
    id INT AUTO_INCREMENT PRIMARY KEY,
    played_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rate limiting for admin login, event code entry and score submission.
-- `bucket` is a keyed hash of the caller's address: the address itself is never
-- stored. Rows are pruned once they no longer hold anyone back.
CREATE TABLE IF NOT EXISTS rate_limits (
    bucket VARCHAR(191) PRIMARY KEY,
    attempts INT NOT NULL DEFAULT 0,
    updated_at INT NOT NULL DEFAULT 0,
    locked_until INT NOT NULL DEFAULT 0,
    INDEX idx_locked_until (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
