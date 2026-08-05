<?php

declare(strict_types=1);

namespace App\Controllers\Api\Audit;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use App\Http\ApiResponse;
use App\Pagination\KeysetPaginator;
use App\Services\Audit\AuditChainVerifier;
use App\Services\Audit\AuditPayload;
use App\Services\Export\CsvWriter;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use Config\Services;

/**
 * Read-only administrative reader for the immutable audit event chain.
 */
final class AuditEventController extends ApiController
{
    public function index(): ResponseInterface
    {
        $this->authorize('audit.read');

        $filters = $this->readFilters();
        $cursor  = trim((string) ($this->request->getGet('cursor') ?? ''));
        $limit   = (int) ($this->request->getGet('limit') ?? 50);
        if ($limit < 1 || $limit > 100) {
            throw $this->invalidFilter('limit', 'limit must be between 1 and 100.');
        }

        $builder = $this->eventBuilder(false);
        $this->applyFilters($builder, $filters);
        $builder
            ->orderBy('ae.occurred_at', 'DESC')
            ->orderBy('ae.id', 'DESC');

        KeysetPaginator::apply($builder, $cursor !== '' ? $cursor : null, $limit, 'ae.occurred_at', 'ae.id');
        $final = KeysetPaginator::finalize($builder->get()->getResultArray(), $limit, 'occurred_at');

        return $this->ok(
            array_map(fn (array $row): array => $this->mapEvent($row), $final['rows']),
            ApiResponse::paginationMeta($limit, $final['nextCursor'], null),
        );
    }

    /**
     * Return values already present in the evidence store for filter controls.
     */
    public function facets(): ResponseInterface
    {
        $this->authorize('audit.read');
        $db = Services::database();

        $actions = array_column(
            $db->table(SYNAPSE_AUDIT_EVENTS)
                ->distinct()->select('action_code')->orderBy('action_code', 'ASC')
                ->get()->getResultArray(),
            'action_code',
        );
        $entities = array_column(
            $db->table(SYNAPSE_AUDIT_EVENTS)
                ->distinct()->select('entity_type')->orderBy('entity_type', 'ASC')
                ->get()->getResultArray(),
            'entity_type',
        );
        $actors = $db->table(SYNAPSE_AUDIT_EVENTS . ' ae')
            ->distinct()
            ->select('u.id, u.username AS display_name, ai.secret AS email')
            ->join('users u', 'u.id = ae.actor_user_id', 'inner')
            ->join('auth_identities ai', "ai.user_id = u.id AND ai.type = 'email_password'", 'left')
            ->orderBy('ai.secret', 'ASC')
            ->get()->getResultArray();

        return $this->ok([
            'action_codes' => array_values(array_map('strval', $actions)),
            'entity_types' => array_values(array_map('strval', $entities)),
            'actors'       => array_map(static fn (array $actor): array => [
                'id'           => (int) $actor['id'],
                'email'        => $actor['email'] !== null ? (string) $actor['email'] : null,
                'display_name' => $actor['display_name'] !== null ? (string) $actor['display_name'] : null,
            ], $actors),
        ]);
    }

    public function show(int $id): ResponseInterface
    {
        $this->authorize('audit.read');

        $row = $this->eventBuilder(true)
            ->where('ae.id', $id)
            ->get()->getRowArray();
        if ($row === null) {
            throw ApiException::notFound('audit.event_not_found');
        }

        $payload = json_decode((string) $row['payload_json'], true);
        return $this->ok([
            ...$this->mapEvent($row),
            'payload' => AuditPayload::redact(is_array($payload) ? $payload : []),
        ]);
    }

    /**
     * Stream up to 5,000 events from the complete filtered range.
     */
    public function export(): ResponseInterface
    {
        $this->authorize('audit.export');

        $filters = $this->readFilters();
        $cursor  = trim((string) ($this->request->getGet('cursor') ?? ''));
        $limit   = max(1, min(5_000, (int) ($this->request->getGet('limit') ?? 5_000)));

        $builder = $this->eventBuilder(true);
        $this->applyFilters($builder, $filters);
        $builder
            ->orderBy('ae.occurred_at', 'DESC')
            ->orderBy('ae.id', 'DESC');
        KeysetPaginator::apply($builder, $cursor !== '' ? $cursor : null, $limit, 'ae.occurred_at', 'ae.id', 5_000);
        $final = KeysetPaginator::finalize($builder->get()->getResultArray(), $limit, 'occurred_at');

        $writer = new CsvWriter($this->response, 'synapse-audit');
        $writer->writeHeader([
            'id', 'prev_id', 'action_code', 'entity_type', 'entity_id',
            'actor_user_id', 'actor_email', 'actor_display_name', 'request_id',
            'occurred_at', 'commit_hash', 'payload_json_redacted',
        ]);

        foreach ($final['rows'] as $row) {
            $payload = json_decode((string) $row['payload_json'], true);
            $writer->writeRowWithRedactedPayload([
                $row['id'],
                $row['prev_id'] ?? '',
                $row['action_code'],
                $row['entity_type'],
                $row['entity_id'] ?? '',
                $row['actor_user_id'] ?? '',
                $row['actor_email'] ?? '',
                $row['actor_display_name'] ?? '',
                $row['request_id'] ?? '',
                $row['occurred_at'],
                $row['commit_hash'],
            ], is_array($payload) ? $payload : null);
        }

        $writer->close();
        return $this->response;
    }

    /** Verify the complete chain from genesis. */
    public function verifyAll(): ResponseInterface
    {
        $this->authorize('audit.read');
        return $this->ok((new AuditChainVerifier())->verify());
    }

    /** Verify the chain from genesis through a specific event. */
    public function verify(int $id): ResponseInterface
    {
        $this->authorize('audit.read');

        $exists = Services::database()->table(SYNAPSE_AUDIT_EVENTS)
            ->select('id')->where('id', $id)->get()->getRowArray();
        if ($exists === null) {
            throw ApiException::notFound('audit.event_not_found');
        }

        return $this->ok((new AuditChainVerifier())->verify($id));
    }

    private function eventBuilder(bool $withPayload): BaseBuilder
    {
        $columns = 'ae.id, ae.prev_id, ae.action_code, ae.entity_type, ae.entity_id, '
            . 'ae.actor_user_id, ae.request_id, ae.occurred_at, ae.commited_at, ae.commit_hash, '
            . 'u.username AS actor_display_name, ai.secret AS actor_email';
        if ($withPayload) {
            $columns .= ', ae.payload_json';
        }

        return Services::database()->table(SYNAPSE_AUDIT_EVENTS . ' ae')
            ->select($columns)
            ->join('users u', 'u.id = ae.actor_user_id', 'left')
            ->join('auth_identities ai', "ai.user_id = u.id AND ai.type = 'email_password'", 'left');
    }

    /**
     * @return array{action:?string,entity_type:?string,entity_id:?int,actor_user_id:?int,request_id:?string,from:?string,to:?string,q:?string}
     */
    private function readFilters(): array
    {
        $action     = $this->optionalString('action', 64);
        $entityType = $this->optionalString('entity_type', 64);
        $query      = $this->optionalString('q', 120);
        $entityId   = $this->optionalPositiveInt('entity_id');
        $actorId    = $this->optionalPositiveInt('actor_user_id');
        $from       = $this->optionalDate('from');
        $to         = $this->optionalDate('to');
        if ($from !== null && $to !== null && $from > $to) {
            throw $this->invalidFilter('from', 'from must be on or before to.');
        }

        $requestIdRaw = trim((string) ($this->request->getGet('request_id') ?? ''));
        $requestId = null;
        if ($requestIdRaw !== '') {
            $normalized = strtolower((string) preg_replace('/[^a-fA-F0-9]/', '', $requestIdRaw));
            if (strlen($normalized) !== 32) {
                throw $this->invalidFilter('request_id', 'request_id must contain exactly 32 hexadecimal characters.');
            }
            $requestId = $normalized;
        }

        return [
            'action'        => $action,
            'entity_type'   => $entityType,
            'entity_id'     => $entityId,
            'actor_user_id' => $actorId,
            'request_id'    => $requestId,
            'from'          => $from,
            'to'            => $to,
            'q'             => $query,
        ];
    }

    /** @param array{action:?string,entity_type:?string,entity_id:?int,actor_user_id:?int,request_id:?string,from:?string,to:?string,q:?string} $filters */
    private function applyFilters(BaseBuilder $builder, array $filters): void
    {
        if ($filters['action'] !== null) {
            $builder->where('ae.action_code', $filters['action']);
        }
        if ($filters['entity_type'] !== null) {
            $builder->where('ae.entity_type', $filters['entity_type']);
        }
        if ($filters['entity_id'] !== null) {
            $builder->where('ae.entity_id', $filters['entity_id']);
        }
        if ($filters['actor_user_id'] !== null) {
            $builder->where('ae.actor_user_id', $filters['actor_user_id']);
        }
        if ($filters['request_id'] !== null) {
            $builder->where('ae.request_id', $filters['request_id']);
        }
        if ($filters['from'] !== null) {
            $builder->where('ae.occurred_at >=', $filters['from'] . ' 00:00:00');
        }
        if ($filters['to'] !== null) {
            $exclusiveEnd = (new DateTimeImmutable($filters['to']))->modify('+1 day')->format('Y-m-d');
            $builder->where('ae.occurred_at <', $exclusiveEnd . ' 00:00:00');
        }
        if ($filters['q'] !== null) {
            $builder->like('ae.payload_json', $filters['q']);
        }
    }

    /** @return array<string, mixed> */
    private function mapEvent(array $row): array
    {
        $actorId = $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null;
        return [
            'id'            => (int) $row['id'],
            'prev_id'       => $row['prev_id'] !== null ? (int) $row['prev_id'] : null,
            'action_code'   => (string) $row['action_code'],
            'entity_type'   => (string) $row['entity_type'],
            'entity_id'     => $row['entity_id'] !== null ? (int) $row['entity_id'] : null,
            'actor'         => $actorId === null ? null : [
                'id'           => $actorId,
                'email'        => $row['actor_email'] !== null ? (string) $row['actor_email'] : null,
                'display_name' => $row['actor_display_name'] !== null ? (string) $row['actor_display_name'] : null,
            ],
            'request_id'    => $row['request_id'] !== null ? (string) $row['request_id'] : null,
            'occurred_at'   => $row['occurred_at'] !== null ? (string) $row['occurred_at'] : null,
            'committed_at'  => (string) $row['commited_at'],
            'commit_hash'   => (string) $row['commit_hash'],
        ];
    }

    private function optionalString(string $field, int $maxLength): ?string
    {
        $value = trim((string) ($this->request->getGet($field) ?? ''));
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $maxLength) {
            throw $this->invalidFilter($field, $field . " must not exceed {$maxLength} characters.");
        }
        return $value;
    }

    private function optionalPositiveInt(string $field): ?int
    {
        $raw = trim((string) ($this->request->getGet($field) ?? ''));
        if ($raw === '') {
            return null;
        }
        if (! ctype_digit($raw) || (int) $raw < 1) {
            throw $this->invalidFilter($field, $field . ' must be a positive integer.');
        }
        return (int) $raw;
    }

    private function optionalDate(string $field): ?string
    {
        $value = trim((string) ($this->request->getGet($field) ?? ''));
        if ($value === '') {
            return null;
        }
        $parts = explode('-', $value);
        if (count($parts) !== 3 || ! checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]) || strlen($parts[0]) !== 4) {
            throw $this->invalidFilter($field, $field . ' must be a valid YYYY-MM-DD date.');
        }
        return $value;
    }

    private function invalidFilter(string $field, string $message): ApiException
    {
        return ApiException::validationFailure([
            ['code' => 'validation.field', 'message' => $message, 'field' => $field],
        ]);
    }
}
