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

namespace Tests\Support;

use App\Models\Database;
use PDO;

/**
 * Backs the Database singleton with a fresh in-memory SQLite database.
 *
 * These tests used to call Database::connect() and run against whatever
 * config/credentials.php pointed at — dropping tables and deleting rows in the
 * process. On a machine configured for production that destroyed real data, and
 * on a clean clone the unchecked connect() produced a hard error instead of a
 * skip. Binding the singleton to an in-memory database removes both problems:
 * every test starts from an empty schema and nothing outside the process is
 * ever touched.
 */
trait UsesInMemoryDatabase
{
    private ?PDO $memoryPdo = null;

    /**
     * Create the in-memory database, install the schema, and hand it to the
     * Database singleton so that static helpers resolve to it.
     */
    protected function bootInMemoryDatabase(): PDO
    {
        Database::resetInstance();

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $db = Database::getInstance();
        $db->setPdo($pdo);
        $db->initSchema();

        $this->memoryPdo = $pdo;
        return $pdo;
    }

    /**
     * Drop the connection so the next test cannot inherit this one's rows.
     */
    protected function shutdownInMemoryDatabase(): void
    {
        $this->memoryPdo = null;
        Database::getInstance()->setPdo(null);
        Database::resetInstance();
    }

    protected function memoryPdo(): PDO
    {
        if ($this->memoryPdo === null) {
            throw new \LogicException('bootInMemoryDatabase() must be called first.');
        }
        return $this->memoryPdo;
    }
}
