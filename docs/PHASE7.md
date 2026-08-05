# SYNAPSE — Phase 7: Runtime Retrofit (Boot-to-Verified)

**Status:** Backend now BOOTS and is verified end-to-end over HTTP for the first time.
**Context:** The scaffold referenced numerous phantom framework APIs — the API had never actually run. This phase made the platform real without changing its architecture or security posture.

## Verified working (evidence: live HTTP calls against `php spark serve`, MariaDB 10.4)

| Check | Result |
|---|---|
| `composer install` + `composer test` | 21/21 unit tests, 53 assertions |
| `php spark migrate -n App` | all 11 migrations green (incl. BMG generated column + triggers) |
| `PermissionsAndGroupsSeeder` + `synapse:smoke` | fully green |
| `GET /api/v1/health` | canonical envelope |
| `POST /auth/login` | JWT issued + HttpOnly refresh cookie |
| `GET /auth/me` | id/email/permissions (`*` wildcard) |
| `GET /facilities/units`, `/dashboard/counters`, `/audit/events` | envelopes + keyset meta |
| `POST /clinic/encounters` | business write + same-txn audit outbox |
| Unauthenticated module route | `401` envelope + `WWW-Authenticate` |
| 6 bad logins | 5× `401` then **`429 auth.login_locked`** (envelope, `Retry-After`) |
| `synapse:audit-drain` | 15 real events drained, `prev_id` chain linked |
| `synapse:audit-verify` | chain intact up to id 15 |
| `POST /referrals/verify` (public) | minimum disclosure only: `{status, artifact_type, issuer}` |

## What was fictional and how it was fixed

1. **Boot chain** — `spark` called nonexistent `CommandRunner`; `index.php` used a nonexistent bootstrap path; `Config\Paths` used wrong property names. → Stock CI 4.7 `Boot::bootSpark()/bootWeb()` flow; corrected `Paths`.
2. **30 missing stock configs** (Cache, Cookie, Security, Session, Routing, Modules, Exceptions, …) grafted from `vendor/codeigniter4/framework/app/Config`; `app/Views/errors` grafted.
3. **`Config\Routes` class extended phantom `CodeIgniter\Config\Routes`** → procedural `Routes.php` on real `RouteCollection`; module loaders + `BaseRoutes` retyped. `/referrals/verify` handler got a leading `\` (router was prefixing the default namespace).
4. **Phantom classes removed**: `CodeIgniter\Filters\CorsFilter` → `Filters\Cors` (+ stock `$default` Cors config shape, env-driven allowlist preserved); Shield phantom models (`RefreshTokenModel`, `UsersAuthgroupsModel`) → new `App\Auth\UserProvider` (users + `auth_identities` reader); `Config\Logger`/`Validation` parents fixed; fictional `Config\Commands` deleted (spark auto-discovers).
5. **`BaseBuilder::lockForUpdate()` doesn't exist** → `BaseService::selectForUpdate()` (raw bound `SELECT … FOR UPDATE`); all 11 call sites converted; `RefreshTokenService` uses a bound raw query.
6. **CI4 never constructor-injects controllers** → all 5 controllers self-resolve dependencies (tests may still inject); `RefreshTokenService` readonly-reassignment fatal fixed.
7. **`Controller::validate()` signature clash** → `ApiController::makeValidation()`; `$this->validation` property now real.
8. **`Config\Services::database()` recursed infinitely** (no core `database` service) → direct `Database::connect()`.
9. **`PermissionService` queried a nonexistent `group` column** → join `auth_groups_users → auth_groups`; admin wildcard `*` now actually grants in `userHas()`.
10. **Filters returning Responses** declared `RequestInterface` return types (TypeError → 500) → union returns; **uncaught exceptions never hit after-filters**, so the canonical envelope now comes from `App\Exceptions\ApiExceptionHandler` wired via `Config\Exceptions::handler()` (redacted 500s, `ApiException` keeps status/codes).
11. **Cache keys used `:`** (reserved by CI4) in rate-limit buckets + login-lock keys → underscore format, IPv6-safe subjects.
12. **Shield composer auto-discovery hijacked routing** (Session authenticator, CSRF demands) → `Config\Modules::$discoverInComposer = false`; auth is JWT-only via `App\Auth`.
13. **Drain**: `SKIP LOCKED` gated on server support (MySQL 8+/MariaDB 10.6+; XAMPP MariaDB 10.4 falls back to `FOR UPDATE`).
14. **Shield time constants** (`HOUR` etc.) + `APP_NAMESPACE`/`COMPOSER_PATH` added to `Constants.php`; security preflight moved to `Constants.php` tail (`ENVIRONMENT`, not the nonexistent `CI_ENVIRONMENT`).
15. **Missing DB config bits**: real `CodeIgniter\Database\Config` parent, `$filesPath` for seeders, `encrypt => false` (the SSL array broke the MariaDB handshake).
16. **`App.php`** rewritten to stock CI 4.7 property shape (`$uriProtocol` etc.); persistence timezone pinned to UTC.

## Dev environment notes

- `backend/.env` (dev-only, NOT committed as example): MariaDB collation override `utf8mb4_general_ci`; production stays MySQL 8.4 `utf8mb4_0900_ai_ci`.
- `DevUserSeeder` (refuses production): `admin@synapse.dev` / `DevPassw0rd!`, admin group + explicit grants.
- `scripts/scaffold-audit.php` — CI vs. app symbol drift detector; `scripts/db-probe.php` — raw mysqli connectivity probe.
- Vendor Shield migrations are NOT run (`migrate -n App`); SYNAPSE owns its auth schema.

## Bootstrap (updated)

```bash
cd backend
cp .env.example .env   # fill dev values; MariaDB needs DBCollat override
composer install
php spark migrate -n App
php spark db:seed App\\Database\\Seeds\\PermissionsAndGroupsSeeder
php spark db:seed App\\Database\\Seeds\\DevUserSeeder   # dev only
php spark synapse:smoke
php spark serve --port 8090
composer test
```

## Remaining (Phase 8 candidates)

1. Inventory module (dangling `*.inventory.*` permissions) + Appointments module.
2. Notifications outbox worker.
3. User management CRUD / password reset.
4. Frontend run + Playwright E2E against the now-live API; `auth.login_locked` UX mapping.
5. DB-backed integration test tier.
