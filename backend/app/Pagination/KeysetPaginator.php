<?php

declare(strict_types=1);

namespace App\Pagination;

use CodeIgniter\Database\BaseBuilder;
use InvalidArgumentException;

/**
 * KeysetPaginator — cursor-based, O(1) pagination built on indexed columns.
 *
 * Per directive: NEVER use OFFSET. Identifiers are `(created_at, id)` ordered
 * descending; cursors encode both values opaquely as base64url JSON.
 */
final class KeysetPaginator
{
    private const TS_KEY = 't';
    private const ID_KEY = 'i';

    /**
     * Encode a tuple (createdAt, id) into an opaque base64url cursor.
     * Both fields must be ISO8601 UTC and int respectively.
     */
    public static function encode(string $createdAt, int|string $id): string
    {
        $json = json_encode([self::TS_KEY => $createdAt, self::ID_KEY => (int) $id], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * Decode an opaque cursor. Returns null on empty / malformed input so
     * callers can treat it as "first page".
     *
     * @return array{created_at:string,id:int}|null
     */
    public static function decode(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $b64 = strtr($cursor, '-_', '+/');
        $padded = $b64 . str_repeat('=', (4 - (strlen($b64) % 4)) % 4);
        $raw = base64_decode($padded, true);
        if ($raw === false) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($decoded) || ! isset($decoded[self::TS_KEY], $decoded[self::ID_KEY])) {
            return null;
        }

        return [
            'created_at' => (string) $decoded[self::TS_KEY],
            'id'         => (int)    $decoded[self::ID_KEY],
        ];
    }

    /**
     * Apply keyset constraints to a query builder. The order MUST already
     * be `ORDER BY {tsColumn} DESC, {idColumn} DESC`; both columns indexed.
     *
     * `$tsColumn` / `$idColumn` accept alias-qualified names (`u.created_at`)
     * so joined queries stay unambiguous. `$maxLimit` is 100 for API pages;
     * bulk surfaces (CSV export) may raise it explicitly.
     */
    public static function apply(
        BaseBuilder $builder,
        ?string $cursor,
        int $limit,
        string $tsColumn = 'created_at',
        string $idColumn = 'id',
        int $maxLimit = 100,
    ): BaseBuilder {
        if ($limit < 1 || $limit > $maxLimit) {
            throw new InvalidArgumentException("Keyset limit must be between 1 and {$maxLimit}.");
        }

        $builder->limit($limit + 1); // +1 = sentinel to detect "has next"

        if (($decoded = self::decode($cursor)) !== null) {
            // Strict less-than comparison on (tsColumn, idColumn).
            $builder
                ->groupStart()
                    ->where($tsColumn . ' <', $decoded['created_at'])
                    ->orGroupStart()
                        ->where($tsColumn, $decoded['created_at'])
                        ->where($idColumn . ' <', $decoded['id'])
                    ->groupEnd()
                ->groupEnd();
        }

        return $builder;
    }

    /**
     * Given a list of rows (DESC-ordered) and the requested limit, return
     * sanitized rows + next cursor (or null if exhausted). `$tsKey` is the
     * row array key holding the timestamp (alias prefixes are stripped, so
     * `u.created_at` reads `$row['created_at']`).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{rows: array<int, array<string, mixed>>, nextCursor: ?string}
     */
    public static function finalize(array $rows, int $limit, string $tsKey = 'created_at'): array
    {
        $dot = strrpos($tsKey, '.');
        if ($dot !== false) {
            $tsKey = substr($tsKey, $dot + 1);
        }

        if (count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
            $last = end($rows);
            $nextCursor = self::encode((string) $last[$tsKey], (int) $last['id']);
        } else {
            $nextCursor = null;
        }

        return ['rows' => $rows, 'nextCursor' => $nextCursor];
    }
}