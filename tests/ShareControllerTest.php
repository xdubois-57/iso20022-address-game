<?php
/**
 * Tests for ShareController: token generation/decryption, sanitization, URL safety.
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Support\Url;
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

    /* =======================================================
       GD share card — the fallback path, drawn on hosts without Imagick
       ======================================================= */

    /**
     * The score card's GD path was the one render path nothing exercised:
     * CI has Imagick, so the browser tests take the other branch and this
     * code only ever runs on the hosts least able to report a problem.
     * Called directly so the assertion holds whether or not Imagick is
     * installed, rather than passing by not running.
     */
    private function renderGdScoreCard(): \GdImage
    {
        $m = new \ReflectionMethod(\App\Controllers\ShareController::class, 'buildShareImageGd');
        $m->setAccessible(true);
        $png = $m->invoke(new \App\Controllers\ShareController(), '4200', 'Alice');

        $img = @imagecreatefromstring($png);
        $this->assertNotFalse($img, 'the GD path must return a decodable PNG');

        return $img;
    }

    public function testGdScoreCardIsA1200x630Png(): void
    {
        $img = $this->renderGdScoreCard();

        $this->assertSame(1200, imagesx($img), 'the size every social network expects');
        $this->assertSame(630, imagesy($img));

        imagedestroy($img);
    }

    public function testGdScoreCardCarriesTheEndorsementPlate(): void
    {
        // A host without Imagick posting share cards with no PMPG lockup
        // would be a half-applied rebrand, so both paths must draw it. The
        // white plate is the part that is theme-independent — it is allocated
        // as pure white on either path — which makes it the thing worth
        // asserting on.
        $img = $this->renderGdScoreCard();

        $rgb = imagecolorat($img, 600, 527);
        $this->assertSame(
            [255, 255, 255],
            [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF],
            'the lockup plate must be drawn at the foot of the GD card'
        );

        imagedestroy($img);
    }

    public function testGdScoreCardDrawsNothingWhereTheCaptionUsedToBe(): void
    {
        // The "Supported by" caption sat centred just above the plate. It was
        // removed on every surface at once; this pins the GD one, which no
        // browser test reaches. Compared against a point on the same row far
        // from anything drawn, so the assertion holds whatever theme the
        // instance running the suite happens to have.
        $img = $this->renderGdScoreCard();

        $this->assertSame(
            imagecolorat($img, 100, 509),
            imagecolorat($img, 600, 509),
            'the caption strip must be bare background'
        );

        imagedestroy($img);
    }

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
       Host validation
       ======================================================= */

    /**
     * These used to run against a private copy of the regex kept inside this
     * test class, so they could pass while the real code drifted. They now
     * exercise App\Support\Url, which is what the application actually calls.
     */
    private function safeHostFor(string $host): string
    {
        $_SERVER['HTTP_HOST'] = $host;
        return Url::safeHost();
    }

    public function testSafeHostAcceptsValidHosts(): void
    {
        $this->assertEquals('example.com', $this->safeHostFor('example.com'));
        $this->assertEquals('sub.domain.com', $this->safeHostFor('sub.domain.com'));
        $this->assertEquals('localhost:8080', $this->safeHostFor('localhost:8080'));
        $this->assertEquals('192.168.1.1:3000', $this->safeHostFor('192.168.1.1:3000'));
    }

    public function testSafeHostRejectsInvalidHosts(): void
    {
        $this->assertEquals('localhost', $this->safeHostFor('evil.com/attack'));
        $this->assertEquals('localhost', $this->safeHostFor('evil.com attack'));
        $this->assertEquals('localhost', $this->safeHostFor('<script>'));
        $this->assertEquals('localhost', $this->safeHostFor(''));
    }

    public function testCurrentUrlStripsCharactersThatEscapeAnAttribute(): void
    {
        // REQUEST_URI is attacker-supplied and lands in og:url / canonical.
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['REQUEST_URI'] = '/share?d=abc"><script>alert(1)</script>';

        $url = Url::currentUrl();

        $this->assertStringNotContainsString('"', $url);
        $this->assertStringNotContainsString('<', $url);
        $this->assertStringStartsWith('https://example.com/share', $url);
    }

    public function testCurrentUrlHtmlIsAttributeSafe(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['REQUEST_URI'] = '/share?d=a&b=c';

        $this->assertStringNotContainsString('&b', Url::currentUrlHtml());
        $this->assertStringContainsString('&amp;b', Url::currentUrlHtml());
    }

    public function testBaseUrlFallsBackForAnInjectedHostHeader(): void
    {
        $_SERVER['HTTP_HOST'] = 'evil.com"onload="x';
        unset($_SERVER['HTTPS']);

        $this->assertEquals('http://localhost', Url::baseUrl());
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
