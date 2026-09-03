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

namespace Tests;

use App\Controllers\SetupController;
use App\Models\Database;
use PHPUnit\Framework\TestCase;

/**
 * The first-run setup routes.
 *
 * These are the only unauthenticated, CSRF-exempt endpoints in the
 * application — by necessity, since on a fresh install there is no session
 * and no database to authenticate against. public/index.php refuses them
 * outright once an install is configured; what is covered here is what they
 * do while that gate is open, which is write the file holding the database
 * password, the AES key and the admin PIN.
 *
 * Every test points ISO20022_CONFIG_DIR at a throwaway directory, so a real
 * config/credentials.php is never read, written or overwritten. That is not a
 * precaution after the fact: SetupController wrote to a hardcoded path until
 * this suite was added, and the first test that got as far as the write would
 * have destroyed the developer's own encryption key along with it.
 *
 * Neither endpoint's success path is covered here. Both build a MySQL-shaped
 * config from the request (dropping any other driver), and saveConfig only
 * writes once a real connection is made, so reaching either needs a live
 * MySQL server that neither this machine nor CI provides. What IS covered is
 * every way they refuse — which is also every way setup could wrongly write
 * credentials it should not.
 */
class SetupControllerTest extends TestCase
{
    private string $configDir;

    protected function setUp(): void
    {
        Database::resetInstance();
        $this->configDir = sys_get_temp_dir() . '/iso20022-setup-' . bin2hex(random_bytes(6));
        mkdir($this->configDir, 0700, true);
        putenv('ISO20022_CONFIG_DIR=' . $this->configDir);
    }

    protected function tearDown(): void
    {
        putenv('ISO20022_CONFIG_DIR');
        foreach (glob($this->configDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->configDir);
        Database::resetInstance();
    }

    /** @return array{0: mixed, 1: int} */
    private function call(string $method, array $body): array
    {
        $controller = new class ($body) extends SetupController {
            public function __construct(private array $testBody)
            {
            }

            protected function getJsonInput(): array
            {
                return $this->testBody;
            }
        };

        http_response_code(200);
        ob_start();
        $controller->{$method}();

        return [json_decode((string) ob_get_clean(), true), http_response_code()];
    }


    // -----------------------------------------------------------------
    // testConnection
    // -----------------------------------------------------------------

    public function testConnectionRequiresADatabaseName(): void
    {
        [$json, $status] = $this->call('testConnection', ['host' => '127.0.0.1']);

        $this->assertSame(400, $status);
        $this->assertStringContainsString('Database name', $json['error']);
    }

    public function testConnectionReportsFailureForUnreachableCredentials(): void
    {
        [$json, $status] = $this->call('testConnection', [
            'host' => '203.0.113.1', 'port' => '9', 'name' => 'nope',
            'username' => 'nobody', 'password' => 'wrong',
        ]);

        $this->assertSame(400, $status);
        $this->assertStringContainsString('Could not connect', $json['error']);
    }


    // -----------------------------------------------------------------
    // saveConfig
    // -----------------------------------------------------------------

    public function testSaveRequiresADatabaseName(): void
    {
        [, $status] = $this->call('saveConfig', ['host' => '127.0.0.1']);

        $this->assertSame(400, $status);
        $this->assertFileDoesNotExist($this->configDir . '/credentials.php');
    }

    public function testSaveRefusesCredentialsItCannotConnectWith(): void
    {
        [$json, $status] = $this->call('saveConfig', [
            'host' => '203.0.113.1', 'port' => '9', 'name' => 'nope',
            'username' => 'nobody', 'password' => 'wrong',
        ]);

        $this->assertSame(400, $status);
        $this->assertStringContainsString('Connection failed', $json['error']);
        $this->assertFileDoesNotExist(
            $this->configDir . '/credentials.php',
            'nothing may be written for credentials that do not work'
        );
    }



}
