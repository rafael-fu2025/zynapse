# Module: Clinic

Clinical encounters and vitals. **Strict isolation** — no FK to `counselling_*`. Cross-module communication flows only through `referral_referrals`.

## Tables

- `clinic_encounters` — encounter rows keyed by `patient_school_id` + `attending_user_id`.
- `clinic_vitals` — child rows. Cascade on encounter delete (soft via `archived_at`).

## Endpoints (all under `api_auth`)

| Method | Path                                   | Permission                  |
| ------ | -------------------------------------- | --------------------------- |
| GET    | `/api/v1/clinic/encounters`            | `clinic.encounters.read`    |
| POST   | `/api/v1/clinic/encounters`            | `clinic.encounters.create`  |
| POST   | `/api/v1/clinic/encounters/{id}/vitals`| `clinic.encounters.write`   |
| POST   | `/api/v1/clinic/encounters/{id}/close` | `clinic.encounters.write`   |

## Service

`Modules\Clinic\Services\ClinicService` — `listEncounters`, `createEncounter`, `recordVitals`, `closeEncounter`. All writes wrapped in `transStart()` with `lockForUpdate()` on the encounter row. Audit outbox in the same transaction.

## Out of scope

- Cross-module query of `counselling_*`. Use `/referrals`.
- Bulk import (Phase 5+).