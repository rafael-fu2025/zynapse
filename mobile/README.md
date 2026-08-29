# SYNAPSE Mobile

The Flutter client. It consumes the existing CodeIgniter 4 backend (`../backend`) over the same `/api/v1` endpoints as the React SPA (`../frontend`) — no backend changes required. Every request/response shape mirrors `frontend/src/schemas/*` (Zod) and the backend DTOs (`backend/app/Modules/*/DTOs`); most methods carry a comment citing the SPA file or backend route they mirror, which keeps the two clients honest against each other.

Kiosk check-in and kiosk stations intentionally stay on the web app — everything else is here.

## What it covers

| Feature | Endpoint(s) | Notes |
|---|---|---|
| Login / session restore / logout | `POST /auth/login`, `GET /auth/me`, `POST /auth/refresh`, `POST /auth/logout` | JWT + HttpOnly refresh cookie (`synapse_rt`) via a cookie jar, mirroring the browser |
| Dashboard counters | `GET /dashboard/counters` | permission-gated tiles (staff Home tab) |
| Appointments (list + search) | `GET /clinic/appointments` | status filter + text search |
| Student self-booking | `GET /me/student-providers`, `POST /me/student-appointments` | no staff permission needed |
| Queue (public waiting room + my status) | `GET /clinic/queue/state`, `/me/queue-status`, `/me/student-queue-status` | same feed as the lobby TV |
| My portal | `GET /me/employee-profile`, `/me/student-profile`, `/me/clinic-visits` | identity + visit history |
| Patients | `GET /clinic/students`, `/clinic/employees` (+ search) | Students/Employees toggle |
| Inventory (supplies) | `GET /clinic/inventory` | low-stock badges |
| Medicines | `GET /clinic/medicines` | low-stock + expiry chips |
| Counselling | `GET /counselling/sessions` | open/closed status |
| Referrals | `GET /referrals` | status filter + module flow + QR state |
| Facilities (BMG) | `GET /facilities/units` | status + utilization bars |
| Reports | `GET /reports/summary` | selectable date range; chart-rich PDF export |
| Audit trail | `GET /audit/events` | search + keyset pagination |
| Notifications | `GET /notifications`, `POST /notifications/{id}/read` | bell in the app bar + module tile |
| Admin users | `GET /admin/users` | role badges + active status |
| Kiosk media admin (admin only) | `GET/POST /kiosk-*` | settings + media library upload |

## How it's built

- **Auth mirrors the browser exactly.** Access token in `flutter_secure_storage` (keystore/Keychain, with a one-time migration from legacy plaintext). Refresh token in a persistent `PersistCookieJar`. Single-replay silent refresh on 401, proactive refresh when the JWT `exp` approaches, and one in-flight refresh future to deduplicate concurrent 401s. `AuthController.bootstrap()` restores the session before the first frame — splash screen instead of a login flash.
- **State is deliberately boring.** Only `AuthController` is a ChangeNotifier; screens are StatefulWidgets managing their own fetch/loading/error state, with a shared `AutoPolling` mixin (14 screens) gated by tab visibility so hidden tabs skip polls.
- **Three-layer API stack** — `core/config.dart` (URL resolution) → `core/api/api_client.dart` (Dio + interceptors) → `core/services/api_service.dart` (one method per endpoint) → typed models in `core/models/` mirroring the SPA schemas.
- **Navigation** — auth gate in `RootGate`, bottom-nav `IndexedStack` shell, imperative pushes for module screens. Permission gating mirrors the SPA sidebar (wildcard `*` for admins, student-vs-staff variants).

Stack: Flutter (Dart ≥ 3.6, tested on 3.44) · dio 5 + cookie_jar · flutter_secure_storage · provider · Material 3 with a hand-tuned maroon theme and Figtree variable font · pdf + share_plus · image_picker (kiosk media upload) · qr_flutter.

```
lib/
├── main.dart                     app root + theme + RootGate (login vs shell)
├── core/
│   ├── config.dart               API base URL resolution (release requires --dart-define)
│   ├── api/
│   │   ├── api_envelope.dart     {success,data,errors,meta} models + ApiException
│   │   └── api_client.dart       Dio + cookie jar + token + silent refresh
│   ├── models/                   typed DTOs mirroring frontend/src/schemas
│   ├── services/
│   │   ├── auth_controller.dart  ChangeNotifier: session, login/logout/bootstrap
│   │   └── api_service.dart      endpoint methods per module
│   └── utils/                    date (UTC -> Asia/Manila) + notification labels
└── features/
    ├── auth/login_screen.dart
    ├── home/                     home_shell (bottom nav) + home_tab (staff vs student)
    ├── modules/module_hub_screen.dart   grid of all other modules (kiosk excluded)
    ├── dashboard/ · appointments/ · queue/ · notifications/ · portal/
    ├── patients/ · inventory/ · medicines/ · counselling/
    ├── referrals/ · facilities/ · reports/ · audit/ · admin/
    └── common/                   shared cards, error/loading states, AutoPolling
```

## Setup

Prerequisites: Flutter SDK (≥ 3.6, tested on 3.44); the backend on `http://localhost:8090` ([`../backend/README.md`](../backend/README.md)); a demo account — `admin@synapse.dev` / `DevPassw0rd!` (students: `firstname.lastname@foundationu.edu.ph`), full matrix in [`../CREDENTIALS.md`](../CREDENTIALS.md).

```powershell
cd mobile
powershell -ExecutionPolicy Bypass -File setup.ps1
```

`setup.ps1` generates the platform folders (`flutter create .`), fetches deps, and patches the Android manifest for cleartext HTTP (dev only).

> Windows note: native (Android/Windows) builds that bundle plugins may ask for **Developer Mode** ("Building with plugins requires symlink support"). Enable it once in Settings → For developers (`start ms-settings:developers`). The web build doesn't need it.

## Running

```bash
flutter run -d windows     # desktop, backend on localhost:8090
flutter run -d android     # emulator, backend via 10.0.2.2:8090
flutter run -d chrome      # web, backend on localhost:8090

# physical Android device -> point at your machine's LAN IP
flutter run -d <device> --dart-define=API_BASE_URL=http://192.168.1.x:8090/api/v1
```

API base URL resolution lives in `lib/core/config.dart`: dev defaults cover `10.0.2.2` (Android emulator) and `127.0.0.1` (desktop/web), and **release builds throw** unless `API_BASE_URL` is provided via `--dart-define` — no hardcoded URLs ship in release.

## Tests

```bash
flutter test
```

Model JSON-parsing tests (session, appointment, queue, notification) plus `SessionProgressTracker` unit/widget tests. CI runs this on every push/PR ([`../.github/workflows/ci.yml`](../.github/workflows/ci.yml)).

## Platform notes

- **Android** — dev builds allow cleartext HTTP to the dev backend only. `MainActivity` pins a high refresh rate and applies the maroon edge-to-edge treatment (ColorOS-specific workarounds). Release signing is still debug-key with a placeholder `com.example.*` applicationId — **do not distribute release builds as-is**.
- **iOS** — stock template; add photo/camera usage strings before using kiosk media upload.
- **Web / Windows** — supported run targets; the web target is an uncustomized template shell.

## Troubleshooting

- **401 loops** — check `API_BASE_URL` for the device: emulators need `10.0.2.2`, physical devices need the LAN IP.
- **Cleartext HTTP blocked (Android)** — dev-only manifest patch applied by `setup.ps1`; release builds must use HTTPS.
- **Symlink / Developer Mode error on Windows builds** — see the note in [Setup](#setup).
