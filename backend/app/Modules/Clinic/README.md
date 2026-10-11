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

## Booking and manual attendance

Staff scheduling and patient booking (`bookSelf`, `/api/v1/me/appointments`, and the student portal) retain their existing payloads except for the removed `qr_token` response field. Appointment QR issuance and public verification endpoints no longer exist; no appointment HMAC key is required.

Staff can still confirm and check in appointments. Check-in opens the linked encounter and enqueues the patient for the Manila day in the same transaction; encounter completion/no-show continues to cascade to the appointment and queue. Tenancy, RBAC, clash checks, and notification/audit outboxes are unchanged.

RFID registry input, lookup, and `has_rfid` badges are retired. Patient QR identity (`qr_code` and `has_qr`) is retained. New append-only migrations remove `clinic_appointments.qr_token_hash` and `users.rfid_tag` and their indexes; rollback recreates empty fields, not lost data.

## Out of scope

- Cross-module query of `counselling_*`. Use `/referrals`.
- Bulk import (Phase 5+).