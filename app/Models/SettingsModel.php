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

namespace App\Models;

use PDO;

/**
 * Single access point for the key/value `settings` table.
 *
 * Every setting (admin PIN, event code, deadline, theme colors) used to carry
 * its own copy of the same MySQL-only upsert. Centralising it here removes that
 * duplication and lets the upsert adapt to the driver, so the same code runs
 * against MySQL in production and SQLite under test.
 */
class SettingsModel
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Return a setting's value, or null when it is absent or empty.
     */
    public function get(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['setting_value'] === '') {
            return null;
        }
        return $row['setting_value'];
    }

    /**
     * Insert or update a setting.
     */
    public function set(string $key, string $value): void
    {
        $this->upsertStatement()->execute([$key, $value]);
    }

    /**
     * Insert or update several settings inside one transaction.
     *
     * @param array<string,string> $pairs
     */
    public function setMany(array $pairs): void
    {
        if ($pairs === []) {
            return;
        }

        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmt = $this->upsertStatement();
            foreach ($pairs as $key => $value) {
                $stmt->execute([$key, $value]);
            }
            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction) {
                $this->rollBackIfOpen();
            }
            throw $e;
        }
    }

    /**
     * Roll back, unless the transaction has already gone.
     *
     * A method rather than two lines inline: `beginTransaction()` and
     * `rollBack()` change what `inTransaction()` returns, and static analysis
     * assumes it does not — so an inline second call is read as still holding
     * the answer from the first, and the guard is reported as dead. Behind a
     * call boundary there is no earlier answer to reuse. The check itself is
     * not ceremony: the throw may have come from `commit()`, by which point
     * there is nothing left to roll back.
     */
    private function rollBackIfOpen(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * Remove a setting. Missing keys are a no-op.
     */
    public function delete(string $key): void
    {
        $this->pdo->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([$key]);
    }

    /**
     * Fetch several settings at once as a key => value map. Absent keys are omitted.
     *
     * @param  list<string> $keys
     * @return array<string,string>
     */
    public function getMany(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)"
        );
        $stmt->execute($keys);

        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * Driver-appropriate "insert or replace" statement.
     *
     * MySQL 5.7 has no ON CONFLICT, and SQLite has no ON DUPLICATE KEY, so the
     * two dialects are spelled out rather than sharing one string.
     */
    private function upsertStatement(): \PDOStatement
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $sql = $driver === 'sqlite'
            ? 'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) '
                . 'ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value'
            : 'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) '
                . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)';

        return $this->pdo->prepare($sql);
    }
}
