# SYNAPSE — Phase 11: Employee Portal (Self-Scope)

**Status:** Delivered and runtime-verified (HTTP + DB inspection). Adds a strictly self-scoped surface so any authenticated employee on the patient registry can see their own profile, kiosk identifier, and clinic-visit history — and read their own notifications.

---

## 1. The role

The user described an **employee/faculty/staff portal** distinct from the admin console. Concretely:

- See your own record (employee row, position, is_teaching flag).
- See your own clinic/medical record history.
- Receive and read notifications.
- Reference a kiosk identifier you can present at the clinic kiosk.
- "Request direct counselling referrals for students if authorized" — this is the existing `referrals.create` perm; we surface a quick-action button that opens `/referrals`.

> **Out of scope (explicitly deferred):**
> - 2FA / password reset *flow* — backend already has `force_reset` + `changePassword`; the portal links to the existing `/change-password` page rather than re-implementing.
> - Self-admit at the kiosk using RFID/QR — the kiosk scan endpoint is gated by `clinic.checkin.record`. Granting that to every employee would be a security regression. The portal shows the employee their **kiosk identifier** (e.g. `emp:20266839`); the actual scan happens at the kiosk under the staff member's permission.
> - Patient self-service login / booking / QR (deferred since Phase 10).

---

## 2. RBAC

A single new permission, granted to **every** group (mirroring `notifications.read`):

| Code | Category | Granted to |
|---|---|---|
| `employee.portal.read` | core | admin, clinic_staff, counsellor, facilities_op, audit_reader |

The permission gates the **surface** (router, sidebar). The data is **strictly self-scoped**: the service resolves `CurrentUser::id()` to the caller's own `patients_employees` row and returns 404 when no link exists. The user can never see another employee's record through this surface.

---

## 3. Database

### New migration: `2026-02-02-000010_EmployeeUserLink`

A nullable `user_id` column on `patients_employees` with a UNIQUE index. Lets one `users` row link to exactly one employee record (1:1). NULL is allowed for historical/HR-imported employees who are not portal users.

```sql
ALTER TABLE patients_employees
  ADD COLUMN user_id BIGINT(20) UNSIGNED NULL AFTER id,
  ADD UNIQUE KEY uniq_employees_user_id (user_id);
```

### Demo wiring

nurse-jane (`users.id=2`) is linked to the seeded School Nurse (`patients_employees.id=21`, employee_number `20266839`). Other employees (admin, the four `synapse-*` probes) are NOT linked — the portal returns 404 for them, which the SPA renders as the "not on the registry" empty state.

Three demo self-encounters (1 Open + 2 Closed) are pre-seeded for nurse-jane so the "My clinic visits" section has real data on first load.

---

## 4. Backend

### New endpoints

| Method | Path | Guard | Behaviour |
|---|---|---|---|
| GET | `/api/v1/me/employee-profile` | `employee.portal.read` | caller's own `EmployeeDto` + a derived `kiosk_identifier` (`qr:...` / `rfid:...` / `emp:...`) |
| GET | `/api/v1/me/clinic-visits?limit=50` | `employee.portal.read` | caller's own `clinic_encounters` rows, newest first, decorated with the attending clinician's `username` |

Both routes live under a fresh `/api/v1/me/...` group so the SPA never needs the caller's employee id.

### New code

- `backend/app/Modules/Clinic/Services/EmployeeSelfService.php` — `getMyProfile()` + `listMyClinicVisits(int $limit)`. Strict 404 when the user is not on the registry.
- `backend/app/Modules/Clinic/Controllers/EmployeeSelfController.php` — thin HTTP layer.
- `backend/app/Modules/Clinic/Routes.php` — registers the `/api/v1/me/...` group.

### Modified

- `backend/app/Config/AuthGroups.php` — `employee.portal.read` granted to all 5 groups.
- `backend/app/Database/Seeds/PermissionsAndGroupsSeeder.php` — declares the new permission.
- `backend/app/Modules/Clinic/Services/PatientService.php` — `EMPLOYEE_COLS` now includes `user_id`.
- `backend/app/Modules/Clinic/DTOs/EmployeeDto.php` — `jsonSerialize()` exposes `user_id` (nullable) and uses `isset()` guards so legacy callers (rows without the column) still serialize.

---

## 5. Frontend

| File | Purpose |
|---|---|
| `frontend/src/schemas/employeePortal.ts` | Zod mirror of the two endpoints; matches the backend fields exactly. |
| `frontend/src/hooks/useEmployeePortal.ts` | `useMyEmployeeProfile()` + `useMyClinicVisits(limit=50)`. Both surface 404s as a normal empty state instead of retrying. |
| `frontend/src/pages/EmployeePortalPage.tsx` | The dashboard. Profile card, kiosk-identifier card, clinic-visits table, emergency-contact card, recent-notifications card, and two quick-action buttons (change password, refer a student). All read-only. |
| `frontend/src/router.tsx` | Registers `/me` behind the new permission. |
| `frontend/src/components/AppSidebar.tsx` | New "My portal" entry under Overview with the `IdCard` icon. |

The page is accessible: skeleton loaders, `role="status"`, `aria-hidden` on decorative icons, capitalised status badges.

### Verified live

```
=== NURSE-JANE (employee row linked) ===
  PROFILE: Althea Navarro (Health Services) kiosk=emp:20266839
  VISITS: 3
    E#17 Open   BP follow-up               (by synapse-admin)
    E#16 Closed Flu symptoms (fever, cough) (by synapse-admin)
    E#15 Closed Annual physical check-up    (by synapse-admin)
```

---

## 6. Quality gates

- `vendor/bin/phpunit` — **74/74 tests passing** (129 assertions)
- `npm run typecheck` — clean
- `npm run lint` — clean
- e2e API smoke confirms the linked + unlinked paths both behave correctly.
