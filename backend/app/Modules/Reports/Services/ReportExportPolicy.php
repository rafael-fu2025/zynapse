<?php

declare(strict_types=1);

namespace Modules\Reports\Services;

/**
 * Privacy and spreadsheet-safety policy for analytics CSV cells.
 *
 * Report queries are responsible for returning aggregate, allowlisted fields.
 * This final output boundary removes embedded line breaks and neutralizes
 * spreadsheet formulas, including values padded with leading whitespace.
 */
final class ReportExportPolicy
{
    /** @param array<int, mixed> $row @return array<int, mixed> */
    public static function sanitizeRow(array $row): array
    {
        return array_map(self::sanitizeCell(...), $row);
    }

    public static function sanitizeCell(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = preg_replace('/\R/u', ' ', $value) ?? str_replace(["\r", "\n"], ' ', $value);
        $trimmed = ltrim($value);
        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
            return "'" . $value;
        }

        return $value;
    }
}
