# SYNAPSE — Demo Account Credentials

> **Scope:** This file lists the **canonical demo accounts** for the SYNAPSE dev/staging
> environment. These accounts are seeded by `DevUserSeeder`, `PermissionsAndGroupsSeeder`,
> and the Phase 13 manual link script. They exist so every surface of the SPA has at
> least one working login that exercises the right RBAC + patient-registry link.
>
> **Do NOT use these in production.** Production credentials are issued via
> `/api/v1/admin/users/{id}/reset-password` and rotated by HR.
>
> **Git policy:** This file is intentionally committed to the repo. It is the
> source of truth for the demo matrix documented in `docs/PHASE13.md`. The
> `.gitignore` does NOT exclude it.

---

## 1. The matrix

| # | Role | Group | Linked registry row | Username | Email | Password |
|---|---|---|---|---|---|---|
| 1 | **Student** | `student` | patients_students #26 (Andrei Santos, BSIT 1-A) | `student-andrei` | `andrei.santos@synapse.dev` | `StudentAndrei2026!` |
| 2 | **Teaching faculty** | `clinic_staff` | patients_employees #36 (Patricia Cruz, BSIT Prof, teaching=1) | `prof-perez` | `prof.perez@synapse.dev` | `TeachingProf2026!` |
| 3 | **Non-teaching staff** | `clinic_staff` | patients_employees #22 (Brando Del Rosario, Medical Officer) | `staff-brando` | `brando.delrosario@synapse.dev` | `StaffBrando2026!` |
| 4 | **Clinic staff (no employee link)** | `clinic_staff` | — | `nurse-jane` | `nurse@synapse.dev` | *(temp — use admin reset)* |
| 5 | **Clinic staff (probe)** | `clinic_staff` | — | `synapse-clinic-staff` | `clinic_staff@synapse.dev` | *(temp — use admin reset)* |
| 6 | **Counsellor** | `counsellor` | — | `synapse-counsellor` | `counsellor@synapse.dev` | *(temp — use admin reset)* |
| 7 | **BMG staff** | `facilities_op` | — | `synapse-facilities-op` | `facilities_op@synapse.dev` | *(temp — use admin reset)* |
| 8 | **Report viewer** | `audit_reader` | — | `synapse-audit-reader` | `audit_reader@synapse.dev` | *(temp — use admin reset)* |
| 9 | **Admin** | `admin` | — | `synapse-admin` | `admin@synapse.dev` | `DevPassw0rd!` |

The non-canonical password rows (4–8) are probe/demo accounts whose passwords get
rotated by every fresh `DevUserSeeder` run. Use the admin reset endpoint to mint
a fresh temp when you need them.

---

## 2. What each demo user sees

| Actor | `/me` surface | Sidebar entries | Notable gates |
|---|---|---|---|
| Student | `StudentPortalPage` (profile + kiosk identifier + clinic visits + notifications) | Dashboard, My portal | `student.portal.read` only — no clinic write, no referrals |
| Teaching faculty | `EmployeePortalPage` (with the **Refer a student** quick action enabled) | Dashboard, My portal, Encounters, Patients, Appointments, Inventory, Kiosk, Reports, Referrals | `referrals.create` works; `ReferralService::issuerIsTeachingEmployee` allows clinic-originated referrals |
| Non-teaching staff | `EmployeePortalPage` (with the **Refer a student** quick action disabled + inline note) | Same as faculty | `referrals.create` perm is held but `ReferralService` returns 403 `referral.teaching_required` (Phase 12) |
| Clinic staff (no link) | 404 from `/me/employee-profile` → "not on the registry" empty state | Same as faculty | All clinic write perms |
| Counsellor | 404 → empty state | Dashboard, My portal, Counselling, Referrals | counselling.records.*, referrals.acknowledge |
| BMG staff | 404 → empty state | Dashboard, My portal, Facilities | facilities.bmg.* |
| Report viewer | 404 → empty state | Dashboard, My portal, Reports, Audit (read-only) | reports.read, audit.read |
| Admin | 404 → empty state | Everything (`*` wildcard) | All permissions |

---

## 3. Resetting a probe account's password

The 5 probe accounts (`nurse-jane`, `synapse-clinic-staff`, `synapse-counsellor`,
`synapse-facilities-op`, `synapse-audit-reader`) have rotated temp passwords. Mint
a fresh one via the admin reset endpoint:

```powershell
# 1. Login as admin
$login = Invoke-RestMethod -Method POST `
    -Uri 'http://localhost:8080/api/v1/auth/login' `
    -ContentType 'application/json' `
    -Body '{"email":"admin@synapse.dev","password":"DevPassw0rd!"}'
$tok = $login.data.access_token

# 2. Reset a probe account (user ids below)
$h = @{ Authorization = "Bearer $tok" }
Invoke-RestMethod -Method POST `
    -Uri 'http://localhost:8080/api/v1/admin/users/12/reset-password' `
    -Headers $h
# -> {"data":{"id":12,"temporary_password":"<NEW>","force_reset":true}}
```

| user_id | username |
|---|---|
| 2 | `nurse-jane` |
| 11 | `synapse-clinic-staff` |
| 12 | `synapse-counsellor` |
| 13 | `synapse-facilities-op` |
| 14 | `synapse-audit-reader` |

---

## 4. Database state (for direct inspection)

```sql
SELECT u.id, u.username, i.secret AS email, GROUP_CONCAT(g.name) AS groups,
       pe.employee_number IS NOT NULL AS linked_emp,
       ps.student_number IS NOT NULL AS linked_stu
FROM   users u
LEFT JOIN auth_identities        i   ON i.user_id = u.id  AND i.type = 'email_password'
LEFT JOIN auth_groups_users      agu ON agu.user_id = u.id
LEFT JOIN auth_groups            g   ON g.id  = agu.group_id
LEFT JOIN patients_employees     pe  ON pe.user_id = u.id
LEFT JOIN patients_students      ps  ON ps.user_id = u.id
WHERE  u.active = 1
GROUP BY u.id ORDER BY u.id;
```

| user_id | username | email | groups | emp_linked | stu_linked |
|---|---|---|---|---|---|
| 1 | `synapse-admin` | `admin@synapse.dev` | `admin` | 0 | 0 |
| 2 | `nurse-jane` | `nurse@synapse.dev` | `clinic_staff` | 1 (E#21) | 0 |
| 11 | `synapse-clinic-staff` | `clinic_staff@synapse.dev` | `clinic_staff` | 0 | 0 |
| 12 | `synapse-counsellor` | `counsellor@synapse.dev` | `counsellor` | 0 | 0 |
| 13 | `synapse-facilities-op` | `facilities_op@synapse.dev` | `facilities_op` | 0 | 0 |
| 14 | `synapse-audit-reader` | `audit_reader@synapse.dev` | `audit_reader` | 0 | 0 |
| 15 | `prof-perez` | `prof.perez@synapse.dev` | `clinic_staff` | 1 (E#36) | 0 |
| 16 | `student-andrei` | `andrei.santos@synapse.dev` | `student` | 0 | 1 (S#26) |
| 17 | `staff-brando` | `brando.delrosario@synapse.dev` | `clinic_staff` | 1 (E#22) | 0 |

---

## 5. Rotating everything back to defaults

If the dev DB drifts and you want to rebuild the demo accounts from scratch:

```bash
# 1. Apply migrations (idempotent)
php spark migrate

# 2. Seed core data (groups + permissions + admin user)
php spark db:seed PermissionsAndGroupsSeeder
php spark db:seed DevUserSeeder

# 3. Seed the patient registry (employees + students + teaching flag)
php spark db:seed PatientRegistrySeeder

# 4. Seed every other module's demo data
php spark db:seed AppointmentsSeeder
php spark db:seed CounsellingSeeder
php spark db:seed ReferralsSeeder
php spark db:seed InventoryItemsSeeder
php spark db:seed FacilitiesSeeder
```

After step 2, only the canonical `synapse-admin` / `DevPassw0rd!` + the Phase 13
canonical accounts (`student-andrei`, `prof-perez`, `staff-brando`) are
guaranteed to have working passwords. The 5 probe accounts will need fresh
temps from the admin reset endpoint (or, alternatively, run the
`DevUserSeeder` which writes a new random password and prints it to stdout
— see `scripts/nursetemp.json` for the most recent capture).

---

## 6. Quick login reference

| If you want to test… | Use |
|---|---|
| The student self-scope (read-only) | `andrei.santos@synapse.dev` / `StudentAndrei2026!` |
| The teaching-only referral gate (positive) | `prof.perez@synapse.dev` / `TeachingProf2026!` |
| The teaching-only referral gate (negative) | `brando.delrosario@synapse.dev` / `StaffBrando2026!` (or reset `nurse-jane`'s password) |
| Self-update of own profile (Phase 14) | `nurse@synapse.dev` (reset password first) → `POST /me/employee-profile` |
| RBAC introspection (`/rbac/permissions`) | `admin@synapse.dev` / `DevPassw0rd!` (admin user gets `*`) |
| Mint a fresh password for any probe | `admin@synapse.dev` / `DevPassw0rd!` → `POST /api/v1/admin/users/{id}/reset-password` |
