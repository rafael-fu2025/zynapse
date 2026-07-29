<?php

declare(strict_types=1);

namespace App\Controllers\Api\Audit;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use App\Pagination\KeysetPaginator;
use App\Services\Audit\AuditChainVerifier;
use App\Services\Export\CsvWriter;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * AuditEventController — read-only audit reader + audit.export CSV +
 * hash-chain verification (Phase 6).
 *
 * Read endpoints require `audit.read`. Export requires `audit.export`.
 * The CSV writer applies a PII redaction pass: sensitive keys under
 * `payload_json` are replaced with `<redacted>` before streaming.
 */
final class AuditEventController extends ApiController
{
    public function index(): ResponseInterface
    {
        $this->authorize('audit.read');

        $cursor = (string) ($this->request->getGet('cursor') ?? '');
        $limit  = (int)    ($this->request->getGet('limit')  ?? 50);
        $action = $this->request->getGet('action');
        $entity = $this->request->getGet('entity_type');

        $builder = Services::database()
            ->table('audit_events')
            ->select('id, prev_id, action_code, entity_type, entity_id, actor_user_id, request_id, commited_at, commit_hash')
            ->orderBy('commited_at', 'DESC')
            ->orderBy('id', 'DESC');

        if (is_string($action) && $action !== '') {
            $builder->where('action_code', $action);
        }
        if (is_string($entity) && $entity !== '') {
            $builder->where('entity_type', $entity);
        }

        KeysetPaginator::apply($builder, $cursor !== '' ? $cursor : null, $limit, 'commited_at');
        $rows = $builder->get()->getResultArray();
        $final = KeysetPaginator::finalize($rows, $limit, 'commited_at');

        $data = array_map(static function (array $r): array {
            return [
                'id'             => (int)    $r['id'],
                'prev_id'        => $r['prev_id'] !== null ? (int) $r['prev_id'] : null,
                'action_code'    => (string) $r['action_code'],
                'entity_type'    => (string) $r['entity_type'],
                'entity_id'      => $r['entity_id'] !== null ? (int) $r['entity_id'] : null,
                'actor_user_id'  => $r['actor_user_id'] !== null ? (int) $r['actor_user_id'] : null,
                'request_id'     => $r['request_id'] !== null ? (string) $r['request_id'] : null,
                'committed_at'   => (string) $r['commited_at'],
                'commit_hash'    => (string) $r['commit_hash'],
            ];
        }, $final['rows']);

        return $this->ok(
            $data,
            \App\Http\ApiResponse::paginationMeta($limit, $final['nextCursor'], null),
        );
    }

    public function show(int $id): ResponseInterface
    {
        $this->authorize('audit.read');
        $row = Services::database()
            ->table('audit_events')
            ->where('id', $id)
            ->get()->getRowArray();

        if ($row === null) {
            throw ApiException::notFound('audit.event_not_found');
        }

        return $this->ok([
            'id'           => (int)    $row['id'],
            'action_code'  => (string) $row['action_code'],
            'entity_type'  => (string) $row['entity_type'],
            'entity_id'    => $row['entity_id'] !== null ? (int) $row['entity_id'] : null,
            'payload'      => json_decode((string) $row['payload_json'], true),
            'committed_at' => (string) $row['commited_at'],
            'commit_hash'  => (string) $row['commit_hash'],
        ]);
    }

    /**
     * Streams a CSV of audit events. Optional `cursor` argument lets
     * callers break a large window into chunks. The endpoint enforces
     * a hard cap of 5,000 rows per request to keep the response bounded.
     * Accepts the same `action` / `entity_type` filters as `index()` so
     * a filtered on-screen view exports the same slice.
     */
    public function export(): ResponseInterface
    {
        $this->authorize('audit.export');

        $cursor = (string) ($this->request->getGet('cursor') ?? '');
        $limit  = (int)    ($this->request->getGet('limit')  ?? 1000);
        $limit  = max(1, min(5_000, $limit));
        $action = $this->request->getGet('action');
        $entity = $this->request->getGet('entity_type');

        $builder = Services::database()
            ->table('audit_events')
            ->select('id, prev_id, action_code, entity_type, entity_id, actor_user_id, request_id, commited_at, commit_hash, payload_json')
            ->orderBy('commited_at', 'DESC')
            ->orderBy('id', 'DESC');

        if (is_string($action) && $action !== '') {
            $builder->where('action_code', $action);
        }
        if (is_string($entity) && $entity !== '') {
            $builder->where('entity_type', $entity);
        }

        KeysetPaginator::apply($builder, $cursor !== '' ? $cursor : null, $limit, 'commited_at', 'id', 5_000);
        $rows = $builder->get()->getResultArray();

        $writer = new CsvWriter($this->response, 'synapse-audit');
        $writer->writeHeader([
            'id', 'prev_id', 'action_code', 'entity_type', 'entity_id',
            'actor_user_id', 'request_id', 'commited_at', 'commit_hash', 'payload_json_redacted',
        ]);

        foreach ($rows as $r) {
            $payload = json_decode((string) $r['payload_json'], true);
            $writer->writeRowWithRedactedPayload(
                [
                    $r['id'],
                    $r['prev_id'] ?? '',
                    $r['action_code'],
                    $r['entity_type'],
                    $r['entity_id'] ?? '',
                    $r['actor_user_id'] ?? '',
                    $r['request_id'] ?? '',
                    $r['commited_at'],
                    $r['commit_hash'],
                ],
                is_array($payload) ? $payload : null,
            );
        }

        $writer->close();
        return $this->response;
    }

    /**
     * Recompute the hash chain from genesis up to `{id}` and report the
     * first divergence, if any. Read-only; requires `audit.read`.
     */
    public function verify(int $id): ResponseInterface
    {
        $this->authorize('audit.read');

        $exists = Services::database()
            ->table('audit_events')
            ->select('id')
            ->where('id', $id)
            ->get()->getRowArray();

        if ($exists === null) {
            throw ApiException::notFound('audit.event_not_found');
        }

        return $this->ok((new AuditChainVerifier())->verify($id));
    }
}
