/// Date/time helpers.
///
/// The backend stores datetimes in UTC (e.g. `scheduled_at`,
/// `created_at`) and the SPA renders them in Asia/Manila
/// (`frontend/src/lib/*` uses `fmtUtcToApp`). Manila is UTC+8 with no
/// DST, so the conversion is a fixed offset — good enough for the demo.
library;

import 'package:intl/intl.dart';

/// Parses a backend datetime string (`YYYY-MM-DD HH:mm:ss` or with `T`)
/// as UTC and returns the Asia/Manila wall-clock [DateTime].
DateTime? utcToManila(String? raw) {
  if (raw == null || raw.isEmpty) return null;
  final m = RegExp(
    r'^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})',
  ).firstMatch(raw);
  if (m == null) return null;
  final utc = DateTime.utc(
    int.parse(m[1]!),
    int.parse(m[2]!),
    int.parse(m[3]!),
    int.parse(m[4]!),
    int.parse(m[5]!),
    int.parse(m[6]!),
  );
  return utc.add(const Duration(hours: 8));
}

/// Formats a backend datetime as `Aug 5, 2026 · 9:00 AM` (Manila).
String fmtUtcToApp(String? raw) {
  final dt = utcToManila(raw);
  if (dt == null) return raw ?? '—';
  return DateFormat('MMM d, y · h:mm a').format(dt);
}

/// Formats a backend datetime as a short `MMM d, h:mm a` (Manila).
String fmtUtcShort(String? raw) {
  final dt = utcToManila(raw);
  if (dt == null) return raw ?? '—';
  return DateFormat('MMM d · h:mm a').format(dt);
}

/// Formats a local app [DateTime] into the backend's UTC wall-clock
/// string (`YYYY-MM-DD HH:mm:ss`).
///
/// The user picks a local (Manila) time; we convert it to UTC before
/// sending, because the backend validates a `valid_date` UTC string.
String localToUtcSql(DateTime local) {
  final utc = local.subtract(const Duration(hours: 8));
  return DateFormat('yyyy-MM-dd HH:mm:ss').format(utc);
}

/// Formats a [DateTime] as `yyyy-MM-dd` (date-only input).
String toDateInput(DateTime d) => DateFormat('yyyy-MM-dd').format(d);
