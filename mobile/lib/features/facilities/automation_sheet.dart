import 'dart:async';

import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';

import '../../core/api/api_client.dart';
import '../../core/api/api_envelope.dart';
import '../../core/models/bmg_automation.dart';
import '../../core/services/api_service.dart';
import '../../core/utils/dates.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';

/// Per-unit automation — board liveness, RTC health, the run schedule,
/// operator commands and recent runs. Mirrors the SPA `AutomationPanel`.
Future<void> showUnitAutomationSheet(
  BuildContext context, {
  required int unitId,
  required String unitLabel,
  required bool canManage,
}) {
  return showSynapseSheet<void>(
    context,
    builder: (_) => _AutomationSheet(
      unitId: unitId,
      unitLabel: unitLabel,
      canManage: canManage,
    ),
  );
}

class _AutomationSheet extends StatefulWidget {
  const _AutomationSheet({
    required this.unitId,
    required this.unitLabel,
    required this.canManage,
  });

  final int unitId;
  final String unitLabel;
  final bool canManage;

  @override
  State<_AutomationSheet> createState() => _AutomationSheetState();
}

class _AutomationSheetState extends State<_AutomationSheet> {
  BmgUnitMonitor? _monitor;
  List<BmgDeviceCommand> _commands = const [];
  bool _loading = true;
  bool _busy = false;
  String? _error;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _load();
    _timer = Timer.periodic(const Duration(seconds: 60), (_) => _load(silent: true));
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _load({bool silent = false}) async {
    if (!silent) {
      setState(() {
        _loading = _monitor == null;
        _error = null;
      });
    }
    try {
      final monitor = await ApiService.I.facilityUnitMonitor(widget.unitId);
      List<BmgDeviceCommand> commands = const [];
      final device = monitor.device;
      if (device != null) {
        commands = await ApiService.I.facilityDeviceCommands(device.id);
      }
      if (!mounted) return;
      setState(() {
        _monitor = monitor;
        _commands = commands;
        _loading = false;
        _error = null;
      });
    } catch (e) {
      if (!mounted) return;
      if (silent) return;
      setState(() {
        _error = mapDioError(e).message;
        _loading = false;
      });
    }
  }

  Future<void> _send(String command) async {
    final device = _monitor?.device;
    if (device == null || _busy) return;
    setState(() => _busy = true);
    try {
      await ApiService.I.sendFacilityDeviceCommand(device.id, command);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('${titleCaseOption(command)} command queued.')),
      );
      await _load(silent: true);
    } on ApiException catch (e) {
      _snack(e.message, error: true);
    } catch (e) {
      _snack(mapDioError(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _editSchedule() async {
    final monitor = _monitor;
    if (monitor == null || _busy) return;
    final payload = await showSynapseSheet<Map<String, dynamic>>(
      context,
      builder: (_) => _ScheduleSheet(initial: monitor.schedule),
    );
    if (payload == null || !mounted) return;
    setState(() => _busy = true);
    try {
      final saved = await ApiService.I.saveFacilityUnitSchedule(
        widget.unitId,
        payload,
      );
      if (!mounted) return;
      _snack(
        'Schedule v${saved.version} published — the drum applies it on its '
        'next check-in.',
      );
      await _load(silent: true);
    } on ApiException catch (e) {
      _snack(e.message, error: true);
    } catch (e) {
      _snack(mapDioError(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _snack(String message, {bool error = false}) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: error ? const Color(0xFFB3261E) : null,
      ),
    );
  }

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
              title: '${widget.unitLabel} automation',
              subtitle: 'Controller, run schedule and run history',
            ),
            const SizedBox(height: 10),
            if (_loading)
              SizedBox(height: 220, child: AsyncState.loading())
            else if (_error != null)
              SizedBox(
                height: 220,
                child: AsyncState.error(_error!, onRetry: _load),
              )
            else
              Flexible(
                child: ListView(
                  shrinkWrap: true,
                  children: [
                    _deviceCard(),
                    const SizedBox(height: 12),
                    _scheduleCard(),
                    const SizedBox(height: 12),
                    _runsCard(),
                    const SizedBox(height: 12),
                    _commandsCard(),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _deviceCard() {
    final device = _monitor?.device;
    if (device == null) {
      return _Panel(
        title: 'Controller',
        child: const Text(
          'No controller is bound to this drum. Bind a device from the '
          'Devices module.',
          style: TextStyle(fontSize: 12.5, color: Colors.black54),
        ),
      );
    }
    final heartbeat = device.heartbeatAgeMinutes == null
        ? 'No heartbeat yet'
        : '${device.heartbeatAgeMinutes} min ago';
    final rtc = device.rtcValid == null
        ? 'No RTC data'
        : device.rtcValid!
            ? 'RTC valid'
            : 'RTC offset ${device.rtcOffsetSeconds ?? 0}s';
    return _Panel(
      title: 'Controller — ${device.code}',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              StatusBadge(
                label: device.online ? 'Online' : 'Offline',
                color: device.online
                    ? const Color(0xFF1B7A43)
                    : const Color(0xFFB3261E),
              ),
              const SizedBox(width: 8),
              StatusBadge(
                label: titleCaseOption(device.status),
                color: device.status == 'active'
                    ? const Color(0xFF1E6FD9)
                    : Colors.black45,
              ),
            ],
          ),
          const SizedBox(height: 8),
          _fact('Heartbeat', heartbeat),
          _fact('Last seen', device.lastSeenAt == null
              ? '—'
              : fmtUtcShort(device.lastSeenAt)),
          _fact('RTC', rtc),
          _fact('Motor', device.motorState ?? '—'),
          _fact('Firmware', device.firmware ?? '—'),
          if (device.lastRssiDbm != null)
            _fact('WiFi', '${device.lastRssiDbm} dBm'),
          if (widget.canManage) ...[
            const SizedBox(height: 10),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                OutlinedButton.icon(
                  onPressed: _busy ? null : () => _send('run_now'),
                  icon: const Icon(HugeIcons.strokeRoundedPlaylist01,
                      size: 17),
                  label: const Text('Run now'),
                ),
                OutlinedButton.icon(
                  onPressed: _busy ? null : () => _send('start'),
                  icon: const Icon(HugeIcons.strokeRoundedPowerSocket01,
                      size: 17),
                  label: const Text('Start'),
                ),
                OutlinedButton.icon(
                  onPressed: _busy ? null : () => _send('stop'),
                  icon: const Icon(HugeIcons.strokeRoundedCancelCircle,
                      size: 17),
                  label: const Text('Stop'),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  Widget _scheduleCard() {
    final monitor = _monitor!;
    final schedule = monitor.schedule;
    return _Panel(
      title: 'Run schedule',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              StatusBadge(
                label: schedule.enabled ? 'Enabled' : 'Off',
                color: schedule.enabled
                    ? const Color(0xFF1B7A43)
                    : Colors.black45,
              ),
              const SizedBox(width: 8),
              StatusBadge(
                label: 'v${schedule.version}',
                color: const Color(0xFF1E6FD9),
              ),
            ],
          ),
          const SizedBox(height: 8),
          _fact(
            'Run times',
            schedule.times.isEmpty ? '—' : schedule.times.join(', '),
          ),
          _fact('Duration', '${schedule.durationSeconds}s'),
          _fact('Motor speed', 'PWM ${schedule.pwm}'),
          _fact(
            'Next run',
            monitor.nextRunAt == null
                ? '—'
                : fmtUtcShort(monitor.nextRunAt),
          ),
          if (monitor.missedCount > 0)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                '${monitor.missedCount} missed slot(s): '
                '${monitor.missedTimes.join(', ')}',
                style: const TextStyle(
                  fontSize: 11.5,
                  color: Color(0xFFB3261E),
                ),
              ),
            ),
          if (widget.canManage) ...[
            const SizedBox(height: 10),
            OutlinedButton.icon(
              onPressed: _busy ? null : _editSchedule,
              icon: const Icon(HugeIcons.strokeRoundedCalendar02, size: 17),
              label: const Text('Edit schedule'),
            ),
          ],
        ],
      ),
    );
  }

  Widget _runsCard() {
    final runs = _monitor!.recentRuns.take(5).toList();
    return _Panel(
      title: 'Recent runs',
      child: runs.isEmpty
          ? const Text(
              'No runs recorded.',
              style: TextStyle(fontSize: 12, color: Colors.black45),
            )
          : Column(
              children: [
                for (final run in runs)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    dense: true,
                    title: Text(
                      '${titleCaseOption(run.origin)} · ${run.durationSeconds}s'
                      '${run.turnsCount != null ? ' · ${run.turnsCount} turns' : ''}',
                      style: const TextStyle(fontSize: 12.5),
                    ),
                    subtitle: Text(
                      run.startedAt == null
                          ? fmtUtcShort(run.createdAt)
                          : fmtUtcShort(run.startedAt),
                      style: const TextStyle(fontSize: 11),
                    ),
                    trailing: StatusBadge(
                      label: titleCaseOption(run.result),
                      color: _runColor(run.result),
                    ),
                  ),
                if (_monitor!.failedCount > 0)
                  Align(
                    alignment: Alignment.centerLeft,
                    child: Text(
                      '${_monitor!.failedCount} failed run(s) in the recent window.',
                      style: const TextStyle(
                        fontSize: 11.5,
                        color: Color(0xFFB3261E),
                      ),
                    ),
                  ),
              ],
            ),
    );
  }

  Widget _commandsCard() {
    if (_commands.isEmpty) return const SizedBox.shrink();
    return _Panel(
      title: 'Recent commands',
      child: Column(
        children: [
          for (final command in _commands.take(5))
            ListTile(
              contentPadding: EdgeInsets.zero,
              dense: true,
              title: Text(
                titleCaseOption(command.command),
                style: const TextStyle(fontSize: 12.5),
              ),
              subtitle: Text(
                fmtUtcShort(command.createdAt),
                style: const TextStyle(fontSize: 11),
              ),
              trailing: StatusBadge(
                label: titleCaseOption(command.status),
                color: _commandColor(command.status),
              ),
            ),
        ],
      ),
    );
  }

  static Color _runColor(String result) => switch (result) {
        'completed' => const Color(0xFF1B7A43),
        'failed' => const Color(0xFFB3261E),
        'aborted' => const Color(0xFFB45309),
        _ => Colors.black45,
      };

  static Color _commandColor(String status) => switch (status) {
        'acknowledged' => const Color(0xFF1B7A43),
        'pending' => const Color(0xFFB45309),
        'failed' || 'expired' => const Color(0xFFB3261E),
        _ => Colors.black45,
      };
}

class _ScheduleSheet extends StatefulWidget {
  const _ScheduleSheet({required this.initial});

  final BmgSchedule initial;

  @override
  State<_ScheduleSheet> createState() => _ScheduleSheetState();
}

class _ScheduleSheetState extends State<_ScheduleSheet> {
  late final _times =
      TextEditingController(text: widget.initial.times.join(', '));
  late final _duration =
      TextEditingController(text: '${widget.initial.durationSeconds}');
  late final _pwm = TextEditingController(text: '${widget.initial.pwm}');
  late bool _enabled = widget.initial.enabled;
  String? _error;

  @override
  void dispose() {
    _times.dispose();
    _duration.dispose();
    _pwm.dispose();
    super.dispose();
  }

  void _submit() {
    final pattern = RegExp(r'^(?:[01]\d|2[0-3]):[0-5]\d$');
    final times = _times.text
        .split(',')
        .map((t) => t.trim())
        .where((t) => t.isNotEmpty)
        .toList();
    if (times.length > 8) {
      setState(() => _error = 'At most 8 run times per day.');
      return;
    }
    if (times.any((t) => !pattern.hasMatch(t))) {
      setState(() => _error = 'Use 24-hour HH:MM, e.g. 09:00.');
      return;
    }
    if (times.toSet().length != times.length) {
      setState(() => _error = 'Duplicate run times.');
      return;
    }
    final duration = int.tryParse(_duration.text.trim()) ?? 0;
    final pwm = int.tryParse(_pwm.text.trim()) ?? -1;
    if (duration < 1 || duration > 3600) {
      setState(() => _error = 'Duration must be 1–3600 seconds.');
      return;
    }
    if (pwm < 0 || pwm > 255) {
      setState(() => _error = 'PWM must be 0–255.');
      return;
    }
    Navigator.of(context).pop(<String, dynamic>{
      'times': times,
      'duration_seconds': duration,
      'pwm': pwm,
      'enabled': _enabled,
    });
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
              title: 'Run schedule',
              subtitle:
                  'Daily run times in Manila time. The drum applies changes '
                  'on its next check-in (~10 min).',
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _times,
              decoration: const InputDecoration(
                labelText: 'Run times (24-hour HH:MM, comma-separated)',
                hintText: '09:00, 12:00, 17:00',
                border: OutlineInputBorder(),
                isDense: true,
              ),
            ),
            const SizedBox(height: 10),
            Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _duration,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(
                      labelText: 'Duration (seconds)',
                      border: OutlineInputBorder(),
                      isDense: true,
                    ),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: TextField(
                    controller: _pwm,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(
                      labelText: 'PWM (0–255)',
                      border: OutlineInputBorder(),
                      isDense: true,
                    ),
                  ),
                ),
              ],
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: const Text('Schedule enabled'),
              subtitle: const Text('Off keeps the settings but runs nothing.'),
              value: _enabled,
              onChanged: (value) => setState(() => _enabled = value),
            ),
            if (_error != null) ...[
              const SizedBox(height: 6),
              Text(
                _error!,
                style: TextStyle(
                  color: Theme.of(context).colorScheme.error,
                  fontSize: 13,
                ),
              ),
            ],
            const SizedBox(height: 12),
            FilledButton(
              onPressed: _submit,
              child: const Text('Publish schedule'),
            ),
          ],
        ),
      ),
    );
  }
}

class _Panel extends StatelessWidget {
  const _Panel({required this.title, required this.child});

  final String title;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: const Color(0xFFFAFAF9),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: const Color(0xFFE7E5E4)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            title,
            style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 8),
          child,
        ],
      ),
    );
  }
}

Widget _fact(String label, String value) {
  return Padding(
    padding: const EdgeInsets.symmetric(vertical: 2),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          width: 100,
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
