<?php

declare(strict_types=1);

namespace Modules\Counselling\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Pagination\KeysetPaginator;
use App\Services\Audit\AuditOutboxService;
use App\Services\Crypto\EncryptionService;
use App\Services\CurrentTenant;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Counselling\DTOs\NoteDto;
use Modules\Counselling\DTOs\SessionDto;
use Modules\Counselling\Policies\CounsellingPolicy;
use Modules\Referrals\DTOs\ReferralDto;

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
            ->where('counselling_sessions.tenant_id', CurrentTenant::id())
            ->select('id, patient_user_id, patient_school_id, counsellor_user_id, started_at, ended_at, created_at')
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

    /**
     * Exact, authorization-checked session lookup used by deep links and the
     * active Guidance workspace. Queue/referral context is staff-only and is
     * deliberately absent from the public queue DTO.
     *
     * @return array<string, mixed>
     */
    public function getSession(int $sessionId): array
    {
        $row = $this->db->table('counselling_sessions AS s')
            ->where('s.tenant_id', CurrentTenant::id())
            ->select('s.*, u.first_name, u.last_name, q.id AS queue_entry_id, q.position AS queue_position, q.status AS queue_status, q.purpose AS queue_purpose, q.counselling_appointment_id, q.referral_id')
            ->join('users AS u', 'u.id = s.patient_user_id', 'left')
            ->join('counselling_queue_entries AS q', 'q.counselling_session_id = s.id', 'left')
            ->where('s.id', $sessionId)
            ->where('s.archived_at', null)
            ->get()->getRowArray();
        if ($row === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Session #{$sessionId} not found."],
            ]);
        }
        $this->policy->check('list', $row);

        $first = trim((string) ($row['first_name'] ?? ''));
        $last = trim((string) ($row['last_name'] ?? ''));
        $queuePosition = $row['queue_position'] !== null ? (int) $row['queue_position'] : null;
        $noteCount = (int) $this->db->table('counselling_notes')->where('counselling_notes.tenant_id', CurrentTenant::id())->where('session_id', $sessionId)->countAllResults();
        $outgoing = $this->db->table('referral_referrals')->where('referral_referrals.tenant_id', CurrentTenant::id())->where('source_session_id', $sessionId)
            ->where('archived_at', null)->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();

        return array_merge(SessionDto::fromRow($row)->toArray(), [
            'patient_display_name' => trim($last . ($first !== '' ? ', ' . $first : '')) ?: (string) $row['patient_school_id'],
            'queue_entry_id' => $row['queue_entry_id'] !== null ? (int) $row['queue_entry_id'] : null,
            'queue_number' => $queuePosition !== null ? sprintf('G-%03d', $queuePosition) : null,
            'queue_status' => $row['queue_status'] !== null ? (string) $row['queue_status'] : null,
            'purpose' => $row['queue_purpose'] !== null ? (string) $row['queue_purpose'] : null,
            'appointment_id' => $row['counselling_appointment_id'] !== null ? (int) $row['counselling_appointment_id'] : null,
            'incoming_referral_id' => $row['referral_id'] !== null ? (int) $row['referral_id'] : null,
            'note_count' => $noteCount,
            'outgoing_referral' => $outgoing !== null ? ReferralDto::fromRow($outgoing)->toArray() : null,
        ]);
    }

    /**
     * Minimal patient lookup for the counselling forms (open session /
     * book appointment). The kiosk lookup requires `clinic.patients.read`,
     * which counsellors and clinical supervisors do NOT have. This is a
     * narrow, counselling-scoped search (same query, no PII beyond
     * id/kind/name/school_id) gated by `counselling.records.create` —
     * mirrors the referrals `lookupPatient` audit fix.
     *
     * @return array<int, array{id: int, kind: string, name: string, school_id: string}>
     */
    public function lookupPatient(string $q, int $limit = 8): array
    {
        $this->policy->check('open');
        $limit = max(1, min($limit, 12));
        $qTrim = trim($q);
        if ($qTrim === '') {
            return [];
        }

        $rows = $this->db->table('users')
            ->where('users.tenant_id', CurrentTenant::id())
            ->select('id, kind, first_name, last_name, middle_name, student_number, employee_number')
            ->whereIn('kind', ['student', 'employee'])
            ->where('archived_at', null)
            ->groupStart()
                ->like('student_number', $qTrim)
                ->orLike('employee_number', $qTrim)
                ->orLike('last_name', $qTrim)
                ->orLike('first_name', $qTrim)
            ->groupEnd()
            ->orderBy('last_name', 'ASC')
            ->orderBy('first_name', 'ASC')
            ->limit($limit)
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $schoolId = (string) ($r['kind'] === 'student' ? $r['student_number'] : $r['employee_number']);
            $middle   = $r['middle_name'] !== null && $r['middle_name'] !== ''
                ? ' ' . mb_substr((string) $r['middle_name'], 0, 1) . '.'
                : '';
            $out[] = [
                'id'        => (int) $r['id'],
                'kind'      => (string) $r['kind'],
                'name'      => trim((string) $r['last_name'] . ', ' . (string) $r['first_name'] . $middle),
                'school_id' => $schoolId,
            ];
        }

        return $out;
    }

    public function openSession(string $patientSchoolId): SessionDto
    {
        $this->policy->check('open');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($patientSchoolId, $userId): SessionDto {
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            [, $patient] = (new \Modules\Clinic\Services\PatientLookupService())->findByIdentifier($patientSchoolId);

            $this->db->table('counselling_sessions')->insert([
                'tenant_id'          => CurrentTenant::id(),
                'patient_user_id'    => $patient !== null ? (int) $patient['id'] : null,
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

            $row = $this->db->table('counselling_sessions')->where('counselling_sessions.tenant_id', CurrentTenant::id())->where('id', $id)->get()->getRowArray();
            return SessionDto::fromRow($row);
        });
    }

    /**
     * Append a note. Notes are INSERT-ONLY (F15): corrections arrive as
     * a new note carrying `supersedesNoteId`, never as an edit — the
     * superseded row stays intact so the error itself remains evidence.
     */
    public function writeNotes(int $sessionId, string $plaintext, ?int $supersedesNoteId = null): NoteDto
    {
        $userId = \App\Auth\CurrentUser::assert();

        // Body size cap before encryption.
        if (strlen($plaintext) > 16384) {
            throw new ApiException('validation.invalid', 422, [
                ['code' => 'validation.invalid', 'message' => 'Note exceeds 16 KiB.', 'field' => 'plaintext'],
            ]);
        }

        return $this->txn(function () use ($sessionId, $plaintext, $supersedesNoteId, $userId): NoteDto {
            $session = $this->selectForUpdate('counselling_sessions', ['tenant_id' => CurrentTenant::id(), 'id' => $sessionId, 'archived_at' => null]);

            if ($session === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Session #{$sessionId} not found."],
                ]);
            }

            $this->policy->check('writeNotes', $session);

            // An amendment must point at a real note of the SAME
            // session — otherwise it silently corrects nothing.
            if ($supersedesNoteId !== null) {
                $target = $this->db->table('counselling_notes')
                    ->where('counselling_notes.tenant_id', CurrentTenant::id())
                    ->select('id')
                    ->where('id', $supersedesNoteId)
                    ->where('session_id', $sessionId)
                    ->get()->getRowArray();
                if ($target === null) {
                    throw new ApiException('validation.invalid', 422, [
                        ['code' => 'validation.invalid', 'message' => "Note #{$supersedesNoteId} is not part of session #{$sessionId}.", 'field' => 'supersedes_note_id'],
                    ]);
                }
            }

            $env = $this->crypto->encryptField($plaintext);

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $this->db->table('counselling_notes')->insert([
                'tenant_id'           => CurrentTenant::id(),
                'session_id'          => $sessionId,
                'supersedes_note_id'  => $supersedesNoteId,
                'notes_cipher'        => $env['ciphertext'],
                'notes_nonce'         => $env['nonce'],
                'notes_key_version'   => $env['key_version'],
                'created_by_user_id'  => $userId,
                'created_at'          => $now,
                'updated_at'          => $now,
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
            return new NoteDto($sessionId, $plaintext, $env['key_version'], $now, $noteId, $supersedesNoteId);
        });
    }

    public function readNotes(int $sessionId): array
    {
        $userId = \App\Auth\CurrentUser::assert();

        $session = $this->db->table('counselling_sessions')
            ->where('counselling_sessions.tenant_id', CurrentTenant::id())
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
            ->where('counselling_notes.tenant_id', CurrentTenant::id())
            ->select('id, notes_cipher, notes_nonce, notes_key_version, created_at, supersedes_note_id')
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
                (int) $r['id'],
                $r['supersedes_note_id'] !== null ? (int) $r['supersedes_note_id'] : null,
            ))->toArray();
        }

        // Sensitive-read audit (RBAC_SECURITY_REVIEW R2): decrypting
        // counselling notes is the most privacy-critical read in the
        // system, so every authorized read is recorded in the append-only
        // audit chain. No plaintext or patient identifier is logged.
        // Logged only when notes actually exist — a zero-note session
        // decrypts nothing, so logging it would record a read that never
        // happened (audit 2026-09-03, F3).
        if ($out !== []) {
            $this->audit->enqueue(
                'counselling.notes_read',
                'counselling_notes',
                $sessionId,
                $userId,
                ['resource_code' => 'session#' . $sessionId],
            );
        }

        return $out;
    }

    public function closeSession(int $sessionId): SessionDto
    {
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($sessionId, $userId): SessionDto {
            $session = $this->selectForUpdate('counselling_sessions', ['tenant_id' => CurrentTenant::id(), 'id' => $sessionId, 'archived_at' => null]);

            if ($session === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Session #{$sessionId} not found."],
                ]);
            }

            $this->policy->check('close', $session);

            if ($session['ended_at'] !== null) {
                return SessionDto::fromRow($session);
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $this->db->table('counselling_sessions')
                ->where('counselling_sessions.tenant_id', CurrentTenant::id())
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

            $row = $this->db->table('counselling_sessions')->where('counselling_sessions.tenant_id', CurrentTenant::id())->where('id', $sessionId)->get()->getRowArray();
            return SessionDto::fromRow($row);
        });
    }

    /**
     * Transfer a session to another counsellor — the recorded
     * operational act that replaces the old standing "any counsellor
     * may act on any session" bypass (audit 2026-09-05, F2). Only
     * `counselling.records.read_any` holders (clinical_supervisor,
     * admin) pass the policy gate; the transfer itself is audited.
     */
    public function reassignSession(int $sessionId, int $newCounsellorUserId): SessionDto
    {
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($sessionId, $newCounsellorUserId, $userId): SessionDto {
            $session = $this->selectForUpdate('counselling_sessions', ['tenant_id' => CurrentTenant::id(), 'id' => $sessionId, 'archived_at' => null]);

            if ($session === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Session #{$sessionId} not found."],
                ]);
            }

            $this->policy->check('reassign', $session);

            // The target must be an active, non-archived user —
            // reassigning to a departed counsellor would strand the
            // session again, which is exactly what this route exists
            // to prevent.
            $target = $this->db->table('users')
                ->where('users.tenant_id', CurrentTenant::id())
                ->select('id, archived_at, status')
                ->where('id', $newCounsellorUserId)
                ->get()->getRowArray();
            if ($target === null || $target['archived_at'] !== null || (string) $target['status'] !== 'active') {
                throw new ApiException('validation.invalid', 422, [
                    ['code' => 'validation.invalid', 'message' => 'Target counsellor must be an active user.', 'field' => 'counsellor_user_id'],
                ]);
            }
            if ((int) $session['counsellor_user_id'] === $newCounsellorUserId) {
                throw new ApiException('statemachine.invalid_transition', 409, [
                    ['code' => 'statemachine.invalid_transition', 'message' => 'Session is already assigned to that counsellor.'],
                ]);
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('counselling_sessions')
                ->where('counselling_sessions.tenant_id', CurrentTenant::id())
                ->where('id', $sessionId)
                ->update([
                    'counsellor_user_id' => $newCounsellorUserId,
                    'updated_at'         => $now,
                ]);

            $this->audit->enqueue(
                'counselling.session_reassigned',
                'counselling_sessions',
                $sessionId,
                $userId,
                // from/to counsellor ids ride the whitelisted
                // resource_code key — AuditOutboxService drops any key
                // outside its CONTEXT_KEYS allow-list.
                ['resource_code' => sprintf('session#%d:%d->%d', $sessionId, (int) $session['counsellor_user_id'], $newCounsellorUserId)],
            );

            $row = $this->db->table('counselling_sessions')->where('counselling_sessions.tenant_id', CurrentTenant::id())->where('id', $sessionId)->get()->getRowArray();
            return SessionDto::fromRow($row);
        });
    }

    /**
     * Soft-delete a session (audit 2026-09-05, F15) — corrects a note-
     * on-the-wrong-patient mistake without mutating the notes themselves.
     * All read paths already filter on `archived_at = null`. Gated on
     * `counselling.records.soft_delete` (supervisor + admin only).
     */
    public function archiveSession(int $sessionId): SessionDto
    {
        return $this->setSessionArchived($sessionId, true);
    }

    public function unarchiveSession(int $sessionId): SessionDto
    {
        return $this->setSessionArchived($sessionId, false);
    }

    private function setSessionArchived(int $sessionId, bool $archived): SessionDto
    {
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($sessionId, $archived, $userId): SessionDto {
            // Unarchive needs to find already-archived rows; archive finds
            // only live ones.
            $predicates = ['tenant_id' => CurrentTenant::id(), 'id' => $sessionId];
            if ($archived) {
                $predicates['archived_at'] = null;
            }
            $session = $this->selectForUpdate('counselling_sessions', $predicates);

            if ($session === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Session #{$sessionId} not found."],
                ]);
            }

            $action = $archived ? 'archive' : 'unarchive';
            $this->policy->check($action, $session);

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('counselling_sessions')
                ->where('counselling_sessions.tenant_id', CurrentTenant::id())
                ->where('id', $sessionId)
                ->update([
                    'archived_at' => $archived ? $now : null,
                    'updated_at'  => $now,
                ]);

            $this->audit->enqueue(
                'counselling.session_' . ($archived ? 'archived' : 'unarchived'),
                'counselling_sessions',
                $sessionId,
                $userId,
                ['resource_code' => 'session#' . $sessionId],
            );

            $row = $this->db->table('counselling_sessions')->where('counselling_sessions.tenant_id', CurrentTenant::id())->where('id', $sessionId)->get()->getRowArray();
            return SessionDto::fromRow($row);
        });
    }
}
