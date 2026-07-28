<?php

declare(strict_types=1);

namespace Modules\Clinic\DTOs;

use App\Modules\Shared\BaseDTO;

final class MedicineBatchDto extends BaseDTO
{
    public function __construct(private readonly array $row) {}

    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    public function jsonSerialize(): array
    {
        return [
            'id'                 => (int)    $this->row['id'],
            'medicine_id'        => (int)    $this->row['medicine_id'],
            'batch_number'       => (string) $this->row['batch_number'],
            'quantity_received'  => (int)    $this->row['quantity_received'],
            'quantity_remaining' => (int)    $this->row['quantity_remaining'],
            'expiration_date'    => (string) $this->row['expiration_date'],
            'received_date'      => (string) $this->row['received_date'],
            'supplier'           => $this->row['supplier'] !== null ? (string) $this->row['supplier'] : null,
            'status'             => (string) $this->row['status'],
            'created_at'         => (string) $this->row['created_at'],
        ];
    }
}
