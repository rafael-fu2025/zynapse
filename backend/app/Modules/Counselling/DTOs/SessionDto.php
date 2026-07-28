<?php

declare(strict_types=1);

namespace Modules\Counselling\DTOs;

use App\Modules\Shared\BaseDTO;

final class SessionDto extends BaseDTO
{
    public function __construct(private readonly array $row) {}

    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    public function jsonSerialize(): array
    {
        return [
            'id'                  => (int)    $this->row['id'],
            'patient_school_id'   => (string) $this->row['patient_school_id'],
            'counsellor_user_id'  => (int)    $this->row['counsellor_user_id'],
            'started_at'          => (string) $this->row['started_at'],
            'ended_at'            => $this->row['ended_at'] !== null ? (string) $this->row['ended_at'] : null,
        ];
    }
}