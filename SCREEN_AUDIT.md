# Synapse — Screen-by-Screen Audit

**Date:** 2026-09-05 · **Branch:** `feat/bmg` (large uncommitted counselling/inventory fix pass in the working tree — **all findings reflect the working tree as read from disk**, not git HEAD)
**Scope:** every screen of the web SPA (23 pages), every screen of the Flutter mobile app (19 screens), and the backend endpoints/permissions/queries those screens drive.
**Method:** 7 parallel read-only deep-dive passes (one per module cluster) + direct verification of the highest-severity claims, the tenancy scanner (`composer scan:tenancy` → **30 unscoped sites**), the notification-template surface, the CSV export path, and a fresh verification of every finding in `COUNSELLING_AUDIT.md` (2026-09-03) against the in-flight fix pass. No files were modified.

---

## How to read this document

| Severity | Meaning |
|---|---|
| **P0** | Security or data-integrity defect with present-day impact (not merely latent) |
| **P1** | Broken or missing core workflow, misleading UI on a sensitive surface, or a latent cross-tenant/correctness defect that violates a documented convention |
| **P2** | Convention violation (URL state, Manila-day, tenancy), UX gap, or maintainability risk |
| **P3** | Polish, dead code, stale comments, minor a11y/consistency nits |

Every finding cites `file:line` evidence from the working tree. "Upgrades" are ranked, concrete improvement ideas — they are not defects.

Two housekeeping notes:

- The repo already carries `ANALYSIS.md` (repo-wide architecture, 2026-09-03) and `COUNSELLING_AUDIT.md` (counselling module, 2026-09-03). This document does not re-derive those; it verifies their findings against current state (see Appendices A/B) and covers what they did not: **per-screen** UX, permission parity, pagination, and workflow completeness.
- The `composer scan:tenancy` baseline moved from **1 → 30 unscoped sites** since `ANALYSIS.md` because the scanner was extended to catch `$this->db->query()` calls (the 2026-09-03 counselling audit's own recommendation). The counselling module's sites are gone from the scan (fixed in the working tree); the remaining 30 are concentrated in `Modules/Clinic` and are catalogued in §13.

---

## 1. Executive summary

The platform remains structurally strong — envelope/keyset discipline, hash-chained audit, outbox patterns, transactional writes with row locks, and honest documentation all verified again. But this pass surfaced a coherent set of **new, verified defects**, most of them wiring- and convention-level rather than architectural:

**P0 (2)**

1. **"Mark no-show" always 404s** — the SPA calls `POST /clinic/encounters/{id}/no-show`, the controller and full cascade service exist, but no route is registered (`backend/app/Modules/Clinic/Routes.php`). A shipped UI button is dead. (§5)
2. **Cross-tenant aggregates in BMG waste-category stats** — `BmgSupport::categoryDurationStats`/`categorySamples` run raw SQL with no `tenant_id`, and their results are rendered per-row on WasteCategoriesPage and drum analytics. (§10)

**P1 clusters (the pattern matters more than any single item)**

- **Tenancy:** 30 scanner-flagged raw-query sites (queue position allocation, call-next FIFO, guest-duplicate windows, wait-time averages, FEFO) plus unscoped audit-trail reads/facets/export and the dashboard `audit_events` counter. Latent single-tenant, but the "keep scan:tenancy clean" rule is currently violated at scale. (§13.1)
- **Manila-day violations, live:** `CheckinService::scan` compares Manila wall-clock strings against a UTC `scheduled_at` column — an evening scan can check in *tomorrow morning's* appointment; `bulkImportEncounters` buckets the queue on the UTC date; appointments Upcoming/Past tabs parse UTC as local (8-hour skew); BMG progress/ETA and inventory expiry chips use UTC day math. (§5, §6, §8, §10)
- **Permission parity in the UI:** Patients, Appointments, Facilities, Referrals, and Inventory render write actions (register, schedule, archive, acknowledge, issue-QR, close) to roles the backend will 403 — counsellors, faculty employees, and clinic_staff each get guaranteed-failure buttons on sensitive surfaces. (§4, §5, §8, §10, §11)
- **Session/auth:** the Command Palette "Sign out" clears memory without revoking the refresh family or confirming; cross-tab refresh rotation can trigger replay detection and log out every open tab. (§2)
- **Core-workflow wiring:** staff appointments pagination is dead (`res.data?.next` misread), the lobby TV media playlist restarts every 15 s, no no-show *aging* exists so `booked` appointments accumulate forever, and the kiosk station's ID field strips employee-number formats. (§5, §6)

**What got fixed since the last audits (verified):** the entire 2026-09-05 counselling fix pass landed — action-aware note policy with `read_any` oversight + audited reassignment, reveal-gated decryption, tenant-scoped raw SQL, analytics tenant key, three-strike enforcement with supervisor override, archive/unarchive + amendments + retention-purge command, URL-backed scheduling filters, honest encryption copy. Verified per-finding in **Appendix A**.

---

## 2. Shell, auth & shared UX

### LoginPage — `frontend/src/pages/LoginPage.tsx` (204 lines)

Purpose · unauthenticated entry. Guard · public route. Endpoints · `POST /auth/login` → `GET /auth/me`.

- [P3] Throttle feedback says "Wait a few minutes" but never surfaces the backend's `Retry-After` (`errorCodes.ts:82-83`; `AuthController.php:61`), and `retryAfterSeconds()` reports the full 15-minute window rather than the remaining lock (`LoginThrottleService.php:69-72`).
- [P3] `/login` has no `errorElement`, so a lazy-chunk failure on the one truly public screen lands on React Router's raw default error page (`router.tsx:172-175`); `/login` also never redirects an already-authenticated user back into the app (`router.tsx:173`).
- [P3] Background-image right-click shield is acknowledged security theater (`LoginPage.tsx:59-99`) — consider deleting.
- Positive: error focus + `aria-live`, `aria-invalid`/`aria-describedby` field wiring, double-submit block, zod matches backend min-8.

Upgrades: surface `Retry-After`; add branded `errorElement` + authed-redirect; delete the shield.

### ChangePasswordPage — `frontend/src/pages/ChangePasswordPage.tsx` (83 lines)

- [P3] Error `<p role="alert">`s lack `id`s and inputs lack `aria-describedby` (unlike LoginPage); no focus move on failure (`ChangePasswordPage.tsx:56-73`).
- Contract verified correct: min-12 matches backend; `force_reset` cleared server-side exactly where `AccountStateService` reads it; client pre-clears the `['me']` cache so the gate lifts without a Layout bounce (`useAuth.ts:90-100`).

### Auth store, bootstrap & API client — `frontend/src/store/auth.ts` (59), `hooks/useBootstrapSession.ts` (71), `api/client.ts` (133)

- [P1] **Cross-tab refresh rotation logs out every tab.** `client.ts:32-57` single-flights per tab only. Two tabs polling 10–60 s both 401 near the 900 s expiry and each POST `/auth/refresh` with the same cookie; the second is classified REPLAY and revokes the whole family (`RefreshTokenService.php:119-131`). Mitigation: Web Locks/BroadcastChannel around rotation, or a server-side grace window that treats a just-replaced token as a re-issue.
- [P2] `useBootstrapSession` posts `/auth/refresh` directly (`useBootstrapSession.ts:39-42`) instead of the client's single-flight helper — two independent refresh entry points; today mount ordering prevents overlap, by luck rather than contract.
- [P2] `auth.password_change_required` is issued by `ApiAuthFilter.php:51-57` but missing from `errorCodes.ts:8-44` despite the file's keep-in-sync contract — the toast/humanizer falls back to raw code.
- [P3] Dead store fields: `sidebarCollapsed`/`timezone`/setters have zero consumers while the header comment claims they're live (`store/auth.ts:19-27`). Bootstrap `retry: false` bounces a valid-cookie user to `/login` on one network blip (`useBootstrapSession.ts:20`). `X-Request-Id` uses `crypto.randomUUID()`, undefined on plain-HTTP LAN origins (`client.ts:74`).

### CommandPalette — `frontend/src/components/CommandPalette.tsx` (473 lines)

- [P1] **"Sign out" is not a logout.** It wires `useAuthStore((s) => s.clear)` (`CommandPalette.tsx:242`, verified) — no `POST /auth/logout`, no refresh-family revocation, no ConfirmDialog; on shared clinic machines the next visit silently re-authenticates. Unify both sign-out paths on `useLogout()` + ConfirmDialog.
- [P2] Student search fires per keystroke for users without `clinic.patients.read` (403 noise): the query's `enabled` checks only length (`usePatients.ts:61-64`), the palette gates only rendering (`CommandPalette.tsx:277-284`).
- [P2] "My portal" command requires `employee.portal.read` only, while the router/sidebar accept `student.portal.read` as anyOf (`CommandPalette.tsx:155-158` vs `router.tsx:213`).
- [P3] Listbox lacks `role="combobox"`/`aria-activedescendant`; raw `perms.includes('*')` checks instead of `hasPermission`.

### Layout, AppSidebar & navigation — `Layout.tsx` (141), `AppSidebar.tsx` (281), `HeaderBreadcrumbs.tsx` (101), `router.tsx` (355)

- [P2] Router/sidebar gap on `/`: the router renders Dashboard for anyone who isn't a pure student (`router.tsx:162-170`), but the sidebar hides it without `employee.portal.read` (`AppSidebar.tsx:83`) — a `notifications.read`-only user gets a dashboard whose staff widgets all 403.
- [P2] Sidebar counters poll `/dashboard/counters` every 60 s for users who can never see a badge (`AppSidebar.tsx:177`; `useDashboard.ts:35`) — gate the query with `enabled:` on permissions.
- [P2] `useTheme` is per-instance `useState`, so Layout's `<Toaster theme>` goes stale when toggling from UserMenu (`useTheme.ts:13-21`; `Layout.tsx:134`); no `prefers-color-scheme` fallback in the boot script (`index.html:20-25`).
- [P3] Dead `showInactivePatientBanner = false` with full markup retained (`Layout.tsx:44-68`); duplicate `lucide-react` import; breadcrumb last crumb lacks `aria-current="page"`; `resolvePageMeta` can't match parameterized routes so DrumDetail gets no topbar title (`pageMeta.ts:51-63`); prefetch registry maps `/me` to the wrong chunk and omits `/notifications` (`routeChunks.ts:43,77-79`); protected-route `state.from` is dead — after session expiry users always land on `/` instead of their page (`ProtectedRoute.tsx:28`, `useAuth.ts:38`).
- Verified clean: sidebar↔router↔backend permission codes match pairwise for every module route; longest-prefix active logic correct; prefetch-on-intent correct.

**Cluster totals:** 2 × P1 · 8 × P2 · 13 × P3.

---

## 3. Dashboard

### DashboardPage — `frontend/src/pages/DashboardPage.tsx` (253 lines)

Purpose · staff launchpad; clinic-role users get the analytics view instead of the modules grid. Guard · `/` (router) vs sidebar `employee.portal.read` — gap noted in §2. Endpoints · `/dashboard/counters`, `/reports/clinic`.

- [P2] **Backend still computes a payload nobody renders.** `DashboardController.php:77-89` runs two `countAllResults` on `users` per call for `identity_coverage`, and the frontend type still declares it (`useDashboard.ts:19-24`), but the page removed the widget on 2026-08-05 (`DashboardPage.tsx:204-207`). Dead work every 60 s per `rbac.read` holder — delete both sides.
- [P2] The `audit_events` 24-hour counter is not tenant-scoped (`DashboardController.php:66-72`, verified) — see §13.1.
- [P3] Clinic-role users lose **all** quick links (the modules grid is hidden entirely) — the sidebar becomes the only navigation.
- [P3] `hasPermission({ permissions: perms } as never, …)` casts (`DashboardPage.tsx:176-177,193`) — use the store hook directly.
- [P3] `ClinicRoleDashboard` freezes its 5-month range at mount (`useMemo []`, `:145-151`) — stale on long-lived tabs; the header hardcodes "Asia/Manila".
- [P3] Patients/Inventory/Appointments/Users cards show static copy while Clinic/Counselling/Facilities/Referrals show live counters — cosmetic inconsistency.
- Positive: counters error gets `role="alert"` with "functionality unaffected" copy; module cards are real links with focus rings; tenant scoping on every *other* counter verified.

**Totals:** 0 × P1 · 2 × P2 · 5 × P3.

---

## 4. Patients & portals

### PatientsPage — `frontend/src/pages/PatientsPage.tsx` (1,577 lines)

Purpose · student/employee registry: register (mints portal account + one-time temp password), edit, allergies/emergency contacts, departments. Guard · route/sidebar `clinic.patients.read`; backend splits `clinic.patients.write` / `clinic.departments.manage` (`ClinicPolicy.php:43-48`).

- [P1] **Write UI shown to read-only roles.** Register/Edit/Archive/Departments all render for anyone holding `clinic.patients.read` (`PatientsPage.tsx:1236,1391,1523-1524,1007-1043`); counsellors hold read without write (`AuthGroups.php:111`) and get 403 on every action they can see.
- [P1] **Allergy/contact update+delete lookups are not tenant-scoped.** `PatientService.php:377-380,409-412,441-444,482-485` select by `(id, user_id)` only; the mutation survives on a cross-tenant pair and is only undone because the trailing `getStudent()` 404 rolls the transaction back — accidental, fragile protection. Both tables carry `tenant_id` (`2026-08-02-100700` migration:40-41) and the scanner cannot see builder queries.
- [P2] Allergy and emergency-contact **deletes have no ConfirmDialog** (`PatientsPage.tsx:534-543,599-608`) while archive does — PII rows, unrecoverable.
- [P2] Search cannot find archived rows even with "Show archived" on — backend hardcodes `archived_at IS NULL` in search (`PatientService.php:88,716`).
- [P2] Pagination cursor/history local `useState` (`:1046-1049`) and hand-rolled twice instead of the existing `useKeysetPagination` hook; date fields are free-text with `max(10)`-only zod (`schemas/patients.ts:108,136-139`) so garbage fails only as a backend toast.
- [P2] Post-create zod parse of `temporary_password` (`patients.ts:14`, min 12) runs *after* the POST — a short password throws inside `mutationFn` and toasts "Failed to register" although the student row + account were created (`usePatients.ts:91-112`).
- [P3] Twin-list duplication: both `useStudents` and `useEmployees` always fetch regardless of active tab (`:1072-1075`); desktop+mobile IIFEs compute the same rows twice (`:1412-1514`); stale comment about empty-string stripping (`:319-321`); dead `studentsListQuerySchema` (`patients.ts:178-184`); backend `searchEmployees` uses byte `strlen` vs `mb_strlen` for students (`PatientController.php:266` vs `:50`); `createEmployee` omits an `is_teaching` rule while persisting it (`PatientService.php:584`); list rows return full PII (address, DOB, contacts) with no field minimization.
- Positive: keyset pagination correct end-to-end (`KeysetPaginator` verified); student/employee filters and tab are URL-backed (`useUrlFilter`/`useTabParam`); blood type/length enums match backend rule-by-rule.

Upgrades (ranked): tenant-scope the four allergy/contact lookups; gate write buttons on `clinic.patients.write`; ConfirmDialog on deletes; adopt `useKeysetPagination` + split the god file along the twin lists.

### StudentPortalPage — `frontend/src/pages/StudentPortalPage.tsx` (352 lines)

- [P1] `/me/student-providers` **leaks cross-tenant staff** — `AppointmentService::providers()` filters by auth group with no `tenant_id` on `users` (`AppointmentService.php:575-586`, verified) while the controller checks only `student.portal.read` (`StudentSelfController.php:66-70`).
- [P1] **Booking QR proof is minted then discarded on the portal path** — `PortalAppointmentService::book` wraps the result in `clinicRow()` which drops `qr_token` (`PortalAppointmentService.php:86-89` vs `AppointmentService.php:437`), and the portal schema has no field — students can never render their proof-of-booking QR on the web.
- [P2] Clinic-visits section has no error branch — a failed fetch silently renders nothing (`StudentPortalPage.tsx:249-290`).
- [P2] `POST /me/student-appointments` → `bookSelf` never checks the provider is clinic staff or has availability — any user id at any future non-clashing time is bookable (`AppointmentService.php:365-439`); the route is live (`Routes.php:63`) while the UI that constrained it is dead (see StudentBooking below).
- [P3] Stale "read-only, full self-service deferred" docblocks above a live booking flow (`StudentPortalPage.tsx:4-7`; `StudentSelfController.php:23-24`); the portal's generic "No-shows" badge is counselling-only data (clinic no-shows never increment `consecutive_no_shows`).
- Self-scoping verified structural (profile/visits resolve by `CurrentUser::id()` + tenant + kind), not just permission-coded — good.

### EmployeePortalPage — `frontend/src/pages/EmployeePortalPage.tsx` (376 lines)

- [P2] Backend offers `POST /me/employee-profile` self-edit (`EmployeeSelfController.php:86-117`) but the UI is read-only and says "see the HR team" (`EmployeePortalPage.tsx:366`) — an unadvertised write surface. Wire a form or delete the endpoint.
- [P2] No tabs / not URL-stateful (unlike the student portal); clinic-visits error unhandled (same as student page).
- [P3] `kioskPayload()` has two identical branches and describes a `me.` prefix scheme that doesn't exist (`EmployeeSelfController.php:125-136`); contradictory docblocks about the removed `patients_employees` link; duplicate change-password affordances.

### PortalAppointments (shared booking) — `frontend/src/components/PortalAppointments.tsx` (12 lines)

- [P1] **Clinic self-booking race** — `bookSelf` guards with non-locking `countAllResults` (`AppointmentService.php:602-616`) before INSERT; two concurrent bookings can both pass. The guidance path does it properly with `FOR UPDATE` counting (`PortalAppointmentService.php:191-194`).
- [P1] **Guidance booking raw SQL is unscoped** — `PortalAppointmentService.php:191,193` select the availability window and count appointments with no `tenant_id` (confirmed in scanner output).
- [P2] Cancel uses `window.confirm` (not ConfirmDialog) and any failure toasts "Cancellation closes one hour before the appointment" regardless of the real error (`PortalAppointments.tsx:10`); booking/cancelling doesn't invalidate `['me','appointment-slots']` so the just-booked slot stays selectable and re-booking 409s (`usePortalAppointments.ts:10-11`).
- [P2] `GET /me/appointments` is unbounded (no LIMIT, `PortalAppointmentService.php:31-53`), `GET /me/queues` performs writes on a GET (`autoCheckInTodaysPending` + `enqueueDueAppointments`, `:122-131`) — duplicating the spark-command convention; `clinicSlots()` is N+1 (per day × per slot a clash query, `:142-158`).
- [P3] DatePicker allows past dates (no matcher) — the server blocks them but the user discovers it only via "No available slots"; the whole component/backend pair is one-statement golfed code, inconsistent with the rest of the repo; Manila conversions reimplemented inline instead of `ManilaDay`.

### StudentBooking — `frontend/src/components/StudentBooking.tsx` (209 lines) — DEAD

- [P2] Entire component + 3 exclusive hooks + schema are unreachable (no importer repo-wide), while the live backend path it once guarded is the *less-safe* one. Worst of both: dead UI, live weaker endpoint. Resurrect behind the slot endpoint or delete the branch.

### PatientPicker / PatientIdCell — 116 / 29 lines

- [P2] Picker listbox has no arrow-key navigation or `aria-activedescendant` (`PatientPicker.tsx:83-110`); free text flows into `patient_school_id` so the form can hold a non-existent id until a backend 404 at submit.
- PatientIdCell clean.

**Cluster totals:** 8 × P1 · 17 × P2 · 16 × P3.

---

## 5. Appointments (staff)

### AppointmentsPage — `frontend/src/pages/AppointmentsPage.tsx` (919 lines)

Purpose · front-desk grid: All/Upcoming/Past tabs, status filter, search, schedule/edit dialogs, transitions, QR entry. Guard · route/sidebar `clinic.appointments.read`; writes need `clinic.appointments.write` (`ClinicPolicy.php:41-42`).

- [P1] **Keyset pagination is dead.** `useAppointments.ts:44-48` reads `res.data?.next`, but the axios interceptor replaces `response.data` with the bare rows array and stashes meta on `synapseMeta` (`client.ts:91-94`); the canonical reader is `getNextCursor(res)` (`client.ts:28`) — used correctly by `useAdminUsers`/`useAudit`/`useSchedule`, missed here. Next button is permanently disabled; staff can never see past row 25.
- [P1] **Upcoming/Past buckets parse UTC as local.** `Date.parse(a.scheduled_at)` (`AppointmentsPage.tsx:685,695,697`) on zone-less UTC strings (`AppointmentDto.php:60`); `utils/date.ts:13-20` documents exactly this trap and provides `parseUtc`, bypassed here. In Manila every appointment in the next ~8 hours lands in "Past"; Safari returns `NaN` (all rows bucket to Past).
- [P2] Schedule/Edit/Issue-QR render for read-only holders (`:750-752,466-470,899`) — same UI-permission pattern as Patients; backend 403s after the click.
- [P3] Tab counts are page-local but labeled globally (`:681-691`); cursor/history not URL-backed (filters correctly are: `?tab=`, `?status=`, debounced `?q=`); garbage `?tab=` blanks the page (no fallback to `all`); background list keeps polling at 30 s while search results are displayed; `now = Date.now()` in memo deps defeats memoization.
- Positive: double-submit guards real (`pending` composition), cancel through ConfirmDialog, loading/error/empty on both table and mobile cards, mobile list `md:hidden` (no double render), mutation invalidations cover search/detail.

### AppointmentQrDialog — `frontend/src/components/AppointmentQrDialog.tsx` (235 lines)

- [P2] Plaintext HMAC tokens persisted to **localStorage** on shared front-desk machines, never cleared on close/logout (`:61-72,110-114`). Bounded impact (verify reveals only status+time, `AppointmentService.php:512-516`) but a durable bearer secret per appointment.
- [P2] Verify banner says "Valid appointment" for **cancelled/completed** bookings — backend `verify()` filters only `archived_at` (`:501-516`); make the verdict status-aware.
- [P3] No token expiry (no column; tokens verify forever, `2026-08-11` migration); Re-issue silently kills the patient's copy with no confirm; `includeMargin` deprecated in qrcode.react v4; dialog shows the stale status captured at open.

### AppointmentService / AppointmentController — 964 / 162 lines (backend)

- [P1] **No no-show aging.** The lazy sweep touches only today-through-now+15min (`AppointmentService.php:894-906`); past-due `scheduled` rows can never become `no_show` (only manual cancel) — the Past tab accumulates ghost "Scheduled" appointments forever. Add an aging clause to `synapse:appointments-enqueue-due`.
- [P1] **`POST /clinic/encounters/{id}/no-show` route missing** (verified: no registration in `Clinic/Routes.php`; controller `:126-130` and full cascade service exist). See §7.
- [P2] Staff path lacks the self-service guards: no past-date, no ≤90-day horizon, no double-booking — `assertNoClash` exists (`:602-616`) but `schedule()`/`update()` never call it (`:299-352,784-870`).
- [P2] `provider_user_id` accepted with no existence/tenant/role validation (`AppointmentController.php:56,99`) and notifications are enqueued to that id (`:340-347,852-864`) — an appointment can be attached to a student or archived user, who then gets notified.
- [P2] Raw queue-position query unscoped (`:722-725`, scanner-confirmed); inline Manila midnight duplicating `ManilaDay` (`:897-898`, scanner-era convention violation); manual check-in files the queue entry under the *appointment's* day, not today (`:720-721`) — checking in an old appointment creates a queue row invisible to today's queue; `PatientLookupService` `users` query unscoped (`PatientLookupService.php:60-75`).
- [P3] Auto-sweep is notification-silent and mis-attributes audit actor to the provider (`:927,940-950`); dead `'confirmed'` status in the clash query (`:607`); LIKE wildcards unescaped in search (`:88-127`); public `verify` resolves tenant to the default (fine single-tenant); out-of-range `limit` throws uncaught → 500 instead of 422 (`KeysetPaginator.php:83-85`).
- Verified exemplary: row-locked transitions with tenant+archived predicates, encounter UNIQUE double-check-in guard, audit+notification outbox in the same transaction, HMAC-hash-only QR storage.

**Cluster totals:** 4 × P1 · 9 × P2 · ~10 × P3.

---

## 6. Clinic encounters

### ClinicPage — `frontend/src/pages/ClinicPage.tsx` (1,678 lines)

Purpose · queue-first clinical workspace: Queue (today) → Closed → Staff schedules; encounters open from queue rows into a URL-deep-linked workspace. Guard · `clinic.encounters.read` (route = sidebar = backend).

- [P0] **"Mark no-show" always fails — no backend route.** UI and hook call `POST /clinic/encounters/{id}/no-show` (`useClinic.ts:159`; wired at `ClinicPage.tsx:695,836,926`); `ClinicController::markNoShow` (`:126-130`) and the full cascade service (`ClinicService.php:411-519`) exist; `Clinic/Routes.php:55-67` registers no such line; no feature test covers it. One route line + a test un-breaks a shipped clinical workflow.
- [P1] **Encounters can be written after they are closed.** `recordVitals` (`ClinicService.php:203-212`) and `setAssessment` (`:628-652`) have no `status === 'open'` guard (only `addTreatment` does, `:713-717`); `decideTriage` also writes to a closed encounter (`:969-973`). This contradicts the DTO contract (`EncounterDto.php:13-16`) and the UI's own claim that terminal states can't be modified (`ClinicPage.tsx:485-487`).
- [P1] **Bulk import buckets the queue on the UTC day** — `$queueDate = substr($now, 0, 10)` on a UTC now (`ClinicService.php:144-145,179`) while every other writer uses ManilaDay. From Manila midnight to 08:00 imports land on yesterday's queue and never surface in "Queue (today)".
- [P1] **`decideTriage` bypasses the attending-user record policy** — `policy->check('triageUse')` with no `$record` (`:865,940`) and `BasePolicy::enforce` only record-checks when `$record !== null` (`BasePolicy.php:66`), so any `clinic.triage.use` holder can overwrite any encounter's triage priority, a path that bypasses the `setAssessment` record gate on the same field.
- [P1] **Cross-tenant raw SQL across the queue lifecycle** — position locks, call-next scan, wait averages, no-show/completion lookups (22 of the scanner's 30 sites are in this cluster; see §13.1). `callNext`'s unscoped scan can select another tenant's `called` row and 409 this tenant.
- [P2] Vitals can be recorded as an **all-NULL row** — every field optional with no "at least one" refinement (`schemas/clinic.ts:143-152`; backend `permit_empty`), feeding NULL data to triage and counters.
- [P2] Medicine picker is the first 100 medicines, client-filtered — `ComboboxField.fetchOptions` remote search exists (`ComboboxField.tsx:118-129`) but is unused; >100 catalogue entries make medicines unselectable; quantity has no client-side ceiling against stock (409 only after submit).
- [P2] `referred` encounters are unreachable in the UI — the Closed tab fetches `status='closed'` only (`:1369`), the Open tab is gone, and the schema still lists `referred` (`schemas/clinic.ts:10`).
- [P2] Cursor/history/showArchived not URL-backed (`:1347-1363`); deep-link `?tab=closed&encounter=N` highlights a row but opens nothing (workspace rendered only in the queue tab, `:1450-1461`); vitals errors are one generic bottom-of-form alert (no per-field, no `aria-invalid`, no min/max attributes, `:214-254`); god file with doubled desktop+mobile JSX (three tab components render both trees).
- [P2] `useCreateEncounter`/`useImportEncounters` are dead hooks, while the surviving `POST /clinic/encounters` creates encounters **without a queue entry** — invisible on the queue-first UI (`useClinic.ts:73-89,274-299`; `ClinicService.php:85-118`). Either wire a "New walk-in" entry point or delete the route.
- [P3] Duplicate vitals toast (hook + dialog both toast); queue/staff action buttons not permission-gated in the UI (backend 403s after click); stale header comment; empty assessment saves still toast "Assessment saved" and emit an audit event; two stale "UTC midnight" comments on Manila-correct code; `suggestTriage` inserts a new prediction row per click (no dedup); triage UI never shows `features_used` or an "advisory only" wording; FIFO-vs-urgent expectation gap (urgent badge has no effect on ordering — intentional, but untold).
- [P2, backend] Read endpoints skip record-level authorization inconsistently: `getEncounter` is record-gated (`:286`) but `listVitals`/`listTreatments`/`previousHeightWeight` are module-level only (`:260,669,321`).
- [P2, clinical safety] Medication dispensing never cross-checks the patient's allergy list — `consumeFefo` checks stock/expiry only (`ClinicService.php:765-831`); `suggestTriage` reads allergies but `addTreatment` doesn't.

### Supporting pieces

- **SessionProgressTracker** (50): `current` under-used (only vitals marked); disabled steps give no inline reason. P3s.
- **useClinic.ts** (323): P0 root; zod parse inside mutations can throw `ZodError` past the typed error contract (P2); vitals/treatments queries don't poll while their siblings do (P3).
- **Schemas**: vitals all-NULL gap; otherwise exact range parity with the backend (0-300/0-200/20-45/0-100/0-600/0-300) — good. No dose/unit field anywhere: medication recording is a raw unit count + free text (P3, product gap).

**Cluster totals:** 1 × P0 · 5 × P1 · 10 × P2 · 11 × P3.

---

## 7. Queue & kiosk

### QueueDisplayPage (lobby TV) — `frontend/src/pages/QueueDisplayPage.tsx` (94 lines) + `MediaPlaylistPanel.tsx` (138) + `useQueue.ts`/`useKioskSettings.ts`/`lib/chime.ts`

Purpose · unauthenticated lobby board: now-serving + waiting for both destinations, media playlist, chime. Public endpoints verified: `GET /clinic/queue/state`, `GET /kiosk-settings`, `GET /kiosk-media/{uuid}/(content|thumbnail)` — no unauthenticated POST exists in this cluster.

- [P1] **Media playlist restarts from item 0 every 15 s once server settings exist.** `useKioskSettings.ts:17-21` pushes a freshly-normalized settings object every 15 s tick and `MediaPlaylistPanel.tsx:75-81` resets index on playlist identity — items longer than 15 s never finish. Cheap fix: equality-gate the snapshot before `setSettings`.
- [P1] **Public feed publishes the full waiting list with full name + school ID for the clinic branch** (`QueueService.php:390-396`, verified) while the guidance branch was minimized to position + queue number (G-###, `Counselling/QueueService.php:594-600`, locked by `PublicRoutesTest`). The class docblock still claims "the school id is never exposed unauthenticated" (`QueueService.php:28-30`) — the docblock must go; consider extending the F16 minimization to clinic *waiting* rows (name only on now-serving).
- [P2] Chime is gesture-gated but the board has no gesture path — `unlockAudio()` is only wired on the station (`KioskStationPage.tsx:301`); an unattended TV's chime preset is silently dead (`chime.ts:29-37`).
- [P2] Board blanks on a transient poll error (`QueueDisplayPage.tsx:82-83`; no `placeholderData`) — a lobby TV shouldn't empty out; chime/flash watches only `now_serving` so a second Guidance call never chimes (`:65`).
- [P3] `est_wait_minutes` fetched but never rendered; header clock uses browser-local tz (no `timeZone: 'Asia/Manila'`) and rebuilds the formatter every second; each 5 s poll costs ~6 backend queries (two OR-joins, two averages) with no ETag.

### Staff Check-in Kiosk — `frontend/src/pages/KioskPage.tsx` (352) + `useCheckin.ts` (65)

- [P1] **Manila-day regression, live in `CheckinService::scan`.** The clinic-appointment lookup compares UTC `scheduled_at` against Manila wall-clock strings used as UTC bounds (`CheckinService.php:150-151` → `:236-243`, verified): an evening scan (≥16:00 Manila) matches *tomorrow morning's* appointment and checks it in today; a pre-08:00 appointment is missed and the kiosk silently creates a second walk-in. Exactly the 2026-08 regression class `ManilaDay.php:14-17` documents; every neighboring path does this correctly.
- [P2] No in-flight submit lock on this surface (the station added `submitLockRef`); camera path bypasses the purpose pre-check — `onDecoded` submits directly (`KioskPage.tsx:345`) and always round-trips a 422 if no purpose was chosen.
- [P3] Guest check-ins render a blank Patient column (`guest_name` is in the schema, the table prints only `patient_school_id`); stats derive from the client-side 15 s trail; offline-buffered scans recorded before the purpose requirement are now always rejected on sync.

### Kiosk Station (fullscreen) — `frontend/src/pages/KioskStationPage.tsx` (537) + `KioskCheckin.tsx` (919)

- [P1] **The scan-result modal is a bystander-readable PII surface** — full name, student number, course, year level, allergy flag (`KioskCheckin.tsx:742-751,606-618` from `CheckinService.php:557-589`). The 15-second auto-clear is real, but on lobby hardware the station removed the trail for exactly this reason while the modal kept the full identity. Render first name + queue number on the station.
- [P2] The ID field strips all non-digits (`KioskStationPage.tsx:226-231`) — employee numbers (`EMP2024001`) and non-numeric QR/RFID payloads cannot be entered on the primary affordance; the dashless-EMP fallback in `KioskCheckin.tsx:70` is unreachable from here.
- [P2] A skipped clinic entry is terminal and a called entry blocks the queue with no timeout — no un-skip transition exists (`QueueService.php:35-46`), `callNext` 409s while anything is `called/in_session` (`:230-235`), and abandoned `open` encounters linger until tomorrow's sweep (`:176-204`). One forgotten `called` slot freezes the lane until someone remembers Skip.
- [P3] Stale attract-screen docblocks; local `useDebounced` duplicates the shared hook; "fullscreen" is layout-only (no Fullscreen API); lock-release races its own double-trigger (mitigated server-side).

### Backend queue/kiosk surface

- [P2] Tenancy: 12 raw-query sites confirmed unscoped (guest/registered duplicate windows match across tenants; `MAX(position)` allocation and `callNext` select without tenant — interleave/cross-tenant pick in multi-tenant; wait averages aggregate all tenants into the public estimate). See §13.1.
- [P2] Rate limiting is one global 600/min bucket shared by public GETs and authenticated traffic (`ApiRateLimitFilter.php:22-24`) — a lobby attacker on shared NAT can starve the TV's own quota; no tighter per-route bucket.
- [P3] `GET /kiosk-media/{uuid}/content` serves archived assets (`KioskMediaService::publicFile` has no `archived_at` check) and `publicThumbnail` can spawn ffmpeg repeatedly from an unauthenticated route (global bucket is the only brake); dead `POST /clinic/queue` route targets a method that no longer exists (`Clinic/Routes.php:151`); stale docblocks.
- Verified clean: purpose catalog server-enforced and SPA-mirrored; media MIME allowlist excludes SVG (no stored XSS via media), names sanitized, uploads decode-validated; settings save revision-checked with URL validation both ends; no-show cascade leaves `in_session` alone; call-next FIFO explicit and immutable.

### Portal "Your Queue" card — `YourQueueCard.tsx` (17) + `useMyQueue.ts` (25)

- [P3] Query errors swallow into "no card" — a 403/500 during a `called` state makes the patient's "You're up" banner silently vanish exactly when it matters; render last-known state with a stale dot. Polling copy ("every 10 seconds") matches the hook. Dead `_kind` parameter.

**Cluster totals:** 3 × P1 · 9 × P2 · ~12 × P3.

---

## 8. Inventory

### InventoryPage + `components/inventory/` (page 70 lines; 25 extracted components) — the extraction pattern works

Purpose · medicines (batch-tracked, FEFO) + supplies (signed ledger) + reorders + insights. Guard · route/sidebar `clinic.inventory.read`; backend splits read/write/forecast/delete (`MedicineService.php` policy checks in every method).

- [P1] **Archive/unarchive offered to roles that can never succeed.** `clinic_staff` holds read/write/forecast but **not** `clinic.inventory.delete` (`AuthGroups.php:64-81` — only admin's wildcard does); MedicinesTab and SuppliesTab render Archive unconditionally (`MedicinesTab.tsx:161-166`; `SuppliesTab.tsx:140-145`), so the primary role gets a guaranteed 403 after ConfirmDialog on a destructive action.
- [P2] **Repeated partial receives can over-receipt beyond the order** — the clamp is per-call (`MedicineService.php:229-233`), `requested_quantity` never decreases (`ReorderService.php:306-325`), and the dialog pre-fills the full ordered amount every time (`AddBatchDialog.tsx:52-56`); receiving 30-of-30 twice lands 60. Same on supplies (`InventoryService.php:406-412`).
- [P2] **Tab filter collision:** MedicinesTab and SuppliesTab read/write the same `?q=`/`?archived=` (`MedicinesTab.tsx:73-74`; `SuppliesTab.tsx:70-74`) — a medicine search silently filters supplies after a tab switch.
- [P2] **ReordersTab filters not URL-backed** (`ReordersTab.tsx:53-55`) — the only tab in the page violating the convention.
- [P2] **Systemic UTC day-boundary math in expiry/FEFO** — FEFO cutoff (`MedicineService.php:369`), on-hand aggregation (`:863`), expiring window (`:480-481`), received-date default (`:236`), reorder timestamps (`ReorderService.php:258,360`; `InventoryService.php:455`), and the frontend `daysUntil()` helper (`components/inventory/format.ts:8-10`) all use raw UTC — expiry chips drift up to 8 h against the Manila day the clinic operates on, *differently* than the backend's own cutoffs.
- [P2] **New alert banner (uncommitted) counts long-expired lots as "expiring within 30 days"** — `listExpiring` has no lower bound (`MedicineService.php:489`), has **no dismissal at all**, and its "Review in Medicines" jump lands on an unfiltered tab (no low-stock filter exists medicines-side; supplies have one) (`InventoryStockAlertBanner.tsx:31-48`). Also medicines-only while supplies have the same low-stock signal.
- [P2] WriteOffBatchDialog is a bare `DialogContent` nested inside BatchesDialog's already-open root (`WriteOffBatchDialog.tsx:37` inside `BatchesDialog.tsx:101-103`) — shared Escape/focus semantics on the destructive path; ConfirmDialog's own-root pattern is the fix.
- [P2] Reorder item pickers are hard-capped without search — first 25 medicines / 100 supplies orderable (`CreateReorderDialog.tsx:31-32`); error path points at `medicine_id` regardless of type (`:148-150`).
- [P2] Zod errors for `reorder_threshold`/`reorder_level` are never rendered — typing `-5` silently blocks submit with no message (`CreateMedicineDialog.tsx:176-185`; `EditMedicineDialog.tsx:73-85`; supplies twins).
- [P2] Backend: scanner flags confirmed (`MedicineService.php:373,660,908`; `InventoryService.php:558` — PK-keyed, no practical leak, scanner stays red); **duplicated FEFO engine** — `ClinicService::consumeFefo` re-implements the same locked FEFO + balance SQL as `dispense()` (two writers to one ledger, `ClinicService.php:763-848`); permission checks live in services only for Inventory/Reorder controllers while MedicineController authorizes at both layers — future service methods ship silently unprotected; batch-number duplicate check is a non-locking read (second insert 500s instead of 409).
- [P3] Archived supply rows lose the ledger entry point (medicines keep batches visible — twins drifted); raw `YYYY-MM-DD` rendering vs `fmtUtcToApp` convention; DispenseDialog lacks the quantity max its supply twin has; ledger error row has no retry and is silently capped at 200 rows; Insights "Reorders in flight" counts only the first 50 rows and every "View all" jump is unfiltered; recall confirm styled non-destructive; forecaster's `accuracy_metrics` are **hardcoded constants persisted as model output** (`InventoryForecaster.php:68`); auto-check cooldown stamp is read-then-write without a lock and stamps before failed runs; `SYSTEM_USER_ID = 1` assumption; `?limit>100` → 500.
- **Stock-integrity verdict: solid.** FEFO under `FOR UPDATE`, insufficient-stock 409, append-only typed ledger with stored running balance, receive gated on a locked `received` reorder and completed in-transaction, audit rows on every mutation, nothing can go negative on either path.
- **Test gap:** exactly one unit test for the whole cluster (`InventoryForecasterTest`) — the most invariant-heavy code in the repo is the least tested.

**Cluster totals:** 1 × P1 · 13 × P2 · ~8 × P3.

---

## 9. Counselling — verification of the in-flight fix pass

The 2026-09-03 audit's 17 findings + U1–U8 were re-verified against the working tree; the restructured frontend (page 1,415 → 122 lines + 17 components under `components/counselling/`) was spot-checked for new-code health. Full per-finding table in **Appendix A**. Summary:

**Verified fixed:** false E2E-encryption copy (gone); action-aware policy — own-session `readNotes`/`writeNotes`/`close`/`refer`, `read_any` oversight for supervisor+admin, audited reassignment route, archive/unarchive on `soft_delete` (`CounsellingPolicy.php` verified); reveal-gated decryption with honest audit-log copy (`SessionNotesList.tsx:24-35`); tenant predicates on all 12+ raw queries (module absent from the scanner); analytics tenant unique-key migration; three-strike gate in `book()` with `team_manage` supervisor override (`NO_SHOW_STRIKE_LIMIT = 3`); write-on-GET removed from `today()` (moved to the spark command); appointments list rebuilt (keyset 25, `getNextCursor`, `appointment_date ASC`, status+date filters); past-date + unregistered-patient booking refusals; URL-backed scheduling/analytics state; retention purge command refusing an unset period; archive/unarchive routes; public guidance branch minimized to position + number; `called_at` through `fmtUtcToApp`; empty-state copy on queue and notes; dirty-draft confirm on the notes dialog; 12 `isPending` guards across dialogs; session deep-link preserved.

**Still open after the fix pass:**

- [P2] **Byte-vs-char note cap** — controller `max_length[16384]` (chars) vs service `strlen` (bytes) with a "16 KiB" message (`CounsellingController.php:104`; `CounsellingService.php:197-199`): a 16,000-character Tagalog/emoji note passes validation then fails with a misleading error. Unchanged.
- [P2] **Analytics recompute still aggregates all history with `cancelled` in the denominator** (`ScheduleService.php:418-427` — `COUNT(*)` with no status exclusion and no date window), and `avgUtilization` still returns hardcoded `0.85` (`SchedulingAnalytics.php:42`) — stored, schema-validated, never rendered. Tenant scoping itself is fixed.
- [P2] **Skipped queue entries remain terminal** — no un-skip; queue reassign covers `waiting|called` only (`QueueService.php:278-282`).
- [P2] **`counsellors()` still filters on group name `counsellor`** (`ScheduleService.php:81`) — supervisors with `team_manage` can't be selected as bookable counsellors (U2).
- [P3] Analytics tab still renders `#{counsellor_user_id}` instead of a name (`AnalyticsTab.tsx:137`); `enqueueDueAppointments` still builds two inline `Asia/Manila` DateTimeImmutables instead of `ManilaDay` (`QueueService.php:59,72` — day math itself correct); removing an availability window still orphans its bookings without a listing (unchanged); notes are still not re-read after write (no decryption round-trip verification).
- Note: the write-on-GET pattern survives on the **portal** side (`PortalAppointmentService.php:125` calls `enqueueDueAppointments()` inside `GET /me/queues`) — flagged in §4.

---

## 10. Facilities / BMG

### FacilitiesPage — `frontend/src/pages/FacilitiesPage.tsx` (1,991 lines, 13 in-file dialogs)

- [P1] **The Curing phase is unreachable from the web and invisible once entered.** `AddUpdateDialog` hardcodes `update_type: 'log'` (`:311`); `useMoveToCuring` has zero call sites (`useFacilities.ts:793-828`); `listActiveBatches` excludes `curing` (`AnalyticsReader.php:81`) so a curing drum vanishes from the Processing Drums widget and DrumDetail shows "No active batch" — for a 1–3 month phase.
- [P1] **Write actions shown to every reader** (all ~15 menu items; no `me.permissions` checks) — latent while only `facilities_op` holds the module, but owner-scoped *reads* already 403 in practice: "View updates" on another operator's batch renders "Could not load updates." (`:392`).
- [P2] Units list masks API errors as the empty state — a failed fetch renders "No drums yet. Create one" and invites a duplicate (`:1736-1742,1801-1805`).
- [P2] Cursor/history/showArchived not URL-backed (`:1541-1543`); BatchHistoryDialog keeps its own cursor/filter locally (`:1423-1425`).
- [P2] Three unreachable dialogs + hooks ship dead weight — `RecordOutputDialog`, `ProcessLogsDialog`, `ReleaseBatchDialog`, `useRecordOutput`, `useReleaseBatch` (`:1549-1556,1873-1947`).
- [P2] Suggest-a-drum silently broken — backend returns a 6-field subset, frontend parses with the full unit schema, parse always throws, banner never renders, no error anywhere (`useFacilities.ts:1056-1068` vs `BmgService.php:1846-1853`).
- [P2] Cancel reason hardcoded `'unspecified'` (`:1661`); AnalyticsDialog quick "Record output" writes to the unguarded structured-outputs ledger (see backend below); analytics loss payload (`total_loss_kg`/`losses`) stripped by the frontend schema (`schemas/facilities.ts:380-398`).
- [P3] Stale lifecycle copy (no Curing/Released); docblock says server uppercases unit codes, server now lowercases; impossible `'cancelled'` unit-status branch; half-copied optimistic pattern (`onError` consumes a snapshot `onMutate` never provides); OpenAlertsBanner items aren't links; ad-hoc error paragraphs instead of shared `QueryErrorRow`.

### DrumDetailPage — `frontend/src/pages/DrumDetailPage.tsx` (627 lines)

- [P1] Curing drums show the dead-end empty state (derives the batch from `useActiveBatches`, which excludes curing) — same root as above.
- [P2] Losses are write-only: the backend's `GET /batches/{id}/losses` returns a running total no hook ever calls; no invariant or soft warning as losses approach input mass.
- [P2] Acknowledge enabled for users who will 403 (`alerts_ack` is batch-owner-only, `BmgPolicy.php:81`) — toast failure, alert stays.
- [P3] Dead `'Input'` badge branch; loss/IO hooks untyped (`unknown`, no zod) unlike every other hook.

### WasteCategoriesPage — `frontend/src/pages/WasteCategoriesPage.tsx` (414 lines)

- [P2] This page renders the P0 — `historical_avg_days`/`sample_count` per row (`:147-151`) are computed cross-tenant.
- [P2] Create form under-validates (non-empty only; `"veg scrp"` reaches the server and fails the slug contract) and reports zod failures as a generic toast; `showArchived` not URL-backed; DeviationReport failures render as "no data" (and the endpoint needs `manage_units`, so read-scoped future roles get nothing silently).
- Positive: inline archive/delete confirms; delete-409 → "Archive instead" toast action is good UX.

### BMG backend — `BmgService.php` (1,870) + `Bmg/*` collaborators

- [P0] **Cross-tenant aggregates:** `BmgSupport.php:226-239` and `:292-303` (scanner-flagged, verified as genuine tenancy defects — injection-safe but unscoped) feed category duration stats/samples into every tenant's category list (`CategoryService.php:57,139,70-71`) and drum analytics (`AnalyticsReader.php:220-235`). The class docblock claiming tenant scoping is false for these two.
- [P1] **Cancel accepted from the terminal `released` state** — `BmgService.php:852` blocks only `idle`/`cancelled`; the API state machine admits an illegal transition the UI never offers.
- [P1] **STALLED alert is unreachable** — the alert engine's only caller is `addProcessLog` (`BmgService.php:1306`); a batch nobody logs against for 14 days can never fire the alert built for exactly that. No spark command exists.
- [P1] **Three parallel output ledgers with different invariants** — (a) update-ledger enforces cumulative ≤ input (`:344-352`); (b) legacy `recordOutput` overwrites the denormalized column with a single-value check (`:601-617`); (c) structured `facilities_bmg_outputs` rows have **no mass check at all** (the DB trigger covers only the denormalized column) — and `finishBatch` overwrites the column with the final yield (`:807`) which the PFRP certificate then reads (`AnalyticsReader.php:336-342`): cumulative output can silently vanish from the certificate.
- [P2] Alert + notification spam with no suppression (doc claims 24 h dedup; every log inserts and fans out, `:1317-1364`); notifications go to all `logs.read` holders but ack/updates/logs/analytics are owner-only (`BmgPolicy.php:32-46,80-81`) — everyone is told, only the starter can act or even read; process logs refused on curing batches while the update-ledger allows them (`:1248-1252` vs `:330`); UTC day math in progress/ETA (`:104`; `AnalyticsReader.php:85`; `BmgAnalytics.php`) bypassing `ManilaDay`; `listOpenAlerts` unbounded.
- [P3] Dead duplicate null-check (`:1491-1495`); `suggestUnit` field subset breaks its consumer; compliance `thermophilic_days` counts log rows, not distinct days as its own comment claims (`AnalyticsReader.php:310-333`).
- **God-file verdict revised:** BmgService already delegates to five collaborators and its remainder is a coherent transaction core — the actual god files are now **FacilitiesPage (1,991)** and **useFacilities.ts (1,080, 28 hooks)**; split those along dialog/hook families.

**Cluster totals:** 1 × P0 · 5 × P1 · 12 × P2 · 10 × P3.

---

## 11. Referrals

### ReferralsPage — `frontend/src/pages/ReferralsPage.tsx` (917 lines) + `useReferrals.ts` (211) + backend `ReferralService.php` (746)

Purpose · shared board for referrers (faculty/clinic/counselling) and handlers: status-filtered keyset list (30 s polling), create, lifecycle transitions, queue handoff, counselling booking, QR issue/revoke, camera/paste verify.

- [P1] **Lifecycle buttons ignore permissions and receiving-side.** Acknowledge/Review (`:807-811`), Book (`:813-817`), Issue-QR (`:826-830`), Revoke-QR (`:831-839`), Close (`:841-847`) render for whoever can open the page; a faculty employee sees handler buttons on their own referrals and eats 403s from `ReferralPolicy::checkReceivingSide` (`ReferralPolicy.php:87-99`). Only the handoff button is permission-gated (`:795-796`).
- [P2] **"Close referral" offered from `submitted`** (`:841-847`) but the transition map allows close only from `acknowledged|under_review` (`ReferralService.php:465-470`) — guaranteed 409 on click.
- [P2] **Encrypted notes unreachable from the create flow** — the schema accepts `notes_plaintext` (8,192) and the backend encrypts it (`ReferralService.php:204-209`), but `CreateReferralDialog` renders no notes field; the feature exists only via contextual encounter/session endpoints.
- [P2] **"Only teaching employees can refer" hint over-blocks** — the UI hides New-referral for every employee-kind user (`:658-659,714-722`) while the backend blocks only `source_module === 'clinic'` (`ReferralService.php:137`); non-teaching employees have no path to create counselling→clinic referrals.
- [P2] **QR issued for, and verifies as valid on, closed/submitted referrals** — `issueQr` checks only existence (`:538-562`), `verify()` checks revoked/expired but never status (`:636-668`). Return `referral_status` in the verify envelope and reject issuance on non-active statuses.
- [P2] "Showing your referrals" badge misfires for staff linked to the employee registry — scope inferred from `person_kind` (`:662,727-731`) while the server scopes by `servingSides()` (`ReferralPolicy.php:35-57`).
- [P3] Pagination cursor not URL-backed (status filter correctly is); `useQueueHandoff` invalidates a guessed queue key (brittle cross-module coupling); public verify route has no throttle (token is 128-bit CSPRNG, brute-force infeasible, but unmetered); expiry comparison via `strtotime` is TZ-fragile if `appTimezone` ever changes.
- Verified non-issues: `ReferralService.php:168/:211` scanner flags are **false positives** (parameterized PK lock; plain builder insert). Notes encrypted at rest and never serialized; QR stores only the keyed HMAC; verify returns minimum disclosure; duplicate guard row-locked and tenant-scoped.

**Cluster totals:** 1 × P1 · 6 × P2 · 4 × P3.

---

## 12. Reports, Audit trail & Admin

### AuditPage — `frontend/src/pages/AuditPage.tsx` (642) + `useAudit.ts` (96) + `useAuditExport.ts` (72) + `AuditEventController.php` (323)

Purpose · oversight console over the hash-chained event store: filters, facets, detail with redacted payload, chain verification, 5,000-row CSV export.

- [P1·latent] **Audit-trail reads are not tenant-scoped.** The events builder, facets, export, and verify never filter `tenant_id` (`AuditEventController.php:184-187,58-76`; verified) although the column exists (2026-08-02 migration) — any `audit.read` holder reads every tenant's events, and the facets' actors query exposes all tenants' actor emails. Same class as the dashboard counter (§3).
- [P2] **Forensic date filters bucket on UTC, not Manila.** `from`/`to` are naive `YYYY-MM-DD` compared against UTC `occurred_at` (`:246-251`) while the UI renders timestamps in Manila — filtering "Sep 5" silently excludes Sep 5 Manila 00:00–07:59 events (they occurred Sep 4 UTC). For a tool whose job is "what exactly happened", this is a correctness gap; convert bounds like `ReportRange` does.
- [P2] **CSV export has no formula-injection guard.** `CsvWriter` uses raw `fputcsv` (`Services/Export/CsvWriter.php`) with no neutralization of cells starting with `=`, `+`, `-`, `@` — `payload_json` contains user-controlled strings (names, notes). Shared by the reports CSV path.
- [P3] `q` filter LIKEs `payload_json` with wildcards unescaped (`:253-255`); facets are unbounded full-table distincts (5-min client cache only); `verifyAll` runs the full chain synchronously in-request (120 s client timeout; the `synapse:audit-verify` spark command is the alternative).
- Verified good: strict filter validation (32-hex request_id, real date checks, length caps), keyset pagination, redaction (`AuditPayload::REDACT_KEYS` covers password/token/plaintext/school-id), export limit 5,000 with cursor, blob download with bearer + filename parsing, 15 s polling with `placeholderData` so the console doesn't blank.

### ReportsPage — `frontend/src/pages/ReportsPage.tsx` (441) + `useReports.ts` (444) + `components/reports/` + `ReportConfigService.php` (597) / `ReportService.php` (668)

- [P2] **Saved report configurations have no ownership check** — `updateConfig`/`archiveConfig` lock on `(tenant_id, id, is_active)` and never compare `created_by_user_id` (`ReportConfigService.php:101-131`), so any `reports.read` holder can edit or archive anyone's saved config. Decide: shared-by-design (document it) or ownership.
- [P2] **`claimNext()` claims across tenants** — the raw `SELECT … WHERE status='queued' … FOR UPDATE` has no tenant predicate (`:322-325`); the subsequent tenant-scoped UPDATE would no-op on a foreign-tenant row, leaving a claimed-but-queued job and a drain loop. Latent single-tenant.
- [P3] Draft-range state and per-module narratives in local state (the applied range is URL-driven — acceptable); no CSV formula guard (shared with §12 above); PDF share path (Web Share API → download fallback) is well-built.
- Verified good: **ReportRange is Manila-aware** (calendar dates selected in Manila, converted to inclusive/exclusive UTC bounds — `ReportRange.php:14-20,78-84`) — the model other modules should copy; generated-report polling is conditional (only while queued/processing, `useReports.ts:393-395`); expiry cleanup deletes the file and marks `expired`; download path is tenant-scoped with `basename` traversal safety and a friendly not-ready 409.

### AdminUsersPage — `frontend/src/pages/AdminUsersPage.tsx` (739) + `UserAdminService.php` (393)

- Verified safe on the scary paths: self-demotion blocked ("You cannot remove your own admin role", `UserAdminService.php:332`); granting the admin group requires holding it (`:304`); temp passwords are one-time in the response with `force_reset=1` and the audit payload carries only `resource_code`/`next_status` — **no plaintext password lands in the audit chain** (verified against `AuditPayload::REDACT_KEYS`).
- [P3] Only filters are URL-backed (`?q/status/group/sort` via `useSearchParams`, `:389-397`) — pagination cursor/history remain local; `create()` accepts a caller-supplied password (still forced through `force_reset`, but it bypasses the "generate + show once" flow's intent).

### AdminKioskSettingsPage — `frontend/src/pages/AdminKioskSettingsPage.tsx` (152) + `KioskMediaLibrary.tsx` (155)

- [P3] Playlist scheduling uses `datetime-local` inputs (browser-tz naive on a Manila clinic's laptops); otherwise the editor is strong: revision-checked saves, URL validation both ends (verified by §7), chime preview, ConfirmDialog on destructive playlist edits, media upload MIME allowlist verified server-side.
- The queue-display half of settings was audited in §7 (the 15 s playlist-reset P1 lives in the consumer, not this page).

**Cluster totals:** 1 × P1 · 5 × P2 · ~8 × P3.

---

## 13. Cross-cutting findings

### 13.1 Tenancy — the ratchet is red at 30 sites

`composer scan:tenancy` (re-run during this audit) reports **30 unscoped sites**, up from 1 in `ANALYSIS.md` because the scanner now sees `$this->db->query()`/`->table()` raw SQL (the extension the counselling audit recommended). The counselling module is clean (fix pass landed). The remaining sites, by file:

| File | Sites | Nature |
|---|---|---|
| `Clinic/Services/CheckinService.php` | 8 | guest + registered duplicate windows (cross-tenant name/ID match suppresses check-ins), appointment lookups, `MAX(position)` allocation, wait-time averages |
| `Clinic/Services/QueueService.php` | 4 | referral-handoff position + active check, `callNext` FOR UPDATE scan (can pick another tenant's row → tenant-scoped update no-ops → failed call), wait average |
| `Clinic/Services/ClinicService.php` | 5 | bulk-import position lock, no-show/completion lookups, FEFO + balance chain |
| `Clinic/Services/AppointmentService.php` | 1 | manual check-in position query |
| `Clinic/Services/MedicineService.php` | 3 | FEFO lock, lastBalance, lastMovementByMedicine (PK-keyed) |
| `Clinic/Services/InventoryService.php` | 1 | lastMovementByItem (PK-keyed) |
| `Clinic/Services/EncounterCompletionService.php` | 1 | queue-entry lookup |
| `Clinic/Services/PortalAppointmentService.php` | 2 | guidance availability + appointment count on booking |
| `Facilities/Services/Bmg/BmgSupport.php` | 2 | **not latent** — cross-tenant aggregates actually rendered (the P0, §10) |
| `Referrals/Services/ReferralService.php` | 2 | **false positives** (parameterized PK lock; plain builder insert) |
| `Reports/Services/ReportConfigService.php` | 1 | `claimNext()` job claim (§12) |
| **Outside the scanner:** `Controllers/Api/Dashboard/DashboardController.php:66-72`, all of `Controllers/Api/Audit/AuditEventController.php` | — | `audit_events` reads without tenant (dashboard counter; audit list/facets/export/verify) |

All are latent in the current single-tenant deployment except BmgSupport. Fix order: BmgSupport (P0) → queue position/callNext/duplicate windows → audit reads → the PK-keyed stragglers (or document a sanctioned raw-SQL contract the scanner recognizes).

### 13.2 Manila-day violations (the documented regression class, live again)

1. `CheckinService.php:236-243` — Manila strings as UTC bounds on `scheduled_at` (P1, §7).
2. `ClinicService.php:144-145,179` — bulk import queue_date on the UTC day (P1, §6).
3. `AppointmentsPage.tsx:685-697` — `Date.parse` on zone-less UTC for Upcoming/Past (P1, §5).
4. Inventory expiry/FEFO chips + backend cutoffs — UTC day math on both ends, disagreeing with each other (§8).
5. BMG progress/ETA — UTC `DateTimeImmutable` throughout (§10).
6. Audit `from`/`to` filters — naive dates vs UTC `occurred_at` (§12).
7. Re-implementations bypassing the helper: `AppointmentService.php:897-898`, `PortalAppointmentService.php:22,206-207`, `Counselling/QueueService.php:59,72` (the counselling audit's F13, mostly fixed).

`ReportRange.php` is the in-repo model to copy: calendar dates in Manila, converted to inclusive/exclusive UTC bounds, documented at the top.

### 13.3 UI permission parity

The recurring P1/P2: **pages gate routes but not actions.** Patients (register/edit/archive/departments), Appointments (schedule/edit/issue-QR), Facilities (everything), Referrals (lifecycle/QR/book), Inventory (archive) all render write affordances to read-only roles. ClinicPage's `canWrite` pattern (`ClinicPage.tsx:626-628`) is the in-repo model. A `useCan(permission)` helper over `me.permissions` applied to every action button closes the class.

### 13.4 Other cross-cutting items

- **Keyset pagination:** correct everywhere except `useAppointments.ts:44-48` (dead pagination, P1) and hand-rolled duplicates in PatientsPage/FacilitiesPage that should use `useKeysetPagination`. Backend cursor contract verified sound.
- **URL-state convention:** violations in Appointments cursor, Patients cursor, Clinic cursor/showArchived, Facilities cursor/showArchived, WasteCategories showArchived, ReordersTab filters, Notifications cursor/onlyUnread, Audit cursor/draft, Referrals cursor. (Filters/tabs themselves are URL-backed in most pages — the residue is mostly pagination state.)
- **Notification copy map is stale** (`utils/notifications.ts`): `referral.queue_handoff`, `referral.qr_issued/qr_revoked`, `counselling.session_opened/closed/reassigned` (new in the fix pass), `counselling.appointment_booked`, `kiosk.*`, `admin.*` (incl. `admin.portal_account_minted`) render as raw template codes to users; the deep-link handler covers only `appointment./referral./reorder.` prefixes — `bmg.alert_triggered` (→ `/facilities`), `queue.called` (→ `/me`), `admin.*` (→ `/admin/users`) click through to nowhere.
- **CSV export:** no formula-injection guard anywhere (`CsvWriter` is shared by audit + reports).
- **Backend 500s instead of 422** for out-of-range `limit` (`KeysetPaginator::apply` throws uncaught) — seen in appointments, inventory, reports.
- **Test coverage asymmetry:** the inventory services (most invariant-heavy) have one unit test; the no-show cascade has none; counselling gained `CounsellingNoteAccessTest` (feature) in the fix pass — the right direction.

---

## 14. Mobile (Flutter)

**Overall:** production-quality code, production-incomplete packaging (unchanged verdict, re-verified). Auth stack (proactive refresh on `exp` with skew margin, secure storage, single replay) is genuinely mature; `AutoPolling` pauses hidden tabs; envelope/keyset handling in `api_service.dart` is correct (`ApiPage` + `PaginationMeta`).

### Verified ship-blockers (all still present)

- `com.example.synapse_mobile` / `com.example.synapseMobile` bundle IDs (`android/app/build.gradle.kts:8,22`; iOS `project.pbxproj:385`).
- Android release buildType signs with the **debug key** (`build.gradle.kts:35`); no iOS signing team.
- Tests still 2 files (`models_test.dart`, `session_workflow_test.dart`) — no widget/auth-refresh/API-client coverage for a clinical client.
- Poll-only notifications (no push).

### Per-screen highlights

- **dates.dart (56):** fixed +8 h Manila conversion — correct for UTC+8/no-DST and honestly documented ("good enough for the demo"); no Manila day-boundary helper, so date inputs use the device-local calendar (`toDateInput`) — wrong if a device isn't on Manila time. [P3]
- **counselling_session_workspace.dart (406):** **auto-decrypts notes on open** (`results[1]` fetched during load, `:83`) — the web's reveal-gating fix has no mobile equivalent, so the mobile app re-creates the audit-noise behavior web just removed. [P2] Lacks the new reassign/archive/amendments features (parity gap, not breakage). [P2] "Session notes encrypted and saved" toast should match the corrected at-rest wording. [P3]
- **api_service.dart (1,854):** envelope unwrap + typed `ApiException` interceptor is solid; some lists hardcode `limit: 100` (`:160`) — fine under the ~200 cap; no mobile calls to the missing no-show route (no breakage there).
- **auth_controller.dart (171):** bootstrap refreshes first when the persisted token is expired (one round trip, `:51-56`), revokes the family on logout — mirrors the web contract faithfully.
- **auto_polling.dart (82):** pauses hidden tabs via `TabVisibilityScope`; **no `AppLifecycleState` handling anywhere** — polling continues while the app is backgrounded until the OS suspends the process. [P3]
- **Screens verified for structure:** home shell/module hub consistent; queue/clinic/counselling workspaces reuse `common/widgets`; fail-fast errors with retry affordances; double-submit guards present on forms inspected.

**Mobile totals:** 0 × P0/P1 new · 2 × P2 (auto-decrypt parity, feature lag) · 6 × P3 · 4 confirmed config-level ship-blockers.

---

## 15. Remediation roadmap (ranked by leverage)

> **Implementation status (2026-09-05, later the same day):** items **1, 2, 3, 4, 5, 7, 9, 11, 13 (archive gating half), 14, 15, 16, 17, 20** plus the portal QR pass-through are implemented in the working tree, and the tenancy scan moved **30 → 25** (BmgSupport, PortalAppointmentService, ReportConfigService fixed; the remainder are queue-position/PK-keyed raw sites needing transaction-level work). Backend feature suite grew by 5 tests (`EncounterNoShowTest`) covering the no-show cascade and both directions of the kiosk-appointment UTC-bounds fix. A responsive policy fix (sidebar Sheet below 1024px, `use-mobile.ts`) resolved the 768px overflow class. All gates green: unit 220/220, feature 45/45, typecheck/lint/vitest clean, Playwright **21/21 mocked** + **12/12 live** (1 by-design skip). Not yet implemented: 6 (remaining 25 tenancy sites), 8 (inventory FEFO UTC chips), 10 (Facilities gating), 12 (BMG output-ledger decision), 18 (god-file splits), 19 (mobile parity).

| # | Action | Effort | Where |
|---|---|---|---|
| 1 | Register `encounters/(:num)/no-show` route + feature test (un-breaks a shipped clinical button) | Trivial | §6 |
| 2 | Tenant-scope `BmgSupport::categoryDurationStats/categorySamples` (P0) | Trivial | §10 |
| 3 | Fix `CheckinService::scan` Manila-vs-UTC appointment bounds (P1) | Small | §7 |
| 4 | Fix `useAppointments` cursor read (`getNextCursor`) — restores staff pagination | Trivial | §5 |
| 5 | Unify sign-out: palette → `useLogout()` + ConfirmDialog; add cross-tab refresh lock or server grace window | Small | §2 |
| 6 | Burn down the tenancy ratchet: queue position/callNext/duplicate windows → audit reads → stragglers | Medium | §13.1 |
| 7 | Equality-gate kiosk settings snapshot (stops the 15 s playlist reset) | Trivial | §7 |
| 8 | Replace `Date.parse` bucketing with a UTC parser; route all day math through `ManilaDay`/`ReportRange` patterns | Medium | §13.2 |
| 9 | Add no-show aging sweep; extend three-strike decision to clinic (or relabel) | Small | §5, §4 |
| 10 | `useCan()` permission helper; gate every write button (Patients, Appointments, Facilities, Referrals, Inventory) | Medium | §13.3 |
| 11 | Status-aware QR verify + token expiry + localStorage cleanup; confirm on re-issue | Small | §5 |
| 12 | Decide the one-true-output-ledger question in BMG; enforce invariants end-to-end | Medium | §10 |
| 13 | Add `clinic.inventory.delete` to clinic_staff **or** hide Archive without it; collapse over-receipt via cumulative tracking | Small | §8 |
| 14 | Encounter open-status guards on vitals/assessment/triage; triage record policy fix | Small | §6 |
| 15 | CSV formula-injection guard in `CsvWriter` | Trivial | §12 |
| 16 | Tenant-scope audit reads/facets/export; convert audit date filters Manila→UTC; delete dead `identity_coverage` | Small | §12, §3 |
| 17 | Notification copy map + deep-link coverage for the 2026-09-05 templates | Small | §13.4 |
| 18 | Split FacilitiesPage (1,991) + useFacilities (1,080) along dialog/hook families; delete the 3 dead dialogs | Medium | §10 |
| 19 | Mobile: bring reveal-gating + new counselling features to parity; then bundle IDs/signing for release | Medium | §14 |
| 20 | Referrals: gate row actions, restrict Close from `submitted`, add notes field to create, status-aware verify | Small | §11 |

---

## Appendix A — Counselling audit (2026-09-03) verification table

| ID | Finding (short) | Status | Evidence (working tree) |
|---|---|---|---|
| F1 | False "end-to-end encrypted" claim | **FIXED** | No "end to end"/"encrypted before" copy remains (grep clean); reveal panel says "Decrypting is recorded in the audit log (AES-256-GCM)" |
| F2 | Flat note access (`records.write` bypass) | **FIXED** | `CounsellingPolicy::canOnRecord` own-session for readNotes/writeNotes/close/refer; `read_any` for supervisor+admin (`AuthGroups.php:167-178`); reassign route audited; `CounsellingNoteAccessTest` added |
| F3 | Auto-decrypt + audit noise on row click | **FIXED** | `SessionWorkspace.tsx:39-46` gates `useNotes` on `revealedId`; explicit Reveal action |
| F4 | 13 unscoped raw queries in module | **FIXED** | Tenant predicates throughout (`QueueService.php:63,76,78,101,108,170,191,216,247,272…`); module absent from scanner output |
| F5 | Analytics tenant-blind (unique key, index, predicates) | **FIXED** | Migration `2026-09-05-000020` swaps unique key to include tenant + adds index; recompute reads/upserts tenant-scoped |
| F6 | Fictional three-strike policy | **FIXED** | `NO_SHOW_STRIKE_LIMIT = 3`; `book()` refuses at limit with `counselling.schedule.no_show_limit`, override via `team_manage` (`ScheduleService.php:56-63,281-291`) |
| F7 | `GET /counselling/queue` performs writes | **FIXED** | Controller no longer calls `enqueueDueAppointments`; spark command is the trigger. (Residue: portal `GET /me/queues` still does — §4) |
| F8 | Assigned-lane stranding; no reassignment | **PARTIAL** | `callNext` handles assigned lanes (`:170-173`); `POST queue/{id}/reassign` + session reassign added (waiting/called only); `skipped` still terminal, no un-skip |
| F9 | Appointments list truncates at 50, no "today", wrong order | **FIXED** | `useSchedule.ts:73-93` — keyset 25 + `getNextCursor`, `?status=`/`?date=`, backend `ORDER BY appointment_date ASC` (`ScheduleService.php:184-191`) |
| F10 | Past dates + unregistered patients bookable | **FIXED** | `ScheduleService.php:234-246` — past-date refusal + patient resolution required |
| F11 | Analytics: all history, cancellations counted, fabricated 0.85 | **PARTIAL** | Tenant scoping fixed; still `COUNT(*)` with no status exclusion or date window (`ScheduleService.php:421-427`); `avgUtilization` hardcoded (`SchedulingAnalytics.php:42`), still unrendered |
| F12 | Filter state not in URL | **FIXED** | `subtab/appt_status/appt_date/avail_view/sort_key/sort_dir` via `useUrlFilter` (`SchedulingTab.tsx:6-9`; `AnalyticsTab.tsx:32-33`) |
| F13 | `businessToday()` bypasses ManilaDay | **PARTIAL** | `ManilaDay::today()` used at `QueueService.php:99,173,439`; two inline `Asia/Manila` constructions remain (`:59,72`) |
| F14 | Byte-vs-char note cap | **NOT FIXED** | `CounsellingController.php:104` (chars) vs `CounsellingService.php:197-199` (bytes) |
| F15 | No retention/correction path | **FIXED** | `sessions/{id}/archive|unarchive` routes; `soft_delete` granted to supervisor+admin (seeder `:68-69`); note amendments (`supersedes_note_id`, migration `2026-09-05-000010`, amend action in workspace); `synapse:counselling-purge` refuses unset period, `--yes`/`--dry-run` |
| F16 | Public guidance feed exposes names/school IDs | **FIXED** | `publicRow` returns position + `G-###` only (`Counselling/QueueService.php:594-600`) |
| F17 | `#42` analytics label; raw `called_at`; window-removal orphans; no post-write re-read; `counsellors()` group filter; queue deep-link | **MIXED** | `called_at` FIXED (`GuidanceQueueTab.tsx:108`); queue deep-link preserved (`CounsellingPage.tsx:52-69`); `#{s.counsellor_user_id}` NOT fixed (`AnalyticsTab.tsx:137`); `counsellors()` still group-name filtered (`ScheduleService.php:81`); orphans + post-write re-read unchanged |
| U1 | Zombie `booked` status (nothing transitions it) | **PARTIAL** | Booking now gated by strikes; manual `no_show` transition exists; no automatic aging of stale appointments (same gap as clinic, §5) |
| U2 | Counsellor picker asymmetry (supervisors unselectable) | **NOT FIXED** | `counsellors()` filters `g.name = 'counsellor'` |
| U3 | Queue empty state gives no guidance | **FIXED** | "No Guidance check-ins today" + kiosk/due-appointment explanation (`GuidanceQueueTab.tsx:85-90`) |
| U4 | Analytics renders empty despite seeded rows | **LIKELY FIXED** | Root causes (tenant scope/collision) fixed; not re-verified against a live seeded DB in this pass |
| U5 | Theme toggle unreachable while modal open | **UNCHANGED** | Shell-level issue (§2 `useTheme`) — no in-dialog theme control |
| U6 | Note dialog loses drafts without warning | **FIXED** | `isDirty` + confirm-discard dialog + outside-click prevention (`WriteNotesDialog.tsx:28-57,98-101`) |
| U7 | Nested Scheduling tabs have no URL state | **FIXED** | `subtab` param (`SchedulingTab.tsx:6`) |
| U8 | Zero-note state indistinguishable from failure | **FIXED** | "No notes on record."/"No notes yet." + note_count summary (`SessionNotesList.tsx:28-30,54-56`) |

---

## Appendix B — ANALYSIS.md items re-verified

| Item | Status |
|---|---|
| God files: BmgService 1,870 / FacilitiesPage 1,991 / api_service 1,854 | Confirmed; **verdict revised** — split FacilitiesPage + useFacilities first (BmgService already delegates; §10) |
| Tenancy 1 flagged site | Superseded — scanner extension now reports 30 (§13.1); Referrals flags are false positives |
| Dead `showInactivePatientBanner` | Still present (now `Layout.tsx:44`) |
| localStorage policy | Tokens still memory-only (verified); non-token operational state (kiosk buffer, QR tokens) unchanged — QR token persistence now specifically flagged (§5) |
| Router v7 debt, thin vitest coverage | Unchanged |
| Mobile release blockers | All four still true (§14) |
| Demo seeders prod-gated; CREDENTIALS.md stale | Not re-audited this pass (out of scope: screens) |

## Appendix C — Method & coverage

- **Per-cluster deep passes:** 7 agent-driven (shell/auth, clinic, patients/portals, appointments, queue/kiosk, inventory, facilities/referrals) + 5 done directly (dashboard/notifications, counselling verification, reports/audit/admin, mobile, cross-cutting scans). All reads only; the working tree was not modified.
- **Direct verifications performed:** no-show route absence (grep), palette sign-out wiring (read), `composer scan:tenancy` full output (run twice), audit redaction keys vs temp-password payload (read), CSV writer injection path (read), notification template enumeration (backend enqueue sites vs frontend copy map), counselling findings F1–F17/U1–U8 (targeted file:line re-verification), mobile release blockers (read), mobile polling lifecycle (read).
- **Not covered (limitations):** live runtime/visual testing of screens against a seeded dev stack (this audit is static + contract-level); database trigger behavior under concurrent load; the `AuditChainVerifier` crypto itself; Playwright/e2e suite health; `mobile/` store packaging beyond the known blockers.
