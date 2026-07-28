# SYNAPSE — Phase 10: Appointment Name Resolution + Demo Seed Sweep

**Status:** Delivered and runtime-verified (HTTP + DB inspection). Picks up where PHASE9 left off and tightens the demo dataset for every module so first-run exploration never lands on an empty page.

---

## 1. Appointment name resolution

The `AppointmentDto` now carries three new fields resolved server-side at every return point:

- `patient_name` — `students.first_name middle_initial. last_name` (or employee equivalent); falls back to `null` if the patient row is missing or archived.
- `patient_kind` — `'student' | 'employee' | null` so the UI can render a kind badge next to the name.
- `provider_name` — `users.username` (e.g. `nurse-jane`, `synapse-admin`); falls back to `null` if the user row was archived.

### Implementation

- **`App\Modules\Clinic\DTOs\AppointmentDto`** — added a `withNames(array)` step that decorates the DTO. `jsonSerialize()` defaults the new fields to `null` so the type stays backwards-compatible.
- **`App\Modules\Clinic\Services\AppointmentService`** — new private `decorate(array $rows)` helper runs three small `whereIn` queries (students, employees, users) to resolve all names in one batch, and is invoked from `list()`, `schedule()`, `transition()`, `show()`, and `update()` (both the no-op and the real-update paths). A new `composeName()` helper formats Filipino-style display names (`Juan D. Cruz`).
- **`App\Modules\Clinic\Frontend\schemas\appointments.ts`** — `appointmentSchema` accepts the three new optional fields; old clients ignore them safely.
- **`App\Modules\Clinic\Frontend\pages\AppointmentsPage.tsx`** — the table row and the detail dialog now render the name with a muted fallback to the raw school id and a `student` / `employee` badge. The provider cell prefers the new `provider_name` first and falls back to the cached employees lookup.

### Verified live

```
A#14 patient=Quinn T. Aguilar (student) prov=synapse-admin :: Completed
A#13 patient=Paolo R. Castro (student) prov=nurse-jane :: NoShow
A#12 patient=Olivia G. Marquez (student) prov=nurse-jane :: Cancelled
A#11 patient=Nathan P. Villanueva (student) prov=nurse-jane :: Completed
SHOW #3 patient=Frances R. Bautista (student) prov=nurse-jane
```

---

## 2. Demo seed sweep

Three new seeders fill the previously empty tables so every SPA module shows real content on first run. All refuse to run in production (`ENVIRONMENT === 'production'`) and are idempotent (wipe + re-seed).

### `App\Database\Seeds\CounsellingSeeder`

- 10 availability windows (Mon-Fri, 09:00-12:00 + 13:00-16:00, max 2 slots each)
- 8 counselling appointments spread across the previous 7 days, today, and the next 4 days, covering all 5 enum statuses (scheduled, confirmed, completed, cancelled, no_show)
- 2 sessions — 1 closed with encrypted notes (AES-256-GCM through `EncryptionService`), 1 still open
- 2 `counselling_scheduling_analytics` rows showing realistic no-show rates so the analytics view renders without a recompute round-trip

### `App\Database\Seeds\ReferralsSeeder`

- 5 referrals across the 4 lifecycle states:
  - 1 Closed (clinic → counselling, full lifecycle, 7d ago)
  - 1 UnderReview (counselling → clinic, midway, 3d ago)
  - 1 Acknowledged (clinic → counselling, just acknowledged, 1d ago)
  - 1 Submitted (counselling → clinic, fresh)
  - 1 Submitted with a QR-issued valid HMAC token (clinic → counselling)
- Notes encrypted through `EncryptionService`; the demo QR token is printed to stdout (DEV ONLY).

### `App\Database\Seeds\InventoryItemsSeeder`

- 10 realistic school-clinic consumables (gloves, masks, ORS, paracetamol, bandages, alcohol, gauze, BP cuff batteries, thermometer covers, cold packs)
- 2 movements per item (1 initial `receive` 14 days ago + 1 recent `dispense`) so the stock-history graph has data
- Reorder levels are tuned so **3 items sit below the reorder level**, exercising the `low-stock` filter and reorder auto-check

### Re-run order

```bash
php spark db:seed PatientRegistrySeeder
php spark db:seed AppointmentsSeeder
php spark db:seed CounsellingSeeder
php spark db:seed ReferralsSeeder
php spark db:seed InventoryItemsSeeder
php spark db:seed FacilitiesSeeder
```

`FacilitiesSeeder` and the user/group seeders (`DevUserSeeder`, `PermissionsAndGroupsSeeder`, `SeedDemoUsersSeeder`) were unchanged but should be run first since the new seeders depend on their data.

### Verified live

```
  Appointments                      12 rows
  Employees (teaching)              21 rows
  Counselling appts                  8 rows
  Counselling avail                 10 rows
  Counselling sessions               2 rows
  Counselling analytics              2 rows
  Referrals                          5 rows
  Inventory                         10 rows (3 below reorder level)
  Medicines                          3 rows
```

Plus the public QR verify endpoint confirms a freshly issued demo token:

```json
{
  "status": "Valid",
  "artifact_type": "intake_pass",
  "issuer": "nurse-jane"
}
```

---

## 3. Quality gates

- `vendor/bin/phpunit` — **74/74 tests passing** (129 assertions)
- `npm run typecheck` — clean
- `npm run lint` — clean
- All seeders are idempotent; safe to re-run after schema changes.
