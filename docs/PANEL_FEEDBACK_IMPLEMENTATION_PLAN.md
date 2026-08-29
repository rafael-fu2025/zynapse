# Panel Feedback Implementation Plan

**Source tracker:** `panel-feedback-phases.csv`  
**Meeting scope:** Clinic procurement, inventory, encounters, waste/BMG, queues, and reporting  
**Plan status:** Approved and executing  
**Implementation status:** Web/backend waves and the mobile parity slice are implemented locally; tracker rows remain governed by the acceptance and UAT rules in section 11.

## 1. Purpose

This document converts the panel-feedback progress tracker into an executable delivery plan. The CSV remains the source for phase status, ownership, and high-level acceptance tracking. This plan supplies the details the CSV intentionally does not carry:

- dependencies and delivery order;
- decisions that must be confirmed before coding;
- database, API, web, and mobile impact;
- security, tenancy, audit, and privacy constraints;
- migration, rollout, rollback, and compatibility strategy;
- unit, integration, browser, and mobile verification gates;
- completion evidence required before a tracker row can be marked done.

This document records the approved implementation sequence. It does not by itself authorize production deployment or replace the CSV's evidence requirements.

### 1.1 Mobile parity slice delivered

The Flutter client now follows the same operational contracts as the implemented web workflows:

- clinic and counselling sessions use one step-tracked workspace instead of several disconnected tabs;
- referrals can be created within either active session and enter the destination department's normal queue;
- clinic vitals reuse previous height/weight only when appropriate, while completion remains explicitly confirmed;
- kiosk administration uses shared backend settings, multi-media upload progress, gallery filtering, archive/restore, playlist ordering, original video duration, and looping;
- medicine and supply histories display signed transactions with running `Stock after` values;
- report presets expose daily, weekly, monthly, and current academic-year ranges (August onward).

This slice does not turn the public queue display or kiosk check-in station into native mobile screens; those remain browser-facing display surfaces. The waste sorting rules and extraction-day predictor also remain separate program phases.

## 2. Current-state baseline

### 2.1 Architecture affected by this work

- Backend: PHP 8.3 and CodeIgniter 4 modular monolith.
- Web: React 18, TypeScript, Vite, TanStack Query, Zod, and Zustand.
- Mobile: Flutter client consuming the same `/api/v1` contracts.
- Database: MySQL with forward migrations and soft-delete conventions.
- Security: JWT access tokens, refresh rotation, DB-driven RBAC, tenant scoping, rate limiting, append-only audit outbox, and notification outbox.

### 2.2 Relevant existing behavior

- Inventory movements and medicine transactions already persist `balance_after`.
- Reorder triggering already compares a fixed integer threshold, but the quantity is derived as approximately twice the threshold. There is no independently configured maximum/target stock.
- Supply and medicine threshold field names differ (`reorder_level` and `reorder_threshold`). Public API compatibility must be considered before normalizing them.
- Encounter treatment medicine selection already uses an autocomplete.
- Encounter completion currently lacks the requested confirmation.
- Clinic queueing exists; counselling/guidance has no separate operational queue.
- Reports expose daily trends and some clinic monthly data, but aggregation is not a consistent request-level contract.
- Waste categories already support reference duration and expected yield metadata.
- The BMG analytics fallback is being changed from 45 to 21 days in uncommitted work.
- Academic-year constants have been added to `ReportRange` in uncommitted work, but no completed preset method or end-to-end UI/API behavior exists.

### 2.3 Baseline conditions to resolve before feature work

1. Preserve and review the existing uncommitted changes in:
   - `backend/app/Modules/Reports/Services/ReportRange.php`
   - `backend/app/Services/Analytics/BmgAnalytics.php`
   - `backend/tests/unit/BmgAnalyticsTest.php`
2. Repair encoding corruption in the current BMG comments before those changes are merged.
3. Correct the stale BMG unit-test comment that still says 45 days while asserting 21 days.
4. Establish a green baseline or document an explicit temporary waiver for the existing appointment transition failure (`checked_in -> no_show`). Panel work must not hide unrelated red tests.
5. Fix the ESLint configuration so `.cjs` files do not receive typed TypeScript rules; otherwise lint cannot serve as a delivery gate.
6. Re-run Flutter checks sequentially in an environment where the SDK does not stall. A timeout is not completion evidence.

## 3. Non-negotiable engineering constraints

All phases must preserve the repository's existing invariants:

- no SQL join between clinic-owned and counselling-owned clinical tables;
- no physical deletion of operational or clinical data;
- all sensitive mutations must be authorized by permission, not hard-coded role name;
- all tenant-owned reads and writes must include the active tenant;
- clinical notes, tokens, QR secrets, and patient payloads must not enter logs or audit context;
- mutation plus audit/notification outbox writes must be atomic;
- public queue displays and verification endpoints must remain minimum-disclosure;
- access-token storage rules must not be weakened;
- list endpoints must retain bounded/keyset pagination where applicable;
- migrations need a safe `down()` path unless a documented irreversible data transformation is approved;
- web and mobile contract changes must be synchronized through DTO/schema updates.

## 4. Decisions required before implementation

These decisions are gates, not optional refinements. Record the approved answers in this document and the affected CSV row before coding.

### D1. Procurement and budget scope — required for P1.1

Confirm whether the panel requested only a defense explanation of direct clinic-personnel ordering or also expects software enforcement of a monthly budget ceiling.

Recommended interpretation for this delivery: document the authority, purpose, and audit trail only. Do not invent a head-nurse approval step or budget enforcement feature without a new requirement.

### D2. Maximum-stock semantics — required for P3.3

Confirm for both supplies and medicines:

- whether maximum stock means physical capacity, desired post-reorder target, or both;
- whether the value applies globally or per clinic location;
- whether staff may override the proposed quantity;
- whether reaching the minimum automatically creates a request or only displays a recommendation.

Recommended contract: `reorder_level`/`reorder_threshold` is the trigger, `target_stock` is the desired replenished level, and the proposed order is `max(0, target_stock - on_hand)`. Staff may review the proposal, but all overrides are audited.

### D3. Stock-status precedence — required for P3.1

Recommended deterministic rules:

1. `out_of_stock` when on-hand equals zero;
2. `needs_to_reorder` when on-hand is greater than zero and at/below threshold;
3. `in_stock` otherwise.

Expiry/recall is a separate batch condition, not a mutually exclusive item stock status.

### D4. Previous-vitals behavior — required for P4.1

Confirm that only height and weight are reusable. Blood pressure, temperature, pulse, respiratory rate, and oxygen saturation should always begin blank because they describe the current visit.

Recommended behavior: suggest the most recent height and weight, visibly identify their source encounter/date, allow overwrite, and save new rows only after staff confirmation.

### D5. Waste taxonomy and preparation evidence — required for P5.2

Confirm what “bones” means in the operational taxonomy and whether twig chopping is:

- a blocking validation;
- a required checklist acknowledgement; or
- advisory guidance only.

Recommended first release: structured category rules plus an auditable preparation acknowledgement. Do not depend on free-text SKU matching.

### D6. Guidance queue entry point — required for P6.1

Choose exactly when a clinic-to-guidance referral joins the guidance queue:

- when the referral is created;
- when Guidance acknowledges it; or
- when the patient physically arrives/checks in.

Recommended behavior: acknowledgement makes the referral eligible, but physical check-in creates the operational queue entry. This avoids showing absent patients as waiting. If operations want immediate enqueue on acknowledgement, record that explicitly.

Also define cancellation, duplicate referral, next-day arrival, and no-show behavior.

### D7. Reporting buckets — required for P7.1 and P7.2

Confirm:

- week boundary: recommended Monday 00:00 through Sunday 23:59 in Asia/Manila;
- yearly meaning: recommended Academic Year, August 1 through July 31;
- whether “Today / Weekly / Monthly / Yearly” are range presets, aggregation choices, or both.

The UI should keep range and aggregation as separate controls to avoid ambiguity.

### D8. Predictor evidence threshold — required for P5.3

Agree on the minimum evidence needed before displaying a recommendation. Recommended initial rule:

- fewer than 5 comparable completed batches: no recommendation, show “Insufficient historical data”;
- 5–14 batches: low confidence;
- 15–29 batches: medium confidence;
- 30 or more batches with adequate day coverage: high confidence.

Final-yield observations alone support an association between elapsed days and yield, not a causal optimum. Repeated in-process measurements are preferable and should be collected if operations need a defensible optimal extraction day.

## 5. Dependency graph

```text
Baseline and decision gates
  |
  +-- Wave 1: documentation and low-risk UX
  |     P1.1, P2.1, P4.2, P4.3, P4.4, P6.2
  |
  +-- Wave 2: inventory data contract
  |     P3.2 -> P3.3 -> P3.1 -> P2.2
  |
  +-- Wave 3: encounter history assistance
  |     P4.1
  |
  +-- Wave 4: reporting foundation
  |     P7.1 -> P7.2 -> P7.3
  |
  +-- Wave 5: department queue
  |     P6.1
  |
  +-- Wave 6: BMG operational rules
  |     P5.1 -> P5.2
  |
  +-- Wave 7: BMG decision support
        P5.3 (depends on sufficient validated historical data)
```

Waves 2, 3, 4, 5, and 6 can be developed on separate branches after their decision gates are closed, but migrations must be ordered and merged carefully. Wave 7 must not be presented as complete merely because a chart or formula exists.

## 6. Delivery-wave plan

## Wave 0 — Baseline, ownership, and decisions

### Objectives

- Assign one accountable owner per CSV row.
- Close D1–D8 or mark affected phases blocked from implementation.
- Capture screenshots/API examples of current behavior.
- Separate existing WIP from new panel-feedback branches.
- Make automated quality gates trustworthy.

### Deliverables

- approved decision record appended to this plan;
- updated CSV owners and notes;
- baseline test/build report;
- clean branch boundaries for existing BMG/report work;
- test fixtures needed by later waves.

### Exit criteria

- no phase starts with an unresolved decision that changes its data model;
- current unrelated failures are fixed or explicitly isolated;
- no user-owned uncommitted work is overwritten.

## Wave 1 — Documentation and low-risk workflow safety

### P1.1 — Defense-ready procurement documentation

**Current state:** Direct ordering and reorder lifecycle exist. The panel's main request is a defensible explanation of why the current authority model is appropriate.

**Work:**

- Document current monthly-budget ownership, who may request/manage reorders, why no head-nurse approval exists, and how accountability is preserved.
- Produce a one-page defense narrative and a workflow diagram.
- Verify reorder audit events in the audit store/outbox rather than relying on application log text.
- Document the boundary: this phase does not claim automated monthly-budget enforcement unless D1 expands scope.

**Evidence:**

- approved narrative reviewed by clinic personnel;
- permission matrix for request/manage actions;
- audit event sample with no sensitive payload;
- defense screenshot/demo script.

### P2.1 — User-facing transaction and stock terminology

**Current state:** Persistent fields may retain `balance_after`; user-facing labels still contain ledger/balance/low-stock wording.

**Work:**

- Inventory all visible copy in web, mobile, reports, exports, empty states, accessibility labels, and help text.
- Replace user-facing “Ledger” with “Transactions.”
- Replace user-facing “Balance” with “Stock” or “Stock after transaction.”
- Do not rename database columns solely for copy consistency.
- Update screenshots, docs, and automated text selectors.

**Acceptance criteria:**

- no user-visible inventory screen uses “ledger” or ambiguous “balance”;
- API/DB compatibility remains intact;
- screen-reader labels use the same terminology;
- reports and PDFs use “Transactions” and “Stock.”

### P4.2 — Encounter step organization

**Current state:** Vitals, Assessment, and Treatments are already separated.

**Work:**

- Confirm Assessment is understood as the progress/clinical-notes step.
- Standardize the visible order: Vitals -> Assessment/Progress -> Treatments -> Complete.
- Add a lightweight step indicator only if observation shows staff lose their place.
- Preserve direct navigation to completed/read-only encounter data.

**Acceptance criteria:** Clinic staff can identify the current step and the next valid action without opening another modal or guessing terminology.

### P4.3 — Medicine autocomplete verification

**Current state:** The treatment flow already uses `ComboboxField`.

**Work:** Verification and polish only unless testing finds a defect.

- Test keyboard, pointer, empty, loading, and no-match states.
- Confirm search covers generic name, brand, and relevant identifiers.
- Confirm archived/unavailable medicine cannot be selected.
- Capture defense evidence.

### P4.4 — Encounter-completion confirmation

**Work:**

- Web: use the existing `ConfirmDialog` before the close mutation.
- Mobile: use `AlertDialog` before submission.
- Message must explain any real post-close restrictions without claiming fields are locked if the backend does not enforce that invariant.
- Disable repeat submission while the mutation is pending.
- Preserve API idempotency/conflict handling.

**Tests:** component interaction test where feasible, mutation-not-called-on-cancel test, Playwright confirmation flow, and mobile widget test.

### P6.2 — Standard FIFO queue policy

**Current state:** Clinic `callNext` uses ascending position and exposes no priority bypass.

**Work:**

- Document FIFO policy in clinic and future counselling queue services.
- Add invariant tests demonstrating no priority column/input changes call order.
- Keep emergency clinical escalation outside queue-position manipulation; emergency care should use a separately documented clinical workflow.

## Wave 2 — Inventory foundation and transaction visibility

This wave should ship as one compatible vertical slice because P3.3 changes the data contract consumed by P3.1 and P2.2.

### P3.2 — Preserve hard-number trigger

**Work:**

- Retain `on_hand <= threshold` as the only automatic reorder trigger.
- Remove percentage language from user-facing stock state.
- Add boundary tests for zero, exactly threshold, threshold plus one, and archived items.
- Keep one-open-request-per-item behavior.

### P3.3 — Add configurable target stock

**Preferred data design:**

- Add `target_stock INT UNSIGNED NULL` to supply items and medicines.
- Keep existing threshold names initially to avoid a broad contract rename.
- Add DB/application validation: target is null or strictly greater than threshold.
- Backfill target with a reviewed value. A mechanical `threshold * 2` backfill may preserve current behavior temporarily, but it must be marked as provisional and reviewed by clinic staff.
- Snapshot `target_stock` into a reorder request so historical requests retain the rule used at creation time.

**Migration sequence:**

1. Add nullable columns and indexes only if query plans require them.
2. Deploy code that reads nullable target and falls back to legacy quantity logic.
3. Backfill reviewed values.
4. Switch proposal logic to `target_stock - on_hand` when target is present.
5. Make target required only after all active rows are valid and stakeholders approve.

**API changes:**

- DTOs expose `target_stock`.
- Create/update validation accepts target.
- Reorder DTO exposes frozen threshold, target, on-hand, and proposed quantity.
- Avoid silent quantity changes on existing open requests.

**Web/mobile changes:**

- Add threshold and target fields with explanatory labels.
- Show calculation context on reorder creation.
- Require an explicit reason when authorized staff override the proposal.

**Tests:** migration up/down, invalid threshold/target combinations, exact proposal calculation, concurrency/duplicate open request, archived item exclusion, medicine aggregate on-hand, supply on-hand, audit context, and schema parsing.

### P3.1 — Explicit stock statuses

**Preferred contract:** Return a derived `stock_status` of `in_stock`, `needs_to_reorder`, or `out_of_stock`. Keep `low_stock` temporarily for backward compatibility, deprecate it after both clients migrate.

**Work:**

- Centralize derivation so backend, web, mobile, reports, and exports cannot disagree.
- Render text plus icon; color must not be the only signal.
- Replace “Low” and “Critical” in stock-state contexts. Do not rename reorder-request urgency until its separate operational meaning is reviewed.
- Treat expiry and recall as independent batch warnings.

**Acceptance criteria:** zero always renders Out of Stock; positive at/below threshold renders Needs to Reorder; above threshold renders In Stock.

### P2.2 — Scrollable transactions with running stock

**Preferred contract:** Keep existing movement/transaction endpoints initially and normalize client view models rather than adding a redundant endpoint alias.

**Work:**

- Provide signed movement display (`+N` receipt/adjustment in, `-N` dispense/movement out).
- Show transaction time, type/reason, responsible actor where permitted, note/reference, and `Stock after`.
- Use a bounded scroll area with accessible table semantics and mobile card/sheet parity.
- Define sort order explicitly. Recommended display is newest first, while `Stock after` remains the value recorded at that transaction.
- Add empty/loading/error states and pagination if transaction history can exceed the current bounded response.
- Verify running-stock chains and flag legacy null `balance_after` rather than fabricating a number.

**Tests:** signed-display mapping, running balance after receive/dispense, ordering, pagination, permission failures, accessible labels, and mobile scrolling.

## Wave 3 — Encounter history assistance

### P4.1 — Suggest previous height and weight

**Preferred backend design:** Add a self-contained service query keyed by patient identity, not a free-form school identifier in the URL. Return only the latest permitted height/weight source.

**Proposed response:**

```json
{
  "height_cm": 170.0,
  "weight_kg": 62.5,
  "source_encounter_id": 123,
  "recorded_at": "2026-08-01T02:30:00Z"
}
```

**Work:**

- Query the most recent unarchived vitals belonging to the same tenant and patient.
- Enforce clinic patient/vitals read permission.
- Do not expose counselling data or cross the module boundary.
- Web/mobile fetch suggestion when the vitals form opens.
- Prefill only blank height/weight fields; never overwrite current unsaved input.
- Label suggested values and allow staff to clear or replace them.
- Save provenance only if clinically useful and approved; otherwise retain the source solely in UI context.

**Edge cases:** no prior visit, prior record missing one field, guest patient without stable identity, archived encounter, concurrent vitals entry, network failure, and a current encounter that already has saved vitals.

**Tests:** tenant/patient scoping, latest-row selection, blank response, permission denial, form non-overwrite, override/save, and mobile parity.

## Wave 4 — Academic-year reports and actionable peaks

### P7.1 — Academic-year range presets

**Work:**

- Complete `ReportRange::academicYear(yearStart)` and `currentAcademicYear()` using Asia/Manila.
- Define labels such as `AY 2025-2026` and exact inclusive dates.
- Add Today, Last 7 Days, This Month, This Academic Year, Previous Academic Year, and Custom presets where appropriate.
- Keep arbitrary custom range support.
- Reconcile the current `MAX_DAYS = 366` rule with multi-academic-year comparisons; use a separate bounded comparison endpoint or approved maximum rather than silently weakening safeguards.
- Add presets to reports, dashboard, saved report configuration, mobile, PDFs, and exported provenance where those surfaces expose ranges.

**Tests:** July 31/August 1 boundary, leap year, Asia/Manila current date, explicit year, invalid year, saved configuration round trip, and UI preset synchronization.

### P7.2 — Consistent aggregation granularity

**Preferred API design:** Add a validated `granularity=day|week|month|academic_year` query parameter. Return neutral `bucket_start`, `bucket_end`, `label`, and numeric value fields instead of overloading `daily_trend` indefinitely.

**Compatibility rollout:**

1. Add a new `trend` field while retaining `daily_trend` for current clients.
2. Migrate web/mobile/PDF schemas and renderers.
3. Deprecate `daily_trend` after all consumers move.

**Work:**

- Implement one timezone-safe bucket builder used by clinic, counselling, inventory, referrals, and facilities reports.
- Avoid hard-coded `INTERVAL 8 HOUR` duplication where a centralized expression/helper can guarantee consistent behavior.
- Fill missing buckets with zero when charts require continuous timelines.
- Use Monday-start weeks if D7 approves it.
- Academic-year buckets run August 1 through July 31.
- Ensure saved/generated reports persist granularity as provenance.

**Tests:** boundaries around UTC-to-Manila midnight, week transition, month transition, AY transition, zero-filled gaps, each report module, schema compatibility, and CSV/PDF labels.

### P7.3 — Historically busiest month

**Work:**

- Compute peak from the same filtered monthly series displayed to the user.
- Return bucket label, count, selected period, and tie information.
- If multiple months tie, say so or apply a documented deterministic rule.
- Render “Historically busiest month” and selected AY; never say “upcoming” without a separate forecast.
- Add staff-planning explanatory copy without directly instructing leave approval decisions.

**Tests:** unique peak, tied peak, empty range, one-month range, changed AY, permission-scoped dashboard, and consistent PDF/export output.

## Wave 5 — Department-separated guidance queue

### P6.1 — Guidance/counselling queue

**Preferred architecture:** Keep clinic and counselling queue persistence separate to preserve module boundaries. Share only domain-neutral queue algorithms if that can be done without cross-module table coupling. Do not refactor the working clinic queue merely to create abstraction symmetry.

**Proposed counselling queue data:**

- tenant ID;
- referral ID and counselling patient/session reference where permitted;
- queue date and monotonically assigned position within tenant/date/zone;
- status (`waiting`, `called`, `serving`, `completed`, `cancelled`, `no_show`);
- timestamps for arrival, call, service start, finish;
- archived timestamp;
- uniqueness/idempotency key preventing duplicate active entries for the same referral/arrival.

**Work:**

- Add migration, indexes, constraints, DTO, policy, service, controller, routes, and audit events.
- Implement enqueue at the D6-approved lifecycle point.
- Assign position under a transaction/row lock so concurrent arrivals cannot duplicate positions.
- Implement FIFO call-next with no priority bypass.
- Define next-day handling rather than carrying stale positions silently.
- Add counsellor queue UI and mobile surface.
- If a public display is required, create a separate minimum-disclosure response; do not reuse staff DTOs.
- Keep referral state and queue state distinct. A referral can remain valid even if a daily queue entry is cancelled/no-show.

**Tests:** concurrent enqueue, duplicate prevention, tenant/date/zone isolation, FIFO call-next, state transitions, referral lifecycle integration, cancellation/no-show, next-day arrival, RBAC, audit, public disclosure, and no prohibited clinic/counselling joins.

**Rollout:** Deploy migration and dormant endpoints first; then staff UI; then enable automatic/assisted enqueue after operational training.

## Wave 6 — BMG baseline and sorting controls

### P5.1 — 21-day fallback

**Current WIP:** `BmgAnalytics::DEFAULT_DURATION_DAYS = 21` exists locally but is not sufficient by itself.

**Work:**

- Finish and clean the current WIP.
- Replace all remaining hard-coded 45-day fallbacks in `BmgService` with the central constant/helper.
- Decide whether existing category values of 30/35/45 are valid overrides or stale defaults; do not overwrite legitimate category-specific values blindly.
- Backfill only null values if stakeholders approve a database default.
- Expose whether an expected date came from category configuration, historical average, weighted composition, or global fallback.

**Tests:** fallback, category override, historical override, mixed-category weighting, migration behavior, displayed source, and existing analytics regression suite.

### P5.2 — Category sorting and preparation rules

**Preferred data design:** Avoid opaque free-text or one-off JSON when rules need validation and reporting. Use structured rule records, for example:

- category rule type (`forbidden_material`, `required_preparation`);
- canonical material/preparation code;
- user-facing instruction;
- blocking versus acknowledgement-only enforcement;
- active dates and tenant scope.

If the taxonomy is small and stable, a constrained JSON schema may be acceptable, but it still needs validation and versioning.

**Work:**

- Define canonical material code for bones and preparation code for chopped twigs.
- Validate batch composition against active category rules.
- Record preparation acknowledgement with actor/time if required.
- Show rules before batch start and again when invalid material is entered.
- Apply web/mobile parity and audit rule changes.
- Never infer safety-critical rules from arbitrary display-name substring matching.

**Tests:** forbidden bone/food combination, allowed category, yard-waste acknowledgement, missing acknowledgement, inactive rule, tenant isolation, rule editing permissions, audit, and accessible error messaging.

## Wave 7 — Historical-yield extraction recommendation

### P5.3 — Decision-support predictor

This phase has a data gate. It cannot be considered complete with seeded demonstrations alone.

**Stage A: instrumentation and data-quality audit**

- Define the outcome: final usable output yield, quality-adjusted yield, or another approved measure.
- Verify consistent start, extraction/release, input mass, output mass, category/composition, and process-condition data.
- Add repeated observation storage if staff can practically record in-process yield/quality measurements.
- Produce a data-quality report: sample counts, missingness, duration coverage, outliers, and category comparability.

**Stage B: transparent baseline model**

- Group comparable completed batches by category/composition and elapsed days.
- Calculate sample count, mean/median yield, dispersion, and confidence per day band.
- Use smoothing/interpolation only when coverage supports it.
- Choose the recommended day from an approved objective, not simply the largest noisy observation.
- Return evidence alongside the recommendation: sample count, observed range, expected yield, confidence, last model refresh, and reason when unavailable.

**Stage C: operational UI**

- Plot observed historical points/bands and the current batch's elapsed day.
- Label recommendations as evidence-based estimates, not guarantees.
- Show “Insufficient historical data” instead of a fabricated default recommendation.
- Let users inspect the supporting historical range without exposing sensitive actor/patient data.
- Provide feedback capture for actual extraction day and resulting yield so the model improves.

**Stage D: validation**

- Back-test on held-out historical batches.
- Compare against the 21-day baseline and current operator decisions.
- Define acceptable error and false-recommendation thresholds with the panel/domain owner.
- Pilot as advisory only; do not automate extraction.
- Monitor drift by category and composition.

**API shape:**

- category curve endpoint with day band, central estimate, dispersion, and sample count;
- batch recommendation endpoint with recommended day/date, expected range, confidence, evidence count, model version, and explanation code;
- no recommendation when D8 threshold is unmet.

**Tests:** deterministic calculations, sparse data, outliers, mixed composition, no data, tenant isolation, model versioning, date boundaries, UI disclaimers, and back-test fixture.

## 7. Cross-client contract strategy

For every API change:

1. Define backend DTO and example envelope.
2. Update web Zod schema before consuming the field.
3. Update Flutter model/parser with backward-compatible nullable handling.
4. Add contract fixtures shared conceptually across backend, web, and mobile tests.
5. Deploy additive server changes before clients that depend on them.
6. Remove legacy fields only in a later versioned cleanup.

Likely additive/deprecated fields include:

- `target_stock`;
- `stock_status` while retaining `low_stock` temporarily;
- normalized `trend` while retaining `daily_trend` temporarily;
- recommendation evidence fields;
- queue zone/status fields.

## 8. Migration and rollback policy

- Prefer expand-and-contract migrations.
- New fields begin nullable unless a correct value can be derived without guessing.
- Backfills run in bounded batches for large tables.
- Add constraints only after data passes validation queries.
- Never modify an old migration that may already have run in another environment.
- Snapshot rule inputs on transactional records when historical interpretation matters.
- Before deployment, record row counts and invalid-data counts; after deployment, compare them.
- Rollback must preserve user-entered data. If a column cannot safely be dropped, rollback code behavior and leave the additive column dormant.

## 9. Test and quality gates

Every implementation wave must provide the following evidence as applicable.

### Backend

- PHPUnit unit tests for calculations, state machines, policies, and DTOs.
- Database integration tests for migrations, queries, locking, tenant scope, and outbox atomicity.
- Route/controller tests for validation, envelope shape, permissions, and public disclosure.
- All PHP files pass syntax checks.

### Web

- TypeScript typecheck.
- ESLint with zero warnings after the configuration is repaired.
- Schema/contract tests.
- Focused interaction tests for confirmation, autocomplete, statuses, ranges, charts, and transactions.
- Production build with bundle impact reviewed.
- Playwright critical paths against a seeded live backend.

### Mobile

- `flutter analyze` and `flutter test` complete successfully, not merely start.
- Model parser tests for every additive API field.
- Widget tests for confirmations, statuses, transactions, ranges, and queue states.
- At least one device/emulator smoke for each affected workflow.

### Operational

- permission matrix review;
- audit-event inspection;
- minimum-disclosure review for public endpoints;
- migration rehearsal on a production-like database copy;
- clinic/counselling/facilities user acceptance evidence;
- screenshots or recorded demo steps for the defense.

## 10. Proposed pull-request boundaries

Keep changes reviewable and independently releasable:

1. **PR A — Baseline and documentation:** test/lint baseline, P1.1, P4.3/P6.2 verification notes.
2. **PR B — Safety and terminology:** P2.1, P4.2, P4.4.
3. **PR C — Inventory schema/API:** P3.2/P3.3 migrations, DTOs, services, backend tests.
4. **PR D — Inventory clients:** P3.1/P2.2 web, mobile, reports, E2E.
5. **PR E — Previous vitals:** P4.1 complete vertical slice.
6. **PR F — Report range and aggregation:** P7.1/P7.2 backend and contracts.
7. **PR G — Report insights:** P7.3 plus web/mobile/PDF integration.
8. **PR H — Guidance queue backend:** P6.1 migration, service, API, integration tests.
9. **PR I — Guidance queue clients and operational enablement.**
10. **PR J — BMG baseline and rules:** P5.1/P5.2.
11. **PR K — Predictor instrumentation and data-quality report.**
12. **PR L — Predictor pilot:** P5.3 only after evidence and validation gates pass.

Avoid combining the structural queue migration, inventory migration, and predictor in one release.

## 11. Tracker update protocol

The CSV remains the progress tracker. Update it using these rules:

- `todo`: decision or implementation has not started.
- `in_progress`: an owner and branch/PR exist and work has begun.
- `blocked`: an explicit decision, data, or external dependency prevents progress.
- `done`: all acceptance criteria and required quality gates have authoritative evidence.
- `Tested Functional=yes`: requires the listed functional verification to have actually run against the integrated system.

For every status change, update:

- Owner;
- Notes with decision/PR reference;
- Verification / How to Test if the implemented contract changed;
- Files Affected if the final implementation differs from the initial estimate.

Do not mark a phase done based only on code presence, a unit test, a screenshot, or a seeded demo when the requirement is broader.

## 12. Recommended execution order

1. Complete Wave 0 and stakeholder decisions.
2. Deliver Wave 1 for immediate defense clarity and safety.
3. Deliver Wave 2 because inventory terminology, statuses, transactions, and reorder quantities are coupled.
4. Deliver Wave 3 as a contained clinical workflow improvement.
5. Deliver Wave 4 before the next reporting demonstration.
6. Deliver Wave 5 only after Guidance confirms queue entry semantics.
7. Deliver Wave 6 after Facilities confirms taxonomy and enforcement.
8. Begin Wave 7 instrumentation early, but release recommendations only after sufficient real data and validation.

## 13. Completion definition

The panel-feedback program is complete only when:

- every CSV phase has decision-backed acceptance criteria;
- all implementation phases are deployed across required clients;
- migrations and compatibility behavior are verified;
- backend, web, mobile, and operational gates pass;
- clinic, counselling/guidance, and facilities representatives accept their workflows;
- analytics wording accurately distinguishes descriptive history from prediction;
- the extraction recommendation has evidence, confidence, and a validated no-data behavior;
- the defense narrative matches the system that is actually running.
