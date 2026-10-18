import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/models/guidance_content.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
import '../../core/utils/dates.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';

/// Guidance content management — announcements and the service catalogue.
/// Mirrors the SPA `AnnouncementsTab` / `ServicesTab`.
class GuidanceContentScreen extends StatelessWidget {
  const GuidanceContentScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final session = context.watch<AuthController>().session;
    final canAnnouncements =
        session?.hasPermission('counselling.announcements.manage') ?? false;
    final canServices =
        session?.hasPermission('counselling.services.manage') ?? false;

    if (!canAnnouncements && !canServices) {
      return Scaffold(
        appBar: AppBar(title: const Text('Guidance content')),
        body: AsyncState.error(
          'You do not have permission to manage guidance content.',
        ),
      );
    }
    final tabs = <Tab>[
      if (canAnnouncements) const Tab(text: 'Announcements'),
      if (canServices) const Tab(text: 'Services'),
    ];
    return DefaultTabController(
      length: tabs.length,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Guidance content'),
          bottom: TabBar(tabs: tabs),
        ),
        body: TabBarView(
          children: [
            if (canAnnouncements) const _AnnouncementsTab(),
            if (canServices) const _ServicesTab(),
          ],
        ),
      ),
    );
  }
}

class _AnnouncementsTab extends StatefulWidget {
  const _AnnouncementsTab();

  @override
  State<_AnnouncementsTab> createState() => _AnnouncementsTabState();
}

class _AnnouncementsTabState extends State<_AnnouncementsTab> {
  bool _archived = false;
  bool _loading = true;
  String? _error;
  List<GuidanceAnnouncement> _items = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _items.isEmpty;
      _error = null;
    });
    try {
      final items =
          await ApiService.I.guidanceAnnouncements(archived: _archived);
      if (!mounted) return;
      setState(() {
        _items = items;
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

  Future<void> _edit([GuidanceAnnouncement? item]) async {
    final payload = await showSynapseSheet<Map<String, dynamic>>(
      context,
      builder: (_) => _AnnouncementSheet(initial: item),
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => item == null
          ? ApiService.I.createGuidanceAnnouncement(payload)
          : ApiService.I.updateGuidanceAnnouncement(item.id, payload),
      successMessage:
          item == null ? 'Announcement created.' : 'Announcement updated.',
    );
    if (ok) _load();
  }

  Future<void> _toggleArchive(GuidanceAnnouncement item) async {
    final ok = await runCrudAction(
      context,
      () => ApiService.I.archiveGuidanceAnnouncement(
        item.id,
        archived: !item.archived,
      ),
      successMessage:
          item.archived ? 'Announcement restored.' : 'Announcement archived.',
    );
    if (ok) _load();
  }

  Future<void> _deletePermanently(GuidanceAnnouncement item) async {
    final confirmed = await showCrudConfirm(
      context,
      title: 'Delete permanently?',
      message: 'Permanently delete “${item.title}”? This cannot be undone.',
      confirmLabel: 'Delete permanently',
      destructive: true,
    );
    if (!confirmed || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.deletePermanently('announcements', item.id),
      successMessage: 'Announcement permanently deleted.',
    );
    if (ok) _load();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 4),
          child: Row(
            children: [
              FilterChip(
                label: const Text('Archived'),
                selected: _archived,
                onSelected: (value) {
                  setState(() => _archived = value);
                  _load();
                },
              ),
              const Spacer(),
              FilledButton.icon(
                onPressed: () => _edit(),
                icon: const Icon(HugeIcons.strokeRoundedAdd01, size: 18),
                label: const Text('New'),
              ),
            ],
          ),
        ),
        Expanded(
          child: RefreshIndicator(onRefresh: _load, child: _buildBody()),
        ),
      ],
    );
  }

  Widget _buildBody() {
    if (_loading) return AsyncState.loading();
    if (_error != null) return AsyncState.error(_error!, onRetry: _load);
    if (_items.isEmpty) {
      return AsyncState.empty(
        _archived ? 'No archived announcements.' : 'No announcements yet.',
      );
    }
    return ListView.separated(
      padding: const EdgeInsets.all(16),
      itemCount: _items.length,
      separatorBuilder: (_, __) => const SizedBox(height: 8),
      itemBuilder: (context, i) {
        final item = _items[i];
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
            title: Row(
              children: [
                Expanded(
                  child: Text(
                    item.title,
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                ),
                StatusBadge(
                  label: item.archived
                      ? 'Archived'
                      : titleCaseOption(item.status),
                  color: item.archived
                      ? Colors.black45
                      : item.status == 'live'
                          ? const Color(0xFF1B7A43)
                          : const Color(0xFF8A5A00),
                ),
              ],
            ),
            subtitle: Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    item.body,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontSize: 12),
                  ),
                  Text(
                    [
                      titleCaseOption(item.audience),
                      titleCaseOption(item.severity),
                      if (item.isRequired) 'Required',
                      if (item.publishAt != null)
                        'From ${fmtUtcShort(item.publishAt)}',
                    ].join(' · '),
                    style: const TextStyle(fontSize: 11, color: Colors.black45),
                  ),
                ],
              ),
            ),
            trailing: PopupMenuButton<String>(
              icon: const Icon(HugeIcons.strokeRoundedMore),
              onSelected: (action) {
                if (action == 'edit') _edit(item);
                if (action == 'archive') _toggleArchive(item);
                if (action == 'delete') _deletePermanently(item);
              },
              itemBuilder: (_) => [
                if (!item.archived)
                  const PopupMenuItem(
                      value: 'edit', child: Text('Edit announcement')),
                if (item.archived)
                  const PopupMenuItem(
                      value: 'archive', child: Text('Restore')),
                if (!item.archived)
                  const PopupMenuItem(
                      value: 'archive', child: Text('Archive')),
                if (item.archived)
                  const PopupMenuItem(
                      value: 'delete', child: Text('Delete permanently')),
              ],
            ),
          ),
        );
      },
    );
  }
}

class _ServicesTab extends StatefulWidget {
  const _ServicesTab();

  @override
  State<_ServicesTab> createState() => _ServicesTabState();
}

class _ServicesTabState extends State<_ServicesTab> {
  bool _archived = false;
  bool _loading = true;
  String? _error;
  List<GuidanceService> _items = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _items.isEmpty;
      _error = null;
    });
    try {
      final items = await ApiService.I.guidanceServices(archived: _archived);
      if (!mounted) return;
      setState(() {
        _items = items;
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

  Future<void> _edit([GuidanceService? item]) async {
    final payload = await showSynapseSheet<Map<String, dynamic>>(
      context,
      builder: (_) => _ServiceSheet(initial: item),
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => item == null
          ? ApiService.I.createGuidanceService(payload)
          : ApiService.I.updateGuidanceService(item.id, payload),
      successMessage: item == null ? 'Service created.' : 'Service updated.',
    );
    if (ok) _load();
  }

  Future<void> _toggleArchive(GuidanceService item) async {
    final ok = await runCrudAction(
      context,
      () => ApiService.I.archiveGuidanceService(
        item.id,
        archived: !item.archived,
      ),
      successMessage:
          item.archived ? 'Service restored.' : 'Service archived.',
    );
    if (ok) _load();
  }

  Future<void> _deletePermanently(GuidanceService item) async {
    final confirmed = await showCrudConfirm(
      context,
      title: 'Delete permanently?',
      message: 'Permanently delete “${item.name}”? This cannot be undone.',
      confirmLabel: 'Delete permanently',
      destructive: true,
    );
    if (!confirmed || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.deletePermanently('services', item.id),
      successMessage: 'Service permanently deleted.',
    );
    if (ok) _load();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 4),
          child: Row(
            children: [
              FilterChip(
                label: const Text('Archived'),
                selected: _archived,
                onSelected: (value) {
                  setState(() => _archived = value);
                  _load();
                },
              ),
              const Spacer(),
              FilledButton.icon(
                onPressed: () => _edit(),
                icon: const Icon(HugeIcons.strokeRoundedAdd01, size: 18),
                label: const Text('New'),
              ),
            ],
          ),
        ),
        Expanded(
          child: RefreshIndicator(onRefresh: _load, child: _buildBody()),
        ),
      ],
    );
  }

  Widget _buildBody() {
    if (_loading) return AsyncState.loading();
    if (_error != null) return AsyncState.error(_error!, onRetry: _load);
    if (_items.isEmpty) {
      return AsyncState.empty(
        _archived ? 'No archived services.' : 'No services yet.',
      );
    }
    return ListView.separated(
      padding: const EdgeInsets.all(16),
      itemCount: _items.length,
      separatorBuilder: (_, __) => const SizedBox(height: 8),
      itemBuilder: (context, i) {
        final item = _items[i];
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
            title: Row(
              children: [
                Expanded(
                  child: Text(
                    item.name,
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                ),
                StatusBadge(
                  label: item.archived
                      ? 'Archived'
                      : item.isActive
                          ? 'Active'
                          : 'Inactive',
                  color: item.archived || !item.isActive
                      ? Colors.black45
                      : const Color(0xFF1B7A43),
                ),
              ],
            ),
            subtitle: Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (item.description != null &&
                      item.description!.isNotEmpty)
                    Text(
                      item.description!,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontSize: 12),
                    ),
                  Text(
                    [
                      item.code,
                      if (item.queueDestination != null)
                        'Queue: ${titleCaseOption(item.queueDestination!)}',
                      'Order ${item.sortOrder}',
                    ].join(' · '),
                    style: const TextStyle(fontSize: 11, color: Colors.black45),
                  ),
                ],
              ),
            ),
            trailing: PopupMenuButton<String>(
              icon: const Icon(HugeIcons.strokeRoundedMore),
              onSelected: (action) {
                if (action == 'edit') _edit(item);
                if (action == 'archive') _toggleArchive(item);
                if (action == 'delete') _deletePermanently(item);
              },
              itemBuilder: (_) => [
                if (!item.archived)
                  const PopupMenuItem(
                      value: 'edit', child: Text('Edit service')),
                if (item.archived)
                  const PopupMenuItem(
                      value: 'archive', child: Text('Restore')),
                if (!item.archived)
                  const PopupMenuItem(value: 'archive', child: Text('Archive')),
                if (item.archived)
                  const PopupMenuItem(
                      value: 'delete', child: Text('Delete permanently')),
              ],
            ),
          ),
        );
      },
    );
  }
}

class _AnnouncementSheet extends StatefulWidget {
  const _AnnouncementSheet({this.initial});

  final GuidanceAnnouncement? initial;

  @override
  State<_AnnouncementSheet> createState() => _AnnouncementSheetState();
}

class _AnnouncementSheetState extends State<_AnnouncementSheet> {
  late final _title = TextEditingController(text: widget.initial?.title ?? '');
  late final _body = TextEditingController(text: widget.initial?.body ?? '');
  late final _actionUrl =
      TextEditingController(text: widget.initial?.actionUrl ?? '');
  late final _actionLabel =
      TextEditingController(text: widget.initial?.actionLabel ?? '');
  late final _publishAt =
      TextEditingController(text: widget.initial?.publishAt ?? '');
  late final _unpublishAt =
      TextEditingController(text: widget.initial?.unpublishAt ?? '');
  late String _audience = widget.initial?.audience ?? 'all';
  late String _severity = widget.initial?.severity ?? 'normal';
  late bool _required = widget.initial?.isRequired ?? false;
  String? _error;

  @override
  void dispose() {
    _title.dispose();
    _body.dispose();
    _actionUrl.dispose();
    _actionLabel.dispose();
    _publishAt.dispose();
    _unpublishAt.dispose();
    super.dispose();
  }

  void _submit() {
    if (_title.text.trim().isEmpty || _body.text.trim().isEmpty) {
      setState(() => _error = 'Title and body are required.');
      return;
    }
    Navigator.of(context).pop(<String, dynamic>{
      'title': _title.text.trim(),
      'body': _body.text.trim(),
      'audience': _audience,
      'severity': _severity,
      'is_required': _required,
      if (_actionUrl.text.trim().isNotEmpty)
        'action_url': _actionUrl.text.trim(),
      if (_actionLabel.text.trim().isNotEmpty)
        'action_label': _actionLabel.text.trim(),
      if (_publishAt.text.trim().isNotEmpty)
        'publish_at': _publishAt.text.trim(),
      if (_unpublishAt.text.trim().isNotEmpty)
        'unpublish_at': _unpublishAt.text.trim(),
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
            SheetHeader(
              title: widget.initial == null
                  ? 'New announcement'
                  : 'Edit announcement',
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _title,
              maxLength: 200,
              decoration: const InputDecoration(
                labelText: 'Title',
                border: OutlineInputBorder(),
                isDense: true,
              ),
            ),
            const SizedBox(height: 8),
            TextField(
              controller: _body,
              minLines: 3,
              maxLines: 6,
              decoration: const InputDecoration(
                labelText: 'Body',
                border: OutlineInputBorder(),
                alignLabelWithHint: true,
              ),
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              initialValue: _audience,
              decoration: const InputDecoration(
                labelText: 'Audience',
                border: OutlineInputBorder(),
                isDense: true,
              ),
              items: const [
                DropdownMenuItem(value: 'all', child: Text('All students')),
                DropdownMenuItem(
                    value: 'new_students', child: Text('New students')),
                DropdownMenuItem(
                    value: 'continuing_students',
                    child: Text('Continuing students')),
                DropdownMenuItem(
                    value: 'graduating_students',
                    child: Text('Graduating students')),
              ],
              onChanged: (v) => setState(() => _audience = v ?? 'all'),
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              initialValue: _severity,
              decoration: const InputDecoration(
                labelText: 'Severity',
                border: OutlineInputBorder(),
                isDense: true,
              ),
              items: const [
                DropdownMenuItem(value: 'normal', child: Text('Normal')),
                DropdownMenuItem(value: 'urgent', child: Text('Urgent')),
              ],
              onChanged: (v) => setState(() => _severity = v ?? 'normal'),
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: const Text('Required for clearance'),
              value: _required,
              onChanged: (v) => setState(() => _required = v),
            ),
            TextField(
              controller: _actionUrl,
              decoration: const InputDecoration(
                labelText: 'Action URL (optional)',
                hintText: 'https://…',
                border: OutlineInputBorder(),
                isDense: true,
              ),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: _actionLabel,
              maxLength: 60,
              decoration: const InputDecoration(
                labelText: 'Action label (optional)',
                border: OutlineInputBorder(),
                isDense: true,
              ),
            ),
            const SizedBox(height: 4),
            TextField(
              controller: _publishAt,
              decoration: const InputDecoration(
                labelText: 'Publish at (optional)',
                hintText: 'YYYY-MM-DD HH:MM:SS',
                border: OutlineInputBorder(),
                isDense: true,
              ),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: _unpublishAt,
              decoration: const InputDecoration(
                labelText: 'Unpublish at (optional)',
                hintText: 'YYYY-MM-DD HH:MM:SS',
                border: OutlineInputBorder(),
                isDense: true,
              ),
            ),
            if (_error != null) ...[
              const SizedBox(height: 8),
              Text(
                _error!,
                style: TextStyle(
                  color: Theme.of(context).colorScheme.error,
                  fontSize: 13,
                ),
              ),
            ],
            const SizedBox(height: 14),
            FilledButton(
              onPressed: _submit,
              child: Text(widget.initial == null ? 'Create' : 'Save'),
            ),
          ],
        ),
      ),
    );
  }
}

class _ServiceSheet extends StatefulWidget {
  const _ServiceSheet({this.initial});

  final GuidanceService? initial;

  @override
  State<_ServiceSheet> createState() => _ServiceSheetState();
}

class _ServiceSheetState extends State<_ServiceSheet> {
  late final _code = TextEditingController(text: widget.initial?.code ?? '');
  late final _name = TextEditingController(text: widget.initial?.name ?? '');
  late final _description =
      TextEditingController(text: widget.initial?.description ?? '');
  late final _cmo =
      TextEditingController(text: widget.initial?.cmoReference ?? '');
  late final _sortOrder =
      TextEditingController(text: '${widget.initial?.sortOrder ?? 0}');
  String? _destination;
  bool _active = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _destination = widget.initial?.queueDestination;
    _active = widget.initial?.isActive ?? true;
  }

  @override
  void dispose() {
    _code.dispose();
    _name.dispose();
    _description.dispose();
    _cmo.dispose();
    _sortOrder.dispose();
    super.dispose();
  }

  void _submit() {
    if (_name.text.trim().isEmpty) {
      setState(() => _error = 'Service name is required.');
      return;
    }
    Navigator.of(context).pop(<String, dynamic>{
      if (_code.text.trim().isNotEmpty) 'code': _code.text.trim(),
      'name': _name.text.trim(),
      if (_description.text.trim().isNotEmpty)
        'description': _description.text.trim(),
      if (_cmo.text.trim().isNotEmpty) 'cmo_reference': _cmo.text.trim(),
      'sort_order': int.tryParse(_sortOrder.text.trim()) ?? 0,
      'queue_destination': _destination,
      'is_active': _active,
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
            SheetHeader(
              title: widget.initial == null ? 'New service' : 'Edit service',
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _name,
              maxLength: 120,
              decoration: const InputDecoration(
                labelText: 'Name',
                border: OutlineInputBorder(),
                isDense: true,
              ),
            ),
            const SizedBox(height: 8),
            TextField(
              controller: _code,
              decoration: const InputDecoration(
                labelText: 'Code (optional)',
                hintText: 'Derived from the name when omitted',
                border: OutlineInputBorder(),
                isDense: true,
              ),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: _description,
              minLines: 2,
              maxLines: 4,
              decoration: const InputDecoration(
                labelText: 'Description (optional)',
                border: OutlineInputBorder(),
                alignLabelWithHint: true,
              ),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: _cmo,
              decoration: const InputDecoration(
                labelText: 'CMO reference (optional)',
                border: OutlineInputBorder(),
                isDense: true,
              ),
            ),
            const SizedBox(height: 10),
            Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _sortOrder,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(
                      labelText: 'Sort order',
                      border: OutlineInputBorder(),
                      isDense: true,
                    ),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: DropdownButtonFormField<String?>(
                    initialValue: _destination,
                    decoration: const InputDecoration(
                      labelText: 'Queue destination',
                      border: OutlineInputBorder(),
                      isDense: true,
                    ),
                    items: const [
                      DropdownMenuItem(value: null, child: Text('None')),
                      DropdownMenuItem(value: 'clinic', child: Text('Clinic')),
                      DropdownMenuItem(
                          value: 'counselling', child: Text('Guidance')),
                    ],
                    onChanged: (v) => setState(() => _destination = v),
                  ),
                ),
              ],
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: const Text('Active'),
              value: _active,
              onChanged: (v) => setState(() => _active = v),
            ),
            if (_error != null) ...[
              const SizedBox(height: 8),
              Text(
                _error!,
                style: TextStyle(
                  color: Theme.of(context).colorScheme.error,
                  fontSize: 13,
                ),
              ),
            ],
            const SizedBox(height: 14),
            FilledButton(
              onPressed: _submit,
              child: Text(widget.initial == null ? 'Create' : 'Save'),
            ),
          ],
        ),
      ),
    );
  }
}
