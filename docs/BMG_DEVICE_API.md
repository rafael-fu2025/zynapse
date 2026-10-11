# BMG device API — ESP32-S3 contract

The backend contract for the mechanized compost-drum controllers. Every
endpoint here is reached through `DeviceAuthFilter` and authenticated with
the device's static token. The firmware is expected to match this document;
the backend does not adapt to the firmware.

- **Base:** `https://<host>/api/v1/devices`
- **Header:** `X-Device-Token: dev_<64 hex>` (an `Authorization: Bearer
  dev_…` header is accepted as a fallback). User JWTs never work here, and
  device tokens never work on the human API.
- **Timeouts:** every request should use a **5 s timeout**. The board must
  never make a run depend on a network call — it runs its flash-resident
  schedule and reports afterwards.
- **Body format:** `Content-Type: application/json`.
- **Response shape:** the NEW endpoints (`/schedule`, `/heartbeat`,
  `/runs`) answer **flat JSON** — no envelope, parse at depth 1. The
  older `/turn-sessions` and `/commands*` endpoints keep the legacy
  `{success, data, errors, meta}` envelope. **On any non-200 response,
  ignore the body and keep current state** — errors always use the
  envelope shape and are for logs, not for branching.
- **Rate limit:** 120 requests/min per device token (env
  `RATELIMIT_DEVICE_PER_MIN`). Exceeding it returns `429` with
  `Retry-After: 60`.
- **Clock:** the server stores UTC; the schedule's times are
  **Asia/Manila "HH:MM"**. The device sets its own clock from NTP and
  reports RTC health in the heartbeat; the server never sends time.

---

## 1. `GET /bmg/schedule` — pull the run schedule

Poll roughly every 10 minutes. **Apply only when `version` changes** —
compare against the version stored in flash.

### 200 OK — flat body

```json
{
  "times": ["09:00", "12:00", "17:00"],
  "duration_seconds": 10,
  "pwm": 220,
  "enabled": true,
  "version": 7
}
```

| Field | Type | Meaning |
| --- | --- | --- |
| `times` | string[] | Up to 8 daily run times, `"HH:MM"` 24-hour, **Asia/Manila**, ascending. Empty = no scheduled runs. |
| `duration_seconds` | int | Motor run length per slot, 1–3600. |
| `pwm` | int | Motor PWM duty, 0–255. |
| `enabled` | bool | Master switch. `false` = run nothing, keep the rest of the config. |
| `version` | int | Monotonic. Re-apply the whole payload when this differs from your stored copy; store the new version only after applying. |

### Errors

Envelope shape, e.g.:

```json
{
  "success": false,
  "data": null,
  "errors": [{"code": "device.not_bound", "message": "This device is not bound to a BMG unit."}],
  "meta": null
}
```

| Status | Code | Meaning |
| --- | --- | --- |
| 401 | `device.unauthorized` | Missing/unknown token. |
| 403 | `device.disabled` | Token revoked or device disabled. |
| 409 | `device.not_bound` | The board is not assigned to a drum yet. |
| 429 | `ratelimit.exceeded` | Back off (`Retry-After` seconds). |

If the unit's schedule has never been published, the server answers with
safe defaults: `times: []`, `duration_seconds: 600`, `pwm: 200`,
`enabled: false`, `version: 1`. **Keep your previous schedule on any
error** and retry on the next poll.

---

## 2. `POST /bmg/heartbeat` — liveness + telemetry

Send every ~5–10 minutes (the server's offline threshold is
`BMG_OFFLINE_AFTER_MIN`, default 20 minutes). All fields optional.

```json
{
  "firmware": "bmg-2026.10.1",
  "uptime_seconds": 86400,
  "rtc_epoch": 1791561600,
  "rtc_valid": 1,
  "rssi_dbm": -61,
  "last_run_result": "completed",
  "motor_state": "idle"
}
```

| Field | Type | Notes |
| --- | --- | --- |
| `firmware` | string ≤32 | Free-form tag, stored for the dashboard. |
| `uptime_seconds` | int | Since boot. |
| `rtc_epoch` | int | Current RTC time as UTC epoch seconds. |
| `rtc_valid` | 0/1 | `0` when the DS3231 is missing/unhealthy — the server then measures no drift and raises an RTC alert for staff. |
| `rssi_dbm` | int −120..0 | WiFi signal. |
| `last_run_result` | string ≤32 | Mirror of the last run outcome (`completed`/`failed`/`aborted`). |
| `motor_state` | string ≤16 | `idle` / `running` / `stopped`. |

### 200 OK

```json
{"ok": true}
```

### Errors

| Status | Code |
| --- | --- |
| 401 | `device.unauthorized` |
| 403 | `device.disabled` |
| 422 | `validation.*` (bad field types/ranges) |
| 429 | `ratelimit.exceeded` |

Unbound boards may heartbeat — liveness is independent of a drum.

---

## 3. `POST /bmg/runs` — report one motor run

One call per run, scheduled or manual. **`run_uid` must be unique and
stable** — retries with the same uid are idempotent (the server answers
`200` with `"created": false` and does not duplicate anything). Generate
it once when the run starts, e.g. `run-<epoch>-<counter>`, persist it in
flash until the report is acknowledged, and reuse it on every retry.
Queue in flash exactly like the turn-session payload if WiFi is down.

```json
{
  "run_uid": "run-1791561600-0007",
  "origin": "scheduled",
  "started_at_epoch": 1791561600,
  "ended_at_epoch": 1791561610,
  "duration_seconds": 10,
  "result": "completed",
  "turns_count": 4,
  "note": ""
}
```

| Field | Type | Notes |
| --- | --- | --- |
| `run_uid` | string ≤64 `[A-Za-z0-9_-]` | Idempotency key. Required. |
| `origin` | `scheduled` \| `manual` | `manual` = operator pressed Run now. Required. |
| `started_at_epoch` / `ended_at_epoch` | int | RTC epochs. Optional; a value outside the sane window (ended before start, >10 min in the future, >7 days in the past) is dropped and receipt time is used. |
| `duration_seconds` | int 1–86399 | Required. |
| `result` | `completed` \| `failed` \| `aborted` | Required. Also mirrors into the heartbeat. |
| `turns_count` | int ≤10000 | Optional. |
| `note` | string ≤500 | Optional. |

### 201 Created (fresh) / 200 OK (replay)

```json
{"run_uid": "run-1791561600-0007", "created": true, "batch_id": 123}
```

`batch_id` is `null` when the drum has no active batch. That is **not an
error**: the run is stored, staff are notified, and the dashboard shows
it. Keep reporting; do not retry.

### Errors

| Status | Code | Meaning |
| --- | --- | --- |
| 401/403 | `device.unauthorized` / `device.disabled` | Token problem; stop and log. |
| 409 | `device.not_bound` | The board has no drum; runs cannot be filed. |
| 422 | `validation.*` | Malformed payload — fix the firmware, retrying will not help. |
| 429 | `ratelimit.exceeded` | Back off; keep the run queued. |

---

## 4. `GET /bmg/commands` — pending operator commands

Poll on your existing cadence (1.5 s while running, 3.5 s idle). Flat
shape is not used here — this endpoint keeps the legacy envelope:

```json
{
  "success": true,
  "data": [
    {
      "id": 91,
      "command": "run_now",
      "payload": {"unit_id": 4, "manual": true},
      "status": "pending",
      "delivered_at": "2026-10-07 03:15:00",
      "expires_at": "2026-10-07 03:25:00",
      "created_at": "2026-10-07 03:15:00"
    }
  ],
  "errors": null,
  "meta": null
}
```

Commands, oldest first, max 20 per poll:

| Command | Meaning |
| --- | --- |
| `start` | A batch started — begin the batch's turning programme. |
| `run_now` | Operator pressed Run now — one immediate run using the current schedule's duration/PWM settings. |
| `stop` | Stop the motor now (batch cancelled/finished, or maintenance). |

Delivery is **at-least-once**: fetching stamps `delivered_at` but the row
stays `pending` until the ACK, so a crashed board re-receives it — dedupe
by `id`. **Expiry:** when `expires_at` has passed, the server fails the
command and stops returning it; it will never fire late. Ack what you
actuate:

## 5. `POST /bmg/commands/ack` — confirm a command

```json
{"command_id": 91}
```

### 200 OK

```json
{
  "success": true,
  "data": {"id": 91, "command": "run_now", "status": "acknowledged", "acknowledged_at": "2026-10-07 03:15:04"},
  "errors": null,
  "meta": null
}
```

Re-acking is idempotent. A 404 means the id is not yours (or unknown).

---

## 6. `POST /bmg/turn-sessions` — legacy turning session

Kept for existing firmware; new firmware should prefer `/runs` (it
accepts runs without an active batch and carries a run outcome). Same
token/invoke posture: `session_uid` is the idempotency key, and the
session is rejected with `409 statemachine.bmg.log_terminal_batch` when
the drum has no active batch — queue and retry, exactly as today.

---

## Firmware checklist

1. Store `{times, duration_seconds, pwm, enabled, version}` in flash;
   apply at a slot boundary, not mid-run.
2. Poll `/schedule` every ~10 min; apply only on a version change.
3. Heartbeat every ~5–10 min with `rtc_valid` set honestly — a lying
   flag disables drift monitoring.
4. Report every run to `/runs` with a flash-persisted `run_uid`; retry
   with the same uid until a 200/201 lands.
5. Dedupe commands by `id`, honour `expires_at`, ACK after actuating.
6. Never gate a run on network success — flash schedule first, report
   second.

## Environment knobs (server side)

| Var | Default | Effect |
| --- | --- | --- |
| `BMG_OFFLINE_AFTER_MIN` | 20 | Heartbeat age at which a device is "offline". |
| `BMG_MISSED_RUN_GRACE_MIN` | 30 | Minutes after a slot before it counts as missed. |
| `BMG_COMMAND_TTL_MIN` | 10 | Pending command lifetime. |
| `RATELIMIT_DEVICE_PER_MIN` | 120 | Per-device request budget. |
