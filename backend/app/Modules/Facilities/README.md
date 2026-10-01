# Module: Facilities

Bio-Medical Generator (BMG) state machine.

## Lifecycle

```
Idle → Processing → AwaitingOutput → Released
          │                │              ▲
          └────────────────┴──────────────┘
                    Cancelled
```

`finishBatch` collapses the run from either active state and is the single
operator-facing terminal transition; it stamps both `finished_at` and
`released_at`. `releaseBatch` is a backend-only variant that also lands on
`released` but does NOT stamp `finished_at` — which matters, because
`BmgSupport::categoryDurationStats` filters on `finished_at IS NOT NULL`, so
release-only batches contribute nothing to the historical duration averages.

The `curing` state was retired on 2026-09-29 (migration
`RemoveBmgCuringState`). Rows parked in it were mapped back to
`awaiting_output`. Historical `curing` rows in the append-only
`facilities_bmg_batch_updates` ledger are deliberately left intact.

## DB-level invariants

- `facilities_bmg_batches.active_unit_id` is a generated column populated ONLY when `status IN ('processing','awaiting_output')`. UNIQUE index ⇒ at most one unfinished batch per unit.
- Triggers `trg_bmg_batches_mass_invariant_ins` / `_upd` enforce `output_weight_kg <= total_input_weight_kg`.

## Endpoints

| Method | Path                                          | Permission                          |
| ------ | --------------------------------------------- | ----------------------------------- |
| GET    | `/api/v1/facilities/units`                    | `facilities.units.read`             |
| POST   | `/api/v1/facilities/units/{id}/start`         | `facilities.bmg.transition`         |
| POST   | `/api/v1/facilities/batches/{id}/output`      | `facilities.bmg.record_output`      |
| POST   | `/api/v1/facilities/batches/{id}/update`      | `facilities.bmg.transition`         |
| POST   | `/api/v1/facilities/batches/{id}/finish`      | `facilities.bmg.transition`         |
| POST   | `/api/v1/facilities/batches/{id}/cancel`      | `facilities.bmg.transition`         |

See `Routes.php` for the full surface (alerts, devices, waste categories,
analytics, process logs, structured I/O).

## Concurrency

Every state-changing service method:

1. `lockForUpdate()` on the unit/batch row.
2. Validates the requested transition.
3. Inserts audit row in the same transaction.

Parallel attempts to start a batch on the same unit fail with `1062 Duplicate entry` (the UNIQUE index on `active_unit_id`).