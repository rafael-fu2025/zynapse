import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:synapse_mobile/core/models/clinic.dart';
import 'package:synapse_mobile/core/models/counselling.dart';
import 'package:synapse_mobile/core/models/inventory.dart';
import 'package:synapse_mobile/core/models/kiosk.dart';
import 'package:synapse_mobile/features/common/session_progress_tracker.dart';

void main() {
  test('clinic detail parses progress and outgoing referral', () {
    final encounter = ClinicEncounter.fromJson({
      'id': 12,
      'patient_school_id': '2026-001',
      'chief_complaint': 'Headache',
      'status': 'open',
      'attending_user_id': 4,
      'started_at': '2026-08-15 01:00:00',
      'closed_at': null,
      'vitals_count': 1,
      'treatment_count': 2,
      'assessment_recorded': true,
      'outgoing_referral': {
        'id': 9,
        'patient_school_id': '2026-001',
        'source_module': 'clinic',
        'target_module': 'counselling',
        'artifact_type': 'intake_pass',
        'status': 'submitted',
        'created_at': '2026-08-15 01:10:00',
        'updated_at': '2026-08-15 01:10:00',
      },
    });

    expect(encounter.vitalsCount, 1);
    expect(encounter.treatmentCount, 2);
    expect(encounter.assessmentRecorded, isTrue);
    expect(encounter.outgoingReferral?.targetModule, 'counselling');
  });

  test('counselling detail remains compatible with list rows', () {
    final listRow = CounsellingSession.fromJson({
      'id': 4,
      'patient_school_id': '2026-002',
      'counsellor_user_id': 7,
      'started_at': '2026-08-15 01:00:00',
      'ended_at': null,
    });
    final detail = CounsellingSession.fromJson({
      'id': 4,
      'patient_school_id': '2026-002',
      'patient_display_name': 'Sample Student',
      'counsellor_user_id': 7,
      'started_at': '2026-08-15 01:00:00',
      'ended_at': null,
      'note_count': 3,
      'incoming_referral_id': 8,
      'outgoing_referral': null,
    });

    expect(listRow.noteCount, 0);
    expect(detail.noteCount, 3);
    expect(detail.patientDisplayName, 'Sample Student');
    expect(detail.incomingReferralId, 8);
  });

  testWidgets('progress tracker exposes and selects workflow points',
      (tester) async {
    var selected = 'vitals';
    final steps = [
      const SessionProgressStep(
        id: 'started',
        label: 'Started',
        summary: 'Visit opened',
        state: SessionStepState.complete,
      ),
      const SessionProgressStep(
        id: 'vitals',
        label: 'Vitals',
        summary: 'Not recorded',
        state: SessionStepState.current,
      ),
      const SessionProgressStep(
        id: 'referral',
        label: 'Referral',
        summary: 'Optional',
        state: SessionStepState.optional,
      ),
    ];

    await tester.pumpWidget(MaterialApp(
      home: StatefulBuilder(builder: (context, setState) {
        return Scaffold(
          body: SessionProgressTracker(
            steps: steps,
            selectedId: selected,
            onSelected: (value) => setState(() => selected = value),
          ),
        );
      }),
    ));

    expect(find.text('Started'), findsOneWidget);
    expect(find.text('Vitals'), findsOneWidget);
    await tester.tap(find.text('Referral'));
    await tester.pumpAndSettle();
    expect(selected, 'referral');
  });

  test('kiosk media parses static poster and archive state', () {
    final asset = KioskMediaAsset.fromJson({
      'id': 3,
      'public_id': '123e4567-e89b-42d3-a456-426614174000',
      'kind': 'video',
      'label': 'Welcome',
      'original_name': 'welcome.mp4',
      'mime_type': 'video/mp4',
      'size_bytes': 2048,
      'url': '/api/v1/kiosk-media/id/content',
      'thumbnail_url': '/kiosk-thumbnails/id.jpg',
      'archived': false,
      'created_at': '2026-08-15 04:00:00',
    });

    expect(asset.kind, 'video');
    expect(asset.thumbnailUrl, startsWith('/kiosk-thumbnails/'));
    expect(asset.archived, isFalse);
  });

  test('stock transaction preserves in/out and running stock', () {
    final transaction = StockTransaction.fromJson({
      'id': 14,
      'type': 'dispensed',
      'qty_in': null,
      'qty_out': 3,
      'balance_after': 37,
      'reference_type': 'clinic_encounter',
      'reference_id': 12,
      'user_email': 'nurse@example.edu',
      'note': 'Issued during encounter',
      'created_at': '2026-08-15 04:30:00',
    });

    expect(transaction.qtyIn, isNull);
    expect(transaction.qtyOut, 3);
    expect(transaction.stockAfter, 37);
    expect(transaction.referenceType, 'clinic_encounter');
  });
}
