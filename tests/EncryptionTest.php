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
use App\Models\Encryption;

class EncryptionTest extends TestCase
{
    private Encryption $encryption;

    protected function setUp(): void
    {
        $this->encryption = new Encryption('test_key_for_unit_testing_32bytes!');
    }

    public function testEncryptReturnsNonEmptyString(): void
    {
        $result = $this->encryption->encrypt('Hello World');
        $this->assertNotEmpty($result);
        $this->assertNotEquals('Hello World', $result);
    }

    public function testDecryptReturnsOriginalText(): void
    {
        $plaintext = 'John Doe';
        $encrypted = $this->encryption->encrypt($plaintext);
        $decrypted = $this->encryption->decrypt($encrypted);
        $this->assertEquals($plaintext, $decrypted);
    }

    public function testEncryptProducesDifferentCiphertexts(): void
    {
        $plaintext = 'Same Input';
        $encrypted1 = $this->encryption->encrypt($plaintext);
        $encrypted2 = $this->encryption->encrypt($plaintext);
        // Due to random IV, each encryption should produce a different result
        $this->assertNotEquals($encrypted1, $encrypted2);
    }

    public function testDecryptWithInvalidDataReturnsFalse(): void
    {
        $result = $this->encryption->decrypt('not-valid-base64!!!');
        $this->assertFalse($result);
    }

    public function testDecryptWithTooShortDataReturnsFalse(): void
    {
        $result = $this->encryption->decrypt(base64_encode('short'));
        $this->assertFalse($result);
    }

    public function testEncryptDecryptEmptyString(): void
    {
        $encrypted = $this->encryption->encrypt('');
        $decrypted = $this->encryption->decrypt($encrypted);
        $this->assertEquals('', $decrypted);
    }

    public function testEncryptDecryptUnicodeCharacters(): void
    {
        $plaintext = 'Müller Hauptstraße 45 日本語';
        $encrypted = $this->encryption->encrypt($plaintext);
        $decrypted = $this->encryption->decrypt($encrypted);
        $this->assertEquals($plaintext, $decrypted);
    }

    public function testDecryptWithWrongKeyFails(): void
    {
        $encrypted = $this->encryption->encrypt('Secret Data');
        $otherEncryption = new Encryption('different_key_that_is_32_bytes!!');
        $result = $otherEncryption->decrypt($encrypted);
        // GCM: wrong key should return false (authentication failure)
        $this->assertFalse($result);
    }

    public function testGcmEncryptionProducesGcmPrefix(): void
    {
        $encrypted = $this->encryption->encrypt('test');
        $decoded = base64_decode($encrypted, true);
        // GCM format starts with 'gcm:' prefix
        $this->assertStringStartsWith('gcm:', $decoded);
    }

    public function testGcmTamperDetection(): void
    {
        $encrypted = $this->encryption->encrypt('Sensitive Data');
        $decoded = base64_decode($encrypted, true);
        // Flip a bit in the ciphertext (after the gcm: prefix + IV + tag)
        $tampered = $decoded;
        $pos = strlen($tampered) - 1;
        $tampered[$pos] = chr(ord($tampered[$pos]) ^ 0x01);
        $result = $this->encryption->decrypt(base64_encode($tampered));
        // GCM should detect tampering and return false
        $this->assertFalse($result);
    }

    public function testLegacyCtrDecryptionStillWorks(): void
    {
        // Simulate a legacy CTR-encrypted value (no 'gcm:' prefix)
        $key = 'test_key_for_unit_testing_32bytes!';
        $iv = openssl_random_pseudo_bytes(16);
        $ciphertext = openssl_encrypt(
            'Legacy Name',
            'aes-256-ctr',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );
        $legacyEncoded = base64_encode($iv . $ciphertext);

        $result = $this->encryption->decrypt($legacyEncoded);
        $this->assertEquals('Legacy Name', $result);
    }

    public function testDecryptWithEmptyStringReturnsFalse(): void
    {
        $result = $this->encryption->decrypt('');
        $this->assertFalse($result);
    }
}
