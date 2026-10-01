import 'package:flutter_test/flutter_test.dart';
import 'package:synapse_mobile/core/models/facilities.dart';

/// Facilities (BMG) model + payload tests.
///
/// The JSON fixtures mirror real backend responses (see
/// backend/app/Modules/Facilities/DTOs and frontend/src/schemas/facilities.ts).
void main() {
  group('BmgUnit', () {
    test('treats only in-flight statuses as active', () {
      for (final status in ['processing', 'awaiting_output']) {
        expect(BmgUnit.fromJson({'id': 1, 'status': status}).isActive, isTrue,
            reason: '$status carries a live batch');
      }
      for (final status in [
        'idle',
        'cancelled',
        'maintenance',
        'released',
        'curing'
      ]) {
        expect(BmgUnit.fromJson({'id': 1, 'status': status}).isActive, isFalse,
            reason: '$status is not an in-flight batch');
      }
    });

    test('parses the joined active-batch columns', () {
      final unit = BmgUnit.fromJson(const {
        'id': 7,
        'code': 'drum-01',
        'display_name': 'Drum 01',
        'status': 'processing',
        'active_batch_id': 42,
        'active_batch_weight_kg': 80.5,
        'active_batch_progress_pct': 35,
        'utilization_pct': 64,
        'created_at': '2026-08-01 09:00:00',
      });

      expect(unit.activeBatchId, 42);
      expect(unit.activeBatchWeightKg, 80.5);
      expect(unit.activeBatchProgressPct, 35);
      expect(unit.utilizationPct, 64);
    });
  });

  group('BmgActiveBatches', () {
    // The BMG list endpoints nest a `{data, next}` payload inside the
    // API envelope's `data` field rather than putting a bare array there
    // like every other module. `ApiService` unwraps the envelope, so what
    // reaches the model is the inner payload — which is what these
    // fixtures model.
    Map<String, dynamic> payload(List<Map<String, dynamic>> rows,
            {String? next}) =>
        {
          'data': rows,
          'next': next,
          'count': rows.length,
          'turning_due_days': 4,
        };

    test('unwraps the nested payload and finds a drum by id', () {
      final batches = BmgActiveBatches.fromJson(payload(const [
        {
          'batch_id': 42,
          'batch_code': 'BMG-0001',
          'batch_status': 'processing',
          'unit_id': 7,
          'unit_code': 'drum-01',
          'unit_name': 'Drum 01',
          'input_kg': 100.0,
          'started_at': '2026-08-01 09:00:00',
          'days_active': 3,
          'progress_pct': 30.0,
        },
      ]));

      expect(batches.batches, hasLength(1));
      expect(batches.forUnit(7)?.batchId, 42);
      expect(batches.forUnit(999), isNull);
    });

    test('reads the server-supplied turning threshold rather than a local copy',
        () {
      final batches = BmgActiveBatches.fromJson(payload(const []));
      expect(batches.turningDueDays, 4);
    });

    test('word the ETA the same way on every surface', () {
      BmgActiveBatch withDays(int? days) => BmgActiveBatch.fromJson({
            'batch_id': 1,
            'batch_code': 'BMG-0001',
            'batch_status': 'processing',
            'unit_id': 1,
            'unit_code': 'drum-01',
            'unit_name': 'Drum 01',
            'input_kg': 100.0,
            'started_at': '2026-08-01 09:00:00',
            'days_active': 1,
            'progress_pct': 5.0,
            'expected_completion_date': days == null ? null : '2026-08-10',
            'days_until_expected': days,
          });

      expect(withDays(-3).etaLabel, '3 days overdue');
      expect(withDays(0).etaLabel, 'Due today');
      expect(withDays(1).etaLabel, 'in 1 day');
      expect(withDays(5).etaLabel, 'in 5 days');
      expect(withDays(null).etaLabel, '—');
      expect(withDays(-3).isOverdue, isTrue);
      expect(withDays(2).isOverdue, isFalse);
    });
  });

  group('BmgDevice', () {
    Map<String, dynamic> device({
      String? silenceNotifiedAt,
      String? archivedAt,
      String status = 'active',
      int? unitId,
    }) =>
        {
          'id': 3,
          'code': 'b8-1f-3f-d7-ec-18',
          'display_name': 'Compost Tumbler 1',
          'status': status,
          'unit_id': unitId,
          'created_at': '2026-09-01 09:00:00',
          if (silenceNotifiedAt != null)
            'silence_notified_at': silenceNotifiedAt,
          if (archivedAt != null) 'archived_at': archivedAt,
        };

    test('a watchdog-flagged device reads as silent', () {
      final d =
          BmgDevice.fromJson(device(silenceNotifiedAt: '2026-09-20 08:00:00'));
      expect(d.isSilent, isTrue);
      expect(d.isArchived, isFalse);
    });

    test('an archived device is never reported as silent', () {
      // The watchdog only considers active devices, so an archived one
      // can carry a stale marker from before it was retired.
      final d = BmgDevice.fromJson(device(
          silenceNotifiedAt: '2026-09-20 08:00:00',
          archivedAt: '2026-09-21 09:00:00'));
      expect(d.isSilent, isFalse);
      expect(d.isArchived, isTrue);
      expect(d.isActive, isFalse);
    });

    test('tracks its drum binding', () {
      expect(BmgDevice.fromJson(device()).isBound, isFalse);
      expect(BmgDevice.fromJson(device(unitId: 7)).isBound, isTrue);
    });
  });

  group('BmgWasteCategory', () {
    test('parses the expected-duration and history columns separately', () {
      final c = BmgWasteCategory.fromJson(const {
        'id': 2,
        'code': 'veg-scrp',
        'name': 'Vegetable Scraps',
        'expected_yield_pct': 40.0,
        'reference_duration_days': 21,
        'expected_days': 24,
        'historical_avg_days': 23.4,
        'sample_count': 5,
        'is_active': true,
      });

      expect(c.expectedDays, 24);
      expect(c.referenceDurationDays, 21);
      expect(c.historicalAvgDays, 23.4);
      expect(c.sampleCount, 5);
    });
  });
}
