# SYNAPSE — University Platform (Phase 6)

Decoupled. **CodeIgniter 4.7+** stateless REST API + **React 18 / Vite / TypeScript** SPA. Database **`synapse_zcode`** (MySQL 8.4 LTS).

## Phase 6 Deliverables (Integrity Verification & Security Hardening)

Full detail in [`docs/PHASE6.md`](docs/PHASE6.md). **Phase 7 (runtime retrofit — the backend now boots and is HTTP-verified end-to-end): [`docs/PHASE7.md`](docs/PHASE7.md).** **Phase 8 (Inventory + Appointments + Notifications outbox, browser-verified): [`docs/PHASE8.md`](docs/PHASE8.md).** **Phase 9 (User management + notification bell, browser-verified): [`docs/PHASE9.md`](docs/PHASE9.md).**

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

## Bootstrap

```bash
# Backend
cd backend
cp .env.example .env
composer install
php spark migrate -n App
php spark db:seed App\\Database\\Seeds\\PermissionsAndGroupsSeeder
php spark db:seed App\\Database\\Seeds\\DevUserSeeder   # dev only
php spark synapse:smoke
php spark synapse:audit-drain
php spark synapse:audit-verify
php spark synapse:reports-drain --limit=10
composer test
php spark serve --port 8080

# Frontend
cd ../frontend
cp .env.example .env
npm install
npm run dev

# Optional E2E (requires both stacks running)
SYNAPSE_E2E=1 npx playwright test
```

## Background jobs

Production must run the durable outbox and generated-report workers outside
HTTP request processes:

```cron
* * * * * cd /path/to/zynapse/backend && php spark synapse:audit-drain --batch=500 --max-batches=10
* * * * * cd /path/to/zynapse/backend && php spark synapse:reports-drain --limit=10
15 2 * * * cd /path/to/zynapse/backend && php spark synapse:audit-verify
```

The reports worker claims queued jobs, streams aggregate CSV rows to disk,
records provenance and row counts, and expires retained files after 30 days.
Route non-zero command exits and the nightly verification result to the
deployment platform's alerting channel. Use a single reports worker unless
the queue claim is upgraded to `SKIP LOCKED` for multi-worker processing.

## Performance Notes (dev)

- **Fast Windows launcher**: run `.\dev-fast.ps1` from PowerShell. It starts
  both stacks, maps the bracketed repository through a safe temporary drive,
  and points Vite directly at the backend. Press Ctrl+C to stop both.
- **After changing routes/config or adding classes**: run
  `cd backend; php spark cache:clear` once so CodeIgniter rebuilds its config
  and file-locator caches.
- **Measured bottleneck**: the trivial `GET /api/v1/health` route took
  **~3–13 s** through the bracketed XAMPP/Apache path, while raw PHP was
  **~13–85 ms**. The safe-path launcher reduced CodeIgniter requests to
  roughly **~0.2–1.3 s** on this Windows machine. The remaining cost is
  framework boot on the single-threaded development server, not React.
- **Decisive fix (server-side)**: run PHP with **OPcache enabled** and serve `backend/public` via **XAMPP Apache** (mod_php or FastCGI) instead of the single-threaded built-in server; keep the Vite proxy target on that origin. `PHP_CLI_SERVER_WORKERS` is not supported on Windows, so Apache is the practical route to parallel + compiled requests.
- **Frontend wins already shipped**: route-level **code-splitting** (`React.lazy`) shrinks the initial bundle; the cold-load **`/auth/me` is de-duplicated** (bootstrap seeds the shared `['me']` query cache) — one fewer serialized round-trip.
- Production builds (`npm run build`) emit hashed per-route chunks; dev mode is always slower than a built bundle.

## Out of Phase Scope

- Inventory & Appointments SPA pages (backend shipped in Phase 8).
- User management CRUD / password reset (Phase 9).
- Clinic bulk import (Phase 9).
- Real-time push (WebSocket).
- File upload / object storage.
- Internationalization beyond English.
