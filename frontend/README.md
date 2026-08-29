# SYNAPSE Frontend

The React 18 admin portal — every SYNAPSE persona (administrators, clinic staff, counsellors, facilities operators, students) plus the chromeless web kiosk check-in station and the public lobby queue display, all in one Vite + TypeScript (strict) SPA.

## How it's built

**Type safety is the backbone.** `strict` + `noUncheckedIndexedAccess` + `exactOptionalPropertyTypes`, ESLint with `no-explicit-any: error` — and the result is zero `any` and zero `@ts-ignore` in `src/`. Every API response is parsed at runtime by one of 19 Zod modules (`src/schemas/`) — that runtime parse *is* the API contract, with types derived via `z.infer`.

**State is split three ways.** Server state lives in TanStack Query 5 (staleTime 30 s, no retries on 4xx, per-domain invalidation helpers, polling tuned per surface — queue at 5–10 s down to dashboard at 60 s). Auth and UI preferences live in a small Zustand store. Tabs and entity selection live in the URL, so investigations are deep-linkable and survive refresh.

**One axios client guards the door.** `src/api/client.ts` unwraps the API envelope, normalizes failures into `ApiEnvelopeError`, propagates `X-Request-Id`, and performs a single-flight silent 401 refresh with one replay (`_synapseRetried` guard). The access token never leaves memory; the refresh token never leaves its HttpOnly cookie.

**Routing is RBAC-first.** Every route is wrapped in `<ProtectedRoute anyOf={['perm.code']}>`, pages are lazy-loaded with retry-on-chunk-failure, and permission-aware dispatchers route students vs staff from the same entry points. Route-level `errorElement` gives a branded error boundary.

```
src/
├── api/                client.ts (axios), envelope.ts, errorCodes.ts (+ unit tests)
├── components/         shared UI; ui/ (shadcn-style), inventory/, reports/ dialog folders
├── hooks/              35 domain hooks (react-query per module)
├── pages/              23 route pages
├── providers/          QueryProvider — retry & invalidation policy
├── router.tsx          createBrowserRouter, RBAC-guarded lazy routes
├── schemas/            19 Zod modules — the runtime API contract
├── store/              Zustand (auth in memory only + UI prefs)
└── styles/             Tailwind 4 tokens — light/dark, maroon #800000 accent
e2e/                    13 Playwright specs (mocked + live tiers)
verify-schema.cjs       manual live-API contract check (supplement to src/schemas)
```

Stack: React 18.3 · Vite 5 · TypeScript 5.6 · TanStack Query 5 · Zustand 4 · React Hook Form 7 + Zod resolvers · Tailwind CSS 4 with ~10 Radix primitives · sonner · recharts · qrcode.react + html5-qrcode · jspdf.

## Setup

```bash
cd frontend
cp .env.example .env
npm install
npm run dev              # http://localhost:5173 — Vite proxies /api -> :8090
```

Prerequisite: the backend running on `http://localhost:8090` ([`../backend/README.md`](../backend/README.md)). Login with `admin@synapse.dev` — full account matrix in [`../CREDENTIALS.md`](../CREDENTIALS.md).

| Var | Purpose |
|---|---|
| `VITE_API_BASE_URL` | Backend base path (default `/api/v1`); the dev server proxies it to the upstream |
| `VITE_KIOSK_UPLOAD_BASE_URL` | Direct backend base for large kiosk-media uploads (bypasses Vite's file relay) |
| `VITE_APP_TZ` | Display timezone (default `Asia/Manila`) |

## Notable behaviors

- **Optimistic BMG transitions** — `useStartBatch`, `useRecordOutput`, `useFinishBatch`, `useCancelBatch` use `onMutate` / `onError` / `onSettled`: the unit row's `status` (and `active_batch_id`) flips in the cache immediately, rolls back on failure, and reconciles on settle. Mutations carry `unitId` / `batchId` as variables, so one hook instance serves every row without breaking the rules of hooks.
- **Active batch resolution** — `BmgService::listUnits` LEFT-JOINs `facilities_bmg_batches` so each unit carries `active_batch_id`; the Facilities page drives Output / Finish / Cancel from it, no hardcoded batch ids.
- **Forms** — RHF + `zodResolver` in every dialog; submit buttons disable while `mutation.isPending`.
- **Toasts** — all driven through the error-code surface (`errorCodes.ts`: `variantForCode()` + `humanizeCode()`); hooks own toasts, not pages.
- **Confirm dialogs** — one shared `ConfirmDialog` + `ConfirmAction` descriptor used by all pages, with animation-safe content freeze.
- **QR** — issuance via `qrcode.react`, scanning via `html5-qrcode`, both backed by the public minimum-disclosure `POST /referrals/verify`.
- **Accessibility** — aria coverage across pages, keyboard row navigation, 44 px touch targets, `role="status"` / `role="alert"` loading and error surfaces. Target: WCAG 2.1 AA ([`../PRODUCT.md`](../PRODUCT.md)).

## Auth model

- Access token in-memory (Zustand) — **never** `localStorage`.
- Refresh token in `HttpOnly; Secure; SameSite=Lax` cookie set by `/auth/login`.
- On 401 the client attempts one silent refresh + replay; on `auth.refresh_invalid_or_replayed` it wipes the store and routes to `/login`.

## Commands

```bash
npm run dev          # dev server (:5173)
npm run build        # tsc -b --noEmit && vite build — typecheck gates the build
npm run preview      # serve the built bundle (:4173)
npm run typecheck    # tsc -b --noEmit
npm run lint         # eslint --max-warnings 0
npm test             # vitest run
```

## Tests

| Tier | Command | Notes |
|---|---|---|
| Unit (Vitest) | `npm test` | envelope + error-code catalogs, date utils |
| Gates | `npm run typecheck && npm run lint` | lint fails on any warning |
| Mocked e2e | `npx playwright test` (the 6 specs that stub the API) | dev server auto-started; runs in CI |
| Live e2e | `SYNAPSE_E2E=1 SYNAPSE_E2E_EMAIL=… SYNAPSE_E2E_PASSWORD=… npx playwright test` | backend on :8090; credentials from env only |

The live tier covers login → dashboard → clinic → audit, BMG batch transitions, admin users, and forced password reset. The mocked tier covers portal role views, queue display + media, destination queues, login errors, reports RBAC, and clinic/guidance session workflows. Playwright runs single-worker, Chromium-only — the live stack is stateful.

## Troubleshooting

- **Raw error codes in toasts** — an unmapped code in `src/api/errorCodes.ts` renders the generic message; add a mapping.
- **401 loops** — confirm the backend origin and `CORS_ALLOWED_ORIGINS` match the Vite dev origin; the client retries a 401 exactly once before logout.
- **Stale data after backend changes** — server state caches 30 s and polls at per-surface intervals (queue 5–10 s … dashboard 60 s); hard-refresh to force refetch.
