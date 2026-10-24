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

    /**
     * Envelopes written at key_version >= 2 bind additional authenticated
     * data — "{table}|{entity_id}|{tenant_id}" — so a ciphertext moved
     * between rows/tenants fails to decrypt even under the right key
     * (2026-10 audit G-8). Version 1 rows stay AAD-less and decryptable.
     */
    public const AAD_MIN_VERSION = 2;

    /** @var array<int, string> Per-request cache of version -> key_ref. */
    private array $keyRefCache = [];

    /**
     * Encrypt plaintext. Returns an envelope array suitable for storage
     * in three separate columns: `ciphertext`, `nonce`, `key_version`.
     * `ciphertext` already carries the GCM tag as its final 16 bytes.
     *
     * @param string|null $aad Required when the active version is >= 2
     *        (caller-supplied row binding). Ignored for version 1.
     * @return array{ciphertext:string,nonce:string,key_version:int}
     */
    public function encryptField(string $plaintext, ?string $aad = null): array
    {
        $version = $this->activeVersion();
        $key     = $this->keyFor($version);
        $nonce   = random_bytes(self::NONCE_BYTES);
        $tag     = '';

        if ($version >= self::AAD_MIN_VERSION && ($aad === null || $aad === '')) {
            throw new RuntimeException('Encrypted fields at key_version >= 2 require AAD (row binding).');
        }

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $version >= self::AAD_MIN_VERSION ? $aad : '',
            self::TAG_BYTES,
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
     *
     * @param string|null $aad The SAME row binding the encryption used;
     *        required (and enforced) for key_version >= 2 envelopes.
     */
    public function decryptField(string $ciphertext, string $nonce, int $keyVersion, ?string $aad = null): string
    {
        if (strlen($ciphertext) <= self::TAG_BYTES) {
            throw new RuntimeException('Ciphertext too short; missing GCM tag.');
        }

        $tag  = substr($ciphertext, -self::TAG_BYTES);
        $body = substr($ciphertext, 0, -self::TAG_BYTES);
        $key  = $this->keyFor($keyVersion);

        if ($keyVersion >= self::AAD_MIN_VERSION && ($aad === null || $aad === '')) {
            throw new RuntimeException('Decryption at key_version >= 2 requires the AAD row binding.');
        }

        $plaintext = openssl_decrypt(
            $body,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $keyVersion >= self::AAD_MIN_VERSION ? $aad : '',
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

        return $this->materialFor($ref, $version);
    }

    /**
     * Encrypt under an EXPLICIT env key ref (no version table involved).
     * Used by subsystems outside the counselling key-version lifecycle —
     * e.g. the MFA secrets (MFA_KEY). Same AES-256-GCM envelope as
     * encryptField(), minus the key_version (the caller owns the ref).
     *
     * @return array{ciphertext:string,nonce:string}
     */
    public function encryptWith(string $keyRef, string $plaintext): array
    {
        $key   = $this->materialFor($keyRef, null);
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag   = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '', // No AAD for v1 envelopes (see Phase 5 notes).
            self::TAG_BYTES,
        );
        if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
            throw new RuntimeException('Encryption failed.');
        }

        return ['ciphertext' => $ciphertext . $tag, 'nonce' => $nonce];
    }

    /** Decrypt an encryptWith() envelope. */
    public function decryptWith(string $keyRef, string $ciphertext, string $nonce): string
    {
        if (strlen($ciphertext) <= self::TAG_BYTES) {
            throw new RuntimeException('Ciphertext too short; missing GCM tag.');
        }
        $tag  = substr($ciphertext, -self::TAG_BYTES);
        $body = substr($ciphertext, 0, -self::TAG_BYTES);
        $key  = $this->materialFor($keyRef, null);

        $plaintext = openssl_decrypt($body, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed; data may be tampered or key rotated.');
        }

        return $plaintext;
    }

    /** Validate and load the 64-hex material for an env key ref. */
    private function materialFor(string $ref, ?int $version): string
    {
        $hex = (string) (getenv($ref) ?: '');
        if (strlen($hex) !== 64 || ! ctype_xdigit($hex)) {
            throw new RuntimeException(sprintf(
                'Key env "%s"%s must be a 64-character hex string (32 bytes).',
                $ref,
                $version !== null ? sprintf(' (version %d)', $version) : '',
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
