# Synapse — 6-Minute Video Walkthrough Script (with Actions)

Simple-words voice over. Speak at a calm, steady pace (~120 words per minute).
Each scene has two parts: the **Actions** table (what you do on screen, with
timestamps) and the **Voice-over** text to read during it. Total voice-over is
~670 words ≈ 5:40 spoken, leaving ~20s of slack for clicks and transitions.

Demo accounts (dev only): `admin@synapse.dev`, `nurse@synapse.dev`,
`counsellor@synapse.dev`, `bmg_admin@synapse.dev`, `student@synapse.dev` —
password `DevPassw0rd!`. (Optionally mention students log in with their
university ID via MIS.)

---

## Scene 1 — Intro (0:00–0:25)

**Actions**

| Time | On screen |
|---|---|
| 0:00 | Title card: "SYNAPSE — University Health & Guidance Platform" (hold 5s) |
| 0:06 | Quick 3-shot flash: web dashboard → student portal → mobile home screen (1–2s each) |
| 0:14 | Fade to the web **Login page**, cursor resting in the Email field |

**Voice-over**

> Hello! This is Synapse. One system for our university's health and guidance
> services. It has three parts: a web system for staff, a student portal, and
> a mobile app. Today we will walk through the main features — starting with
> logging in.

---

## Scene 2 — Login as admin (0:25–0:55)

**Actions**

| Time | On screen |
|---|---|
| 0:25 | Login page in focus; click the Email field |
| 0:27 | Type `admin@synapse.dev` (zoom to ~130% while typing) |
| 0:31 | Tab to Password, type `DevPassw0rd!` |
| 0:35 | Click **Sign in**; wait for the Dashboard to load |
| 0:40 | Let it settle; slowly sweep the cursor over today's appointments, the queue count, and the alert cards |
| 0:52 | Move toward the sidebar to set up the next scene |

**Voice-over**

> We start on the web. Sign in with your email and password. We are logging
> in as the platform admin. If someone types the wrong password too many
> times, the account is locked — this keeps the system safe. After login, we
> see the dashboard. It shows today's clinic numbers, appointments, and
> alerts, all in one place.

---

## Scene 3 — Roles and users (0:55–1:20)

**Actions**

| Time | On screen |
|---|---|
| 0:55 | Sidebar → **Administration → Users** |
| 1:00 | Type a name in the search box; the user row appears |
| 1:04 | Click **Edit roles**; let the role checkbox list fill the screen |
| 1:09 | Check one role, then click **Save roles** |
| 1:13 | Briefly show a different role's sidebar (e.g. nurse) to prove menus differ |
| 1:17 | Cut back to the admin session |

**Voice-over**

> Not everyone sees everything. Synapse has twelve roles — like nurse,
> counsellor, and admin. Here we can search for a user and change their
> role. The menu changes based on who you are. And every important action
> is saved in an audit log — we will show that later.

---

## Scene 4 — Clinic (1:20–2:00)

**Actions**

| Time | On screen |
|---|---|
| 1:20 | Sidebar → **Clinic → Patients**; show the registry list |
| 1:25 | Search and open one patient's record |
| 1:29 | Start a new encounter: type vitals (blood pressure, temperature) and save |
| 1:38 | Enter the care given, set the outcome to **Referred**, save |
| 1:45 | Go to **Appointments → Queue**; show the live queue list |
| 1:51 | Click **Call next** so the top patient visibly moves state |
| 1:55 | Quick cut to **Inventory**; hover a medicine row to show its stock count |

**Voice-over**

> Now the clinic. Staff keep the list of students and employees here. When a
> patient walks in, the nurse records the vitals — like blood pressure and
> temperature — then the care given, and the outcome. For example: sent home,
> or referred. The clinic runs on appointments. Booked patients move into a
> live queue, and staff call them in order. Medicines and supplies are
> tracked here too, so the clinic always knows what is in stock.

---

## Scene 5 — Counselling / Guidance (2:00–2:45)

**Actions**

| Time | On screen |
|---|---|
| 2:00 | Sidebar → **Counselling** |
| 2:04 | Show the counsellor's **availability** grid (weekly slots) |
| 2:10 | Open the **Guidance queue**; show the waiting list |
| 2:14 | Open one **session note**; pause on it (mention encryption) |
| 2:22 | Go to the **Surveys** tab; open a survey and point at the **Year level** limit (NEW feature — linger here) |
| 2:32 | Quick pass through the **Announcements** tab, then the **Services** tab |

**Voice-over**

> Next is counselling — the guidance office. Counsellors set their available
> hours, and students book a slot. The guidance queue works just like the
> clinic queue. During a session, the counsellor writes private notes. These
> notes are encrypted — even the database cannot read them. Students can also
> answer surveys. Here is a new feature: surveys can now be limited by year
> level, so a survey meant for first-year students only appears to them.
> Announcements and guidance services are managed on this page too.

---

## Scene 6 — Referrals (2:45–3:05)

**Actions**

| Time | On screen |
|---|---|
| 2:45 | Sidebar → **Referrals** |
| 2:48 | Click **New referral**, fill in the patient and reason, save |
| 2:53 | The QR code appears on the referral detail — zoom on it for 2s |
| 2:57 | Open the public verify view (or scan preview): show status only, no personal details |

**Voice-over**

> When a clinic case needs a counsellor, staff make a referral. The system
> creates a QR code. Anyone can scan it to check that it is real — it shows
> only the status, never personal details.

---

## Scene 7 — Facilities / BMG composting (3:05–3:45)

**Actions**

| Time | On screen |
|---|---|
| 3:05 | Sidebar → **Facilities**; show the batch list |
| 3:08 | Trace the batch steps across the screen: idle → processing → awaiting output → curing |
| 3:14 | Open one batch; enter a temperature/moisture reading and save |
| 3:22 | (Optional) show the weight validation — try output > input and let the error appear, then correct it |
| 3:28 | Open the **Drum detail** page |
| 3:31 | Go to **Devices**; show the ESP32 device row and its last turning session timestamp |

**Voice-over**

> Facilities handles the composting area, called B-M-G. Each batch goes
> through steps: idle, processing, waiting for output, and curing. Staff
> record the temperature, moisture, and weight. The system checks that the
> output weight is never more than the input weight. Our compost drum is
> automated. It uses a small computer chip — an ESP32 — that reports every
> turning session over WiFi. You can see the device records right here.

---

## Scene 8 — Reports and Audit (3:45–4:10)

**Actions**

| Time | On screen |
|---|---|
| 3:45 | Sidebar → **Reports**; open a saved configuration |
| 3:49 | Click **Run / Export CSV**; let the download appear |
| 3:55 | Sidebar → **Audit**; scroll the event list slowly |
| 3:58 | Click **Verify chain**; hold on the "valid / intact" result badge |

**Voice-over**

> Reports let staff build a report, save it, and export it as a CSV file.
> Files are auto-deleted after thirty days. On the Audit page, every action
> is listed. The log is a hash chain — press verify, and the system proves
> nothing was changed or deleted.

---

## Scene 9 — Student portal on web (4:10–4:45)

**Actions**

| Time | On screen |
|---|---|
| 4:10 | Click the avatar → **Log out** |
| 4:13 | Log in as `student@synapse.dev` |
| 4:18 | The **Student Portal** loads |
| 4:22 | **Book appointment:** pick Guidance or Clinic → choose a slot → confirm |
| 4:31 | Open the **Surveys** tab → open a survey → answer one or two questions → submit (the year-level filter is visible in the list) |
| 4:40 | End on the **Announcements** tab for 2–3s |

**Voice-over**

> Now the student side. A student signs in with their university account.
> From the portal, they book a clinic or guidance appointment in a few
> clicks. They can read announcements, see guidance services, and take
> surveys. Everything is in one simple page.

---

## Scene 10 — Mobile app (4:45–5:35)

**Actions**

| Time | On screen |
|---|---|
| 4:45 | Switch to the phone/emulator window |
| 4:47 | Login screen → enter the student account → **Home** with quick actions |
| 4:55 | **Book appointment** flow: pick type → slot → confirm |
| 5:02 | Open the appointment detail → the **QR code** fills the screen (hold 3s) |
| 5:08 | Open **Surveys** → take one → submit |
| 5:17 | Open **Notifications**; show the appointment reminder |
| 5:24 | Open a report → **Export PDF** → show it in the PDF viewer |

**Voice-over**

> The mobile app brings the same features to your phone. Students log in the
> same way. On the home screen, they see quick actions. They can book an
> appointment and get a QR code for check-in — just show it at the clinic.
> They can also take surveys right on the phone. This is new. Notifications
> arrive in the app, so students never miss a schedule. Employees can view
> reports and export them as a P-D-F.

---

## Scene 11 — Testing (5:35–5:50)

**Actions**

| Time | On screen |
|---|---|
| 5:35 | Show test output: terminal with `composer test`, `npm test`, `flutter test` passing — or the CI page |
| 5:40 | Scroll the CI checks: backend / frontend / mobile jobs, all green (hold 5s) |

**Voice-over**

> Finally, testing. The project runs hundreds of automated tests — for the
> backend, the web, and the mobile app. Every time we push code, continuous
> integration runs all of them.

---

## Scene 12 — Closing (5:50–6:00)

**Actions**

| Time | On screen |
|---|---|
| 5:50 | Back to the logo / title card (optionally add the team names) |
| 5:56 | Fade out |

**Voice-over**

> That is Synapse — clinic, counselling, referrals, facilities, reports, and
> governance, in one safe and traceable system. Thank you for watching.

---

## Recording checklist

- [ ] Seed fresh demo data before recording (appointments today, a batch in
      processing, one referral, one survey per year level).
- [ ] Pre-log into each account in separate browser profiles so switching is
      one click; keep the mobile emulator window sized and ready.
- [ ] Record at 1080p; zoom (120–140%) on forms when typing credentials.
- [ ] Do a dry run following the action timestamps with a timer; trim dead
      air between scenes.
- [ ] If an action runs long, let the VO for the *next* beat start rather
      than pausing — silence is what kills the 6-minute limit.
- [ ] Don't show real student data — use the dev dataset only.
- [ ] On-screen text cards (optional): scene titles like "Clinic",
      "Counselling", "Mobile App" help the panel follow along.
