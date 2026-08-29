# Guidance queue product assumptions

The final approved Guidance kiosk-purpose wording was not included with the
implementation request. Until Guidance staff approve the labels, the isolated
configuration uses these provisional options:

- Initial Consultation
- Follow-up Session
- Crisis Support
- Referral Follow-up
- Other (explicit custom text, maximum 120 characters)

The backend source of truth is `App\Services\Kiosk\CheckinPurposeCatalog`; the
frontend mirrors the same labels in one configuration file. Replacing these
labels does not require a migration or a new purpose-management page.

Guidance guest check-in is intentionally unsupported in the first release.
Counselling sessions require a registered `patient_school_id`; allowing an
anonymous queue entry would produce a patient who cannot safely enter the
existing encrypted counselling-record workflow. Clinic guest check-in remains
available.

An authorized referral handoff adds the patient to the receiving department's
queue and records the logical referral-to-queue association. It deliberately
leaves the source department's queue entry unchanged: receiving staff do not
implicitly complete, skip, or otherwise mutate the source department's
lifecycle. Source staff retain responsibility for its disposition.
