# Synapse — In-Depth Project Analysis

**Date:** 2026-09-03 · **Branch:** `feat/bmg` (clean, in sync with origin) · **Head:** `a62fed4`
**Scope:** full monorepo — backend (CI4), frontend (React SPA), mobile (Flutter), tests/CI/docs.
**Method:** four parallel read-only deep dives + direct spot-verification of the load-bearing claims (file line counts, tenancy scanner, unit suite run: **220 tests / 590 assertions, green**).

---

## 1. Executive Summary

Synapse is a university clinic management platform for Foundation University (maroon `#800000` branding, Asia/Manila timezone), built as a three-tier monorepo:

| Tier | Stack | Size | State |
|---|---|---|---|
| `backend/` | CodeIgniter 4.7, PHP 8.3+, MySQL/MariaDB-only | 340 PHP files, ~40.6k lines in `app/` | Production-grade architecture, MySQL-locked |
| `frontend/` | React 18 + Vite + TypeScript, shadcn/Radix + Tailwind 4, TanStack Query/Table, Zustand | 195 TS/TSX files, ~35k lines | Strong; 4 god-pages remain |
| `mobile/` | Flutter (Provider, dio, secure storage) | 60 Dart files, ~21k lines | Feature-complete client, store-release blocked by config |

**Overall verdict:** an unusually disciplined codebase — genuine service-layer architecture, policy-based authorization, outbox pattern for audit/notifications, tenancy fitness ratchets, and honest documentation. The prior "remediation roadmap" (commits b5996c4 → a62fed4) demonstrably landed: tenancy scan sites went 305 → 1, the CI pipeline is real and passing, and the demo-credential leak in e2e specs was removed.

**Top risks (ranked):**
1. **MySQL/MariaDB hard lock-in** — ~40 migrations use `SIGNAL SQLSTATE` triggers, ENUM columns, CHECK constraints. SQLite is impossible; CI pins `mariadb:10.4` and warns against MySQL 8.
2. **Tenancy enforcement is convention + ratchet, not an interceptor** — one unscoped site remains (`ReferralService.php:211`, an INSERT that derives tenant from the row, so likely benign); any *new* service that skips scoping ships silently (acknowledged in README).
3. **God files** — `BmgService.php` 1,870 lines; `FacilitiesPage.tsx` 1,991 lines with 21 in-file dialog components; `api_service.dart` 1,854 lines.
4. **Mobile store-release blockers** — `com.example.*` bundle IDs (Android + iOS), debug-key release signing, no iOS signing team. Code is ready; packaging is not.
5. **Docs drift** — small but real: stale references in `CREDENTIALS.md` (dead `docs/PHASE13.md`, `scripts/nursetemp.json` paths), mobile README claims tests that don't exist, root README says refresh cookie `SameSite=Strict` while frontend README says `Lax`.

---

## 2. Backend — Architecture Deep Dive

### 2.1 Layout & request flow

Stateless REST API, modular monolith, ~200 routes under `/api/v1/`:

```
public/index.php → Filters (api_exc / api_log / api_ratelimit on api/*)
  → Router (Config/Routes.php delegates to Modules/*/Routes::register())
  → thin controller (makeValidation + authorize permission check)
  → service layer (txn() + policy->check() + query-builder SQL)
  → App\Http\ApiResponse envelope { success, data, errors, meta }
```

Five domain modules under `app/Modules/` (`Clinic`, `Counselling`, `Facilities`, `Referrals`, `Reports`), each owning its Controllers/Services/DTOs/Policies/Routes. `Modules/Shared/` is the kernel: `BaseService` (transaction wrapper + sanctioned `selectForUpdate()` raw-SQL hatch), `BasePolicy` (two-stage gate), `BaseDTO`, `ManilaDay`.

**Notable design choice:** there are **no CI4 Model/Entity/Library classes anywhere** — all data access is query-builder SQL inside services. Controllers are genuinely thin (largest: `AuthController.php` 340 lines).

Largest files: `BmgService.php` **1,870** (verified) · `MedicineService.php` 1,086 · `PatientService.php` 1,040 · `ClinicService.php` 1,000 · `AppointmentService.php` 964.

### 2.2 Domain modules

| Module | Scope |
|---|---|
| **Clinic** (97 routes, 12 controllers) | Patients (student/employee registries, allergies, contacts), appointments, encounters, queue, triage prediction, treatments, medicine inventory + forecasting, reorders, staff schedules, kiosk check-in, self-service portals |
| **Counselling** (21 routes) | Guidance sessions, availability, queue, scheduling analytics; **AES-256-GCM note encryption with key-versioned rotation** (`COUNSELLING_KEY`) |
| **Facilities / BMG** (36 routes) | Biomedical-garden composting: units, batches (state machine: start/finish/cancel/cure/release), inputs/outputs/losses, waste categories, process logs, alert engine, SOPs, analytics; **DB triggers enforce mass invariants** |
| **Referrals** (9 routes) | Referral workflow, encrypted notes, HMAC QR verify tokens |
| **Reports** (12 routes) | Saved configurations, queued generation, CSV export with provenance, 30-day expiry |
| Cross-cutting | Auth, RBAC, Admin, Audit (hash-chained), Notify, Dashboard |

### 2.3 Database

- **85 migrations** (single default group), **68 tables**, `2026-01-01` → `2026-08-29`.
- Schema spans: identity (`users`, `persons`, `patients_*`), Shield auth tables, 17 `clinic_*`, 7 `counselling_*`, 11 `facilities_bmg_*`/waste/SOP, referrals, reports, notifications + outboxes, audit, kiosk.
- **Seeding is properly gated:** `PermissionsAndGroupsSeeder` is production/load-bearing; all demo seeders throw `RuntimeException` in production and are idempotent (verified in `DevUserSeeder.php:22`, `AppointmentsSeeder.php:158`).

### 2.4 Auth & tenancy

- **Stateless JWT** (HS256, 900s TTL), refresh via HttpOnly cookie `synapse_rt` with rotation chain; login throttle (5 failures/15 min, HMAC-keyed); account-state gates (disabled → 401, temp password → 403 `auth.password_change_required`).
- **RBAC:** Shield groups + DB-driven permission matrix (10 groups: admin, clinic_staff, counsellor, kiosk, facilities_op, audit_reader, report_viewer, clinical_supervisor, student, employee). Permission checks are centralized; "NEVER hardcode role checks" is a documented rule.
- **Tenancy:** request-scoped `CurrentTenant` static (set from `users.tenant_id` in `ApiAuthFilter`), enforced by per-query `->where('tenant_id', ...)` across 35 files + a source-scanning ratchet (`composer scan:tenancy`) + `TenantScopeFitnessTest`. Current state: **1 flagged site** — `ReferralService.php:211` (verified: it's the INSERT of a `$row` built with tenant in scope, so the scanner's flag is conservative, not an active leak).
- **Policy layer:** two-stage `BasePolicy` (permission code → `canOnRecord()`), implemented for Clinic (attending user), Counselling (counsellor), BMG (batch owner; `BMG_ENFORCE_OWNERSHIP` flag, **fails closed**), Referrals. Policies are invoked by services themselves — a skipped check ships silently unless a contract test catches it.

### 2.5 Observability & jobs

- **Hash-chained append-only audit** (`audit_events` + durable `audit_outbox` written in the same transaction), drained by `synapse:audit-drain` (FOR UPDATE SKIP LOCKED, poison-row ejection), verifiable by `synapse:audit-verify`.
- 9 spark workers (`synapse:*`): smoke, audit-drain/verify/clear/orphans, reports-drain, appointments-enqueue-due (T−15 queue promotion), notify-drain, reorder-auto-check. Cron recipes documented; opportunistic in-request drains (cooldown-gated) cover dev/demo when no cron exists.
- Logging is deliberately sparse (7 call sites); 5xx responses redacted to `internal.error`.

### 2.6 Backend code health

- **Raw SQL beyond the sanctioned hatch:** ~30 `$this->db->query()` calls in Clinic services beyond `selectForUpdate()`. All use bound parameters — no SQL-injection vector found — but the raw-SQL surface is wider than the architecture doc implies.
- **N+1:** low (set-based joins; only small bounded per-patient child reads).
- **Zero TODO/FIXME/HACK markers** in `app/`.
- Validation is systematic (`ApiController::makeValidation()` + custom rules mirroring DB triggers, e.g. `BmgMassInvariant`).
- **Local hygiene:** a populated `backend/.env` (real `JWT_SECRET`, `COUNSELLING_KEY`) sits in the working tree — git-ignored, untracked, but worth flagging. Stray `probe.out`/`probe.err` at repo root (ignored).
- **Portability footgun documented:** `database.default.DBCollat` must be `utf8mb4_unicode_ci` (MariaDB 10.4 compatibility); setting `database.default.collation` is a silent no-op.

---

## 3. Frontend — Architecture Deep Dive

### 3.1 Stack (correcting the historical record)

The SPA is **not** Bootstrap/NiceAdmin — it is **shadcn/ui on Radix primitives + Tailwind CSS 4**, with TanStack Query 5 (server state), Zustand 4 (auth store), react-hook-form + zod (19 schema modules, 2,240 lines of runtime API contract), sonner (toasts), recharts, html5-qrcode + qrcode.react, jspdf. React Router v6.27 with all-lazy routes (`lazyWithRetry`). TypeScript is maximally strict (`strict`, `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`) — and the codebase contains **zero `any`/`@ts-ignore`/TODO**.

Vite config is tuned: `/api` proxy to `php spark serve` on :8090, custom middleware serving kiosk thumbnails from `../backend/public/kiosk-thumbnails` (32-hex-char guard), aggressive `optimizeDeps` pre-bundling for lazy pages, no-FOUC theme script in `index.html`.

### 3.2 Structure

23 pages, 36 hooks, ~27 shared components + inventory/reports/UI component families. No React contexts — state is Zustand + TanStack Query + URL params.

**God pages (top offenders by `wc -l`):**

| Lines | File | Problem |
|---|---|---|
| 1,991 (verified) | `pages/FacilitiesPage.tsx` | 21 dialog/card sub-components defined in one file; belongs in `components/facilities/` (the inventory module already proves the extraction pattern) |
| 1,634 | `pages/ClinicPage.tsx` | |
| 1,577 | `pages/PatientsPage.tsx` | Maintains parallel student/employee twin lists (5 URL filters duplicated) |
| 1,415 | `pages/CounsellingPage.tsx` | |
| 1,080 | `hooks/useFacilities.ts` | God-hook |

### 3.3 Auth & routing

- Access token **in memory only** (Zustand; never localStorage); refresh via HttpOnly cookie with single-flight refresh dedup + one replay. Cold-load rehydration via silent refresh → `/auth/me`.
- Route guards: `ProtectedRoute` with `anyOf`/`allOf` permission checks; role dispatchers (`HomeDispatcher`, `MyPortalDispatcher`); `force_reset` gates every route to `/change-password`.
- Public routes: `/login`, `/queue-display` (lobby TV board); `/kiosk-station` authenticated but chromeless.
- Real 404 in-shell + route-level `errorElement`; `/403` keeps shell.

### 3.4 Data layer & UX systems

- Axios client with `X-Request-Id`, envelope unwrapping, cursor metadata retention; retry policy never retries 4xx; per-domain polling intervals (queue 5–10s → dashboard 60s).
- `useUrlFilter` mirrors filters into URL query with debouncing (the fix from fc27/a62 era) — consumed by Patients (5), Appointments (2), Referrals (1), inventory tabs.
- Feedback layer: sonner toasts + `ConfirmDialog` + `QueryErrorState` with retry.
- Theming: `.dark` class + Tailwind v4 tokens, maroon `#800000` primary/sidebar, Figtree font, localStorage-persisted with no-FOUC boot script.
- A11y effort is real: 290 `aria-*` attributes in pages, skip-to-content, `role="alert"` error surfaces, keyboard row navigation, responsive mobile-card fallbacks.

### 3.5 Frontend code health

- **Dead code:** `showInactivePatientBanner = false` in `Layout.tsx:43` with full markup retained.
- **Hardcoded values:** `VITE_KIOSK_UPLOAD_BASE_URL` default `http://localhost:8090` in `.env.example`; phone placeholder format in PatientsPage; page descriptions manually synced per their own comment.
- **localStorage inconsistencies** (non-token): kiosk station id, offline scan buffer, QR proof token, theme, sidebar — fine individually, but worth a deliberate policy.
- **Unit coverage is thin:** 3 vitest files (envelope, errorCodes, date — pure logic). All behavioral coverage lives in 13 Playwright e2e specs.
- Router v7 migration debt (dual Suspense boundaries + `v7_startTransition` workaround documented).

---

## 4. Mobile — Reality Check (revised verdict)

The 2026-08-29 audit called this "unshippable." **That verdict is now stale.** Current state, verified:

- **~21k lines, 19 fully-wired screens, ~110 endpoint integrations including write operations.** Zero TODO/FIXME/stub markers in `lib/`.
- **Auth stack is genuinely mature:** token in `flutter_secure_storage` (with one-time plaintext migration), persistent cookie jar mirroring the web refresh cookie, single-replay silent refresh with in-flight dedup, **proactive refresh when JWT `exp` nears** (with clock-skew margin), session bootstrap before first frame.
- **Release-config discipline:** `API_BASE_URL` via `--dart-define` only — release builds **throw** rather than ship loopback. Cleartext HTTP confined to a debug-only manifest overlay. Android icons generated from brand logo.
- **Fail-fast errors, tab-aware auto-polling that pauses on hidden tabs, PDF export, QR booking proofs.**

**Actual ship-blockers (all config-level):**
1. `com.example.synapse_mobile` / `com.example.synapseMobile` bundle IDs (Android `build.gradle.kts` TODO comment still present; iOS pbxproj).
2. Android release buildType signs with the **debug key**; no iOS signing team.
3. Needs a real HTTPS deployment for `API_BASE_URL`.
4. Thin tests: 2 files, 10 cases (model parsing only) — no widget, API-client, or refresh-logic tests for a clinical client.
5. Poll-only notifications (no push), no offline mode, template web shell.

**Verdict:** production-quality code, production-incomplete packaging. The README is honest about all of this.

---

## 5. Tests, CI & Docs

### 5.1 Backend suites

Two deliberately mutually exclusive bootstraps (PHPUnit has no per-testsuite bootstrap): unit suite (`phpunit.xml`, framework-free, DB-free, 220 tests — **run today: OK**) and feature suite (`phpunit.feature.xml`, real kernel against MariaDB, 6 classes / 27 tests). Feature bootstrap self-provisions the schema, refuses to run outside `backend/`, and **refuses if the tests group resolves to the same schema as default** (protects dev data). `SYNAPSE_TEST_DB_*` uppercase env names are preferred for CI (GitHub can't export dotted keys).

Unit domains span auth/security, BMG invariants, clinic workflow contracts, queue FIFO, reports/CSV, pagination, tenancy fitness. Feature tests cover auth flows, BMG lifecycle, kiosk check-in + Manila queue-day partitioning, public-route leaks, RBAC matrix, bootstrap smoke.

### 5.2 Frontend tests

3 vitest files + 13 Playwright specs in two tiers: **mocked** (6 specs, run in CI via explicit filename list) and **live** (7 specs, ~21 tests, gated behind `SYNAPSE_E2E=1` + env credentials — no committed fallback; the historical dev-password leak into 9 specs was cleaned). Single Chromium project, single worker (stateful live stack), retries 2 in CI.

### 5.3 CI (`.github/workflows/ci.yml` — 3 jobs, all passing)

- **backend:** composer install + unit + feature suites against a pinned `mariadb:10.4` service container.
- **frontend:** typecheck + lint (max-warnings 0) + vitest + mocked e2e with Chromium.
- **mobile:** `flutter test` via subosito action.
- Gaps: **no Composer caching** (npm and Flutter are cached), no scheduled/manual dispatch, live e2e not wired into CI (documented as deferred).

### 5.4 Documentation accuracy (spot-checked)

- Root/backend/frontend READMEs: commands, ports (8090 API / 5173 SPA), module maps, seeder names — **all verified accurate**. Minor count drift (35 hooks vs 36 files).
- `CREDENTIALS.md`: intentional dev doc; credentials match seeders; **two dead references** (`docs/PHASE13.md`, `scripts/nursetemp.json`).
- `mobile/README.md`: **overstates test coverage** (claims `SessionProgressTracker` tests that don't exist as files).
- Root vs frontend README **contradict on cookie `SameSite`** (Strict vs Lax).
- `backend/.env.example` lacks a `database.tests.*` section (the required separate test schema is only discoverable from READMEs).

### 5.5 Hygiene

Tracked files contain no real secrets (only placeholders + intentional dev demo passwords). All build/test artifacts (`dist/`, `test-results/`, `writable/`, `probe.*`, mobile screenshots) are correctly git-ignored and untracked. Working tree clean.

---

## 6. Cross-Cutting Strengths

1. **Consistency of envelope and conventions** — one response shape, one validation path, one pagination style (keyset-only, OFFSET banned), one permission model, mirrored error codes between backend and SPA (`errorCodes.ts`).
2. **Security posture above the bar** — JWT + rotation + throttle, two-stage policies that fail closed, encrypted counselling notes with key rotation, HMAC QR tokens, hash-chained audit, production-gated seeders, strict CORS allowlist, sanitized uploads.
3. **Docs that tell the truth** — READMEs document gotchas (FactoriesCache stale-cache 500, DBCollat no-op, two-bootstrap split) rather than only happy paths.
4. **Test philosophy that matches the constraints** — contract/fitness tests (ratchets) where interceptors don't exist; e2e two-tier mocked/live strategy; stateful-stack serialization handled explicitly.

## 7. Ranked Recommendations

| # | Action | Effort | Why |
|---|---|---|---|
| 1 | Split `BmgService.php` (1,870) and `FacilitiesPage.tsx` (1,991) | Medium | Change amplification, review friction; extraction pattern already proven in `components/inventory/` |
| 2 | Add `database.tests.*` template block to `backend/.env.example`; fix the 4 doc drifts (dead CREDENTIALS refs, mobile test claim, SameSite contradiction) | Trivial | Removes real setup traps |
| 3 | Resolve or document `ReferralService.php:211` scanner flag | Trivial | Keeps the tenancy ratchet at a believable zero |
| 4 | Add Composer caching to CI | Trivial | Faster, cheaper pipelines |
| 5 | Mobile: real bundle IDs + release signing config (defer actual keys to release time) | Small | Removes the only code-adjacent ship-blocker |
| 6 | Frontend component/unit tests for high-risk pure logic (auth refresh replay, useUrlFilter, keyset pagination) | Medium | Currently only 3 vitest files; the trickiest client logic is only covered by e2e |
| 7 | Decide a deliberate localStorage policy for non-token state (kiosk station, scan buffer, QR token) | Small | Consistency with the "never localStorage for tokens" rule |
| 8 | Router v7 migration (dual Suspense + startTransition workaround) | Small | Known documented debt |
| 9 | Mobile test suite: widget + auth-refresh tests | Medium | Clinical client with model-only coverage |

**Deferred/accepted risks (documented, no action needed now):** MySQL-only lock-in (deliberate), per-query tenancy convention (ratchet-backed), in-request opportunistic drains (dev/demo only), IAB/kiosk right-click deterrent (acknowledged UX-only).
