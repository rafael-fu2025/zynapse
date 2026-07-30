<?php

declare(strict_types=1);

namespace Modules\Clinic\DTOs;

use App\Modules\Shared\BaseDTO;

final class InventoryItemDto extends BaseDTO
{
    public function __construct(private readonly array $row) {}

    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    public function jsonSerialize(): array
    {
        return [
            'id'               => (int)    $this->row['id'],
            'sku'              => (string) $this->row['sku'],
            'name'             => (string) $this->row['name'],
            'unit'             => (string) $this->row['unit'],
            'quantity_on_hand' => (int)    $this->row['quantity_on_hand'],
            'reorder_level'    => (int)    $this->row['reorder_level'],
            'low_stock'        => (int) $this->row['quantity_on_hand'] <= (int) $this->row['reorder_level'],
            'archived'         => ($this->row['archived_at'] ?? null) !== null,
            'created_at'       => (string) $this->row['created_at'],
        ];
    }
}
