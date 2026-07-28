# Audit

Append-only, hash-chained audit log.

## Tables

- `audit_outbox` — same-transaction writes from services.
- `audit_events` — immutable landing zone. `commit_hash = sha256(prev_hash + payload_json)`.

## Drain

```bash
php spark synapse:audit-drain [--batch=500] [--max-batches=10]
```

Claims unprocessed outbox rows with `FOR UPDATE SKIP LOCKED` (parallel-safe), appends them to `audit_events` with the chained hash and `prev_id` linkage, and marks `processed_at`. A failing batch rolls back atomically; the offending row's `attempt_count`/`last_error` are bumped and rows at 5 attempts are ejected from selection.

## Reader endpoints

| Method | Path                          | Permission       |
| ------ | ----------------------------- | ---------------- |
| GET    | `/api/v1/audit/events`        | `audit.read`     |
| GET    | `/api/v1/audit/events/{id}`   | `audit.read`     |
| GET    | `/api/v1/audit/export`        | `audit.export`   |
| GET    | `/api/v1/audit/verify/{id}`   | `audit.read`     |

## CSV export

`GET /api/v1/audit/export?cursor=…&limit=…` streams a CSV. Sensitive keys (see `CsvWriter::REDACT_KEYS`) are replaced with `<redacted>` before streaming. The hard cap is 5,000 rows per request.

## Verification (Phase 6)

`GET /api/v1/audit/verify/{id}` recomputes the hash chain from genesis up to `{id}` and returns `{ ok, checked, verified_up_to, first_divergence }` — `first_divergence` reports the first row whose `prev_id` linkage or `commit_hash` diverges. The CLI equivalent:

```bash
php spark synapse:audit-verify [--to=N]   # exit 0 = intact, 1 = divergent
```

Both are read-only and share `App\Services\Audit\AuditChainVerifier`.