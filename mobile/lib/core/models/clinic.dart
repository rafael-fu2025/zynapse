import 'referral.dart';

class ClinicEncounter {
  ClinicEncounter({
    required this.id,
    required this.patientSchoolId,
    required this.chiefComplaint,
    required this.status,
    required this.attendingUserId,
    required this.startedAt,
    this.patientName,
    this.appointmentId,
    this.stationId,
    this.triagePriority,
    this.triageOverride = false,
    this.diagnosis,
    this.outcome,
    this.closedAt,
    this.queueEntryId,
    this.queueNumber,
    this.queueStatus,
    this.incomingReferralId,
    this.vitalsCount = 0,
    this.treatmentCount = 0,
    this.assessmentRecorded = false,
    this.outgoingReferral,
  });

  factory ClinicEncounter.fromJson(Map<String, dynamic> json) =>
      ClinicEncounter(
        id: (json['id'] ?? 0) as int,
        patientSchoolId: (json['patient_school_id'] ?? '') as String,
        patientName: json['patient_name'] as String?,
        appointmentId: json['appointment_id'] as int?,
        stationId: json['station_id'] as String?,
        chiefComplaint: (json['chief_complaint'] ?? '') as String,
        triagePriority: json['triage_priority'] as String?,
        triageOverride: (json['triage_override'] ?? false) as bool,
        diagnosis: json['diagnosis'] as String?,
        status: (json['status'] ?? '') as String,
        outcome: json['outcome'] as String?,
        attendingUserId: (json['attending_user_id'] ?? 0) as int,
        startedAt: (json['started_at'] ?? '') as String,
        closedAt: json['closed_at'] as String?,
        queueEntryId: json['queue_entry_id'] as int?,
        queueNumber: json['queue_number'] as String?,
        queueStatus: json['queue_status'] as String?,
        incomingReferralId: json['incoming_referral_id'] as int?,
        vitalsCount: (json['vitals_count'] ?? 0) as int,
        treatmentCount: (json['treatment_count'] ?? 0) as int,
        assessmentRecorded: (json['assessment_recorded'] ?? false) as bool,
        outgoingReferral: json['outgoing_referral'] is Map<String, dynamic>
            ? Referral.fromJson(
                json['outgoing_referral'] as Map<String, dynamic>)
            : null,
      );

  final int id;
  final String patientSchoolId;
  final String? patientName;
  final int? appointmentId;
  final String? stationId;
  final String chiefComplaint;
  final String? triagePriority;
  final bool triageOverride;
  final String? diagnosis;
  final String status;
  final String? outcome;
  final int attendingUserId;
  final String startedAt;
  final String? closedAt;
  final int? queueEntryId;
  final String? queueNumber;
  final String? queueStatus;
  final int? incomingReferralId;
  final int vitalsCount;
  final int treatmentCount;
  final bool assessmentRecorded;
  final Referral? outgoingReferral;

  bool get isOpen => status == 'open';
  String get patientDisplayName =>
      patientName?.trim().isNotEmpty == true ? patientName! : patientSchoolId;
}

class ClinicVitals {
  ClinicVitals({
    required this.encounterId,
    required this.recordedAt,
    this.bpSystolic,
    this.bpDiastolic,
    this.pulseBpm,
    this.tempC,
    this.spo2Pct,
    this.weightKg,
    this.heightCm,
  });

  factory ClinicVitals.fromJson(Map<String, dynamic> json) => ClinicVitals(
        encounterId: (json['encounter_id'] ?? 0) as int,
        bpSystolic: (json['bp_systolic'] as num?)?.toInt(),
        bpDiastolic: (json['bp_diastolic'] as num?)?.toInt(),
        pulseBpm: (json['pulse_bpm'] as num?)?.toInt(),
        tempC: (json['temp_c'] as num?)?.toDouble(),
        spo2Pct: (json['spo2_pct'] as num?)?.toInt(),
        weightKg: (json['weight_kg'] as num?)?.toDouble(),
        heightCm: (json['height_cm'] as num?)?.toDouble(),
        recordedAt: (json['recorded_at'] ?? '') as String,
      );

  final int encounterId;
  final int? bpSystolic;
  final int? bpDiastolic;
  final int? pulseBpm;
  final double? tempC;
  final int? spo2Pct;
  final double? weightKg;
  final double? heightCm;
  final String recordedAt;
}

class PreviousHeightWeight {
  PreviousHeightWeight({
    required this.sourceEncounterId,
    required this.recordedAt,
    this.weightKg,
    this.heightCm,
  });

  factory PreviousHeightWeight.fromJson(Map<String, dynamic> json) =>
      PreviousHeightWeight(
        sourceEncounterId: (json['source_encounter_id'] ?? 0) as int,
        weightKg: (json['weight_kg'] as num?)?.toDouble(),
        heightCm: (json['height_cm'] as num?)?.toDouble(),
        recordedAt: (json['recorded_at'] ?? '') as String,
      );

  final int sourceEncounterId;
  final double? weightKg;
  final double? heightCm;
  final String recordedAt;
}

class ClinicTreatment {
  ClinicTreatment({
    required this.id,
    required this.encounterId,
    required this.type,
    required this.description,
    required this.administeredAt,
    this.medicineId,
    this.medicineName,
    this.unit,
    this.quantityUsed,
  });

  factory ClinicTreatment.fromJson(Map<String, dynamic> json) =>
      ClinicTreatment(
        id: (json['id'] ?? 0) as int,
        encounterId: (json['encounter_id'] ?? 0) as int,
        type: (json['treatment_type'] ?? '') as String,
        description: (json['description'] ?? '') as String,
        medicineId: json['medicine_id'] as int?,
        medicineName: json['medicine_name'] as String?,
        unit: json['unit'] as String?,
        quantityUsed: (json['quantity_used'] as num?)?.toInt(),
        administeredAt: (json['administered_at'] ?? '') as String,
      );

  final int id;
  final int encounterId;
  final String type;
  final String description;
  final int? medicineId;
  final String? medicineName;
  final String? unit;
  final int? quantityUsed;
  final String administeredAt;
}
