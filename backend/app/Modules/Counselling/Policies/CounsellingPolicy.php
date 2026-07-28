<?php

declare(strict_types=1);

namespace Modules\Counselling\Policies;

use App\Modules\Shared\BasePolicy;

/**
 * CounsellingPolicy — gates counselling sessions + encrypted notes.
 *
 * Module-level permissions:
 *   - counselling.records.read  → list / view
 *   - counselling.records.create → open session
 *   - counselling.records.write  → write notes / close
 *
 * Record-level:
 *   - A user with `counselling.records.write` may act on any session.
 *   - Otherwise, only the session's `counsellor_user_id` may act.
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
            'scheduleRead'   => 'counselling.schedule.read',
            'scheduleManage' => 'counselling.schedule.manage',
            default      => null,
        };
        if ($code === null) {
            $this->deny('rbac.counselling.forbidden');
        }
        $this->enforce($code, $action, $record);
    }

    /**
     * @param array<string, mixed>|object|null $record
     */
    protected function canOnRecord(int $userId, mixed $record, string $action): bool
    {
        if ($this->can('counselling.records.write')) {
            return true;
        }
        $counsellor = is_array($record)
            ? ($record['counsellor_user_id'] ?? null)
            : ($record?->counsellor_user_id ?? null);
        return $counsellor !== null && (int) $counsellor === $userId;
    }
}