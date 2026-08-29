import 'package:flutter_test/flutter_test.dart';
import 'package:synapse_mobile/core/models/appointment.dart';
import 'package:synapse_mobile/core/models/notification.dart';
import 'package:synapse_mobile/core/models/queue.dart';
import 'package:synapse_mobile/core/models/session.dart';

/// Pure-Dart model tests. The JSON fixtures mirror real backend responses
/// (see backend/app/Modules/*/DTOs and frontend/src/schemas/*).
void main() {
  group('Session', () {
    test('parses an admin session (wildcard)', () {
      final session = Session.fromJson(const {
        'id': 1,
        'email': 'admin@synapse.dev',
        'username': 'admin',
        'is_active': true,
        'force_reset': false,
        'person_kind': null,
        'person_name': null,
        'is_teaching': null,
        'permissions': ['*'],
      });
      expect(session.hasPermission('anything.else'), isTrue,
          reason: 'wildcard grants everything');
      expect(session.isStaff, isTrue);
    });

    test('parses a student session', () {
      final session = Session.fromJson(const {
        'id': 42,
        'email': 'andrei.santos@foundationu.edu.ph',
        'username': '',
        'is_active': true,
        'person_kind': 'student',
        'person_name': 'Andrei Santos',
        'permissions': ['student.portal.read', 'notifications.read'],
      });
      expect(session.isStudent, isTrue);
      expect(session.isStaff, isFalse);
      expect(session.hasPermission('student.portal.read'), isTrue);
      expect(session.hasPermission('clinic.encounters.read'), isFalse);
    });
  });

  group('Appointment', () {
    test('parses a decorated appointment row', () {
      final a = Appointment.fromJson(const {
        'id': 57,
        'patient_school_id': '20261970',
        'patient_name': 'Gabriel S. Lopez',
        'patient_kind': 'student',
        'provider_user_id': 54,
        'provider_name': 'Nina Reyes',
        'scheduled_at': '2026-08-05 06:00:00',
        'status': 'scheduled',
        'reason': 'Flu symptoms check-up',
        'encounter_id': null,
        'created_at': '2026-08-01 01:23:45',
      });
      expect(a.patientLabel, contains('Gabriel S. Lopez'));
      expect(a.providerLabel, 'Nina Reyes');
      expect(a.status, 'scheduled');
    });
  });

  group('Queue', () {
    test('parses the public waiting-room state', () {
      final state = PublicQueueState.fromJson(const {
        'now_serving': {
          'position': 1,
          'display_name': 'Hannah Rivera',
          'patient_school_id': '20269617',
        },
        'waiting': [
          {
            'position': 2,
            'display_name': 'Andrei Santos',
            'patient_school_id': '20266239',
            'est_wait_minutes': 5,
          },
        ],
        'updated_at': '2026-08-05 00:05:00',
      });
      expect(state.nowServing?.displayName, 'Hannah Rivera');
      expect(state.waiting, hasLength(1));
      expect(state.waiting.first.estWaitMinutes, 5);
      expect(state.isEmpty, isFalse);
    });

    test('parses a my-queue-status payload', () {
      final status = MyQueueStatus.fromJson(const {
        'queue_entry_id': 7,
        'encounter_id': 28,
        'position': 1,
        'queue_number': 'C-001',
        'status': 'waiting',
        'called_at': null,
        'started_at': null,
        'people_ahead': 0,
        'estimated_wait_minutes': 0,
      });
      expect(status.isQueued, isTrue);
      expect(status.queueNumber, 'C-001');
      expect(status.status, 'waiting');
    });
  });

  group('AppNotification', () {
    test('parses an unread notification', () {
      final n = AppNotification.fromJson(const {
        'id': 12,
        'template_code': 'referral.created',
        'context': {
          'resource_code': '18',
          'source_module': 'clinic',
          'target_module': 'counselling',
        },
        'read_at': null,
        'created_at': '2026-08-05 03:00:00',
      });
      expect(n.isRead, isFalse);
      expect(n.templateCode, 'referral.created');
    });
  });
}
