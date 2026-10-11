import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:synapse_mobile/core/services/legacy_preferences.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('prefix purge is idempotent and preserves auth and unrelated prefs',
      () async {
    SharedPreferences.setMockInitialValues({
      'synapse_appointment_qr_1': 'obsolete-test-value',
      'synapse_appointment_qr_2': 'obsolete-test-value',
      'synapse_appointment_qr_old': 42,
      'synapse_access_token': 'auth-test-value',
      'synapse_rt': 'refresh-test-value',
      'synapse_patient_qr_1': 'identity-test-value',
      'synapse_appointment_qr': 'similar-but-not-prefix',
      'theme': 'dark',
      'notifications': true,
    });
    await purgeLegacyAppointmentQrPreferences();
    await purgeLegacyAppointmentQrPreferences();
    final prefs = await SharedPreferences.getInstance();
    expect(prefs.getKeys(), {
      'synapse_access_token',
      'synapse_rt',
      'synapse_patient_qr_1',
      'synapse_appointment_qr',
      'theme',
      'notifications',
    });
    expect(prefs.getString('synapse_access_token'), 'auth-test-value');
    expect(prefs.getString('synapse_rt'), 'refresh-test-value');
    expect(prefs.getString('synapse_patient_qr_1'), 'identity-test-value');
    expect(prefs.getString('theme'), 'dark');
    expect(prefs.getBool('notifications'), isTrue);
  });

  test('clean installs need no migration marker or preference changes',
      () async {
    SharedPreferences.setMockInitialValues({'theme': 'dark'});
    await purgeLegacyAppointmentQrPreferences();
    final prefs = await SharedPreferences.getInstance();
    expect(prefs.getKeys(), {'theme'});
    expect(prefs.getString('theme'), 'dark');
  });
}
