<?php

declare(strict_types=1);

namespace App\Http;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * ApiResponse — the ONLY response shape this API emits.
 *
 * Envelope:
 *   { "success": bool, "data": T|null, "errors": array|null, "meta": array|null }
 *
 * Controllers must never call `response()->setJSON()` directly.
 */
final class ApiResponse
{
    /**
     * @param array<string, mixed>|null $meta Includes `pagination` for keyset pages.
     * @return array{status:int, body:array{success:bool,data:mixed,errors:null,meta:array|null}}
     */
    public static function success(mixed $data = null, ?array $meta = null, int $status = 200): array
    {
        return [
            'status' => $status,
            'body'   => [
                'success' => true,
                'data'    => $data,
                'errors'  => null,
                'meta'    => $meta,
            ],
        ];
    }

    /**
     * @param array<int, array{code:string,message:string,field?:string,details?:array}> $errors
     */
    public static function failure(array $errors, int $status = 400, ?array $meta = null): array
    {
        return [
            'status' => $status,
            'body'   => [
                'success' => false,
                'data'    => null,
                'errors'  => $errors,
                'meta'    => $meta,
            ],
        ];
    }

    /**
     * Apply a previously-built envelope to a ResponseInterface.
     */
    public static function apply(ResponseInterface $response, array $envelope): ResponseInterface
    {
        return $response
            ->setStatusCode($envelope['status'])
            ->setJSON($envelope['body']);
    }

    /**
     * Canonical keyset pagination meta-builder.
     *
     * @param string|null $nextCursor Opaque base64 cursor; null = end of stream.
     * @param string|null $prevCursor
     */
    public static function paginationMeta(int $count, ?string $nextCursor, ?string $prevCursor): array
    {
        return [
            'pagination' => [
                'limit'       => $count,
                'next_cursor' => $nextCursor,
                'prev_cursor' => $prevCursor,
            ],
        ];
    }
}