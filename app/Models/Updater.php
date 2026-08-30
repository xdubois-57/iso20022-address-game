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

/**
 * Applies the install GitHubWebhook queued in the `update_pending` setting:
 * backup -> download -> extract -> copy over the live tree -> write
 * config/version.php, with an automatic rollback to the just-taken backup on
 * any failure from the download step onward.
 *
 * Runs against $basePath, the repository root (one level above app/ and
 * public/) — the very tree that is executing this class, which is why the
 * copy step is careful to invalidate OPcache/stat caches afterwards
 * (see dropStaleCompiledCode()) rather than assume the interpreter notices.
 *
 * Concurrency is a single non-blocking flock() on storage/update.lock: two
 * installs racing (a webhook delivery and a manual "Install now" click, or
 * two rapid webhook deliveries) must never run at once, since one could be
 * mid-copy when the other starts reading the same tree. The loser reports
 * 'in_progress' and leaves `update_pending` untouched, so whichever request
 * finds the lock free next (another visitor triggering the poor man's cron
 * in public/index.php, or the next webhook delivery) picks it up.
 *
 * `update_pending` is only cleared once run() reaches a DEFINITIVE outcome
 * (completed, or failed/rolled_back with the outcome recorded) — never
 * cleared just because an attempt started. A process that is killed
 * mid-copy (a host's request time limit, a crashed PHP-FPM worker) never
 * reaches that line, so the same target is retried by whichever request
 * finds the lock free next, rather than silently stranded.
 */
class Updater
{
    private const LOCK_RELATIVE_PATH = 'storage/update.lock';
    private const BACKUP_DIR = 'storage/backups';
    private const TEMP_DIR = 'storage/temp/update';
    private const MAX_BACKUPS = 3;
    private const DOWNLOAD_RETRY_WINDOW_SECONDS = 60;
    private const MAX_DOWNLOAD_REDIRECTS = 5;

    /**
     * Top-level entries never copied over the live tree, in either
     * direction (install or rollback restore) — regardless of whether the
     * source archive happens to contain an entry by that name. storage/ and
     * uploads/ hold live data (the settings/leaderboard SQLite fallback file
     * is not used here, but uploaded scenario files and this very lock/
     * backup/temp machinery are); the rest are development-only tooling
     * that has no business on a production install.
     */
    private const EXCLUDED_TOP_LEVEL = [
        'storage', 'uploads', '.git', '.github', 'tests', 'node_modules',
        'coverage', 'test-results', 'playwright-report', '.claude', '.idea', '.vscode',
    ];

    /** Never overwritten even though release.sh already excludes them from the artifact — defense in depth. */
    private const PROTECTED_RELATIVE_FILES = ['config/credentials.php', 'config/db_config.json'];

    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(private string $basePath, private SettingsModel $settings)
    {
    }

    /**
     * Applies whatever install is currently queued in `update_pending`.
     * Safe to call with nothing queued (returns 'noop') and safe to call
     * concurrently (the loser returns 'in_progress').
     *
     * @return array{status: string, error?: string}
     */
    public function run(): array
    {
        $pendingJson = $this->settings->get('update_pending');
        if ($pendingJson === null) {
            return ['status' => 'noop'];
        }
        $pending = json_decode($pendingJson, true);
        if (!is_array($pending) || empty($pending['download_url']) || empty($pending['version_to'])) {
            // Corrupt/unreadable — nothing sane to retry, so drop it rather
            // than loop forever on a target that can never install.
            $this->settings->delete('update_pending');
            return ['status' => 'failed', 'error' => 'Pending update record was invalid.'];
        }

        if (!$this->acquireLock()) {
            return ['status' => 'in_progress'];
        }

        try {
            return $this->install($pending);
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * @param array<string, mixed> $pending
     * @return array{status: string, error?: string}
     */
    private function install(array $pending): array
    {
        $sourceType = (string) ($pending['source_type'] ?? 'release');
        $downloadUrl = (string) $pending['download_url'];
        $versionTo = (string) $pending['version_to'];
        $commit = (string) ($pending['commit'] ?? '') ?: substr(sha1($versionTo), 0, 7);

        $tempDir = $this->basePath . '/' . self::TEMP_DIR;
        $this->removeDirectory($tempDir);
        @mkdir($tempDir, 0755, true);

        $backupPath = null;
        try {
            $backupPath = $this->createBackup();
        } catch (\Throwable $e) {
            // Nothing was touched yet — no rollback needed, just report it.
            $this->removeDirectory($tempDir);
            $this->finish('failed', 'Safety backup failed: ' . $e->getMessage());
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }

        try {
            $oldComposerLock = @file_get_contents($this->basePath . '/composer.lock') ?: '';

            $artifactPath = $tempDir . '/artifact.zip';
            $this->download($downloadUrl, $artifactPath);

            $extractedDir = $tempDir . '/extracted';
            @mkdir($extractedDir, 0755, true);
            $this->extract($artifactPath, $extractedDir);

            $sourceRoot = $this->resolveArchiveRoot($extractedDir);
            $replacedPhpFiles = [];
            $this->copyRecursive($sourceRoot, $this->basePath, '', $replacedPhpFiles);
            $this->dropStaleCompiledCode($replacedPhpFiles);
            $this->writeVersionFile($versionTo, $commit);

            $newComposerLock = @file_get_contents($this->basePath . '/composer.lock') ?: '';
            $this->settings->set('update_dependencies_changed', $newComposerLock !== $oldComposerLock ? '1' : '0');

            $this->pruneOldBackups();
            $this->finish('completed', null);
            return ['status' => 'completed', 'version' => $versionTo];
        } catch (\Throwable $e) {
            $rolledBack = false;
            if ($backupPath !== null) {
                try {
                    $this->restoreBackup($backupPath);
                    $rolledBack = true;
                } catch (\Throwable $restoreError) {
                    $this->finish('failed', 'Install failed AND automatic rollback failed: '
                        . $e->getMessage() . ' / ' . $restoreError->getMessage());
                    return ['status' => 'failed', 'error' => $e->getMessage()];
                }
            }
            $status = $rolledBack ? 'rolled_back' : 'failed';
            $this->finish($status, $e->getMessage());
            return ['status' => $status, 'error' => $e->getMessage()];
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    private function finish(string $status, ?string $error): void
    {
        $this->settings->setMany([
            'update_last_install_at' => (string) time(),
            'update_last_install_status' => $status,
            'update_last_install_error' => $error ?? '',
        ]);
        $this->settings->delete('update_pending');
    }

    // ---------------------------------------------------------------
    // Locking
    // ---------------------------------------------------------------

    private function acquireLock(): bool
    {
        $lockPath = $this->basePath . '/' . self::LOCK_RELATIVE_PATH;
        @mkdir(dirname($lockPath), 0755, true);

        $handle = @fopen($lockPath, 'c+');
        if ($handle === false) {
            // Can't even open the lock file — treat as "in progress" rather
            // than silently proceeding unguarded.
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }

        $this->lockHandle = $handle;
        return true;
    }

    private function releaseLock(): void
    {
        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    // ---------------------------------------------------------------
    // Backup / restore
    // ---------------------------------------------------------------

    /**
     * @throws \RuntimeException
     */
    private function createBackup(): string
    {
        $dir = $this->basePath . '/' . self::BACKUP_DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create backup directory.');
        }

        $path = $dir . '/backup-' . date('Ymd-His') . '.zip';
        $this->zipDirectory($this->basePath, $path);
        return $path;
    }

    /**
     * @throws \RuntimeException
     */
    private function restoreBackup(string $backupPath): void
    {
        $restoreDir = $this->basePath . '/' . self::TEMP_DIR . '/restore';
        $this->removeDirectory($restoreDir);
        @mkdir($restoreDir, 0755, true);

        $this->extract($backupPath, $restoreDir);
        $replaced = [];
        $this->copyRecursive($restoreDir, $this->basePath, '', $replaced);
        $this->dropStaleCompiledCode($replaced);
        $this->removeDirectory($restoreDir);
    }

    private function pruneOldBackups(): void
    {
        $dir = $this->basePath . '/' . self::BACKUP_DIR;
        $files = glob($dir . '/backup-*.zip') ?: [];
        sort($files);
        $excess = count($files) - self::MAX_BACKUPS;
        for ($i = 0; $i < $excess; $i++) {
            @unlink($files[$i]);
        }
    }

    /**
     * @throws \RuntimeException
     */
    private function zipDirectory(string $sourceDir, string $zipPath): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create backup archive.');
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $sourceLen = strlen($sourceDir) + 1;
        foreach ($items as $item) {
            $relative = substr((string) $item->getPathname(), $sourceLen);
            $topLevel = strtok($relative, '/');
            if ($topLevel !== false && in_array($topLevel, self::EXCLUDED_TOP_LEVEL, true)) {
                continue;
            }
            if ($item->isDir()) {
                $zip->addEmptyDir($relative);
            } else {
                $zip->addFile((string) $item->getPathname(), $relative);
            }
        }

        $zip->close();
    }

    // ---------------------------------------------------------------
    // Download
    // ---------------------------------------------------------------

    /**
     * @throws \RuntimeException
     */
    private function download(string $url, string $destPath): void
    {
        if (!GitHubUrlValidator::isAllowed($url)) {
            throw new \RuntimeException('Update source refused: must be an https GitHub URL.');
        }

        $deadline = microtime(true) + self::DOWNLOAD_RETRY_WINDOW_SECONDS;
        $lastError = 'unknown error';

        while (true) {
            [$ok, $statusCode, $lastError] = $this->attemptDownload($url, $destPath);
            if ($ok) {
                return;
            }

            $remaining = $deadline - microtime(true);
            $transient = $statusCode === null || $statusCode === 403 || $statusCode === 429 || $statusCode >= 500;
            if (!$transient || $remaining <= 0) {
                break;
            }
            usleep((int) (min(5.0, max(1.0, $remaining)) * 1_000_000));
        }

        throw new \RuntimeException("Download failed ({$lastError}).");
    }

    /**
     * Redirects are followed by hand (follow_location => 0) so every hop is
     * re-checked against the GitHub host allowlist — a legitimate GitHub
     * download redirects (api.github.com -> codeload.github.com, a release
     * asset -> objects/release-assets.githubusercontent.com), but a
     * redirect to any other host must abort rather than be followed.
     *
     * @return array{0: bool, 1: int|null, 2: string}
     */
    private function attemptDownload(string $url, string $destPath): array
    {
        $currentUrl = $url;

        for ($hop = 0; $hop <= self::MAX_DOWNLOAD_REDIRECTS; $hop++) {
            if (!GitHubUrlValidator::isAllowed($currentUrl)) {
                return [false, null, 'redirect left the GitHub allowlist'];
            }

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => "User-Agent: iso20022-address-game-updater\r\n",
                    'timeout' => 300,
                    'follow_location' => 0,
                    'ignore_errors' => true,
                ],
            ]);

            $body = @file_get_contents($currentUrl, false, $context);
            if ($body === false) {
                return [false, null, 'connection failed'];
            }

            $statusCode = $this->parseHttpStatus($http_response_header ?? []);

            if ($statusCode !== null && $statusCode >= 300 && $statusCode < 400) {
                $location = $this->parseLocationHeader($http_response_header ?? []);
                if ($location === null) {
                    return [false, $statusCode, "redirect {$statusCode} with no Location"];
                }
                $currentUrl = $location;
                continue;
            }

            if ($statusCode !== null && $statusCode >= 400) {
                return [false, $statusCode, "HTTP {$statusCode}"];
            }

            if (@file_put_contents($destPath, $body) === false || !is_file($destPath) || filesize($destPath) === 0) {
                @unlink($destPath);
                return [false, $statusCode, 'could not write downloaded file'];
            }

            return [true, $statusCode, ''];
        }

        return [false, null, 'too many redirects'];
    }

    /** @param string[] $headers */
    private function parseHttpStatus(array $headers): ?int
    {
        $status = null;
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        return $status;
    }

    /** @param string[] $headers */
    private function parseLocationHeader(array $headers): ?string
    {
        $location = null;
        foreach ($headers as $header) {
            if (preg_match('/^location:\s*(.+)$/i', trim($header), $m) === 1) {
                $location = trim($m[1]);
            }
        }
        return $location;
    }

    // ---------------------------------------------------------------
    // Extract / install
    // ---------------------------------------------------------------

    /**
     * @throws \RuntimeException
     */
    private function extract(string $zipPath, string $destDir): void
    {
        $zip = new \ZipArchive();
        $openResult = $zip->open($zipPath);
        if ($openResult !== true) {
            throw new \RuntimeException("Update archive is invalid (code {$openResult}).");
        }
        $extracted = $zip->extractTo($destDir);
        $zip->close();

        if (!$extracted) {
            throw new \RuntimeException('Could not extract the update archive.');
        }
    }

    /**
     * A GitHub-generated zipball (the branch/commit archive, or a release's
     * auto-generated source zipball when no custom asset was uploaded)
     * always wraps its contents in one top-level "{owner}-{repo}-{sha}/"
     * directory. release.sh's own artifact does not — it zips the repo
     * contents directly at the top level. Detected structurally (does
     * composer.json exist at this level?) rather than trusted to
     * source_type, since a release without a custom asset falls back to
     * exactly the wrapped shape a branch install always has.
     */
    private function resolveArchiveRoot(string $extractedDir): string
    {
        if (is_file($extractedDir . '/composer.json')) {
            return $extractedDir;
        }

        $entries = array_values(array_diff(scandir($extractedDir) ?: [], ['.', '..']));
        if (count($entries) === 1 && is_dir($extractedDir . '/' . $entries[0])) {
            return $extractedDir . '/' . $entries[0];
        }

        return $extractedDir;
    }

    /**
     * Copies $source over $dest, skipping EXCLUDED_TOP_LEVEL entries and
     * PROTECTED_RELATIVE_FILES, and recording every replaced .php path into
     * $replacedPhp so the caller can invalidate exactly those from OPcache.
     *
     * Every copy()/mkdir() is checked; the first failure throws, which is
     * what sends install() into its rollback path rather than reporting a
     * half-applied update as a success.
     *
     * @param list<string> $replacedPhp
     * @throws \RuntimeException
     */
    private function copyRecursive(string $source, string $dest, string $relative, array &$replacedPhp): void
    {
        if (is_dir($source)) {
            if (!is_dir($dest) && !@mkdir($dest, 0755, true) && !is_dir($dest)) {
                throw new \RuntimeException("Could not create directory: " . ($relative !== '' ? $relative : '.'));
            }
            foreach (scandir($source) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if ($relative === '' && in_array($entry, self::EXCLUDED_TOP_LEVEL, true)) {
                    continue;
                }
                $entryRelative = $relative === '' ? $entry : $relative . '/' . $entry;
                if (in_array($entryRelative, self::PROTECTED_RELATIVE_FILES, true)) {
                    continue;
                }
                $this->copyRecursive($source . '/' . $entry, $dest . '/' . $entry, $entryRelative, $replacedPhp);
            }
        } elseif (!@copy($source, $dest)) {
            throw new \RuntimeException("Could not write file: {$relative}");
        } elseif (str_ends_with($dest, '.php')) {
            $replacedPhp[] = $dest;
        }
    }

    /**
     * The copy above replaced the source of every changed class, but
     * OPcache re-reads a file's mtime at most once per
     * opcache.revalidate_freq seconds (60 by default). Left alone, the next
     * minute of requests would execute the PREVIOUS version of the code
     * this very install just replaced. clearstatcache() goes with it:
     * realpath_cache_ttl defaults to 120s, and this same request is about
     * to stat paths (writeVersionFile(), the composer.lock comparison) it
     * may have already stat'd before the copy.
     *
     * @param list<string> $replacedPhpFiles
     */
    private function dropStaleCompiledCode(array $replacedPhpFiles): void
    {
        clearstatcache(true);
        if (!function_exists('opcache_invalidate')) {
            return;
        }
        foreach ($replacedPhpFiles as $path) {
            @opcache_invalidate($path, true);
        }
    }

    private function writeVersionFile(string $tag, string $commit): void
    {
        $escapedTag = addslashes($tag);
        $escapedCommit = addslashes($commit);
        $contents = "<?php\n// Written by App\\Models\\Updater — do not edit manually\nreturn [\n"
            . "    'tag' => '{$escapedTag}',\n    'commit' => '{$escapedCommit}',\n];\n";
        file_put_contents($this->basePath . '/config/version.php', $contents);
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
}
