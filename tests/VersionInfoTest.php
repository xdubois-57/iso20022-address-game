<?php
/**
 * Tests for the version info system: config/version.php reading and fallback behavior.
 */

namespace Tests;

use PHPUnit\Framework\TestCase;

class VersionInfoTest extends TestCase
{
    private string $versionFile;
    private ?string $originalContent = null;

    protected function setUp(): void
    {
        $this->versionFile = __DIR__ . '/../config/version.php';
        if (file_exists($this->versionFile)) {
            $this->originalContent = file_get_contents($this->versionFile);
        }
    }

    protected function tearDown(): void
    {
        // Restore original file
        if ($this->originalContent !== null) {
            file_put_contents($this->versionFile, $this->originalContent);
        } elseif (file_exists($this->versionFile)) {
            unlink($this->versionFile);
        }
    }

    public function testVersionFileExists(): void
    {
        $this->assertFileExists($this->versionFile);
    }

    public function testVersionFileReturnsArray(): void
    {
        $info = include $this->versionFile;
        $this->assertIsArray($info);
    }

    public function testVersionFileHasTagKey(): void
    {
        $info = include $this->versionFile;
        $this->assertArrayHasKey('tag', $info);
        $this->assertNotEmpty($info['tag']);
    }

    public function testVersionFileHasCommitKey(): void
    {
        $info = include $this->versionFile;
        $this->assertArrayHasKey('commit', $info);
        $this->assertNotEmpty($info['commit']);
    }

    public function testTagStartsWithV(): void
    {
        $info = include $this->versionFile;
        $this->assertMatchesRegularExpression('/^v\d+\.\d+\.\d+$/', $info['tag']);
    }

    public function testCommitIsShortHash(): void
    {
        $info = include $this->versionFile;
        // Short git hash is typically 7 hex characters
        $this->assertMatchesRegularExpression('/^[0-9a-f]{7,10}$/', $info['commit']);
    }

    public function testGetVersionInfoHelperReadsConfigFile(): void
    {
        // Define getVersionInfo locally (mirrors layout.php logic)
        $versionFile = $this->versionFile;
        $info = include $versionFile;
        if (is_array($info) && !empty($info['tag']) && !empty($info['commit'])) {
            $result = $info;
        } else {
            $result = ['tag' => 'dev', 'commit' => 'unknown'];
        }

        $this->assertNotEquals('dev', $result['tag']);
        $this->assertNotEquals('unknown', $result['commit']);
    }

    public function testGetVersionInfoFallsBackOnInvalidFile(): void
    {
        // Write invalid content
        file_put_contents($this->versionFile, "<?php\nreturn 'not an array';");

        $info = include $this->versionFile;
        $isValid = is_array($info) && !empty($info['tag']) && !empty($info['commit']);
        $this->assertFalse($isValid);
    }

    public function testGetVersionInfoFallsBackOnEmptyTag(): void
    {
        file_put_contents($this->versionFile, "<?php\nreturn ['tag' => '', 'commit' => 'abc1234'];");

        $info = include $this->versionFile;
        $isValid = is_array($info) && !empty($info['tag']) && !empty($info['commit']);
        $this->assertFalse($isValid);
    }

    public function testGetVersionInfoFallsBackOnEmptyCommit(): void
    {
        file_put_contents($this->versionFile, "<?php\nreturn ['tag' => 'v1.0.0', 'commit' => ''];");

        $info = include $this->versionFile;
        $isValid = is_array($info) && !empty($info['tag']) && !empty($info['commit']);
        $this->assertFalse($isValid);
    }

    public function testVersionInfoOutputIsHtmlSafe(): void
    {
        $info = include $this->versionFile;
        // Version info should be safe to embed in HTML (no < > & etc.)
        $tag = htmlspecialchars($info['tag'], ENT_QUOTES, 'UTF-8');
        $commit = htmlspecialchars($info['commit'], ENT_QUOTES, 'UTF-8');
        $this->assertEquals($info['tag'], $tag, 'Tag should not contain HTML-special chars');
        $this->assertEquals($info['commit'], $commit, 'Commit should not contain HTML-special chars');
    }
}
