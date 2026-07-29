<?php

declare(strict_types=1);

namespace Modules\Referrals\Policies;

use App\Modules\Shared\BasePolicy;

/**
 * ReferralPolicy — gates the bridge contract.
 *
 * Module-level permissions:
 *   - referrals.read         → list / view
 *   - referrals.create       → create
 *   - referrals.acknowledge  → acknowledge
 *   - referrals.review       → review
 *   - referrals.close        → close
 *   - referrals.issue_qr     → issue QR token
 *
 * No record-level ownership. Issuers can acknowledge / review their
 * own referrals without a separate permission today; that's a future
 * product decision. The default `canOnRecord() === true` is in effect.
 */
final class ReferralPolicy extends BasePolicy
{
    public function check(string $action, mixed $record = null): void
    {
        $code = match ($action) {
            'list'        => 'referrals.read',
            'create'      => 'referrals.create',
            'view'        => 'referrals.read',
            'acknowledge' => 'referrals.acknowledge',
            'review'      => 'referrals.review',
            'close'       => 'referrals.close',
            'issueQr'     => 'referrals.issue_qr',
            default       => null,
        };
        if ($code === null) {
            $this->deny('rbac.referrals.forbidden');
        }
        $this->enforce($code, $action, $record);
    }

    /**
     * Receiving-side gate (RBAC_SECURITY_REVIEW R6): acknowledge / review
     * / close may only be performed by a user who belongs to the
     * referral's TARGET module — a clinic->counselling referral is
     * actioned by counselling staff, a counselling->clinic referral by
     * clinic staff. The discriminating codes are chosen so a bare admin
     * (wildcard) still qualifies for oversight, while clinic_staff and
     * counsellor cannot act on the wrong side.
     */
    public function checkReceivingSide(string $targetModule): void
    {
        $ok = match ($targetModule) {
            'clinic'      => $this->can('clinic.encounters.read'),
            'counselling' => $this->can('counselling.records.read')
                || $this->can('counselling.schedule.read'),
            default       => false,
        };

        if (! $ok) {
            $this->deny('rbac.referrals.wrong_side');
        }
    }
}