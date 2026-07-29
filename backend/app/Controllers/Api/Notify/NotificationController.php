<?php

declare(strict_types=1);

namespace App\Controllers\Api\Notify;

use App\Auth\CurrentUser;
use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use App\Pagination\KeysetPaginator;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use DateTimeImmutable;
use DateTimeZone;

/**
 * NotificationController — in-app notifications for the CURRENT user.
 *
 * Strictly self-scoped: the recipient filter is always the token's
 * user id; there is no admin cross-user read here. `notifications.read`
 * gates the endpoint so kiosk-style service accounts can be excluded.
 */
final class NotificationController extends ApiController
{
    public function index(): ResponseInterface
    {
        $this->authorize('notifications.read');
        $userId = CurrentUser::assert();

        $cursor = (string) ($this->request->getGet('cursor') ?? '');
        $limit  = (int)    ($this->request->getGet('limit')  ?? 25);

        $builder = Services::database()
            ->table('notifications')
            ->select('id, template_code, context_json, read_at, created_at')
            ->where('recipient_user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC');

        KeysetPaginator::apply($builder, $cursor !== '' ? $cursor : null, $limit);
        $rows = $builder->get()->getResultArray();
        $final = KeysetPaginator::finalize($rows, $limit);

        $data = array_map(static function (array $r): array {
            return [
                'id'            => (int)    $r['id'],
                'template_code' => (string) $r['template_code'],
                'context'       => json_decode((string) ($r['context_json'] ?? 'null'), true),
                'read_at'       => $r['read_at'] !== null ? (string) $r['read_at'] : null,
                'created_at'    => (string) $r['created_at'],
            ];
        }, $final['rows']);

        return $this->ok(
            $data,
            \App\Http\ApiResponse::paginationMeta($limit, $final['nextCursor'], null),
        );
    }

    public function markRead(int $id): ResponseInterface
    {
        $this->authorize('notifications.read');
        $userId = CurrentUser::assert();

        $db = Services::database();
        $row = $db->table('notifications')
            ->where('id', $id)
            ->where('recipient_user_id', $userId)
            ->get()->getRowArray();

        if ($row === null) {
            throw ApiException::notFound('resource.not_found');
        }

        if ($row['read_at'] === null) {
            $db->table('notifications')
                ->where('id', $id)
                ->update(['read_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s')]);
        }

        return $this->ok(['id' => $id, 'read' => true]);
    }

    /**
     * Marks every unread notification for the CURRENT user as read.
     * Self-scoped like the rest of the controller — the recipient
     * filter is always the token's user id.
     */
    public function markAllRead(): ResponseInterface
    {
        $this->authorize('notifications.read');
        $userId = CurrentUser::assert();

        $db = Services::database();
        $db->table('notifications')
            ->where('recipient_user_id', $userId)
            ->where('read_at', null)
            ->update(['read_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s')]);

        return $this->ok(['read' => $db->affectedRows()]);
    }
}
