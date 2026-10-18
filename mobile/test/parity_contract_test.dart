import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:synapse_mobile/core/api/api_client.dart';
import 'package:synapse_mobile/core/api/api_envelope.dart';
import 'package:synapse_mobile/core/models/bmg_automation.dart';
import 'package:synapse_mobile/core/models/guidance_content.dart';
import 'package:synapse_mobile/core/models/guidance_followup.dart';
import 'package:synapse_mobile/core/models/referral.dart';
import 'package:synapse_mobile/core/models/scheduling_staff.dart';
import 'package:synapse_mobile/core/models/workstation_dashboard.dart';
import 'package:synapse_mobile/core/services/api_service.dart';
import 'package:synapse_mobile/core/utils/notifications_copy.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('archive scope asks for archived records only', () async {
    final requests = <RequestOptions>[];
    final interceptor = InterceptorsWrapper(onRequest: (options, handler) {
      requests.add(options);
      handler.resolve(Response(
        requestOptions: options,
        statusCode: 200,
        data: {
          'success': true,
          'data': <dynamic>[],
          'meta': {
            'pagination': <String, dynamic>{},
          },
        },
      ));
    });
    ApiClient.I.dio.interceptors.add(interceptor);
    try {
      await ApiService.I.students(includeArchived: true);
      await ApiService.I.employees(includeArchived: true);
      await ApiService.I.studentSearch('juan', includeArchived: true);
      await ApiService.I.employeeSearch('juan', includeArchived: true);
      await ApiService.I.medicines(includeArchived: true);
      await ApiService.I.inventoryItems(includeArchived: true);
      await ApiService.I.equipmentItems(includeArchived: true);
      await ApiService.I.facilityUnits(includeArchived: true);
      await ApiService.I.facilityDevices(includeArchived: true);

      for (final request in requests) {
        final expected = request.path.startsWith('/clinic/s')
                || request.path.startsWith('/clinic/employees')
            ? 'archived'
            : '1';
        expect(
          request.queryParameters['archived'],
          expected,
          reason: request.path,
        );
        expect(
          request.queryParameters.containsKey('include_archived'),
          isFalse,
          reason: request.path,
        );
      }

      requests.clear();
      await ApiService.I.students();
      expect(requests.single.queryParameters.containsKey('archived'), isFalse);
    } finally {
      ApiClient.I.dio.interceptors.remove(interceptor);
    }
  });

  test('new backend error codes get actionable messages', () {
    ApiException map(String code) => mapDioError(
          ApiException(409, [ApiError(code: code, message: 'server text')]),
        );

    expect(map('statemachine.schedule.slot_full').message,
        contains('no longer available'));
    expect(map('scheduling.staff.ineligible').message,
        contains('not enrolled'));
    expect(map('scheduling.owner_changed').message, contains('changed'));
    expect(map('archive.required').message, contains('Archive'));
    expect(map('archive.references_protected').message,
        contains('referenced'));
    expect(map('archive.retention_protected').message,
        contains('Retention'));
    expect(map('device.not_bound').message, contains('Bind a drum'));
    expect(map('device.disabled').message, contains('disabled'));
    expect(map('mis.sync_active').message, contains('already running'));
    expect(map('unmapped.code').message, 'server text');
  });

  test('referral parses priority with a normal fallback', () {
    final urgent = Referral.fromJson({
      'id': 1,
      'patient_school_id': '2026-1',
      'source_module': 'clinic',
      'target_module': 'counselling',
      'artifact_type': 'intake_pass',
      'status': 'submitted',
      'priority': 'urgent',
      'created_at': '2026-10-05 01:00:00',
      'updated_at': '2026-10-05 01:00:00',
    });
    expect(urgent.priority, 'urgent');

    final fallback = Referral.fromJson({
      'id': 2,
      'patient_school_id': '2026-2',
      'source_module': 'clinic',
      'target_module': 'counselling',
      'artifact_type': 'intake_pass',
      'status': 'submitted',
      'created_at': '2026-10-05 01:00:00',
      'updated_at': '2026-10-05 01:00:00',
    });
    expect(fallback.priority, 'normal');
  });

  test('new notification templates have friendly labels', () {
    expect(notificationLabel('bmg.device_silent'), 'Facilities device offline');
    expect(notificationLabel('bmg.device_run_no_batch'),
        'Device ran without an active batch');
    expect(notificationLabel('bmg.device_rtc_invalid'),
        'Device clock needs attention');
    expect(notificationLabel('bmg.device_command_expired'),
        'Device command expired');
    expect(notificationLabel('referral.queue_handoff'),
        'Referral handed to the queue');
    expect(notificationLabel('queue.skip_expired'),
        'Skipped patient recall expired');
    expect(notificationLabel('counselling.session_archived'),
        'Guidance session archived');
  });

  test('workstation dashboard parses metrics, trend and recent rows', () {
    final dashboard = WorkstationDashboard.fromJson({
      'unit': 'clinic',
      'day': '2026-10-19',
      'generated_at': '2026-10-19T01:00:00Z',
      'scope': 'tenant',
      'metrics': [
        {
          'key': 'waiting_queue',
          'label': 'Waiting / called today',
          'value': 4,
          'unit': null,
          'description': 'Today’s queue.',
        },
      ],
      'trend': {
        'title': 'Encounter starts',
        'description': 'Last seven days.',
        'points': [
          {'day': '2026-10-18', 'value': 2},
          {'day': '2026-10-19', 'value': 5},
        ],
      },
      'breakdown': {
        'title': 'Triage priorities',
        'description': 'Recorded priorities.',
        'items': [
          {'key': 'urgent', 'label': 'Urgent', 'value': 1},
        ],
      },
      'recent': {
        'kind': 'encounters',
        'title': 'Recent encounters',
        'rows': [
          {
            'id': 9,
            'code': '#9',
            'title': 'Juan Dela Cruz',
            'subtitle': null,
            'status': 'open',
            'occurred_at': '2026-10-19 00:30:00',
            'owner': 'nurse',
          },
        ],
      },
    });
    expect(dashboard.unit, 'clinic');
    expect(dashboard.metrics.single.value, 4);
    expect(dashboard.trend.points.last.value, 5);
    expect(dashboard.breakdown.items.single.value, 1);
    expect(dashboard.recent.rows.single.title, 'Juan Dela Cruz');
  });

  test('scheduling staff page parses enrollment state', () {
    final page = SchedulingStaffPage.fromJson({
      'items': [
        {
          'user_id': 54,
          'employee_number': 'E-54',
          'name': 'Test Provider',
          'department': 'Clinic',
          'position': 'Nurse',
          'employment_status': 'active',
          'assignment_id': 7,
          'enrollment_status': 'inactive',
          'eligible': true,
          'can_enroll': false,
          'status': 'inactive_enrollment',
          'future_appointments': 3,
        },
      ],
      'next_cursor': null,
      'department_keys': ['clinic'],
    });
    final row = page.items.single;
    expect(row.enrolled, isTrue);
    expect(row.enrollmentActive, isFalse);
    expect(row.selectable, isFalse);
    expect(row.futureAppointments, 3);
  });

  test('guidance follow-up parses risk and assignment', () {
    final followup = GuidanceFollowup.fromJson({
      'id': 3,
      'student_user_id': 42,
      'student_name': 'Test Student',
      'survey_title': 'Wellbeing check',
      'risk_reason': 'Low WHO-5 score',
      'who5_score': 8,
      'status': 'in_review',
      'assigned_counsellor_user_id': 54,
      'assigned_counsellor': 'counsellor',
      'outcome_note': null,
      'due_at': '2026-10-20 01:00:00',
      'created_at': '2026-10-19 01:00:00',
    });
    expect(followup.status, 'in_review');
    expect(followup.who5Score, 8);
    expect(followup.assignedCounsellor, 'counsellor');
  });

  test('guidance content parses archived announcements and services', () {
    final announcement = GuidanceAnnouncement.fromJson({
      'id': 1,
      'title': 'Clearance',
      'body': 'Complete your survey.',
      'audience': 'graduating_students',
      'is_required': true,
      'severity': 'urgent',
      'status': 'live',
      'created_at': '2026-10-19 01:00:00',
      'updated_at': '2026-10-19 01:00:00',
      'archived_at': null,
    });
    expect(announcement.isRequired, isTrue);
    expect(announcement.archived, isFalse);

    final service = GuidanceService.fromJson({
      'id': 2,
      'code': 'intake',
      'name': 'Intake interview',
      'sort_order': 1,
      'is_active': true,
      'created_at': '2026-10-19 01:00:00',
      'archived_at': '2026-10-19 02:00:00',
    });
    expect(service.archived, isTrue);
  });

  test('BMG monitor parses schedule, device and runs', () {
    final monitor = BmgUnitMonitor.fromJson({
      'unit': {'id': 1, 'code': 'DRM-01', 'display_name': 'Drum 1'},
      'device': {
        'id': 9,
        'code': 'ESP-9',
        'display_name': 'Board 9',
        'status': 'active',
        'online': true,
        'offline_after_minutes': 15,
        'heartbeat_age_minutes': 2,
        'rtc_valid': true,
        'rtc_offset_seconds': 0,
      },
      'schedule': {
        'times': ['09:00', '17:00'],
        'duration_seconds': 600,
        'pwm': 200,
        'enabled': true,
        'version': 4,
        'next_run_at': '2026-10-19 09:00:00',
      },
      'runs': {
        'recent': [
          {
            'id': 5,
            'run_uid': 'run-5',
            'origin': 'scheduled',
            'result': 'completed',
            'duration_seconds': 600,
            'created_at': '2026-10-19 01:00:00',
          },
        ],
        'failed_count': 1,
        'missed_times': ['2026-10-18 17:00:00'],
        'missed_count': 1,
      },
      'active_batch': null,
      'totals': {'input_kg': 12.5, 'output_kg': 4.0},
      'open_alerts': [],
    });
    expect(monitor.device!.online, isTrue);
    expect(monitor.schedule.times, ['09:00', '17:00']);
    expect(monitor.nextRunAt, '2026-10-19 09:00:00');
    expect(monitor.recentRuns.single.result, 'completed');
    expect(monitor.missedCount, 1);
    expect(monitor.inputKg, 12.5);
  });
}
