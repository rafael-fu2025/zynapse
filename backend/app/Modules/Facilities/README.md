# Module: Facilities

Bio-Medical Generator (BMG) state machine.

## Lifecycle

```
Idle → Processing → AwaitingOutput → Idle
                       └────────────→ Cancelled
```

## DB-level invariants

- `facilities_bmg_batches.active_unit_id` is a generated column populated ONLY when `status IN ('Processing','AwaitingOutput')`. UNIQUE index ⇒ at most one unfinished batch per unit.
- Triggers `trg_bmg_batches_mass_invariant_ins` / `_upd` enforce `output_weight_kg <= total_input_weight_kg`.

## Endpoints

| Method | Path                                          | Permission                          |
| ------ | --------------------------------------------- | ----------------------------------- |
| GET    | `/api/v1/facilities/units`                    | `facilities.units.read`             |
| POST   | `/api/v1/facilities/units/{id}/start`         | `facilities.bmg.transition`         |
| POST   | `/api/v1/facilities/batches/{id}/output`      | `facilities.bmg.record_output`      |
| POST   | `/api/v1/facilities/batches/{id}/finish`      | `facilities.bmg.transition`         |
| POST   | `/api/v1/facilities/batches/{id}/cancel`      | `facilities.bmg.transition`         |

## Concurrency

Every state-changing service method:

1. `lockForUpdate()` on the unit/batch row.
2. Validates the requested transition.
3. Inserts audit row in the same transaction.

Parallel attempts to start a batch on the same unit fail with `1062 Duplicate entry` (the UNIQUE index on `active_unit_id`).