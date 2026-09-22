# SYNAPSE — Dev Credentials

> **Scope:** Dev/staging sign-in reference. The demo student/employee dataset
> was removed 2026-09-16 — real accounts now come from the university MIS
> integration, and dev machine accounts from `DevUserSeeder` only.
>
> **Do NOT use these in production.** Production never runs `DevUserSeeder`
> (it refuses to run under `ENVIRONMENT=production`); the Platform Owner is
> minted there via `php spark synapse:promote-superadmin`.

---

## 1. Dev machine accounts (`DevUserSeeder`)

| Email | Username | Groups | Password |
|---|---|---|---|
| `admin@synapse.dev` | `synapse-admin` | `superadmin` + `clinic_admin` | `DevPassw0rd!` |
| `nurse@synapse.dev` | `synapse-nurse` | `clinic_staff` | `DevPassw0rd!` |
| `report_viewer@synapse.dev` | `synapse-report-viewer` | `report_viewer` | `DevPassw0rd!` |

Seed order matters — groups/permissions first:

```bash
php spark db:seed App\\Database\\Seeds\\PermissionsAndGroupsSeeder
php spark db:seed App\\Database\\Seeds\\DevUserSeeder
```

## 2. Real users — Foundation University MIS

Students and employees sign in with their **university ID number** and MIS
password on the campus network (`FUMIS_ENABLED=true`):

- First login JIT-provisions the local user (`kind` = `student`/`employee`,
  default group `student`/`employee`).
- The MIS API returns no email — SYNAPSE derives the university address for
  display (`firstname+givennames.lastname@foundationu.com`, e.g.
  `johnlloyd.macias@foundationu.com`; middle names excluded).
- Passwords are managed by the **FU helpdesk**, not SYNAPSE — the
  change-password screen is hidden for MIS users.

### Granting roles to a real user

The default `student`/`employee` groups are self-service portals. To give a
real user an operational role (nurse, counsellor, report viewer, admin…):

1. Sign in as `admin@synapse.dev` (superadmin) → **Administration → Users**.
2. Search by the person's **name** (MIS users have no real email; name search
   finds them).
3. **Edit roles** → check the role → **Save roles**. Privileged roles
   (`clinic_admin`, `guidance_admin`, `bmg_admin`, `superadmin`) require the
   superadmin and an audit acknowledgement; every privileged grant/revoke is
   audited.

## 3. Cleaning demo data from an older dev database

Dev databases seeded before 2026-09-16 carry the removed demo dataset
(`@foundationu.edu.ph` accounts + their clinical activity). Purge it without
touching real MIS users or the dev machine accounts:

```bash
php spark synapse:purge-demo            # dry run: lists what would be deleted
php spark synapse:purge-demo --execute  # perform the purge
```

## 4. Remaining seeders

| Seeder | Purpose |
|---|---|
| `PermissionsAndGroupsSeeder` | Groups + permission codes — always first, prod-safe |
| `DevUserSeeder` | The 3 dev machine accounts above (dev only) |
| `FacilitiesSeeder` | BMG waste categories + drums |
| `InventoryItemsSeeder` | Clinic inventory + equipment catalog |
