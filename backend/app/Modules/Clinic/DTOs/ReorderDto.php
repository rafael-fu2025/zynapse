<?php

declare(strict_types=1);

namespace Modules\Clinic\DTOs;

use App\Modules\Shared\BaseDTO;

final class ReorderDto extends BaseDTO
{
    public function __construct(private readonly array $row) {}

    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    public function jsonSerialize(): array
    {
        return [
            'id'                     => (int)    $this->row['id'],
            'item_type'              => (string) ($this->row['item_type'] ?? 'medicine'),
            'medicine_id'            => isset($this->row['medicine_id']) && $this->row['medicine_id'] !== null ? (int) $this->row['medicine_id'] : null,
            'supply_item_id'         => isset($this->row['supply_item_id']) && $this->row['supply_item_id'] !== null ? (int) $this->row['supply_item_id'] : null,
            'generic_name'           => isset($this->row['generic_name']) ? (string) $this->row['generic_name'] : null,
            'item_name'              => isset($this->row['item_name']) ? (string) $this->row['item_name'] : null,
            'unit'                   => isset($this->row['unit']) ? (string) $this->row['unit'] : null,
            'requested_quantity'     => (int)    $this->row['requested_quantity'],
            'current_stock'          => (int)    $this->row['current_stock'],
            'reorder_level'          => (int)    $this->row['reorder_level'],
            'urgency'                => (string) $this->row['urgency'],
            'status'                 => (string) $this->row['status'],
            'auto_triggered'         => (bool)   $this->row['auto_triggered'],
            'procurement_note'       => $this->row['procurement_note'] !== null ? (string) $this->row['procurement_note'] : null,
            'order_date'             => $this->row['order_date'] !== null ? (string) $this->row['order_date'] : null,
            'expected_delivery_date' => $this->row['expected_delivery_date'] !== null ? (string) $this->row['expected_delivery_date'] : null,
            'actual_delivery_date'   => $this->row['actual_delivery_date'] !== null ? (string) $this->row['actual_delivery_date'] : null,
            'fulfilled_at'           => isset($this->row['fulfilled_at']) && $this->row['fulfilled_at'] !== null ? (string) $this->row['fulfilled_at'] : null,
            'created_at'             => (string) $this->row['created_at'],
        ];
    }
}
