import 'dart:convert';
import 'dart:io';

import 'package:cookie_jar/cookie_jar.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:synapse_mobile/core/api/secure_cookie_storage.dart';

class TestSecureStorage extends FlutterSecureStorage {
  final values = <String, String>{};
  bool failRead = false;
  bool failWrite = false;
  bool discardWrite = false;

  @override
  Future<String?> read({
    required String key,
    AppleOptions? iOptions,
    AndroidOptions? aOptions,
    LinuxOptions? lOptions,
    WebOptions? webOptions,
    AppleOptions? mOptions,
    WindowsOptions? wOptions,
  }) async {
    if (failRead) throw StateError('Unavailable');
    return values[key];
  }

  @override
  Future<void> write({
    required String key,
    required String? value,
    AppleOptions? iOptions,
    AndroidOptions? aOptions,
    LinuxOptions? lOptions,
    WebOptions? webOptions,
    AppleOptions? mOptions,
    WindowsOptions? wOptions,
  }) async {
    if (failWrite) throw StateError('Unavailable');
    if (!discardWrite) values[key] = value!;
  }
}

void main() {
  const secureKey = '${SecureCookieStorage.keyPrefix}.ie0_ps1';
  final uri = Uri.parse('https://example.test/api/v1/auth/refresh');
  late TestSecureStorage secure;
  late Directory temp;

  setUp(() async {
    secure = TestSecureStorage();
    temp = await Directory.systemTemp.createTemp('synapse_cookie_test_');
  });
  tearDown(() async => temp.delete(recursive: true));

  Future<List<Directory>> noLegacy() async => [];

  Future<Directory> seedLegacy(String name, String value) async {
    final root = Directory('${temp.path}/$name');
    final jar = PersistCookieJar(storage: FileStorage(root.path));
    await jar.saveFromResponse(uri, [
      Cookie('synapse_rt', value)
        ..httpOnly = true
        ..secure = true
        ..path = '/api/v1/auth'
        ..maxAge = 2592000,
    ]);
    return Directory('${root.path}/ie0_ps1');
  }

  test('serializes opaque records and preserves unrelated secure values',
      () async {
    secure.values['access_token'] = 'unrelated';
    final storage = SecureCookieStorage(secureStorage: secure);
    await storage.init(true, false);
    await Future.wait([
      storage.write('.index', '["example.test"]'),
      storage.write('example.test', r'{"quoted":"a\"b"}'),
      storage.write('.domains', '{}'),
    ]);
    final records = jsonDecode(secure.values[secureKey]!) as Map;
    expect(records.length, 3);
    final restored = SecureCookieStorage(secureStorage: secure);
    await restored.init(true, false);
    expect(await restored.read('example.test'), r'{"quoted":"a\"b"}');
    await restored.delete('example.test');
    expect(await restored.read('example.test'), isNull);
    // Delete all must remove even records omitted by cookie_jar's host index.
    await restored.deleteAll(['.index']);
    expect(secure.values[secureKey], '{}');
    expect(secure.values['access_token'], 'unrelated');
  });

  test('cookie round trip retains attributes and supports logout', () async {
    final jar = await createSecureCookieJar(
      secureStorage: secure,
      legacyDirectories: noLegacy,
    );
    expect(jar, isA<PersistCookieJar>());
    await jar.saveFromResponse(uri, [
      Cookie('synapse_rt', 'refresh')
        ..httpOnly = true
        ..secure = true
        ..path = '/api/v1/auth'
        ..maxAge = 2592000,
      Cookie('domain_cookie', 'shared')..domain = 'example.test',
    ]);
    final restored = await createSecureCookieJar(
      secureStorage: secure,
      legacyDirectories: noLegacy,
    );
    final cookies = await restored.loadForRequest(uri);
    final refresh = cookies.singleWhere((c) => c.name == 'synapse_rt');
    expect(refresh.value, 'refresh');
    expect(refresh.httpOnly, isTrue);
    expect(refresh.secure, isTrue);
    expect(refresh.path, '/api/v1/auth');
    expect(cookies.any((c) => c.name == 'domain_cookie'), isTrue);
    expect(
      (await restored.loadForRequest(Uri.parse('http://example.test/')))
          .any((c) => c.name == 'synapse_rt'),
      isFalse,
    );
    await restored.deleteAll();
    final loggedOut = await createSecureCookieJar(
      secureStorage: secure,
      legacyDirectories: noLegacy,
    );
    expect(await loggedOut.loadForRequest(uri), isEmpty);
  });

  test('migrates real legacy jars, verifies secure copy, removes plaintext',
      () async {
    final primary = await seedLegacy('support', 'primary');
    final accidental = await seedLegacy('default', 'stale');
    final jar = await createSecureCookieJar(
      secureStorage: secure,
      legacyDirectories: () async => [primary, accidental],
    );
    expect((await jar.loadForRequest(uri)).single.value, 'primary');
    expect(await primary.exists(), isFalse);
    expect(await accidental.exists(), isFalse);
    final restored = await createSecureCookieJar(
      secureStorage: secure,
      legacyDirectories: noLegacy,
    );
    expect((await restored.loadForRequest(uri)).single.value, 'primary');
  });

  test('secure logout tombstone prevents legacy session resurrection',
      () async {
    final legacy = await seedLegacy('support', 'stale');
    secure.values[secureKey] = '{}';
    final jar = await createSecureCookieJar(
      secureStorage: secure,
      legacyDirectories: () async => [legacy],
    );
    expect(await jar.loadForRequest(uri), isEmpty);
    expect(await legacy.exists(), isFalse);
  });

  for (final failure in ['read', 'write', 'verification']) {
    test('$failure failure keeps legacy files and uses memory only', () async {
      final legacy = await seedLegacy('support', 'retained');
      secure.failRead = failure == 'read';
      secure.failWrite = failure == 'write';
      secure.discardWrite = failure == 'verification';
      final jar = await createSecureCookieJar(
        secureStorage: secure,
        legacyDirectories: () async => [legacy],
      );
      expect(jar, isNot(isA<PersistCookieJar>()));
      expect(await jar.loadForRequest(uri), isEmpty);
      await jar.saveFromResponse(uri, [Cookie('synapse_rt', 'memory')]);
      expect((await jar.loadForRequest(uri)).single.value, 'memory');
      expect(await legacy.exists(), isTrue);
      secure.failRead = secure.failWrite = secure.discardWrite = false;
      final retry = await createSecureCookieJar(
        secureStorage: secure,
        legacyDirectories: () async => [legacy],
      );
      expect((await retry.loadForRequest(uri)).single.value, 'retained');
      expect(await legacy.exists(), isFalse);
    });
  }

  test('path lookup failure uses memory only', () async {
    final jar = await createSecureCookieJar(
      secureStorage: secure,
      legacyDirectories: () async =>
          throw const FileSystemException('Unavailable'),
    );
    expect(jar, isNot(isA<PersistCookieJar>()));
    expect(secure.values, isEmpty);
  });

  test('runtime write failure propagates without changing cached records',
      () async {
    final storage = SecureCookieStorage(secureStorage: secure);
    await storage.init(true, false);
    await storage.write('host', 'before');
    secure.failWrite = true;
    await expectLater(storage.write('host', 'after'), throwsStateError);
    expect(await storage.read('host'), 'before');
    secure.failWrite = false;
    await storage.write('host', 'retry');
    expect(await storage.read('host'), 'retry');
  });
}
