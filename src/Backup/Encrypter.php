<?php

declare(strict_types=1);

namespace ShipIt\Backup;

class Encrypter
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;
    private const IV_LENGTH = 12;

    /**
     * Generates a new secure 256-bit encryption key formatted as base64.
     */
    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    /**
     * Parses the raw 32-byte binary key from string (handles base64: prefix).
     */
    public static function parseKey(string $keyString): string
    {
        $keyString = trim($keyString);
        if (str_starts_with($keyString, 'base64:')) {
            $decoded = base64_decode(substr($keyString, 7), true);
            if ($decoded === false || strlen($decoded) !== 32) {
                throw new \InvalidArgumentException("Invalid base64 encryption key. Must be a 32-byte (256-bit) key.");
            }
            return $decoded;
        }

        if (strlen($keyString) === 32) {
            return $keyString;
        }

        // If hex encoded (64 hex characters)
        if (strlen($keyString) === 64 && ctype_xdigit($keyString)) {
            return hex2bin($keyString);
        }

        throw new \InvalidArgumentException(
            "Invalid encryption key format. Must be 32 bytes or prefixed with 'base64:' containing 32 decoded bytes."
        );
    }

    /**
     * Encrypts a source file to a target encrypted file using AES-256-GCM.
     * File format: [12-byte IV][16-byte Auth Tag][Encrypted Ciphertext]
     */
    public function encryptFile(string $sourcePath, string $targetPath, string $keyString): void
    {
        if (!file_exists($sourcePath) || !is_readable($sourcePath)) {
            throw new \InvalidArgumentException("Source file to encrypt not found: {$sourcePath}");
        }

        $key = self::parseKey($keyString);
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $plaintext = file_get_contents($sourcePath);
        if ($plaintext === false) {
            throw new \RuntimeException("Failed to read source file: {$sourcePath}");
        }

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new \RuntimeException("OpenSSL encryption failed: " . openssl_error_string());
        }

        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        // Write header (IV + Tag) followed by ciphertext
        $payload = $iv . $tag . $ciphertext;
        if (file_put_contents($targetPath, $payload) === false) {
            throw new \RuntimeException("Failed to write encrypted file: {$targetPath}");
        }
    }

    /**
     * Decrypts an encrypted file to a target plaintext file.
     */
    public function decryptFile(string $sourceEncryptedPath, string $targetPath, string $keyString): void
    {
        if (!file_exists($sourceEncryptedPath) || !is_readable($sourceEncryptedPath)) {
            throw new \InvalidArgumentException("Encrypted file not found: {$sourceEncryptedPath}");
        }

        $key = self::parseKey($keyString);
        $payload = file_get_contents($sourceEncryptedPath);
        if ($payload === false) {
            throw new \RuntimeException("Failed to read encrypted file: {$sourceEncryptedPath}");
        }

        $headerLen = self::IV_LENGTH + self::TAG_LENGTH;
        if (strlen($payload) < $headerLen) {
            throw new \RuntimeException("Encrypted file payload is too short or corrupted: {$sourceEncryptedPath}");
        }

        $iv = substr($payload, 0, self::IV_LENGTH);
        $tag = substr($payload, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($payload, $headerLen);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException(
                "Decryption failed! Please verify that your SHIPIT_BACKUP_KEY matches the key used during backup encryption."
            );
        }

        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        if (file_put_contents($targetPath, $plaintext) === false) {
            throw new \RuntimeException("Failed to write decrypted file: {$targetPath}");
        }
    }
}
