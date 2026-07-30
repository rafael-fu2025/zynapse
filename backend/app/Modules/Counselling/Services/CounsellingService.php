<?php

declare(strict_types=1);

namespace Modules\Counselling\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Pagination\KeysetPaginator;
use App\Services\Audit\AuditOutboxService;
use App\Services\Crypto\EncryptionService;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Counselling\DTOs\NoteDto;
use Modules\Counselling\DTOs\SessionDto;
use Modules\Counselling\Policies\CounsellingPolicy;

final class CounsellingService extends BaseService
{
    public function __construct(
        private readonly CounsellingPolicy $policy,
        private readonly AuditOutboxService $audit,
        private readonly EncryptionService $crypto,
    ) {
        parent::__construct();
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, next: ?string, count: int}
     */
    public function listSessions(?string $cursor, int $limit): array
    {
        $this->policy->check('list');

        $builder = $this->db->table('counselling_sessions')
            ->select('id, patient_school_id, counsellor_user_id, started_at, ended_at, created_at')
            ->where('archived_at', null)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC');

        KeysetPaginator::apply($builder, $cursor, $limit);

        $rows = $builder->get()->getResultArray();
        $final = KeysetPaginator::finalize($rows, $limit);

        return [
            'data'  => array_map(static fn (array $r) => SessionDto::fromRow($r)->toArray(), $final['rows']),
            'next'  => $final['nextCursor'],
            'count' => $limit,
        ];
    }

    public function openSession(string $patientSchoolId): SessionDto
    {
        $this->policy->check('open');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($patientSchoolId, $userId): SessionDto {
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $this->db->table('counselling_sessions')->insert([
                'patient_school_id'  => $patientSchoolId,
                'counsellor_user_id' => $userId,
                'started_at'         => $now,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);

            $id = (int) $this->db->insertID();

            $this->audit->enqueue(
                'counselling.session_opened',
                'counselling_sessions',
                $id,
                $userId,
                ['next_status' => 'open'],
            );

            $row = $this->db->table('counselling_sessions')->where('id', $id)->get()->getRowArray();
            return SessionDto::fromRow($row);
        });
    }

    public function writeNotes(int $sessionId, string $plaintext): NoteDto
    {
        $userId = \App\Auth\CurrentUser::assert();

        // Body size cap before encryption.
        if (strlen($plaintext) > 16384) {
            throw new ApiException('validation.invalid', 422, [
                ['code' => 'validation.invalid', 'message' => 'Note exceeds 16 KiB.', 'field' => 'plaintext'],
            ]);
        }

        return $this->txn(function () use ($sessionId, $plaintext, $userId): NoteDto {
            $session = $this->selectForUpdate('counselling_sessions', ['id' => $sessionId, 'archived_at' => null]);

            if ($session === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Session #{$sessionId} not found."],
                ]);
            }

            $this->policy->check('writeNotes', $session);

            $env = $this->crypto->encryptField($plaintext);

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $this->db->table('counselling_notes')->insert([
                'session_id'        => $sessionId,
                'notes_cipher'      => $env['ciphertext'],
                'notes_nonce'       => $env['nonce'],
                'notes_key_version' => $env['key_version'],
                'created_by_user_id'=> $userId,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);

            $noteId = (int) $this->db->insertID();

            $this->audit->enqueue(
                'counselling.notes_written',
                'counselling_notes',
                $noteId,
                $userId,
                ['resource_code' => 'session#' . $sessionId],
            );

            // DTO reveals plaintext only to the consuming channel
            // (controller); never persisted in plaintext.
            return new NoteDto($sessionId, $plaintext, $env['key_version'], $now);
        });
    }

    public function readNotes(int $sessionId): array
    {
        $userId = \App\Auth\CurrentUser::assert();

        $session = $this->db->table('counselling_sessions')
            ->select('id, counsellor_user_id')
            ->where('id', $sessionId)
            ->where('archived_at', null)
            ->get()->getRowArray();
        if ($session === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Session #{$sessionId} not found."],
            ]);
        }
        $this->policy->check('readNotes', $session);

        $rows = $this->db->table('counselling_notes')
            ->select('id, notes_cipher, notes_nonce, notes_key_version, created_at')
            ->where('session_id', $sessionId)
            ->orderBy('id', 'DESC')
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $plaintext = $this->crypto->decryptField(
                $r['notes_cipher'],
                $r['notes_nonce'],
                (int) $r['notes_key_version'],
            );
            $out[] = (new NoteDto(
                $sessionId,
                $plaintext,
                (int) $r['notes_key_version'],
                (string) $r['created_at'],
            ))->toArray();
        }

        // Sensitive-read audit (RBAC_SECURITY_REVIEW R2): decrypting
        // counselling notes is the most privacy-critical read in the
        // system, so every authorized read is recorded in the append-only
        // audit chain. No plaintext or patient identifier is logged.
        $this->audit->enqueue(
            'counselling.notes_read',
            'counselling_notes',
            $sessionId,
            $userId,
            ['resource_code' => 'session#' . $sessionId],
        );

        return $out;
    }

    public function closeSession(int $sessionId): SessionDto
    {
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($sessionId, $userId): SessionDto {
            $session = $this->selectForUpdate('counselling_sessions', ['id' => $sessionId, 'archived_at' => null]);

            if ($session === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Session #{$sessionId} not found."],
                ]);
            }

            $this->policy->check('close', $session);

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $this->db->table('counselling_sessions')
                ->where('id', $sessionId)
                ->update([
                    'ended_at'   => $now,
                    'updated_at' => $now,
                ]);

            $this->audit->enqueue(
                'counselling.session_closed',
                'counselling_sessions',
                $sessionId,
                $userId,
                ['resource_code' => 'session#' . $sessionId],
            );

            $row = $this->db->table('counselling_sessions')->where('id', $sessionId)->get()->getRowArray();
            return SessionDto::fromRow($row);
        });
    }
}