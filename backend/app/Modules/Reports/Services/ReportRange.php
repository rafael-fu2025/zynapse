<?php

declare(strict_types=1);

namespace Modules\Reports\Services;

use App\Exceptions\ApiException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Canonical report-range contract.
 *
 * Calendar dates are selected in Asia/Manila. Timestamp-backed tables are
 * stored in UTC, so their queries use an inclusive UTC start and exclusive
 * UTC end. DATE columns continue to use the calendar dates directly.
 */
final class ReportRange
{
    public const APP_TIMEZONE = 'Asia/Manila';
    public const DEFAULT_DAYS = 30;
    public const MAX_DAYS = 366;

    /**
     * @return array{start: string, end: string}
     */
    public function resolve(?string $start, ?string $end): array
    {
        $start = $this->blankToNull($start);
        $end = $this->blankToNull($end);

        if ($start === null && $end === null) {
            $endDate = new DateTimeImmutable('today', new DateTimeZone(self::APP_TIMEZONE));
            $startDate = $endDate->modify('-' . (self::DEFAULT_DAYS - 1) . ' days');

            return [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d'),
            ];
        }

        if ($start === null || $end === null) {
            throw $this->validation('Both start and end dates are required.', $start === null ? 'start' : 'end');
        }

        $startDate = $this->parseDate($start, 'start');
        $endDate = $this->parseDate($end, 'end');

        if ($startDate > $endDate) {
            throw $this->validation('Start date must be on or before end date.', 'start');
        }

        $days = (int) $startDate->diff($endDate)->format('%a') + 1;
        if ($days > self::MAX_DAYS) {
            throw $this->validation('Date range cannot exceed ' . self::MAX_DAYS . ' days.', 'end');
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * @param array{start: string, end: string} $range
     * @return array{start_utc: string, end_utc_exclusive: string}
     */
    public function timestampBounds(array $range): array
    {
        $tz = new DateTimeZone(self::APP_TIMEZONE);
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable($range['start'] . ' 00:00:00', $tz);
        $endExclusive = (new DateTimeImmutable($range['end'] . ' 00:00:00', $tz))->modify('+1 day');

        return [
            'start_utc' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            'end_utc_exclusive' => $endExclusive->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Return the immediately preceding range with the same number of days.
     *
     * @param array{start: string, end: string} $range
     * @return array{start: string, end: string}
     */
    public function previous(array $range): array
    {
        $tz = new DateTimeZone(self::APP_TIMEZONE);
        $start = new DateTimeImmutable($range['start'], $tz);
        $end = new DateTimeImmutable($range['end'], $tz);
        $days = (int) $start->diff($end)->format('%a') + 1;
        $previousEnd = $start->modify('-1 day');

        return [
            'start' => $previousEnd->modify('-' . ($days - 1) . ' days')->format('Y-m-d'),
            'end' => $previousEnd->format('Y-m-d'),
        ];
    }

    private function blankToNull(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        return trim($value);
    }

    private function parseDate(string $value, string $field): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(self::APP_TIMEZONE));
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $parsed->format('Y-m-d') !== $value) {
            throw $this->validation('Date must be a real calendar date in YYYY-MM-DD format.', $field);
        }
        return $parsed;
    }

    private function validation(string $message, string $field): ApiException
    {
        return ApiException::validationFailure([
            ['code' => 'validation.field', 'message' => $message, 'field' => $field],
        ]);
    }
}
