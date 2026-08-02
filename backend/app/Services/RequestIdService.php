<?php

declare(strict_types=1);

namespace App\Services;

/**
 * RequestIdService — request-scoped correlation identifier.
 *
 * Browser clients send UUID-shaped ids while the audit schema stores a
 * compact CHAR(32). `bind()` keeps the 32 hexadecimal UUID characters and
 * generates a cryptographically-random fallback for missing/invalid input.
 */
final class RequestIdService
{
    private ?string $current = null;

    public function bind(?string $candidate): string
    {
        $hex = strtolower((string) preg_replace('/[^a-fA-F0-9]/', '', $candidate ?? ''));
        $this->current = strlen($hex) >= 32
            ? substr($hex, 0, 32)
            : bin2hex(random_bytes(16));

        return $this->current;
    }

    public function current(): ?string
    {
        return $this->current;
    }
}
