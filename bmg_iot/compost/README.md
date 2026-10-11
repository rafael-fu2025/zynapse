# compost.ino — mechanized BMG compost tumbler controller

ESP32-S3 firmware (`compost-1.2`) that turns the compost drum, runs the
UI-managed schedule from its own clock, takes remote commands from the Synapse
BMG module over WiFi, and reports every motor run.

**Board (verified via esptool on this unit):** ESP32-S3 rev v0.2, 16 MB quad
flash + 8 MB embedded PSRAM, CH343 USB-UART — a SuperMini-style dev board.
Serial port on this machine: **COM6**. Chip MAC: `b8:1f:3f:d7:ec:18`
(esptool reports it; the firmware also prints it at boot, and it is the default
device code at registration).

The sketch's GPIO map is valid on ESP32-S3 as-is: GPIO 8/9 are I2C for
the DS3231 RTC, GPIO 4/5/6 drive the motor H-bridge. All status goes to the
**Serial Monitor at 115200**. Do **not** port it to a classic ESP32 (WROOM-32):
its flash pins overlap GPIO 6–11 territory and the sketch would crash.

**Dependencies:** Adafruit **RTClib** (auto-installs Adafruit BusIO) —
already installed on this machine via arduino-cli; other machines need
one Library Manager install. WiFi, HTTPClient, and Preferences are
bundled with the ESP32 core. No JSON library: the payloads are small and
flat, so the sketch carries its own strict scanner.

## Wiring

| Signal | GPIO | Connects to |
|---|---|---|
| I2C SDA | 8 | DS3231 RTC |
| I2C SCL | 9 | DS3231 RTC |
| Motor IN1 | 4 | H-bridge input 1 |
| Motor IN2 | 5 | H-bridge input 2 |
| Motor EN  | 6 | H-bridge enable (PWM) |

## Architecture: who owns what

The rule that keeps the motor timing safe is that each resource has exactly one
owner, and the cores only talk through queues.

| Core | Owns | Never touches |
|---|---|---|
| **1** (`setup`/`loop`) | motor pins, the DS3231 (I2C), the run state machine, schedule firing | WiFi, HTTP, NVS report queue |
| **0** (`networkTask`) | WiFi, NTP, HTTP, the NVS report queue | motor pins, I2C |

- **Core 0 → Core 1:** a `CoreCmd` queue (`start` / `stop` / `run_now`), plus a
  mutex-guarded hand-off for a freshly pulled schedule.
- **Core 1 → Core 0:** a `CoreEvt` queue (run began / ended / cancelled,
  command done). A run report is built and persisted by Core 0 only.
- A `stop` is therefore actuated by Core 1's next loop pass (about a
  millisecond), not by Core 0 writing pins mid-turn. The old 1.1 design had a
  race there, and a blocking report call could hold the motor loop for ~35 s.
- The network task is non-blocking: a WiFi connect in flight never delays
  draining run events, so a run that finishes offline is still persisted.

## What the board does

| Trigger | Run | Origin reported |
|---|---|---|
| Server `start` command (batch started) | the **recipe**: 3 sets × 7 timed turns | `manual` |
| Server `run_now` command | one **timed run** using the schedule's `duration_seconds` + `pwm` | `manual` |
| A slot in the schedule's `times` (Asia/Manila) | one timed run, same settings | `scheduled` |
| Serial `s` / `r` | recipe / timed run | `manual` |

Every run starts with a **5 s safety countdown**. A stop during the countdown
cancels cleanly and reports nothing (the drum never moved).

**Recipe (prototype bench values — do not tune casually):** `5 s` countdown →
3 sets × 7 turns (`1.2 s` on, `0.3 s` gap) with `10 s` pause between sets.
Reported `turns_count` is **counted**, not assumed, so an aborted recipe
reports the turns it really did.

### Schedule

- Pulled from `GET /bmg/schedule` every 10 min and applied **only when `version`
  changes**, and only **between runs** (never mid-run).
- Persisted to flash **after** it is applied, so a reboot resumes the schedule
  with no network at all.
- Parsed strictly: any missing or out-of-range field rejects the whole payload
  and the previous schedule stays. A half-parsed schedule never reaches the motor.
- Slots fire from the RTC (or NTP time if the RTC is invalid). After a reboot or
  a schedule change, slots already behind us today are **not** re-run, so a
  restart at 09:01 does not double-turn the 09:00 slot. A slot blocked by a busy
  motor still fires up to 5 min late; beyond that it is skipped and the server's
  missed-run alert covers it.
- With no trustworthy clock at all, scheduled runs do not fire (and the
  heartbeat says why: `rtc_valid: 0`).

### Clock

The DS3231 is the source of truth, synced from NTP on first WiFi and
re-synced when it drifts more than 5 s or reports lost backup power.
`rtc_valid` in the heartbeat is reported honestly. A false "valid" would
disable the server's drift monitoring.

## Network behaviour

| Call | Cadence | Notes |
|---|---|---|
| `GET /bmg/commands` | 1.5 s running / 3.5 s idle | up to 20 per poll; all are handled |
| `POST /bmg/commands/ack` | after actuating | retried until `200`/`404`; never skips ahead past a failure |
| `POST /bmg/heartbeat` | 5 min (30 s retry) | firmware, uptime, RSSI, RTC validity + epoch, last result, motor state |
| `GET /bmg/schedule` | 10 min (60 s retry) | applied only on a new `version` |
| `POST /bmg/runs` | one per run, queued | idempotent on `run_uid` |

- **Commands are deduped by id.** Delivery is at-least-once; if the server
  redelivers a command whose ack was lost, the board re-acks it and never
  re-fires the motor.
- A command that was ignored because the drum was busy is still acked;
  leaving it pending would re-fire it the moment the run ended.
- All requests use a 5 s timeout. Failures back the network task off
  (10 s transport error, 60 s on `429`/`401`/`403`), so a dead server or a
  revoked token never becomes a request storm.
- **Nothing gates a run on the network.** The flash schedule runs first;
  reporting comes after.

## Reporting and recovery

Each finished run is persisted to NVS **before** anything is sent, then posted:

```
POST {API_BASE_URL}/api/v1/devices/bmg/runs
X-Device-Token: dev_<64 hex>
{ "run_uid": "run-<epoch>-<8 hex>", "origin": "scheduled|manual",
  "started_at_epoch": <utc>, "ended_at_epoch": <utc>,
  "duration_seconds": 12, "result": "completed|aborted",
  "turns_count": 21, "note": "" }
```

- Up to 8 unsent runs are held across power cycles (oldest dropped if the queue
  is full). A run is retried every 60 s for 24 h, reusing the same `run_uid`.
- `200`/`201` → `REPORT: SYNC OK`. `409 device.not_bound`, `401`/`403`, `404`,
  `408`, `429` and 5xx are **kept and retried**, because the server side can
  be fixed (bind the board, re-key it). Any other `4xx` is a malformed payload
  and is dropped.
- A run with **no active batch is no longer lost**: the server stores it and
  notifies staff (`/runs` accepts it; the old `turn-sessions` endpoint 409'd).
- **Power loss mid-run is reported.** When the motor starts, a marker is written
  to NVS; it is cleared when the run's report is persisted. If the marker is
  still there at boot, the board reports that run as `aborted` (note:
  *interrupted: device restarted mid-run*), dated by its saved start time.
- A turn-session left in NVS by firmware ≤ 1.1 is delivered once through the
  legacy endpoint, then never used again.

## Serial Monitor (115200)

`s` recipe run · `r` timed run · `x` stop · `i` status line
(state, WiFi, NTP, RTC, schedule version). Look for `COMMAND RECEIVED`,
`COMMAND ACK`, `SCHEDULE: applied vN`, `HEARTBEAT OK`, `REPORT: SYNC OK`.

## Setup

1. Backend — from `backend/`:
   ```bash
   php spark migrate
   php spark db:seed PermissionsAndGroupsSeeder
   ```
   Then either open the web UI (**Facilities — BMG → Devices**, bmg_admin):
   register by chip MAC, bind the drum, copy the token from the shown-once
   dialog — or use the CLI:
   ```bash
   php spark synapse:bmg-device-register --mac b8:1f:3f:d7:ec:18 --unit drum-01
   ```
   Either path prints the device token **exactly once**. Re-registering
   refuses (use `--regenerate` / the Regenerate button to rekey; the machine
   user is reused). Revoke any time with `synapse:bmg-device-revoke <code>`
   or the Disable button.
2. Firmware — the sketch with credentials is **not in the repo** (gitignored).
   Copy the placeholder to the real sketch, then fill in the four config
   values: `WIFI_SSID`, `WIFI_PASS`, `API_BASE_URL` (backend base URL, no
   trailing slash), `DEVICE_TOKEN`. Keep `USE_HTTPS 0` for the LAN prototype.
   ```bash
   cp compost.ino.example compost.ino
   ```
   Upgrading from 1.1: carry over those four values; nothing else in NVS needs
   clearing.
3. Flash — Arduino IDE, board profile for an ESP32-S3 dev module, port
   COM6. Close any Serial Monitor first (it holds the port). The chip has
   auto-reset circuitry, so no manual BOOT button dance is needed. From the
   command line: `arduino-cli compile --fqbn esp32:esp32:esp32s3 bmg_iot/compost`
   (this compiles clean at ~81 % flash, ~14 % RAM).
4. Schedule the backend watchdog (every 15 min) so offline boards, bad clocks,
   missed runs and **commands that never reached a board** are flagged:
   `php spark synapse:bmg-device-watchdog`.

## Manual bench checklist

Boot and recipe
- [ ] Boot prints `BOOT: compost-1.2`, the MAC, `SYSTEM READY`; motor stays stopped.
- [ ] `s` → 5 s countdown, 21 turns, `REPORT: SYNC OK`; the run appears in the
      unit monitor with `origin: manual` and `turns_count: 21`.
- [ ] `x` during turns → motor halts at once; run reported `aborted` with the
      turns actually done.
- [ ] `x` during the 5 s countdown → `PROCESS CANCELLED`; nothing reported.

Commands
- [ ] Start a batch on the bound drum → `COMMAND RECEIVED … 'start'`, recipe runs,
      `COMMAND ACK`.
- [ ] Admin **Run now** → a timed run of the schedule's duration/PWM is actually
      performed (1.1 acked this without moving the motor), `origin: manual`.
- [ ] Cancel/finish the batch → `stop` halts the motor within ~2 s.
- [ ] Block the ack (unplug WiFi right after a command) → on reconnect the board
      re-acks and does **not** run the motor a second time.

Schedule and clock
- [ ] Publish a schedule 2 min ahead → `SCHEDULE: applied vN` within 10 min, the
      slot fires on time with `origin: scheduled`, even with WiFi switched off
      after the schedule was applied.
- [ ] Reboot the board right after a slot → it does not re-run that slot.
- [ ] Reboot mid-run → next boot logs `RECOVERY …` and the server shows an
      `aborted` run.
- [ ] Unplug the DS3231 → heartbeat reports `rtc_valid: 0` and staff get the RTC
      alert.

Server visibility
- [ ] `HEARTBEAT OK` every ~5 min; the unit monitor shows RSSI, uptime, motor state.
- [ ] Switch the board off, start a batch, wait out the 10 min TTL, run the
      watchdog → staff get *"The start command never reached drum …"*.
