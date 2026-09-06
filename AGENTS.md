# Synapse — university health & guidance platform

Three independent tiers in one repo. Always identify which tier a path belongs to
before changing anything; they share no build system.

| Path        | Stack                          | Notes                                   |
| ----------- | ------------------------------ | --------------------------------------- |
| `backend/`  | CodeIgniter 4 + Shield, PHP    | REST API, JWT, MariaDB/MySQL only        |
| `frontend/` | React + TypeScript + Vite      | SPA, Vitest unit + Playwright e2e        |
| `mobile/`   | Flutter (`synapse_mobile`)     | Dart SDK >=3.6.0                        |

Deeper docs: `README.md` (quick start), `PRODUCT.md`, `backend|frontend|mobile/README.md`.

## Commands

Run each from its own tier directory — not the repo root.

```
backend/    composer test           # unit suite (phpunit.xml)
            composer test:feature   # HTTP suite, boots kernel, needs MariaDB
            composer test:all       # both
            composer scan:tenancy   # tenant-scope static scan
frontend/   npm run dev             # Vite, port 5173
            npm run typecheck       # tsc -b --noEmit
            npm run lint            # eslint, --max-warnings 0
            npm test                # Vitest
            npx playwright test     # e2e/, autostarts dev server
mobile/     flutter test
```

Before claiming work is done, run the gates for **every tier you touched**.
CI (`.github/workflows/ci.yml`) runs three parallel jobs and mirrors these exactly.

## Testing constraints

- The two PHPUnit bootstraps are **mutually exclusive** — PHPUnit has no
  per-suite bootstrap. `tests/bootstrap.php` stubs `service()`/`config()`;
  `tests/_bootstrap_feature.php` boots the real kernel. Never merge them.
- Feature tests are **MySQL/MariaDB-only** and refuse to run otherwise.
  Credentials come from `SYNAPSE_TEST_DB_{HOST,USER,PASS,NAME,PORT}` —
  dotted env names get dropped by GitHub runners.
- Feature test files live in `tests/Feature/` — capital F, casing matters.
- Playwright: 13 specs, single Chromium project, serial (`workers: 1`) because
  the live stack is stateful. 6 mocked specs run in CI; the other 7 are gated
  behind `SYNAPSE_E2E=1` and need a live PHP backend + dev account.
  Override the target with `SYNAPSE_E2E_BASE_URL` to skip dev-server autostart.

## Conventions

- **Timezone:** the clinic operates in Manila. Day-boundary logic must use the
  Manila-day helper, never a raw UTC date — queue/check-in partitioning has
  regressed on this before. Assume any new date bucketing is suspect.
- **Filter state belongs in the URL.** Use the shared URL-filter hook rather
  than local `useState` so views stay linkable and reloadable.
- Every tenant-scoped query needs its scope; keep `composer scan:tenancy` clean.
- Async work runs through spark commands, not request threads:
  `synapse:notify-drain`, `synapse:audit-drain`, `synapse:reports-drain`,
  `synapse:appointments-enqueue-due`, `synapse:reorder-auto-check`,
  `synapse:smoke`, `synapse:audit-verify`, `synapse:audit-clear`,
  `synapse:audit-orphans`.

## Rules

- **Never scaffold from a template skeleton.** When asked to match an existing
  document's or module's structure, match its depth and richness — do not emit a
  section-for-section empty clone.
- The `employees` endpoint silently caps `limit` near 200; do not request 500.
- Demo/seed accounts are dev-only and gated out of production. `CREDENTIALS.md`
  is **stale** (wrong emails, passwords, and table names) — trust the seeders in
  `backend/app/Database/Seeds/` instead.
- Do not commit or push unless explicitly asked. Working branch: `feat/bmg`.
- Don't add dependencies to any tier without asking first.
