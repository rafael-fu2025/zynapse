# Operations Runbook — RBAC & External API

Companion to [`DATA-SHARING.md`](DATA-SHARING.md). Covers the 2026-09 RBAC
rework (Platform Owner + unit administrators) and the external API surface
day-2 operations.

## 1. Role catalog reference

13 roles, code-defined in `backend/app/Config/AuthGroups.php` (reviewed in
git; the read-only matrix at `/admin/roles` renders it live):

| Role | Code | Type | Scope |
|---|---|---|---|
| Platform Owner | `superadmin` | **wildcard (*)** | Everything; the only wildcard holder; API apps/keys |
| Clinic Administrator | `clinic_admin` | privileged | Full clinic + referrals, kiosk content, saved-report authoring, user provisioning |
| Clinic Staff | `clinic_staff` | standard | Encounter/appointment/patient workflows |
| Kiosk Station | `kiosk` | machine | `kiosk.checkin.submit` only |
| Guidance Administrator | `guidance_admin` | privileged | All counselling, referral lifecycle, patient directory (read), user provisioning |
| Guidance Supervisor | `guidance_supervisor` | standard | Counselling oversight incl. `read_any` break-glass |
| Guidance Counsellor | `counsellor` | standard | Own-session counselling work |
| BMG Administrator | `bmg_admin` | privileged | Full facilities/BMG configuration + operations |
| BMG Operator | `facilities_op` | standard | Drum operations (no configuration) |
| Audit Reader | `audit_reader` | read-only | Audit log browse/export |
| Report Viewer | `report_viewer` | read-only | Cross-module reports/export |
| Student / Employee | `student`, `employee` | self-service | Own portal + notifications |

Privileged set P = {`superadmin`, `clinic_admin`, `guidance_admin`,
`bmg_admin`}: granting or revoking any of these requires
`rbac.privileged.manage` (superadmin). Kiosk machine accounts are created /
password-reset only by `clinic_admin` or `superadmin`. No user can revoke
their own last privileged role, and the last holder of a privileged role is
irremovable. Every set-P change is audited
(`rbac.privileged_role_granted` / `rbac.privileged_role_revoked`).

## 2. Bootstrap & migration procedure (existing deployment)

> Run from `backend/`. Always clear the CI4 file caches after deploying new
> code — a stale `writable/cache/FileLocatorCache` silently hides new
> migrations and classes from `spark` (see README → Troubleshooting).

```bash
# 0. Clear CI4 caches so new migrations/controllers are visible
php spark cache:clear

# 1. Apply migrations (RBAC rename + api_apps/api_keys + sandbox tenant)
php spark migrate

# 2. Converge RBAC rows (groups, permission codes, junction sync)
php spark db:seed App\\Database\\Seeds\\PermissionsAndGroupsSeeder

# 3. Mint the Platform Owner (first superadmin) — --confirm in production
php spark synapse:promote-superadmin owner@foundationu.edu.ph --confirm

# 4. Tell staff to sign in again — client permission sets refresh at login.

# 5. If sandbox data is wanted for the explorer (dev/staging ONLY):
php spark db:seed App\\Database\\Seeds\\SandboxTenantSeeder
```

**What happens to existing admins:** memberships survive — every member of
the old `admin` group becomes `clinic_admin` (explicit matrix: full clinic +
referrals + user provisioning). `audit.*`, `counselling.*` and `facilities.*`
are NO LONGER implied. If a former administrator also performed audit review
or saved-report authoring, grant them `audit_reader` and/or `report_viewer`
via `/admin/users` (superadmin can also grant `clinic_admin` nothing extra —
`reports.configure` is already in the clinic_admin matrix).

`clinical_supervisor` members became `guidance_supervisor` with an unchanged
matrix; `facilities_op` was relabeled "BMG Operator" and lost the
units/categories configuration codes (now `bmg_admin`).

## 3. Key rotation cadence (90 days)

- Keys default to 90-day expiry (`Config\ExternalApps::$defaultKeyTtlDays`).
- Weekly: check the developer portal (`/developer`) for keys expiring within
  14 days — issue replacements, update integrations, revoke the old keys.
- Expired keys fail with `401 auth.api_key_expired`; there is no background
  sweeper (enforcement is at request time), so expired rows remain as audit
  breadcrumbs.
- Optional cron for alerting on upcoming expiries: query
  `api_keys WHERE revoked_at IS NULL AND expires_at < NOW() + INTERVAL 14 DAY`.

## 4. Privileged-role offboarding checklist

When someone with a set-P role leaves their position:

1. **Deactivate the account** in `/admin/users` (revokes refresh tokens
   immediately) — or reset its password if a handover is intended.
2. **Remove the privileged role** from the account (assign a successor role
   where needed). Remember: you cannot remove a privileged role from your
   own account if it is your last one; a second Platform Owner makes
   offboarding painless.
3. **Review audit**: filter `audit_events` for
   `rbac.privileged_role_granted` / `rbac.privileged_role_revoked` and for
   the actor's recent activity.
4. **Rotate what they knew**: if they managed API keys, revoke keys they
   issued; if they held superadmin, consider rotating `JWT_SECRET` (forces
   re-login everywhere) and check `synapse:audit-verify` passes.
5. If the account is the LAST holder of a privileged role, appoint the
   successor first — the platform refuses to orphan a privileged role.

## 5. External API app lifecycle

- **Register** the app (name + owner contact) → **issue a `test` key** →
  integration develops against the sandbox tenant → **data-sharing
  acknowledgement** (DPA, see DATA-SHARING.md) → **issue a `live` key**.
- **Suspend** an app to stop all of its keys instantly (reactivation
  restores unrevoked/unexpired keys).
- **Revoke** individual keys for rotation or compromise.
- Audit codes: `api_app.created`, `api_app.suspended`, `api_app.reactivated`,
  `api_key.created`, `api_key.revoked`, `external.request` (sampled 100% for
  live keys, 20% for sandbox; tune via `EXTERNAL_AUDIT_SAMPLE_LIVE` /
  `EXTERNAL_AUDIT_SAMPLE_TEST`).
- Verify the audit chain nightly (already cron'd): `synapse:audit-verify`.

## 6. Sandbox tenant

- Slug `sandbox` (tenant id ≠ 1). All `syn_test_…` keys read it; live keys
  read production. Test keys can never read production data — enforced by
  per-query tenant scoping and feature-tested.
- The sandbox starts EMPTY in production (the seeder is prod-gated, matching
  every demo seeder). Seed synthetic data only if the explorer needs
  non-empty aggregates: `php spark db:seed App\\Database\\Seeds\\SandboxTenantSeeder`
  — dev/staging only.
