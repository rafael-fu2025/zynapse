# Guidance Module Parity+ — Integration Plan

How Synapse replaces and improves the four tabs of the university's Greyhound
Kiosk Guidance module (`kiosk.foundationu.com/college/guidance`): Announcements,
Services, Survey Links, Interviews.

Audited 2026-09-12 from the live kiosk (student session):

| Tab | What it is today |
|---|---|
| Announcements | Static HTML list grouped by cohort ("New and Transferee", "All College Students"); items link OUT to Google Forms (`F-OGC-SNA-COL-003-00` Student Needs Assessment, `F-OGC-GEV-EGS-013-00` Evaluation of Guidance Services) or third-party tests (EducationPlanner learning style, Literacynet multiple intelligence). Caption: "requirements for clearance signing" — completion is tracked by humans, not the system. |
| Services | Static bullet list of the 9 guidance service categories. No actions. |
| Survey Links | Duplicate of the Announcements list. |
| Interviews | Two per-student free-text questionnaires with persistence: Routine Interview (7 questions, continuing students) and Exit Interview (8 questions, graduating students). No triage, no lifecycle, no analytics. |

Unifying gap: content is hand-maintained HTML, interactions leak to Google
Forms, and both the compliance driver (clearance signing) and the care driver
(flagged concerns) are handled outside the system.

## Regulatory backbone

- **RA 11036 (Mental Health Act) §24** — HEIs must run policies and programs
  for the prevention, treatment, and **aftercare** of students at risk, and
  maintain a complement of mental-health professionals.
  <https://lawphil.net/statutes/repacts/ra2018/ra_11036_2018.html>
- **CHED CMO 9 s.2013** — Enhanced Policies and Guidelines on Student Affairs
  and Services: the source catalogue for guidance-office service categories
  (the kiosk's 9 services map to it) and guidance-office standards.
  <https://legacy.ched.gov.ph/wp-content/uploads/2017/10/CMO-No.09-s2013.pdf>
- **CHED CMO 8 s.2021** — flexible SAS delivery; HEIs must provide mental
  health services for student psycho-social well-being.

Positioning: Synapse's guidance module is the **RA 11036 §24 compliance
vehicle** — the triage/aftercare closed loop (Phase C) and the CMO-structured
services catalogue (Phase A) are compliance artifacts.

## Phase A — Staff-owned content: Announcements + Services

- `guidance_announcements` (tenant-scoped): title, body, audience
  (`all|new_students|continuing_students|graduating_students`), action_url +
  action_label optional, is_required, publish_at/unpublish_at windows,
  archived_at soft delete. CRUD for `guidance_admin` (new permission
  `counselling.announcements.manage`). Scheduled windows replace the kiosk's
  hand-written timing notes; cohort targeting uses real data (new = student
  account created in the current academic year, AY starts Aug 1 per the
  existing ReportRange AY constants; continuing = earlier).
- `guidance_services`: seeded from the CMO 9 s.2013 categories, each with
  `queue_destination nullable` — bookable services deep-link into the existing
  counselling appointment/queue flows. Doubles as CMO compliance
  documentation. Permission: `counselling.services.manage`.
- Publishing a required announcement fans out notifications to the target
  cohort via the existing notify outbox.
- The kiosk's separate "Survey Links" tab dies: announcements carry optional
  action links; surveys become first-class in Phase B.

## Phase B — Surveys engine (canonical hybrid schema)

Canonical model per survey-system practice (Redgate walkthrough, LimeSurvey's
survey/questions/tokens structure, versioning threads):

- `surveys` → `survey_versions` (**immutable, copy-on-publish**) →
  `survey_questions` (+ `answer_options`; question_type
  single/multi/likert/rating/free_text/external_url; `conditional_on` reserved
  for v2 skip logic) → `survey_responses` (one per student submission,
  version_id referenced, answers_snapshot JSON for integrity) →
  `survey_answers` (long table, one row per question-response pair —
  aggregation feeds the reports module).
- Form builder for `guidance_admin`; audience + Manila-day open/close windows.
- `GET /me/requirements` returns pending required items — the clearance-signing
  gate becomes server-enforced; response-rate dashboards per cohort.
- The two Google Forms become the first native templates; EducationPlanner /
  Literacynet stay as `external_url` items with completion self-attestation
  (do not rebuild validated psychometric tests).
- Ethics surface: purpose/visibility statement per form; skippable blocks
  marked; permissions `counselling.surveys.manage`,
  `counselling.responses.read` + `counselling.responses.read_any`.

## Phase C — Interviews with a validated triage loop

- Routine/Exit Interviews = surveys of category `interview`; seeded templates
  mirror the current 7 + 8 questions, versioned; per-student draft pre-fill
  (kiosk behavior preserved), resumable + audited.
- **Routine Interview gains a WHO-5 Well-Being Index block** — 5
  positively-worded items, 0–100 score, free to use, validated for identifying
  at-risk college students (WHO 2024: <https://www.who.int/publications/m/item/WHO-UCN-MSD-MHE-2024.01>;
  college validation: <https://eric.ed.gov/?id=EJ1127367>).
- **Triage = closed loop, not keywords**: WHO-5 raw score ≤ configurable
  threshold (default 50) OR self-flagged concern → `guidance_followups` row
  (student, source response, risk_reason, assigned counsellor, SLA due_at,
  status new → in_review → addressed → closed). Closed-loop rule: nothing
  resolves without an outcome note. Outreach templates are supportive by
  default — early-alert practice warns proactive outreach must stay
  non-punitive and autonomy-first:
  <https://studentexperienceproject.org/seven-ways-to-ensure-your-early-alerts-are-helpful-not-harmful/>,
  <https://pmc.ncbi.nlm.nih.gov/articles/PMC12022146/>,
  <https://pmc.ncbi.nlm.nih.gov/articles/PMC9307132/>.
- Ethics guardrails: the WHO-5 block is skippable ("prefer not to answer");
  followups visible only to `counselling.responses.read_any` holders;
  answers encrypted at rest via `EncryptionService` (same pattern as
  counselling notes); every access audited; retention documented.
- Exit Interview: structured + free-text mix; term-over-term aggregate report;
  a "findings shared" workflow step closes the loop with stakeholders
  (<https://decisionwise.com/resources/articles/developing-effective-exit-surveys/>).

## Cross-cutting

Tenant-scoped tables; audit via outbox → hash chain
(`guidance.announcement_published`, `guidance.response_submitted`,
`guidance.followup_created|status_changed`, `guidance.response_accessed`);
reports integration for aggregates; student portal Guidance section
(announcements feed, requirements checklist, surveys/interviews) plus a
post-check-in kiosk feed; mobile out of scope initially;
`docs/DATA-SHARING.md` gains a guidance-interviews/screening section.

## Survey-engine design references

- <https://www.red-gate.com/blog/database-design-survey-system/>
- <https://stackoverflow.com/questions/1764469/sql-design-for-survey-with-answers-of-different-data-types>
- <https://dba.stackexchange.com/questions/323092/choosing-between-entity-attribute-value-or-jsonb-representations>
- <https://www.limesurvey.org/manual/Survey_participants>

## Status

- Phase A: **implemented** (this branch) — see migration
  `2026-09-12-000040_GuidanceContent`.
- Phase B: **implemented** (this branch) — see migration
  `2026-09-12-000050_Surveys`. The builder's v1 keeps one immutable
  version per survey; retiring = archive, new campaign = new survey.
  The canonical answer snapshot is stored encrypted (AES-256-GCM)
  alongside the aggregation projection.
- Phase C: **implemented** (this branch) — see migration
  `2026-09-12-000060_GuidanceFollowups`. The seeded Routine Interview
  template ships as a DRAFT per tenant: the guidance administrator
  reviews and publishes it. WHO-5 items are optional (skippable) even
  though the interview itself can be a clearance requirement. Triage
  threshold: WHO-5 percentage ≤ 50 (constant in
  GuidanceFollowupService). Aggregate reporting exposes counts and
  averages only — verbatims stay behind the responses permission and
  audit. Deferred to a future pass: per-term interview re-runs with
  answer pre-fill (one submission per survey; re-run = new survey),
  supervisory assignment UI beyond self-assignment via "Review".
