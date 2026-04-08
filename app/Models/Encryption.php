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
    private const CIPHER = 'aes-256-ctr';
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
     * Encrypt a plaintext string using AES-256-CTR.
     * Returns base64-encoded IV + ciphertext.
     */
    public function encrypt(string $plaintext): string
    {
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = openssl_random_pseudo_bytes($ivLength);
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );
        return base64_encode($iv . $ciphertext);
    }

    /**
     * Decrypt a base64-encoded IV + ciphertext string.
     */
    public function decrypt(string $encoded): string|false
    {
        $data = base64_decode($encoded, true);
        if ($data === false) {
            return false;
        }
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if (strlen($data) < $ivLength) {
            return false;
        }
        $iv = substr($data, 0, $ivLength);
        $ciphertext = substr($data, $ivLength);
        return openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );
    }
}
