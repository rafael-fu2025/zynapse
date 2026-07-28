<?php

declare(strict_types=1);

namespace Modules\Facilities\DTOs;

use App\Modules\Shared\BaseDTO;

final class BmgUnitDto extends BaseDTO
{
    /**
     * @param array{id:int,code:string,display_name:string,status:string,location_code:?string,spec_capacity_kg:?float,default_category_id?:?int,default_category_name?:?string,notes?:?string,created_at:string,updated_at?:string,archived_at?:?string,active_batch_id?:?int} $row
     */
    public function __construct(private readonly array $row) {}

    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    public function jsonSerialize(): array
    {
        return [
            'id'                     => (int)    $this->row['id'],
            'code'                   => (string) $this->row['code'],
            'display_name'           => (string) $this->row['display_name'],
            'status'                 => (string) $this->row['status'],
            'location_code'          => $this->row['location_code'] !== null ? (string) $this->row['location_code'] : null,
            'spec_capacity_kg'       => $this->row['spec_capacity_kg'] !== null ? (float) $this->row['spec_capacity_kg'] : null,
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
        ];
    }
}