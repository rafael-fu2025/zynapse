<?php

declare(strict_types=1);

namespace Modules\Facilities\DTOs;

use App\Modules\Shared\BaseDTO;

final class BmgUnitDto extends BaseDTO
{
    /**
     * @param array{id:int,code:string,display_name:string,status:string,location_code:?string,spec_capacity_kg:?float,drum_one_capacity_kg?:?float,drum_two_capacity_kg?:?float,default_category_id?:?int,default_category_name?:?string,categories?:list<array{id:int,name:string}>,notes?:?string,created_at:string,updated_at?:string,archived_at?:?string,active_batch_id?:?int,active_batch_weight_kg?:?float,device_id?:?int,device_code?:?string,device_display_name?:?string,device_status?:?string,device_last_seen_at?:?string} $row
     */
    public function __construct(private readonly array $row) {}

    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    public function jsonSerialize(): array
    {
        $capacity = $this->row['spec_capacity_kg'] !== null ? (float) $this->row['spec_capacity_kg'] : 0.0;
        $loaded   = isset($this->row['active_batch_weight_kg']) && $this->row['active_batch_weight_kg'] !== null
            ? (float) $this->row['active_batch_weight_kg'] : 0.0;

        return [
            'id'                     => (int)    $this->row['id'],
            'code'                   => (string) $this->row['code'],
            'display_name'           => (string) $this->row['display_name'],
            'status'                 => (string) $this->row['status'],
            'location_code'          => $this->row['location_code'] !== null ? (string) $this->row['location_code'] : null,
            'spec_capacity_kg'       => $this->row['spec_capacity_kg'] !== null ? (float) $this->row['spec_capacity_kg'] : null,
            // The two drums' individual capacities — the unit's rated
            // capacity above is their sum (set by the service, never by
            // the client). Null on legacy rows profiled before the split.
            'drum_one_capacity_kg'   => isset($this->row['drum_one_capacity_kg']) && $this->row['drum_one_capacity_kg'] !== null
                ? (float) $this->row['drum_one_capacity_kg'] : null,
            'drum_two_capacity_kg'   => isset($this->row['drum_two_capacity_kg']) && $this->row['drum_two_capacity_kg'] !== null
                ? (float) $this->row['drum_two_capacity_kg'] : null,
            // Waste categories the drum is designated for, configured at
            // drum setup; startBatch may only mix from this set.
            'categories'             => isset($this->row['categories']) && is_array($this->row['categories'])
                ? array_values(array_map(
                    static fn (array $c): array => ['id' => (int) $c['id'], 'name' => (string) $c['name']],
                    $this->row['categories'],
                ))
                : [],
            'default_category_id'    => isset($this->row['default_category_id']) && $this->row['default_category_id'] !== null
                ? (int) $this->row['default_category_id'] : null,
            'default_category_name'  => isset($this->row['default_category_name']) && $this->row['default_category_name'] !== null
                ? (string) $this->row['default_category_name'] : null,
            'notes'                  => isset($this->row['notes']) && $this->row['notes'] !== null
                ? (string) $this->row['notes'] : null,
            'created_at'             => (string) $this->row['created_at'],
            'updated_at'             => isset($this->row['updated_at']) && $this->row['updated_at'] !== null
                ? (string) $this->row['updated_at'] : null,
            'archived_at'            => isset($this->row['archived_at']) && $this->row['archived_at'] !== null
                ? (string) $this->row['archived_at'] : null,
            'active_batch_id'        => isset($this->row['active_batch_id']) && $this->row['active_batch_id'] !== null
                ? (int) $this->row['active_batch_id']
                : null,
            'active_batch_weight_kg' => $loaded > 0 ? $loaded : null,
            'active_batch_expected_completion_date' => isset($this->row['active_batch_expected_completion_date'])
                && $this->row['active_batch_expected_completion_date'] !== null
                ? (string) $this->row['active_batch_expected_completion_date']
                : null,
            'active_batch_progress_pct' => isset($this->row['active_batch_progress_pct'])
                && $this->row['active_batch_progress_pct'] !== null
                ? (int) $this->row['active_batch_progress_pct']
                : null,
            // Tier 2.8: how much of the drum's capacity is in use. Capped at
            // 100% — a drum can never legitimately exceed its rated capacity.
            'utilization_pct'        => $capacity > 0 ? min(100, (int) round(($loaded / $capacity) * 100)) : 0,
            // The integrated ESP32 (1:1 — see the uq_bmg_devices_unit
            // index). All keys nullable/optional so rows fetched without
            // the device join keep serializing unchanged.
            'device_id'              => isset($this->row['device_id']) && $this->row['device_id'] !== null
                ? (int) $this->row['device_id'] : null,
            'device_code'            => isset($this->row['device_code']) && $this->row['device_code'] !== null
                ? (string) $this->row['device_code'] : null,
            'device_display_name'    => isset($this->row['device_display_name']) && $this->row['device_display_name'] !== null
                ? (string) $this->row['device_display_name'] : null,
            'device_status'          => isset($this->row['device_status']) && $this->row['device_status'] !== null
                ? (string) $this->row['device_status'] : null,
            'device_last_seen_at'    => isset($this->row['device_last_seen_at']) && $this->row['device_last_seen_at'] !== null
                ? (string) $this->row['device_last_seen_at'] : null,
        ];
    }
}