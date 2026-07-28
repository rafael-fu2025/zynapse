<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * CsvWriter — streaming CSV response helper.
 *
 * Owns the `php://output` handle, sets the canonical headers, and
 * provides a single-row API. Callers write a header row, then row-by-row,
 * then `close()` (or rely on the destructor for the cleanup path).
 *
 * Redaction:
 *   - Sensitive payload keys are replaced with `<redacted>` recursively
 *     before being JSON-encoded into the last column. The redaction list
 *     is duplicated here (rather than imported from a controller) so
 *     future export surfaces (referrals export, etc.) pick it up for free.
 */
final class CsvWriter
{
    /**
     * Sensitive keys whose values MUST be replaced with `<redacted>`
     * before being streamed. Keys are matched case-insensitively.
     */
    public const REDACT_KEYS = [
        'password', 'refresh_token', 'access_token', 'authorization',
        'cookie', 'token', 'qr_secret', 'plaintext', 'notes_plaintext',
        'patient_school_id',
    ];

    /** @var resource|null */
    private $handle = null;

    public function __construct(
        private readonly ResponseInterface $response,
        private readonly string $filenamePrefix,
    ) {
        $filename = $filenamePrefix . '-' . gmdate('Ymd-His') . '.csv';

        $this->response->setHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->response->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $this->response->setHeader('Cache-Control', 'no-store');
        $this->response->setHeader('X-Content-Type-Options', 'nosniff');

        $handle = fopen('php://output', 'w');
        if ($handle === false) {
            throw ApiException::conflict('export.unavailable', 'Unable to open CSV output stream.');
        }
        $this->handle = $handle;
    }

    /**
     * Write the column-header row.
     *
     * @param array<int, string> $columns
     */
    public function writeHeader(array $columns): void
    {
        $this->ensureOpen();
        fputcsv($this->handle, $columns);
    }

    /**
     * Write a single CSV row.
     *
     * @param array<int, mixed> $values
     */
    public function writeRow(array $values): void
    {
        $this->ensureOpen();
        fputcsv($this->handle, $values);
    }

    /**
     * Convenience: write a row whose final column is a redacted JSON
     * payload.
     *
     * @param array<int, mixed>             $prefix  Scalar columns.
     * @param array<string, mixed>|null     $payload Decoded payload_json.
     */
    public function writeRowWithRedactedPayload(array $prefix, ?array $payload): void
    {
        $this->writeRow([
            ...$prefix,
            json_encode(self::redact($payload ?? []), JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
        ]);
    }

    /**
     * Recursively redact sensitive keys. Static + pure so redaction
     * behaviour is unit-testable without an output stream.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function redact(array $payload): array
    {
        $out = [];
        foreach ($payload as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), self::REDACT_KEYS, true)) {
                $out[$k] = '<redacted>';
                continue;
            }
            $out[$k] = is_array($v) ? self::redact($v) : $v;
        }
        return $out;
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function ensureOpen(): void
    {
        if ($this->handle === null) {
            throw new \RuntimeException('CsvWriter is already closed.');
        }
    }
}