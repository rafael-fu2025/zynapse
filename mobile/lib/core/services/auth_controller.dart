import 'package:flutter/foundation.dart';

import '../api/api_client.dart';
import '../api/api_envelope.dart';
import '../models/session.dart';

/// Holds the authenticated session and drives the auth lifecycle.
///
/// Mirrors `frontend/src/store/auth.ts` + `useAuth.ts` + the bootstrap in
/// `useBootstrapSession.ts`:
///   1. On cold start, [bootstrap] restores the persisted access token and
///      validates it via `/auth/me` (a 401 triggers the silent refresh in
///      [ApiClient], which re-mints from the refresh cookie).
///   2. [login] calls `/auth/login` (returns the access token + sets the
///      refresh cookie), then hydrates the session from `/auth/me`.
///   3. [logout] revokes the refresh family server-side and clears local
///      state.
class AuthController extends ChangeNotifier {
  AuthController._() {
    ApiClient.I.registerSessionExpired(_expireSession);
  }

  static final AuthController I = AuthController._();

  Session? _session;
  bool _booting = true;
  bool _busy = false;

  Session? get session => _session;
  bool get isLoggedIn => _session != null;
  bool get isBooting => _booting;
  bool get isBusy => _busy;

  /// Restores a persisted session at app start.
  Future<Session?> bootstrap() async {
    _booting = true;
    _busy = true;
    notifyListeners();
    try {
      // Kick off the two cheap local initializations CONCURRENTLY (cookie
      // jar + token restore are independent disk reads), so the network
      // validation starts as soon as possible and the splash is shorter.
      final jarInit = ApiClient.I.initPersistentCookieJar();
      final token = await ApiClient.I.restoreToken();
      if (token == null || token.isEmpty) {
        _session = null;
        return null;
      }
      await jarInit; // cookie jar ready before any network request.

      // If the persisted access token already expired, refresh FIRST (one
      // round trip) instead of the slower /auth/me → 401 → refresh →
      // replay cascade (three round trips) on every cold start.
      if (ApiClient.I.isTokenExpired(token)) {
        final refreshed = await ApiClient.I.refreshAccessToken();
        if (!refreshed) {
          _session = null;
          return null;
        }
      }
      try {
        final res = await ApiClient.I.dio.get<Map<String, dynamic>>('/auth/me');
        final data = res.data?['data'];
        if (data is Map<String, dynamic>) {
          _session = Session.fromJson(data);
        }
      } catch (_) {
        // Token expired and refresh failed -> stay logged out.
        _session = null;
      }
      return _session;
    } finally {
      _booting = false;
      _busy = false;
      notifyListeners();
    }
  }

  /// Exchanges credentials for a session.
  Future<Session> login(String email, String password) async {
    _busy = true;
    notifyListeners();
    try {
      // Make sure the persistent cookie jar exists so the `synapse_rt`
      // Set-Cookie from this login response is captured to disk (defensive
      // — bootstrap already initialises it, but a direct login is possible).
      await ApiClient.I.initPersistentCookieJar();
      final res = await ApiClient.I.dio.post<Map<String, dynamic>>(
        '/auth/login',
        data: {'email': email.trim(), 'password': password},
      );
      final data = res.data?['data'];
      if (data is! Map<String, dynamic>) {
        throw ApiException(500, [
          ApiError(code: 'auth.unexpected', message: 'Unexpected login response'),
        ]);
      }
      final token = data['access_token'] as String?;
      if (token == null || token.isEmpty) {
        throw ApiException(500, [
          ApiError(code: 'auth.no_token', message: 'No access token returned'),
        ]);
      }
      await ApiClient.I.setAccessToken(token);

      final me = await ApiClient.I.dio.get<Map<String, dynamic>>('/auth/me');
      final meData = me.data?['data'];
      if (meData is! Map<String, dynamic>) {
        throw ApiException(500, [
          ApiError(code: 'auth.no_session', message: 'Could not load session'),
        ]);
      }
      _session = Session.fromJson(meData);
      return _session!;
    } finally {
      _busy = false;
      notifyListeners();
    }
  }

  /// Revokes the refresh family and clears local session state.
  Future<void> logout() async {
    try {
      await ApiClient.I.dio.post('/auth/logout');
    } catch (_) {
      // best effort — revoke may fail if the token already expired.
    }
    await ApiClient.I.setAccessToken(null);
    await ApiClient.I.clearRefreshCookie();
    _session = null;
    notifyListeners();
  }

  /// Self-service password change — the backend rotates the token pair and
  /// revokes all other sessions.
  Future<void> changePassword({
    required String currentPassword,
    required String newPassword,
  }) async {
    final res = await ApiClient.I.dio.post<Map<String, dynamic>>(
      '/auth/change-password',
      data: {
        'current_password': currentPassword,
        'new_password': newPassword,
      },
    );
    final data = res.data?['data'];
    if (data is Map<String, dynamic>) {
      final token = data['access_token'] as String?;
      if (token != null && token.isNotEmpty) {
        await ApiClient.I.setAccessToken(token);
      }
    }
    // Refresh the session (clears force_reset).
    final me = await ApiClient.I.dio.get<Map<String, dynamic>>('/auth/me');
    final meData = me.data?['data'];
    if (meData is Map<String, dynamic>) {
      _session = Session.fromJson(meData);
      notifyListeners();
    }
  }

  void _expireSession() {
    if (_session != null) {
      _session = null;
      notifyListeners();
    }
  }
}
