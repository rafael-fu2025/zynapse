import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../core/api/api_client.dart';
import '../../core/api/api_envelope.dart';
import '../../core/config.dart';
import '../../core/models/appointment.dart';
import '../../core/services/api_service.dart';
import '../../core/utils/dates.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';

/// Modal sheet that renders the per-appointment proof-of-booking QR.
///
/// The backend stores only the HMAC hash of the token — the plaintext is
/// returned at booking time (`qr_token` on the appointment) and via the
/// `POST /clinic/appointments/{id}/qr` endpoint. This sheet:
///   * renders the current token (if the appointment already carries one),
///   * lets staff/owner re-issue a fresh token, and
///   * runs a PUBLIC minimum-disclosure verify (`POST /appointments/verify`)
///     that reveals only validity + status + time, never PII.
class AppointmentQrSheet extends StatefulWidget {
  const AppointmentQrSheet({
    super.key,
    required this.appointment,
    this.initialToken,
    this.canIssue = true,
  });

  final Appointment appointment;

  /// Token already attached at booking time (no extra network call needed).
  final String? initialToken;

  /// Staff (`clinic.appointments.write`) or the booking's owner.
  final bool canIssue;

  @override
  State<AppointmentQrSheet> createState() => _AppointmentQrSheetState();
}

class _AppointmentQrSheetState extends State<AppointmentQrSheet> {
  String? _token;
  bool _loading = false;
  bool _verifying = false;
  String? _error;
  AppointmentQrVerify? _verifyResult;

  /// Token pref key for this appointment.
  String get _prefKey => AppConfig.appointmentQrPrefKey(widget.appointment.id);

  @override
  void initState() {
    super.initState();
    _token = widget.initialToken;
    // Restore a previously-issued token (if any) so reopening the sheet
    // shows the SAME QR instead of silently re-minting (which would
    // rotate the token and invalidate any earlier copy). The token is
    // only minted/rotated on an EXPLICIT "Issue QR" / "Re-issue" tap.
    _restoreToken();
  }

  /// Load the last issued plaintext token from local storage. Seeded from
  /// the booking-time token first; a persisted token wins when present
  /// (it reflects the most recent explicit issue).
  Future<void> _restoreToken() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final saved = prefs.getString(_prefKey);
      if (!mounted) return;
      if (saved != null && saved.isNotEmpty) {
        setState(() => _token = saved);
      }
    } catch (_) {
      // Non-fatal: fall back to showing the "Issue QR" action.
    }
  }

  Future<void> _issue() async {
    setState(() {
      _loading = true;
      _error = null;
      _verifyResult = null;
    });
    try {
      final token = await ApiService.I.issueAppointmentQr(widget.appointment.id);
      // Persist so the same QR survives reopening until an explicit
      // re-issue rotates it.
      try {
        final prefs = await SharedPreferences.getInstance();
        await prefs.setString(_prefKey, token);
      } catch (_) {
        // Non-fatal: the in-memory token still works for this session.
      }
      if (!mounted) return;
      setState(() {
        _token = token;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = mapDioError(e).message;
        _loading = false;
      });
    }
  }

  Future<void> _verify() async {
    final token = _token;
    if (token == null) return;
    setState(() {
      _verifying = true;
      _verifyResult = null;
      _error = null;
    });
    try {
      final result = await ApiService.I.verifyAppointmentQr(token);
      if (!mounted) return;
      setState(() {
        _verifyResult = result;
        _verifying = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _verifying = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = mapDioError(e).message;
        _verifying = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        bottom: MediaQuery.of(context).viewInsets.bottom,
      ),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 14, 20, 20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const SheetHeader(
              title: 'Appointment QR',
              subtitle: 'Show this at check-in as your proof of booking',
            ),
            const SizedBox(height: 8),
            Center(
              child: Text(
                fmtUtcToApp(widget.appointment.scheduledAt),
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
            ),
            const SizedBox(height: 2),
            Center(
              child: Text(
                '${widget.appointment.patientLabel} · ${titleCaseOption(widget.appointment.status)}',
                style: const TextStyle(color: Colors.black54, fontSize: 13),
              ),
            ),
            const SizedBox(height: 16),
            Center(
              child: _buildQr(),
            ),
            const SizedBox(height: 16),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Text(
                  _error!,
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: Theme.of(context).colorScheme.error,
                    fontSize: 13,
                  ),
                ),
              ),
            if (_verifyResult != null) _verifyPanel(_verifyResult!),
            const SizedBox(height: 12),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                // With no token yet, the ONLY way to mint one is the
                // explicit "Issue QR" action (never auto-issued on open).
                if (widget.canIssue && _token == null) ...[
                  FilledButton.icon(
                    onPressed: _loading ? null : _issue,
                    icon: _loading
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(HugeIcons.strokeRoundedQrCode01,
                            size: 18),
                    label: const Text('Issue QR'),
                  ),
                ] else if (widget.canIssue) ...[
                  OutlinedButton.icon(
                    onPressed: _loading ? null : _issue,
                    icon: const Icon(HugeIcons.strokeRoundedRefresh, size: 18),
                    label: const Text('Re-issue'),
                  ),
                ],
                if (_token != null) ...[
                  const SizedBox(width: 12),
                  FilledButton.icon(
                    onPressed: _verifying ? null : _verify,
                    icon: _verifying
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(HugeIcons.strokeRoundedSecurityCheck,
                            size: 18),
                    label: const Text('Verify'),
                  ),
                ],
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildQr() {
    if (_loading) {
      return Container(
        width: 220,
        height: 220,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: Colors.white,
          border: Border.all(color: Colors.black12),
          borderRadius: BorderRadius.circular(12),
        ),
        child: const CircularProgressIndicator(strokeWidth: 2),
      );
    }
    final token = _token;
    if (token == null) {
      return Container(
        width: 220,
        height: 220,
        alignment: Alignment.center,
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: Colors.white,
          border: Border.all(color: Colors.black12),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(HugeIcons.strokeRoundedQrCode01,
                size: 40, color: Colors.black26),
            const SizedBox(height: 10),
            const Text(
              'No QR issued yet.',
              textAlign: TextAlign.center,
              style: TextStyle(color: Colors.black54),
            ),
            if (widget.canIssue) ...[
              const SizedBox(height: 6),
              const Text(
                'Tap “Issue QR” to mint the proof-of-booking code.',
                textAlign: TextAlign.center,
                style: TextStyle(color: Colors.black38, fontSize: 12),
              ),
            ],
          ],
        ),
      );
    }
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border.all(color: Colors.black12),
        borderRadius: BorderRadius.circular(12),
      ),
      child: QrImageView(
        data: token,
        version: QrVersions.auto,
        size: 200,
        backgroundColor: Colors.white,
        errorCorrectionLevel: QrErrorCorrectLevel.M,
      ),
    );
  }

  Widget _verifyPanel(AppointmentQrVerify result) {
    final ok = result.valid;
    final color = ok ? const Color(0xFF1B7A43) : const Color(0xFFB3261E);
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: color.withValues(alpha: 0.4)),
      ),
      child: Column(
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(
                ok
                    ? HugeIcons.strokeRoundedCheckmarkCircle01
                    : HugeIcons.strokeRoundedCancel01,
                size: 18,
                color: color,
              ),
              const SizedBox(width: 6),
              Text(
                ok ? 'Valid appointment' : 'Not found / invalid',
                style: TextStyle(
                  fontWeight: FontWeight.w600,
                  color: color,
                ),
              ),
            ],
          ),
          if (result.status != null) ...[
            const SizedBox(height: 4),
            Text(
              'Status: ${titleCaseOption(result.status!)}'
              '${result.scheduledAt != null ? ' · ${fmtUtcToApp(result.scheduledAt)}' : ''}',
              style: const TextStyle(fontSize: 13, color: Colors.black87),
            ),
          ],
        ],
      ),
    );
  }
}

/// Convenience helper: opens the QR sheet in a bottom sheet.
Future<void> showAppointmentQrSheet(
  BuildContext context, {
  required Appointment appointment,
  String? initialToken,
  bool canIssue = true,
}) {
  return showSynapseSheet<void>(
    context,
    builder: (_) => AppointmentQrSheet(
      appointment: appointment,
      initialToken: initialToken,
      canIssue: canIssue,
    ),
  );
}
