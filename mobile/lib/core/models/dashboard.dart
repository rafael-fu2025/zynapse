/// Dashboard counters — mirrors `frontend/src/hooks/useDashboard.ts` and
/// the backend `DashboardController::counters()`.
class DashboardCounters {
  DashboardCounters({
    this.clinic,
    this.counselling,
    this.facilities,
    this.referrals,
    this.audit,
  });

  factory DashboardCounters.fromJson(Map<String, dynamic> json) =>
      DashboardCounters(
        clinic: json['clinic'] is Map<String, dynamic>
            ? ClinicCounters.fromJson(json['clinic'] as Map<String, dynamic>)
            : null,
        counselling: json['counselling'] is Map<String, dynamic>
            ? CounsellingCounters.fromJson(
                json['counselling'] as Map<String, dynamic>)
            : null,
        facilities: json['facilities'] is Map<String, dynamic>
            ? FacilitiesCounters.fromJson(
                json['facilities'] as Map<String, dynamic>)
            : null,
        referrals: json['referrals'] is Map<String, dynamic>
            ? ReferralCounters.fromJson(
                json['referrals'] as Map<String, dynamic>)
            : null,
        audit: json['audit'] is Map<String, dynamic>
            ? AuditCounters.fromJson(json['audit'] as Map<String, dynamic>)
            : null,
      );

  final ClinicCounters? clinic;
  final CounsellingCounters? counselling;
  final FacilitiesCounters? facilities;
  final ReferralCounters? referrals;
  final AuditCounters? audit;
}

class ClinicCounters {
  ClinicCounters({required this.openEncounters, required this.closedEncounters});
  factory ClinicCounters.fromJson(Map<String, dynamic> json) => ClinicCounters(
        openEncounters: (json['open_encounters'] ?? 0) as int,
        closedEncounters: (json['closed_encounters'] ?? 0) as int,
      );
  final int openEncounters;
  final int closedEncounters;
}

class CounsellingCounters {
  CounsellingCounters({required this.openSessions, required this.closedSessions});
  factory CounsellingCounters.fromJson(Map<String, dynamic> json) =>
      CounsellingCounters(
        openSessions: (json['open_sessions'] ?? 0) as int,
        closedSessions: (json['closed_sessions'] ?? 0) as int,
      );
  final int openSessions;
  final int closedSessions;
}

class FacilitiesCounters {
  FacilitiesCounters({
    required this.unitsIdle,
    required this.unitsProcessing,
    required this.unitsAwaiting,
    required this.atRisk,
  });
  factory FacilitiesCounters.fromJson(Map<String, dynamic> json) =>
      FacilitiesCounters(
        unitsIdle: (json['units_idle'] ?? 0) as int,
        unitsProcessing: (json['units_processing'] ?? 0) as int,
        unitsAwaiting: (json['units_awaiting'] ?? 0) as int,
        atRisk: (json['at_risk'] ?? 0) as int,
      );
  final int unitsIdle;
  final int unitsProcessing;
  final int unitsAwaiting;
  final int atRisk;
}

class ReferralCounters {
  ReferralCounters({
    required this.submitted,
    required this.acknowledged,
    required this.underReview,
    required this.closed,
  });
  factory ReferralCounters.fromJson(Map<String, dynamic> json) =>
      ReferralCounters(
        submitted: (json['submitted'] ?? 0) as int,
        acknowledged: (json['acknowledged'] ?? 0) as int,
        underReview: (json['under_review'] ?? 0) as int,
        closed: (json['closed'] ?? 0) as int,
      );
  final int submitted;
  final int acknowledged;
  final int underReview;
  final int closed;
}

class AuditCounters {
  AuditCounters({required this.eventsLast24h});
  factory AuditCounters.fromJson(Map<String, dynamic> json) => AuditCounters(
        eventsLast24h: (json['events_last_24h'] ?? 0) as int,
      );
  final int eventsLast24h;
}
