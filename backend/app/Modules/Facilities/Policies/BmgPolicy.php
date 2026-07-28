<?php

declare(strict_types=1);

namespace Modules\Facilities\Policies;

use App\Modules\Shared\BasePolicy;

/**
 * BmgPolicy — gates the BMG state machine.
 *
 * Module-level permissions:
 *   - facilities.units.read       → list
 *   - facilities.bmg.transition   → start / finish / cancel
 *   - facilities.bmg.record_output → record output
 *
 * No record-level ownership (any operator with the permission may
 * act on any unit). The default `canOnRecord() === true` is in effect.
 */
final class BmgPolicy extends BasePolicy
{
    public function check(string $action, mixed $record = null): void
    {
        $code = match ($action) {
            'list'             => 'facilities.units.read',
            'manage_units'     => 'facilities.units.manage',
            'start'            => 'facilities.bmg.transition',
            'record_output'    => 'facilities.bmg.record_output',
            'finish'           => 'facilities.bmg.transition',
            'cancel'           => 'facilities.bmg.transition',
            'logs_read'        => 'facilities.bmg.logs.read',
            'logs_record'      => 'facilities.bmg.logs.record',
            'categories_manage' => 'facilities.categories.manage',
            'io_record'        => 'facilities.bmg.io.record',
            'analytics'        => 'facilities.units.read',
            default            => null,
        };
        if ($code === null) {
            $this->deny('rbac.facilities.forbidden');
        }
        $this->enforce($code, $action, $record);
    }
}