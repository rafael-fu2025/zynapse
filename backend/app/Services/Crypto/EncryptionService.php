<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use RuntimeException;

/**
 * EncryptionService — AES-256-GCM field-level encryption.
 *
 * Used by the Counselling module for sensitive notes. NEVER used as
 * the primary security boundary for at-rest DB files. The DB itself
 * uses TDE (configured at the storage layer).
 *
 * Storage format (Phase 6):
 *   `ciphertext` column = raw ciphertext || 16-byte GCM auth tag.
 *   The tag MUST travel with the ciphertext — GCM decryption without
 *   the tag always fails. Phase ≤5 envelopes discarded the tag and are
 *   therefore undecryptable; no such rows can contain recoverable data.
 *
 * Key management:
 *   - `COUNSELLING_KEY` env provides the active 32-byte (64 hex) key.
 *   - `COUNSELLING_KEY_VERSION` selects the active version.
 *   - Historical versions resolve through `counselling_key_versions`
 *     (version -> env key_ref). Key MATERIAL never lives in the DB —
 *     the table stores only the name of the env var holding the key.
 */
class EncryptionService
{
    public const CIPHER      = 'aes-256-gcm';
    public const NONCE_BYTES = 12;
    public const TAG_BYTES   = 16;

    /** @var array<int, string> Per-request cache of version -> key_ref. */
    private array $keyRefCache = [];

    /**
     * Encrypt plaintext. Returns an envelope array suitable for storage
     * in three separate columns: `ciphertext`, `nonce`, `key_version`.
     * `ciphertext` already carries the GCM tag as its final 16 bytes.
     *
     * @return array{ciphertext:string,nonce:string,key_version:int}
     */
    public function encryptField(string $plaintext): array
    {
        $version = $this->activeVersion();
        $key     = $this->keyFor($version);
        $nonce   = random_bytes(self::NONCE_BYTES);
        $tag     = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '', // No additional authenticated data (AAD) for v1.
            self::TAG_BYTES
        );

        if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
            throw new RuntimeException('Encryption failed.');
        }

        return [
            'ciphertext'  => $ciphertext . $tag,
            'nonce'       => $nonce,
            'key_version' => $version,
        ];
    }

    /**
     * Decrypt a previously-stored envelope. `$ciphertext` is the stored
     * column value: raw ciphertext with the GCM tag appended.
     */
    public function decryptField(string $ciphertext, string $nonce, int $keyVersion): string
    {
        if (strlen($ciphertext) <= self::TAG_BYTES) {
            throw new RuntimeException('Ciphertext too short; missing GCM tag.');
        }

        $tag  = substr($ciphertext, -self::TAG_BYTES);
        $body = substr($ciphertext, 0, -self::TAG_BYTES);
        $key  = $this->keyFor($keyVersion);

        $plaintext = openssl_decrypt(
            $body,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed; data may be tampered or key rotated.');
        }

        return $plaintext;
    }

    private function activeVersion(): int
    {
        return (int) (getenv('COUNSELLING_KEY_VERSION') ?: 1);
    }

    /**
     * Resolve the 32-byte key for a version.
     *
     * Resolution order:
     *   1. `counselling_key_versions.key_ref` (env var NAME, not material).
     *   2. Fallback: the active version maps to `COUNSELLING_KEY`;
     *      historical versions map to `COUNSELLING_KEY_V{n}`.
     */
    private function keyFor(int $version): string
    {
        $ref = $this->keyRefCache[$version] ??= $this->lookupKeyRef($version)
            ?? ($version === $this->activeVersion() ? 'COUNSELLING_KEY' : 'COUNSELLING_KEY_V' . $version);

        $hex = (string) (getenv($ref) ?: '');
        if (strlen($hex) !== 64 || ! ctype_xdigit($hex)) {
            throw new RuntimeException(sprintf(
                'Key env "%s" (version %d) must be a 64-character hex string (32 bytes).',
                $ref,
                $version,
            ));
        }

        $bin = hex2bin($hex);
        if ($bin === false || strlen($bin) !== 32) {
            throw new RuntimeException(sprintf('Key env "%s" is not valid hex.', $ref));
        }

        return $bin;
    }

    /**
     * Read `counselling_key_versions` for the env var name holding this
     * version's key. Returns null when the table is absent (pre-Phase 6
     * boot, unit tests) so the env fallback applies. Overridable in tests.
     */
    protected function lookupKeyRef(int $version): ?string
    {
        try {
            $row = \Config\Database::connect()
                ->table('counselling_key_versions')
                ->select('key_ref')
                ->where('version', $version)
                ->get()->getRowArray();

            return $row !== null ? (string) $row['key_ref'] : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
