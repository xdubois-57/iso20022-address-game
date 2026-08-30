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

use App\Models\SettingsModel;
use App\Models\Updater;
use PHPUnit\Framework\TestCase;
use Tests\Support\UsesInMemoryDatabase;

/**
 * Updater's download()/extract() paths need a real GitHub URL to exercise
 * end to end, so this suite covers everything reachable without the
 * network: the lock/noop/corrupt-pending branches of run(), and the pure
 * file-tree logic (archive root resolution, the copy exclusion rules, the
 * backup/restore round trip) via reflection on a throwaway temp directory —
 * the same approach AdminFeaturesTest.php and SecurityTest.php already use
 * for private methods.
 */
class UpdaterTest extends TestCase
{
    use UsesInMemoryDatabase;

    private SettingsModel $settings;
    private string $basePath;

    protected function setUp(): void
    {
        $this->bootInMemoryDatabase();
        $this->settings = new SettingsModel($this->memoryPdo());
        $this->basePath = sys_get_temp_dir() . '/iso20022-updater-test-' . bin2hex(random_bytes(8));
        mkdir($this->basePath, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);
        $this->shutdownInMemoryDatabase();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir((string) $item) : @unlink((string) $item);
        }
        @rmdir($dir);
    }

    private function invokePrivate(Updater $updater, string $method, array $args = [])
    {
        $ref = new \ReflectionMethod(Updater::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($updater, $args);
    }

    // -----------------------------------------------------------------
    // run() — noop / corrupt pending / locking
    // -----------------------------------------------------------------

    public function testRunReturnsNoopWithNothingPending(): void
    {
        $updater = new Updater($this->basePath, $this->settings);
        $this->assertSame(['status' => 'noop'], $updater->run());
    }

    public function testRunDropsAndReportsCorruptPending(): void
    {
        $this->settings->set('update_pending', 'not valid json');
        $updater = new Updater($this->basePath, $this->settings);

        $result = $updater->run();

        $this->assertSame('failed', $result['status']);
        $this->assertNull($this->settings->get('update_pending'));
    }

    public function testRunReturnsInProgressWhenLockIsHeld(): void
    {
        $this->settings->set('update_pending', json_encode([
            'source_type' => 'branch',
            'download_url' => 'https://api.github.com/repos/x/y/zipball/main',
            'version_to' => 'dev-abc1234',
            'commit' => 'abc1234',
            'queued_at' => time(),
        ]));

        @mkdir($this->basePath . '/storage', 0755, true);
        $lockPath = $this->basePath . '/storage/update.lock';
        $handle = fopen($lockPath, 'c+');
        flock($handle, LOCK_EX);

        try {
            $updater = new Updater($this->basePath, $this->settings);
            $result = $updater->run();

            $this->assertSame(['status' => 'in_progress'], $result);
            // The lock's holder, not this run(), owns clearing the target —
            // it must survive so whoever holds the lock next retries it.
            $this->assertNotNull($this->settings->get('update_pending'));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    // -----------------------------------------------------------------
    // resolveArchiveRoot
    // -----------------------------------------------------------------

    public function testResolveArchiveRootReturnsDirWhenComposerJsonAtTopLevel(): void
    {
        $extracted = $this->basePath . '/extracted';
        mkdir($extracted, 0755, true);
        touch($extracted . '/composer.json');

        $updater = new Updater($this->basePath, $this->settings);
        $root = $this->invokePrivate($updater, 'resolveArchiveRoot', [$extracted]);

        $this->assertSame($extracted, $root);
    }

    public function testResolveArchiveRootDescendsIntoSingleWrappingDirectory(): void
    {
        $extracted = $this->basePath . '/extracted';
        $wrapped = $extracted . '/owner-repo-abc1234';
        mkdir($wrapped, 0755, true);
        touch($wrapped . '/composer.json');

        $updater = new Updater($this->basePath, $this->settings);
        $root = $this->invokePrivate($updater, 'resolveArchiveRoot', [$extracted]);

        $this->assertSame($wrapped, $root);
    }

    public function testResolveArchiveRootFallsBackToExtractedDirWhenAmbiguous(): void
    {
        $extracted = $this->basePath . '/extracted';
        mkdir($extracted . '/one', 0755, true);
        mkdir($extracted . '/two', 0755, true);

        $updater = new Updater($this->basePath, $this->settings);
        $root = $this->invokePrivate($updater, 'resolveArchiveRoot', [$extracted]);

        $this->assertSame($extracted, $root);
    }

    // -----------------------------------------------------------------
    // copyRecursive — exclusion rules
    // -----------------------------------------------------------------

    public function testCopyRecursiveSkipsExcludedTopLevelAndProtectedFiles(): void
    {
        $source = $this->basePath . '/source';
        mkdir($source . '/app', 0755, true);
        file_put_contents($source . '/app/Foo.php', '<?php // foo');
        mkdir($source . '/storage', 0755, true);
        file_put_contents($source . '/storage/secret.txt', 'must not be copied');
        mkdir($source . '/config', 0755, true);
        file_put_contents($source . '/config/credentials.php', '<?php return ["should" => "not overwrite"];');
        file_put_contents($source . '/config/version.php', '<?php return ["tag" => "v9.9.9"];');

        $dest = $this->basePath . '/dest';
        mkdir($dest, 0755, true);
        mkdir($dest . '/config', 0755, true);
        file_put_contents($dest . '/config/credentials.php', '<?php return ["real" => "credentials"];');

        $updater = new Updater($this->basePath, $this->settings);
        $replaced = [];
        $this->invokePrivate($updater, 'copyRecursive', [$source, $dest, '', &$replaced]);

        $this->assertFileExists($dest . '/app/Foo.php');
        $this->assertFileDoesNotExist($dest . '/storage/secret.txt', 'storage/ must never be copied over');
        $this->assertStringContainsString('real', (string) file_get_contents($dest . '/config/credentials.php'));
        $this->assertFileExists($dest . '/config/version.php');
        $this->assertContains($dest . '/app/Foo.php', $replaced);
        $this->assertContains($dest . '/config/version.php', $replaced);
    }

    public function testCopyRecursiveThrowsOnUnwritableTarget(): void
    {
        $source = $this->basePath . '/source';
        mkdir($source, 0755, true);
        file_put_contents($source . '/file.txt', 'content');

        $dest = $this->basePath . '/dest';
        mkdir($dest, 0755, true);
        // Make the destination directory read-only so the file write inside it fails.
        chmod($dest, 0555);

        $updater = new Updater($this->basePath, $this->settings);
        $replaced = [];

        try {
            $this->expectException(\RuntimeException::class);
            $this->invokePrivate($updater, 'copyRecursive', [$source, $dest, '', &$replaced]);
        } finally {
            chmod($dest, 0755);
        }
    }

    // -----------------------------------------------------------------
    // Backup / restore round trip
    // -----------------------------------------------------------------

    public function testBackupAndRestoreRoundTrip(): void
    {
        mkdir($this->basePath . '/app', 0755, true);
        file_put_contents($this->basePath . '/app/Original.php', '<?php // v1');
        mkdir($this->basePath . '/storage', 0755, true);
        file_put_contents($this->basePath . '/storage/live-data.txt', 'must survive untouched');

        $updater = new Updater($this->basePath, $this->settings);
        $backupPath = $this->invokePrivate($updater, 'createBackup');

        $this->assertFileExists($backupPath);

        // Simulate a bad install landing on top of the original tree.
        file_put_contents($this->basePath . '/app/Original.php', '<?php // BROKEN');
        file_put_contents($this->basePath . '/app/NewFileFromBadInstall.php', '<?php // should be removed? (not by restore)');

        $this->invokePrivate($updater, 'restoreBackup', [$backupPath]);

        $this->assertStringContainsString('v1', (string) file_get_contents($this->basePath . '/app/Original.php'));
        $this->assertSame('must survive untouched', file_get_contents($this->basePath . '/storage/live-data.txt'));
    }

    public function testBackupExcludesStorageDirectory(): void
    {
        mkdir($this->basePath . '/storage', 0755, true);
        file_put_contents($this->basePath . '/storage/should-not-be-backed-up.txt', 'x');
        mkdir($this->basePath . '/app', 0755, true);
        file_put_contents($this->basePath . '/app/Kept.php', '<?php');

        $updater = new Updater($this->basePath, $this->settings);
        $backupPath = $this->invokePrivate($updater, 'createBackup');

        $zip = new \ZipArchive();
        $zip->open($backupPath);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        $this->assertContains('app/Kept.php', $names);
        foreach ($names as $name) {
            $this->assertStringStartsNotWith('storage/', $name);
        }
    }

    // -----------------------------------------------------------------
    // download() — fast local rejection, no network
    // -----------------------------------------------------------------

    public function testDownloadRefusesNonGitHubUrlWithoutTouchingNetwork(): void
    {
        $updater = new Updater($this->basePath, $this->settings);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be an https GitHub URL');
        $this->invokePrivate($updater, 'download', ['https://attacker.example/evil.zip', $this->basePath . '/artifact.zip']);
    }
}
