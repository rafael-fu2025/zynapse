<?php

declare(strict_types=1);

namespace Modules\Clinic\DTOs;

use App\Modules\Shared\BaseDTO;

final class VitalsDto extends BaseDTO
{
    public function __construct(private readonly array $row) {}

    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    public function jsonSerialize(): array
    {
        return [
            'encounter_id'       => (int) $this->row['encounter_id'],
            'bp_systolic'        => $this->row['bp_systolic']    !== null ? (int) $this->row['bp_systolic']    : null,
            'bp_diastolic'       => $this->row['bp_diastolic']   !== null ? (int) $this->row['bp_diastolic']   : null,
            'pulse_bpm'          => $this->row['pulse_bpm']      !== null ? (int) $this->row['pulse_bpm']      : null,
            'temp_c'             => $this->row['temp_c']         !== null ? (float) $this->row['temp_c']       : null,
            'spo2_pct'           => $this->row['spo2_pct']       !== null ? (int) $this->row['spo2_pct']       : null,
            'weight_kg'          => $this->row['weight_kg']      !== null ? (float) $this->row['weight_kg']    : null,
            'height_cm'          => $this->row['height_cm']      !== null ? (float) $this->row['height_cm']    : null,
            'recorded_at'        => (string) $this->row['recorded_at'],
        ];
    }
}