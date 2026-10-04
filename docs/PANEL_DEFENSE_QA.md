# SYNAPSE — Panel Defense Q&A Prep

Anticipated panel questions with suggested answers. Answers are written to be
spoken as-is (1–3 sentences) or expanded live. Click paths match the current
sidebar (`frontend/src/components/AppSidebar.tsx`); routes match
`frontend/src/router.tsx`.

> **Numbers move.** Before the defense, re-run the suites and update the cheat
> sheet below — quoting a stale test count to a panel is worse than saying
> "around three hundred."

---

## Cheat sheet (memorize)

| Fact | Value |
|---|---|
| Stack | CodeIgniter 4.7 / PHP 8.3 REST API · React 18 + Vite SPA · Flutter mobile · MariaDB/MySQL |
| Auth | JWT (HS256): 15-min access token in memory + 30-day rotating refresh cookie (`synapse_rt`, HttpOnly/Secure/SameSite=Strict) |
| Roles | **13** (2026-09 RBAC rework — not 12; see note in A3) |
| Modules | Clinic · Counselling (Guidance) · Facilities (BMG) · Referrals · Reports, over a `Shared/` kernel |
| Encryption | Session notes AES-256-GCM (`COUNSELLING_KEY`, versioned for rotation); referral QRs HMAC-signed (`REFERRAL_HMAC_KEY`) |
| Audit | Append-only, SHA-256 hash chain, verifiable end-to-end (API or `synapse:audit-verify`) |
| Login lockout | 5 failures → 15-minute lock (`LOGIN_LOCKOUT_MAX_FAILURES=5`, window 900 s) |
| Rate limits | 600 req/min global, 30 req/min on auth routes |
| MIS | `mis.foundationu.com` — campus-WiFi-only, called strictly server-side |
| Day boundaries | Manila-day helper everywhere — never raw UTC dates |
| Tests | ~291 backend unit · ~243 feature (MariaDB) · ~201 frontend · ~40 mobile · Playwright e2e suite; 3 parallel CI jobs |
| Demo accounts | `admin@synapse.dev`, `nurse@synapse.dev`, `counsellor@synapse.dev`, `bmg_admin@synapse.dev`, `student@synapse.dev` — password `DevPassw0rd!` (dev only) |

---

## Section A — Easy "Where can I find…?" questions

These are the free points. Answer with the click path, then *show it* if the
panel has a screen.

### A1. "Where can I find the MIS API configuration?"

> "The MIS configuration is deliberately **not in the web UI** — it's
> server-side only, because it holds an API key that must never reach a
> browser or the mobile app. It lives in two places in the backend: the
> `.env` file, which has the `FUMIS_` keys — `FUMIS_ENABLED`,
> `FUMIS_BASE_URL`, `FUMIS_API_KEY`, timeout and token-cache settings — and
> `backend/app/Config/FuMis.php`, which loads them and adds safe defaults.
> I can open them here."

**Demo move:** open `backend/.env.example` lines 61–79 in the editor and the
`FuMis.php` config class. Key facts to say if probed:

- `FUMIS_ENABLED=false` (dev) → local email/password logins work off-campus;
  `true` (production) → student/employee-number logins are delegated to the
  MIS API with just-in-time account provisioning.
- The MIS host (`mis.foundationu.com/sandbox`) is **campus-WiFi-only**, and
  Synapse calls it strictly from the server — never from a client.
- Bulk record sync runs as the spark command `php spark synapse:mis-sync`
  (`--kind all|student|employee`), plus configurable auto-sync
  (`FUMIS_AUTO_SYNC_*`).

### A2. "Where can I find where users and their roles are managed?"

> "Under **Administration → Users** — route `/admin/users`. I search for the
> person, click **Edit roles**, tick the roles, and save. The role
> definitions themselves are under **Administration → Roles** at
> `/admin/roles`. Only holders of the `rbac.manage` permission see the Users
> item in the sidebar."

### A3. "How many roles does the system have?"

> "Thirteen, after our September RBAC rework." Then list them grouped:
> - **Platform:** superadmin (Platform Owner — the *only* wildcard holder,
>   minted via the `synapse:promote-superadmin` command, never through the UI)
> - **Clinic:** Clinic Administrator, Clinic Staff
> - **Guidance:** Guidance Administrator, Guidance Supervisor (oversight /
>   break-glass), Guidance Counsellor
> - **BMG/Facilities:** BMG Administrator, BMG Operator, and the BMG Device —
>   a machine identity for the automated tumbler, never used for interactive
>   login
> - **Cross-cutting read-only:** Audit Reader, Report Viewer
> - **Self-service:** Student, Employee
>
> ⚠ **Watch out:** the recorded walkthrough says "twelve roles" — that was
> before the rework. Say **thirteen**, and be ready for a panelist who counted
> along with the video.

### A4. "Where can I find patient records?"

> "Two entries share one registry: **Clinic → Records** at `/patients` with
> **Students** and **Employees** tabs, and **Guidance Center → Records**
> which opens the same page in guidance mode. Which entry you see depends on
> your role."

### A5. "Where do I record a walk-in visit — vitals, care given, outcome?"

> "**Clinic → Encounters** at `/clinic`. I open or create the encounter,
> enter the vitals — blood pressure, temperature — then the care administered
> and the outcome: discharged, observed, or referred. The tab also holds the
> live **Queue**, closed encounters, and **Staff schedules**."

### A6. "Where is the clinic queue, and how does a patient get into it?"

> "**Clinic → Encounters → Queue**. Confirmed appointments are promoted into
> the live queue fifteen minutes before their slot; staff then press
> **Call next** to serve patients in order. There's a parallel queue for
> guidance under **Guidance Center → Counselling → Queue**."

### A7. "Where can I find the appointments?"

> "**Clinic → Appointments** at `/appointments`, with Upcoming, Needs action,
> Past, and All tabs. Students book these either from their portal or the
> mobile app; every confirmed booking carries a QR code for check-in."

### A8. "Where is the inventory / medicines?"

> "**Clinic → Inventory** at `/inventory` — tabs for Medicines, Supplies,
> Equipment, Purchases, and Insights for stock analytics. Purchases is the
> reorder workflow."

### A9. "Where are the surveys, including the year-level filter?"

> "**Guidance Center → Surveys** at `/counselling/surveys`. When building a
> survey you can target it by **year level**, so a survey meant for
> first-year students only appears to them — on the web portal and in the
> mobile app. That year-level targeting is the newest feature in this build;
> there's a migration `SurveyYearLevels` behind it."

### A10. "Where are announcements and guidance services?"

> "**Guidance Center → Announcements** and **Guidance Center → Services**.
> Students read both from their portal's Guidance tab and in the mobile app."

### A11. "Where does a counsellor set their availability?"

> "**Guidance Center → Counselling → Scheduling** tab — a weekly availability
> grid. Students then book those slots from the portal or the app."

### A12. "Where are the counselling session notes?"

> "Inside **Guidance Center → Counselling** — the counsellor opens the
> session and writes there. Worth saying proactively: notes are **encrypted
> with AES-256-GCM before they ever reach the database**, with key rotation
> supported, so even a database dump doesn't expose them. Access is
> own-session only for counsellors; the supervisor holds an audited
> break-glass `read_any`."

### A13. "Where do I create a referral and where is its QR code?"

> "**Clinic → Clinic Referrals** or **Guidance Center → Guidance Referrals**
> — same page, role-dependent entry. Click **New referral**, fill the patient
> and reason, save — the **QR code appears on the referral detail**. Scanning
> it hits a public verify endpoint that returns **status only, never personal
> details**; the QR carries an HMAC-signed token."

### A14. "Where is the composting / BMG module?"

> "Under **Facilities**: the batch list at `/facilities`, **Waste Category**
> for the categories, and **Devices** for the IoT drum. Each batch moves
> through a fixed state machine — idle, processing, awaiting output, curing —
> and staff log temperature, moisture, and weights."

### A15. "Where do I register the ESP32 composting device?"

> "**Facilities → Devices**, as a BMG Administrator: register the device by
> its chip MAC address, bind it to a drum, and copy the token from the
> shown-once dialog. The device then reports every turning session over
> WiFi, and those land in the device log you see on that page."

### A16. "Where do I run and export reports?"

> "**Administration → Reports** at `/reports` — tabs per module: Clinic,
> Counselling, Inventory, Referrals, Facilities. You build a report, save the
> configuration, and click **Run / Export CSV**. Exports are
> worker-generated with provenance metadata and field redaction, and the
> files are **auto-deleted after thirty days**."

### A17. "Where is the audit log?"

> "**Administration → Audit** at `/audit`. Every mutation across every module
> is listed, and **Verify chain** re-checks the SHA-256 hash chain
> end-to-end — the badge proves nothing was edited or deleted. There's also a
> CLI: `php spark synapse:audit-verify`."

### A18. "Where do students see their own stuff?"

> "The **My portal** sidebar item at `/me`, with Overview, Appointments,
> History, Guidance, and Notifications tabs. Pure students land there
> automatically after login instead of the staff dashboard. The mobile app
> mirrors it — including taking surveys and getting the appointment QR code."

### A19. "Where do users change their password?"

> "At `/change-password` — the **Change password** page."

### A20. "Where are notifications?"

> "Three places: the bell in the web topbar and `/notifications`, the
> Notifications tab inside My portal, and a notifications screen in the
> mobile app. Appointment reminders are delivered by a background worker —
> `synapse:notify-drain` — not by request threads."

### A21. "Where do students log in? What credentials do they use?"

> "In production they sign in with their **university ID through the MIS
> API** — Synapse delegates authentication to the university's system and
> provisions the account on first login. For development we have seeded demo
> accounts like `student@synapse.dev`, and the MIS toggle
> (`FUMIS_ENABLED`) switches between the two paths."

---

## Section B — "Where is that in the code?"

### B1. "Where are the roles and permissions defined?"

> "`backend/app/Config/AuthGroups.php` defines the 13 groups; the
> fine-grained permission matrix lives in the database `permissions` table,
> seeded by `PermissionsAndGroupsSeeder`. Controllers never hardcode role
> names — they check permission codes like `clinic.encounters.write` through
> the `authorize()` helper. The frontend mirrors this: every route is wrapped
> in `ProtectedRoute` with the permission codes it needs, and the sidebar
> filters items by permission."

### B2. "Where are the JWT settings?"

> "`backend/.env` — `JWT_SECRET`, `JWT_ALG` (HS256), `JWT_ACCESS_TTL_SECONDS`
> = 900, `JWT_REFRESH_TTL_SECONDS` = 30 days, plus the refresh-cookie
> attributes. The service itself is `backend/app/Auth/` — `JwtService`,
> refresh rotation, throttling, account state."

### B3. "Where are the encryption keys?"

> "Also in `backend/.env`: `COUNSELLING_KEY` with `COUNSELLING_KEY_VERSION`
> for rotation, `REFERRAL_HMAC_KEY` for the referral QR tokens, and
> `JWT_SECRET`. All are generated with `openssl rand -hex 32` and never
> committed — the repo ships `.env.example` with placeholders only."

### B4. "Where are the rate limits and lockout configured?"

> "`backend/.env`: `RATELIMIT_GLOBAL_PER_MIN` = 600, `RATELIMIT_AUTH_PER_MIN`
> = 30, and the lockout pair `LOGIN_LOCKOUT_MAX_FAILURES` = 5 /
> `LOGIN_LOCKOUT_WINDOW_SECONDS` = 900."

### B5. "Where do the background workers live?"

> "`backend/app/Commands/` — twelve spark workers run through the scheduler,
> never inside request threads: `synapse:notify-drain`,
> `synapse:audit-drain`, `synapse:reports-drain`,
> `synapse:appointments-enqueue-due` (queue promotion), `synapse:mis-sync`,
> `synapse:bmg-device-watchdog`, and the audit maintenance commands among
> them."

### B6. "How is the codebase organized?"

> "The backend is five domain modules — Clinic, Counselling, Facilities,
> Referrals, Reports — each with its own Controllers, Services, DTOs, and
> Policies, over a shared kernel in `Modules/Shared`. Modules keep hard
> boundaries against each other; ninety-six migrations define the schema.
> The web and mobile are independent clients over the one REST API."

---

## Section C — Architecture & design

### C1. "Walk me through the overall architecture."

> "Three independent tiers over one REST API. The backend is a stateless
> CodeIgniter 4 API — the single source of truth — backed by MariaDB. The
> React SPA and the Flutter app are both just clients of that API with the
> same JWT flow, which is why an appointment booked on the phone appears on
> the staff web queue with no separate mobile backend and no drift between
> platforms."

### C2. "Why CodeIgniter and not Laravel? Why React? Why Flutter?"

> "CodeIgniter 4 gave us a lightweight, explicitly-structured PHP framework
> with the module separation we wanted and a small footprint on modest
> campus hardware. React with strict TypeScript and Zod-validated responses
> keeps the staff UI type-safe end to end. Flutter gives us one codebase for
> Android and iOS with native-feeling UI, sharing the same design language as
> the web."

### C3. "Why MariaDB/MySQL?"

> "It's the standard relational choice for this class of data — strongly
> consistent records, transactions, and database-level constraints, which we
> lean on: the BMG mass invariant is enforced by a database trigger, not just
> application code. It's also what the university environment already
> operates comfortably."

### C4. "How does authorization actually work? Show me."

> "Role-based access control, DB-driven. Signing in returns the user's
> permission codes; every API controller calls `authorize()` with the code
> it requires, every frontend route is wrapped in `ProtectedRoute` checking
> the same codes, and the sidebar renders only what you're permitted to see.
> Granting or revoking the privileged roles is itself restricted — only the
> Platform Owner can, and no user can revoke their own last privileged role."

### C5. "You have both clinic and counselling data. How do you keep them separate?"

> "By design, they never query each other — no cross-module SQL joins, ever.
> **Referrals are the single deliberate bridge**: a clinic case that needs a
> counsellor becomes a referral record with its own lifecycle — create,
> acknowledge, review, close — and the verification QR carries an HMAC token
> whose public endpoint returns status only. So a breach or a bug in one
> module doesn't silently read the other."

### C6. "How do you handle the campus operating hours / dates?"

> "The clinic operates in Manila. All day-boundary logic — queue
> partitioning, check-in windows, daily reports — goes through a shared
> Manila-day helper rather than raw UTC dates. That class of bug has bitten
> queue partitioning before, so we also treat any new date bucketing as
> suspect in review."

### C7. "Is the data multi-tenant? How do you guarantee a tenant can't see another's data?"

> "Yes — every tenant-scoped query carries its scope in the query itself,
> enforced in the service layer. We keep that guarantee mechanically, not
> just by discipline: a static scan (`composer scan:tenancy`) plus a
> tenancy-ratchet test that fails the build if any query loses its tenant
> scoping."

### C8. "How do web and mobile stay in sync?"

> "There's nothing to sync — one database, one API, two clients. The mobile
> app calls the same endpoints with the same token flow, so what staff see on
> the web and what students see on the phone is the same data by
> construction."

### C9. "What happens in the background — how do notifications get sent?"

> "Asynchronous work never runs inside a request thread. Notifications,
> audit writes, report exports, and queue promotion are drained by spark
> console commands on a schedule — `synapse:notify-drain`,
> `synapse:audit-drain`, `synapse:reports-drain`,
> `synapse:appointments-enqueue-due`, plus a watchdog for the BMG devices."

---

## Section D — Security & privacy

### D1. "How do you protect sensitive records like counselling notes?"

> "Three layers. Transport: HTTPS with a strict CORS allowlist. At rest:
> session notes are encrypted with AES-256-GCM — tag-verified, with a
> versioned key so rotation is possible — before they reach the database.
> Access: counsellors read only their own sessions; wider access exists only
> as an audited supervisor break-glass permission."

### D2. "Walk me through a login. What exactly is issued?"

> "Credentials are checked, and on success we issue a **short-lived access
> token — fifteen minutes — held in memory** on the client, paired with a
> **rotating refresh token in an HttpOnly, Secure, SameSite=Strict cookie**
> that detects replay. Five failed attempts lock the account for fifteen
> minutes, and the auth routes are additionally rate-limited."

### D3. "How does the system comply with the Data Privacy Act (RA 10173)?"

> "By data minimization and auditability. The referral QR's public verify
> endpoint is minimum-disclosure by design — status only, no personal
> information. Report exports carry provenance and redact sensitive fields,
> and are retained only thirty days. Every mutation lands in an append-only,
> hash-chained audit log, and the repo carries data-sharing terms for the
> external MIS integration prepared against RA 10173."

### D4. "How do you prove records weren't tampered with?"

> "The audit log is append-only and each entry is chained by SHA-256 hashes
> — each hash covers the previous entry. One click on **Verify chain** — or
> the `synapse:audit-verify` CLI — recomputes the chain end to end; any
> edit or deletion breaks it visibly."

### D5. "Why is the MIS API key not in the mobile app or the frontend?"

> "Because anything shipped to a client can be extracted — the key would be
> public. The MIS integration is called strictly server-side: the browser
> and the app talk only to our API, and our API holds the key and calls MIS.
> The MIS host is also campus-WiFi-only, which contains the blast radius
> further."

### D6. "What if a token is stolen?"

> "The access token expires in fifteen minutes and lives in memory, not
> storage. The refresh token is HttpOnly so script theft can't read it, it
> rotates on every use — a reused old token is a replay signal — and all
> privileged actions are attributable through the audit chain."

---

## Section E — Testing & quality

### E1. "How did you test the system?"

> "At four levels. Backend: unit tests plus HTTP feature tests that boot the
> real kernel against a live MariaDB — around 290 and 240 respectively, over
> six thousand assertions. Frontend: Vitest unit tests. Mobile: Flutter
> tests. End to end: a Playwright suite against the real UI. Three parallel
> CI jobs — backend, frontend, mobile — run on every push, and the branch
> doesn't merge unless all three are green."

### E2. "Show me the tests pass."

> **Demo move:** run `composer test` in `backend/`, `npm test` in
> `frontend/`, `flutter test` in `mobile/` — or open the GitHub Actions page
> with the three green jobs. Re-run the suites **before** the defense so the
> on-screen counts match what you say.

### E3. "Any automated guarantees beyond the tests?"

> "Two worth naming. The tenancy ratchet: a test that fails the build if any
> query loses its tenant scoping. And the audit hash chain, which
> re-verifies end to end in one click — integrity is checkable, not claimed.
> We also ran black-box testing per actor — students, employees, clinic and
> guidance staff, facilities operators, administrators — documented in
> `Documentation/BlackBoxTesting.md`."

### E4. "How do you prevent regressions like the queue bug you mentioned?"

> "Exactly through those layers — the Manila-day helper is now the only
> sanctioned way to draw a day boundary, feature tests cover queue
> partitioning across midnight, and code review treats any new date
> bucketing as suspect."

---

## Section F — Hard / curveball questions

### F1. "Why build this when university systems or off-the-shelf clinic software exist?"

> "Off-the-shelf clinic software doesn't cover the guidance office, the
> composting facility, and university governance in one auditable system —
> and it can't integrate with our MIS for identity. Unifying them is the
> point: one login, one audit chain, one referral bridge, one report
> builder — instead of paper and disconnected tools."

### F2. "What is actually new or your own contribution here?"

> Have your team's split ready (who built which module). Sound system-level
> claims that hold up: the **hash-chained audit log with one-click
> verification**, the **minimum-disclosure referral QR**, the
> **module-boundary discipline** (no cross-module joins; referrals as the
> only bridge), the **tenancy ratchet test**, and the **AES-256-GCM
> encrypted, key-rotatable session notes**.

### F3. "Does the mobile app work offline?"

> "The app is online-first — it needs the API for booking, surveys, and
> queues, which are inherently live operations. Offline support is a
> documented future-work item: caching announcements and pre-filling survey
> drafts are the natural first steps."

### F4. "What happens if the MIS API is down?"

> "The backend fails fast — the MIS call budget is ten seconds because login
> latency is user-facing — and the failure surfaces as a clean, user-safe
> error, never a stack trace. Administrators keep local credentials as a
> fallback path, and record sync is batch with a cooldown, so a MIS outage
> delays syncs but doesn't take Synapse down. Note the MIS host is
> campus-only anyway, so this is an on-campus failure mode we planned for."

### F5. "How does it scale?"

> "The API is stateless, so it scales horizontally behind a load balancer
> with nothing but the database shared; sessions live in tokens, not server
> memory. Heavy work — report generation, notifications — already runs in
> background workers rather than request threads. For a single university's
> population, the load is modest; the constraint we actually engineered
> around was correctness and auditability, not volume."

### F6. "How is it deployed and backed up?"

> "Say what's true for your build: the backend needs PHP 8.3 and MariaDB on
> the campus network (required for MIS), the web is a static Vite build,
> mobile ships as an APK/store build. Backups are at the MariaDB layer; the
> encrypted-note keys must be backed up with the database or the notes are
> unrecoverable — that's a deliberate consequence of the encryption design."
> *(If the panel pushes on a DR runbook, say it's documented as future work
> with the operations runbook in `docs/`.)*

### F7. "What are the limitations?"

> Answer honestly — panels trust a team that knows its limits. Candidates
> grounded in this build: online-first mobile (no offline mode); MIS
> reachable only from campus WiFi; notifications are pull/poll from the
> clients rather than push sockets; report exports retained only 30 days by
> design; no native iOS distribution story beyond Flutter's capability.

### F8. "What would you do next / future work?"

> "Push notifications on mobile, offline caching for read-only content, a
> disaster-recovery runbook with scheduled backup verification, and deeper
> MIS sync coverage — employee namespaces and scheduled auto-sync in
> production."

### F9. "Someone edits a row directly in the database. Does anything break?"

> "Possibly not immediately — that's exactly what the audit hash chain is
> for. The chain covers the audit log's integrity: recompute it and any
> inconsistency shows. Application-level invariants like the BMG mass
> invariant are additionally enforced by a database trigger, so even
> direct SQL can't record output weight exceeding input weight."

### F10. "Why does the student need staff to do mutations? Students can't even write anything?"

> "Deliberate. Students hold only read and self-service permissions — they
> book their own appointments and take surveys through dedicated
> `/me/student-*` endpoints scoped to their own linked record. Every
> clinical or guidance *record* mutation is performed by staff, because
> health records need an accountable author — that's also what makes the
> audit chain meaningful."

### F11. "What happens if two staff call the same patient / book the same slot?"

> "Slot capacity and queue state transitions are enforced server-side in the
> service layer — the client can't double-book through the API because the
> check happens atomically where the state lives, and both clients are thin
> over the same API. *(If pressed for the exact locking strategy, don't
> improvise — say you can pull up the booking service code.)*"

### F12. "Show me an error the user would actually see."

> **Demo move (from the walkthrough, Scene 4):** wrong password → stable
> user-safe error; five times → the lockout screen. BMG form: output weight
> > input weight → precise rejection at three layers while the user just
> sees the message. Missing required field on booking/survey → inline error
> before the request is even sent. Server errors are redacted by the API
> envelope — no stack traces ever reach a client.

---

## If you get stumped

1. **Narrate, don't guess.** "Let me pull that up in the code" is stronger
   than a wrong answer — every answer above has a file to point at.
2. The two numbers you *will* be asked: **13 roles** and the **test counts**
   (re-verify before the defense).
3. The phrase that answers half the architecture questions: *"one database,
   one API, two clients."*
4. The phrase that answers half the security questions: *"encrypted where it
   matters, audited everywhere, minimum disclosure by design."*
