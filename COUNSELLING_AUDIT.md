# Counselling / Guidance page audit — 2026-09-03

Scope audited end-to-end (page → hooks → routes → controllers → services → policy → schema):

| Layer | Files |
| ----- | ----- |
| Frontend | `frontend/src/pages/CounsellingPage.tsx` (1415 L), `hooks/useCounselling.ts`, `hooks/useSchedule.ts`, `hooks/useQueue.ts` (guidance part), `schemas/counselling.ts`, `schemas/schedule.ts`, `components/CounsellingPatientPicker.tsx` |
| Backend | `backend/app/Modules/Counselling/**` (Routes, 3 controllers, 3 services, policy, 2 DTOs — 1 288 L), `Services/Analytics/SchedulingAnalytics.php` |
| Schema | `CounsellingSessions`, `CounsellingKeyVersions`, `CounsellingSchedule`, `CounsellingSchedulingAnalytics`, `DestinationQueues`, `TenantsAndTenantId`, `TenantIdSchemaGap` |
| Gates | `composer test` (220 pass), `composer scan:tenancy` (1 unscoped, in Referrals) |

The page has 4 tabs: **Queue** (FIFO guidance queue), **Sessions & Notes** (encrypted notes workspace),
**Scheduling** (availability + appointments), **Analytics** (no-show optimizer).

---

## P0 — Security / correctness

### 1. The UI tells users notes are end-to-end encrypted. They are not.

`CounsellingPage.tsx:1390` — *"Session notes are encrypted end to end."*
`CounsellingPage.tsx:220` — label: *"Notes (encrypted before they reach the server)"*
Button: *"Encrypt & save"* with a `ShieldCheck` icon.

Plaintext is POSTed over the wire and encrypted **server-side** in
`CounsellingService::writeNotes` → `EncryptionService::encryptField`. The page's own docblock
(line 3) correctly says *"encrypted server-side"*, directly contradicting the UI strings it renders.

This is the most privacy-critical surface in the product, and the claim is materially false: it
promises the server cannot read the notes, when the server holds the key (`COUNSELLING_KEY`) and
decrypts on every read. A counsellor may disclose more in a note than they would if the trust
boundary were stated honestly. Fix the copy to "encrypted at rest (AES-256-GCM)".

### 2. Record-level ownership on notes is a no-op for every counsellor

`CounsellingPolicy::canOnRecord` returns `true` as soon as the caller holds
`counselling.records.write`:

```php
if ($this->can('counselling.records.write')) { return true; }
$counsellor = ... ; return (int) $counsellor === $userId;
```

`AuthGroups.php:102-109` grants `counselling.records.write` to **every** member of the `counsellor`
group. So the `counsellor_user_id` fallback branch is unreachable in practice: any counsellor can
`GET /counselling/sessions/{id}/notes` and decrypt any other counsellor's session notes for any
patient. The policy docblock states this is intentional ("A user with `counselling.records.write`
may act on any session"), but the *effect* — a flat, non-compartmentalised mental-health record —
is a significant privacy decision that the RBAC design appears to present as record-scoped.

**Decision (2026-09-05) — narrow the routine grant, keep an audited oversight path.**
Peer access stays *possible* but leaves the counsellor role. Full compartmentalisation would be
worse than the status quo, because there is **no reassignment endpoint anywhere** in
`Counselling/Routes.php`: restricting `readNotes` to `counsellor_user_id` alone strands every
session whose owner is on leave, and the office will route around that by sharing logins.

1. **`canOnRecord` branches on `$action`.** The parameter is already threaded through
   `BasePolicy::enforce()` (`BasePolicy.php:66`) and currently ignored. `readNotes` / `writeNotes` /
   `close` resolve to own-session (`counsellor_user_id === $userId`); the new `read_any` satisfies
   the record gate for oversight. `counselling.records.write` stops being a universal bypass.
2. **Add `counselling.records.read_any`** to `PermissionsAndGroupsSeeder` (alongside L60-68) and
   grant it to `clinical_supervisor` (`AuthGroups.php:163-174`) plus `admin` via the wildcard.
   `counsellor` (L102-123) keeps `records.read`/`write`/`create` and is thereby own-session only.
   This restores `clinical_supervisor` to the deliberate, audited break-glass purpose its own
   comment already claims (`AuthGroups.php:32-36`) — today every counsellor has identical reach,
   so that role is decorative.
3. **Add an audited reassignment** — `POST sessions/(:num)/reassign`, gated on
   `counselling.schedule.team_manage` (already held by `clinical_supervisor`, L171) or the new
   `records.read_any`. It writes `counsellor_user_id` inside `txn()` + `selectForUpdate` following
   `closeSession` (`CounsellingService.php:298-333`) and enqueues `counselling.session_reassigned`.
   Coverage becomes a recorded operational act instead of a standing privacy exception.
4. **Fix the docblock** (`CounsellingPolicy.php:17-19`) to state the real rule — it currently
   documents a branch no existing group can reach.

Detection is already built: `CounsellingService.php:283` enqueues `counselling.notes_read` on every
decrypt, so once the grant narrows, that stream becomes meaningful "who read whose notes" signal
instead of uniform noise.

**Test first** — nothing currently covers any authorization boundary between two counsellors (see
Coverage assessment): counsellor A cannot read B's notes (403 `rbac.record.forbidden`), a
supervisor can, the supervisor's read is audited, and reassignment transfers ownership.

**Ops:** `AuthGroups.php` + seeder changes need the permissions seeder re-run on existing
environments — this is not a pure code deploy.

### 3. Reading a session auto-decrypts and auto-audits, with no intent

`SessionsTab` calls `useNotes(selectedId ?? 0)` unconditionally, and `useNotes` is enabled for any
`sessionId > 0`. Merely *clicking a row* in the sessions table (or landing on a `?session=` deep
link) decrypts the full note history and writes a `counselling.notes_read` audit event
(`CounsellingService::readNotes`). Consequences:

- The audit chain's most sensitive signal becomes noise — it records "browsed the list", not
  "deliberately read this patient's notes", destroying its forensic value.
- The event is enqueued even when the session has **zero** notes, so the trail logs reads that
  never happened.
- React Query refetches (list poll is 30 s) can multiply events for one human action.

Gate decryption behind an explicit "Reveal notes" action and audit that.

### 4. Guidance queue + booking raw SQL is not tenant-scoped, and the scanner cannot see it

`composer scan:tenancy` reports **1** unscoped site (in Referrals) and the fitness test passes —
but both only match `->table('X')`. Every `$this->db->query(...)` is invisible to them.
Counselling has 13 raw queries, and these carry **no `tenant_id` predicate**:

| File | Line | Query |
| ---- | ---- | ----- |
| `QueueService.php` | 160 | `callNext` FIFO `SELECT ... FOR UPDATE` |
| `QueueService.php` | 267 | `completeLinkedSession` queue lookup |
| `QueueService.php` | 375 | duplicate-active-entry guard in `enqueue` |
| `QueueService.php` | 387 | `MAX(position)` allocation |
| `QueueService.php` | 544 | `averageServiceMinutes` |
| `ScheduleService.php` | 220 | availability-window check on booking |
| `ScheduleService.php` | 233 | overlap-capacity check on booking |
| `ScheduleService.php` | 324/330 | `UPDATE users SET consecutive_no_shows` |
| `ScheduleService.php` | 385 | analytics recompute aggregate |

Latent today (single tenant, `CurrentTenant::id()` always 1), but this is exactly the class of
regression the tenancy gate exists to catch, and the gate is **structurally blind** to it. The
green scan is not evidence of tenant safety for this module. Worse, position allocation
(`L387`) and the duplicate guard (`L375`) would collide across tenants on day one of a second
tenant, and the unique key is `(tenant_id, queue_date, position)` — an insert would hard-fail.

### 5. `counselling_scheduling_analytics` is tenant-blind despite having the column

**Corrected 2026-09-03 (implementation pass).** The original finding claimed the column was
missing. It is not: `TenantsAndTenantId` lists the table in `DOMAIN_TABLES` (L58) and the analytics
migration (`2026-01-24`) predates it (`2026-08-02`), so `tableExists()` is true and the column
**is** added — `INT UNSIGNED NOT NULL DEFAULT 1` after `id`. The real defects are three:

1. **The unique key has no tenant component.** `CounsellingSchedulingAnalytics.php:37` declares
   `addUniqueKey(['counsellor_user_id', 'day_of_week', 'time_slot'])`. A second tenant's recompute
   for the same counsellor/slot collides with tenant 1's row and **overwrites** it.
2. **The tenant index was never created.** `TenantsAndTenantId:112` calls `addKey(...)` *after*
   `createTable()`, which is a documented no-op in this codebase (`TenantIdSchemaGap:58-60`
   explains the same trap and works around it with a raw `ALTER TABLE ... ADD INDEX`). So
   `idx_..._tenant` almost certainly does not exist.
3. **The service never filters on it.** `ScheduleService` reads and upserts with no tenant
   predicate (L411 select, L418-419 update, L398-409 insert data, L443 `listAnalytics`), so the
   column silently rides its `DEFAULT 1`.

Net effect is what the original finding described — cross-tenant statistic corruption — but the fix
is a unique-key migration plus service predicates, not an `addColumn`.

---

## P1 — Broken or missing process

### 6. The "three-strike no-show policy" is advertised but never enforced

The page states it three times (`:9`, `:1139` *"counts toward the patient's three-strike counter"*,
`:1391` *"repeated no-shows follow the three-strike policy"*). The only code touching the counter is
`ScheduleService::transition`:

```php
// no_show:  consecutive_no_shows = consecutive_no_shows + 1
// complete: consecutive_no_shows = 0
```

A repo-wide grep for `consecutive_no_shows` finds **no read that gates anything** — every other hit
is a `USER_COLS` list, a DTO passthrough, a migration, or a seeder default. `ScheduleService::book`
never checks it. There is no threshold constant, no 3-strike block, no warning banner, no override
path. The counter increments forever and changes nothing.

So the policy is a fiction: a patient with 12 consecutive no-shows books exactly as freely as a
new one. Either implement the block in `book()` (with a documented override permission) or remove
the claim from the UI — the current state misleads staff into believing enforcement exists.

### 7. `GET /counselling/queue` performs writes

`QueueService::today()` calls `enqueueDueAppointments()` on every read. A GET request thus inserts
queue rows, and the frontend polls it every 10 seconds (`useQueue.ts:45`). Problems:

- Violates GET safety; any cache, prefetch, or retry mutates state.
- Duplicates the `synapse:appointments-enqueue-due` spark command, contradicting AGENTS.md
  ("Async work runs through spark commands, not request threads").
- Each poll opens a transaction per due appointment with `SELECT ... FOR UPDATE`, and swallows
  every failure into a `log_message('warning', ...)` — a persistently failing enqueue is invisible
  to operators and silently retried every 10 s per open browser tab.
- With N counsellors watching the tab, N concurrent enqueue attempts contend on the same rows.

### 8. Assigned-lane queue entries can be permanently stranded

`callNext` only sees entries where `assigned_counsellor_user_id` is the caller **or NULL**
(`L160-166`). Kiosk check-ins and due appointments can carry a specific counsellor
(`enqueueFromKiosk`, `enqueueDueAppointments` passes `counsellor_user_id`). If that counsellor is
absent, their queued patients are invisible to every other counsellor, and the routes expose
**no reassignment endpoint** — only `call-next`, `transition`, `repair-session`. The patient waits
in the lobby forever with no staff-visible remedy short of a DB edit.

Similarly `transition` refuses any action when `assigned_counsellor_user_id` differs from the
caller (`rbac.record.forbidden`), so a colleague cannot even skip the stale entry. And a `skipped`
entry has no un-skip / re-queue path.

### 9. Appointments list silently truncates at 50 and cannot show "today"

`useSchedule.ts` `useAppointments` sends `limit=50`, parses `res.data` as an array, and **discards
the `next` cursor**. The backend is keyset-paginated and `ScheduleController::listAppointments`
honours any `limit`. The Scheduling tab therefore:

- shows at most 50 appointments with **no pagination UI and no truncation warning** (contrast the
  Sessions tab, which has Prev/Next);
- orders by `created_at DESC`, not `appointment_date` — so a counsellor cannot see their upcoming
  day in order, and a just-booked appointment for next month outranks tomorrow's;
- offers no date range or "today" filter at all.

For a scheduling surface this is the core workflow, and it is unusable past ~50 rows.

### 10. Bookings accept past dates and unregistered patients

`ScheduleController::book` validates `appointment_date` with `valid_date[Y-m-d]` only.
`ScheduleService::book` checks weekday-window fit and overlap capacity but never compares the date
to today. A counsellor can book 2019-01-01. Likewise `openSession` / `book` resolve the patient via
`PatientLookupService::findByIdentifier` and, when it returns nothing, store
`patient_user_id = NULL` with the raw typed string:

```php
'patient_user_id' => $patient !== null ? (int) $patient['id'] : null,
'patient_school_id' => (string) $input['patient_school_id'],
```

So a typo creates an orphan session/appointment attached to no real person. It will never notify
(the outbox loop filters falsy ids), never enqueue (`enqueueDueAppointments` skips
`patient_user_id <= 0`), and never appear in that patient's history — a silent black hole. The
picker makes this less likely but the field remains free-text.

### 11. Analytics are computed over all history, including cancellations, with a fabricated metric

`recomputeAnalytics` aggregates `counselling_appointments` with **no date window** — a counsellor's
no-show rate is diluted by years of history and can never recover from an old bad patch. `COUNT(*)`
includes `cancelled` appointments in the denominator, so cancellations mathematically suppress the
no-show rate the screen exists to surface.

`SchedulingAnalytics::avgUtilization` returns a hardcoded `0.85` whenever `total > 0`:

```php
return $total > 0 ? 0.85 : 0.0;
```

The column is stored, typed, schema-validated, and labelled — but is a constant carrying zero
information. It is not rendered on the page today, which is the only reason nobody has been misled
yet. Remove it or compute it.

Recompute is also unbounded and synchronous in the request thread (no cursor, whole-table
`GROUP BY`), contrary to the drain-command convention.

---

## P2 — Convention violations & UX gaps

### 12. Filter state is not in the URL (AGENTS.md violation)

AGENTS.md: *"Filter state belongs in the URL. Use the shared URL-filter hook rather than local
`useState`."* `frontend/src/hooks/useUrlFilter.ts` exists. The page uses it for `tab` and `session`
(hand-rolled, not via the hook) but holds all of these in local `useState`:

- `statusFilter` — the appointments status filter (`SchedulingTab`)
- `availabilityView` — list vs calendar
- the Availability/Appointments sub-tab (`Tabs defaultValue="availability"`)
- `sort` — analytics sort key and direction
- `cursor` / `history` — sessions pagination

None survive a reload or a shared link. A supervisor cannot send "the no-show appointments view".

### 13. `businessToday()` re-implements the mandated Manila-day helper

AGENTS.md: *"Day-boundary logic must use the Manila-day helper, never a raw UTC date."*
`App\Modules\Shared\ManilaDay` exists precisely for this and its docblock names the 2026-08
regression. Counselling's `QueueService::businessToday()` (L556) and `enqueueDueAppointments()`
(L52) instead construct `new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'))` inline.
Functionally equivalent today, but it is a second source of truth for the exact partition that has
already regressed once — and it is the reason a `ManilaDay` grep does not list this module.

### 14. Multi-byte notes are rejected with a misleading error

Controller: `'plaintext' => 'required|string|max_length[16384]'` (character count).
Service: `if (strlen($plaintext) > 16384)` → 422 *"Note exceeds 16 KiB."* (byte count).
A 16 000-character note in Tagalog/Cebuano with accents, or containing emoji, passes field
validation and then fails the byte check. The frontend zod cap is also `.max(16384)` characters, so
the client believes it is valid. The counsellor loses a long note to an error that says the note is
too long when the visible length is under the stated limit. Cap consistently in bytes and surface a
live byte counter.

### 15. No retention or correction path for the most sensitive data in the system

`counselling.records.soft_delete` is defined in `PermissionsAndGroupsSeeder` (L63) and granted to
nobody, and **no endpoint anywhere sets `archived_at`** on `counselling_sessions` — every read
filters `archived_at = null`, but nothing can ever write it. There is likewise no note edit or
delete route. Consequences: a note written on the wrong patient is permanent and undeletable
through the product; there is no data-retention or erasure path for mental-health records. For a
university health platform this is a live compliance exposure (RA 10173 / Data Privacy Act).

**Decision (2026-09-05) — build the archive path, keep notes append-only, defer only the period.**
The mechanism is ~90% present, which makes this a retrofit of an established convention rather than
a design question: `counselling_sessions.archived_at` already exists (`ClinicalForeignKeys.php:43`,
`has_archived_at => true`) and all seven read paths already filter on it
(`CounsellingService.php:40, 71, 85, 123, 199, 248, 299`). Only the write side is missing. Four
other modules already ship the route pair to copy (`Clinic/Routes.php:97,110,118,133`,
`Reports/Routes.php:24-25`, `Facilities/Routes.php:18-19,33-34`) and `PatientService.php:266` has
the exact update shape.

1. **Session archive/unarchive** — `POST sessions/(:num)/archive` + `/unarchive` following the
   in-repo convention, `'archived_at' => $archived ? $now : null`, inside `txn()`, audited.
2. **Grant `counselling.records.soft_delete`** — defined but granted to nobody
   (`PermissionsAndGroupsSeeder:63`) — to `clinical_supervisor` and `admin`, **not** `counsellor`.
   The common real case is a note written on the wrong patient, and that is exactly the case that
   should surface to a supervisor rather than be quietly cleaned up by the person who made the
   error.
3. **No note edit or delete.** `counselling_notes` is insert-only by construction: the migration
   comment says "nothing else writes here" (`CounsellingSessions.php:38-39`), the table has no
   `archived_at`, and `readNotes` already returns the full history `ORDER BY id DESC`
   (`CounsellingService.php:261`). Corrections happen by **amendment** — a new note carrying a
   `supersedes_note_id` reference, with both rendered in the workspace. This matches the audit
   chain's own append-only philosophy; mutating or deleting would destroy the only evidence that an
   error occurred.
4. **Retention period is not a code decision.** RA 10173 requires a *stated* period, and that
   number belongs to the university records officer — mental-health records typically carry longer
   statutory minimums than ordinary ones, so guessing in either direction is a real exposure. Build
   `synapse:counselling-purge` now (spark, `SYNAPSE` group, following `AuditClear.php`'s shape
   including its `--yes` / `array_key_exists` flag caveat), read the period from config, and
   **refuse to run while unset**, in the manner of `Boot::assertSecurityPosture()`. The compliance
   answer then becomes "the mechanism exists and is waiting on your number" instead of today's
   "there is no path at all."

### 16. Public lobby feed exposes guidance patients' full names and school IDs

`api/v1/clinic/queue/state` is **deliberately unauthenticated** ("lobby TV / kiosk poll it") and
returns `guidance` alongside `clinic` via `CounsellingQueueService::publicState()`.
`publicRow` calls `displayName($row, true)` — the `$full = true` branch — yielding
`"Last, First"` plus `patient_school_id`:

```php
'display_name' => $this->displayName($row, true),
'patient_school_id' => (string) $row['patient_school_id'],
```

So an unauthenticated caller learns the full name and student number of everyone waiting for
**counselling**, and can poll it to build a roster over time. Clinic has the same shape, but
attendance at a *guidance/mental-health* queue is materially more sensitive than a clinic visit —
this is the disclosure the queue-number abstraction (`G-003`) exists to prevent. The queue number
is already in the payload; the name and school ID should be dropped from the guidance branch (or
initials only).

### 17. Smaller items

- **Analytics shows `#42`, not a name.** `AnalyticsTab` renders `#{s.counsellor_user_id}` while
  `useCounsellors()` is already available in the same file — supervisors must map ids by hand.
- **`called_at` rendered raw.** `GuidanceQueueTab:1330` prints `{entry.called_at ?? '—'}` — a raw
  UTC SQL string, while every other timestamp on the page goes through `fmtUtcToApp`. Staff see
  a time 8 hours behind Manila.
- **Removing an availability window orphans its bookings.** The confirm text admits it
  ("Existing bookings in this window are not automatically cancelled"), but nothing lists or flags
  the now-unbacked appointments, and `removeSlot` doesn't count them. They stay bookable-looking in
  the Appointments tab and will fail nothing — the window check only runs at booking time.
- **Notes are never re-read after write.** `useWriteNotes` invalidates the notes key correctly, but
  the DTO returns the plaintext the client just sent — a decryption round-trip is never verified,
  so a key-rotation misconfiguration surfaces only on a later read.
- **`counsellors()` filters on group name `'counsellor'`** with an inner join to
  `auth_groups_users`; a clinical supervisor who manages schedules is not in that group and cannot
  be selected as a bookable counsellor, even with `schedule.team_manage`.
- **No `session` deep-link for the queue tab.** `setTab` deletes `session` on every tab change, so
  the Queue → Session handoff (`onOpenSession`) works only because `selectSession` also clears
  `tab`; navigating back to Queue silently drops the active workspace.

---

## Coverage assessment

| Gate | Result | Covers counselling? |
| ---- | ------ | ------------------- |
| `composer test` | 220 pass | Partially — `GuidanceSessionWorkflowContractTest` (5 tests) and `SchedulingAnalyticsTest` are **source-grep / pure-math** contract tests, not behavioural |
| `composer scan:tenancy` | 1 unscoped (Referrals) | **No** — blind to all 13 raw `db->query` sites (finding 4) |
| `TenantScopeFitnessTest` | pass | **No** — same `->table('X')` regex limitation |
| Playwright `guidance-session-workflow.spec.ts` | 3 tests | Start-session id handoff, deep-link refresh, duplicate referral only |

**Nothing tests**: the queue state machine transitions, `callNext` assigned-lane contention, the
no-show counter, booking window/capacity enforcement, note encryption round-trip, key rotation,
or any authorization boundary between two counsellors. The green suites are not evidence for any
finding above.

---

## Recommended order

1. **Finding 1** (false E2E-encryption claim) — copy change, minutes, removes a live misrepresentation.
2. **Finding 6** (fictional three-strike policy) — either enforce in `book()` or delete the claim.
3. **Finding 16** (public guidance names) — drop name/school ID from the guidance public branch.
4. **Finding 2** (flat note access) — decided: action-aware `canOnRecord` + `records.read_any` for
   oversight + audited reassign. Write the two-counsellor authorization test **first**; it is the
   missing safety net for everything else in this list.
5. **Finding 3** (auto-decrypt + audit noise) — gate behind explicit reveal. Land with or straight
   after Finding 2; both change `readNotes`, and 2 is what makes the `notes_read` audit stream
   meaningful.
6. **Finding 15** (no retention/correction path) — decided: session archive + supervisor-only
   `soft_delete` + amendment-by-new-note + `synapse:counselling-purge` gated on an unset period.
   Must follow Finding 2, whose compartmentalisation decision determines who may archive.
7. **Finding 7** (write-on-GET) — move to `synapse:appointments-enqueue-due` only.
8. **Findings 4, 5** (tenancy) — extend the scanner to `db->query` first, *then* fix the sites, and add `tenant_id` to the analytics table.
9. **Findings 8, 9, 10** (stranded lanes, truncated list, past-date bookings) — operational correctness.
10. **Findings 12, 13** (AGENTS.md conventions) — URL filters and `ManilaDay`.

Findings 2 and 15 were open policy questions; both were **decided 2026-09-05** and the plans are
recorded inline above. The one item still owned outside engineering is Finding 15's retention
*period* — the purge command ships refusing to run until the records officer supplies it.

---

## Live UI audit — 2026-09-03 (counsellor session)

Walked all four tabs in a browser as `liza.santos@foundationu.edu.ph` (counsellor) against the
running dev stack, including both theme modes. This pass confirms or refines the static findings
and adds UI-specific ones (U1–U8).

### What renders

| Tab | Live state observed |
| --- | ------------------- |
| **Queue** | Empty — *"No Guidance check-ins today."* Polls every 10 s (finding 7). |
| **Sessions & Notes** | 2 open sessions (#1, #2). Session workspace shows progress tracker, manual-session panel, and a zero-note history; **opening a session immediately fetches notes** (finding 3 confirmed in network trace). |
| **Scheduling → Availability** | 10 windows seeded (Mon–Fri 09:00–12:00 + 13:00–16:00, cap 2). List and calendar views both render; Add-window dialog works and is focus-trapping. |
| **Scheduling → Appointments** | Exactly 1 row: "Student, Sarah" (S-2021-0001), counsellor Marla Vicenta Aquino, **Sep 5, 2025 10:00 — status still `booked`**. |
| **Analytics** | Renders empty (no rows) despite 2 seeded analytics rows — see U4. |

### Confirmed in the live UI

- **Finding 1 (false E2E claim) — visible verbatim.** Page header reads *"Session notes are
  encrypted end to end"*; the Write Note dialog labels the field *"Notes (encrypted before they
  reach the server)"* with an *"Encrypt & save"* button. Encryption is server-side; the claim is
  on screen.
- **Finding 3 (auto-decrypt).** Clicking session #2 fired the notes fetch and (per backend) a
  `counselling.notes_read` audit event even though the session has **zero** notes — the audit
  trail records a read that never happened.
- **Finding 9 (appointments list).** The single live row predates today by a year and still shows
  `booked`; there is no date filter, no "today" view, and the order is `created_at DESC` — the
  tab cannot answer "what is my day?".
- **Finding 17 (`called_at` raw).** Queue tab renders `called_at` as a raw UTC string; every other
  timestamp on the page is localized to Manila.

### New UI findings

#### U1 — Appointments `booked` status is a zombie: nothing ever transitions it

The only appointment in the system is dated **2025-09-05** and still reads `booked` on
**2026-09-03**. Live proof of finding 6: the `no_show` transition is never fired by any process
(the counter only moves inside `ScheduleService::transition`, which nothing calls on a schedule),
so past appointments never age into `no_show` or `complete`. The Appointments tab accumulates
stale `booked` rows forever, and the advertised three-strike policy has no input signal to act
on even if it were wired.

#### U2 — Counsellor is not in their own appointment patient flow (picker asymmetry)

The Appointments row names the counsellor (Marla Vicenta Aquino) and the patient as a free-text
"Student, Sarah", but the Scheduling tab offers no way to filter by counsellor, and the
`counsellors()` source filters on group name `counsellor` via an inner join (finding 17) — a
supervisor managing schedules cannot be selected, and the displayed counsellor may not be
bookable from this surface.

#### U3 — Queue empty state gives no operational guidance

With an empty queue the tab shows only *"No Guidance check-ins today."* There is no hint that
due appointments auto-enqueue (finding 7), no link to the Appointments tab to book one, and no
indication of whether the day simply has no check-ins or the enqueue path failed silently (the
backend swallows enqueue errors into a `warning` log). A counsellor cannot distinguish
"quiet day" from "enqueue broken".

#### U4 — Analytics tab renders empty despite seeded rows

`CounsellingSeeder` inserts 2 analytics rows, but the Analytics tab shows nothing in this
session. Either the recompute ran with no tenant predicate against the wrong scope, or the read
is filtering them out. Given finding 5 (the table has **no `tenant_id`**) and finding 11
(recompute is synchronous, unbounded, and includes cancellations), the tab is at best stale and
at worst cross-contaminated — and it fails silently rather than surfacing *"no data yet"* vs
*"compute failed"*.

#### U5 — Dark mode renders, but the theme toggle is fragile under modal focus

The page renders correctly in dark mode (Sessions tab verified end-to-end). However the theme
toggle lives inside the user menu, and while any focus-trapping dialog (e.g. *Add availability
window*) is open, the menu button is unreachable and the toggle cannot be activated — keyboard
or pointer. Modals correctly trap focus, but there is no in-dialog theme control, so a user on
a dark-mode desktop with a light-mode app (or vice versa) is stuck for the duration of the
dialog. Minor, but it is the only theme entry point on the page.

#### U6 — Modal dialogs have no visible data-loss guard on the notes field

The Write Note dialog holds the most sensitive free-text on the page. Closing it (Esc, overlay
click, or the × button) discards an in-progress note with **no `data-confirm` guard** — unlike
the logout confirm already standard elsewhere in the app (see v2 feedback layer). A single
mis-click after drafting a long confidential note loses it silently.

#### U7 — Availability/Appointments is a nested tab set with no URL state

Scheduling contains a second, inner tab row (Availability | Appointments) held in `useState`,
on top of finding 12. Reloading from the Appointments view drops the user back to Availability,
so a counsellor cannot bookmark or share the appointments list — the one view that needs to be
linkable for a daily stand-up.

#### U8 — Session workspace: zero-note state is indistinguishable from load failure

Opening session #2 (which has zero notes) renders an empty history with no *"No notes yet"*
empty state and no skeleton/error differentiation. Combined with finding 3 (the fetch is silent
and automatic), a counsellor cannot tell whether the patient has no notes or the notes failed
to load/decrypt — a dangerous ambiguity for a clinical record.

### UI audit — coverage notes

- Both themes exercised; dark mode renders the page fully (U5 aside).
- All four tabs and both Scheduling sub-tabs walked; Add-availability and Write-Note dialogs
  opened and inspected.
- Not exercised (needs writable actions against live dev data): actually saving a note (would
  create a real confidential record), booking an appointment, `call-next` on a live queue, and
  removing an availability window. Findings U1–U8 are from read-only observation plus the
  static pass.
