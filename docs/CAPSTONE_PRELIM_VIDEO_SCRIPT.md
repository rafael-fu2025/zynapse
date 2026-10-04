# SYNAPSE — Capstone 2 Prelim Video Script (9:00, Web + Mobile)

Formal, tech-oriented voice-over. Speak at ~130 words per minute; total
narration is ~1,150 words ≈ 8:50 spoken, leaving slack for clicks and scene
transitions inside a 9:00 cut (brief allows 5:00–10:00).

**Fill these placeholders before recording:** `[TEAM MEMBERS]`,
`[ADVISER]`, `[REPO LINK]`, `[CONTACT]`.

Grading weights drive the pacing: Functionality 60% (Scene 3 gets 4.5 of the
9 minutes), UI/UX 30% (Scene 2), Testing & Debugging 10% (Scene 4, ≥60 s of
live demos as required).

Demo accounts (dev only, see `CREDENTIALS.md`): `admin@synapse.dev`,
`nurse@synapse.dev`, `counsellor@synapse.dev`, `bmg_admin@synapse.dev`,
`student@synapse.dev` — password `DevPassw0rd!`.

---

## Scene 1 — Introduction & System Overview (0:00–1:00)

**On screen**

| Time | Visual |
|---|---|
| 0:00 | Title card: "SYNAPSE — University Health & Guidance Platform · Capstone 2 Prelim" + team members + adviser (hold ~8 s) |
| 0:08 | Split screen: Web admin dashboard (left) · Mobile app home (right) |
| 0:22 | Over the split screen, one action lands on each side: an appointment appears on the web queue moments after being booked on the phone |
| 0:40 | Crossfade toward the web **Login** page to set up Scene 3; hold on logo |

**Voice-over**

> Good day. This is the Capstone 2 Prelim walkthrough for SYNAPSE, the
> University Health and Guidance Platform, developed by [TEAM MEMBERS] under
> the supervision of [ADVISER]. Universities run several sensitive operations
> side by side — clinic visits, guidance counselling, referrals, facilities
> management, and record governance — yet these are often tracked on paper or
> across disconnected tools. SYNAPSE unifies all of them in one auditable
> platform. It serves three audiences: students and employees, through the
> mobile app and student portal; clinic and guidance staff, through the web
> system; and administrators, through governance, reporting, and analytics.
> Both platforms run on a single REST API, so every action taken on the web
> appears on mobile in real time — and the reverse.

---

## Scene 2 — UI/UX & Navigation Overview (1:00–2:30) · rubric 30%

**On screen**

| Time | Visual |
|---|---|
| 1:00 | Web dashboard, full sweep: sidebar, cards, tables — cursor traces the reading order |
| 1:14 | Click two sidebar items back-to-back to show two-click access to any module |
| 1:20 | Show the URL bar after applying table filters → refresh → the filtered view restores itself |
| 1:28 | Drag the browser window narrower to tablet width; layout reflows without loss |
| 1:38 | Tab through a form: visible focus ring on every control |
| 1:46 | Student portal: type + slot picker + confirm — the 3-tap booking journey |
| 1:56 | Cut to mobile: home screen, bottom nav, cards — same palette and typography |
| 2:08 | Side-by-side still: same announcement rendered on web and on mobile |
| 2:16 | Quick pass: Counselling, Reports, Audit — identical table/filter/button language |

**Voice-over**

> Before the feature walkthrough, a look at the interface. SYNAPSE follows a
> restrained, institutional design language: consistent typography, a calm
> color hierarchy, and status communicated through both color and text
> labels, never color alone. Navigation lives in a persistent sidebar, and
> every major task is reachable within two clicks. Views are URL-addressable:
> filters survive a refresh and can be shared as links. The layout is
> responsive — the same dashboard reflows cleanly to tablet width without
> losing content. Accessibility was treated as a requirement, not an
> afterthought: every control is keyboard-operable with a visible focus ring,
> screens carry labels for assistive technology, and motion respects the
> system reduced-motion setting. The student portal compresses the journey
> further — booking a session takes three deliberate taps. On mobile, the
> same design language appears as a native Flutter interface: a bottom
> navigation bar, uniform cards, identical palette and typography, so a user
> moving from web to phone already knows where everything is. Across every
> web page and every mobile screen, spacing, buttons, and status colors stay
> consistent — the evidence of a single, user-centered design system.

---

## Scene 3 — Core & Advanced Functionality (2:30–7:00) · rubric 60%

### 3A — Authentication & RBAC (2:30–2:55)

**On screen:** sign in as `admin@synapse.dev`; dashboard settles. Quick flash
of the nurse's sidebar (different menus).

> We begin with authentication and access control. Sign-in issues a
> short-lived access token held in memory, paired with a rotating refresh
> cookie that detects replay. Authorization is role-based: nurse,
> counsellor, facilities administrator, student — each role receives only
> the navigation it is permitted to use. The administrator dashboard
> aggregates today's appointments, queue counts, and system alerts.

### 3B — Clinic: registry, encounters, queue, inventory (2:55–3:55)

**On screen:** Patients list → search + open record → edit → save. New
encounter: vitals → care → outcome *Referred*. Appointments → Queue → **Call
next**. Inventory row with stock count.

> The clinic module is the core workflow. Staff maintain the registry of
> students and employees — creating, updating, and archiving records; data is
> never hard-deleted. A walk-in becomes an encounter: the nurse records the
> vitals, the care administered, and the outcome — discharged, observed, or
> referred. The clinic runs on appointments: confirmed bookings are promoted
> into the live queue fifteen minutes before their slot, where staff call
> patients in order. Finally, inventory — medicines and supplies with running
> stock levels, so consumption is always reconciled.

### 3C — Counselling / Guidance (3:55–4:35)

**On screen:** availability grid → guidance queue → one session note (hold) →
Surveys tab, zoom on the **year-level** limit (new in this build) →
announcements + services tabs.

> Guidance counselling runs in parallel. Counsellors publish their
> availability, and students book those slots. Session notes are confidential
> by design — encrypted with AES-256-GCM before they ever reach the database,
> with key rotation supported. The module ships its own queue and no-show
> analytics, and manages announcements, guidance services, and surveys —
> which can now be targeted by year level, so a survey meant for
> first-year students is visible only to them.

### 3D — Referrals: the controlled bridge (4:35–5:00)

**On screen:** New referral → save → QR artifact appears → public verify view
showing status only.

> When a clinic case needs a counsellor, staff issue a referral. The system
> generates a QR artifact carrying an HMAC-signed token. Anyone can verify it
> against the public endpoint — the response returns the status only, never
> personal information. Referrals form the single, deliberate bridge between
> clinic and counselling data; the two modules never query each other
> directly.

### 3E — Facilities / BMG composting + IoT (5:00–5:40)

**On screen:** batch list with state badges → open a batch → log a reading →
drum detail → Devices page: ESP32 row with last turning-session timestamp.

> Facilities manages the composting operations. Each batch moves through a
> fixed state machine: idle, processing, awaiting output, curing. Staff log
> temperature, moisture, and carbon-to-nitrogen blending. The system enforces
> a mass invariant — output weight can never exceed input weight — validated
> in the service layer and again by the database itself. The mechanical drum
> is driven by an ESP32 controller that reports every turning session over
> Wi-Fi; each session lands in the device log you see here, idempotent and
> attributable.

### 3F — Reports & governance (5:40–6:05)

**On screen:** Reports → saved configuration → run → CSV download. Audit page
scroll.

> Reporting ties the data together. Saved report configurations stream to CSV
> with provenance metadata, field redaction, and thirty-day retention. On the
> governance side, every mutation across every module lands in an append-only
> audit log whose entries are chained by SHA-256 hashes.

### 3G — Cross-platform integration (6:05–7:00)

**On screen:** phone: book guidance appointment → cut to web: it appears in
the queue. Web: publish survey with year-level filter → phone: take that
survey → submit. Notifications on both. End on web dashboard + phone side by
side.

> Now the integration that defines the platform. A student books a guidance
> appointment on the phone — and it appears in the staff queue on the web
> immediately. A survey published on the web with a year-level filter appears
> only for eligible students, who can take it right in the app. Appointment
> notifications reach both platforms. One database, one API, two clients —
> zero drift between what staff see and what students see.

---

## Scene 4 — Testing & Stability Demonstration (7:00–8:30) · rubric 10%

**On screen**

| Time | Visual |
|---|---|
| 7:00 | Login: wrong password → stable error message |
| 7:10 | Repeat wrong entries (accelerated) → `auth.login_locked` lockout screen |
| 7:20 | BMG batch form: output weight > input weight → rejected with a precise message; correct it → saves |
| 7:34 | Booking/survey form: submit with a missing required field → inline error; fill it → success |
| 7:46 | Invalid referral QR token → public verify returns status-only, no details |
| 7:54 | Audit page → **Verify chain** → "intact" badge (hold 4 s) |
| 8:02 | Terminal: `composer test`, `npm test`, `flutter test` passing |
| 8:14 | GitHub Actions: three parallel jobs green (hold) |

**Voice-over**

> Functionality must be proven, not claimed. First, live input validation.
> Submitting the login form with an invalid password returns a stable,
> user-safe error; five failures lock the account for fifteen minutes. In the
> BMG form, entering an output weight larger than the input weight is
> rejected at three layers — the validation rule, the service assertion, and
> the database trigger — while the user simply sees a precise message, and
> the record is untouched. Booking and survey forms reject missing required
> fields inline, before any request is sent. In every case the system
> recovers gracefully: no crash, no freeze, and no stack trace is ever
> exposed — fifth-hundred-class errors are redacted by the API envelope.
> Second, proof of a bug-free build. The project runs two hundred ninety-one
> backend unit tests and two hundred forty-three HTTP feature tests against a
> live MariaDB — over six thousand assertions in total — two hundred one
> frontend unit tests, forty mobile tests, and fourteen Playwright
> end-to-end specifications, executed automatically on every push by three
> parallel CI jobs. A tenancy
> ratchet test fails the build if any query loses its tenant scoping, and the
> audit hash chain re-verifies end to end in one click — the integrity badge
> you just saw.

---

## Scene 5 — Conclusion & Summary (8:30–9:00)

**On screen:** closing card — project title, one-line status, repository
`[REPO LINK]`, contact `[CONTACT]`, team + adviser. Fade out.

> In summary: SYNAPSE delivers the complete clinic, counselling, referral,
> facilities, reporting, and governance workflow on both web and mobile —
> backed by role-based access control, encryption at rest, a verifiable audit
> chain, and an automated test suite that runs on every commit. The current
> build is stable, feature-complete, and ready for the Prelim defense. Thank
> you to our adviser, [ADVISER], and to the panel. The repository and our
> contact details are on screen.

---

## Rubric self-check before upload

| Criterion | Requirement | Where it is satisfied |
|---|---|---|
| Functionality 60% | No missing core features, no dead links/buttons in frame | Scene 3 (4.5 min) covers auth, RBAC, clinic CRUD, queue, inventory, counselling + encryption, surveys, referrals/QR, BMG + IoT, reports, audit, cross-platform flow |
| UI/UX 30% | 1080p minimum, clear audio, no stutter, consistent design | Scene 2 (90 s of responsive/a11y/consistency proof) + 1080p capture rule below |
| Testing 10% | ≥60 s of stable performance + input handling | Scene 4 runs 7:00–8:30 (90 s live validation demos + CI proof) |

**Recording checklist**

- [ ] Seed fresh demo data before recording: appointments today, one batch in
      processing, one referral, one survey per year level.
- [ ] Pre-login each account in separate browser profiles; keep the emulator
      window sized and ready beside the browser.
- [ ] Record at 1080p or 1440p; zoom 120–140% when typing credentials; cap the
      cursor — no stray hovering in narration pauses.
- [ ] Dry-run with a timer against the per-scene timestamps; trim dead air
      between sub-beats rather than speeding the voice-over.
- [ ] Dev dataset only — never show real student data.
- [ ] Before the Scene 4 narration, re-run the three suites so the on-screen
      terminal matches the spoken counts (291 unit / 243 feature / 201 web /
      40 mobile, 14 e2e specs).
- [ ] Optional on-screen chapter cards ("Authentication", "Clinic",
      "Integration", "Testing") — they map visibly to the rubric.
- [ ] Audio: external mic, room treated or quiet; normalize to ≈ −16 LUFS;
      no keyboard bleed into narration.

**Administrative checklist (from the brief — confirm current dates)**

- [ ] Submission: on or before **September 22, 2026** per the brief — ⚠ this
      date has already passed relative to the current build date; confirm the
      actual Prelim deadline with the adviser before scheduling recording.
- [ ] Adviser endorsement secured before upload.
- [ ] Capstone 2 down payment settled (unpaid entries are not scheduled).
