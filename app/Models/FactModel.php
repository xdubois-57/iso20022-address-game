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

class FactModel
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get a random "Did you know?" fact.
     */
    public function getRandom(): ?string
    {
        $stmt = $this->pdo->query('SELECT message_text FROM facts ORDER BY RAND() LIMIT 1');
        $row = $stmt->fetch();
        return $row ? $row['message_text'] : null;
    }

    /**
     * Get all facts.
     */
    public function getAll(): array
    {
        $stmt = $this->pdo->query('SELECT id, message_text FROM facts ORDER BY id');
        return $stmt->fetchAll();
    }

    /**
     * Insert a new fact.
     */
    public function create(string $messageText): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO facts (message_text) VALUES (?)');
        $stmt->execute([$messageText]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Delete all facts (used before Excel re-import).
     */
    public function deleteAll(): void
    {
        $this->pdo->exec('DELETE FROM facts');
    }
}
