# Clinic Procurement and Reordering — Defense Notes

## Position to defend

SYNAPSE allows authorized clinic personnel to create and manage reorder requests directly. It does not require approval from a head nurse because that role is not currently part of the clinic's operating structure. The system models the clinic's present process instead of inventing an unavailable approver who would delay replenishment.

Direct reordering is not unrestricted purchasing. It is a permission-controlled, traceable procurement workflow operating within the clinic's externally managed monthly budget.

## Why this design exists

- The clinic works with a monthly allocation and must replenish essential medicines and supplies before stock is exhausted.
- No head nurse is currently assigned, so a mandatory head-nurse gate would create a fictional dependency and could stop legitimate orders.
- Authorized clinic personnel are closest to actual consumption, current stock, and immediate care requirements.
- Separating the stock trigger from the human procurement lifecycle keeps the system helpful without allowing it to purchase autonomously.

## Authority and controls

The application checks permissions rather than job-title strings:

- `clinic.reorders.read` permits viewing reorder requests.
- `clinic.reorders.manage` permits manual requests, threshold checks, and lifecycle transitions.
- The seeded `clinic_staff` group receives both permissions; administrators receive the wildcard permission.

The current lifecycle is:

```text
pending -> approved -> ordered -> received -> completed
   |          |           |
   +----------+-----------+-> cancelled
```

`completed` is produced by the related stock-entry flow after delivered stock is recorded. It is not a button that can bypass inventory receipt.

## Accountability safeguards

- A medicine or supply item can have only one open request at a time.
- Manual requests record the requesting user, requested quantity, current stock, threshold, urgency, note, and creation time.
- Every request and transition writes an audit-outbox event in the same transaction as the business change.
- Transition audit events identify the resulting state without including medicine secrets, clinical notes, credentials, or patient payloads.
- Automatic checks create only a `pending` request. They do not approve, order, pay for, or receive goods.
- Notifications alert users with the relevant permission that a reorder request exists.
- Archived inventory items are excluded from automatic checks.

## What the system does not yet claim

- It does not enforce the monetary amount of the monthly budget.
- It does not integrate with purchasing, accounting, or a supplier.
- It does not replace organizational approval policies outside the clinic.
- It does not yet replenish toward an independently configured maximum stock; that is tracked as P3.3.

If the university later appoints a head nurse or introduces a formal budget approver, the permission matrix and state machine can add that gate without rewriting the inventory ledger.

## Suggested defense answer

> We allow direct reorder requests because the clinic currently has no head nurse and operates from a monthly allocation. Requiring a nonexistent approver would delay essential stock. The feature does not make uncontrolled purchases: only authorized clinic personnel can manage requests, the system permits one open request per item, and every request and state transition is audited. Automatic stock checking only proposes a pending request; staff remain responsible for approval, ordering, and receipt. If the university formalizes another approval role, the permission-based workflow can add it.

## Demonstration sequence

1. Sign in as an authorized clinic user.
2. Open Inventory, then Reorders.
3. Show current stock and threshold information for an item.
4. Run the threshold check or create a manual request.
5. Show that the request starts as `pending` and that duplicate open requests are rejected.
6. Advance a test request through the permitted lifecycle.
7. Open the Audit page and locate the corresponding `clinic.reorder_requested` and transition events.
8. Explain that actual budget totals remain an administrative process outside the current software scope.

## Evidence checklist before presentation

- [ ] Clinic representative confirms direct ordering accurately reflects current practice.
- [ ] The panel accepts that this phase documents monthly-budget context rather than enforcing a budget ceiling.
- [ ] Demo roles have the intended reorder permissions.
- [ ] Audit outbox is drained so events appear in the Audit page.
- [ ] Demo data contains at least one threshold-triggered item and one reorder that can be transitioned safely.
- [ ] The presenter can explain the difference between a stock trigger, a reorder request, an approved order, and recorded receipt.

