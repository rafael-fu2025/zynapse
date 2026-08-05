<?php

declare(strict_types=1);

namespace Modules\Clinic\DTOs;

use App\Modules\Shared\BaseDTO;

final class InventoryItemDto extends BaseDTO
{
    /**
     * @param array{reason_code: string, qty_delta: int, created_at: string, user_email: ?string}|null $lastMovement
     *        Most recent movement for this item, or null when the item
     *        has none yet — powers the row-level "last move" hint.
     */
    public function __construct(
        private readonly array $row,
        private readonly ?array $lastMovement = null,
    ) {}

    public static function fromRow(array $row, ?array $lastMovement = null): self
    {
        return new self($row, $lastMovement);
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
            'last_movement'    => $this->lastMovement === null
                ? null
                : [
                    'reason_code' => (string) $this->lastMovement['reason_code'],
                    'qty_delta'   => (int)    $this->lastMovement['qty_delta'],
                    'created_at'  => (string) $this->lastMovement['created_at'],
                    'user_email'  => $this->lastMovement['user_email'] !== null
                        ? (string) $this->lastMovement['user_email']
                        : null,
                ],
        ];
    }
}
