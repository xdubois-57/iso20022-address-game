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

class Encryption
{
    private const CIPHER_GCM = 'aes-256-gcm';
    private const CIPHER_CTR = 'aes-256-ctr';
    private const TAG_LENGTH = 16;
    private string $key;

    public function __construct(?string $key = null)
    {
        if ($key !== null) {
            $this->key = $key;
        } else {
            $credFile = __DIR__ . '/../../config/credentials.php';
            $creds = file_exists($credFile) ? require $credFile : [];
            $this->key = $creds['encryption']['key'] ?? '';
        }
    }

    /**
     * Encrypt a plaintext string using AES-256-GCM (authenticated encryption).
     * Returns base64-encoded: "gcm:" prefix + IV (12 bytes) + tag (16 bytes) + ciphertext.
     */
    public function encrypt(string $plaintext): string
    {
        $iv = openssl_random_pseudo_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER_GCM,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );
        return base64_encode('gcm:' . $iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a base64-encoded ciphertext string.
     * Supports both GCM (prefixed with "gcm:") and legacy CTR format.
     */
    public function decrypt(string $encoded): string|false
    {
        $data = base64_decode($encoded, true);
        if ($data === false) {
            return false;
        }

        // GCM format: "gcm:" (4 bytes) + IV (12 bytes) + tag (16 bytes) + ciphertext
        if (str_starts_with($data, 'gcm:')) {
            return $this->decryptGcm(substr($data, 4));
        }

        // Legacy CTR format: IV (16 bytes) + ciphertext
        return $this->decryptCtr($data);
    }

    private function decryptGcm(string $data): string|false
    {
        $minLength = 12 + self::TAG_LENGTH;
        if (strlen($data) < $minLength) {
            return false;
        }
        $iv = substr($data, 0, 12);
        $tag = substr($data, 12, self::TAG_LENGTH);
        $ciphertext = substr($data, $minLength);
        $result = openssl_decrypt(
            $ciphertext,
            self::CIPHER_GCM,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        return $result !== false ? $result : false;
    }

    private function decryptCtr(string $data): string|false
    {
        $ivLength = openssl_cipher_iv_length(self::CIPHER_CTR);
        if (strlen($data) < $ivLength) {
            return false;
        }
        $iv = substr($data, 0, $ivLength);
        $ciphertext = substr($data, $ivLength);
        return openssl_decrypt(
            $ciphertext,
            self::CIPHER_CTR,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );
    }
}
