# Data Sharing — External API Surface

> **Status:** engineering support template, **not legal advice**. The university
> (Foundation University) is the Personal Information Controller (PIC) and must
> review, amend, and sign off on this document through its Data Protection
> Officer before any LIVE key is issued to an external party.
>
> **DPO contact:** *[placeholder — assign before first live integration]*

This document describes what the Synapse external API exposes, the safeguards
that make it safe to share, and the obligations the university assumes under
the Data Privacy Act of 2012 (Republic Act No. 10173) and its implementing
rules and regulations.

## 1. Purpose limitation

The external API (`/api/v1/external/v1/...`) exists for one purpose: letting
verified university-affiliated integrations — e.g. student capstone teams and
campus dashboards — read **de-identified, aggregate statistics** about clinic
utilization, check-in flow, and referral flows.

It MUST NOT be used to:

- identify, profile, or contact any patient, student, or employee;
- combine Synapse aggregates with other datasets for re-identification;
- extract raw clinical records, notes, identifiers, or free-text complaints.

Scope grants are least-privilege (a key receives only the scopes it asked
for), and every request is audited with app + key identity.

## 2. Data categories (v1)

| Category | Examples | Identifiability |
|---|---|---|
| Encounter counts | total encounters, status breakdown, daily trend | aggregate counts |
| Check-in outcomes | outcome distribution per period | aggregate counts |
| Patient-type split | counts by student / employee / guest kind | aggregate counts, no names or IDs |
| Complaint categories | pre-bucketed categories (Respiratory, Digestive, …) | aggregate counts — free-text chief complaints never leave the system |
| Referral flows | status breakdown, source→target counts, closed rate | aggregate counts |

**What does NOT exist in v1:** patient-record endpoints, name/ID returners,
notes, appointment details, inventory supplier data, and webhooks. Any future
PHI-returning scope requires (a) an explicit superadmin-only grant, (b) a
documented lawful basis under RA 10173, and (c) a DPIA-style review recorded
in this file before the scope is added to `Config\ExternalApps::$scopes`.

## 3. Lawful basis & NPC registration

- Clinic records are **Sensitive Personal Information** under RA 10173. The
  university, as PIC, processes them for the delivery of student and employee
  health services; the external surface's aggregates support institutional
  analytics and education.
- **NPC registration:** under Section 42 of the IRR, a data processing system
  must be registered with the National Privacy Commission when it processes
  sensitive personal information of at least one thousand (1,000)
  individuals. The university's clinic system exceeds this threshold and is
  therefore subject to registration. *[Placeholder — registration number and
  date to be recorded by the DPO.]*
- **Data Protection Officer:** a DPO / compliance officer for data privacy
  must be designated (NPC issuances) and is the contact point for data
  subjects and the NPC. *[Placeholder — name, email, phone.]*

## 4. Security safeguards (implemented in this repository)

- **Credentials:** Stripe-style keys `syn_<env>_<prefix4>_<secret>`. The
  secret is shown once at creation and stored only as a SHA-256 hash — it
  cannot be recovered from the database, only revoked.
- **Environment separation:** test keys are pinned to a dedicated sandbox
  tenant with synthetic data; live keys are pinned to the production tenant.
  Tenant scoping is enforced per query and feature-tested (a test key cannot
  read production-tenant data).
- **Least privilege:** scopes are an explicit allowlist; the wildcard is
  never grantable. Missing scope ⇒ `403 rbac.permission_denied:<scope>`.
- **Rate limiting:** per-key fixed-window budget (default 60/min) with
  `X-RateLimit-*` response headers.
- **Rotation & revocation:** 90-day default expiry, instant revocation,
  zero-downtime rotation via multiple active keys per app.
- **Auditability:** app/key lifecycle events (`api_app.created`,
  `api_app.suspended`, `api_key.created`, `api_key.revoked`) and sampled
  `external.request` events feed the append-only, hash-chained audit log
  (`synapse:audit-verify`).
- **Suspension:** suspending an app stops all of its keys immediately.

## 5. Retention

- Externally fetched aggregates: retention is owned by the receiving
  integration; the recommendation is to keep only the most recent 12 months.
- Audit events: retained in `audit_events` (append-only); audit outbox rows
  drain asynchronously.
- Issued keys: expired/revoked key rows are retained for auditability; the
  full secret never existed server-side.

## 6. Breach notification (NPC Circular 16-03)

RA 10173 §20(f) obliges the PIC to notify the NPC and affected data subjects
of a personal data breach that is reasonably believed to create a real risk
of serious harm. NPC Circular 16-03 sets the operational deadline:

1. **Within 72 hours** of knowledge (or reasonable belief) of a personal data
   breach, the PIC notifies the NPC — and, where required, affected data
   subjects — based on the information available at the time.
2. A **full breach report** follows (current NPC guidance: within 5 days of
   discovery).

Integration partners who detect an actual or suspected compromise of a
Synapse API key or of fetched data must notify the university DPO immediately
at *[placeholder]* so the 72-hour clock can be honored. Compromised keys are
revoked within minutes of the report.

## 7. Key revocation & incident playbook (summary)

1. Revoke the key in the developer portal (immediate effect) or suspend the
   owning app to stop all of its keys.
2. Rotate: issue a replacement key; update the integration.
3. Preserve evidence: the audit log's external.request entries identify the
   key prefix, endpoint, and status codes per request.
4. Notify the DPO; the DPO evaluates the 72-hour NPC notification duty.
