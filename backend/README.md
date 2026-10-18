# SYNAPSE Backend

The CodeIgniter 4.7 REST API behind everything — the React SPA and Flutter app both consume these endpoints. PHP 8.3+, MariaDB 10.4+ / MySQL 8 (`synapse_zcode`), stateless JWT auth, DB-driven RBAC, hash-chained audit log.

## How it's built

Five domain modules — **Clinic**, **Counselling**, **Facilities (BMG)**, **Referrals**, **Reports** — each owning its own `Controllers/ Services/ DTOs/ Policies/ Routes.php`, over a `Modules\Shared` kernel (`BaseService`, `BasePolicy`, `BaseRoutes`, `BaseDTO`). Cross-cutting surfaces (auth, RBAC, admin, audit, dashboard, notifications) live in root `Controllers/Api`. Modules register their own routes via `Routes::register()`, so root `Config/Routes.php` stays small.

Two deliberate design choices worth knowing before you contribute:

- **No CI4 Model classes.** All data access is query-builder SQL inside services. Tenancy is therefore explicit — every query carries a `CurrentTenant::id()` predicate — and guarded by the `TenantScopeFitnessTest` ratchet (see [Tests](#tests)) rather than by model scopes.
- **MySQL/MariaDB only.** The schema leans on `SIGNAL SQLSTATE` triggers, ENUM columns, and CHECK constraints; SQLite is not an option, which is why the feature suite needs a real MariaDB.

Concurrency is uniform: every mutation runs in `txn()` with `selectForUpdate()` row locks, and the audit write joins the same transaction.

```
app/
├── Auth/                    JwtService, RefreshTokenService (rotation chain),
│                            LoginThrottleService, AccountStateService, CurrentUser
├── Commands/                12 spark workers (see below)
├── Config/                  root Routes.php, Filters, Constants
├── Controllers/Api/         Auth · Rbac · Admin · Audit · Dashboard · Notify
├── Database/
│   ├── Migrations/          96 timestamped migrations (2026-01 → 2026-09)
│   └── Seeds/               5 seeders — PermissionsAndGroupsSeeder is load-bearing
├── Filters/                 ApiAuthFilter, rate limit, exception envelope, CORS, logging
├── Modules/                 Clinic · Counselling · Facilities · Referrals · Reports
│   └── Shared/              BaseService, BasePolicy, BaseRoutes, BaseDTO
├── Services/                CurrentTenant, Export\CsvWriter, EncryptionService
└── Validation/              BmgMassInvariant rule
scripts/                     scan-tenant-scope.php — tenancy burn-down worklist
tests/
├── unit/                    framework-free suite (custom bootstrap, no DB)
└── Feature/                 HTTP suite — boots the kernel against MariaDB
```

## Setup

```bash
# utf8mb4_unicode_ci is portable across MySQL 5.7+/8.4 and MariaDB 10.4+;
# utf8mb4_0900_ai_ci is MySQL-8-only
mysql -u root -e "CREATE DATABASE IF NOT EXISTS synapse_zcode CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

cp .env.example .env        # then generate JWT_SECRET and COUNSELLING_KEY
                            # (each: openssl rand -hex 32)
composer install
php spark migrate --all
php spark db:seed App\\Database\\Seeds\\PermissionsAndGroupsSeeder
php spark db:seed App\\Database\\Seeds\\DevUserSeeder
php spark synapse:smoke
php spark serve --port 8090
```

`DevUserSeeder` creates the 13-account dev roster — one account per role in `Config\AuthGroups`, password `DevPassw0rd!` (see [`../CREDENTIALS.md`](../CREDENTIALS.md) for the matrix) — and **refuses to run when `ENVIRONMENT=production`**. Always seed `PermissionsAndGroupsSeeder` first — it creates the groups and permission codes everything else depends on, and it's also the feature-suite seed. The remaining data seeders are reference/catalog data: `FacilitiesSeeder` (BMG waste categories + drums) and `InventoryItemsSeeder` (clinic inventory + equipment). The demo student/employee dataset was removed 2026-09-16 — real accounts JIT-provision from the university MIS on first ID-number login (see [`../CREDENTIALS.md`](../CREDENTIALS.md)), and older dev databases can purge leftover demo rows with `php spark synapse:purge-demo`.

## Environment

Key `.env` variables (full annotated template in `.env.example`):

| Variable | Purpose |
|---|---|
| `JWT_SECRET` / `JWT_ALG` / `JWT_ACCESS_TTL_SECONDS` / `JWT_REFRESH_TTL_SECONDS` | HMAC signing + lifetimes (900 s / 30 d defaults, capped by absolute session deadline); minimum key length 32/48/64 bytes for HS256/384/512 |
| `JWT_ISSUER` / `JWT_AUDIENCE` | Exact issuer (defaults to `app.baseURL`) and expected audience (`synapse-api`); legacy JWTs without audience are rejected |
| `JWT_SESSION_MAX_AGE_SECONDS` / `JWT_REFRESH_GRACE_SECONDS` | Immutable session lifetime (12 h default) and bounded rotation grace (0–5 s, default 5) |
| `COUNSELLING_KEY` / `COUNSELLING_KEY_VERSION` | AES-256-GCM note encryption. Rotate by bumping the version, inserting a `counselling_key_versions` row, and exposing the retired key as `COUNSELLING_KEY_V1` etc. |

| `REFRESH_COOKIE_*` | Refresh cookie attributes (`synapse_rt`, Secure, HttpOnly, SameSite=Strict) |
| `CORS_ALLOWED_ORIGINS` | Explicit HTTPS origin allowlist required in production; no wildcards (enforced by `Config\Boot`) |
| `RATELIMIT_GLOBAL_PER_MIN` / `RATELIMIT_AUTH_PER_MIN` | Fixed-window buckets (600 / 30) |
| `LOGIN_LOCKOUT_MAX_FAILURES` / `LOGIN_LOCKOUT_WINDOW_SECONDS` | Per-account lockout (5 failures / 900 s) |

Production preflight requires an HTTPS `app.baseURL`, enabled
`app.forceGlobalSecureRequests`, Secure refresh cookies, and `SameSite=Strict`.
The HTTPS filter now runs globally; configure trusted proxy IPs correctly when TLS
terminates upstream, and verify redirects/cookie flags at the deployed edge.
Development and testing retain HTTP support. Do not disable these controls to
work around a proxy configuration error. HSTS remains an edge/deployment setting.

The refresh-session migration deliberately revokes existing refresh tokens;
strict audience validation also rejects old access tokens. Coordinate the
migration and worker restart, and expect users to sign in again. See
[Refresh-session security](REFRESH_SECURITY.md) for deployment, rollback, expiry,
concurrency, and replay-grace details.

Appointment QR tokens and RFID registry handles are retired. Booking (including `bookSelf` and the portal) needs neither `APPOINTMENT_HMAC_KEY` nor the old `REFERRAL_HMAC_KEY` fallback. Patient QR identity (`users.qr_code` / `has_qr`) is unrelated and remains supported. Older environment templates may still list these retired keys; they are not required.

The append-only `DropAppointmentQrColumns` and `DropRfidIdentityColumns` migrations remove only the retired hash/handle and their indexes. Review and back up before applying: rollback restores empty nullable columns/indexes, **not deleted values**. Historical migrations are unchanged. The legacy identity tables and kiosk check-in table were already dropped by earlier migrations.

Note: the Database config reads collation from `database.default.DBCollat` (not `collation`) — setting `database.default.collation` is a no-op.

## Directory-driven scheduling staff

`users` is the identity registry, including MIS-synced employees who have never
logged in. Scheduling reads this local directory only: discovery, enrollment,
status changes and templates never call MIS/login and never change credentials,
`auth_groups*`, `users.active`, or other account flags. Scheduling enrollment is
not authorization to log in, manage a team, or access Guidance records/notes.

### Department configuration and eligibility

`app/Config/SchedulingStaff.php::$departments` maps the stable scheduling unit
keys `clinic` and `guidance` to **exact directory aliases**. The initial observed
labels are `Health Services` / `University Health Services` for clinic, and
`Guidance` for guidance. Matching lowercases and trims surrounding whitespace;
it does not fuzzy-match, inspect job titles or infer eligibility from roles.
Add other verified labels/codes to this configuration (or a CI config registrar)
only after checking the directory. `users.department` is currently free text:
these are normalized labels, **not invented MIS department IDs**. The API returns
the unit's normalized aliases as `department_keys`.

Eligible staff must have `kind=employee`, a nonempty `employee_number`, active
`employment_status`, neither `archived_at` nor `deleted_at`, and a matching
current department. Login activation is deliberately irrelevant. New schedule
writes and Guidance staff bookings/confirmations require both current directory
eligibility and an active enrollment. Portal capacity uses only eligible,
actively enrolled staff, revalidated under locks at booking time. Clinic
assignments are **duty shift roster membership**, not appointment-provider
membership; Guidance assignments are appointment-provider membership.

The legacy `POST /api/v1/me/student-appointments` remains a compatibility adapter:
`scheduled_at` is still a UTC `YYYY-MM-DD HH:mm:ss` string and the response is
still the clinic appointment DTO (including the same appointment ID used by
`GET /me/student-appointments`). An optional `provider_user_id` is validated as
an eligible active enrollment, but does not reserve or assign that person;
bookings remain unassigned until approval. The legacy provider picker returns
eligible clinic directory IDs and names, not role-derived accounts. Both portal
write routes use the same atomic pooled-capacity check. Transactional roster,
leave and booking reads are current locking reads, not repeatable-read snapshots.

Schedule edits/restores and Guidance appointment mutations lock directory and
enrollment rows in sorted user-ID order before locking the resource row. A
concurrent ownership change returns `409 scheduling.owner_changed`; reload before
retrying. Guidance queue reassignment uses the same enrollment eligibility for a
non-null target, without requiring login activation; returning to the pool is
still allowed and existing queue permissions are unchanged.

### API (`/api/v1/scheduling/staff/{unit}`)

All responses use the existing success/data/errors/meta envelope and preserve
`users.id` as `user_id` / `counsellor_user_id`.

| Method/path | Contract |
|---|---|
| `GET /?q=&cursor=&limit=25` | `data={items,next_cursor,department_keys}`. Includes matching directory employees **and all previously enrolled staff** (including transferred, inactive and missing legacy identities). Search and cursor filtering precede SQL pagination; limit is capped at 100. Cursor is opaque and tenant/unit-bound. |
| `GET /providers` | `data=[{id,name,position,employee_number}]`, eligible active enrollments only. The existing `/api/v1/counselling/counsellors` endpoint uses the same source without role filtering. Guidance non-team readers see only themselves. |
| `POST /enroll` | `{user_ids:[number]}` → `{created,skipped}`. Atomic, audited, idempotent. Existing inactive assignments are skipped, **never reactivated implicitly**. |
| `POST /assignments/{id}/status` | `{active:boolean}` → assignment row. Reactivation revalidates current directory eligibility; deactivation retains schedules and appointments. |
| `POST /template` | `{user_ids,days_of_week,start_time,end_time,schedule_type?}` → `{created,skipped}`. Times are valid `HH:mm`; days are integers 0–6; IDs/days are deduplicated and sorted. Requires explicit active enrollment first; never enrolls staff implicitly. Creates only missing user/day schedules in one transaction. Any existing user/day, including inactive rows, split shifts, dated windows and leave, is skipped unchanged. Clinic types are `regular`, `on_call`, `leave`; Guidance accepts only `regular`. Invalid/inactive members roll back the entire batch. |

Discovery/enrollment/status/template require `clinic.schedules.manage` or
`counselling.schedule.team_manage`, respectively. Existing own-schedule
permissions and Guidance record/notes ownership remain separate and unchanged.
Provider reads require the existing schedule read permission (clinic uses its
existing roster-management permission). No new role or permission is needed.

Discovery items include `assignment_id`, `enrollment_status`, `eligible`,
`can_enroll`, `status` and `future_appointments`, plus directory identity fields.
`eligible` describes the directory independently of enrollment; `can_enroll` is
true only for eligible, not-yet-enrolled identities. Statuses are `not_enrolled`,
`ready`, `configured`, `inactive_employee`, `inactive_enrollment`,
`department_mismatch`, `needs_review`. Directory problems take precedence over
enrollment/configuration status. `configured` means at least one existing
schedule row, including an inactive row. `future_appointments` counts scheduled
or confirmed Guidance appointments whose Manila start time is still in the
future. For **clinic only**, the compatibility field counts active recurring
roster rows that have not expired (not assigned patient appointments or expanded
future shift occurrences).

### Migration and review runbook

1. Back up and review before migration. This project enables config/file-locator
   caches: remove only the generated `writable/cache/FileLocatorCache` and
   `writable/cache/FactoriesCache_config` files after deploying new files (leave
   unrelated runtime/rate-limit caches intact), verify
   `php spark migrate:status` lists `SchedulingStaffAssignments`, then run
   `php spark migrate --all`. The append-only
   `2026-10-18-000010_SchedulingStaffAssignments` migration adds a table unique on
   `(tenant_id,unit,user_id)`, with active state, enrollment/update actors and
   timestamps. It backfills distinct clinic roster staff and Guidance
   availability/appointment staff, including inactive/historical rows. It does
   **not** edit/drop any schedule, appointment, directory or authentication row.
2. Backfilled actors are null (migration provenance); enrollment does not prove
   that the referenced legacy account is an employee. Missing/nonemployee or
   unnumbered directory identities surface as `needs_review` and are blocked
   from new assignments/bookings. No cascading user FK erases those review rows.
3. Review both units' discovery pages after deployment. Verify aliases against
   real directory labels, correct/link legacy identities through the existing
   directory workflow, then explicitly activate/deactivate enrollment as needed.
   Do not infer an employee from a historical role or job title. The migration
   does not silently link duplicate accounts or grant access to historical notes.
4. On department transfer, archive/delete, leave or employment deactivation,
   existing schedules/appointments remain visible under their existing access
   rules. Provider choices and new booking capacity stop immediately. Use review
   flags and future counts to explicitly resolve outstanding work; nothing is
   auto-cancelled/deleted. Cancelling/completing historical work remains possible
   under existing lifecycle rules. Reconfirmation and new schedule edits are
   blocked until the assigned member is eligible and actively enrolled again.
5. Rollback drops only the enrollment table, not historical scheduling data.
   Reapplying backfills legacy membership, so export intentional inactive
   enrollments before rolling back and re-review/reapply them afterwards.

## Module map

| Module | Routes | Entry controller | Policy |
|---|---|---|---|
| Facilities (BMG) | `Modules/Facilities/Routes.php` | `BmgController` | `BmgPolicy` |
| Clinic | `Modules/Clinic/Routes.php` | `ClinicController` | `ClinicPolicy` (record-level: attending_user_id) |
| Counselling | `Modules/Counselling/Routes.php` | `CounsellingController` | `CounsellingPolicy` (record-level: counsellor_user_id) |
| Referrals | `Modules/Referrals/Routes.php` | `ReferralController` | `ReferralPolicy` |
| Reports | `Modules/Reports/Routes.php` | `ReportController` | `ReportExportPolicy` (service-level) |
| Audit reader | `Controllers/Api/Audit` | — | n/a |
| Dashboard | `Controllers/Api/Dashboard` | — | n/a |
| Auth | `Controllers/Api/Auth` | — | n/a |

Authorization is two-stage: `ApiController::authorize()` checks the permission code, then module policies add record-ownership gates. Services call `$this->policy->check()` themselves — a new service method that skips it ships silently, so review for this in PRs.

## API conventions

- **Envelope** — `{ success, data, errors, meta }` on every response; stable error codes; 5xx redacted to `internal.error` with no message/trace leakage; logs keep only exception class + object id.
- **Pagination** — keyset cursors only (`KeysetPaginator`); OFFSET is banned.
- **Public endpoints (deliberate)** — `/health`, `/auth/login`, `/auth/refresh`. Appointment and referral QR verification routes are retired; `PublicRoutesTest` guards their absence.
- **Rate limits** — `api_ratelimit` across `api/*`; a strict `auth` bucket (30/min) on login/refresh; per-account lockout after 5 failures in 15 min (HMAC-keyed counters — attempted emails are never stored, logged, or audited).

### Workstation dashboards

Authenticated `GET /api/v1/dashboard/{clinic|guidance|facilities}` returns the
standard envelope. Base gates are respectively `clinic.encounters.read`,
`counselling.records.read`, and `facilities.units.read` (401 without authentication,
403 without the base grant). `WorkstationDashboardService` builds uncached,
read-only projections; responses carry `Cache-Control: private, no-store`.
No new migrations or dependencies are required.

The fixed `data` contract is:

```ts
{
  unit: 'clinic' | 'guidance' | 'facilities';
  day: string;           // YYYY-MM-DD, Asia/Manila
  generated_at: string;  // ISO 8601 UTC, with Z
  scope: 'tenant' | 'own';
  metrics: Array<{key: string; label: string; value: number;
    unit: string | null; description: string}>;
  trend: {title: string; description: string;
    points: Array<{day: string; value: number}>};
  breakdown: {title: string; description: string;
    items: Array<{key: string; label: string; value: number}>};
  recent: {kind: 'encounters' | 'sessions' | 'drums'; title: string;
    rows: Array<{id: number; code: string; title: string;
      subtitle: string | null; status: string; occurred_at: string | null;
      owner: string | null}>};
}
```

Every listed field is present, including nullable fields. Unauthorized metrics
are **omitted**, never returned as misleading zeroes. Trends always contain seven
ascending, zero-filled Manila dates including today. UTC timestamp filtering uses
`ManilaDay::dayBoundsUtcSql()` inclusive-start/exclusive-end bounds; only grouping
converts timestamps to Manila dates. `occurred_at` consistently uses **UTC SQL
`YYYY-MM-DD HH:mm:ss`**, not local time (clients must parse it as UTC).
Recent rows are limited to six with timestamp + ID descending tie-breaking.
Frontend links can be built from `recent.kind` and `id`; no API URLs are embedded.

| Workstation | Metric keys and semantics |
|---|---|
| Clinic | `waiting_queue`: today's waiting + called queue entries, with `clinic.queue.read`; `completed_consultations`: encounters closed today by staff (`outcome IS NULL`, excluding no-show/auto-close); `appointments_today`: all nonarchived appointments scheduled today, with `clinic.appointments.read`; `active_medicines`: nonarchived medicine catalog count, with `clinic.inventory.read` |
| Guidance | `open_sessions`: no end time; `completed_sessions`: ended today; `waiting_queue`: today's waiting-only queue with `counselling.queue.read`; `incoming_referrals`: nonclosed referrals targeting counselling only, with `referrals.read` |
| Facilities | `total_units`: nonarchived units; `active_batches`: processing/awaiting-output batches on nonarchived units; `bound_devices`: nonarchived devices bound to nonarchived units (all three use the existing `facilities.units.read` list/analytics/device gate); `treated_kg`: all-time input kg on released or legacy finished-idle batches, once per batch, with `facilities.bmg.io.record` (the module's I/O read gate) |

Clinic trend counts encounter starts; breakdown groups actual recorded triage
priorities (`low`, `medium`, `high`, `urgent`), excluding unassessed encounters.
Guidance trend counts session starts; breakdown groups actual open/closed
sessions. Without `counselling.records.read_any`, **all session** totals, trends,
breakdown and recent rows are restricted to the authenticated counsellor and
`scope` is `own`. Guidance queue counts intentionally retain the existing
tenant-wide day-board visibility, including other assignments and unassigned
walk-ins; incoming referrals use the receiving counselling side, never outgoing
referrals. These operational metrics do not change session ownership or grant
access to clinical notes. No appointment or followup metric is introduced for
guidance. Clinic appointments retain their existing tenant-wide list scope.

Facilities trend counts batch starts; breakdown groups persisted unit lifecycle
states, not motor telemetry. Treated kg is **input treated, not compost yield**;
input/output ledgers are not joined or added together. Archived units and their
batches/devices are excluded. Recent drums are most recently updated units;
`occurred_at` is the bound nonarchived device's last-seen timestamp (nullable),
`subtitle` its device code, and `owner` its display name. No online/motor status
is inferred. Clinical recent titles are patient display names when a live
same-tenant identity exists, otherwise `Patient`; codes are `#id`, owner is the
clinician username, subtitle is null. Complaints, diagnoses and session notes
are never selected or decrypted. Every relationship is tenant-matched.

`GET /api/v1/dashboard/counters` remains compatible in keys and shape. Its
facilities unit counts are now tenant-scoped, and `at_risk` counts **distinct**
live batches on live units with outstanding alerts, not alert rows. Counselling
counters respect the same own/read-any boundary. Counters are no longer shared
cached, preventing cross-user own-session aggregates from leaking.

Focused integration coverage: `composer test:feature -- --filter WorkstationDashboardTest`.
The service accepts an optional clock closure returning `DateTimeImmutable` for
repeatable Manila midnight tests without changing the process clock.

### Archive list contracts

Archive filters are not interchangeable. Preserve the endpoint's contract when
adding a list toggle or restore action:

| Surface | Default | Opt-in archive scope |
|---|---|---|
| Guidance announcements, services, surveys | Unarchived only | `archived=1` shows **only archived** rows (Pattern A) |
| Clinic medicines, inventory, equipment; BMG units/devices | Unarchived only | `archived=1` shows only archived; `archived=0` only active. Legacy `include_archived=1` or `true` includes both |
| Clinic staff schedules; report configurations | `is_active=1` only | `archived=1` shows only archived; `archived=0` only active. Legacy `include_archived=1` or `true` includes both |
| Student/employee registries, search and facets | Unarchived only | `archived=active\|archived\|all`; legacy `include_archived=1\|true` means `all` |
| Clinic encounters | Unarchived only | `status=archived` is an **exclusive** archived slice |
| BMG waste categories | All, including disabled (legacy) | Table callers use `archived=0` for active, `archived=1` for archived; legacy `active=1` narrows to active only |
| Counselling sessions | Unarchived only | `archived=1` shows only archived; requires oversight soft-delete permission in addition to read permission |
| Appointments, referrals | Unarchived only | No archive-list toggle |

Guidance staff list/create/update payloads always expose nullable `archived_at`.
Archive state is separate from publication windows and service `is_active`.
Archive/unarchive retries are idempotent under a tenant-scoped row lock; only
actual state changes update timestamps and enqueue audit events. Service codes
remain reserved after archiving, matching the `(tenant_id, code)` unique key.
Restoring retains survey versions and responses and does not reopen a closed
survey. Student feeds, forms and submissions still exclude archived content;
restored content must still satisfy its audience, year-level and window gates.
Staff survey detail/response endpoints remain unavailable until restoration.

### Guarded permanent deletion

`POST /api/v1/archived-records/{resource}/{id}/delete-permanently` accepts no
request body. It requires `api_auth` and the existing resource permission/policy;
there is no blanket administrator bypass for retention. IDs are tenant-scoped
(and student/employee IDs are `users.id`, restricted by `users.kind`).

Success is HTTP 200 with the normal envelope and
`data: { "resource": "announcements", "id": 123, "deleted": true }`.
Unknown resource keys, missing rows, wrong patient kind and other-tenant IDs
return 404; missing permissions return 403. Active rows return 409
`archive.required`. Retention/referral-history refusals return 409
`archive.retention_protected` or `archive.references_protected` with a
human-readable explanation in `errors[].message`. A retry after successful
deletion returns 404, not another deletion event.

The explicit resource whitelist and rules are:

| Resource keys | Existing permission | Permanent-deletion rule |
|---|---|---|
| `announcements`, `services` | `counselling.announcements.manage`, `counselling.services.manage` respectively | Already archived; no operational references in the current schema |
| `surveys` | `counselling.surveys.manage` | Only unused archived drafts; published versions/content, responses/answers, follow-ups, link opens, and screenshots (including staged uploads) block deletion |
| `equipment`, `medicines`, `supplies` | `clinic.inventory.delete` | Block on any units/status history, batches, transactions/movements, procurement requests, treatments or forecasts as applicable, even retired/expired/cancelled history; supplies must also have zero stock on hand |
| `staff-schedules` | `clinic.schedules.manage` | `is_active=0`; standalone recurring roster rows have no inbound operational references |
| `devices` | `facilities.units.manage` | Already archived and unbound; any assignments, commands, runs or telemetry (stored device code or numeric ID) block deletion |
| `waste-categories` | `facilities.categories.manage` | `is_active=0`; any batch, composition, input, drum default or waste-mix reference blocks deletion |
| `drums` | `facilities.units.manage` | Already archived; any batches, devices, assignment/run history, schedules/history or waste-mix assignments block deletion |
| `report-configs` | `reports.configure` plus existing module visibility gate | `is_active=0`; any generated/queued report, including failed/expired history, blocks deletion |
| `students`, `employees` | `clinic.patients.write` | Always protected: patient registry retention is not authorized for generic HTTP erasure |
| `encounters` | `clinic.encounters.write` and encounter policy | Always protected clinical records |
| `counselling-sessions` | `counselling.records.soft_delete` and session policy | Always protected counselling records |

Archiving means non-null `archived_at`, except staff schedules, waste categories
and report configurations, where it means `is_active=0`. Merely disabling a
guidance service does **not** archive it. Archive/restore remains the appropriate
workflow for retained records; retention-based erasure requires a separately
authorized process, not this endpoint.

`App\Services\Archive\PermanentDeletionService` locks the tenant-owned parent
and checks references inside `BaseService::txn()`. Reference checks include
non-FK relationships and do not filter away archived, voided, failed or expired
history. Only unused survey draft questions/options and draft links are explicitly
removed, in dependency order; files, responses, history and audits are never
cleaned up by cascade. The legacy waste-category DELETE endpoint delegates to
the same guard (it now also requires an already archived category).

The delete and `archive.permanently_deleted` audit-outbox insert commit atomically.
Existing audit events/outbox rows remain intact. The event records the table,
ID, actor and stable catalog code/resource discriminator, not raw payloads,
patient data, survey titles, notes or device secrets. Refusals and rollbacks do
not leave a deletion event. When adding a relationship, update the explicit
reference map and its feature coverage before allowing deletion of its parent;
do not rely on `CASCADE`, `SET NULL` or FK errors to protect evidence.

## Background workers

Production drains the durable outbox and report queue outside HTTP processes:

```cron
* * * * * cd /path/to/zynapse/backend && php spark synapse:audit-drain --batch=500 --max-batches=10
* * * * * cd /path/to/zynapse/backend && php spark synapse:reports-drain --limit=10
* * * * * cd /path/to/zynapse/backend && php spark synapse:appointments-enqueue-due
* * * * * cd /path/to/zynapse/backend && php spark synapse:queue-skip-sweep
*/15 * * * * cd /path/to/zynapse/backend && php spark synapse:bmg-device-watchdog
15 2 * * * cd /path/to/zynapse/backend && php spark synapse:audit-verify
```

- The audit drain uses `FOR UPDATE SKIP LOCKED`, carries the hash-chain tail across batches (no N+1), and ejects poison rows via `attempt_count`/`last_error`.
- The appointment worker idempotently enqueues due Guidance bookings at T−15. Clinic confirmation and check-in remain staff-actioned; check-in opens the linked encounter and queue entry.
- The skip sweep resolves a skipped clinic patient's 60-minute recall window to no-show. It is idempotent (every candidate re-validated under a row lock), so the `post_system` hook can run the same sweep concurrently on dev/demo machines without double-writing.
- The reports worker claims queued jobs, streams aggregate CSV rows to disk without holding a transaction open, records provenance and row counts, and expires files after 30 days. Run **one** reports worker unless the claim is upgraded to `SKIP LOCKED`.
- The BMG watchdog sweeps every 15 min: offline devices (`BMG_OFFLINE_AFTER_MIN`, default 20 min), RTC-invalid devices, missed scheduled runs (`BMG_MISSED_RUN_GRACE_MIN`, default 30; only slots after the schedule was published plus a 10 min sync allowance, and after the batch started, can count as missed), and device commands that expired before the board picked them up (`BMG_COMMAND_TTL_MIN`, default 10). The first three dedupe per episode; an expired command notifies once, at the moment it is failed — `stop` is critical (the drum may still be turning), `start`/`run_now` are warnings. Only the last 24 h are announced, and disabled/archived boards are settled silently. `--hours` remains for legacy report-only firmware.
- Route non-zero exits and the nightly `synapse:audit-verify` result to operational alerting.

### MIS directory queue

Deploy the additive queue schema with `php spark cache:clear` then `php spark migrate` before enabling enqueue traffic. No scheduler is installed by this change. Run `php spark synapse:mis-sync-drain --limit=1` on a campus-network worker; `--tenant=N` restricts discovery to one tenant and `--limit` accepts 1–100 jobs. Each job explicitly selects its tenant and restores the previous context. Enqueue and automatic cooldown checks never call MIS.

`POST /api/v1/admin/users/sync-directory` accepts `{kind,dry_run,page_size?,max_pages?}` and returns 202 with a job. Poll `GET /api/v1/admin/users/sync-directory/jobs/latest` (job or null) or `/jobs/{id}`; both require `rbac.manage` and are tenant-scoped. Equivalent active submissions reuse the job; conflicting options return 409. One active job per tenant is enforced by tenant row serialization and a unique nullable guard.

States are queued, running, completed, failed and interrupted. Counts/current page describe committed pages only. Identity writes, page audit and progress commit together, including dry-run progress. Leases fence ownership before any page writes; expired workers become interrupted even when status is polled without a running scheduler. Lease duration is at least 15 minutes and expands for configured upstream timeout/recovery. Prior committed pages remain after failure/interruption; automatic sync never replays either state. Explicitly submit a new job for a fresh sweep from page 1. Job output has no person records, credentials or upstream exception details.

`php spark synapse:mis-sync` uses the same admission guard and executes the admitted job synchronously in CLI (including an equivalent queued job). It remains dry-run by default; `--apply --confirm` is required for production writes. `SYNAPSE_TENANT_ID` selects its tenant. HTTP dry-run and apply only enqueue; a worker is required for execution. Feature tests apply only against their isolated MySQL/MariaDB test schema, never the development schema; a pre-provisioned non-migrating run must receive the queue migration separately.

Commands include: `synapse:smoke`, `synapse:audit-drain`, `synapse:audit-verify`, `synapse:audit-clear`, `synapse:audit-orphans`, `synapse:reports-drain`, `synapse:appointments-enqueue-due`, `synapse:queue-skip-sweep`, `synapse:notify-drain`, `synapse:reorder-auto-check`, `synapse:bmg-device-watchdog`.

## Tests

```bash
composer test           # unit suite — framework-free, no database required
composer test:feature   # HTTP suite — self-provisions synapse_zcode_test (never dev data)
composer test:all       # both
composer scan:tenancy   # list every unscoped tenant query site with file:line
```

- **Unit (220 tests)** — crypto round-trip/tamper, keyset cursors, CSV redaction, BMG mass invariant, alert engine, analytics boundaries. Never boots the framework.
- **Non-migrating feature run** — `SYNAPSE_TEST_SKIP_MIGRATIONS=1 composer test:feature` uses an already-provisioned dedicated test schema without executing migrations. Tests still seed RBAC and write disposable test records. New migrations must be reviewed/applied separately before schema-removal behavior can be validated against the database.
- **Feature (25 tests)** — `AuthenticationFlowTest`, `BmgWorkflowTest`, `RbacMatrixTest` (403 matrix), `PublicRoutesTest`, `BootstrapSmokeTest`. Runs the real Router → Filters → Controller → Service → MySQL pipeline; migrations run once per suite. The two PHPUnit configs exist because the two bootstraps are mutually exclusive.
- **Tenancy ratchet** — `TenantScopeFitnessTest` source-scans every `->table()` site on 36 tenant tables and fails CI on any new unscoped site. On CI, the feature bootstrap hydrates DB credentials from `SYNAPSE_TEST_DB_HOST/USER/PASS` (plain names — GitHub Actions drops dotted env keys).

## Gotchas

**Every route 500s after editing `Config/*` or routes** — run `php spark cache:clear`. CI4 disk-caches config factories and file-locator listings in `writable/cache/`; stale `FactoriesCache_config` 500s every route, stale `FileLocatorCache` silently hides new migrations from `spark migrate`.

**Migration collation errors** — `utf8mb4_unicode_ci` only. `utf8mb4_0900_ai_ci` is MySQL-8-only and breaks MariaDB 10.4.

**Feature tests can't connect** — they need MariaDB/MySQL reachable with the `tests` DB group credentials (or the `SYNAPSE_TEST_DB_*` env vars on CI).
