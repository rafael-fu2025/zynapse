# SYNAPSE — Windows Local Dev Setup

> **Scope:** Stand up SYNAPSE end-to-end on a fresh Windows dev box.
> Backend = CodeIgniter 4.7 on PHP 8.3 + MariaDB 10.4. Frontend =
> React 18 + Vite 5 + TypeScript.
>
> **Audience:** A developer who has never run this repo before.
> ~15–25 min of active waiting, mostly downloads.

---

## 1. Prerequisites

| Tool | Where we get it | Why |
|---|---|---|
| **Windows 10/11 (64-bit)** | already on your box | host OS |
| **XAMPP 8.x** with MariaDB | `C:\Users\udtoh_lmtzs7k\xampp\` | already installed by previous devs |
| **PowerShell 7+** (`pwsh`) | `winget install Microsoft.PowerShell` | runs the helper scripts |
| **PHP 8.3 portable** | downloaded by `setup.ps1` | CodeIgniter 4 needs 8.x |
| **Composer 2.x** | downloaded by `setup.ps1` | PHP dependency manager |
| **Node.js 20 LTS** | `winget install OpenJS.NodeJS.LTS` | Vite + npm |
| **Visual C++ Redistributable x64** | `aka.ms/vs/17/release/vc_redist.x64.exe` | PHP DLL runtime |

If XAMPP is not yet installed, install it to **`C:\Users\udtoh_lmtzs7k\xampp\`** (any
other path breaks our helper script paths). During XAMPP install, uncheck
running Apache/MySQL as services — we control startup manually.

---

## 2. One-shot bootstrap

Open **PowerShell 7** (`pwsh`) at the repo root and run:

```powershell
pwsh ./scripts/setup.ps1
```

That single command will, in order:

1. Download PHP 8.3 to `C:\Users\udtoh_lmtzs7k\php83\` (≈30 MB)
2. Download Composer to `C:\Users\udtoh_lmtzs7k\composer\`
3. Copy `backend/.env.example` → `backend/.env` and inject random secrets
4. Start MariaDB (via `_start-mariadb.ps1`) and create `synapse_zcode`
5. `composer install` in `backend/`
6. `php spark migrate --all` (61 migrations)
7. Seed `PermissionsAndGroupsSeeder` + `DevUserSeeder` + `SeedDemoUsersSeeder`
8. `npm install` in `frontend/`

Every step is **idempotent** — re-running `setup.ps1` skips the artifacts
that already exist. So you can re-run it after pulling new commits without
blowing away your `.env` or seeded data.

If a step fails (network down, port conflict, etc.) the script aborts at
the first error with a colour-coded message. Fix the underlying issue
and re-run.

---

## 3. Start the dev stack

```powershell
pwsh ./scripts/dev-up.ps1
```

What this does:

- Starts MariaDB (if not already running) via `_start-mariadb.ps1`
- Launches `php spark serve` on `127.0.0.1:8090` as a PowerShell Job
- Launches `npm run dev` on `127.0.0.1:5173` with `--strictPort`
- Writes `.dev-pids.json` to the repo root so `dev-down.ps1` can find them
- Waits on the backend Job — Ctrl+C in this terminal tears everything down

URLs once it reports ready:

| Service | URL |
|---|---|
| Backend API | http://127.0.0.1:8090/ |
| Open the SPA | http://127.0.0.1:5173/ |
| Health check | http://127.0.0.1:8090/api/v1/health |

### Optional flags

```powershell
pwsh ./scripts/dev-up.ps1 -SkipFrontend   # backend only
pwsh ./scripts/dev-up.ps1 -SkipDb         # don't (re)start MariaDB
pwsh ./scripts/dev-up.ps1 -SkipBackend    # frontend only
```

### Stopping the stack

From the original `dev-up.ps1` terminal: hit **Ctrl+C** (sends SIGINT
to the Wait-Job block which then cascades).

From any other terminal:

```powershell
pwsh ./scripts/dev-down.ps1                # stops backend + frontend, leaves MariaDB up
pwsh ./scripts/dev-down.ps1 -StopDb        # also stops MariaDB
pwsh ./scripts/dev-down.ps1 -Force         # also nukes orphaned php/node procs
```

The PID tracking file is `.dev-pids.json` at the repo root. It is
gitignored.

---

## 4. Manual verification (3 commands)

```powershell
# 1. Backend health endpoint
curl http://127.0.0.1:8090/api/v1/health
# expect: {"success":true,...}

# 2. Login as the seeded admin
$login = Invoke-RestMethod -Method POST `
    -Uri http://127.0.0.1:8090/api/v1/auth/login `
    -ContentType 'application/json' `
    -Body '{"email":"admin@synapse.dev","password":"DevPassw0rd!"}'
$login.access_token   # expect: a long JWT

# 3. Frontend HTML shell
curl http://127.0.0.1:5173/
# expect: HTML containing "/src/main.tsx"
```

If all three return successfully, the stack is up and the SPA can talk
to the API via the Vite `/api` proxy.

### Other dev accounts

See [`CREDENTIALS.md`](CREDENTIALS.md) for the full 9-account demo matrix
(canonical passwords: `StudentAndrei2026!`, `TeachingProf2026!`,
`StaffBrando2026!`).

---

## 5. Tests

Backend unit suite (PHPUnit, ~20 specs — BMG alert engine, loss-category
contract, tenant filter, mass invariant, etc.):

```powershell
cd backend
composer test
```

Frontend type-check + lint:

```powershell
cd frontend
npm run typecheck
npm run lint
```

---

## 6. Troubleshooting

| Symptom | Fix |
|---|---|
| `mysqld` won't start / port 3306 in use | `Get-Process mysqld` → kill stale instances, or `net stop mysql` if a Windows service is running |
| `php.exe` complains about a missing DLL | Install VC++ Runtime: https://aka.ms/vs/17/release/vc_redist.x64.exe |
| Migration fails with "Can't DROP CONSTRAINT" | Re-run `pwsh ./scripts/setup.ps1` — the migrations are idempotent now; a re-run from this state will succeed |
| `php spark` runs the wrong PHP | Check that `dev-up.ps1` is the entry point — system-wide PHP 8.0/8.2 will produce surprises |
| Vite can't reach the backend (network errors) | Verify `php spark serve` is running on 8090 (`Get-Process php`). The proxy target is `http://localhost:8090` by default — override with `$env:VITE_API_PROXY_TARGET = 'http://127.0.0.1:9000'` before `dev-up.ps1` if you changed ports |
| Forgot the admin password | Seeder ran? `cd backend && php spark db:seed DevUserSeeder` (resets to `DevPassw0rd!`) |

---

## 7. Architecture notes

- **Database**: `synapse_zcode` schema in MariaDB 10.4 (XAMPP). Collation
  `utf8mb4_unicode_ci` (the MySQL 8 default `utf8mb4_0900_ai_ci` is
  rejected by MariaDB).
- **PHP runtime**: `C:\Users\udtoh_lmtzs7k\php83\` (portable, NOT on the
  global PATH; helper scripts prepend it for the session).
- **MariaDB**: starts via `mysqld.exe --standalone` (not as a Windows
  service) so it dies when the shell exits. Re-launched by
  `dev-up.ps1` if you Ctrl+C and want to come back.
- **Vite proxy**: `frontend/vite.config.ts` proxies `/api/*` to whatever
  `VITE_API_PROXY_TARGET` says (default `http://localhost:8090`).
- **Security tokens** (`COUNSELLING_KEY`, `REFERRAL_HMAC_KEY`, `JWT_SECRET`)
  are randomised by `setup.ps1`. Re-running `setup.ps1` does NOT
  regenerate them — to rotate, delete `backend/.env` and re-run, or
  hand-edit the lines.

### Repo layout (the pieces this setup touches)

```
backend/
  .env                      ← created by setup.ps1, gitignored
  .env.example              ← tracked
  app/                      ← CodeIgniter 4 app
  composer.json
  spark                     ← CI4 CLI
frontend/
  .env                      ← already present
  vite.config.ts
  package.json
scripts/
  _start-mariadb.ps1        ← internal helper (reused)
  dev-up.ps1                ← one-command bring-up
  dev-down.ps1              ← one-command tear-down
  setup.ps1                 ← one-command fresh clone bring-up
docs/
  SETUP.md                  ← this file
  CREDENTIALS.md            ← demo accounts
```

---

## 8. Conventions & guardrails for new contributors

These are hard rules — read them before opening a PR:

- **Never** log access tokens, refresh tokens, QR secrets, request bodies,
  or clinical notes. The configured `logger.threshold` and
  `AuditLogger` already strip them, but watch for accidental additions.
- **No wildcard CORS** in production. The dev allowlist in
  `backend/.env` is `http://localhost:5173`. Add staging origins to
  `CORS_ALLOWED_ORIGINS`, never `*`.
- **No SQL JOIN across `clinic_*` and `counselling_*`** — they bridge
  via `referral_referrals`. Reinforced in service-level assertions.
- **Pagination is keyset-only** (`KeysetPaginator`). No `OFFSET n`.
- **Soft delete only** via `archived_at` (no physical `DELETE`).
- **Auth-event audit context keys are `auth_method`, `outcome`,
  `family_id` only.** Never email / password / token.
- **Login lockout HMAC digests** are derived from IP+UA+attempted email;
  the attempted email is never stored, logged, or audited.
- **`counselling_key_versions` stores env-var NAMES only**, never key
  material. The `.env` secrets would be wiped by adding to this table.
- **BMG mass invariant**: `output_weight_kg <= total_input_weight_kg`
  is enforced in three places — the `bmg_mass_invariant` rule, the
  service assertion, and a DB trigger. Don't bypass any of them.
- **Referral verify endpoint**: returns ONLY `{status, artifact_type,
  issuer}`. Never PII.
