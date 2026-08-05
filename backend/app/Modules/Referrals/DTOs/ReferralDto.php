<?php

declare(strict_types=1);

namespace Modules\Referrals\DTOs;

use App\Modules\Shared\BaseDTO;

final class ReferralDto extends BaseDTO
{
    public function __construct(private readonly array $row) {}

    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    public function jsonSerialize(): array
    {
        return [
            'id'                => (int)    $this->row['id'],
            'patient_school_id' => (string) $this->row['patient_school_id'],
            'source_module'     => (string) $this->row['source_module'],
            'target_module'     => (string) $this->row['target_module'],
            'artifact_type'     => (string) $this->row['artifact_type'],
            'status'            => (string) $this->row['status'],
            'reason_code'       => $this->row['reason_code'] !== null ? (string) $this->row['reason_code'] : null,
            // Handling provider (nurse / counsellor) — panel revision.
            // NULL until the receiving side acknowledges the referral.
            'provider_user_id'  => ($this->row['provider_user_id'] ?? null) !== null ? (int) $this->row['provider_user_id'] : null,
            'provider_name'     => ($this->row['provider_name'] ?? null) !== null ? (string) $this->row['provider_name'] : null,
            'created_at'        => (string) $this->row['created_at'],
            'updated_at'        => (string) $this->row['updated_at'],
            'qr_expires_at'     => $this->row['qr_expires_at'] !== null ? (string) $this->row['qr_expires_at'] : null,
            'qr_revoked_at'     => ($this->row['qr_revoked_at'] ?? null) !== null ? (string) $this->row['qr_revoked_at'] : null,
        ];
    }
}