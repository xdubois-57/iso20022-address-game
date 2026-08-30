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
 * The install flow end to end, against a throwaway tree.
 *
 * This is the code that unpacks a downloaded archive over the live PHP tree —
 * the most destructive thing the application can do to itself, and previously
 * reachable in tests only as far as its individual helpers. Updater::download()
 * is overridden here with a local archive so the whole sequence runs: backup,
 * extract, copy, version write, and the automatic rollback when a step fails.
 *
 * Nothing here touches the real installation: every test builds its own
 * $basePath under the system temp directory.
 */
class UpdaterInstallTest extends TestCase
{
    use UsesInMemoryDatabase;

    private string $basePath;
    private SettingsModel $settings;
    private string $artifact;

    protected function setUp(): void
    {
        $this->bootInMemoryDatabase();
        $this->settings = new SettingsModel($this->memoryPdo());

        $this->basePath = sys_get_temp_dir() . '/iso20022-install-' . bin2hex(random_bytes(8));
        mkdir($this->basePath . '/app', 0755, true);
        mkdir($this->basePath . '/config', 0755, true);
        mkdir($this->basePath . '/storage', 0755, true);
        mkdir($this->basePath . '/uploads', 0755, true);

        // The "currently installed" tree.
        file_put_contents($this->basePath . '/app/Existing.php', "<?php // version 1\n");
        file_put_contents($this->basePath . '/config/version.php', "<?php return ['tag' => 'v0.0.1', 'commit' => 'aaaaaaa'];\n");
        file_put_contents($this->basePath . '/config/credentials.php', "<?php return ['secret' => 'do not touch'];\n");
        file_put_contents($this->basePath . '/composer.lock', '{"packages":[]}');
        file_put_contents($this->basePath . '/storage/live-data.txt', 'must survive');
        file_put_contents($this->basePath . '/uploads/user-file.xlsx', 'must survive');

        $this->artifact = $this->basePath . '/../artifact-' . bin2hex(random_bytes(6)) . '.zip';
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->basePath);
        @unlink($this->artifact);
        $this->shutdownInMemoryDatabase();
    }

    private function removeTree(string $dir): void
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

    /**
     * Builds a release-shaped zip (contents at the top level, as release.sh
     * produces) from a path => contents map.
     */
    private function buildArtifact(array $files): void
    {
        $zip = new \ZipArchive();
        $zip->open($this->artifact, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($files as $path => $contents) {
            $zip->addFromString($path, $contents);
        }
        $zip->close();
    }

    private function queue(string $version = 'v1.2.3', string $commit = 'bbbbbbb'): string
    {
        $json = json_encode([
            'source_type' => 'release',
            'download_url' => 'https://github.com/owner/repo/releases/download/v1.2.3/release.zip',
            'version_to' => $version,
            'commit' => $commit,
            'queued_at' => time(),
        ]);
        $this->settings->set('update_pending', $json);
        return $json;
    }

    /** An Updater whose download step copies the local artifact instead of fetching. */
    private function updater(?callable $onDownload = null): Updater
    {
        return new class ($this->basePath, $this->settings, $this->artifact, $onDownload) extends Updater {
            public function __construct(
                string $basePath,
                SettingsModel $settings,
                private string $artifactPath,
                private $onDownload
            ) {
                parent::__construct($basePath, $settings);
            }

            protected function download(string $url, string $destPath): void
            {
                if ($this->onDownload !== null) {
                    ($this->onDownload)();
                }
                if (!@copy($this->artifactPath, $destPath)) {
                    throw new \RuntimeException('test artifact could not be staged');
                }
            }
        };
    }

    // -----------------------------------------------------------------
    // The happy path
    // -----------------------------------------------------------------

    public function testACompleteInstallReplacesCodeAndRecordsTheNewVersion(): void
    {
        $this->buildArtifact([
            'app/Existing.php' => "<?php // version 2\n",
            'app/BrandNew.php' => "<?php // added\n",
            'composer.lock' => '{"packages":[]}',
        ]);
        $this->queue();

        $result = $this->updater()->run();

        $this->assertSame('completed', $result['status']);
        $this->assertStringContainsString('version 2', file_get_contents($this->basePath . '/app/Existing.php'));
        $this->assertFileExists($this->basePath . '/app/BrandNew.php');

        $version = include $this->basePath . '/config/version.php';
        $this->assertSame('v1.2.3', $version['tag']);
        $this->assertSame('bbbbbbb', $version['commit']);

        $this->assertSame('completed', $this->settings->get('update_last_install_status'));
        $this->assertNull($this->settings->get('update_pending'), 'the installed target is cleared');
    }

    public function testLiveDataAndSecretsAreNeverOverwritten(): void
    {
        // An archive that tries to clobber all of them.
        $this->buildArtifact([
            'app/Existing.php' => "<?php // version 2\n",
            'storage/live-data.txt' => 'CLOBBERED',
            'uploads/user-file.xlsx' => 'CLOBBERED',
            'config/credentials.php' => "<?php return ['secret' => 'STOLEN'];\n",
        ]);
        $this->queue();

        $this->assertSame('completed', $this->updater()->run()['status']);

        $this->assertSame('must survive', file_get_contents($this->basePath . '/storage/live-data.txt'));
        $this->assertSame('must survive', file_get_contents($this->basePath . '/uploads/user-file.xlsx'));
        $this->assertStringContainsString(
            'do not touch',
            file_get_contents($this->basePath . '/config/credentials.php'),
            'credentials.php carries the encryption key and must never be replaced by an artifact'
        );
    }

    public function testASafetyBackupIsTakenBeforeAnythingIsOverwritten(): void
    {
        $this->buildArtifact(['app/Existing.php' => "<?php // version 2\n"]);
        $this->queue();

        $this->updater()->run();

        $backups = glob($this->basePath . '/storage/backups/backup-*.zip') ?: [];
        $this->assertCount(1, $backups);

        $zip = new \ZipArchive();
        $zip->open($backups[0]);
        $this->assertNotFalse($zip->locateName('app/Existing.php'), 'the pre-install code is in the backup');
        $zip->close();
    }

    public function testAChangedComposerLockIsFlaggedForTheAdmin(): void
    {
        $this->buildArtifact([
            'app/Existing.php' => "<?php // v2\n",
            'composer.lock' => '{"packages":[{"name":"something/new"}]}',
        ]);
        $this->queue();

        $this->updater()->run();

        $this->assertSame('1', $this->settings->get('update_dependencies_changed'));
    }

    public function testAnUnchangedComposerLockIsNotFlagged(): void
    {
        $this->buildArtifact([
            'app/Existing.php' => "<?php // v2\n",
            'composer.lock' => '{"packages":[]}',
        ]);
        $this->queue();

        $this->updater()->run();

        $this->assertSame('0', $this->settings->get('update_dependencies_changed'));
    }

    public function testAGitHubZipballWrapperDirectoryIsStripped(): void
    {
        // GitHub's own zipball wraps everything in {owner}-{repo}-{sha}/.
        $this->buildArtifact([
            'owner-repo-abc1234/app/Existing.php' => "<?php // from zipball\n",
            'owner-repo-abc1234/composer.lock' => '{"packages":[]}',
        ]);
        $this->queue();

        $this->assertSame('completed', $this->updater()->run()['status']);
        $this->assertStringContainsString('from zipball', file_get_contents($this->basePath . '/app/Existing.php'));
        $this->assertDirectoryDoesNotExist($this->basePath . '/owner-repo-abc1234');
    }

    // -----------------------------------------------------------------
    // Failure and rollback
    // -----------------------------------------------------------------

    public function testAFailedDownloadRollsBackAndLeavesTheTreeUntouched(): void
    {
        $this->buildArtifact(['app/Existing.php' => "<?php // never applied\n"]);
        $this->queue();

        $updater = $this->updater(function (): void {
            throw new \RuntimeException('network went away');
        });
        $result = $updater->run();

        $this->assertSame('rolled_back', $result['status']);
        $this->assertStringContainsString('version 1', file_get_contents($this->basePath . '/app/Existing.php'));

        $version = include $this->basePath . '/config/version.php';
        $this->assertSame('v0.0.1', $version['tag'], 'the version must not advance on a failed install');
        $this->assertSame('rolled_back', $this->settings->get('update_last_install_status'));
        $this->assertNotEmpty($this->settings->get('update_last_install_error'));
    }

    public function testACorruptArchiveIsRejectedAndRolledBack(): void
    {
        file_put_contents($this->artifact, 'this is not a zip file');
        $this->queue();

        $result = $this->updater()->run();

        $this->assertSame('rolled_back', $result['status']);
        $this->assertStringContainsString('version 1', file_get_contents($this->basePath . '/app/Existing.php'));
    }

    public function testOnlyTheMostRecentBackupsAreKept(): void
    {
        $this->buildArtifact(['app/Existing.php' => "<?php // v2\n", 'composer.lock' => '{"packages":[]}']);

        // MAX_BACKUPS is 3; five installs must not leave five archives behind
        // on a host where disk is the scarce resource.
        for ($i = 0; $i < 5; $i++) {
            $this->queue('v1.2.' . $i, 'commit' . $i);
            $this->updater()->run();
            sleep(1); // backup names carry a per-second timestamp
        }

        $backups = glob($this->basePath . '/storage/backups/backup-*.zip') ?: [];
        $this->assertLessThanOrEqual(3, count($backups));
    }
}
