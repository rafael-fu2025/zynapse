# SYNAPSE Backend

The CodeIgniter 4.7 REST API behind everything — React SPA, Flutter app, and web kiosk all consume these endpoints. PHP 8.3+, MariaDB 10.4+ / MySQL 8 (`synapse_zcode`), stateless JWT auth, DB-driven RBAC, hash-chained audit log.

## How it's built

Five domain modules — **Clinic**, **Counselling**, **Facilities (BMG)**, **Referrals**, **Reports** — each owning its own `Controllers/ Services/ DTOs/ Policies/ Routes.php`, over a `Modules\Shared` kernel (`BaseService`, `BasePolicy`, `BaseRoutes`, `BaseDTO`). Cross-cutting surfaces (auth, RBAC, admin, audit, dashboard, notifications, kiosk) live in root `Controllers/Api`. Modules register their own routes via `Routes::register()`, so root `Config/Routes.php` stays small.

Two deliberate design choices worth knowing before you contribute:

- **No CI4 Model classes.** All data access is query-builder SQL inside services. Tenancy is therefore explicit — every query carries a `CurrentTenant::id()` predicate — and guarded by the `TenantScopeFitnessTest` ratchet (see [Tests](#tests)) rather than by model scopes.
- **MySQL/MariaDB only.** The schema leans on `SIGNAL SQLSTATE` triggers, ENUM columns, and CHECK constraints; SQLite is not an option, which is why the feature suite needs a real MariaDB.

Concurrency is uniform: every mutation runs in `txn()` with `selectForUpdate()` row locks, and the audit write joins the same transaction.

```
app/
├── Auth/                    JwtService, RefreshTokenService (rotation chain),
│                            LoginThrottleService, AccountStateService, CurrentUser
├── Commands/                9 spark workers (see below)
├── Config/                  root Routes.php, Filters, Constants
├── Controllers/Api/         Auth · Rbac · Admin · Audit · Dashboard · Notify · Kiosk
├── Database/
│   ├── Migrations/          85 timestamped migrations (2026-01 → 2026-08)
│   └── Seeds/               10 seeders — PermissionsAndGroupsSeeder is load-bearing
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

cp .env.example .env        # then generate JWT_SECRET, REFERRAL_HMAC_KEY,
                            # COUNSELLING_KEY (each: openssl rand -hex 32)
composer install
php spark migrate --all
php spark db:seed App\\Database\\Seeds\\PermissionsAndGroupsSeeder
php spark db:seed App\\Database\\Seeds\\DevUserSeeder
php spark synapse:smoke
php spark serve --port 8090
```

`DevUserSeeder` creates `admin@synapse.dev` / `DevPassw0rd!` (username `synapse-admin`, full admin group) and **refuses to run when `ENVIRONMENT=production`**. Always seed `PermissionsAndGroupsSeeder` first — it creates the groups and permission codes everything else depends on, and it's also the feature-suite seed. The demo seeders (`PatientRegistrySeeder`, `SeedDemoUsersSeeder`, `AppointmentsSeeder`, `CounsellingSeeder`, `ReferralsSeeder`, `InventoryItemsSeeder`, `FacilitiesSeeder`, `ClinicAnalyticsSeeder`) build the full demo dataset; the account matrix is in [`../CREDENTIALS.md`](../CREDENTIALS.md).

## Environment

Key `.env` variables (full annotated template in `.env.example`):

| Variable | Purpose |
|---|---|
| `JWT_SECRET` / `JWT_ALG` / `JWT_ACCESS_TTL_SECONDS` / `JWT_REFRESH_TTL_SECONDS` | Token signing + lifetimes (900 s / 30 d defaults) |
| `COUNSELLING_KEY` / `COUNSELLING_KEY_VERSION` | AES-256-GCM note encryption. Rotate by bumping the version, inserting a `counselling_key_versions` row, and exposing the retired key as `COUNSELLING_KEY_V1` etc. |
| `REFERRAL_HMAC_KEY` | QR token HMAC |
| `REFRESH_COOKIE_*` | Refresh cookie attributes (`synapse_rt`, Secure, HttpOnly, SameSite=Strict) |
| `CORS_ALLOWED_ORIGINS` | Strict origin allowlist — no wildcards in production (enforced by `Config\Boot`) |
| `RATELIMIT_GLOBAL_PER_MIN` / `RATELIMIT_AUTH_PER_MIN` | Fixed-window buckets (600 / 30) |
| `LOGIN_LOCKOUT_MAX_FAILURES` / `LOGIN_LOCKOUT_WINDOW_SECONDS` | Per-account lockout (5 failures / 900 s) |
| `FFMPEG_BINARY` | Kiosk video thumbnails |

Note: the Database config reads collation from `database.default.DBCollat` (not `collation`) — setting `database.default.collation` is a no-op.

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
- **Public endpoints (deliberate)** — `/health`, `/auth/login`, `/auth/refresh`, `/clinic/queue/state` (lobby TV, minimum disclosure), `/appointments/verify` + `/referrals/verify` (QR; returns only `{ status, artifact_type, issuer }`), kiosk media/settings reads (unguessable-UUID bearer). All locked open *and* leak-free by `PublicRoutesTest`.
- **Rate limits** — `api_ratelimit` across `api/*`; a strict `auth` bucket (30/min) on login/refresh; per-account lockout after 5 failures in 15 min (HMAC-keyed counters — attempted emails are never stored, logged, or audited).

## Background workers

Production drains the durable outbox and report queue outside HTTP processes:

```cron
* * * * * cd /path/to/zynapse/backend && php spark synapse:audit-drain --batch=500 --max-batches=10
* * * * * cd /path/to/zynapse/backend && php spark synapse:reports-drain --limit=10
* * * * * cd /path/to/zynapse/backend && php spark synapse:appointments-enqueue-due
15 2 * * * cd /path/to/zynapse/backend && php spark synapse:audit-verify
```

- The audit drain uses `FOR UPDATE SKIP LOCKED`, carries the hash-chain tail across batches (no N+1), and ejects poison rows via `attempt_count`/`last_error`.
- The appointment worker is idempotent and promotes Clinic and Guidance bookings into their destination queues at T−15.
- The reports worker claims queued jobs, streams aggregate CSV rows to disk without holding a transaction open, records provenance and row counts, and expires files after 30 days. Run **one** reports worker unless the claim is upgraded to `SKIP LOCKED`.
- Route non-zero exits and the nightly `synapse:audit-verify` result to operational alerting.

All nine commands: `synapse:smoke`, `synapse:audit-drain`, `synapse:audit-verify`, `synapse:audit-clear`, `synapse:audit-orphans`, `synapse:reports-drain`, `synapse:appointments-enqueue-due`, `synapse:notify-drain`, `synapse:reorder-auto-check`.

## Tests

```bash
composer test           # unit suite — framework-free, no database required
composer test:feature   # HTTP suite — self-provisions synapse_zcode_test (never dev data)
composer test:all       # both
composer scan:tenancy   # list every unscoped tenant query site with file:line
```

- **Unit (220 tests)** — crypto round-trip/tamper, keyset cursors, CSV redaction, BMG mass invariant, alert engine, analytics boundaries. Never boots the framework.
- **Feature (25 tests)** — `AuthenticationFlowTest`, `BmgWorkflowTest`, `RbacMatrixTest` (403 matrix), `PublicRoutesTest`, `BootstrapSmokeTest`. Runs the real Router → Filters → Controller → Service → MySQL pipeline; migrations run once per suite. The two PHPUnit configs exist because the two bootstraps are mutually exclusive.
- **Tenancy ratchet** — `TenantScopeFitnessTest` source-scans every `->table()` site on 36 tenant tables and fails CI on any new unscoped site. On CI, the feature bootstrap hydrates DB credentials from `SYNAPSE_TEST_DB_HOST/USER/PASS` (plain names — GitHub Actions drops dotted env keys).

## Gotchas

**Every route 500s after editing `Config/*` or routes** — run `php spark cache:clear`. CI4 disk-caches config factories and file-locator listings in `writable/cache/`; stale `FactoriesCache_config` 500s every route, stale `FileLocatorCache` silently hides new migrations from `spark migrate`.

**Migration collation errors** — `utf8mb4_unicode_ci` only. `utf8mb4_0900_ai_ci` is MySQL-8-only and breaks MariaDB 10.4.

**Feature tests can't connect** — they need MariaDB/MySQL reachable with the `tests` DB group credentials (or the `SYNAPSE_TEST_DB_*` env vars on CI).
