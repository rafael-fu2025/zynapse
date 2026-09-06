import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/models/counselling.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
import '../../core/utils/dates.dart';
import '../common/crud_form.dart';
import '../common/session_progress_tracker.dart';

class CounsellingSessionWorkspace extends StatefulWidget {
  const CounsellingSessionWorkspace({
    super.key,
    required this.sessionId,
    this.initialStep = 'notes',
  });

  final int sessionId;
  final String initialStep;

  @override
  State<CounsellingSessionWorkspace> createState() =>
      _CounsellingSessionWorkspaceState();
}

class _CounsellingSessionWorkspaceState
    extends State<CounsellingSessionWorkspace> {
  CounsellingSession? _session;
  List<CounsellingNote> _notes = [];
  bool _loading = true;
  bool _saving = false;
  bool _notesRevealed = false;
  bool _notesLoading = false;
  String? _notesError;
  String? _error;
  String _step = 'notes';
  final _notesController = TextEditingController();
  final _referralReason = TextEditingController();
  final _referralNotes = TextEditingController();

  bool get _canWrite =>
      context
          .read<AuthController>()
          .session
          ?.hasPermission('counselling.records.write') ??
      false;
  bool get _canRefer =>
      _canWrite &&
      (context
              .read<AuthController>()
              .session
              ?.hasPermission('referrals.create') ??
          false);

  @override
  void initState() {
    super.initState();
    _step = widget.initialStep;
    _load();
  }

  @override
  void dispose() {
    _notesController.dispose();
    _referralReason.dispose();
    _referralNotes.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    if (mounted) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }
    try {
      // Parity with the SPA (2026-09 audit, finding 3): opening a session
      // must NOT auto-decrypt the note history — every decrypt is a
      // recorded audit event, so fetching stays behind an explicit
      // Reveal action. Only the session detail loads here.
      final session = await ApiService.I.counsellingSessionDetail(widget.sessionId);
      if (!mounted) return;
      setState(() {
        _session = session;
        _loading = false;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = mapDioError(error).message;
        _loading = false;
      });
    }
  }

  Future<void> _revealNotes() async {
    if (_notesRevealed || _notesLoading) return;
    setState(() {
      _notesLoading = true;
      _notesError = null;
    });
    try {
      final notes = await ApiService.I.counsellingNotes(widget.sessionId);
      if (!mounted) return;
      setState(() {
        _notes = notes;
        _notesRevealed = true;
        _notesLoading = false;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _notesError = mapDioError(error).message;
        _notesLoading = false;
      });
    }
  }

  List<SessionProgressStep> get _steps {
    final session = _session!;
    return [
      const SessionProgressStep(
        id: 'started',
        label: 'Started',
        summary: 'Session opened',
        state: SessionStepState.complete,
      ),
      SessionProgressStep(
        id: 'notes',
        label: 'Progress',
        summary: session.noteCount > 0
            ? '${session.noteCount} note(s)'
            : 'Not recorded',
        state: session.noteCount > 0
            ? SessionStepState.complete
            : SessionStepState.current,
      ),
      SessionProgressStep(
        id: 'referral',
        label: 'Referral',
        summary: session.outgoingReferral != null
            ? '#${session.outgoingReferral!.id}'
            : _canRefer
                ? 'Optional'
                : 'Unavailable',
        state: session.outgoingReferral != null
            ? SessionStepState.complete
            : _canRefer
                ? SessionStepState.optional
                : SessionStepState.unavailable,
      ),
      SessionProgressStep(
        id: 'complete',
        label: 'Complete',
        summary: session.isOpen ? 'Pending' : 'Finished',
        state: session.isOpen
            ? SessionStepState.available
            : SessionStepState.complete,
      ),
    ];
  }

  Future<void> _run(Future<void> Function() action, String success) async {
    if (_saving) return;
    setState(() => _saving = true);
    final ok = await runCrudAction(context, action, successMessage: success);
    if (!mounted) return;
    setState(() => _saving = false);
    if (ok) await _load();
  }

  Future<void> _saveNotes() => _run(() async {
        final value = _notesController.text.trim();
        if (value.isEmpty) {
          throw const FormatException('Enter session progress notes.');
        }
        await ApiService.I.writeCounsellingNotes(widget.sessionId, value);
        _notesController.clear();
        // Re-read after write: a decryption round-trip verifies the
        // key configuration surfaced only on a later read before the
        // 2026-09 audit (finding 17).
        if (_notesRevealed) {
          _notes = await ApiService.I.counsellingNotes(widget.sessionId);
        }
      }, 'Session note saved — encrypted at rest (AES-256-GCM).');

  Future<void> _refer() => _run(() async {
        await ApiService.I.createCounsellingReferral(
          widget.sessionId,
          reasonCode: _referralReason.text,
          notes: _referralNotes.text,
        );
      }, 'Referral submitted to Clinic.');

  Future<void> _complete() async {
    final confirmed = await showCrudConfirm(
      context,
      title: 'Complete counselling session?',
      message: 'Are you sure you want to complete this counselling session?',
      confirmLabel: 'Complete',
      destructive: true,
    );
    if (!confirmed || !mounted) return;
    await _run(() async {
      await ApiService.I.closeCounsellingSession(widget.sessionId);
    }, 'Counselling session completed.');
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text('Counselling #${widget.sessionId}')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(
                  child: Padding(
                    padding: const EdgeInsets.all(24),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(_error!, textAlign: TextAlign.center),
                        const SizedBox(height: 12),
                        FilledButton(
                          onPressed: _load,
                          child: const Text('Try again'),
                        ),
                      ],
                    ),
                  ),
                )
              : Column(
                  children: [
                    SessionProgressTracker(
                      steps: _steps,
                      selectedId: _step,
                      onSelected: (value) => setState(() => _step = value),
                    ),
                    const Divider(height: 1),
                    Expanded(
                      child: SingleChildScrollView(
                        padding: const EdgeInsets.all(16),
                        child: _buildStep(),
                      ),
                    ),
                  ],
                ),
    );
  }

  Widget _buildStep() => switch (_step) {
        'started' => _startedStep(),
        'notes' => _notesStep(),
        'referral' => _referralStep(),
        _ => _completeStep(),
      };

  Widget _section(String title, String description, List<Widget> children) =>
      Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(title, style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 4),
          Text(
            description,
            style: TextStyle(
              color: Theme.of(context).colorScheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 16),
          ...children,
        ],
      );

  Widget _startedStep() {
    final session = _session!;
    final name = session.patientDisplayName?.trim().isNotEmpty == true
        ? session.patientDisplayName!
        : session.patientSchoolId;
    return _section(
      'Session started',
      'Patient and handoff context remain visible throughout the session.',
      [
        _InfoRow('Patient', '$name (${session.patientSchoolId})'),
        _InfoRow('Started', fmtUtcToApp(session.startedAt)),
        _InfoRow('Purpose', session.purpose ?? '—'),
        _InfoRow('Queue', session.queueNumber ?? 'Manual session'),
        _InfoRow(
          'Incoming referral',
          session.incomingReferralId == null
              ? '—'
              : '#${session.incomingReferralId}',
        ),
      ],
    );
  }

  Widget _notesStep() {
    final session = _session!;
    return _section(
      'Notes / Progress',
      'Notes are encrypted at rest (AES-256-GCM) and are never copied into a referral.',
      [
        if (!_notesRevealed && session.noteCount > 0)
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              border: Border.all(
                color: Theme.of(context).colorScheme.outlineVariant,
              ),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Column(
              children: [
                Text(
                  '${session.noteCount} encrypted note${session.noteCount == 1 ? '' : 's'} on record.',
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 4),
                Text(
                  'Decrypting is recorded in the audit log.',
                  style: TextStyle(
                    fontSize: 12,
                    color: Theme.of(context).colorScheme.onSurfaceVariant,
                  ),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 12),
                OutlinedButton.icon(
                  onPressed: _notesLoading ? null : _revealNotes,
                  icon: _notesLoading
                      ? const SizedBox(
                          width: 16,
                          height: 16,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Icon(Icons.lock_open_outlined),
                  label: const Text('Reveal notes'),
                ),
                if (_notesError != null) ...[
                  const SizedBox(height: 8),
                  Text(
                    _notesError!,
                    style: TextStyle(
                        color: Theme.of(context).colorScheme.error),
                    textAlign: TextAlign.center,
                  ),
                ],
              ],
            ),
          )
        else if (!_notesRevealed)
          const Text('No notes on record.')
        else if (_notes.isEmpty)
          const Text('No notes on record.')
        else
          for (final note in _notes.reversed)
            Card(
              child: ListTile(
                title: Text(note.plaintext),
                subtitle: Text(fmtUtcToApp(note.createdAt)),
              ),
            ),
        if (_canWrite && session.isOpen) ...[
          const SizedBox(height: 12),
          TextField(
            controller: _notesController,
            minLines: 5,
            maxLines: 10,
            maxLength: 16384,
            decoration: const InputDecoration(
              labelText: 'New progress note',
              alignLabelWithHint: true,
              border: OutlineInputBorder(),
            ),
          ),
          FilledButton.icon(
            onPressed: _saving ? null : _saveNotes,
            icon: const Icon(Icons.lock_outline),
            label: const Text('Save note'),
          ),
        ],
      ],
    );
  }

  Widget _referralStep() {
    final referral = _session!.outgoingReferral;
    return _section(
      'Clinic referral',
      'Referral is optional and remains independent from session completion.',
      [
        if (referral != null)
          Card(
            child: ListTile(
              leading: const Icon(Icons.check_circle, color: Color(0xFF1B7A43)),
              title: Text('Referral #${referral.id}'),
              subtitle: Text(
                'Guidance → Clinic · ${referral.status.replaceAll('_', ' ')}',
              ),
            ),
          )
        else if (!_canRefer)
          const Text('You do not have permission to create referrals.')
        else if (_session!.isOpen) ...[
          TextField(
            controller: _referralReason,
            maxLength: 64,
            decoration: const InputDecoration(
              labelText: 'Reason (optional)',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _referralNotes,
            minLines: 3,
            maxLines: 6,
            maxLength: 8192,
            decoration: const InputDecoration(
              labelText: 'Referral notes (optional)',
              helperText: 'Share only information Clinic needs.',
              border: OutlineInputBorder(),
            ),
          ),
          FilledButton.icon(
            onPressed: _saving ? null : _refer,
            icon: const Icon(Icons.arrow_forward),
            label: const Text('Refer to Clinic'),
          ),
        ],
      ],
    );
  }

  Widget _completeStep() => _section(
        'Complete session',
        'Review the session record before finishing. Any referral remains open.',
        [
          if (!_session!.isOpen)
            const Card(
              child: ListTile(
                leading: Icon(Icons.check_circle, color: Color(0xFF1B7A43)),
                title: Text('Counselling session completed'),
              ),
            )
          else if (_canWrite)
            FilledButton.icon(
              style: FilledButton.styleFrom(
                backgroundColor: const Color(0xFFB3261E),
              ),
              onPressed: _saving ? null : _complete,
              icon: const Icon(Icons.check_circle_outline),
              label: const Text('Complete session'),
            ),
        ],
      );
}

class _InfoRow extends StatelessWidget {
  const _InfoRow(this.label, this.value);
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(
              width: 120,
              child: Text(
                label,
                style: TextStyle(
                  color: Theme.of(context).colorScheme.onSurfaceVariant,
                ),
              ),
            ),
            Expanded(
              child: Text(
                value,
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
            ),
          ],
        ),
      );
}
