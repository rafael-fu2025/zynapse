<?php

declare(strict_types=1);

namespace Modules\Clinic\DTOs;

use App\Modules\Shared\BaseDTO;

final class MedicineDto extends BaseDTO
{
    /**
     * @param array<int, array<string, mixed>> $batches
     */
    public function __construct(
        private readonly array $row,
        private readonly int $onHand,
        private readonly ?string $earliestExpiry,
        private readonly ?array $batches = null,
    ) {}

    public static function fromRow(array $row, int $onHand, ?string $earliestExpiry, ?array $batches = null): self
    {
        return new self($row, $onHand, $earliestExpiry, $batches);
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
            'quantity_on_hand'  => $this->onHand,
            'low_stock'         => $this->onHand <= (int) $this->row['reorder_threshold'],
            'earliest_expiry'   => $this->earliestExpiry,
            'archived'          => $this->row['archived_at'] !== null,
            'created_at'        => (string) $this->row['created_at'],
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
