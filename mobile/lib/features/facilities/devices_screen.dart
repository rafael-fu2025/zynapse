/// DevicesScreen — the mechanized tumblers that report turning sessions.
///
/// This is the mobile counterpart of the web's `/facilities/devices`. It
/// exists because the credential flow is field work: a technician flashing
/// an ESP32 on site needs to register the board, read the token once, and
/// paste it into the firmware — none of which is practical from a desktop.
///
/// The plaintext token is returned exactly once, at mint or regenerate
/// time. Only its prefix is ever readable afterwards; losing it means
/// regenerating, which invalidates the old credential and requires a
/// re-flash.
library;

import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/models/facilities.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
import '../common/auto_polling.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';

class DevicesScreen extends StatefulWidget {
  const DevicesScreen({super.key});

  @override
  State<DevicesScreen> createState() => _DevicesScreenState();
}

class _DevicesScreenState extends State<DevicesScreen> with AutoPolling {
  List<BmgDevice> _devices = [];
  String? _error;
  bool _loading = true;
  bool _loadedOnce = false;
  bool _showArchived = false;

  @override
  void initState() {
    super.initState();
    _load();
    // The watchdog and the boards themselves change this list out of
    // band, so poll rather than relying on a manual pull.
    startPolling(const Duration(seconds: 60), _silentRefresh);
  }

  Future<void> _silentRefresh() async {
    try {
      final list =
          await ApiService.I.facilityDevices(includeArchived: _showArchived);
      if (!mounted) return;
      setState(() => _devices = list);
    } catch (e) {
      // Keep the current list on transient errors.
    }
  }

  Future<void> _load() async {
    setState(() {
      _loading = !_loadedOnce;
      _error = null;
    });
    try {
      final list =
          await ApiService.I.facilityDevices(includeArchived: _showArchived);
      setState(() {
        _devices = list;
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

  bool get _canManage =>
      context.read<AuthController>().session?.hasPermission(
            'facilities.units.manage',
          ) ??
      false;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Devices'),
        actions: [
          IconButton(
            onPressed: () {
              setState(() {
                _showArchived = !_showArchived;
                _loadedOnce = false;
              });
              _load();
            },
            icon: Icon(_showArchived
                ? HugeIcons.strokeRoundedArchive01
                : HugeIcons.strokeRoundedArchive),
            tooltip: _showArchived ? 'Hide archived' : 'Show archived',
          ),
          IconButton(
            onPressed: _load,
            icon: const Icon(HugeIcons.strokeRoundedRefresh),
            tooltip: 'Refresh',
          ),
        ],
      ),
      floatingActionButton: _canManage
          ? FloatingActionButton.extended(
              backgroundColor: const Color(0xFF800000),
              foregroundColor: Colors.white,
              onPressed: _register,
              icon: const Icon(HugeIcons.strokeRoundedAddCircle),
              label: const Text('Register device'),
            )
          : null,
      body: RefreshIndicator(
        onRefresh: _load,
        child: Builder(builder: (context) {
          if (_loading) return AsyncState.loading();
          if (_error != null) {
            return AsyncState.error(_error!, onRetry: _load);
          }
          if (_devices.isEmpty) {
            return ListView(
              children: [
                SizedBox(
                  height: MediaQuery.of(context).size.height * 0.6,
                  child: Center(
                    child: _showArchived
                        ? const Text('No devices registered yet.',
                            style: TextStyle(color: Colors.black54))
                        : const Text(
                            'No devices registered yet. Register the '
                            'tumbler to start reporting turning sessions.',
                            textAlign: TextAlign.center,
                            style: TextStyle(color: Colors.black54),
                          ),
                  ),
                ),
              ],
            );
          }
          return ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: _devices.length,
            separatorBuilder: (_, __) => const SizedBox(height: 8),
            itemBuilder: (_, i) => _DeviceRow(
              device: _devices[i],
              canManage: _canManage,
              onEdit: _canManage ? () => _edit(_devices[i]) : null,
              onRegenerate: _canManage ? () => _regenerate(_devices[i]) : null,
              onToggle: _canManage ? () => _toggle(_devices[i]) : null,
              onArchive: _canManage ? () => _archive(_devices[i]) : null,
            ),
          );
        }),
      ),
    );
  }

  /// Register a board. The MAC becomes the default code, so a technician
  /// can flash and register from the same bench without inventing a name.
  Future<void> _register() async {
    List<BmgUnit> units = [];
    try {
      final page = await ApiService.I.facilityUnits(limit: 100);
      // A drum integrates exactly ONE ESP32 — only unbound drums can
      // take a new binding here; swapping a held drum belongs to the
      // drum's Edit form.
      units = page.items
          .where((u) => u.archivedAt == null && u.deviceId == null)
          .toList();
    } catch (e) {
      // Registration still works without a drum binding.
    }
    if (!mounted) return;

    const unbound = 'None (reports will be rejected until bound)';
    final options = [
      unbound,
      ...units.map((u) => '${u.displayName} (${u.code})')
    ];

    final payload = await showCrudForm(
      context,
      title: 'Register device',
      fields: [
        const CrudField.text('mac', 'Chip MAC address',
            required: false, hint: 'b8:1f:3f:d7:ec:18'),
        const CrudField.text('code', 'Code (override)',
            required: false, hint: 'compost-tumbler-1'),
        const CrudField.text('display_name', 'Display name',
            required: false, hint: 'Compost Tumbler 1'),
        CrudField.dropdown('unit', 'Bound drum', options),
      ],
      submitLabel: 'Register',
    );
    if (payload == null || !mounted) return;

    final unitChoice = payload['unit'] as String?;
    final unitId = unitChoice == null || options.indexOf(unitChoice) <= 0
        ? null
        : units[options.indexOf(unitChoice) - 1].id;

    final body = <String, dynamic>{
      'mac': (payload['mac'] as String?) ?? '',
      'code': (payload['code'] as String?) ?? '',
      'display_name': (payload['display_name'] as String?) ?? '',
      'unit_id': unitId ?? '',
    };

    try {
      final minted = await ApiService.I.registerFacilityDevice(body);
      if (!mounted) return;
      _load();
      await _showToken(
        'Device token',
        minted.token,
        'Paste this into DEVICE_TOKEN in the sketch and flash the board. '
            'It is shown once — only a SHA-256 hash is stored server-side. '
            'Losing it means regenerating, which stops the old token working.',
      );
    } catch (e) {
      if (!mounted) return;
      showCrudMessage(context, mapDioError(e).message, error: true);
    }
  }

  /// Rebind to a different drum, or rename. Deliberately does NOT re-key
  /// the token — the board keeps reporting with the credential it has.
  Future<void> _edit(BmgDevice d) async {
    List<BmgUnit> units = [];
    try {
      final page = await ApiService.I.facilityUnits(limit: 100);
      // Offer only free drums plus the one this device already holds —
      // a drum bound to ANOTHER board is refused server-side (1:1).
      units = page.items
          .where((u) =>
              u.archivedAt == null &&
              (u.deviceId == null || u.deviceId == d.unitId))
          .toList();
    } catch (e) {
      // Editing the name alone still works without the drum list.
    }
    if (!mounted) return;

    const unbound = 'None (reports will be rejected until bound)';
    String label(BmgUnit u) => '${u.displayName} (${u.code})';
    final options = [unbound, ...units.map(label)];
    final currentIndex = units.indexWhere((u) => u.id == d.unitId);
    final initial = currentIndex >= 0 ? options[currentIndex + 1] : unbound;

    final payload = await showCrudForm(
      context,
      title: 'Edit ${d.code}',
      fields: [
        CrudField.text('display_name', 'Display name', initial: d.displayName),
        CrudField.dropdown('unit', 'Bound drum', options, initial: initial),
      ],
      submitLabel: 'Save',
    );
    if (payload == null || !mounted) return;

    final choice = payload['unit'] as String?;
    final idx = choice == null ? 0 : options.indexOf(choice);
    final body = <String, dynamic>{
      'display_name': payload['display_name'],
      // An explicit null UNBINDS — it is never "leave unchanged".
      'unit_id': idx <= 0 ? null : units[idx - 1].id,
    };

    final ok = await runCrudAction(
      context,
      () => ApiService.I.updateFacilityDevice(d.id, body),
      successMessage: 'Device updated.',
    );
    if (ok) _load();
  }

  Future<void> _regenerate(BmgDevice d) async {
    final confirmed = await showCrudConfirm(
      context,
      title: 'Regenerate token?',
      message: 'The current token for ${d.code} stops working immediately. '
          'The device must be re-flashed with the new one before its next '
          'report.',
      confirmLabel: 'Regenerate',
      destructive: true,
    );
    if (!confirmed || !mounted) return;
    try {
      final token = await ApiService.I.regenerateFacilityDeviceToken(d.id);
      if (!mounted) return;
      await _showToken(
        'New device token',
        token,
        'The previous token no longer works. Flash this into the board '
            'before its next report.',
      );
    } catch (e) {
      if (!mounted) return;
      showCrudMessage(context, mapDioError(e).message, error: true);
    }
  }

  Future<void> _toggle(BmgDevice d) async {
    final next = d.status == 'active' ? 'disabled' : 'active';
    final ok = await runCrudAction(
      context,
      () => ApiService.I.setFacilityDeviceStatus(d.id, next),
      successMessage: next == 'active'
          ? 'Device enabled.'
          : 'Device disabled — its reports will be refused.',
    );
    if (ok) _load();
  }

  Future<void> _archive(BmgDevice d) async {
    final confirmed = await showCrudConfirm(
      context,
      title: 'Archive device?',
      message: 'Archive ${d.code}? The device is retired: its reports will be '
          'refused and its drum binding cleared. The audit history is kept.',
      confirmLabel: 'Archive',
      destructive: true,
    );
    if (!confirmed || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.archiveFacilityDevice(d.id),
      successMessage: 'Device archived.',
    );
    if (ok) _load();
  }

  Future<void> _showToken(String title, String token, String explanation) {
    return showSynapseSheet<void>(
      context,
      builder: (ctx) => Padding(
        padding: const EdgeInsets.fromLTRB(20, 14, 20, 24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SheetHeader(title: title, subtitle: explanation),
            const SizedBox(height: 16),
            SelectableText(
              token,
              style: const TextStyle(
                  fontFamily: 'monospace', fontSize: 13, height: 1.4),
            ),
            const SizedBox(height: 16),
            FilledButton(
              onPressed: () => Navigator.of(ctx).pop(),
              style: FilledButton.styleFrom(
                  backgroundColor: const Color(0xFF800000)),
              child: const Text('Done'),
            ),
          ],
        ),
      ),
    );
  }
}

class _DeviceRow extends StatelessWidget {
  const _DeviceRow({
    required this.device,
    required this.canManage,
    this.onEdit,
    this.onRegenerate,
    this.onToggle,
    this.onArchive,
  });

  final BmgDevice device;
  final bool canManage;
  final VoidCallback? onEdit;
  final VoidCallback? onRegenerate;
  final VoidCallback? onToggle;
  final VoidCallback? onArchive;

  @override
  Widget build(BuildContext context) {
    final silent = device.isSilent;
    return Card(
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(
          color: silent
              ? const Color(0xFFB3261E)
              : Theme.of(context)
                  .colorScheme
                  .outlineVariant
                  .withValues(alpha: 0.5),
        ),
      ),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        device.displayName,
                        style: const TextStyle(
                            fontWeight: FontWeight.w700, fontSize: 15),
                      ),
                      Text(device.code,
                          style: const TextStyle(
                              fontFamily: 'monospace',
                              fontSize: 11,
                              color: Colors.black54)),
                    ],
                  ),
                ),
                _chip(
                  device.isArchived
                      ? 'Archived'
                      : titleCaseOption(device.status),
                  device.isArchived
                      ? Colors.black45
                      : (device.isActive
                          ? const Color(0xFF1B7A43)
                          : const Color(0xFFB3261E)),
                ),
                if (silent) ...[
                  const SizedBox(width: 6),
                  _chip('Silent', const Color(0xFF8A5A00)),
                ],
              ],
            ),
            const SizedBox(height: 6),
            Text(
              '${device.isBound ? device.unitName : 'Not bound'}'
              ' · ${device.lastSeenAt == null || device.lastSeenAt!.isEmpty ? 'never connected' : 'last seen ${device.lastSeenAt}'}'
              '${device.firmware == null ? '' : ' · fw ${device.firmware}'}',
              style: TextStyle(
                fontSize: 11,
                color: silent ? const Color(0xFFB3261E) : Colors.black54,
              ),
            ),
            if (silent)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(
                  'The device-silence watchdog flagged this board. It has not '
                  'checked in since ${device.silenceNotifiedAt}.',
                  style:
                      const TextStyle(fontSize: 11, color: Color(0xFFB3261E)),
                ),
              ),
            if (canManage)
              Align(
                alignment: Alignment.centerRight,
                child: PopupMenuButton<String>(
                  icon: const Icon(HugeIcons.strokeRoundedMore),
                  onSelected: (v) {
                    if (v == 'edit') onEdit?.call();
                    if (v == 'regenerate') onRegenerate?.call();
                    if (v == 'toggle') onToggle?.call();
                    if (v == 'archive') onArchive?.call();
                  },
                  itemBuilder: (_) => [
                    const PopupMenuItem(
                        value: 'edit', child: Text('Edit device')),
                    const PopupMenuItem(
                        value: 'regenerate', child: Text('Regenerate token')),
                    PopupMenuItem(
                      value: 'toggle',
                      child: Text(
                          device.status == 'active' ? 'Disable' : 'Enable'),
                    ),
                    const PopupMenuItem(
                        value: 'archive', child: Text('Archive device')),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }

  static Widget _chip(String label, Color color) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.12),
          borderRadius: BorderRadius.circular(20),
        ),
        child: Text(label,
            style: TextStyle(
                fontSize: 11, fontWeight: FontWeight.w600, color: color)),
      );
}
