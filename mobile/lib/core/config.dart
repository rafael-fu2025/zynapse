import 'package:flutter/foundation.dart'
    show TargetPlatform, defaultTargetPlatform, kIsWeb;

/// App-wide configuration.
///
/// The SYNAPSE backend is a CodeIgniter 4 JSON API mounted under `/api/v1`
/// (see `backend/app/Config/Routes.php`). The local dev server runs on
/// port 8090 (`backend/.env`: `app.baseURL = 'http://localhost:8090/'`).
class AppConfig {
  AppConfig._();

  /// Optional compile-time override, e.g. for a physical device:
  /// `--dart-define=API_BASE_URL=http://192.168.1.10:8090/api/v1`
  static const String _definedBaseUrl = String.fromEnvironment('API_BASE_URL');

  /// Base URL for the SYNAPSE API (including the `/api/v1` prefix).
  static String get apiBaseUrl {
    if (_definedBaseUrl.isNotEmpty) return _definedBaseUrl;
    if (kIsWeb) return 'http://127.0.0.1:8090/api/v1';
    // Android emulators reach the host machine via 10.0.2.2.
    if (defaultTargetPlatform == TargetPlatform.android) {
      return 'http://10.0.2.2:8090/api/v1';
    }
    return 'http://127.0.0.1:8090/api/v1';
  }

  static String absoluteUrl(String path) {
    if (path.startsWith('http://') || path.startsWith('https://')) return path;
    return Uri.parse(apiBaseUrl).resolve(path).toString();
  }

  /// Name of the HttpOnly refresh-token cookie the backend sets
  /// (`backend/.env`: `REFRESH_COOKIE_NAME`).
  static const String refreshCookieName = 'synapse_rt';

  /// Key used to persist the access token in SharedPreferences.
  static const String accessTokenPrefKey = 'synapse_access_token';

  /// Key used to persist a proof-of-booking QR token per appointment.
  ///
  /// The backend returns the plaintext token ONLY at booking/issue time
  /// and stores just the HMAC hash, so without local persistence the app
  /// would have to re-mint (and thereby ROTATE) the token every time the
  /// sheet is reopened — silently invalidating any previously shown QR.
  /// Persisting the issued plaintext keeps the same QR valid across
  /// reopens until the user explicitly taps "Re-issue".
  static String appointmentQrPrefKey(int appointmentId) =>
      'synapse_appointment_qr_$appointmentId';
}
