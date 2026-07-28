# SYNAPSE — Phase 8: Inventory, Appointments & Notifications

**Status:** Delivered and runtime-verified (HTTP + CLI + browser E2E).

## 1. Clinic Inventory (dangling permissions now real)

- Migration `2026-01-08-000010_ClinicInventory`: `clinic_inventory_items` (catalog + on-hand) and `clinic_inventory_movements` (append-only signed ledger; corrections are new `adjustment` rows — never UPDATE/DELETE).
- `InventoryService`: `moveStock()` locks the item row (`selectForUpdate`), asserts `quantity_on_hand >= 0`, appends the ledger row and the audit outbox row in the SAME transaction.
- Endpoints (`clinic.inventory.read` / `clinic.inventory.write`):
  | Method | Path | |
  |---|---|---|
  | GET | `/api/v1/clinic/inventory` | keyset list, `low_stock` flag |
  | POST | `/api/v1/clinic/inventory` | create item (unique SKU → 409) |
  | POST | `/api/v1/clinic/inventory/{id}/move` | signed movement; sign must match `reason_code` |
- **Verified live:** create `PARA-500` → receive +100 → dispense −30 (70 on hand) → over-dispense rejected `409 statemachine.inventory.negative_stock`.

## 2. Clinic Appointments

- Migration `2026-01-09-000010_ClinicAppointments`; lifecycle `Scheduled → CheckedIn → Completed`, with `Cancelled` / `NoShow` exits; UTC persistence.
- `AppointmentService.schedule()` writes the appointment, the audit row AND the provider's notification outbox row in one transaction; `transition()` enforces the state map under row lock.
- Endpoints (`clinic.appointments.read` / `clinic.appointments.write`): `GET/POST /api/v1/clinic/appointments`, `POST /api/v1/clinic/appointments/{id}/transition`.
- **Verified live:** schedule → 201; `CheckedIn` transition OK; `NoShow` after `CheckedIn` rejected 409.

## 3. Notifications outbox (directive: "Outbox for Audit AND Notifications")

- Migration `2026-01-09-000020_NotificationOutbox`: `notification_outbox` (same-transaction writer) + `notifications` (in-app landing zone).
- `NotificationOutboxService::enqueue()` — whitelisted context (`resource_code`, `next_status`, `scheduled_at`); never PII.
- `php spark synapse:notify-drain` — batch transaction, row locking, poison-row ejection (mirrors the audit drain).
- Self-scoped reader (`notifications.read`): `GET /api/v1/notifications`, `POST /api/v1/notifications/{id}/read` — recipient is ALWAYS the token's user.
- **Verified live:** scheduling an appointment enqueued `appointment.assigned`; drain delivered it; list + mark-read round-tripped.

## 4. Frontend fix caught by E2E

Dashboard module cards were plain `<a href>` — a full reload drops the in-memory access token (by design tokens never touch localStorage), silently logging users out on every module click. Replaced with React Router `<Link>`. Playwright suite now runs serially (`workers: 1`) because live-stack tests share the dev account and rate-limit buckets — **4/4 pass**.

## Verification summary (this phase)

| Gate | Result |
|---|---|
| `php -l` all touched files | clean |
| `php spark migrate -n App` | 3 new migrations green |
| Seeders (idempotent re-run) | new perms granted |
| `composer test` | 21/21 |
| Playwright (`SYNAPSE_E2E=1`) | 4/4 |
| Audit chain after all writes | `synapse:audit-verify` — intact @ 50 events |

## 5. SPA pages (Phase 9 increment)

- **InventoryPage** (`/inventory`, gated by `clinic.inventory.read`): keyset table with low-stock badges, RHF+Zod dialogs for item creation and signed stock movements (client-side sign/reason coherence mirrors the backend rule).
- **AppointmentsPage** (`/appointments`, gated by `clinic.appointments.read`): status-badged grid with lifecycle actions (Check in / Complete / Cancel / No-show), scheduling dialog.
- Dashboard cards added for both; routes wired in `router.tsx`.
- **Frontend now type-checks (`tsc --noEmit` clean) for the first time** — fixed pre-existing errors: TanStack v4 `keepPreviousData` → v5 `placeholderData`, untyped optimistic-mutation contexts in `useFacilities`, vite config (`@types/node`, invalid rollup `treeshake` option).
- **Timezone bug fixed**: MySQL `YYYY-MM-DD HH:mm:ss` strings carry no zone designator, so `parseISO` read them as LOCAL time — every rendered timestamp was skewed. `utils/date.ts` now normalizes to explicit UTC before converting to Asia/Manila (verified in the appointments screenshot: 02:00 UTC → 10:00 GMT+8).
- E2E extended: full-flow now covers login → dashboard → clinic → audit → **inventory** → **appointments** with screenshots (`e2e/artifacts/01–06`). **4/4 pass.**

## Remaining (Phase 10+ candidates)

1. User management CRUD + password reset flow.
2. Clinic bulk import.
3. Notification bell / unread badge in the SPA layout.
