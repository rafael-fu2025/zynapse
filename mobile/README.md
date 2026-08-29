# SYNAPSE Mobile (Flutter demo)

A cross-platform Flutter client that talks to the **existing CodeIgniter 4
backend** (`../backend`) over its JSON API (`/api/v1`). No backend changes are
required — this app consumes the same endpoints as the React SPA in `../frontend`.

## Module coverage

All SYNAPSE modules are covered except **kiosk check-in / kiosk stations**,
which intentionally stay on the web app.

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
| Reports | `GET /reports/summary` | selectable date range |
| Audit trail | `GET /audit/events` | search + keyset pagination |
| Notifications | `GET /notifications`, `POST /notifications/{id}/read` | bell in the app bar + module tile |
| Admin users | `GET /admin/users` | role badges + active status |

All request/response shapes mirror `frontend/src/schemas/*` (Zod) and the
backend DTOs (`backend/app/Modules/*/DTOs`).

## Prerequisites

- Flutter SDK (>= 3.6, tested on 3.44) — https://docs.flutter.dev/get-started/install
- The backend running on `http://localhost:8090` (see `../backend/README.md`;
  `php spark serve` or the `../scripts/dev-up.ps1` helper).
- Demo account: `admin@synapse.dev` / `DevPassw0rd!`
  (students: `firstname.lastname@foundationu.edu.ph` / `DevPassw0rd!`).

## Setup

```powershell
cd mobile
powershell -ExecutionPolicy Bypass -File setup.ps1
```

This generates the platform folders (`flutter create .`), fetches deps, and
patches the Android manifest for cleartext HTTP (dev only).

> Windows note: native (Android/Windows) builds that bundle plugins may ask
> for **Developer Mode** ("Building with plugins requires symlink support").
> Enable it once in Settings → For developers (`start ms-settings:developers`).
> The web build does not require it.

## Run

```bash
flutter run -d windows     # desktop, backend on localhost:8090
flutter run -d android     # emulator, backend via 10.0.2.2:8090
flutter run -d chrome      # web, backend on localhost:8090

# physical Android device -> point at your machine's LAN IP
flutter run -d <device> --dart-define=API_BASE_URL=http://192.168.1.x:8090/api/v1
```

## Layout

```
lib/
  main.dart                     app root + theme + RootGate (login vs shell)
  core/
    config.dart                 API base URL resolution
    api/
      api_envelope.dart         {success,data,errors,meta} models + ApiException
      api_client.dart           Dio + cookie jar + token + silent refresh
    models/                     typed DTOs mirroring frontend/src/schemas
    services/
      auth_controller.dart      ChangeNotifier: session, login/logout/bootstrap
      api_service.dart          endpoint methods per module
    utils/                      date (UTC -> Asia/Manila) + notification labels
  features/
    auth/login_screen.dart
    home/home_shell.dart        bottom-nav shell (Home / Appointments / Queue / My Portal / Modules)
    home/home_tab.dart          staff -> Dashboard, students -> portal
    modules/module_hub_screen.dart   grid of all other modules (kiosk excluded)
    dashboard/dashboard_screen.dart
    appointments/appointments_screen.dart
    queue/queue_screen.dart
    notifications/notifications_screen.dart
    portal/portal_screen.dart
    patients/patients_screen.dart
    inventory/inventory_screen.dart
    medicines/medicines_screen.dart
    counselling/counselling_screen.dart
    referrals/referrals_screen.dart
    facilities/facilities_screen.dart
    reports/reports_screen.dart
    audit/audit_screen.dart
    admin/admin_users_screen.dart
    common/widgets.dart         shared cards, error/loading states
```
