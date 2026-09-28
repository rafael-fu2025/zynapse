# compost.ino — mechanized BMG compost tumbler controller

ESP32-S3 firmware that turns the compost drum through a fixed sequence and
reports each completed session to the Synapse BMG module over WiFi.

**Board (verified via esptool on this unit):** ESP32-S3 rev v0.2, 16 MB quad
flash + 8 MB embedded PSRAM, CH343 USB-UART — a SuperMini-style dev board.
Serial port on this machine: **COM6**. Chip MAC: `b8:1f:3f:d7:ec:18`
(esptool reports it; it is also the default device code at registration).

The sketch's GPIO map is valid on ESP32-S3 as-is: GPIO 8/9 are I2C for
the DS3231 RTC, GPIO 4/5/6 drive the motor H-bridge. The original
sketch's I2C LCD is removed (not part of the prototype) — all status
goes to the **Serial Monitor at 115200**. Do **not** port it to a
classic ESP32 (WROOM-32): its flash pins overlap GPIO 6–11 territory
and the sketch would crash.

**Dependencies:** Adafruit **RTClib** (auto-installs Adafruit BusIO) —
already installed on this machine via arduino-cli; other machines need
one Library Manager install. WiFi, HTTPClient, and Preferences are
bundled with the ESP32 core.

## Wiring

| Signal | GPIO | Connects to |
|---|---|---|
| I2C SDA | 8 | DS3231 RTC |
| I2C SCL | 9 | DS3231 RTC |
| Motor IN1 | 4 | H-bridge input 1 |
| Motor IN2 | 5 | H-bridge input 2 |
| Motor EN  | 6 | H-bridge enable (PWM, 180/255 ≈ 70 %) |

## Sequence (prototype values — do not tune casually)

`5 s` start delay → **3 sets × 7 timed turns** (`1.2 s` motor on, `0.3 s`
gap) with `10 s` pause between sets. All constants are compile-time and
treated as prototype bench values; the reported `turns_count` /
`duration_seconds` are **computed from the constants**, so changing the
recipe keeps the report truthful.

## Reporting

When the sequence reaches DONE the device builds one session payload and
POSTs it:

```
POST {API_BASE_URL}/api/v1/devices/bmg/turn-sessions
X-Device-Token: dev_<64 hex>
{ "session_uid": "<32 hex>", "turns_count": 21, "sets_count": 3,
  "duration_seconds": 50, "firmware": "compost-1.1" }
```

- `2xx` → Serial shows `REPORT: SYNC OK`; the backend writes a `turning`
  process-log row on the drum's active batch.
- `4xx` (409 no active batch, 401 revoked token, 422) → Serial shows
  `REPORT: permanent failure <code>`; the session is dropped — retrying
  cannot fix it.
- Network failure / 5xx → Serial shows `REPORT: SYNC QUEUED`; the payload
  is kept in NVS and retried every 60 s for 24 h. It survives a power
  cycle (queue depth: 1 — a new session replaces an undeliverable old one
  after one flush attempt).

Reporting only runs while the motor is idle (DONE): WiFi connect and HTTP
are blocking, and the pump is deliberately never driven during the turn
sequence, so reporting cannot stall the state machine's timing.

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
3. Flash — Arduino IDE, board profile for an ESP32-S3 dev module, port
   COM6. Close any Serial Monitor first (it holds the port). The chip has
   auto-reset circuitry, so no manual BOOT button dance is needed.

## Manual bench checklist (there is no CI gate for firmware)

- [ ] Fresh power-up → sequence runs (3 × 7 turns) → `PROCESS COMPLETE`
      on Serial.
- [ ] WiFi + backend up → Serial `REPORT: SYNC OK`; the drum's detail
      page shows a `turning` row with the device badge, `⚙ 21 rotations · 50s`.
- [ ] Backend down during session → `REPORT: SYNC QUEUED`; bring the
      backend up → row appears within a minute (idempotent replay returns
      the original row).
- [ ] Unit with no active batch → `REPORT: permanent failure 409`; starting
      a batch and running the device again succeeds.
- [ ] `synapse:bmg-device-revoke` → next report gets 401 → `permanent failure 401`.
- [ ] Power cut mid-sequence → on next boot the queued session (if any)
      flushes after the next completed run.
