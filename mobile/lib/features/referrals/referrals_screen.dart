import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/api/api_envelope.dart';
import '../../core/models/profile.dart';
import '../../core/models/referral.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
import '../../core/utils/dates.dart';
import '../common/auto_polling.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';

/// Referrals — `GET /referrals` (paged) with a status filter.
class ReferralsScreen extends StatefulWidget {
  const ReferralsScreen({super.key});

  @override
  State<ReferralsScreen> createState() => _ReferralsScreenState();
}

class _ReferralsScreenState extends State<ReferralsScreen>
    with AutoPolling<ReferralsScreen> {
  String? _status;
  bool _loading = true;
  String? _error;
  List<Referral> _items = [];
  String? _nextCursor;
  bool _loadingMore = false;
  bool _loadedOnce = false;

  @override
  void initState() {
    super.initState();
    _load();
    // Live updates — mirrors the SPA's 30s `refetchInterval`.
    startPolling(const Duration(seconds: 30), _silentRefresh);
  }

  /// Polled live update: silently refetch the first page so new/updated
  /// referrals appear without a manual refresh. Skipped once the user has
  /// paginated deeper (pull-to-refresh there) so the list never collapses.
  Future<void> _silentRefresh() async {
    if (_items.length > 25) return;
    try {
      final page = await ApiService.I.referrals(status: _status);
      if (!mounted) return;
      setState(() {
        _items = page.items;
        _nextCursor = page.meta?.nextCursor;
      });
    } catch (e) {
      // Keep the current list on transient errors.
      if (kDebugMode) debugPrint('ReferralsScreen.poll failed: $e');
    }
  }

  Future<void> _load() async {
    setState(() {
      _loading = !_loadedOnce;
      _error = null;
    });
    try {
      final page = await ApiService.I.referrals(status: _status);
      setState(() {
        _items = page.items;
        _nextCursor = page.meta?.nextCursor;
        _loadedOnce = true;
        _loading = false;
      });
    } catch (e) {
      setState(() {
        _error = mapDioError(e).message;
        _loading = false;
      });
    }
  }

  Future<void> _loadMore() async {
    if (_loadingMore || _nextCursor == null) return;
    setState(() => _loadingMore = true);
    try {
      final page =
          await ApiService.I.referrals(status: _status, cursor: _nextCursor);
      setState(() {
        _items = [..._items, ...page.items];
        _nextCursor = page.meta?.nextCursor;
      });
    } catch (e) {
      // ignore
      if (kDebugMode) debugPrint('ReferralsScreen.loadMore failed: $e');
    } finally {
      setState(() => _loadingMore = false);
    }
  }

  bool get _canCreate {
    final s = context.read<AuthController>().session;
    if (s == null || !s.hasPermission('referrals.create')) return false;
    return true;
  }

  bool get _canHandle {
    final s = context.read<AuthController>().session;
    return s == null
        ? false
        : (s.hasPermission('*') ||
            s.hasPermission('clinic.encounters.read') ||
            s.hasPermission('counselling.records.read') ||
            s.hasPermission('counselling.schedule.read') ||
            s.hasPermission('referrals.create'));
  }

  bool get _canBookCounselling {
    final s = context.read<AuthController>().session;
    return s == null
        ? false
        : (s.hasPermission('counselling.schedule.manage') ||
            s.hasPermission('counselling.schedule.team_manage'));
  }

  Future<void> _bookReferral(Referral referral) async {
    final booked = await showSynapseSheet<bool>(
      context,
      builder: (_) => _BookReferralSheet(referral: referral),
    );
    if (booked == true && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Counselling appointment booked.')),
      );
      _load();
    }
  }

  Future<void> _create() async {
    final payload = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (_) => const _ReferralCreateSheet(),
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.createReferral(payload),
      successMessage: 'Referral created.',
    );
    if (ok) _load();
  }

  Future<void> _transition(Referral r, String action) async {
    final confirmed = await showCrudConfirm(
      context,
      title: '${action[0].toUpperCase()}${action.substring(1)} referral?',
      message: 'Move referral #${r.id} to “$action”?',
      confirmLabel: action[0].toUpperCase() + action.substring(1),
    );
    if (!confirmed || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.transitionReferral(r.id, action),
      successMessage: 'Referral ${action}d.',
    );
    if (ok) _load();
  }

  Future<void> _openDetail(Referral r) async {
    await showSynapseSheet<void>(
      context,
      builder: (_) => _ReferralDetailSheet(referral: r),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Referrals'),
      ),
      floatingActionButton: _canCreate
          ? FloatingActionButton.extended(
              backgroundColor: const Color(0xFF800000),
              foregroundColor: Colors.white,
              onPressed: _create,
              icon: const Icon(HugeIcons.strokeRoundedShare01),
              label: const Text('New referral'),
            )
          : null,
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: SizedBox(
              height: 40,
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                itemCount: _statusOptions.length,
                separatorBuilder: (_, __) => const SizedBox(width: 8),
                itemBuilder: (context, i) {
                  final (value, label) = _statusOptions[i];
                  return FilterChip(
                    label: Text(label),
                    selected: _status == value,
                    onSelected: (_) {
                      setState(() => _status = value);
                      _load();
                    },
                  );
                },
              ),
            ),
          ),
          const SizedBox(height: 4),
          Expanded(
            child: RefreshIndicator(onRefresh: _load, child: _buildBody()),
          ),
        ],
      ),
    );
  }

  static const _statusOptions = <(String?, String)>[
    (null, 'All'),
    ('submitted', 'Submitted'),
    ('acknowledged', 'Acknowledged'),
    ('under_review', 'Under review'),
    ('closed', 'Closed'),
  ];

  Widget _buildBody() {
    if (_loading) return AsyncState.loading();
    if (_error != null) return AsyncState.error(_error!, onRetry: _load);
    if (_items.isEmpty) return AsyncState.empty('No referrals in this view.');

    return ListView.builder(
      padding: const EdgeInsets.all(16),
      itemCount: _items.length + (_nextCursor != null ? 1 : 0),
      // Pre-build rows ahead of the viewport so fast flings don't hitch on
      // widget builds — keeps long lists smooth at the 120Hz vsync.
      scrollCacheExtent: const ScrollCacheExtent.pixels(600),
      // Referral tiles are `isThreeLine` ListTiles = fixed 88dp + 8dp gap
      // (top-aligned, so the gap reads as the old separator). A fixed
      // itemExtent lets the viewport skip layout of off-screen rows
      // (ListView.separated lost itemExtent in Flutter 3.44).
      itemExtent: 96,
      itemBuilder: (context, i) {
        if (i >= _items.length) {
          // Load-more sentinel — defer so we never setState during build.
          WidgetsBinding.instance.addPostFrameCallback((_) {
            if (mounted) _loadMore();
          });
          return const Padding(
            padding: EdgeInsets.all(8),
            child: Center(
              child: SizedBox(
                width: 22,
                height: 22,
                child: CircularProgressIndicator(strokeWidth: 2),
              ),
            ),
          );
        }
        return _ReferralTile(
          referral: _items[i],
          canHandle: _canHandle,
          canBook: _canBookCounselling,
          onTransition: (a) => _transition(_items[i], a),
          onBook: () => _bookReferral(_items[i]),
          onOpen: () => _openDetail(_items[i]),
        );
      },
    );
  }
}

Color _referralStatusColor(String status) => switch (status) {
      'submitted' => const Color(0xFF1E6FD9),
      'acknowledged' => const Color(0xFF8A5A00),
      'under_review' => const Color(0xFF5B4BA6),
      'closed' => const Color(0xFF1B7A43),
      _ => Colors.grey,
    };

Color _referralPriorityColor(String priority) => switch (priority) {
      'urgent' => const Color(0xFFB3261E),
      'high' => const Color(0xFFB45309),
      'low' => Colors.black45,
      _ => const Color(0xFF1E6FD9),
    };

class _ReferralTile extends StatelessWidget {
  const _ReferralTile({
    required this.referral,
    required this.canHandle,
    this.canBook = false,
    this.onTransition,
    this.onBook,
    this.onOpen,
  });

  final Referral referral;
  final bool canHandle;
  final bool canBook;
  final void Function(String action)? onTransition;
  final VoidCallback? onBook;
  final VoidCallback? onOpen;

  @override
  Widget build(BuildContext context) {
    return Card(
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(
          color: Theme.of(context)
              .colorScheme
              .outlineVariant
              .withValues(alpha: 0.5),
        ),
      ),
      child: ListTile(
        onTap: onOpen,
        title: Row(
          children: [
            Expanded(
              child: Text(
                'Referral #${referral.id} · Patient ${referral.patientSchoolId}',
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
            ),
            if (referral.priority == 'high' || referral.priority == 'urgent')
              Padding(
                padding: const EdgeInsets.only(right: 6),
                child: StatusBadge(
                  label: titleCaseOption(referral.priority),
                  color: _referralPriorityColor(referral.priority),
                ),
              ),
            StatusBadge(
              label: titleCaseOption(referral.status),
              color: _referralStatusColor(referral.status),
            ),
          ],
        ),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                  '${titleCaseOption(referral.sourceModule)} → ${titleCaseOption(referral.targetModule)}'),
              Text(
                  '${titleCaseOption(referral.artifactType)} · ${fmtUtcShort(referral.createdAt)}'),
              Text(
                'Priority: ${titleCaseOption(referral.priority)}',
                style: TextStyle(
                  color: _referralPriorityColor(referral.priority),
                  fontSize: 12,
                ),
              ),
              if (referral.providerName != null)
                Text('Provider: ${referral.providerName}'),
            ],
          ),
        ),
        isThreeLine: true,
        trailing: canHandle
            ? PopupMenuButton<String>(
                icon: const Icon(HugeIcons.strokeRoundedMore),
                onSelected: (v) {
                  switch (v) {
                    case 'acknowledge':
                      onTransition?.call('acknowledge');
                    case 'review':
                      onTransition?.call('review');
                    case 'close':
                      onTransition?.call('close');
                    case 'book':
                      onBook?.call();
                  }
                },
                itemBuilder: (_) => [
                  if (referral.status == 'submitted')
                    const PopupMenuItem(
                        value: 'acknowledge', child: Text('Acknowledge')),
                  if (referral.status == 'acknowledged')
                    const PopupMenuItem(value: 'review', child: Text('Review')),
                  if (referral.status == 'acknowledged' ||
                      referral.status == 'under_review')
                    const PopupMenuItem(value: 'close', child: Text('Close')),
                  if (canBook &&
                      referral.targetModule == 'counselling' &&
                      (referral.status == 'acknowledged' ||
                          referral.status == 'under_review'))
                    const PopupMenuItem(
                        value: 'book',
                        child: Text('Book counselling appointment')),
                ],
              )
            : null,
      ),
    );
  }
}

const _moduleLabels = <String, String>{
  'clinic': 'Clinic',
  'counselling': 'Counselling',
};

class _ReferralDetailSheet extends StatelessWidget {
  const _ReferralDetailSheet({required this.referral});

  final Referral referral;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SheetHeader(
              title: 'Referral #${referral.id}',
              subtitle: 'Patient ${referral.patientSchoolId}',
            ),
            const SizedBox(height: 10),
            Wrap(
              spacing: 8,
              runSpacing: 6,
              children: [
                StatusBadge(
                  label: titleCaseOption(referral.status),
                  color: _referralStatusColor(referral.status),
                ),
                StatusBadge(
                  label: 'Priority ${titleCaseOption(referral.priority)}',
                  color: _referralPriorityColor(referral.priority),
                ),
              ],
            ),
            const SizedBox(height: 12),
            _fact(
              'Flow',
              '${titleCaseOption(referral.sourceModule)} → '
                  '${titleCaseOption(referral.targetModule)}',
            ),
            _fact('Artifact', titleCaseOption(referral.artifactType)),
            _fact(
              'Reason',
              referral.reasonCode == null
                  ? '—'
                  : titleCaseOption(referral.reasonCode!),
            ),
            _fact('Provider', referral.providerName ?? '—'),
            if (referral.sourceEncounterId != null)
              _fact('Source encounter', '#${referral.sourceEncounterId}'),
            if (referral.sourceSessionId != null)
              _fact('Source session', '#${referral.sourceSessionId}'),
            if (referral.queueHandoffDestination != null)
              _fact(
                'Queue handoff',
                '${titleCaseOption(referral.queueHandoffDestination!)}'
                    '${referral.queueHandoffEntryId != null ? ' · entry #${referral.queueHandoffEntryId}' : ''}'
                    '${referral.queueHandoffAt != null ? ' · ${fmtUtcShort(referral.queueHandoffAt)}' : ''}',
              ),
            _fact('Created', fmtUtcShort(referral.createdAt)),
            _fact('Updated', fmtUtcShort(referral.updatedAt)),
            const SizedBox(height: 12),
            OutlinedButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Close'),
            ),
          ],
        ),
      ),
    );
  }

  Widget _fact(String label, String value) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(
              width: 120,
              child: Text(
                label,
                style: const TextStyle(fontSize: 12, color: Colors.black45),
              ),
            ),
            Expanded(
              child: Text(value, style: const TextStyle(fontSize: 12.5)),
            ),
          ],
        ),
      );
}

/// Default artifact per source module — mirrors the web
/// `PRESET_ARTIFACT` map so non-IT users don't have to guess.
const _presetArtifacts = <String, String>{
  'clinic': 'intake_pass',
  'counselling': 'referral_letter',
};

/// New-referral sheet — mirrors the SPA `CreateReferralDialog`.
///
/// The patient field is a debounced typeahead backed by the
/// referral-scoped `GET /referrals/patient-lookup` (gated by
/// `referrals.create`, so referrer employees — who lack
/// `clinic.patients.read` — can still search by number or name). The
/// submitted `patient_school_id` is the picked patient's exact school id,
/// or the raw typed value when the lookup misses (same as the web).
class _ReferralCreateSheet extends StatefulWidget {
  const _ReferralCreateSheet();

  @override
  State<_ReferralCreateSheet> createState() => _ReferralCreateSheetState();
}

class _ReferralCreateSheetState extends State<_ReferralCreateSheet> {
  final _patientController = TextEditingController();
  Timer? _debounce;
  List<PatientLookupResult> _suggestions = [];
  PatientLookupResult? _selected;
  String? _lookupError;
  bool _lookingUp = false;

  String? _source = 'clinic';
  String? _target = 'counselling';
  String _priority = 'normal';
  final _artifactController = TextEditingController();
  bool _customArtifact = false;
  final _reasonController = TextEditingController();
  final _notesController = TextEditingController();

  bool _submitting = false;
  String? _submitError;

  @override
  void initState() {
    super.initState();
    // Source/target default to clinic→counselling, so prefill the preset
    // artifact for that direction right away (mirrors the web dialog's
    // artifact prefill effect).
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _prefillArtifact();
    });
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _patientController.dispose();
    _artifactController.dispose();
    _reasonController.dispose();
    _notesController.dispose();
    super.dispose();
  }

  void _onPatientChanged(String value) {
    _debounce?.cancel();
    final q = value.trim();
    if (q.length < 2) {
      setState(() {
        _suggestions = [];
        _selected = null;
      });
      return;
    }
    _debounce = Timer(const Duration(milliseconds: 300), () async {
      setState(() => _lookingUp = true);
      try {
        final results = await ApiService.I.referralPatientLookup(q);
        if (!mounted) return;
        setState(() {
          _suggestions = results;
          _lookupError = null;
          _lookingUp = false;
        });
      } catch (e) {
        if (!mounted) return;
        setState(() {
          _lookupError = mapDioError(e).message;
          _lookingUp = false;
        });
      }
    });
  }

  void _pickSuggestion(PatientLookupResult s) {
    setState(() {
      _selected = s;
      _patientController.text = '${s.name} — ${s.schoolId}';
      _suggestions = [];
      _lookupError = null;
    });
  }

  void _submit() {
    final patientId = (_selected?.schoolId ?? _patientController.text.trim());
    final source = _source;
    final target = _target;
    final artifact = _artifactController.text.trim();
    if (patientId.isEmpty) {
      setState(() => _submitError =
          'Patient is required — pick a suggestion or type the school/employee ID.');
      return;
    }
    if (source == null || target == null || source == target) {
      setState(
          () => _submitError = 'Source and target modules must be different.');
      return;
    }
    if (artifact.isEmpty) {
      setState(() => _submitError = 'Artifact type is required.');
      return;
    }
    final reason = _reasonController.text.trim();
    final notes = _notesController.text.trim();
    setState(() {
      _submitting = true;
      _submitError = null;
    });
    final payload = <String, dynamic>{
      'patient_school_id': patientId,
      'source_module': source,
      'target_module': target,
      'artifact_type': artifact,
      'priority': _priority,
      if (reason.isNotEmpty) 'reason_code': reason,
      if (notes.isNotEmpty) 'notes_plaintext': notes,
    };
    Navigator.of(context).pop(payload);
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
              title: 'New referral',
              subtitle: 'Bridge a patient between clinic and counselling',
            ),
            const SizedBox(height: 16),
            TextField(
              controller: _patientController,
              onChanged: _onPatientChanged,
              enabled: !_submitting,
              decoration: InputDecoration(
                labelText: 'Patient (search by number or name)',
                border: const OutlineInputBorder(),
                isDense: true,
                suffixIcon: _lookingUp
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : null,
              ),
            ),
            const SizedBox(height: 8),
            if (_lookupError != null)
              Text(
                'Could not search: $_lookupError — you can still type the ID directly.',
                style: TextStyle(
                  color: Theme.of(context).colorScheme.error,
                  fontSize: 12,
                ),
              )
            else if (_suggestions.isNotEmpty)
              Container(
                constraints: const BoxConstraints(maxHeight: 150),
                decoration: BoxDecoration(
                  border: Border.all(
                    color: Theme.of(context).colorScheme.outlineVariant,
                  ),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: ListView(
                  shrinkWrap: true,
                  children: [
                    for (final s in _suggestions)
                      ListTile(
                        dense: true,
                        title: Text('${s.name} — ${s.schoolId}'),
                        subtitle: Text(titleCaseOption(s.kind)),
                        onTap: () => _pickSuggestion(s),
                      ),
                  ],
                ),
              )
            else if (_selected != null)
              Text(
                'Selected: ${_selected!.name} (#${_selected!.schoolId})',
                style: const TextStyle(
                  color: Color(0xFF1B7A43),
                  fontWeight: FontWeight.w600,
                  fontSize: 12,
                ),
              ),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: InputDecorator(
                    decoration: const InputDecoration(
                      labelText: 'Source',
                      border: OutlineInputBorder(),
                    ),
                    child: DropdownButtonHideUnderline(
                      child: DropdownButton<String?>(
                        value: _source,
                        isExpanded: true,
                        isDense: true,
                        items: [
                          for (final m in _moduleLabels.keys)
                            DropdownMenuItem(
                              value: m,
                              child: Text(_moduleLabels[m]!),
                            ),
                        ],
                        onChanged: _submitting
                            ? null
                            : (v) => setState(() {
                                  _source = v;
                                  _prefillArtifact();
                                }),
                      ),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: InputDecorator(
                    decoration: const InputDecoration(
                      labelText: 'Target',
                      border: OutlineInputBorder(),
                    ),
                    child: DropdownButtonHideUnderline(
                      child: DropdownButton<String?>(
                        value: _target,
                        isExpanded: true,
                        isDense: true,
                        items: [
                          for (final m in _moduleLabels.keys)
                            DropdownMenuItem(
                              value: m,
                              child: Text(_moduleLabels[m]!),
                            ),
                        ],
                        onChanged: _submitting
                            ? null
                            : (v) => setState(() {
                                  _target = v;
                                  _prefillArtifact();
                                }),
                      ),
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              initialValue: _priority,
              decoration: const InputDecoration(
                labelText: 'Priority',
                border: OutlineInputBorder(),
                isDense: true,
              ),
              items: const [
                DropdownMenuItem(value: 'low', child: Text('Low')),
                DropdownMenuItem(value: 'normal', child: Text('Normal')),
                DropdownMenuItem(value: 'high', child: Text('High')),
                DropdownMenuItem(value: 'urgent', child: Text('Urgent')),
              ],
              onChanged: _submitting
                  ? null
                  : (v) => setState(() => _priority = v ?? 'normal'),
            ),
            const SizedBox(height: 12),
            if (_customArtifact)
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: TextField(
                      controller: _artifactController,
                      enabled: !_submitting,
                      decoration: const InputDecoration(
                        labelText: 'Artifact type (custom)',
                        border: OutlineInputBorder(),
                        isDense: true,
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Padding(
                    padding: const EdgeInsets.only(top: 2),
                    child: OutlinedButton(
                      onPressed: _submitting ? null : _usePresetArtifact,
                      child: const Text('Use preset'),
                    ),
                  ),
                ],
              )
            else
              InputDecorator(
                decoration: const InputDecoration(
                  labelText: 'Artifact type',
                  border: OutlineInputBorder(),
                  isDense: true,
                ),
                child: DropdownButtonHideUnderline(
                  child: DropdownButton<String?>(
                    value: _artifactController.text == 'intake_pass' ||
                            _artifactController.text == 'referral_letter'
                        ? _artifactController.text
                        : 'custom',
                    isExpanded: true,
                    isDense: true,
                    items: const [
                      DropdownMenuItem(
                        value: 'intake_pass',
                        child: Text('Intake pass'),
                      ),
                      DropdownMenuItem(
                        value: 'referral_letter',
                        child: Text('Referral letter'),
                      ),
                      DropdownMenuItem(
                        value: 'custom',
                        child: Text('Custom…'),
                      ),
                    ],
                    onChanged: _submitting ? null : _onArtifactChanged,
                  ),
                ),
              ),
            const SizedBox(height: 12),
            TextField(
              controller: _reasonController,
              enabled: !_submitting,
              maxLength: 64,
              decoration: const InputDecoration(
                labelText: 'Reason code (optional)',
                border: OutlineInputBorder(),
                isDense: true,
              ),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _notesController,
              enabled: !_submitting,
              maxLength: 8000,
              minLines: 2,
              maxLines: 4,
              decoration: const InputDecoration(
                labelText: 'Notes (optional)',
                border: OutlineInputBorder(),
                alignLabelWithHint: true,
              ),
            ),
            if (_submitError != null) ...[
              const SizedBox(height: 8),
              Text(
                _submitError!,
                style: TextStyle(
                  color: Theme.of(context).colorScheme.error,
                  fontSize: 13,
                ),
              ),
            ],
            const SizedBox(height: 12),
            FilledButton(
              onPressed: _submitting ? null : _submit,
              style: FilledButton.styleFrom(
                backgroundColor: const Color(0xFF800000),
                padding: const EdgeInsets.symmetric(vertical: 14),
              ),
              child: const Text('Create referral'),
            ),
          ],
        ),
      ),
    );
  }

  /// Once the direction is known and the artifact field is still empty,
  /// prefill a sensible artifact (mirrors the web dialog's
  /// `PRESET_ARTIFACT[source]`).
  void _prefillArtifact() {
    final source = _source;
    if (!_customArtifact &&
        source != null &&
        _artifactController.text.trim().isEmpty) {
      _artifactController.text = _presetArtifacts[source] ?? '';
    }
  }

  /// Artifact dropdown handler — mirrors the web Select: picking a preset
  /// stores the token directly; picking `custom` switches to free text.
  void _onArtifactChanged(String? v) {
    if (v == null) return;
    if (v == 'custom') {
      setState(() {
        _customArtifact = true;
        _artifactController.text = '';
      });
    } else {
      setState(() {
        _customArtifact = false;
        _artifactController.text = v;
      });
    }
  }

  /// "Use preset" — exits custom mode and restores the source's default.
  void _usePresetArtifact() {
    setState(() {
      _customArtifact = false;
      _artifactController.text = _presetArtifacts[_source ?? 'clinic'] ?? '';
    });
  }
}

/// Bridge an accepted clinic→counselling referral into an actual appointment.
/// Mirrors the SPA `ReferralBookingDialog`; the chosen slot must still fall
/// inside the counsellor's active availability window.
class _BookReferralSheet extends StatefulWidget {
  const _BookReferralSheet({required this.referral});

  final Referral referral;

  @override
  State<_BookReferralSheet> createState() => _BookReferralSheetState();
}

class _BookReferralSheetState extends State<_BookReferralSheet> {
  List<Map<String, dynamic>>? _providers;
  String? _providersError;
  int? _counsellorId;
  DateTime _date = DateTime.now().add(const Duration(days: 1));
  TimeOfDay _start = const TimeOfDay(hour: 9, minute: 0);
  TimeOfDay _end = const TimeOfDay(hour: 10, minute: 0);
  late final _reason = TextEditingController(
    text: 'Referred from ${widget.referral.sourceModule} — '
        'referral #${widget.referral.id}',
  );
  bool _submitting = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadProviders();
  }

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  Future<void> _loadProviders() async {
    try {
      final providers = await ApiService.I.counsellingProviders();
      if (!mounted) return;
      int? selected;
      final preferred = widget.referral.providerUserId;
      if (preferred != null &&
          providers.any((p) => (p['id'] as num?)?.toInt() == preferred)) {
        selected = preferred;
      } else if (providers.isNotEmpty) {
        selected = (providers.first['id'] as num).toInt();
      }
      setState(() {
        _providers = providers;
        _counsellorId = selected;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() => _providersError = mapDioError(e).message);
    }
  }

  String _hhmm(TimeOfDay value) =>
      '${value.hour.toString().padLeft(2, '0')}:'
      '${value.minute.toString().padLeft(2, '0')}';

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _date,
      firstDate: DateTime.now(),
      lastDate: DateTime.now().add(const Duration(days: 180)),
    );
    if (picked != null) setState(() => _date = picked);
  }

  Future<void> _submit() async {
    if (_counsellorId == null) {
      setState(() => _error = 'Select a counsellor.');
      return;
    }
    if (_hhmm(_start).compareTo(_hhmm(_end)) >= 0) {
      setState(() => _error = 'End time must be after start time.');
      return;
    }
    setState(() {
      _submitting = true;
      _error = null;
    });
    try {
      await ApiService.I.bookCounsellingAppointment({
        'patient_school_id': widget.referral.patientSchoolId,
        'counsellor_user_id': _counsellorId,
        'appointment_date': toDateInput(_date),
        'start_time': _hhmm(_start),
        'end_time': _hhmm(_end),
        'type': 'referral_based',
        if (_reason.text.trim().isNotEmpty) 'reason': _reason.text.trim(),
      });
      if (!mounted) return;
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _submitting = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = mapDioError(e).message;
        _submitting = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final providers = _providers;
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
            SheetHeader(
              title: 'Book counselling',
              subtitle:
                  'Referral #${widget.referral.id} · ${widget.referral.patientSchoolId}',
            ),
            const SizedBox(height: 12),
            if (providers == null && _providersError == null)
              const Padding(
                padding: EdgeInsets.all(8),
                child: Center(
                  child: SizedBox(
                    width: 22,
                    height: 22,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  ),
                ),
              )
            else if (_providersError != null)
              Text(
                'Could not load counsellors: $_providersError',
                style: TextStyle(
                  color: Theme.of(context).colorScheme.error,
                  fontSize: 12,
                ),
              )
            else if (providers!.isEmpty)
              const Text(
                'No enrolled counsellors are available. Enroll counsellors '
                'and add availability windows first.',
                style: TextStyle(fontSize: 12, color: Colors.black54),
              )
            else
              InputDecorator(
                decoration: const InputDecoration(
                  labelText: 'Counsellor',
                  border: OutlineInputBorder(),
                  isDense: true,
                ),
                child: DropdownButtonHideUnderline(
                  child: DropdownButton<int>(
                    value: _counsellorId,
                    isExpanded: true,
                    isDense: true,
                    items: [
                      for (final provider in providers)
                        DropdownMenuItem(
                          value: (provider['id'] as num).toInt(),
                          child: Text(
                            (provider['name'] ?? 'Counsellor') as String,
                          ),
                        ),
                    ],
                    onChanged: _submitting
                        ? null
                        : (value) => setState(() => _counsellorId = value),
                  ),
                ),
              ),
            const SizedBox(height: 10),
            OutlinedButton.icon(
              onPressed: _submitting ? null : _pickDate,
              icon: const Icon(HugeIcons.strokeRoundedCalendar01, size: 17),
              label: Text(toDateInput(_date)),
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _submitting
                        ? null
                        : () async {
                            final picked = await showTwelveHourTimePicker(
                              context: context,
                              initialTime: _start,
                            );
                            if (picked != null) {
                              setState(() => _start = picked);
                            }
                          },
                    icon: const Icon(HugeIcons.strokeRoundedClock01, size: 17),
                    label: Text('Start ${_hhmm(_start)}'),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _submitting
                        ? null
                        : () async {
                            final picked = await showTwelveHourTimePicker(
                              context: context,
                              initialTime: _end,
                            );
                            if (picked != null) {
                              setState(() => _end = picked);
                            }
                          },
                    icon: const Icon(HugeIcons.strokeRoundedClock01, size: 17),
                    label: Text('End ${_hhmm(_end)}'),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
            TextField(
              controller: _reason,
              maxLength: 255,
              decoration: const InputDecoration(
                labelText: 'Reason',
                border: OutlineInputBorder(),
                isDense: true,
              ),
            ),
            const Text(
              'The slot must fall inside the counsellor’s active '
              'availability window.',
              style: TextStyle(fontSize: 11, color: Colors.black45),
            ),
            if (_error != null) ...[
              const SizedBox(height: 8),
              Text(
                _error!,
                style: TextStyle(
                  color: Theme.of(context).colorScheme.error,
                  fontSize: 12.5,
                ),
              ),
            ],
            const SizedBox(height: 12),
            FilledButton(
              onPressed: _submitting ? null : _submit,
              child: _submitting
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Text('Book appointment'),
            ),
          ],
        ),
      ),
    );
  }
}
