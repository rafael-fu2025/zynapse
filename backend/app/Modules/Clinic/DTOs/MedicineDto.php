<?php

declare(strict_types=1);

namespace Modules\Clinic\DTOs;

use App\Modules\Shared\BaseDTO;

final class MedicineDto extends BaseDTO
{
    /**
     * @param array<int, array<string, mixed>> $batches
     * @param array{type: string, quantity: int, created_at: string, user_email: ?string}|null $lastMovement
     *        Most recent `clinic_medicine_transactions` row for this medicine,
     *        joined to `users.email`. Null when the medicine has no
     *        movements yet (just-created) — Gap 13 powers the row-level
     *        "last move" hint from this.
     */
    public function __construct(
        private readonly array $row,
        private readonly int $onHand,
        private readonly ?string $earliestExpiry,
        private readonly ?array $lastMovement = null,
        private readonly ?array $batches = null,
    ) {}

    public static function fromRow(
        array $row,
        int $onHand,
        ?string $earliestExpiry,
        ?array $lastMovement = null,
        ?array $batches = null,
    ): self {
        return new self($row, $onHand, $earliestExpiry, $lastMovement, $batches);
    }

    public function jsonSerialize(): array
    {
        $out = [
            'id'                => (int)    $this->row['id'],
            'generic_name'      => (string) $this->row['generic_name'],
            'brand_name'        => $this->row['brand_name'] !== null ? (string) $this->row['brand_name'] : null,
            'category'          => $this->row['category'] !== null ? (string) $this->row['category'] : null,
            'dosage_form'       => $this->row['dosage_form'] !== null ? (string) $this->row['dosage_form'] : null,
            'dosage_strength'   => $this->row['dosage_strength'] !== null ? (string) $this->row['dosage_strength'] : null,
            'unit'              => (string) $this->row['unit'],
            'reorder_threshold' => (int)    $this->row['reorder_threshold'],
            'description'       => $this->row['description'] !== null ? (string) $this->row['description'] : null,
            'quantity_on_hand'  => $this->onHand,
            'low_stock'         => $this->onHand <= (int) $this->row['reorder_threshold'],
            'earliest_expiry'   => $this->earliestExpiry,
            'archived'          => $this->row['archived_at'] !== null,
            'created_at'        => (string) $this->row['created_at'],
            // Gap 13 — always present (null when no movements yet) so the
            // frontend can render a placeholder without a special case.
            'last_movement'     => $this->lastMovement === null
                ? null
                : [
                    'type'       => (string) $this->lastMovement['type'],
                    'quantity'   => (int)    $this->lastMovement['quantity'],
                    'created_at' => (string) $this->lastMovement['created_at'],
                    'user_email' => $this->lastMovement['user_email'] !== null
                        ? (string) $this->lastMovement['user_email']
                        : null,
                ],
        ];

        if ($this->batches !== null) {
            $out['batches'] = array_map(
                static fn (array $b) => MedicineBatchDto::fromRow($b)->toArray(),
                $this->batches,
            );
        }

        return $out;
    }
}
