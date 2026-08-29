import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:cookie_jar/cookie_jar.dart';
import 'package:dio/dio.dart';
import 'package:dio_cookie_manager/dio_cookie_manager.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../config.dart';
import 'api_envelope.dart';

/// Thin, dependency-light shim so callers can map a raw [DioException]
/// back to an [ApiException] (the response interceptor attaches the
/// envelope error to `DioException.error`).
///
/// Mirrors the axios normalizer in `frontend/src/api/client.ts`.
ApiException mapDioError(Object error, {int fallbackStatus = 0}) {
  if (error is ApiException) return error;
  if (error is DioException) {
    final inner = error.error;
    if (inner is ApiException) return inner;
    return ApiException(
      error.response?.statusCode ?? fallbackStatus,
      [
        ApiError(
          code: 'network.unreachable',
          message: error.message ?? 'Network error',
        ),
      ],
    );
  }
  return ApiException(
    fallbackStatus,
    [ApiError(code: 'unknown', message: '$error')],
  );
}

/// The single HTTP client for the SYNAPSE API.
///
/// Behaviour intentionally mirrors the browser flow of the React SPA:
///   * Bearer access token is attached on every request.
///   * A cookie jar persists the HttpOnly `synapse_rt` refresh cookie set
///     at login, so `POST /auth/refresh` works without manual storage.
///   * On 401, a single silent refresh is attempted and the original
///     request is replayed once. If refresh fails the session is expired.
///   * Envelope-shaped error bodies are surfaced as [ApiException].
class ApiClient {
  ApiClient._() {
    _dio = Dio(
      BaseOptions(
        baseUrl: AppConfig.apiBaseUrl,
        connectTimeout: const Duration(seconds: 10),
        receiveTimeout: const Duration(seconds: 20),
        headers: const {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      ),
    );

    // Refresh cookie persistence — the Dio cookie jar acts like the
    // browser's cookie store for `synapse_rt`. The jar is created lazily
    // via [initPersistentCookieJar] (needs an async path_provider lookup),
    // so the HttpOnly refresh cookie survives app relaunch — see that
    // method for why the old in-memory jar lost the session on restart.
    // (The CookieManager interceptor is inserted there, before requests.)

    _dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          final token = _accessToken;
          if (token != null && token.isNotEmpty) {
            options.headers['Authorization'] = 'Bearer $token';
          }
          // Per-request id so backend log lines correlate with the app
          // (the SPA sends an X-Request-Id too).
          options.headers['X-Request-Id'] = _newRequestId();
          handler.next(options);
        },
        onError: (error, handler) async {
          final status = error.response?.statusCode ?? 0;

          // 401 -> refresh once + replay (never loop: the refresh request
          // itself carries `_synapseRetried`, so a failed refresh does not
          // recurse). NOTE: this MUST run BEFORE the envelope conversion
          // below — the backend returns 401 as an envelope body, so a
          // body-first check short-circuits the refresh and the session is
          // lost as soon as the access token expires (the Phase-14 bug).
          if (status == 401 &&
              error.requestOptions.extra['_synapseRetried'] != true) {
            error.requestOptions.extra['_synapseRetried'] = true;
            final ok = await refreshAccessToken();
            if (ok) {
              final token = _accessToken;
              if (token != null && token.isNotEmpty) {
                error.requestOptions.headers['Authorization'] =
                    'Bearer $token';
              }
              try {
                final resp = await _dio.fetch<dynamic>(error.requestOptions);
                handler.resolve(resp);
                return;
              } on DioException catch (re) {
                handler.next(re);
                return;
              }
            }
            // Refresh failed — the session is genuinely expired. Fall
            // through to the envelope conversion so callers get a clean
            // ApiException, and notify the auth controller to log out.
            _onSessionExpired?.call();
          }

          // Envelope-shaped error body -> ApiException.
          final body = error.response?.data;
          if (body is Map<String, dynamic>) {
            final raw = body['errors'];
            if (raw is List && raw.isNotEmpty) {
              final errors = raw
                  .whereType<Map<String, dynamic>>()
                  .map(ApiError.fromJson)
                  .toList();
              if (errors.isNotEmpty) {
                handler.next(
                  DioException(
                    requestOptions: error.requestOptions,
                    response: error.response,
                    type: error.type,
                    error: ApiException(status, errors),
                  ),
                );
                return;
              }
            }
          }

          handler.next(error);
        },
      ),
    );
  }

  static final ApiClient I = ApiClient._();

  late final Dio _dio;
  String? _accessToken;
  Future<bool>? _inFlightRefresh;
  VoidCallback? _onSessionExpired;

  /// The persistent cookie jar backing the Dio `CookieManager`. Created
  /// asynchronously by [initPersistentCookieJar] because it needs a
  /// path_provider directory; null until then.
  PersistCookieJar? _cookieJar;
  Future<void>? _cookieJarInit;

  /// The configured Dio instance (base URL already includes `/api/v1`).
  Dio get dio => _dio;

  /// Current bearer access token (null when logged out).
  String? get accessToken => _accessToken;

  /// Registers a callback fired when a silent refresh fails (session
  /// expired). The [AuthController] uses this to clear the UI session.
  void registerSessionExpired(VoidCallback callback) {
    _onSessionExpired = callback;
  }

  /// Replaces the bearer token and persists it in the platform
  /// keystore/Keychain (flutter_secure_storage) — SharedPreferences is
  /// plaintext XML and this token is a live credential for a clinical
  /// system. Pass `null` to clear.
  ///
  /// Also performs a one-time migration: installs predating secure
  /// storage kept the token in SharedPreferences; it is read once,
  /// moved, and the plaintext copy is deleted.
  Future<void> setAccessToken(String? token) async {
    _accessToken = token;
    // flutter_secure_storage 10+ encrypts Android storage by default
    // (the old EncryptedSharedPreferences opt-in was removed upstream).
    const storage = FlutterSecureStorage();
    if (token == null) {
      await storage.delete(key: AppConfig.accessTokenPrefKey);
      return;
    }
    await storage.write(key: AppConfig.accessTokenPrefKey, value: token);
  }

  /// Restores a previously persisted token (app cold start).
  Future<String?> restoreToken() async {
    // flutter_secure_storage 10+ encrypts Android storage by default
    // (the old EncryptedSharedPreferences opt-in was removed upstream).
    const storage = FlutterSecureStorage();
    _accessToken = await storage.read(key: AppConfig.accessTokenPrefKey);
    if (_accessToken != null) return _accessToken;

    // One-time migration from the old plaintext SharedPreferences copy.
    final prefs = await SharedPreferences.getInstance();
    final legacy = prefs.getString(AppConfig.accessTokenPrefKey);
    if (legacy != null && legacy.isNotEmpty) {
      _accessToken = legacy;
      await storage.write(key: AppConfig.accessTokenPrefKey, value: legacy);
      await prefs.remove(AppConfig.accessTokenPrefKey);
    }
    return _accessToken;
  }

  /// True when the persisted access token's JWT `exp` has already passed
  /// (with a 30s safety margin for clock skew). Used by the auth bootstrap
  /// to refresh PROACTIVELY on cold start, so an expired token costs one
  /// round trip (`/auth/refresh` → `/auth/me`) instead of the slower
  /// `/auth/me` → 401 → refresh → replay cascade (three round trips).
  /// Returns false when the token can't be parsed — the normal 401 flow
  /// still handles that case.
  bool isTokenExpired(String token) {
    try {
      final parts = token.split('.');
      if (parts.length < 2) return false;
      final payload =
          utf8.decode(base64Url.decode(base64Url.normalize(parts[1])));
      final map = jsonDecode(payload) as Map<String, dynamic>;
      final exp = map['exp'];
      if (exp is! int) return false;
      final now = DateTime.now().millisecondsSinceEpoch ~/ 1000;
      return exp <= now + 30;
    } catch (e) {
      if (kDebugMode) debugPrint('ApiClient.isTokenExpired failed: $e');
      return false;
    }
  }

  /// Builds the persistent cookie jar backing the Dio `CookieManager`.
  ///
  /// The backend sets the HttpOnly `synapse_rt` refresh cookie at login
  /// (30-day expiry) and it is the ONLY way to mint a fresh access token
  /// after the 900s access-token TTL. A plain in-memory `CookieJar()`
  /// (the previous behaviour) discarded the cookie on app relaunch, so
  /// once the token expired every request 401'd and silent refresh was
  /// impossible — the session was lost exactly like the Phase-14
  /// "Verify chain could not be completed" failure. This jar persists to
  /// app storage (path_provider) so the refresh cookie survives relaunch,
  /// mirroring the browser's cookie store. Idempotent; safe to call on
  /// every bootstrap.
  Future<void> initPersistentCookieJar() {
    return _cookieJarInit ??= _doInitPersistentCookieJar();
  }

  Future<void> _doInitPersistentCookieJar() async {
    try {
      final dir = await getApplicationSupportDirectory();
      final jar = PersistCookieJar(
        persistSession: true, // persist the 30-day refresh cookie.
        storage: FileStorage('${dir.path}${Platform.pathSeparator}synapse'),
      );
      _cookieJar = jar;
      // CookieManager must be the FIRST interceptor so the refresh cookie
      // is attached before the auth/Bearer wrapper runs (same ordering as
      // the original in-memory jar, which sat at index 0).
      _dio.interceptors.insert(0, CookieManager(jar));
    } catch (e) {
      // path_provider failure — fall back to a best-effort in-memory jar
      // so the app still works within a single run (same as before).
      if (kDebugMode) debugPrint('ApiClient.initCookieJar failed: $e');
      final jar = PersistCookieJar(persistSession: true);
      _cookieJar = jar;
      _dio.interceptors.insert(0, CookieManager(jar));
    }
  }

  /// Clears any cached refresh cookie (logout / session expiry).
  Future<void> clearRefreshCookie() async {
    final jar = _cookieJar;
    if (jar == null) return;
    try {
      await jar.deleteAll();
    } catch (e) {
      // best effort
      if (kDebugMode) debugPrint('ApiClient.clearRefreshCookie failed: $e');
    }
  }

  /// Silently mints a new access token via the refresh cookie. Returns
  /// true on success. Only one refresh runs at a time; concurrent callers
  /// share the in-flight future (mirrors `inflightRefresh` in the SPA).
  Future<bool> refreshAccessToken() {
    final inflight = _inFlightRefresh;
    if (inflight != null) return inflight;
    final f = _doRefresh();
    _inFlightRefresh = f;
    f.whenComplete(() => _inFlightRefresh = null);
    return f;
  }

  Future<bool> _doRefresh() async {
    try {
      final res = await _dio.post<Map<String, dynamic>>(
        '/auth/refresh',
        options: Options(extra: {'_synapseRetried': true}),
      );
      final data = res.data?['data'];
      if (data is Map<String, dynamic>) {
        final token = data['access_token'] as String?;
        if (token != null && token.isNotEmpty) {
          await setAccessToken(token);
          return true;
        }
      }
      return false;
    } catch (e) {
      if (kDebugMode) debugPrint('ApiClient.refreshAccessToken failed: $e');
      return false;
    }
  }

  static final Random _rand = Random();
  static String _newRequestId() {
    final r = _rand.nextInt(1 << 32);
    return '${DateTime.now().millisecondsSinceEpoch.toRadixString(16)}-$r';
  }
}
