<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Exceptions\ApiException;
use App\Services\Audit\AuditPayload;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * CsvWriter — streaming CSV response helper.
 *
 * Owns a SplTempFileObject buffer, sets the canonical headers, and
 * provides a single-row API. Callers write a header row, then row-by-row,
 * then `flush()` to emit the response body.
 *
 * Redaction:
 *   - Sensitive payload keys are replaced with `<redacted>` recursively
 *     before being JSON-encoded into the last column. The shared
 *     AuditPayload policy also protects JSON detail responses.
 */
final class CsvWriter
{
    /**
     * Sensitive keys whose values MUST be replaced with `<redacted>`
     * before being streamed. Keys are matched case-insensitively.
     */
    public const REDACT_KEYS = AuditPayload::REDACT_KEYS;

    private \SplTempFileObject $buffer;

    public function __construct(
        private readonly ResponseInterface $response,
        private readonly string $filenamePrefix,
    ) {
        $filename = $filenamePrefix . '-' . gmdate('Ymd-His') . '.csv';

        $this->response->setHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->response->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $this->response->setHeader('Cache-Control', 'no-store');
        $this->response->setHeader('X-Content-Type-Options', 'nosniff');

        $this->buffer = new \SplTempFileObject();
    }

    /**
     * Write the column-header row.
     *
     * @param array<int, string> $columns
     */
    public function writeHeader(array $columns): void
    {
        $this->buffer->fputcsv($columns);
    }

    /**
     * Write a single CSV row.
     *
     * Cell values are neutralized against spreadsheet formula injection
     * (OWASP CSV sheet): a leading `=`, `+`, `-`, `@`, tab or CR would
     * otherwise be evaluated as a formula when the export is opened in
     * Excel/Sheets. Fields here carry user-controlled strings (names,
     * notes, JSON payloads), so the guard is applied to every cell.
     *
     * @param array<int, mixed> $values
     */
    public function writeRow(array $values): void
    {
        $this->buffer->fputcsv(array_map(static fn ($v) => self::escapeFormulaCell($v), $values));
    }

    /**
     * Prefix a formula-leading string cell with a single quote so the
     * spreadsheet renders it as text. Non-string cells and strings that
     * cannot start a formula pass through untouched.
     */
    private static function escapeFormulaCell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }
        $first = $value[0];
        if ($first === '=' || $first === '+' || $first === '-' || $first === '@'
            || $first === "\t" || $first === "\r") {
            return "'" . $value;
        }
        return $value;
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
        return AuditPayload::redact($payload);
    }

    /**
     * Flush the buffered CSV to the response body.
     */
    public function flush(): void
    {
        $this->buffer->rewind();
        $body = '';
        while (! $this->buffer->eof()) {
            $body .= $this->buffer->fgets();
        }
        $this->response->setBody($body);
    }

    public function close(): void
    {
        // SplTempFileObject cleanup is automatic; nothing to do.
    }

    public function __destruct()
    {
        $this->close();
    }
}
