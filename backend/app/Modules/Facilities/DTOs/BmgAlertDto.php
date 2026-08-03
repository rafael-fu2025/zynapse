<?php

declare(strict_types=1);

namespace Modules\Facilities\DTOs;

use App\Modules\Shared\BaseDTO;

/**
 * BmgAlertDto — alert row surfaced by `BmgAlertEngine`.
 *
 * Codes are stable identifiers so the frontend can map them to
 * localised messages without parsing prose. Severity is one of
 * `info|warning|critical` (DB CHECK enforces the set).
 */
final class BmgAlertDto extends BaseDTO
{
    /**
     * @param array<string, mixed> $row
     */
    public function __construct(private readonly array $row) {}

    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    public function jsonSerialize(): array
    {
        return [
            'id'                       => (int)    $this->row['id'],
            'batch_id'                 => (int)    $this->row['batch_id'],
            'code'                     => (string) $this->row['code'],
            'severity'                 => (string) $this->row['severity'],
            'message'                  => (string) $this->row['message'],
            'triggered_at'             => (string) $this->row['triggered_at'],
            'acknowledged_at'          => $this->row['acknowledged_at'] !== null ? (string) $this->row['acknowledged_at'] : null,
            'acknowledged_by_user_id'  => $this->row['acknowledged_by_user_id'] !== null ? (int) $this->row['acknowledged_by_user_id'] : null,
        ];
    }
}
