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

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Models\Database;

class DatabaseTest extends TestCase
{
    public function testGetInstanceReturnsSameObject(): void
    {
        $db1 = Database::getInstance();
        $db2 = Database::getInstance();
        $this->assertSame($db1, $db2);
    }

    public function testTryConnectWithInvalidCredentialsFails(): void
    {
        $db = Database::getInstance();
        $result = $db->tryConnect([
            'host' => '255.255.255.255',
            'port' => '9999',
            'name' => 'nonexistent',
            'username' => 'fake',
            'password' => 'fake',
        ]);
        $this->assertFalse($result);
    }

    public function testIsConnectedReturnsFalseInitially(): void
    {
        // After a failed connection attempt, isConnected should be false
        $db = Database::getInstance();
        $db->tryConnect([
            'host' => '255.255.255.255',
            'port' => '9999',
            'name' => 'nonexistent',
            'username' => 'fake',
            'password' => 'fake',
        ]);
        $this->assertFalse($db->isConnected());
    }

}
