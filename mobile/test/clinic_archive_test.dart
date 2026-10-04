import 'package:flutter_test/flutter_test.dart';
import 'package:synapse_mobile/core/models/clinic.dart';
import 'package:synapse_mobile/core/models/queue.dart';

void main() {
  group('ClinicEncounter archive parity', () {
    test('parses active encounter and computes archive permissions', () {
      final active = ClinicEncounter.fromJson({
        'id': 101,
        'patient_school_id': '2026-0001',
        'patient_name': 'Juan Dela Cruz',
        'chief_complaint': 'Fever',
        'status': 'open',
        'attending_user_id': 2,
        'started_at': '2026-10-04 08:00:00',
        'closed_at': null,
        'archived_at': null,
      });

      expect(active.id, equals(101));
      expect(active.isOpen, isTrue);
      expect(active.archivedAt, isNull);
      expect(active.isArchived, isFalse);
      expect(active.canArchive, isFalse,
          reason: 'open encounter cannot be archived');
      expect(active.canRestore, isFalse);
    });

    test('parses closed encounter and allows archiving', () {
      final closed = ClinicEncounter.fromJson({
        'id': 102,
        'patient_school_id': '2026-0002',
        'patient_name': 'Maria Clara',
        'chief_complaint': 'Headache',
        'status': 'closed',
        'attending_user_id': 2,
        'started_at': '2026-10-04 08:30:00',
        'closed_at': '2026-10-04 09:00:00',
        'archived_at': null,
      });

      expect(closed.isOpen, isFalse);
      expect(closed.isArchived, isFalse);
      expect(closed.canArchive, isTrue,
          reason: 'closed unarchived encounter can be archived');
      expect(closed.canRestore, isFalse);
    });

    test('parses referred encounter and allows archiving', () {
      final referred = ClinicEncounter.fromJson({
        'id': 103,
        'patient_school_id': '2026-0003',
        'patient_name': 'Crisostomo Ibarra',
        'chief_complaint': 'Chest discomfort',
        'status': 'referred',
        'attending_user_id': 2,
        'started_at': '2026-10-04 09:15:00',
        'closed_at': '2026-10-04 09:45:00',
        'archived_at': null,
      });

      expect(referred.isArchived, isFalse);
      expect(referred.canArchive, isTrue,
          reason: 'referred unarchived encounter can be archived');
      expect(referred.canRestore, isFalse);
    });

    test('parses archived encounter and allows restoration', () {
      final archived = ClinicEncounter.fromJson({
        'id': 104,
        'patient_school_id': '2026-0004',
        'patient_name': 'Elias Morcoso',
        'chief_complaint': 'Sprain',
        'status': 'closed',
        'attending_user_id': 2,
        'started_at': '2026-10-03 10:00:00',
        'closed_at': '2026-10-03 10:30:00',
        'archived_at': '2026-10-03 11:00:00',
      });

      expect(archived.archivedAt, equals('2026-10-03 11:00:00'));
      expect(archived.isArchived, isTrue);
      expect(archived.canArchive, isFalse,
          reason: 'already archived encounter cannot be re-archived');
      expect(archived.canRestore, isTrue,
          reason: 'archived encounter can be restored');
    });
  });

  group('QueueEntry skip-window & archive parity', () {
    test('parses skip window timestamps', () {
      final skipped = QueueEntry.fromJson({
        'id': 15,
        'encounter_id': 105,
        'position': 3,
        'status': 'skipped',
        'display_name': 'Basilio',
        'patient_school_id': '2026-0005',
        'chief_complaint': 'Dizziness',
        'called_at': '2026-10-04 09:00:00',
        'skipped_at': '2026-10-04 09:10:00',
        'skip_deadline_at': '2026-10-04 10:10:00',
        'returned_at': null,
        'encounter_status': 'open',
      });

      expect(skipped.status, equals('skipped'));
      expect(skipped.skippedAt, equals('2026-10-04 09:10:00'));
      expect(skipped.skipDeadlineAt, equals('2026-10-04 10:10:00'));
      expect(skipped.returnedAt, isNull);
      expect(skipped.canArchive, isFalse,
          reason: 'skipped row cannot be archived');
    });

    test('computes canArchive for done queue entry with closed encounter', () {
      final done = QueueEntry.fromJson({
        'id': 16,
        'encounter_id': 106,
        'position': 4,
        'status': 'done',
        'display_name': 'Sisa',
        'patient_school_id': '2026-0006',
        'chief_complaint': 'Cough',
        'called_at': '2026-10-04 08:30:00',
        'started_at': '2026-10-04 08:35:00',
        'finished_at': '2026-10-04 09:00:00',
        'encounter_status': 'closed',
      });

      expect(done.canStart, isFalse);
      expect(done.canComplete, isFalse);
      expect(done.canSkip, isFalse);
      expect(done.canArchive, isTrue,
          reason: 'done entry with closed encounter can be archived');
    });

    test('computes canArchive for done queue entry with referred encounter', () {
      final done = QueueEntry.fromJson({
        'id': 17,
        'encounter_id': 107,
        'position': 5,
        'status': 'done',
        'display_name': 'Tiago',
        'patient_school_id': '2026-0007',
        'chief_complaint': 'Anxiety',
        'encounter_status': 'referred',
      });

      expect(done.canArchive, isTrue,
          reason: 'done entry with referred encounter can be archived');
    });

    test('disallows archiving when encounter is still open or row not done', () {
      final openDone = QueueEntry.fromJson({
        'id': 18,
        'encounter_id': 108,
        'position': 6,
        'status': 'done',
        'display_name': 'Salome',
        'patient_school_id': '2026-0008',
        'chief_complaint': 'Follow up',
        'encounter_status': 'open',
      });
      expect(openDone.canArchive, isFalse);

      final inSession = QueueEntry.fromJson({
        'id': 19,
        'encounter_id': 109,
        'position': 7,
        'status': 'in_session',
        'display_name': 'Padre Damaso',
        'patient_school_id': '2026-0009',
        'chief_complaint': 'Indigestion',
        'encounter_status': 'closed',
      });
      expect(inSession.canArchive, isFalse);

      final noEncounter = QueueEntry.fromJson({
        'id': 20,
        'encounter_id': 0,
        'position': 8,
        'status': 'done',
        'display_name': 'Doña Victorina',
        'patient_school_id': '2026-0010',
        'chief_complaint': 'Checkup',
        'encounter_status': 'closed',
      });
      expect(noEncounter.canArchive, isFalse);
    });
  });
}
