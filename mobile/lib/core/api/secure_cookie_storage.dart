import 'dart:convert';
import 'dart:io';

import 'package:cookie_jar/cookie_jar.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:path_provider/path_provider.dart';

/// Stores cookie_jar's opaque serialized records together in platform secure
/// storage. One entry makes migration atomic and avoids a plaintext host index.
class SecureCookieStorage implements Storage {
  SecureCookieStorage({
    FlutterSecureStorage secureStorage = const FlutterSecureStorage(),
    this.legacyDirectories = const [],
  }) : _secureStorage = secureStorage;

  static const keyPrefix = 'synapse.cookies.v1';
  final FlutterSecureStorage _secureStorage;

  /// Exact cookie_jar directories, not their parents. Never create legacy files.
  final List<Directory> legacyDirectories;
  late String _key;
  Map<String, String> _records = {};
  Future<void> _pending = Future<void>.value();

  @override
  Future<void> init(bool persistSession, bool ignoreExpires) =>
      _serialize(() async {
        _key = '$keyPrefix.ie${ignoreExpires ? 1 : 0}'
            '_ps${persistSession ? 1 : 0}';
        final stored = await _secureStorage.read(key: _key);
        if (stored != null) {
          _records = Map<String, String>.from(jsonDecode(stored) as Map);
        } else {
          final migrated = <String, String>{};
          for (final directory in legacyDirectories) {
            if (!await directory.exists()) continue;
            await for (final entity in directory.list(followLinks: false)) {
              if (entity is! File) continue;
              final name = entity.uri.pathSegments.last;
              // The support-directory jar takes precedence over the old
              // accidental default jar if both have records for a host.
              if (!migrated.containsKey(name)) {
                migrated[name] = await entity.readAsString();
              }
            }
          }
          await _persist(migrated);
          _records = migrated;
        }
        // A secure entry (including an empty logout tombstone) is authoritative.
        // Retry cleanup on every launch, including after an interrupted migration.
        for (final directory in legacyDirectories) {
          if (await directory.exists()) await directory.delete(recursive: true);
        }
      });

  Future<void> _persist(Map<String, String> records) async {
    final encoded = jsonEncode(records);
    await _secureStorage.write(key: _key, value: encoded);
    if (await _secureStorage.read(key: _key) != encoded) {
      throw StateError('Secure cookie storage verification failed');
    }
  }

  /// Queue read-modify-write operations; CookieManager can save responses from
  /// multiple requests concurrently. Failed writes leave the cached map intact.
  Future<void> _serialize(Future<void> Function() operation) {
    final result = _pending.then((_) => operation());
    _pending = result.then<void>((_) {}, onError: (Object _, StackTrace __) {});
    return result;
  }

  @override
  Future<String?> read(String key) async {
    await _pending;
    return _records[key];
  }

  @override
  Future<void> write(String key, String value) => _serialize(() async {
        final next = {..._records, key: value};
        await _persist(next);
        _records = next;
      });

  @override
  Future<void> delete(String key) => _serialize(() async {
        final next = {..._records}..remove(key);
        await _persist(next);
        _records = next;
      });

  @override
  Future<void> deleteAll(List<String> keys) => _serialize(() async {
        // Keep an empty secure entry so legacy files cannot restore a logged-out
        // session, even if an earlier cleanup was interrupted.
        await _persist({});
        _records = {};
      });
}

/// Eager initialization catches platform/keystore failures before installing the
/// interceptor. Runtime persistence failures propagate; no plaintext fallback.
Future<CookieJar> createSecureCookieJar({
  FlutterSecureStorage secureStorage = const FlutterSecureStorage(),
  Future<List<Directory>> Function()? legacyDirectories,
}) async {
  try {
    final directories = await (legacyDirectories ?? _legacyDirectories)();
    final jar = PersistCookieJar(
      persistSession: true,
      storage: SecureCookieStorage(
        secureStorage: secureStorage,
        legacyDirectories: directories,
      ),
    );
    await jar.forceInit();
    return jar;
  } catch (_) {
    // Do not log platform errors: they can contain serialized credentials.
    return CookieJar();
  }
}

Future<List<Directory>> _legacyDirectories() async {
  final support = await getApplicationSupportDirectory();
  return [
    Directory('${support.path}${Platform.pathSeparator}synapse'
        '${Platform.pathSeparator}ie0_ps1'),
    // Prior startup failures accidentally used PersistCookieJar's default path.
    Directory('.cookies/4/ie0_ps1'),
  ];
}
