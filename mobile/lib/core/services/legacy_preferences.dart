import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Deletes only the obsolete plaintext appointment QR cache. No tokens are
/// restored or migrated; auth and unrelated preferences must remain untouched.
Future<void> purgeLegacyAppointmentQrPreferences() async {
  try {
    final prefs = await SharedPreferences.getInstance();
    final keys = prefs
        .getKeys()
        .where((key) => key.startsWith('synapse_appointment_qr_'))
        .toList();
    for (final key in keys) {
      await prefs.remove(key);
    }
  } catch (_) {
    // Best effort: storage failure must not prevent startup or logout. Retry
    // on the next lifecycle call, without logging any cached credentials.
    if (kDebugMode) debugPrint('Legacy appointment cache cleanup failed.');
  }
}
