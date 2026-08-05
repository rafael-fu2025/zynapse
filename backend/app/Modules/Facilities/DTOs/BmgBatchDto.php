<?php

declare(strict_types=1);

namespace Modules\Facilities\DTOs;

use App\Modules\Shared\BaseDTO;

final class BmgBatchDto extends BaseDTO
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
            'id'                  => (int)    $this->row['id'],
            'unit_id'             => (int)    $this->row['unit_id'],
            'reference_code'      => (string) $this->row['reference_code'],
            'status'              => (string) $this->row['status'],
            'total_input_weight_kg' => (float) $this->row['total_input_weight_kg'],
            'output_weight_kg'      => $this->row['output_weight_kg'] !== null ? (float) $this->row['output_weight_kg'] : null,
            'total_loss_kg'         => isset($this->row['total_loss_kg']) && $this->row['total_loss_kg'] !== null
                ? (float) $this->row['total_loss_kg']
                : 0.0,
            'accumulated_in_process_kg' => isset($this->row['accumulated_in_process_kg']) && $this->row['accumulated_in_process_kg'] !== null
                ? (float) $this->row['accumulated_in_process_kg']
                : null,
            // MySQL JSON columns come back as strings — decode so the API
            // contract carries structured arrays, not double-encoded JSON.
            'input_items'         => is_string($this->row['input_items'])
                ? json_decode($this->row['input_items'], true)
                : $this->row['input_items'],
            'output_items'        => is_string($this->row['output_items'] ?? null)
                ? json_decode((string) $this->row['output_items'], true)
                : $this->row['output_items'],
            'started_at'          => (string) $this->row['started_at'],
            'awaiting_output_at'  => $this->row['awaiting_output_at'] !== null ? (string) $this->row['awaiting_output_at'] : null,
            'finished_at'         => $this->row['finished_at'] !== null ? (string) $this->row['finished_at'] : null,
            'cancelled_at'        => $this->row['cancelled_at'] !== null ? (string) $this->row['cancelled_at'] : null,
            'released_at'         => isset($this->row['released_at']) && $this->row['released_at'] !== null ? (string) $this->row['released_at'] : null,
            'released_by_user_id' => isset($this->row['released_by_user_id']) && $this->row['released_by_user_id'] !== null
                ? (int) $this->row['released_by_user_id']
                : null,
            'quality_grade'       => isset($this->row['quality_grade']) && $this->row['quality_grade'] !== null
                ? (string) $this->row['quality_grade']
                : null,
            'maturity_level'      => isset($this->row['maturity_level']) && $this->row['maturity_level'] !== null
                ? (string) $this->row['maturity_level']
                : null,
        ];
    }
}