<?php

declare(strict_types=1);

namespace Modules\Counselling\Policies;

use App\Modules\Shared\BasePolicy;

/**
 * CounsellingPolicy — gates counselling sessions + encrypted notes.
 *
 * Module-level permissions:
 *   - counselling.records.read      → list / view
 *   - counselling.records.create    → open session
 *   - counselling.records.write     → write notes / close (module gate)
 *   - counselling.records.read_any  → oversight: act on any session
 *
 * Record-level (F2 remediation, audit 2026-09-05):
 *   - readNotes / writeNotes / close are OWN-SESSION: only the
 *     session's `counsellor_user_id` passes. `counselling.records.write`
 *     alone does NOT bypass this — previously it did, and since every
 *     counsellor holds it, the ownership branch was unreachable.
 *   - `counselling.records.read_any` (clinical_supervisor + admin) is
 *     the deliberate, audited oversight path for sessions the caller
 *     does not own; every decrypt is logged (CounsellingService
 *     `counselling.notes_read`).
 *   - Ownership transfer for coverage (counsellor on leave, walk-in
 *     reassignment) goes through the audited
 *     POST sessions/{id}/reassign route — never a standing bypass.
 */
final class CounsellingPolicy extends BasePolicy
{
    public function check(string $action, mixed $record = null): void
    {
        $code = match ($action) {
            'list'       => 'counselling.records.read',
            'open'       => 'counselling.records.create',
            'writeNotes' => 'counselling.records.write',
            'readNotes'  => 'counselling.records.read',
            'close'      => 'counselling.records.write',
            'refer'      => 'counselling.records.write',
            'reassign'   => 'counselling.records.read_any',
            // Session archive / unarchive (audit 2026-09-05, F15): gated
            // on `counselling.records.soft_delete` — granted ONLY to
            // clinical_supervisor + admin, not counsellors.
            'archive'    => 'counselling.records.soft_delete',
            'unarchive'  => 'counselling.records.soft_delete',
            'scheduleRead'   => 'counselling.schedule.read',
            'scheduleManage' => 'counselling.schedule.manage',
            'scheduleTeamManage' => 'counselling.schedule.team_manage',
            'queueRead'      => 'counselling.queue.read',
            'queueManage'    => 'counselling.queue.manage',
            default      => null,
        };
        if ($code === null) {
            $this->deny('rbac.counselling.forbidden');
        }
        $this->enforce($code, $action, $record);
    }

    /**
     * Own-session for note access and lifecycle; `read_any` is the
     * oversight override. `list`/`open`/schedule/queue actions carry no
     * record and never reach this branch.
     *
     * @param array<string, mixed>|object|null $record
     */
    protected function canOnRecord(int $userId, mixed $record, string $action): bool
    {
        if (in_array($action, ['readNotes', 'writeNotes', 'close', 'refer'], true)) {
            if ($this->can('counselling.records.read_any')) {
                return true;
            }
            $counsellor = is_array($record)
                ? ($record['counsellor_user_id'] ?? null)
                : ($record?->counsellor_user_id ?? null);
            return $counsellor !== null && (int) $counsellor === $userId;
        }
        return true;
    }
}
