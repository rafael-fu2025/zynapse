# SYNAPSE — Phase 9: User Management & Notification Bell

**Status:** Delivered and runtime-verified (HTTP + browser E2E). Continues the Phase 8 thread (this repo tags the docs by delivery, not sprint).

## 1. Administrative user management (`rbac.manage`)

`App\Services\Admin\UserAdminService` + `App\Controllers\Api\Admin\UserController`:

| Method | Path | Guard | Behaviour |
|---|---|---|---|
| GET | `/api/v1/admin/users` | `rbac.read` | keyset list with email + group codes |
| POST | `/api/v1/admin/users` | `rbac.manage` | create (12-char min password; unique email → 409) |
| POST | `/api/v1/admin/users/{id}/status` | `rbac.manage` | activate/deactivate |
| POST | `/api/v1/admin/users/{id}/groups` | `rbac.manage` | replace group membership |
| POST | `/api/v1/admin/users/{id}/reset-password` | `rbac.manage` | issue CSPRNG temp password |

Invariants honored:
- **No physical delete** — deactivation flips `users.active`/`status` only.
- Deactivation and password reset **revoke all refresh-token families** for the user.
- **Self-deactivation guard** — an admin cannot disable their own account (422).
- Audit context carries user ids + group codes; **never** emails or password material. The temp password is returned in the response body ONLY.

**Verified live:**
- Create `nurse@synapse.dev` (group `clinic_staff`) → user #2.
- Least-privilege proof: nurse reads `/clinic` (200) but is denied `/audit` (403) and `/admin/users` (403).
- Reset password → old password 401, temp password 200, `force_reset=true`.
- Deactivate → login 401; self-deactivate → 422.

### Fix caught during verification
CI4's `required` validation rule treats boolean `false` as empty, so `{"active": false}` was wrongly rejected. `setStatus` now validates the boolean by hand.

## 2. Notification bell (SPA)

- `NotificationBell` (Radix Popover, accessible, unread badge) in the app header; polls `/notifications` every 60 s.
- `useNotifications` / `useMarkNotificationRead` hooks; template `appointment.assigned` renders as a human label with its `resource_code`.
- Clicking an unread row calls `POST /notifications/{id}/read` and refetches.
- **Browser-verified**: the drained `appointment.assigned` row appears in the popover (`e2e/artifacts/07-notifications.png`).

## 3. Clinic bulk import (promised since Phase 5)

`POST /api/v1/clinic/encounters/import` — body `text/csv`, header exactly
`patient_school_id,chief_complaint`, hard cap 500 rows.

- Strictly **all-or-nothing**: every row is validated first; ALL failures are reported with 1-based row numbers (`import.row_invalid`, field `row_N`) before anything is written.
- Inserts run in one transaction with a single `clinic.encounters_imported` audit event (`rows#N`).
- **Verified live:** 3-row CSV → `{imported:3, first_id:2, last_id:4}`; malformed CSV → 422 listing row 2 (missing complaint) and row 3 (missing school id).

## 4. Admin Users SPA page

- `/admin/users` (gated `rbac.manage`) + "Users" dashboard card: grid with email/username/group chips/Active-Disabled badges; create dialog (RHF+Zod, group checkboxes); activate/deactivate (self-row disabled); password reset showing the temp password ONCE in a dialog.
- **Browser-verified** (`e2e/artifacts/08-admin-users.png`): nurse #2 Disabled with `clinic_staff` chip, admin #1 Active.

## 5. `force_reset` UX — forced password rotation (Phase 10 closeout)

- Backend: `POST /api/v1/auth/change-password` (re-verifies the current password, min 12 for the new one, clears `force_reset`, revokes ALL refresh families, returns a fresh token pair). `/auth/me` now exposes `force_reset`. Audit: `auth.password_changed` / `auth.password_change_failed` (no PII).
- SPA: `/change-password` page (RHF+Zod with confirm field); the Layout locks EVERY route to it while `force_reset` is true, with an admin-reset banner.
- **Browser-verified end-to-end** (`e2e/force-reset.spec.ts`, screenshot `09-force-reset.png`): admin resets nurse via API → nurse logs in with the temp password → locked to /change-password → rotates → lands on the dashboard. Backend flow also proven raw over HTTP: temp login 200 (`force_reset:true`), wrong current password rejected, change 200, re-login shows `force_reset:false`.
- Fix: the change-password mutation must AWAIT the `me` refetch before navigating, or the stale cache bounces the user back to the gate.

## Verification summary

| Gate | Result |
|---|---|
| `php -l` new files | clean |
| `tsc --noEmit` | clean |
| `composer test` | 21/21 |
| Playwright (`SYNAPSE_E2E=1`, 9 screens, 5 specs) | 5/5 |
| Audit chain after full lifecycle | intact @ 156 events |
| `synapse:smoke` | OK |

## Remaining (Phase 10+ candidates)

1. DB-backed integration test tier (unit suite is DB-free by design).
