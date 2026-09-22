<?php

declare(strict_types=1);

namespace App\Services\FuMis;

use UnexpectedValueException;

/** Strict pagination contract for bulk sync; unlike interactive search, fail closed. */
final class FuMisDirectoryPage
{
    /** @return array{records: list<array>, current_page: int, max_page: int} */
    public static function parse(array $response, int $requestedPage, int $maxPages): array
    {
        if (($response['success'] ?? true) === false || in_array($response['status'] ?? '', ['error', 'failed'], true)) {
            throw new UnexpectedValueException('MIS returned an unsuccessful directory response.');
        }
        // Documented root envelope and observed status/data/data envelope.
        $page = isset($response['current_page']) ? $response : ($response['data'] ?? null);
        if (! is_array($page) || ! isset($page['data'], $page['current_page'], $page['max_page'])
            || ! is_array($page['data']) || ! array_is_list($page['data'])) {
            throw new UnexpectedValueException('MIS directory pagination metadata is missing or malformed.');
        }
        $current = self::integer($page['current_page']);
        $last = self::integer($page['max_page']);
        if ($current !== $requestedPage || $last < 0 || $last > $maxPages
            || ($last < $current && ! ($last === 0 && $current === 1 && $page['data'] === []))) {
            throw new UnexpectedValueException('MIS returned an unexpected page number or exceeded the page safety limit.');
        }
        if ($page['data'] === [] && $current < $last) {
            throw new UnexpectedValueException('MIS returned an empty non-final page.');
        }
        foreach ($page['data'] as $record) {
            if (! is_array($record) || array_is_list($record)) {
                throw new UnexpectedValueException('MIS directory record is malformed.');
            }
        }
        return ['records' => $page['data'], 'current_page' => $current, 'max_page' => $last];
    }

    private static function integer(mixed $value): int
    {
        if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
            throw new UnexpectedValueException('MIS pagination fields must be integers.');
        }
        return (int) $value;
    }
}
