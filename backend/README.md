# SYNAPSE Backend (Phase 6)

Stateless REST API. CodeIgniter 4.7+ on PHP 8.3+, MySQL 8.4 LTS (`synapse_zcode`).

## Phase 6 Additions (Integrity Verification & Security Hardening)

Full detail in [`../docs/PHASE6.md`](../docs/PHASE6.md).

- **GCM tag fix** — `EncryptionService` now appends/verifies the 16-byte auth tag (`notes_cipher = ciphertext || tag`); decryption previously always failed. Cipher columns widened to `VARBINARY(16400)`.
- **`counselling_key_versions`** — key-rotation lookup (version → env key_ref; names only, never material) with env fallback.
- **`KeysetPaginator` rewrite** — cursor predicates now use bound builder groups; `tsColumn`/`idColumn`/`maxLimit` params fix audit (`commited_at`), BMG (aliased join), and 5,000-row CSV export.
- **Audit chain verification** — `AuditChainVerifier` + `GET /api/v1/audit/verify/{id}` + `php spark synapse:audit-verify`.
- **`synapse:audit-drain` hardened** — correct `prev_id`, `FOR UPDATE SKIP LOCKED`, batch-carried chain tail, poison-row ejection (`attempt_count`/`last_error`).
- **Rate limiting wired** — `Config\Filters` URI pattern fixed to `api/*`; per-group buckets (`api_ratelimit:auth` = 30/min on login/refresh).
- **Login lockout** — `LoginThrottleService` (HMAC-keyed counters, `429 auth.login_locked`, `auth.login_locked` audit event).
- **Config fixes** — `Config\App` fatal property types, `Config\Filters` parse error.
- **Unit tests** — `composer test` (phpunit.xml + tests/unit): crypto, keyset, redaction, mass invariant.

## Phase 5 baseline

- `App\Validation\BmgMassInvariant` rule; `App\Services\Export\CsvWriter` (redaction now via static `CsvWriter::redact()`).
- Auth-event audit outbox (`auth.*` events; context keys `auth_method`, `outcome`, `family_id`).
- Structured `RefreshTokenService::rotate()`; two-stage `BasePolicy::enforce()` / `canOnRecord()`.

## Phase 2/3/4 baseline (unchanged)

- BMG state machine (`active_unit_id` UNIQUE + mass invariant triggers + `bmg_mass_invariant` rule).
- Clinic encounters + vitals; Counselling encrypted notes; Referrals bridge + QR.
- `JwtService`, `PermissionService`, `AuditOutboxService`, `EncryptionService`.
- CLI: `synapse:smoke`, `synapse:audit-drain`, `synapse:audit-verify`.
- Refresh-token rotation chain (Phase 4).
- Audit CSV export, dashboard counters, `ApiErrorCode` catalog, per-module READMEs (Phase 4).

## Bootstrap

```bash
cd backend
cp .env.example .env
composer install
php spark migrate --all
php spark db:seed App\\Database\\Seeds\\PermissionsAndGroupsSeeder
php spark db:seed App\\Database\\Seeds\\DevUserSeeder
php spark synapse:smoke
php spark synapse:audit-drain
php spark synapse:audit-verify
composer test
```

## Default development credentials

`DevUserSeeder` seeds an admin account for local/staging use (**DEV/STAGING ONLY** — it refuses to run when `ENVIRONMENT=production`):

| Field | Value |
|---|---|
| Email | `admin@synapse.dev` |
| Password | `DevPassw0rd!` |
| Username | `synapse-admin` |

The account is added to the `admin` group and granted every seeded permission code. Run `PermissionsAndGroupsSeeder` **before** `DevUserSeeder` so the groups and permissions exist. To (re)create the account after a database reset:

```bash
php spark db:seed App\\Database\\Seeds\\DevUserSeeder
```

## Module map

| Module | Routes | Entry controller | Policy |
|---|---|---|---|
| Facilities (BMG) | `app/Modules/Facilities/Routes.php` | `BmgController` | `BmgPolicy` |
| Clinic | `app/Modules/Clinic/Routes.php` | `ClinicController` | `ClinicPolicy` (record-level: attending_user_id) |
| Counselling | `app/Modules/Counselling/Routes.php` | `CounsellingController` | `CounsellingPolicy` (record-level: counsellor_user_id) |
| Referrals | `app/Modules/Referrals/Routes.php` | `ReferralController` | `ReferralPolicy` |
| Audit reader | `app/Controllers/Api/Audit/AuditEventController.php` | (no module) | n/a |
| Dashboard | `app/Controllers/Api/Dashboard/DashboardController.php` | (no module) | n/a |
| Auth | `app/Controllers/Api/Auth/AuthController.php` | (no module) | n/a |
