# SYNAPSE — Phase 6: Integrity Verification & Security Hardening

**Status:** Delivered.
**Scope:** Backend only. No frontend surface changes (the SPA consumes the new error code `auth.login_locked` through the existing envelope/toast path).

---

## 1. P0 defect fixes (found during post-Phase 5 audit)

### 1.1 AES-256-GCM tag was discarded — counselling/referral note decryption could never succeed
`EncryptionService::encryptField()` produced a GCM tag but never stored it; `decryptField()` called `openssl_decrypt` without a tag, which **always fails** for GCM.

- **Fix:** the stored `notes_cipher` value is now `ciphertext || 16-byte tag`; decrypt splits the tag back off. Tamper of either ciphertext or tag raises.
- Envelope shape (3 columns: `ciphertext`, `nonce`, `key_version`) is unchanged.
- Pre-Phase 6 rows never carried a tag, so no recoverable data is affected by the format change.
- Cipher columns widened `VARBINARY(8192)` → `VARBINARY(16400)`: the service accepts 16 KiB plaintext, which never fit in 8192 bytes.

### 1.2 Keyset cursors generated invalid SQL
`KeysetPaginator::apply()` interpolated an undefined property into raw SQL with unbound `?` placeholders — **every page-2+ request was broken**. Additionally, `audit_events` uses `commited_at` (not `created_at`), and `BmgService::listUnits` uses aliased columns; both made cursors unusable, and `export` passed `limit=1000` into a paginator capped at 100.

- **Fix:** proper grouped builder predicates `(ts < ?) OR (ts = ? AND id < ?)`; `apply()`/`finalize()` accept `tsColumn`/`idColumn` (alias-safe) and an explicit `maxLimit` for bulk surfaces. Call sites updated (`commited_at` for audit, `u.created_at`/`u.id` for BMG).

### 1.3 `Config\App.php` fatal type errors
Several properties declared with types that contradicted their default values (`public bool $CSRFTokenName = 'synapse_csrf'`, `public int $cookiePrefix = ''`, `public int $reverseProxyIPs = []`, …) — a **compile-time fatal** whenever the class loads. All property types corrected.

### 1.4 `Config\Filters.php` missing closing brace
Parse error fixed (`php -l` now clean across the config tree).

---

## 2. Audit chain — verification shipped, drain hardened

### 2.1 `AuditChainVerifier` (new)
`app/Services/Audit/AuditChainVerifier.php` recomputes `commit_hash = SHA-256(prev_hash || payload_json)` from genesis (64 zero chars) and checks `prev_id` linkage, batched 1,000 rows at a time. Read-only.

- **Endpoint:** `GET /api/v1/audit/verify/{id}` (`audit.read`) → `{ ok, checked, verified_up_to, first_divergence }`.
- **CLI:** `php spark synapse:audit-verify [--to=N]` — exit 0 intact / 1 divergent.

### 2.2 `synapse:audit-drain` rewritten
Previous defects: `prev_id` always written NULL (chain linkage broken), claimed-but-absent row locking, per-row chain-tail lookup (N+1), no retry bookkeeping, stale "Phase 1 stub" description.

- Chain tail fetched once per batch and carried forward (`prev_id` + `prev_hash` correct).
- `SELECT … FOR UPDATE SKIP LOCKED` — parallel workers cannot double-claim.
- Poison rows: failing batch rolls back atomically; the offending row's `attempt_count`/`last_error` are bumped outside the transaction; rows at 5 attempts are ejected from selection. `last_error` stores exception class + truncated message only — never payloads.
- Real microsecond timestamps (`DateTimeImmutable`, UTC) instead of `date('…u')` (which always renders 000000).

---

## 3. Rate limiting — actually wired now

- `Config\Filters::$filters` used the URI pattern `api`, which matches only the literal path `api` — **no filter ever ran**. Fixed to `api/*` for `api_exc`, `api_log`, `api_ratelimit`. (`api_auth` intentionally stays per-route so public endpoints remain reachable.)
- `ApiRateLimitFilter` now resolves per-group limits: `global` → `RATELIMIT_GLOBAL_PER_MIN` (600), `auth` → `RATELIMIT_AUTH_PER_MIN` (30). Login/refresh routes carry `filter => api_ratelimit:auth`.

## 4. Per-account login lockout (new)

`App\Auth\LoginThrottleService` — cache-backed sliding-window failure counter per account:

- Key = `hash_hmac('sha256', lowercase(email), JWT_SECRET)` — attempted emails are never stored, logged, or audited.
- Defaults: 5 failures / 900 s window (`LOGIN_LOCKOUT_MAX_FAILURES`, `LOGIN_LOCKOUT_WINDOW_SECONDS`).
- Locked attempts short-circuit **before** `password_verify`, return `429 auth.login_locked` (+ `Retry-After`), and enqueue audit event `auth.login_locked` (`outcome=locked`, no PII).
- Success clears the counter. New error code `ApiErrorCode::AUTH_LOGIN_LOCKED`.

## 5. Counselling key-version lookup table (promised Phase 5+)

Migration `2026-01-06-000010_CounsellingKeyVersions`:

- `counselling_key_versions (version PK, key_ref UNIQUE, status, activated_at, retired_at, created_at)` — stores env var **names**, never key material. Seeded `1 → COUNSELLING_KEY`.
- `EncryptionService::keyFor()` resolves version → `key_ref` → env, with env fallback (`COUNSELLING_KEY` for the active version, `COUNSELLING_KEY_V{n}` for historical) when the table is absent (unit tests, pre-migration boot).

## 6. Test suite (previously zero backend tests)

`backend/phpunit.xml` + `tests/bootstrap.php` (pure-unit, no framework boot) + 4 suites:

| Suite | Covers |
|---|---|
| `EncryptionServiceTest` | round-trip, ciphertext/tag tamper detection, missing-tag rejection, key rotation via historical version, malformed key |
| `KeysetPaginatorTest` | cursor round-trip, URL safety, malformed-cursor rejection, sentinel/next-cursor logic, `commited_at` + alias-prefix keys |
| `CsvWriterRedactTest` | case-insensitive redaction, nested payloads, pass-through, full `REDACT_KEYS` sweep |
| `BmgMassInvariantTest` | ≤ passes, > fails, non-numeric fails |

Run: `cd backend && composer install && composer test`. (`CsvWriter::redact()` was made static+pure to be testable without an output stream.)

## 7. Hygiene

- Stale "stub"/"Phase 1" comments removed (`Routes.php`, drain, rate-limit filter).
- Audit + Counselling READMEs updated to match shipped behaviour (`CsvWriter::REDACT_KEYS` location, verify endpoints, rotation runbook).
- `.env.example`: lockout vars + key-rotation guidance.

---

## Out of scope (Phase 7 candidates, in priority order)

1. **Inventory module** — permissions `clinic.inventory.*` / `facilities.inventory.write` are seeded but dangle with no implementation; PROMPT.md expects an Inventory grid and `lockForUpdate()` on inventory rows.
2. **Appointments module** — PROMPT.md names an Appointments grid; nothing exists.
3. **Notifications outbox** — directive mandates the transactional outbox for "Audit and Notifications"; only audit is implemented.
4. **User management + password reset** — no user CRUD or reset flow; RBAC endpoints are read-only.
5. **Clinic bulk import** (deferred since Phase 5).
6. **Frontend awareness** — map `auth.login_locked` to a dedicated lockout message; surface audit chain verification in the Audit page.
7. **Integration test tier** — the unit suite is DB-free by design; drain/verifier/lockout deserve DB-backed tests against a staged `synapse_zcode`.
