import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:synapse_mobile/core/api/api_client.dart';
import 'package:synapse_mobile/core/models/appointment.dart';
import 'package:synapse_mobile/core/models/profile.dart';
import 'package:synapse_mobile/core/services/api_service.dart';
import 'package:synapse_mobile/core/services/auth_controller.dart';
import 'package:synapse_mobile/features/appointments/appointments_screen.dart';

Map<String, dynamic> appointmentRow({String status = 'scheduled'}) => {
      'id': 57,
      'patient_school_id': '20261970',
      'patient_name': 'Test Patient',
      'patient_kind': 'student',
      'provider_user_id': 54,
      'provider_name': 'Test Provider',
      'scheduled_at': '2026-10-05 01:00:00',
      'status': status,
      'reason': 'Check-up',
      'encounter_id': status == 'checked_in' ? 91 : null,
      'created_at': '2026-10-04 01:00:00',
    };

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  final requests = <RequestOptions>[];
  var student = false;
  var status = 'scheduled';
  var returnLegacyFields = false;
  late Interceptor mockApi;

  setUp(() async {
    requests.clear();
    status = 'scheduled';
    student = false;
    returnLegacyFields = false;
    FlutterSecureStorage.setMockInitialValues({
      'synapse_access_token': 'test-session',
    });
    SharedPreferences.setMockInitialValues({
      'synapse_appointment_qr_57': 'obsolete-test-value',
      'synapse_access_token': 'unrelated-auth-pref',
      'theme': 'dark',
    });
    mockApi = InterceptorsWrapper(onRequest: (options, handler) {
      requests.add(options);
      final path = options.path;
      Object? data;
      if (path == '/auth/me') {
        data = {
          'id': 1,
          'permissions': student ? ['student.portal.read'] : ['*'],
        };
      } else if (path == '/auth/logout') {
        data = <String, dynamic>{};
      } else if (path == '/scheduling/staff/clinic/providers') {
        data = [
          {
            'id': 54,
            'name': 'Test Provider',
            'position': 'Nurse',
            'employee_number': 'E-54',
          },
        ];
      } else if (path == '/clinic/employees') {
        data = [
          {'id': 54, 'first_name': 'Test', 'last_name': 'Provider'},
        ];
      } else if (path == '/me/appointment-slots') {
        data = {
          'slots': [
            {
              'department': 'clinic',
              'starts_at': '2026-10-05T01:00:00Z',
              'ends_at': '2026-10-05T02:00:00Z',
              'duration_minutes': 60,
              'remaining': 1,
            },
          ],
        };
      } else if (path == '/clinic/patients/lookup') {
        data = [
          {
            'id': 42,
            'kind': 'student',
            'name': 'Test Patient',
            'school_id': '20261970',
          },
        ];
      } else if (path == '/clinic/appointments/57/transition') {
        status = (options.data as Map)['status'] as String;
        data = appointmentRow(status: status);
      } else if (path == '/me/appointments') {
        data = <String, dynamic>{};
      } else if (path == '/clinic/appointments' ||
          path == '/me/student-appointments') {
        final row = appointmentRow(status: status);
        if (returnLegacyFields) {
          // Ignore old server fields even if their types are no longer valid.
          row['qr_token'] = {'obsolete': true};
        }
        data = options.method == 'GET' ? [row] : row;
      } else {
        handler.reject(DioException(
          requestOptions: options,
          error: StateError('Unexpected endpoint: $path'),
        ));
        return;
      }
      handler.resolve(Response(
        requestOptions: options,
        statusCode: 200,
        data: {'success': true, 'data': data},
      ));
    });
    ApiClient.I.dio.interceptors.add(mockApi);
  });

  tearDown(() {
    ApiClient.I.dio.interceptors.remove(mockApi);
  });

  Future<void> mountScreen(WidgetTester tester,
      {bool asStudent = false}) async {
    student = asStudent;
    // Bootstrap outside fake async: cookie storage may use native disk IO.
    await tester.runAsync(() => AuthController.I.bootstrap());
    await tester.pumpWidget(ChangeNotifierProvider.value(
      value: AuthController.I,
      child: const MaterialApp(home: AppointmentsScreen()),
    ));
    await tester.pumpAndSettle();
  }

  Future<void> unmountScreen(WidgetTester tester) async {
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pumpAndSettle(); // cancels the screen's polling timer
  }

  void expectNoAppointmentQr() {
    expect(find.byTooltip('Show appointment QR'), findsNothing);
    expect(find.text('Appointment QR'), findsNothing);
    expect(find.text('Issue QR'), findsNothing);
    expect(find.text('Re-issue'), findsNothing);
    expect(requests.where((r) => r.path.endsWith('/qr')), isEmpty);
    expect(requests.where((r) => r.path == '/appointments/verify'), isEmpty);
  }

  test('appointment models tolerate QR-free and legacy responses', () {
    final a = Appointment.fromJson(appointmentRow(status: 'checked_in'));
    expect(a.encounterId, 91);
    expect(a.patientLabel, 'Test Patient (#20261970)');
    expect(a.providerLabel, 'Test Provider');
    final legacy = Appointment.fromJson({
      ...appointmentRow(),
      'qr_token': {'obsolete': true},
    });
    expect(legacy.status, 'scheduled');
  });

  test('patient identity QR survives while RFID payload fields are ignored',
      () {
    final profile = UserProfile.fromJson({
      'id': 42,
      'kind': 'student',
      'has_qr': true,
      'has_rfid': {'obsolete': true},
      'consecutive_no_shows': 2,
    });
    expect(profile.hasQr, isTrue);
    expect(profile.consecutiveNoShows, 2);
    expect(profile.isStudent, isTrue);
    expect(UserProfile.fromJson({'id': 54}).hasQr, isFalse);
  });

  test(
      'appointment API keeps lists, booking, scheduling and manual transitions',
      () async {
    for (final legacy in [false, true]) {
      returnLegacyFields = legacy;
      expect((await ApiService.I.appointments()).items.single.id, 57);
      expect((await ApiService.I.myStudentAppointments()).single.id, 57);
      final slots = await ApiService.I.portalAppointmentSlots(
        'clinic',
        '2026-10-05',
      );
      expect(slots.single.remaining, 1);
      await ApiService.I.bookPortalAppointment(
        slots.single,
        reason: 'Check-up',
      );
      expect(requests.last.path, '/me/appointments');
      expect(requests.last.data, {
        'department': 'clinic',
        'starts_at': '2026-10-05T01:00:00.000Z',
        'type': 'initial',
        'reason': 'Check-up',
      });
      final providers = await ApiService.I.schedulingProviders('clinic');
      expect(providers.single.name, 'Test Provider');
      final scheduled = await ApiService.I.scheduleAppointment(
        patientSchoolId: '20261970',
        providerUserId: 54,
        scheduledAt: '2026-10-05 01:00:00',
      );
      expect(scheduled.id, 57);
      expect(requests.last.data, {
        'patient_school_id': '20261970',
        'provider_user_id': 54,
        'scheduled_at': '2026-10-05 01:00:00',
      });
    }
    for (final next in [
      'confirmed',
      'checked_in',
      'completed',
      'cancelled',
      'no_show',
    ]) {
      final updated =
          await ApiService.I.appointmentTransition(id: 57, status: next);
      expect(updated.status, next);
      expect(requests.last.data, {'status': next});
      if (next == 'checked_in') expect(updated.encounterId, 91);
    }
    expect(requests.every((r) => !r.path.endsWith('/qr')), isTrue);
    expect(requests.any((r) => r.path == '/appointments/verify'), isFalse);
  });

  testWidgets(
      'staff cards keep status actions without an appointment QR button',
      (tester) async {
    await mountScreen(tester);
    expectNoAppointmentQr();
    await tester.tap(find.byTooltip('Appointment actions'));
    await tester.pumpAndSettle();
    for (final label in ['Confirm', 'Check in', 'Cancel', 'No show']) {
      expect(find.text(label), findsOneWidget);
    }
    await tester.tap(find.text('Check in'));
    await tester.pumpAndSettle();
    expect(requests.where((r) => r.path.endsWith('/transition')).single.data,
        {'status': 'checked_in'});
    expectNoAppointmentQr();
    await unmountScreen(tester);
  });

  testWidgets('student booking uses pooled slots and reloads without QR',
      (tester) async {
    await mountScreen(tester, asStudent: true);
    expectNoAppointmentQr();
    expect(find.byTooltip('Appointment actions'), findsNothing);
    await tester.tap(find.widgetWithText(FloatingActionButton, 'Book'));
    await tester.pumpAndSettle();
    expect(find.text('Book appointment'), findsOneWidget);
    await tester.tap(
      find.widgetWithText(FilledButton, 'Find available slots'),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Book'));
    await tester.pumpAndSettle();
    expect(find.text('Appointment booked.'), findsOneWidget);
    expect(find.text('Book appointment'), findsNothing);
    expectNoAppointmentQr();
    expect(
        requests.where(
            (r) => r.path == '/me/appointment-slots' && r.method == 'GET'),
        hasLength(1));
    expect(
        requests.where(
            (r) => r.path == '/me/appointments' && r.method == 'POST'),
        hasLength(1));
    expect(
        requests.where(
            (r) => r.path == '/me/student-appointments' && r.method == 'GET'),
        hasLength(2));
    expectNoAppointmentQr();
    await unmountScreen(tester);
  });

  testWidgets('staff scheduling shows snackbar, closes and reloads without QR',
      (tester) async {
    await mountScreen(tester);
    await tester.tap(find.widgetWithText(FloatingActionButton, 'Schedule'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).at(1), 'Test Patient');
    await tester.pump(const Duration(milliseconds: 350));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Test Patient — 20261970'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Schedule'));
    await tester.pumpAndSettle();
    expect(find.text('Schedule appointment'), findsNothing);
    expect(
        find.textContaining('Appointment #57 scheduled for'), findsOneWidget);
    expect(
        requests.where(
            (r) => r.path == '/clinic/appointments' && r.method == 'POST'),
        hasLength(1));
    expect(
        requests.where(
            (r) => r.path == '/clinic/appointments' && r.method == 'GET'),
        hasLength(2));
    expectNoAppointmentQr();
    await unmountScreen(tester);
  });

  test('bootstrap and logout purge only legacy appointment preference prefix',
      () async {
    await AuthController.I.bootstrap();
    final prefs = await SharedPreferences.getInstance();
    expect(prefs.containsKey('synapse_appointment_qr_57'), isFalse);
    expect(prefs.getString('synapse_access_token'), 'unrelated-auth-pref');
    expect(prefs.getString('theme'), 'dark');
    expect(ApiClient.I.accessToken, 'test-session');
    await prefs.setString('synapse_appointment_qr_58', 'obsolete-test-value');
    await AuthController.I.logout();
    expect(prefs.containsKey('synapse_appointment_qr_58'), isFalse);
    expect(prefs.getString('theme'), 'dark');
    expect(prefs.getString('synapse_access_token'), 'unrelated-auth-pref');
    expect(ApiClient.I.accessToken, isNull);
  });

  test('logged-out bootstrap still purges obsolete booking credentials',
      () async {
    FlutterSecureStorage.setMockInitialValues({});
    SharedPreferences.setMockInitialValues({
      'synapse_appointment_qr_57': 'obsolete-test-value',
      'theme': 'dark',
    });
    expect(await AuthController.I.bootstrap(), isNull);
    final prefs = await SharedPreferences.getInstance();
    expect(prefs.getKeys(), {'theme'});
    expect(prefs.getString('theme'), 'dark');
    expect(requests, isEmpty);
  });

  test('removed appointment QR surface cannot return through stale hooks', () {
    final model = File('lib/core/models/appointment.dart').readAsStringSync();
    final api = File('lib/core/services/api_service.dart').readAsStringSync();
    final ui = File('lib/features/appointments/appointments_screen.dart')
        .readAsStringSync();
    expect(model, isNot(contains('qrToken')));
    expect(model, isNot(contains('AppointmentQrVerify')));
    expect(api, isNot(contains('issueAppointmentQr')));
    expect(api, isNot(contains('verifyAppointmentQr')));
    expect(api, isNot(contains('/appointments/verify')));
    expect(api, isNot(contains(r'/appointments/$id/qr')));
    expect(ui, isNot(contains('appointment_qr_sheet')));
    expect(ui, isNot(contains('showAppointmentQrSheet')));
    expect(File('lib/core/config.dart').readAsStringSync(),
        isNot(contains('appointmentQrPrefKey')));
    expect(
        File('pubspec.yaml').readAsStringSync(), isNot(contains('qr_flutter')));
    expect(File('lib/core/models/profile.dart').readAsStringSync(),
        isNot(contains('hasRfid')));
    expect(
        File('lib/features/patients/patients_screen.dart').readAsStringSync(),
        isNot(contains('RFID')));
    expect(
        File('lib/features/appointments/appointment_qr_sheet.dart')
            .existsSync(),
        isFalse);
  });
}
