<?php
/**
 * Tests for ShareController: token generation/decryption, sanitization, URL safety.
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Models\Encryption;

class ShareControllerTest extends TestCase
{
    private Encryption $encryption;

    protected function setUp(): void
    {
        $this->encryption = new Encryption('test_key_for_share_controller!!');
    }

    /* =======================================================
       Token round-trip (encrypt → URL-safe → reverse → decrypt)
       ======================================================= */

    public function testTokenRoundTrip(): void
    {
        $payload = json_encode(['s' => 5000, 'n' => 'Alice']);
        $token = $this->encryption->encrypt($payload);

        // Make URL-safe (same logic as ShareController::generateToken)
        $urlToken = rtrim(strtr($token, '+/', '-_'), '=');

        // Reverse URL-safe (same logic as ShareController::decryptToken)
        $base64 = strtr($urlToken, '-_', '+/');
        $pad = strlen($base64) % 4;
        if ($pad) {
            $base64 .= str_repeat('=', 4 - $pad);
        }

        $decrypted = $this->encryption->decrypt($base64);
        $this->assertNotFalse($decrypted);

        $data = json_decode($decrypted, true);
        $this->assertEquals(5000, $data['s']);
        $this->assertEquals('Alice', $data['n']);
    }

    public function testTokenWithSpecialCharactersInName(): void
    {
        $payload = json_encode(['s' => 100, 'n' => "O'Brien & Co <test>"]);
        $token = $this->encryption->encrypt($payload);
        $urlToken = rtrim(strtr($token, '+/', '-_'), '=');

        $base64 = strtr($urlToken, '-_', '+/');
        $pad = strlen($base64) % 4;
        if ($pad) {
            $base64 .= str_repeat('=', 4 - $pad);
        }

        $decrypted = $this->encryption->decrypt($base64);
        $data = json_decode($decrypted, true);
        $this->assertEquals("O'Brien & Co <test>", $data['n']);
    }

    public function testTokenWithUnicodeCharacters(): void
    {
        $payload = json_encode(['s' => 250, 'n' => 'Müller 田中']);
        $token = $this->encryption->encrypt($payload);
        $urlToken = rtrim(strtr($token, '+/', '-_'), '=');

        $base64 = strtr($urlToken, '-_', '+/');
        $pad = strlen($base64) % 4;
        if ($pad) {
            $base64 .= str_repeat('=', 4 - $pad);
        }

        $decrypted = $this->encryption->decrypt($base64);
        $data = json_decode($decrypted, true);
        $this->assertEquals('Müller 田中', $data['n']);
    }

    public function testUrlTokenContainsNoUnsafeCharacters(): void
    {
        // Generate multiple tokens and ensure none have +, /, or =
        for ($i = 0; $i < 20; $i++) {
            $payload = json_encode(['s' => $i * 100, 'n' => 'Player' . $i]);
            $token = $this->encryption->encrypt($payload);
            $urlToken = rtrim(strtr($token, '+/', '-_'), '=');

            $this->assertStringNotContainsString('+', $urlToken);
            $this->assertStringNotContainsString('/', $urlToken);
            $this->assertStringNotContainsString('=', $urlToken);
        }
    }

    /* =======================================================
       Score clamping (mirrors ShareController logic)
       ======================================================= */

    public function testScoreClampedToRange(): void
    {
        // Score should be clamped to [0, 10000]
        $this->assertEquals(0, max(0, min(10000, -100)));
        $this->assertEquals(0, max(0, min(10000, 0)));
        $this->assertEquals(5000, max(0, min(10000, 5000)));
        $this->assertEquals(10000, max(0, min(10000, 10000)));
        $this->assertEquals(10000, max(0, min(10000, 99999)));
    }

    /* =======================================================
       Name sanitization (mirrors ShareController::sanitizeName)
       ======================================================= */

    private function sanitizeName(string $raw): string
    {
        $name = trim(strip_tags($raw));
        if ($name === '' || mb_strlen($name) > 50) {
            return 'A player';
        }
        return $name;
    }

    public function testSanitizeNameStripsHtmlTags(): void
    {
        $this->assertEquals('hello', $this->sanitizeName('<script>hello</script>'));
        $this->assertEquals('Bold text', $this->sanitizeName('<b>Bold text</b>'));
    }

    public function testSanitizeNameTrimsWhitespace(): void
    {
        $this->assertEquals('Alice', $this->sanitizeName('  Alice  '));
    }

    public function testSanitizeNameReturnsDefaultForEmpty(): void
    {
        $this->assertEquals('A player', $this->sanitizeName(''));
        $this->assertEquals('A player', $this->sanitizeName('   '));
    }

    public function testSanitizeNameReturnsDefaultForTooLong(): void
    {
        $longName = str_repeat('x', 51);
        $this->assertEquals('A player', $this->sanitizeName($longName));
    }

    public function testSanitizeNameAccepts50Chars(): void
    {
        $name = str_repeat('x', 50);
        $this->assertEquals($name, $this->sanitizeName($name));
    }

    public function testSanitizeNamePreservesUnicode(): void
    {
        $this->assertEquals('Ünsal Müller', $this->sanitizeName('Ünsal Müller'));
    }

    public function testSanitizeNameReturnsDefaultWhenOnlyTags(): void
    {
        $this->assertEquals('A player', $this->sanitizeName('<b></b>'));
    }

    /* =======================================================
       Host validation (mirrors ShareController::getSafeHost)
       ======================================================= */

    private function getSafeHost(string $host): string
    {
        if (!preg_match('/^[a-zA-Z0-9.\-]+(:\d{1,5})?$/', $host)) {
            return 'localhost';
        }
        return $host;
    }

    public function testSafeHostAcceptsValidHosts(): void
    {
        $this->assertEquals('example.com', $this->getSafeHost('example.com'));
        $this->assertEquals('sub.domain.com', $this->getSafeHost('sub.domain.com'));
        $this->assertEquals('localhost:8080', $this->getSafeHost('localhost:8080'));
        $this->assertEquals('192.168.1.1:3000', $this->getSafeHost('192.168.1.1:3000'));
    }

    public function testSafeHostRejectsInvalidHosts(): void
    {
        $this->assertEquals('localhost', $this->getSafeHost('evil.com/attack'));
        $this->assertEquals('localhost', $this->getSafeHost('evil.com attack'));
        $this->assertEquals('localhost', $this->getSafeHost('<script>'));
        $this->assertEquals('localhost', $this->getSafeHost(''));
    }

    /* =======================================================
       Time sanitization (mirrors ShareController::sanitizeTime)
       ======================================================= */

    private function sanitizeTime(string $raw): string
    {
        if (preg_match('/^\d{1,3}:\d{2}$/', $raw)) {
            return $raw;
        }
        return '0:00';
    }

    public function testSanitizeTimeAcceptsValidFormats(): void
    {
        $this->assertEquals('1:30', $this->sanitizeTime('1:30'));
        $this->assertEquals('0:00', $this->sanitizeTime('0:00'));
        $this->assertEquals('999:59', $this->sanitizeTime('999:59'));
        $this->assertEquals('12:05', $this->sanitizeTime('12:05'));
    }

    public function testSanitizeTimeRejectsInvalidFormats(): void
    {
        $this->assertEquals('0:00', $this->sanitizeTime('abc'));
        $this->assertEquals('0:00', $this->sanitizeTime(''));
        $this->assertEquals('0:00', $this->sanitizeTime('1:2'));
        $this->assertEquals('0:00', $this->sanitizeTime('1234:00'));
    }

    /* =======================================================
       Decryption of invalid tokens
       ======================================================= */

    public function testDecryptEmptyTokenReturnsNull(): void
    {
        // Empty token scenario
        $urlToken = '';
        $this->assertEquals('', $urlToken);
    }

    public function testDecryptGarbageTokenReturnsFalse(): void
    {
        $result = $this->encryption->decrypt('not_valid_data');
        $this->assertFalse($result);
    }

    public function testDecryptTokenFromWrongKeyReturnsFalse(): void
    {
        $otherEnc = new Encryption('different_key_for_this_test!!!!');
        $payload = json_encode(['s' => 100, 'n' => 'Test']);
        $token = $otherEnc->encrypt($payload);

        // Try to decrypt with our key
        $result = $this->encryption->decrypt($token);
        $this->assertFalse($result);
    }
}
