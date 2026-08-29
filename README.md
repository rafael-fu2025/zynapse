# SYNAPSE — University Platform (Phase 6)

Decoupled. **CodeIgniter 4.7+** stateless REST API + **React 18 / Vite / TypeScript** SPA. Database **`synapse_zcode`** (MySQL 8.4 LTS).

## Phase 6 Deliverables (Integrity Verification & Security Hardening)

### P0 defect fixes
- **AES-256-GCM tag** — the auth tag is now stored (appended to `notes_cipher`) and verified on decrypt; previously decryption always failed. Cipher columns widened to fit 16 KiB notes.
- **Keyset cursors** — `KeysetPaginator::apply()` emitted invalid SQL for every page-2+ request; rewritten with bound builder predicates + `tsColumn`/`idColumn`/`maxLimit` params (`commited_at` for audit, aliased columns for BMG, 5,000-row export).
- **`Config\App.php` / `Config\Filters.php`** — fatal property-type mismatches and a missing brace fixed.

### Security & integrity
- **Audit chain verification** — `GET /api/v1/audit/verify/{id}` + `php spark synapse:audit-verify` recompute the SHA-256 hash chain and report the first divergence (`AuditChainVerifier`).
- **`synapse:audit-drain` hardened** — correct `prev_id` linkage, `FOR UPDATE SKIP LOCKED`, batch-carried chain tail (no N+1), poison-row ejection via `attempt_count`/`last_error`.
- **Rate limiting live** — filter URI pattern fixed (`api/*`); strict `auth` bucket (30/min) on login/refresh.
- **Per-account login lockout** — `LoginThrottleService` (5 failures / 15 min, HMAC-keyed — no emails stored), `429 auth.login_locked` + audit event.
- **Key-rotation lookup** — `counselling_key_versions` maps version → env key_ref (names only, never key material).

### Tests
- Backend unit suite (`composer test`): crypto round-trip/tamper, keyset cursors, CSV redaction, BMG mass invariant.

### Phase 2–5 baseline (unchanged)
- BMG state machine with DB-level invariants (`active_unit_id` UNIQUE + mass invariant triggers + `bmg_mass_invariant` rule).
- Clinic encounters + vitals; Counselling encrypted notes (AES-256-GCM); Referrals bridge + QR.
- `JwtService` (HS256), `PermissionService` (DB-driven RBAC), `AuditOutboxService` + `synapse:audit-drain` CLI.
- Refresh-token rotation chain (Phase 4); audit CSV export; dashboard counters; canonical `ApiErrorCode`.
- Public minimum-disclosure `POST /referrals/verify` (returns only `{ status, artifact_type, issuer }`).
- Clinic / Counselling / Referrals / Audit / Facilities SPA pages with keyset pagination and Radix UI.
- RHF + Zod dialogs (Clinic / Counselling / Referrals).

## Hard Prohibitions (Reinforced)

- No SQL JOIN between `clinic_*` and `counselling_*`. Bridge via `referral_referrals`.
- No `md5`/`sha1` for security — `hash_hmac` or `password_hash` only.
- No `OFFSET` pagination — `KeysetPaginator` only.
- No physical DELETE — soft delete via `archived_at`.
- No logging of payloads, tokens, QR secrets, or clinical notes.
- No wildcard CORS in production (enforced by `Config\Boot`).
- BMG `output_weight_kg <= total_input_weight_kg` — `bmg_mass_invariant` rule + service assertion + DB trigger.
- `POST /referrals/verify` returns ONLY `{ status, artifact_type, issuer }`. Never PII.
- Access token lives in-memory in Zustand; refresh token is HttpOnly cookie. NEVER localStorage.
- Auth-event audit context keys: `auth_method`, `outcome`, `family_id` ONLY. Never email / password / token.
- Login lockout keys are HMAC digests — attempted emails are never stored, logged, or audited.
- `counselling_key_versions` stores env var NAMES only — key material never enters the DB.

## Quick Start (local)

Prerequisites: **PHP 8.3+** with `mysqli`/`mbstring`, **Composer**, **Node 22+**, **MariaDB 10.4+ / MySQL 8** (`utf8mb4_unicode_ci` — the MySQL-8-only `0900` collation is not portable), and optionally **Flutter 3.27+** for `mobile/`.

```bash
# 1. Database (dev schema)
mysql -u root -e "CREATE DATABASE IF NOT EXISTS synapse_zcode CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Backend
cd backend
cp .env.example .env            # then fill in the secrets (below)
composer install
php spark migrate --all
php spark db:seed App\\Database\\Seeds\\PermissionsAndGroupsSeeder
php spark db:seed App\\Database\\Seeds\\DevUserSeeder   # admin@synapse.dev (dev only)
php spark serve --port 8090

# 3. Frontend (Vite proxies /api -> :8090)
cd ../frontend
cp .env.example .env
npm install
npm run dev                     # http://localhost:5173
```

`.env` secrets — generate each with `openssl rand -hex 32`:
`JWT_SECRET`, `REFERRAL_HMAC_KEY`, `COUNSELLING_KEY` (keep `COUNSELLING_KEY_VERSION=1`).
Check `CORS_ALLOWED_ORIGINS` matches your frontend origin (`http://localhost:5173`).

For the full demo dataset (patients, appointments, counselling, referrals,
inventory, BMG units) additionally seed `PatientRegistrySeeder`,
`SeedDemoUsersSeeder`, `AppointmentsSeeder`, `CounsellingSeeder`,
`ReferralsSeeder`, `InventoryItemsSeeder`, `FacilitiesSeeder` — the account
matrix and passwords live in [`CREDENTIALS.md`](CREDENTIALS.md).

Mobile app: see [`mobile/README.md`](mobile/README.md).

## Tests

| Suite | Command | Needs |
|---|---|---|
| Backend unit (220) | `cd backend && composer test` | nothing |
| Backend feature/HTTP (25) | `cd backend && composer test:feature` | MariaDB running — the bootstrap self-provisions the `synapse_zcode_test` schema (never your dev data) |
| Both | `composer test:all` | |
| Frontend unit (Vitest) | `cd frontend && npm test` | nothing |
| Frontend gates | `npm run typecheck && npm run lint` | |
| Frontend mocked e2e | `npx playwright test` (the 6 specs that stub the API) | dev server (auto-started) |
| Frontend live e2e | `SYNAPSE_E2E=1 SYNAPSE_E2E_EMAIL=… SYNAPSE_E2E_PASSWORD=… npx playwright test` | backend on :8090; credentials from env only |
| Mobile | `cd mobile && flutter test` | |

CI runs every suite except live e2e per push/PR — see [`.github/workflows/ci.yml`](.github/workflows/ci.yml).

> **After changing `Config/*` classes or routes**, run `php spark cache:clear`.
> CI4 disk-caches config factories and file-locator listings in
> `backend/writable/cache/`; a stale `FactoriesCache_config` 500s every route
> after a Config property change, and a stale `FileLocatorCache` silently
> hides NEW migration files from `spark migrate`.

## Background jobs

Production must run the durable outbox and generated-report workers outside
HTTP request processes:

```cron
* * * * * cd /path/to/zynapse/backend && php spark synapse:audit-drain --batch=500 --max-batches=10
* * * * * cd /path/to/zynapse/backend && php spark synapse:reports-drain --limit=10
* * * * * cd /path/to/zynapse/backend && php spark synapse:appointments-enqueue-due
15 2 * * * cd /path/to/zynapse/backend && php spark synapse:audit-verify
```

The reports worker claims queued jobs, streams aggregate CSV rows to disk,
records provenance and row counts, and expires retained files after 30 days.
Route non-zero command exits and the nightly verification result to the
deployment platform's alerting channel. Use a single reports worker unless
the queue claim is upgraded to `SKIP LOCKED` for multi-worker processing.

## Performance Notes (dev)

- **After changing routes/config or adding classes**: run
  `cd backend; php spark cache:clear` once so CodeIgniter rebuilds its config
  and file-locator caches (see the Tests section caveat).
- **Measured bottleneck (Windows)**: the single-threaded PHP built-in server
  (`spark serve`) is the slow link — the trivial `GET /api/v1/health` measured
  ~13–85 ms raw versus seconds through some Apache paths. Serving
  `backend/public` via Apache/PHP-FPM with OPcache enabled (keeping the Vite
  proxy target on that origin) is the practical fix; `PHP_CLI_SERVER_WORKERS`
  is not supported on Windows.
- **Frontend**: route-level code-splitting (`React.lazy`) and the de-duplicated
  cold-load `/auth/me` are already in; production builds (`npm run build`) emit
  hashed per-route chunks. Dev mode is always slower than a built bundle.

## Out of Phase Scope

- Clinic bulk import.
- Real-time push (WebSocket).
- Internationalization beyond English.
