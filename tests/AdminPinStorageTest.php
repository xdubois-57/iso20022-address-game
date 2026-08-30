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

use App\Controllers\AdminController;
use App\Models\SettingsModel;
use PHPUnit\Framework\TestCase;
use Tests\Support\UsesInMemoryDatabase;

/**
 * config/credentials.php is the ONLY place the admin PIN is stored.
 *
 * Two things here are worth more than the rest: that a plaintext PIN typed
 * into the file by hand is accepted once and then hashed in place, and that
 * writing the PIN never disturbs the AES encryption key living in the same
 * file — losing that key would not break a login, it would make every stored
 * player name permanently undecryptable.
 */
class AdminPinStorageTest extends TestCase
{
    use UsesInMemoryDatabase;

    private string $configDir;
    private string $credFile;
    private string $encryptionKey;
    private AdminController $controller;

    protected function setUp(): void
    {
        $this->bootInMemoryDatabase();
        $_SESSION = [];

        $this->configDir = sys_get_temp_dir() . '/iso20022-pin-test-' . bin2hex(random_bytes(6));
        mkdir($this->configDir, 0700, true);
        $this->credFile = $this->configDir . '/credentials.php';
        $this->encryptionKey = bin2hex(random_bytes(32));
        putenv('ISO20022_CONFIG_DIR=' . $this->configDir);

        // AdminController's constructor builds a LeaderboardModel, which needs
        // the encryption key out of credentials.php and refuses to exist
        // without it. Build the controller once here, while a valid file is
        // guaranteed to be present, so individual tests are then free to
        // rewrite or delete that file — including the cases that are
        // specifically about it being missing.
        $this->writeCredentials(null);
        $this->controller = new AdminController();
    }

    protected function tearDown(): void
    {
        putenv('ISO20022_CONFIG_DIR');
        foreach (glob($this->configDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->configDir);
        $_SESSION = [];
        $this->shutdownInMemoryDatabase();
    }

    /**
     * Writes a credentials.php carrying a db block, the encryption key, and
     * $pin — the same shape SetupController produces.
     */
    private function writeCredentials(?string $pin): void
    {
        $creds = [
            'db' => ['driver' => 'sqlite', 'path' => '/tmp/unused.sqlite'],
            'encryption' => ['key' => $this->encryptionKey],
        ];
        if ($pin !== null) {
            $creds['admin'] = ['pin' => $pin];
        }
        file_put_contents($this->credFile, "<?php\nreturn " . var_export($creds, true) . ";\n");
    }

    /** @return array<string, mixed> */
    private function loadCredentials(): array
    {
        // Fresh subprocess-free read: the file is rewritten during these
        // tests, and include() would hand back an opcode-cached copy.
        return eval('?>' . file_get_contents($this->credFile));
    }

    private function invoke(string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod(AdminController::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->controller, $args);
    }

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    public function testReadsAHashedPinFromTheFile(): void
    {
        $hash = password_hash('4321', PASSWORD_BCRYPT);
        $this->writeCredentials($hash);

        $this->assertSame($hash, $this->invoke('getStoredPin'));
    }

    public function testReadsAPlaintextPinFromTheFile(): void
    {
        $this->writeCredentials('4321');

        $this->assertSame('4321', $this->invoke('getStoredPin'));
    }

    public function testFallsBackToZerosWhenNoPinIsConfigured(): void
    {
        $this->writeCredentials(null);

        $this->assertSame('0000', $this->invoke('getStoredPin'));
    }

    public function testFallsBackToZerosWhenTheFileIsAbsent(): void
    {
        unlink($this->credFile);

        $this->assertSame('0000', $this->invoke('getStoredPin'));
    }

    // -----------------------------------------------------------------
    // Plaintext is hashed on the fly
    // -----------------------------------------------------------------

    public function testPlaintextPinIsReplacedByAHashOfItself(): void
    {
        $this->writeCredentials('4321');

        $this->invoke('upgradePinToHash', ['4321']);

        $stored = $this->loadCredentials()['admin']['pin'];
        $this->assertStringStartsWith('$2y$', $stored, 'the file must no longer hold the PIN in clear');
        $this->assertTrue(password_verify('4321', $stored), 'and the hash must still match the PIN');
    }

    public function testHashingAPlaintextPinPreservesTheEncryptionKeyAndEverythingElse(): void
    {
        $this->writeCredentials('4321');

        $this->invoke('upgradePinToHash', ['4321']);

        $creds = $this->loadCredentials();
        $this->assertSame($this->encryptionKey, $creds['encryption']['key'], 'losing this key loses the leaderboard');
        $this->assertSame('sqlite', $creds['db']['driver']);
        $this->assertSame('/tmp/unused.sqlite', $creds['db']['path']);
    }

    public function testAPinContainingNoPlaintextMatchIsStillWritten(): void
    {
        // The previous implementation spliced the new hash in with a regex
        // keyed on the OLD value, so replacing a hash with another hash
        // silently did nothing. Writing the array back wholesale fixes that.
        $this->writeCredentials(password_hash('1111', PASSWORD_BCRYPT));

        $newHash = password_hash('2222', PASSWORD_BCRYPT);
        $this->assertTrue($this->invoke('writePinToCredentials', [$newHash]));
        $this->assertSame($newHash, $this->loadCredentials()['admin']['pin']);
    }

    public function testWriteAddsAnAdminBlockWhenTheFileHasNone(): void
    {
        $this->writeCredentials(null);

        $hash = password_hash('5555', PASSWORD_BCRYPT);
        $this->assertTrue($this->invoke('writePinToCredentials', [$hash]));
        $this->assertSame($hash, $this->loadCredentials()['admin']['pin']);
        $this->assertSame($this->encryptionKey, $this->loadCredentials()['encryption']['key']);
    }

    public function testWriteFailsRatherThanCreatingAFileThatDoesNotExist(): void
    {
        unlink($this->credFile);

        $this->assertFalse($this->invoke('writePinToCredentials', ['whatever']));
        $this->assertFileDoesNotExist($this->credFile);
    }

    public function testWriteLeavesNoTemporaryFilesBehind(): void
    {
        $this->writeCredentials('4321');
        $this->invoke('writePinToCredentials', [password_hash('4321', PASSWORD_BCRYPT)]);

        $strays = array_filter(glob($this->configDir . '/*') ?: [], fn ($f) => str_contains($f, '.tmp'));
        $this->assertSame([], $strays);
    }

    // -----------------------------------------------------------------
    // The database is no longer a storage location
    // -----------------------------------------------------------------

    public function testChangePinWritesTheFileAndNotTheSettingsTable(): void
    {
        $this->writeCredentials(password_hash('1111', PASSWORD_BCRYPT));
        $_SESSION['admin'] = true;

        $controller = new class extends AdminController {
            protected function getJsonInput(): array
            {
                return ['new_pin' => '8642'];
            }
        };

        http_response_code(200);
        ob_start();
        $controller->changePin();
        $json = json_decode((string) ob_get_clean(), true);

        $this->assertTrue($json['success'] ?? false);
        $this->assertTrue(password_verify('8642', $this->loadCredentials()['admin']['pin']));
        $this->assertNull(
            (new SettingsModel($this->memoryPdo()))->get('admin_pin'),
            'the PIN must not be written to the database any more'
        );
    }

    public function testALegacyDatabasePinIsMigratedIntoTheFileAndDropped(): void
    {
        // An install from before file-only storage: the settings row is the
        // real PIN, and credentials.php still carries the original one.
        $settings = new SettingsModel($this->memoryPdo());
        $currentHash = password_hash('9876', PASSWORD_BCRYPT);
        $settings->set('admin_pin', $currentHash);
        $this->writeCredentials('1234');

        $resolved = $this->invoke('getStoredPin');

        $this->assertSame($currentHash, $resolved, 'the newer database PIN must win, not the stale file one');
        $this->assertSame($currentHash, $this->loadCredentials()['admin']['pin'], 'and be migrated into the file');
        $this->assertNull($settings->get('admin_pin'), 'and the row removed so nothing shadows the file');
        $this->assertSame($this->encryptionKey, $this->loadCredentials()['encryption']['key']);
    }

    public function testMigrationStillReturnsThePinWhenTheFileCannotBeWritten(): void
    {
        $settings = new SettingsModel($this->memoryPdo());
        $hash = password_hash('9876', PASSWORD_BCRYPT);
        $settings->set('admin_pin', $hash);
        // No credentials.php at all, so the migration write cannot succeed.
        unlink($this->credFile);

        $this->assertSame($hash, $this->invoke('getStoredPin'), 'nobody may be locked out by a failed migration');
        $this->assertSame($hash, $settings->get('admin_pin'), 'and the only remaining copy must be kept');
    }
}
