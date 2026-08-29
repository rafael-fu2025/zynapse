import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/models/clinic.dart';
import '../../core/models/medicine.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
import '../../core/utils/dates.dart';
import '../common/crud_form.dart';
import '../common/session_progress_tracker.dart';

class ClinicSessionWorkspace extends StatefulWidget {
  const ClinicSessionWorkspace({super.key, required this.encounterId});

  final int encounterId;

  @override
  State<ClinicSessionWorkspace> createState() => _ClinicSessionWorkspaceState();
}

class _ClinicSessionWorkspaceState extends State<ClinicSessionWorkspace> {
  ClinicEncounter? _encounter;
  List<ClinicVitals> _vitals = [];
  List<ClinicTreatment> _treatments = [];
  PreviousHeightWeight? _previous;
  bool _loading = true;
  bool _saving = false;
  String? _error;
  String _step = 'vitals';

  final _vitalControllers = <String, TextEditingController>{
    for (final key in const [
      'bp_systolic',
      'bp_diastolic',
      'pulse_bpm',
      'temp_c',
      'spo2_pct',
      'weight_kg',
      'height_cm',
    ])
      key: TextEditingController(),
  };
  final _diagnosis = TextEditingController();
  final _treatmentDescription = TextEditingController();
  final _medicineSearch = TextEditingController();
  final _quantity = TextEditingController();
  final _referralReason = TextEditingController();
  final _referralNotes = TextEditingController();
  String? _triage;
  String _treatmentType = 'first_aid';
  List<Medicine> _medicineOptions = [];
  Medicine? _selectedMedicine;
  Timer? _medicineDebounce;

  bool get _canWrite =>
      context
          .read<AuthController>()
          .session
          ?.hasPermission('clinic.encounters.write') ??
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
    _load();
  }

  @override
  void dispose() {
    _medicineDebounce?.cancel();
    for (final controller in _vitalControllers.values) {
      controller.dispose();
    }
    _diagnosis.dispose();
    _treatmentDescription.dispose();
    _medicineSearch.dispose();
    _quantity.dispose();
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
      final results = await Future.wait<dynamic>([
        ApiService.I.encounterDetail(widget.encounterId),
        ApiService.I.typedEncounterVitals(widget.encounterId),
        ApiService.I.typedEncounterTreatments(widget.encounterId),
        ApiService.I.previousHeightWeight(widget.encounterId),
      ]);
      if (!mounted) return;
      final encounter = results[0] as ClinicEncounter;
      final previous = results[3] as PreviousHeightWeight?;
      setState(() {
        _encounter = encounter;
        _vitals = results[1] as List<ClinicVitals>;
        _treatments = results[2] as List<ClinicTreatment>;
        _previous = previous;
        _triage = encounter.triagePriority;
        if (_diagnosis.text.isEmpty) {
          _diagnosis.text = encounter.diagnosis ?? '';
        }
        if (_vitalControllers['weight_kg']!.text.isEmpty &&
            previous?.weightKg != null) {
          _vitalControllers['weight_kg']!.text = _number(previous!.weightKg!);
        }
        if (_vitalControllers['height_cm']!.text.isEmpty &&
            previous?.heightCm != null) {
          _vitalControllers['height_cm']!.text = _number(previous!.heightCm!);
        }
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

  static String _number(double value) => value == value.roundToDouble()
      ? value.toInt().toString()
      : value.toString();

  List<SessionProgressStep> get _steps {
    final encounter = _encounter!;
    return [
      const SessionProgressStep(
          id: 'started',
          label: 'Started',
          summary: 'Visit opened',
          state: SessionStepState.complete),
      SessionProgressStep(
          id: 'vitals',
          label: 'Vitals',
          summary: encounter.vitalsCount > 0
              ? '${encounter.vitalsCount} recorded'
              : 'Not recorded',
          state: encounter.vitalsCount > 0
              ? SessionStepState.complete
              : SessionStepState.current),
      SessionProgressStep(
          id: 'assessment',
          label: 'Assessment',
          summary: encounter.assessmentRecorded ? 'Recorded' : 'Not recorded',
          state: encounter.assessmentRecorded
              ? SessionStepState.complete
              : SessionStepState.current),
      SessionProgressStep(
          id: 'care',
          label: 'Care',
          summary: encounter.treatmentCount > 0
              ? '${encounter.treatmentCount} treatment(s)'
              : 'Optional',
          state: encounter.treatmentCount > 0
              ? SessionStepState.complete
              : SessionStepState.optional),
      SessionProgressStep(
          id: 'referral',
          label: 'Referral',
          summary: encounter.outgoingReferral != null
              ? '#${encounter.outgoingReferral!.id}'
              : _canRefer
                  ? 'Optional'
                  : 'Unavailable',
          state: encounter.outgoingReferral != null
              ? SessionStepState.complete
              : _canRefer
                  ? SessionStepState.optional
                  : SessionStepState.unavailable),
      SessionProgressStep(
          id: 'complete',
          label: 'Complete',
          summary: encounter.isOpen ? 'Pending' : 'Finished',
          state: encounter.isOpen
              ? SessionStepState.available
              : SessionStepState.complete),
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

  Map<String, dynamic> _vitalsPayload() {
    final payload = <String, dynamic>{};
    for (final entry in _vitalControllers.entries) {
      final raw = entry.value.text.trim();
      if (raw.isEmpty) continue;
      final value = num.tryParse(raw);
      if (value != null) payload[entry.key] = value;
    }
    return payload;
  }

  Future<void> _saveVitals() => _run(() async {
        final payload = _vitalsPayload();
        if (payload.isEmpty) {
          throw const FormatException('Enter at least one measurement.');
        }
        await ApiService.I.recordEncounterVitals(widget.encounterId, payload);
      }, 'Vitals recorded.');

  Future<void> _saveAssessment() => _run(() async {
        await ApiService.I.setEncounterAssessment(widget.encounterId, {
          if (_triage != null) 'triage_priority': _triage,
          'diagnosis': _diagnosis.text.trim(),
        });
      }, 'Assessment saved.');

  void _searchMedicines(String query) {
    _selectedMedicine = null;
    _medicineDebounce?.cancel();
    _medicineDebounce = Timer(const Duration(milliseconds: 300), () async {
      if (query.trim().length < 2) {
        if (mounted) setState(() => _medicineOptions = []);
        return;
      }
      try {
        final page = await ApiService.I.medicines(q: query.trim(), limit: 8);
        if (mounted) {
          setState(() =>
              _medicineOptions = page.items.where((m) => !m.archived).toList());
        }
      } catch (_) {
        if (mounted) setState(() => _medicineOptions = []);
      }
    });
  }

  Future<void> _addTreatment() => _run(() async {
        final description = _treatmentDescription.text.trim();
        if (description.isEmpty) {
          throw const FormatException('Treatment description is required.');
        }
        if (_treatmentType == 'medication' && _selectedMedicine == null) {
          throw const FormatException(
              'Select a medicine from the search results.');
        }
        final quantity = int.tryParse(_quantity.text.trim());
        if (_treatmentType == 'medication' &&
            (quantity == null || quantity < 1)) {
          throw const FormatException('Enter a valid medicine quantity.');
        }
        await ApiService.I.addEncounterTreatment(widget.encounterId, {
          'treatment_type': _treatmentType,
          'description': description,
          if (_selectedMedicine != null) 'medicine_id': _selectedMedicine!.id,
          if (quantity != null) 'quantity': quantity,
        });
        _treatmentDescription.clear();
        _medicineSearch.clear();
        _quantity.clear();
        _selectedMedicine = null;
        _medicineOptions = [];
      }, 'Treatment recorded.');

  Future<void> _refer() => _run(() async {
        await ApiService.I.createEncounterReferral(
          widget.encounterId,
          reasonCode: _referralReason.text,
          notes: _referralNotes.text,
        );
      }, 'Referral submitted to Guidance.');

  Future<void> _complete() async {
    final confirmed = await showCrudConfirm(
      context,
      title: 'Complete encounter?',
      message: 'Are you sure you want to complete this encounter?',
      confirmLabel: 'Complete',
      destructive: true,
    );
    if (!confirmed || !mounted) return;
    await _run(() async {
      await ApiService.I.completeEncounter(widget.encounterId);
    }, 'Encounter completed.');
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text('Encounter #${widget.encounterId}')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(
                  child: Padding(
                      padding: const EdgeInsets.all(24),
                      child: Column(mainAxisSize: MainAxisSize.min, children: [
                        Text(_error!, textAlign: TextAlign.center),
                        const SizedBox(height: 12),
                        FilledButton(
                            onPressed: _load, child: const Text('Try again'))
                      ])))
              : Column(children: [
                  SessionProgressTracker(
                      steps: _steps,
                      selectedId: _step,
                      onSelected: (value) => setState(() => _step = value)),
                  const Divider(height: 1),
                  Expanded(
                      child: SingleChildScrollView(
                          padding: const EdgeInsets.all(16),
                          child: _buildStep())),
                ]),
    );
  }

  Widget _buildStep() => switch (_step) {
        'started' => _startedStep(),
        'vitals' => _vitalsStep(),
        'assessment' => _assessmentStep(),
        'care' => _careStep(),
        'referral' => _referralStep(),
        _ => _completeStep(),
      };

  Widget _section(String title, String description, List<Widget> children) =>
      Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(title, style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 4),
          Text(description,
              style: TextStyle(
                  color: Theme.of(context).colorScheme.onSurfaceVariant)),
          const SizedBox(height: 16),
          ...children
        ],
      );

  Widget _startedStep() {
    final e = _encounter!;
    return _section('Encounter started',
        'Patient and visit context remain visible throughout the workflow.', [
      _InfoRow('Patient', '${e.patientDisplayName} (${e.patientSchoolId})'),
      _InfoRow('Complaint', e.chiefComplaint),
      _InfoRow('Started', fmtUtcToApp(e.startedAt)),
      _InfoRow('Queue', e.queueNumber ?? 'Manual encounter'),
      _InfoRow('Incoming referral',
          e.incomingReferralId == null ? '—' : '#${e.incomingReferralId}'),
    ]);
  }

  Widget _vitalsStep() => _section(
          'Vitals',
          'Height and weight may reuse the previous visit. Measure all visit-specific vitals now.',
          [
            if (_previous != null)
              Container(
                  margin: const EdgeInsets.only(bottom: 12),
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                      color: const Color(0xFFFFF7E6),
                      borderRadius: BorderRadius.circular(12)),
                  child: Text(
                      'Height/weight prefilled from encounter #${_previous!.sourceEncounterId} (${fmtUtcToApp(_previous!.recordedAt)}). You can replace either value.')),
            Wrap(spacing: 10, runSpacing: 10, children: [
              _numberField('BP systolic', 'bp_systolic'),
              _numberField('BP diastolic', 'bp_diastolic'),
              _numberField('Pulse', 'pulse_bpm'),
              _numberField('Temperature °C', 'temp_c'),
              _numberField('SpO₂ %', 'spo2_pct'),
              _numberField('Weight kg', 'weight_kg'),
              _numberField('Height cm', 'height_cm'),
            ]),
            if (_canWrite && _encounter!.isOpen)
              Padding(
                  padding: const EdgeInsets.only(top: 16),
                  child: FilledButton.icon(
                      onPressed: _saving ? null : _saveVitals,
                      icon: const Icon(Icons.monitor_heart_outlined),
                      label: const Text('Save vitals'))),
            if (_vitals.isNotEmpty) ...[
              const SizedBox(height: 20),
              Text('History', style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: 8),
              for (final v in _vitals.reversed)
                Card(
                    child: ListTile(
                        title: Text(
                            'BP ${v.bpSystolic ?? '—'}/${v.bpDiastolic ?? '—'} · Pulse ${v.pulseBpm ?? '—'} · SpO₂ ${v.spo2Pct ?? '—'}%'),
                        subtitle: Text(fmtUtcToApp(v.recordedAt))))
            ],
          ]);

  Widget _numberField(String label, String key) => SizedBox(
      width: 155,
      child: TextField(
          controller: _vitalControllers[key],
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: InputDecoration(
              labelText: label, border: const OutlineInputBorder())));

  Widget _assessmentStep() => _section('Assessment / Progress',
          'Record triage and diagnosis or progress notes before completion.', [
        DropdownButtonFormField<String>(
            initialValue: _triage,
            decoration: const InputDecoration(
                labelText: 'Triage priority', border: OutlineInputBorder()),
            items: const ['low', 'medium', 'high', 'urgent']
                .map((v) => DropdownMenuItem(
                    value: v, child: Text(v[0].toUpperCase() + v.substring(1))))
                .toList(),
            onChanged: _canWrite && _encounter!.isOpen
                ? (v) => setState(() => _triage = v)
                : null),
        const SizedBox(height: 12),
        TextField(
            controller: _diagnosis,
            minLines: 4,
            maxLines: 8,
            maxLength: 5000,
            readOnly: !_canWrite || !_encounter!.isOpen,
            decoration: const InputDecoration(
                labelText: 'Diagnosis / progress notes',
                alignLabelWithHint: true,
                border: OutlineInputBorder())),
        if (_canWrite && _encounter!.isOpen)
          FilledButton.icon(
              onPressed: _saving ? null : _saveAssessment,
              icon: const Icon(Icons.save_outlined),
              label: const Text('Save assessment')),
      ]);

  Widget _careStep() => _section(
          'Care and treatment',
          'Treatments and medication dispensing remain anchored to this encounter.',
          [
            if (_treatments.isEmpty)
              const Text('No treatments recorded.')
            else
              for (final treatment in _treatments)
                Card(
                    child: ListTile(
                        title: Text(
                            '${treatment.type.replaceAll('_', ' ')} · ${treatment.medicineName ?? treatment.description}'),
                        subtitle: Text(
                            '${treatment.quantityUsed == null ? '' : '${treatment.quantityUsed} ${treatment.unit ?? ''} · '}${fmtUtcToApp(treatment.administeredAt)}'))),
            if (_canWrite && _encounter!.isOpen) ...[
              const SizedBox(height: 16),
              DropdownButtonFormField<String>(
                  initialValue: _treatmentType,
                  decoration: const InputDecoration(
                      labelText: 'Treatment type',
                      border: OutlineInputBorder()),
                  items: const [
                    'medication',
                    'first_aid',
                    'procedure',
                    'referral',
                    'other'
                  ]
                      .map((v) => DropdownMenuItem(
                          value: v, child: Text(v.replaceAll('_', ' '))))
                      .toList(),
                  onChanged: (v) => setState(() {
                        _treatmentType = v ?? 'other';
                        _selectedMedicine = null;
                      })),
              if (_treatmentType == 'medication') ...[
                const SizedBox(height: 12),
                TextField(
                    controller: _medicineSearch,
                    onChanged: _searchMedicines,
                    decoration: InputDecoration(
                        labelText: 'Search medicine',
                        helperText: _selectedMedicine == null
                            ? 'Type at least 2 characters, then select a result'
                            : 'Selected: ${_selectedMedicine!.displayName}',
                        border: const OutlineInputBorder(),
                        prefixIcon: const Icon(Icons.search))),
                if (_medicineOptions.isNotEmpty)
                  Card(
                      child: Column(children: [
                    for (final medicine in _medicineOptions)
                      ListTile(
                          title: Text(medicine.displayName),
                          subtitle: Text(
                              '${medicine.quantityOnHand} ${medicine.unit} in stock'),
                          onTap: () => setState(() {
                                _selectedMedicine = medicine;
                                _medicineSearch.text = medicine.displayName;
                                _medicineOptions = [];
                              }))
                  ])),
                const SizedBox(height: 12),
                TextField(
                    controller: _quantity,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(
                        labelText: 'Quantity', border: OutlineInputBorder())),
              ],
              const SizedBox(height: 12),
              TextField(
                  controller: _treatmentDescription,
                  maxLength: 2000,
                  decoration: const InputDecoration(
                      labelText: 'Description', border: OutlineInputBorder())),
              FilledButton.icon(
                  onPressed: _saving ? null : _addTreatment,
                  icon: const Icon(Icons.add),
                  label: const Text('Add treatment')),
            ],
          ]);

  Widget _referralStep() {
    final referral = _encounter!.outgoingReferral;
    return _section(
        'Guidance referral',
        'Referral is optional and does not automatically complete this encounter.',
        [
          if (referral != null)
            Card(
                child: ListTile(
                    title: Text('Referral #${referral.id}'),
                    subtitle: Text(
                        'Clinic → Guidance · ${referral.status.replaceAll('_', ' ')}'),
                    leading: const Icon(Icons.check_circle,
                        color: Color(0xFF1B7A43))))
          else if (!_canRefer)
            const Text('You do not have permission to create referrals.')
          else if (_encounter!.isOpen) ...[
            TextField(
                controller: _referralReason,
                maxLength: 64,
                decoration: const InputDecoration(
                    labelText: 'Reason (optional)',
                    border: OutlineInputBorder())),
            const SizedBox(height: 12),
            TextField(
                controller: _referralNotes,
                minLines: 3,
                maxLines: 6,
                maxLength: 8192,
                decoration: const InputDecoration(
                    labelText: 'Referral notes (optional)',
                    helperText: 'Share only information Guidance needs.',
                    border: OutlineInputBorder())),
            FilledButton.icon(
                onPressed: _saving ? null : _refer,
                icon: const Icon(Icons.arrow_forward),
                label: const Text('Refer to Guidance')),
          ],
        ]);
  }

  Widget _completeStep() {
    final e = _encounter!;
    final missing = [
      if (e.vitalsCount == 0) 'vitals',
      if (!e.assessmentRecorded) 'assessment'
    ];
    return _section(
        'Complete encounter',
        'Review the record before finishing. Queue and appointment state will be synchronized.',
        [
          if (!e.isOpen)
            const Card(
                child: ListTile(
                    leading: Icon(Icons.check_circle, color: Color(0xFF1B7A43)),
                    title: Text('Encounter completed')))
          else ...[
            if (missing.isNotEmpty)
              Container(
                  margin: const EdgeInsets.only(bottom: 12),
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                      color: const Color(0xFFFFF7E6),
                      borderRadius: BorderRadius.circular(12)),
                  child: Text(
                      'Recommended before completion: ${missing.join(' and ')}. These are warnings, not hard blockers.')),
            if (_canWrite)
              FilledButton.icon(
                  style: FilledButton.styleFrom(
                      backgroundColor: const Color(0xFFB3261E)),
                  onPressed: _saving ? null : _complete,
                  icon: const Icon(Icons.check_circle_outline),
                  label: const Text('Complete encounter')),
          ],
        ]);
  }
}

class _InfoRow extends StatelessWidget {
  const _InfoRow(this.label, this.value);
  final String label;
  final String value;
  @override
  Widget build(BuildContext context) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        SizedBox(
            width: 120,
            child: Text(label,
                style: TextStyle(
                    color: Theme.of(context).colorScheme.onSurfaceVariant))),
        Expanded(
            child: Text(value,
                style: const TextStyle(fontWeight: FontWeight.w600)))
      ]));
}
