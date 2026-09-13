# SYNAPSE

Campus clinic, counselling, referrals, facilities, and governance — one traceable system.

SYNAPSE is a university health-services platform in three parts: a stateless REST API (**CodeIgniter 4.7 / PHP 8.3**), a React 18 admin portal, and a Flutter mobile client, all backed by MariaDB / MySQL (`synapse_zcode`). Sensitive work is encrypted where it matters, every mutation is audited into a hash-chained log, and each module keeps a hard boundary against its neighbors.

[![PHP](https://img.shields.io/badge/php-%5E8.3-777BB4.svg?logo=php&logoColor=white)](https://www.php.net/)
[![CodeIgniter](https://img.shields.io/badge/CodeIgniter-4.7-DD4814.svg)](https://codeigniter.com/)
[![React](https://img.shields.io/badge/React-18-61DAFB.svg?logo=react&logoColor=white)](https://react.dev/)
[![Flutter](https://img.shields.io/badge/Flutter-%E2%89%A53.6-02569B.svg?logo=flutter&logoColor=white)](https://flutter.dev/)
[![CI](https://img.shields.io/badge/CI-GitHub_Actions-2088FF.svg?logo=githubactions&logoColor=white)](.github/workflows/ci.yml)

## What's in the repo

| Path | What it is |
|---|---|
| [`backend/`](backend/README.md) | CodeIgniter 4 REST API — the source of truth. 5 domain modules over a shared kernel, JWT auth, RBAC, audit hash chain, 85 migrations. |
| [`frontend/`](frontend/README.md) | React 18 + Vite SPA — staff and student portal, web kiosk check-in, public lobby queue display. Strict TypeScript, Zod-validated responses. |
| [`mobile/`](mobile/README.md) | Flutter client — every module except kiosk check-in, with PDF report export and the same hardened token flow as the browser. |
| [`docs/`](docs/) | Compliance & operations: external-API data-sharing terms (RA 10173) and the RBAC/key-rotation runbook. |
| [`CREDENTIALS.md`](CREDENTIALS.md) | Dev/staging demo account matrix. Never production. |
| [`PRODUCT.md`](PRODUCT.md) | Product context, design principles, accessibility targets. |

## The domain

- **Clinic** — student/employee registry, encounter workflow (vitals → care → outcome), medicines and supplies inventory, appointments with destination-queue promotion, live waiting-room queue and kiosk self check-in.
- **Counselling (Guidance)** — session notes encrypted with AES-256-GCM (tag-verified, key rotation supported), availability scheduling, no-show analytics, its own queue.
- **Referrals** — the only bridge between Clinic and Counselling (no cross-module SQL JOINs, ever). QR artifact issuance plus a public minimum-disclosure verify endpoint.
- **Facilities (BMG)** — aerobic composting operations: batch state machine (`idle → processing → awaiting_output → curing`), C:N blending, curing and quality-graded release, EPA 40 CFR 503 sanitation compliance, alerting, and a DB-enforced mass invariant.
- **Reports** — saved configurations, worker-generated CSV exports with provenance, redaction, and 30-day retention.
- **Governance** — DB-driven RBAC, per-account login lockout, rate limiting, and an append-only audit log whose SHA-256 chain can be verified end to end via API or CLI.

## Quick start

Prerequisites: **PHP 8.3+** (`mysqli`, `mbstring`), **Composer**, **Node 22+**, **MariaDB 10.4+ / MySQL 8**. Flutter 3.27+ only if you want the mobile app. Collation must be `utf8mb4_unicode_ci` — the MySQL-8-only `0900` collation is not portable.

```bash
# 1. Database
mysql -u root -e "CREATE DATABASE IF NOT EXISTS synapse_zcode CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Backend
cd backend
cp .env.example .env
composer install
php spark migrate --all
php spark db:seed App\\Database\\Seeds\\PermissionsAndGroupsSeeder
php spark db:seed App\\Database\\Seeds\\DevUserSeeder      # admin@synapse.dev, dev only
php spark serve --port 8090

# 3. Frontend (Vite proxies /api -> :8090)
cd ../frontend
cp .env.example .env
npm install
npm run dev                                              # http://localhost:5173
```

Generate each backend secret with `openssl rand -hex 32`: `JWT_SECRET`, `REFERRAL_HMAC_KEY`, `COUNSELLING_KEY` (keep `COUNSELLING_KEY_VERSION=1`). Set `CORS_ALLOWED_ORIGINS` to your frontend origin (`http://localhost:5173`).

Login with the dev admin account (see [`CREDENTIALS.md`](CREDENTIALS.md)). For the full demo dataset — patients, appointments, counselling, referrals, inventory, BMG units — additionally run the seeders listed in [`backend/README.md`](backend/README.md#database--seeders). Mobile setup lives in [`mobile/README.md`](mobile/README.md).

## Repository layout

```
zynapse/
├── backend/                       # CodeIgniter 4 REST API
│   ├── app/
│   │   ├── Auth/                  # JwtService, refresh rotation, throttling, account state
│   │   ├── Commands/              # 9 spark workers: audit drain/verify, reports, queues…
│   │   ├── Config/                # root Routes.php, Filters, Constants
│   │   ├── Controllers/Api/       # Auth, Rbac, Admin, Audit, Dashboard, Notify, Kiosk
│   │   ├── Database/              # 85 migrations, 10 seeders
│   │   ├── Filters/               # api_auth, rate limit, exception envelope, CORS
│   │   ├── Modules/               # Clinic · Counselling · Facilities · Referrals · Reports
│   │   │                          #   each with Controllers/ Services/ DTOs/ Policies/ Routes.php
│   │   │                          #   + Shared/ kernel (BaseService, BasePolicy, BaseRoutes)
│   │   └── Services/              # CurrentTenant, CSV export, encryption helpers
│   └── tests/                     # unit/ (framework-free) + Feature/ (HTTP, MariaDB)
├── frontend/
│   ├── e2e/                       # 13 Playwright specs — mocked tier in CI, live tier opt-in
│   └── src/
│       ├── api/                   # axios client, envelope + error-code catalogs
│       ├── components/            # shared UI + inventory/ and reports/ dialog folders
│       ├── hooks/                 # 35 domain hooks (react-query per module)
│       ├── pages/                 # 23 route pages, RBAC-guarded and lazy-loaded
│       ├── schemas/               # 19 Zod modules — the runtime API contract
│       └── store/                 # Zustand (auth in memory only)
├── mobile/
│   ├── lib/core/                  # config, Dio client, models, AuthController, ApiService
│   └── lib/features/              # screens per module (kiosk check-in stays web-only)
└── .github/workflows/ci.yml       # backend · frontend · mobile jobs
```

## Talking to the API

Base URL in development: `http://localhost:8090/api/v1` (the SPA dev server proxies `/api/v1` there).

- **Auth** — `Authorization: Bearer <access-token>`; the access token is short-lived (15 min) and lives in memory (SPA) or secure storage (mobile). The refresh token is an `HttpOnly; Secure; SameSite=Strict` cookie, rotated on every use, with replay detection.
- **Envelope** — every response is `{ success, data, errors, meta }`. Errors carry stable codes (`resource.not_found`, `auth.login_locked`, …); 5xx bodies are redacted to `internal.error` and never leak messages or traces.
- **Public endpoints (deliberate, leak-tested)** — `/health`, `/auth/login`, `/auth/refresh`, `/clinic/queue/state` (lobby TV), `/appointments/verify` and `/referrals/verify` (QR, minimum disclosure), kiosk media/settings reads.
- **Pagination** — keyset cursors only; OFFSET pagination is banned.

## Configuration

Backend `.env` (from `backend/.env.example`, never committed):

| Variable | Purpose |
|---|---|
| `JWT_SECRET` · `JWT_ALG` · `JWT_*_TTL_SECONDS` | Token signing and lifetimes (15 min access / 30 d refresh) |
| `COUNSELLING_KEY` · `COUNSELLING_KEY_VERSION` | AES-256-GCM note encryption; rotate via `counselling_key_versions` + `COUNSELLING_KEY_V1`… |
| `REFERRAL_HMAC_KEY` | QR token HMAC |
| `CORS_ALLOWED_ORIGINS` | Strict allowlist — no wildcards in production |
| `RATELIMIT_GLOBAL_PER_MIN` · `RATELIMIT_AUTH_PER_MIN` | Fixed-window buckets (600 / 30) |
| `EXTERNAL_AUDIT_SAMPLE_LIVE` · `EXTERNAL_AUDIT_SAMPLE_TEST` | External-API `external.request` audit sampling per key env (1.0 / 0.2) |
| `LOGIN_LOCKOUT_MAX_FAILURES` · `LOGIN_LOCKOUT_WINDOW_SECONDS` | Per-account lockout (5 failures / 15 min) |
| `FFMPEG_BINARY` | Kiosk video thumbnails |

Frontend vars (`frontend/.env.example`): `VITE_API_BASE_URL`, `VITE_KIOSK_UPLOAD_BASE_URL`, `VITE_APP_TZ`. Mobile: `API_BASE_URL` via `--dart-define` (release builds refuse to start without it).

## Background workers

Production must drain the durable outbox and report queue outside HTTP processes:

```cron
* * * * * cd /path/to/zynapse/backend && php spark synapse:audit-drain --batch=500 --max-batches=10
* * * * * cd /path/to/zynapse/backend && php spark synapse:reports-drain --limit=10
* * * * * cd /path/to/zynapse/backend && php spark synapse:appointments-enqueue-due
15 2 * * * cd /path/to/zynapse/backend && php spark synapse:audit-verify
```

The appointment worker promotes Clinic and Guidance bookings into their destination queues at T−15. The reports worker streams CSV rows to disk without holding a transaction open and expires files after 30 days — run a single reports worker. Route non-zero exits and the nightly verification result to alerting. Full command list: [`backend/README.md`](backend/README.md#cli-workers--commands).

## House rules (enforced by tests where possible)

- No SQL JOIN between `clinic_*` and `counselling_*` — bridge via `referral_referrals`.
- No `md5`/`sha1` for security — `hash_hmac` or `password_hash` only.
- No `OFFSET` pagination — `KeysetPaginator` only. No physical DELETE — soft delete via `archived_at`.
- No logging of payloads, tokens, QR secrets, or clinical notes.
- BMG mass invariant `output_weight_kg <= total_input_weight_kg` — validation rule + service assertion + DB trigger.
- `POST /referrals/verify` returns only `{ status, artifact_type, issuer }`. Never PII.
- Access token in memory / secure storage; refresh token in an HttpOnly cookie. Never `localStorage`.
- Auth-event audit context keys: `auth_method`, `outcome`, `family_id` only. Lockout keys are HMAC digests — attempted emails are never stored.
- `counselling_key_versions` stores env var names only — key material never enters the DB.
- New tenant tables must stay inside the `TenantScopeFitnessTest` baseline (see below).

## Testing & CI

| Suite | Command | Needs |
|---|---|---|
| Backend unit (220) | `cd backend && composer test` | nothing |
| Backend feature/HTTP (25) | `composer test:feature` | MariaDB — self-provisions `synapse_zcode_test`, never touches dev data |
| Frontend unit (Vitest) | `cd frontend && npm test` | nothing |
| Frontend gates | `npm run typecheck && npm run lint` | |
| Frontend mocked e2e | `npx playwright test` | dev server (auto-started) |
| Frontend live e2e | `SYNAPSE_E2E=1 SYNAPSE_E2E_EMAIL=… SYNAPSE_E2E_PASSWORD=… npx playwright test` | backend on :8090, credentials from env only |
| Mobile | `cd mobile && flutter test` | |

CI ([`ci.yml`](.github/workflows/ci.yml)) runs every suite except live e2e on each push/PR: a backend job against a MariaDB 10.4 service, a frontend job (typecheck, lint, Vitest, mocked Playwright), and a mobile job. The backend also ships a tenancy ratchet — `TenantScopeFitnessTest` fails the build if any new unscoped tenant query site appears, and `composer scan:tenancy` prints the current worklist.

## Production checklist

1. MariaDB/MySQL with `utf8mb4_unicode_ci`; `php spark migrate --all`.
2. `composer install --no-dev`; production `.env` (`CI_ENVIRONMENT = production`, `REFRESH_COOKIE_SECURE = true`, strict `CORS_ALLOWED_ORIGINS`).
3. `npm run build`; serve `frontend/dist` behind a proxy that forwards `/api/v1` to the PHP backend (Apache/PHP-FPM with OPcache — the single-threaded `spark serve` is dev-only and the measured bottleneck on Windows).
4. Re-run the PermissionsAndGroupsSeeder after the 2026-09 RBAC migration and mint the Platform Owner: `php spark synapse:promote-superadmin <email> --confirm`. Old `admin` memberships carry over to `clinic_admin`; staff sign in again to refresh their permission set. See [`docs/OPERATIONS.md`](docs/OPERATIONS.md).
5. Install the cron workers above and wire their failures to alerting.
6. Do **not** seed `DevUserSeeder` or any demo seeder in production (this includes `SandboxTenantSeeder` — a production sandbox tenant starts empty by design).

## Troubleshooting

**Every route 500s after editing `Config/*` or adding classes** — CI4 disk-caches config factories and file-locator listings in `backend/writable/cache/`. Run `cd backend && php spark cache:clear`. A stale `FactoriesCache_config` 500s every route after a Config change; a stale `FileLocatorCache` silently hides new migrations from `spark migrate`.

**Migration collation errors** — use `utf8mb4_unicode_ci` everywhere; `utf8mb4_0900_ai_ci` is MySQL-8-only and breaks MariaDB 10.4.

**Frontend can't reach the backend** — confirm the backend origin (`:8090`) and `CORS_ALLOWED_ORIGINS` match the frontend origin.

**Live e2e tests won't run** — they're opt-in: `SYNAPSE_E2E=1` plus `SYNAPSE_E2E_EMAIL` / `SYNAPSE_E2E_PASSWORD`.

**Port already in use** — `php spark serve --port=8091` and update the Vite proxy target.

## Contributing

Branch from `main` as `feature/…` / `fix/…` / `docs/…`. Backend changes need unit tests (and feature tests for HTTP behavior); frontend changes must pass `typecheck && lint && test` and add a mocked Playwright spec for new flows. Respect the house rules above. Run `php spark cache:clear` after touching Config or routes.
