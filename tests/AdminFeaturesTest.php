<?php
/**
 * Tests for admin features: deadline management, profanity filter, PIN hash upgrade.
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Models\Database;
use App\Controllers\AdminController;
use App\Controllers\GameController;
use App\Models\SettingsModel;
use Tests\Support\UsesInMemoryDatabase;

class AdminFeaturesTest extends TestCase
{
    use UsesInMemoryDatabase;

    private Database $db;

    protected function setUp(): void
    {
        // Runs against a fresh in-memory SQLite database — never the developer's
        // configured server. initSchema() seeds the ten default facts for us.
        $this->bootInMemoryDatabase();
        $this->db = Database::getInstance();

        $_SESSION['admin'] = true;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin']);
        $this->shutdownInMemoryDatabase();
    }

    /* =======================================================
       Deadline — Static methods (no HTTP output)
       ======================================================= */

    public function testFetchDeadlineStaticReturnsNullWhenNotSet(): void
    {
        $result = AdminController::fetchDeadlineStatic();
        $this->assertNull($result);
    }

    public function testFetchDeadlineStaticReturnsStoredValue(): void
    {
        $pdo = $this->db->getPdo();
        $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('unstructured_deadline', '2026-11-14T18:00')");

        $result = AdminController::fetchDeadlineStatic();
        $this->assertEquals('2026-11-14T18:00', $result);
    }

    public function testFetchDeadlineStaticReturnsUpdatedValue(): void
    {
        $pdo = $this->db->getPdo();
        $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('unstructured_deadline', '2026-01-01T00:00')");

        $this->assertEquals('2026-01-01T00:00', AdminController::fetchDeadlineStatic());

        // Update the value
        $pdo->exec("UPDATE settings SET setting_value = '2027-06-15T12:00' WHERE setting_key = 'unstructured_deadline'");

        $this->assertEquals('2027-06-15T12:00', AdminController::fetchDeadlineStatic());
    }

    public function testFetchDeadlineStaticReturnsNullAfterDelete(): void
    {
        $pdo = $this->db->getPdo();
        $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('unstructured_deadline', '2026-11-14T18:00')");
        $this->assertNotNull(AdminController::fetchDeadlineStatic());

        $pdo->exec("DELETE FROM settings WHERE setting_key = 'unstructured_deadline'");
        $this->assertNull(AdminController::fetchDeadlineStatic());
    }

    /* =======================================================
       Deadline — Default fallback in GameController
       ======================================================= */

    public function testGameControllerDefaultDeadline(): void
    {
        // No deadline set in DB
        $reflection = new \ReflectionClass(GameController::class);
        $constant = $reflection->getConstant('DEFAULT_DEADLINE');
        $this->assertEquals('2026-11-14T18:00', $constant);
    }

    public function testGameControllerUsesDefaultWhenNoDeadlineSet(): void
    {
        // Ensure no deadline in DB
        $deadline = AdminController::fetchDeadlineStatic() ?? '2026-11-14T18:00';
        $this->assertEquals('2026-11-14T18:00', $deadline);
    }

    public function testGameControllerUsesCustomWhenDeadlineSet(): void
    {
        $pdo = $this->db->getPdo();
        $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('unstructured_deadline', '2028-06-15T09:30')");

        $deadline = AdminController::fetchDeadlineStatic() ?? '2026-11-14T18:00';
        $this->assertEquals('2028-06-15T09:30', $deadline);
    }

    /* =======================================================
       Deadline — Validation logic
       ======================================================= */

    public function testDeadlineFormatValidation(): void
    {
        // Valid formats
        $validDates = ['2026-11-14T18:00', '2030-12-31T23:59', '2025-01-01T00:00'];
        foreach ($validDates as $date) {
            $dt = \DateTime::createFromFormat('Y-m-d\TH:i', $date);
            $this->assertNotFalse($dt, "Should accept: $date");
        }

        // Invalid formats
        $invalidDates = ['invalid-date', '2026/11/14 18:00', 'tomorrow', '14-11-2026T18:00', ''];
        foreach ($invalidDates as $date) {
            $dt = \DateTime::createFromFormat('Y-m-d\TH:i', $date);
            $this->assertFalse($dt, "Should reject: $date");
        }
    }

    /* =======================================================
       Profanity Filter — Direct logic tests
       ======================================================= */

    public function testProfanityFilterAcceptsCleanNames(): void
    {
        $censor = new \Snipe\BanBuilder\CensorWords();
        $censor->setDictionary(['en-us', 'en-uk', 'fr']);

        $cleanNames = ['Alice', 'Bob Smith', 'Dr. Johnson', 'ISO Expert', 'Marie-Claire', '田中太郎'];
        foreach ($cleanNames as $name) {
            $result = $censor->censorString($name, true);
            $this->assertEmpty($result['matched'], "Should accept clean name: $name");
        }
    }

    public function testProfanityFilterRejectsOffensiveNames(): void
    {
        $censor = new \Snipe\BanBuilder\CensorWords();
        $censor->setDictionary(['en-us', 'en-uk', 'fr']);

        // Test that at least one clearly offensive word is caught
        $result = $censor->censorString('fuck', true);
        $this->assertNotEmpty($result['matched'], "Should reject offensive word");
    }

    public function testProfanityFilterHandlesEmptyString(): void
    {
        $censor = new \Snipe\BanBuilder\CensorWords();
        $censor->setDictionary(['en-us', 'en-uk', 'fr']);

        $result = $censor->censorString('', true);
        $this->assertEmpty($result['matched']);
    }

    public function testCheckNameValidationRejectsEmptyName(): void
    {
        $name = '';
        $valid = ($name !== '' && mb_strlen($name) <= 50);
        $this->assertFalse($valid);
    }

    public function testCheckNameValidationRejectsTooLong(): void
    {
        $name = str_repeat('a', 51);
        $valid = ($name !== '' && mb_strlen($name) <= 50);
        $this->assertFalse($valid);
    }

    public function testCheckNameValidationAccepts50Chars(): void
    {
        $name = str_repeat('a', 50);
        $valid = ($name !== '' && mb_strlen($name) <= 50);
        $this->assertTrue($valid);
    }

    public function testCheckNameValidationAcceptsSingleChar(): void
    {
        $name = 'X';
        $valid = ($name !== '' && mb_strlen($name) <= 50);
        $this->assertTrue($valid);
    }

    /* =======================================================
       PIN Hash Upgrade — preg_replace_callback fix
       ======================================================= */

    public function testBcryptHashContainsDollarSigns(): void
    {
        $hash = password_hash('1234', PASSWORD_BCRYPT);
        // Bcrypt hashes always start with $2y$ and contain multiple $
        $this->assertStringStartsWith('$2y$', $hash);
        $this->assertGreaterThanOrEqual(3, substr_count($hash, '$'));
    }

    public function testPregReplaceCorruptsBcryptHash(): void
    {
        // Demonstrate the bug that was fixed: preg_replace treats $ as backreference
        $hash = '$2y$12$abcdefghijklmnopqrstuuvwxyz1234567890ABCDEFG';
        $content = "'pin' => '1234'";

        // OLD buggy code: preg_replace with $hash in replacement string
        $buggy = preg_replace(
            "/'pin'\s*=>\s*'1234'/",
            "'pin' => '" . addcslashes($hash, "'") . "'",
            $content
        );

        // The $ in the hash gets interpreted as backreference, corrupting it
        $this->assertStringNotContainsString('$2y$', $buggy, "preg_replace corrupts the hash");
    }

    public function testPregReplaceCallbackPreservesBcryptHash(): void
    {
        // Demonstrate the fix: preg_replace_callback avoids backreference issue
        $hash = password_hash('1234', PASSWORD_BCRYPT);
        $content = "'pin' => '1234'";

        $fixed = preg_replace_callback(
            "/'pin'\s*=>\s*'1234'/",
            function () use ($hash) {
                return "'pin' => '" . addcslashes($hash, "'") . "'";
            },
            $content
        );

        // The hash should be preserved intact
        $this->assertStringContainsString('$2y$', $fixed);
        // Extract the stored hash and verify it still validates
        preg_match("/'pin' => '(.+?)'/", $fixed, $m);
        $this->assertTrue(password_verify('1234', $m[1]));
    }

    public function testDefaultPinIsHashedDuringSetup(): void
    {
        // SetupController hashes '1234' as the default PIN
        $defaultPin = '1234';
        $hash = password_hash($defaultPin, PASSWORD_BCRYPT);
        $this->assertTrue(password_verify($defaultPin, $hash));
        $this->assertFalse(password_verify('0000', $hash));
    }

    /**
     * Replaces an assertion that config/credentials.php must always hold a
     * bcrypt hash. That is no longer true and is no longer meant to be: a PIN
     * written into the file by hand is deliberately accepted in clear and
     * hashed in place on first use, so demanding a hash forbade a supported
     * state. It also read the developer's OWN credentials.php and required the
     * PIN to be 1234, which made it pass, fail or skip depending on the
     * machine rather than on the code.
     *
     * What is actually worth pinning is the shape of the example file that
     * ships with the repository, since Quick Start tells people to copy it.
     * The PIN handling itself is covered properly, and hermetically, by
     * tests/AdminPinStorageTest.php.
     */
    public function testShippedExampleCredentialsFileHasTheExpectedShape(): void
    {
        $example = require __DIR__ . '/../config/credentials.php.example';

        $this->assertIsArray($example);
        $this->assertArrayHasKey('db', $example);
        $this->assertArrayHasKey('encryption', $example);
        $this->assertArrayHasKey('key', $example['encryption'], 'Encryption::__construct() reads this');
        $this->assertArrayHasKey('admin', $example);
        $this->assertArrayHasKey('pin', $example['admin'], 'AdminController::getStoredPin() reads this');

        // The example ships a placeholder key that must never be usable as-is.
        $this->assertStringContainsString('CHANGE_ME', $example['encryption']['key']);
    }

    /* =======================================================
       Settings table — general operations
       ======================================================= */

    public function testSettingsInsertAndRetrieve(): void
    {
        $settings = new SettingsModel($this->db->getPdo());
        $settings->set('test_key', 'test_value');

        $this->assertEquals('test_value', $settings->get('test_key'));
    }

    public function testSettingsUpdateOnDuplicate(): void
    {
        $settings = new SettingsModel($this->db->getPdo());

        $settings->set('test_key2', 'first');
        $settings->set('test_key2', 'second');

        $this->assertEquals('second', $settings->get('test_key2'), 'set() must overwrite, not duplicate');
    }

    public function testSettingsGetReturnsNullForMissingKey(): void
    {
        $settings = new SettingsModel($this->db->getPdo());
        $this->assertNull($settings->get('never_stored'));
    }

    public function testSettingsDeleteRemovesKey(): void
    {
        $settings = new SettingsModel($this->db->getPdo());
        $settings->set('doomed', 'value');
        $settings->delete('doomed');

        $this->assertNull($settings->get('doomed'));
    }

    public function testSettingsSetManyWritesEveryPair(): void
    {
        $settings = new SettingsModel($this->db->getPdo());
        $settings->setMany(['k1' => 'v1', 'k2' => 'v2']);

        $this->assertEquals(['k1' => 'v1', 'k2' => 'v2'], $settings->getMany(['k1', 'k2']));
    }

    /* =======================================================
       Facts — CRUD via static/direct DB
       ======================================================= */

    public function testFactsTableCreatedByInitSchema(): void
    {
        // Portable existence check: querying a missing table raises, so a
        // successful count proves initSchema created it on this driver.
        $count = $this->db->getPdo()->query('SELECT COUNT(*) FROM facts')->fetchColumn();
        $this->assertIsNumeric($count, 'facts table must exist after initSchema');
    }

    public function testFetchFactsStaticReturnsDefaultFacts(): void
    {
        // After setUp, initSchema should have created 10 default facts
        $facts = AdminController::fetchFactsStatic();
        $this->assertIsArray($facts);
        $this->assertCount(10, $facts, 'Should return 10 default facts created by initSchema');
        
        // Verify some expected content exists
        $contents = array_column($facts, 'content');
        $this->assertContains('ISO 20022 Standard Release 2026 marks the end of unstructured address support globally', $contents);
        $this->assertContains('Unstructured addresses will be phased out starting November 14, 2026', $contents);
        $this->assertContains('The new standard supports 207 address formats across all world regions', $contents);
    }

    public function testFetchFactsStaticReturnsInsertedFacts(): void
    {
        $pdo = $this->db->getPdo();
        // Clear default facts first
        $pdo->exec('DELETE FROM facts');
        $pdo->exec("INSERT INTO facts (content) VALUES ('Fact A'), ('Fact B'), ('Fact C')");

        $facts = AdminController::fetchFactsStatic();
        $this->assertCount(3, $facts);
        // Returned in DESC order
        $this->assertEquals('Fact C', $facts[0]['content']);
        $this->assertEquals('Fact A', $facts[2]['content']);
    }

    public function testFetchFactsStaticIncludesAllColumns(): void
    {
        $pdo = $this->db->getPdo();
        // Clear default facts first
        $pdo->exec('DELETE FROM facts');
        $pdo->exec("INSERT INTO facts (content) VALUES ('Test fact')");

        $facts = AdminController::fetchFactsStatic();
        $this->assertArrayHasKey('id', $facts[0]);
        $this->assertArrayHasKey('content', $facts[0]);
        $this->assertArrayHasKey('created_at', $facts[0]);
    }

    public function testFactInsertAndDelete(): void
    {
        $pdo = $this->db->getPdo();
        // Clear default facts first
        $pdo->exec('DELETE FROM facts');
        $stmt = $pdo->prepare('INSERT INTO facts (content) VALUES (?)');
        $stmt->execute(['To be deleted']);
        $id = (int) $pdo->lastInsertId();

        $this->assertGreaterThan(0, $id);
        $this->assertCount(1, AdminController::fetchFactsStatic());

        $del = $pdo->prepare('DELETE FROM facts WHERE id = ?');
        $del->execute([$id]);
        $this->assertCount(0, AdminController::fetchFactsStatic());
    }

    public function testFactUpdate(): void
    {
        $pdo = $this->db->getPdo();
        // Clear default facts first
        $pdo->exec('DELETE FROM facts');
        $pdo->exec("INSERT INTO facts (content) VALUES ('Original')");
        $id = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare('UPDATE facts SET content = ? WHERE id = ?');
        $stmt->execute(['Updated', $id]);

        $facts = AdminController::fetchFactsStatic();
        $this->assertEquals('Updated', $facts[0]['content']);
    }

    public function testFactContentSupportsHtml(): void
    {
        $pdo = $this->db->getPdo();
        // Clear default facts first
        $pdo->exec('DELETE FROM facts');
        $html = 'ISO 20022 is <a href="https://www.iso20022.org">a global standard</a>';
        $stmt = $pdo->prepare('INSERT INTO facts (content) VALUES (?)');
        $stmt->execute([$html]);

        $facts = AdminController::fetchFactsStatic();
        $this->assertStringContainsString('<a href=', $facts[0]['content']);
    }

    public function testFactContentMaxLength(): void
    {
        $content = str_repeat('x', 500);
        $valid = ($content !== '' && mb_strlen($content) <= 500);
        $this->assertTrue($valid);

        $tooLong = str_repeat('x', 501);
        $invalid = ($tooLong !== '' && mb_strlen($tooLong) <= 500);
        $this->assertFalse($invalid);
    }

    public function testFactContentRejectsEmpty(): void
    {
        $content = '';
        $valid = ($content !== '' && mb_strlen($content) <= 500);
        $this->assertFalse($valid);
    }

    public function testFactContentSupportsBoldAndItalic(): void
    {
        $pdo = $this->db->getPdo();
        $pdo->exec('DELETE FROM facts');
        $html = 'ISO 20022 is <b>very important</b> and <i>urgent</i>';
        $stmt = $pdo->prepare('INSERT INTO facts (content) VALUES (?)');
        $stmt->execute([$html]);

        $facts = AdminController::fetchFactsStatic();
        $this->assertStringContainsString('<b>', $facts[0]['content']);
        $this->assertStringContainsString('<i>', $facts[0]['content']);
    }

    public function testFactContentSupportsMixedFormatting(): void
    {
        $pdo = $this->db->getPdo();
        $pdo->exec('DELETE FROM facts');
        $html = '<b>Bold</b> and <i>italic</i> with <a href="https://example.com">a link</a>';
        $stmt = $pdo->prepare('INSERT INTO facts (content) VALUES (?)');
        $stmt->execute([$html]);

        $content = AdminController::fetchFactsStatic()[0]['content'];

        // Formatting survives; the link is additionally hardened on the way out.
        $this->assertStringContainsString('<b>Bold</b>', $content);
        $this->assertStringContainsString('<i>italic</i>', $content);
        $this->assertStringContainsString('href="https://example.com"', $content);
        $this->assertStringContainsString('rel="noopener noreferrer"', $content);
    }

    public function testStoredFactMarkupIsSanitisedOnRead(): void
    {
        // A row written before the allowlist existed must not reach the public
        // welcome screen as executable markup.
        $pdo = $this->db->getPdo();
        $pdo->exec('DELETE FROM facts');
        $stmt = $pdo->prepare('INSERT INTO facts (content) VALUES (?)');
        $stmt->execute(['Safe <script>alert(1)</script><img src=x onerror=alert(2)> text']);

        $content = AdminController::fetchFactsStatic()[0]['content'];

        $this->assertStringNotContainsString('<script', $content);
        $this->assertStringNotContainsString('onerror', $content);
        $this->assertStringContainsString('Safe', $content);
    }

    public function testFactContentWithFormattingFitsInLimit(): void
    {
        // HTML tags take up characters but the 500 limit should accommodate them
        $html = '<b>' . str_repeat('x', 100) . '</b> <i>' . str_repeat('y', 100) . '</i>';
        $valid = ($html !== '' && mb_strlen($html) <= 500);
        $this->assertTrue($valid, 'Formatted fact with 200 chars of text should fit in 500 char limit');
    }

    public function testGameControllerGetFactsPublicAccess(): void
    {
        unset($_SESSION['admin']);
        $pdo = $this->db->getPdo();
        // Clear default facts first
        $pdo->exec('DELETE FROM facts');
        $pdo->exec("INSERT INTO facts (content) VALUES ('Public fact')");

        // fetchFactsStatic works without admin session
        $facts = AdminController::fetchFactsStatic();
        $this->assertCount(1, $facts);
        $this->assertEquals('Public fact', $facts[0]['content']);
    }

    public function testSchemaVersioningCreatesFactsTable(): void
    {
        // Simulate the versioning logic from index.php
        $schemaVersion = 3;
        $session = ['schema_version' => 1]; // Old version
        $shouldRun = ($session['schema_version'] ?? 0) < $schemaVersion;
        $this->assertTrue($shouldRun, 'Schema init should run when version is lower');

        $session['schema_version'] = 3;
        $shouldNotRun = ($session['schema_version'] ?? 0) < $schemaVersion;
        $this->assertFalse($shouldNotRun, 'Schema init should NOT run when version matches');
    }

    public function testDefaultFactsCreatedOnEmptyTable(): void
    {
        // setUp already dropped and recreated the facts table with defaults
        $facts = AdminController::fetchFactsStatic();
        $this->assertCount(10, $facts, '10 default facts should be created when table is empty');
        
        // Check that facts contain expected keywords
        $contents = array_column($facts, 'content');
        $this->assertContains('ISO 20022 Standard Release 2026 marks the end of unstructured address support globally', $contents);
        $this->assertContains('Unstructured addresses will be phased out starting November 14, 2026', $contents);
        $this->assertContains('The new standard supports 207 address formats across all world regions', $contents);
    }

    public function testSchemaVersionTransitionFromBoolean(): void
    {
        // Simulate old boolean flag
        $session = ['schema_ready' => true];
        if (isset($session['schema_ready']) && !isset($session['schema_version'])) {
            unset($session['schema_ready']);
            $session['schema_version'] = 0;
        }
        $this->assertArrayNotHasKey('schema_ready', $session);
        $this->assertEquals(0, $session['schema_version']);
    }
}
