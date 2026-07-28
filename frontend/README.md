# SYNAPSE Frontend (Phase 5)

React 18 + Vite + TypeScript (strict). Tailwind 4, Radix primitives, Shadcn-style components, TanStack Query, Zustand, Zod, Sonner. RHF + Zod for forms.

## Phase 5 Additions (Directive Compliance & Operational Polish)

- **Optimistic BMG transitions** — `useStartBatch`, `useRecordOutput`, `useFinishBatch`, `useCancelBatch` each use TanStack Query's `onMutate` / `onError` / `onSettled` lifecycle. The unit row's `status` (and `active_batch_id`) flips in the cache immediately, rolls back on failure, and reconciles with the server after the request settles.
- **Active batch resolution** — `BmgService::listUnits` now LEFT-JOINs `facilities_bmg_batches` to return `active_batch_id` on each unit. `FacilitiesPage` uses this for the Output / Finish / Cancel buttons so they no longer need a hardcoded batch id.
- **Optimistic mutations carry `unitId` / `batchId` as variables** — the hooks no longer take those values at construction time, so the same `useStartBatch()` instance can be reused across rows without breaking the rules of hooks.

## Phase 3/4 baseline (unchanged)

- **RHF + Zod** is wired in Clinic / Counselling / Referrals dialogs via `useForm({ resolver: zodResolver(schema) })`. Submit buttons are disabled while `mutation.isPending`.
- Clinic / Counselling / Referrals / Audit / Facilities pages with keyset pagination and Radix UI.
- QR issuance (`qrcode.react`) + scanner (`html5-qrcode`) backed by the public minimum-disclosure `POST /referrals/verify`.
- Error-code surface (`src/api/errorCodes.ts`) — `variantForCode()` + `humanizeCode()` drive all toasts.
- Audit export UI (`useAuditExport` → blob download).
- Dashboard counters (`useDashboardCounters`).
- ESLint flat config; Playwright opt-in via `SYNAPSE_E2E=1`.

## Bootstrap

```bash
cd frontend
cp .env.example .env
npm install
npm run dev
# optional
SYNAPSE_E2E=1 npx playwright test
```

## Environment

| Var | Purpose |
|---|---|
| `VITE_API_BASE_URL` | Backend base path (default `/api/v1`). Vite dev-server proxies this to the configured upstream. |

## Auth model (recap)

- Access token in-memory (Zustand) — **never** `localStorage`.
- Refresh token in `HttpOnly; Secure; SameSite=Lax` cookie set by `/auth/login`.
- On 401 (`auth.refresh_invalid_or_replayed`) the SPA wipes the store and routes to `/login`.
