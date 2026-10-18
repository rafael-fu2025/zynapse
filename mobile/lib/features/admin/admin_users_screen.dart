import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/models/admin_user.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';

/// Admin — user registry. `GET /admin/users` (paged) with search.
class AdminUsersScreen extends StatefulWidget {
  const AdminUsersScreen({super.key});

  @override
  State<AdminUsersScreen> createState() => _AdminUsersScreenState();
}

class _AdminUsersScreenState extends State<AdminUsersScreen> {
  final _searchController = TextEditingController();
  String _query = '';
  bool _loading = true;
  String? _error;
  List<AdminUser> _items = [];
  String? _nextCursor;
  bool _loadingMore = false;
  bool _loadedOnce = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = !_loadedOnce;
      _error = null;
    });
    try {
      final page = await ApiService.I.adminUsers(q: _query);
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
          await ApiService.I.adminUsers(q: _query, cursor: _nextCursor);
      setState(() {
        _items = [..._items, ...page.items];
        _nextCursor = page.meta?.nextCursor;
      });
    } catch (e) {
      // ignore
      if (kDebugMode) debugPrint('AdminUsersScreen.loadMore failed: $e');
    } finally {
      setState(() => _loadingMore = false);
    }
  }

  bool get _canManage =>
      context.read<AuthController>().session?.hasPermission('rbac.manage') ??
      false;

  static const _roleOptions = <String>[
    'admin',
    'clinic_staff',
    'counsellor',
    'facilities_op',
    'audit_reader',
    'report_viewer',
    'clinical_supervisor',
    'student',
    'employee',
  ];

  Future<void> _createUser() async {
    final payload = await showCrudForm(
      context,
      title: 'Create user',
      fields: const [
        CrudField.text('email', 'Email', keyboard: TextInputType.emailAddress),
        CrudField.text('username', 'Username',
            required: false, hint: 'Letters, digits, - _'),
        CrudField.dropdown('groups', 'Role', _roleOptions),
      ],
      submitLabel: 'Create',
    );
    if (payload == null || !mounted) return;
    // The form returns a single role string; the API expects an array.
    final groups = [payload['groups']].whereType<String>().toList();
    final body = <String, dynamic>{
      if (payload['email'] != null) 'email': payload['email'],
      if (payload['username'] != null) 'username': payload['username'],
      'groups': groups,
    };
    final ok = await runCrudAction(
      context,
      () => ApiService.I.createAdminUser(body),
      successMessage: 'User created (temp password issued).',
    );
    if (ok) _load();
  }

  Future<void> _resetPassword(AdminUser u) async {
    final confirmed = await showCrudConfirm(
      context,
      title: 'Reset password?',
      message:
          'Reset the password for ${u.email ?? u.label}? A temporary password will be issued.',
      confirmLabel: 'Reset',
    );
    if (!confirmed || !mounted) return;
    try {
      final res = await ApiService.I.resetUserPassword(u.id);
      final temp = res['temporary_password'];
      if (mounted) {
        showCrudMessage(context,
            temp != null ? 'Temp password: $temp' : 'Password reset issued.');
        _load();
      }
    } catch (e) {
      if (mounted) {
        showCrudMessage(context, mapDioError(e).message, error: true);
      }
    }
  }

  Future<void> _toggleStatus(AdminUser u) async {
    final confirmed = await showCrudConfirm(
      context,
      title: u.active ? 'Deactivate user?' : 'Activate user?',
      message: u.active
          ? 'Deactivate ${u.email ?? u.label}? They will lose access.'
          : 'Activate ${u.email ?? u.label}?',
      confirmLabel: u.active ? 'Deactivate' : 'Activate',
      destructive: u.active,
    );
    if (!confirmed || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.setUserStatus(u.id, !u.active),
      successMessage: u.active ? 'User deactivated.' : 'User activated.',
    );
    if (ok) _load();
  }

  Future<void> _openUser(AdminUser user) async {
    await showSynapseSheet<void>(
      context,
      builder: (_) => _UserDetailSheet(user: user),
    );
  }

  Future<void> _editRoles(AdminUser u) async {
    final groups = await showSynapseSheet<List<String>>(
      context,
      builder: (_) => _RolesSheet(initial: u.groups),
    );
    if (groups == null || !mounted) return;
    if (groups.isEmpty) {
      showCrudMessage(context, 'Select at least one role.', error: true);
      return;
    }
    final ok = await runCrudAction(
      context,
      () => ApiService.I.setUserGroups(u.id, groups),
      successMessage: 'Roles updated.',
    );
    if (ok) _load();
  }

  Future<void> _syncDirectory() async {
    Map<String, dynamic>? latest;
    try {
      latest = await ApiService.I.latestDirectorySyncJob();
    } catch (e) {
      if (!mounted) return;
      showCrudMessage(context, mapDioError(e).message, error: true);
      return;
    }
    if (!mounted) return;
    final payload = await showSynapseSheet<Map<String, dynamic>>(
      context,
      builder: (_) => _DirectorySyncSheet(latest: latest),
    );
    if (payload == null || !mounted) return;
    try {
      final job = await ApiService.I.syncDirectory(
        kind: payload['kind'] as String? ?? 'all',
        dryRun: payload['dry_run'] as bool? ?? true,
      );
      if (!mounted) return;
      showCrudMessage(
        context,
        'Directory sync ${job['status'] ?? 'queued'} (job #${job['id'] ?? '—'}).',
      );
    } catch (e) {
      if (!mounted) return;
      showCrudMessage(context, mapDioError(e).message, error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Users'),
        actions: [
          if (_canManage)
            IconButton(
              onPressed: _syncDirectory,
              tooltip: 'MIS directory sync',
              icon: const Icon(HugeIcons.strokeRoundedRefresh),
            ),
        ],
      ),
      floatingActionButton: _canManage
          ? FloatingActionButton.extended(
              backgroundColor: const Color(0xFF800000),
              foregroundColor: Colors.white,
              onPressed: _createUser,
              icon: const Icon(HugeIcons.strokeRoundedUserGroup),
              label: const Text('Create user'),
            )
          : null,
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
            child: TextField(
              controller: _searchController,
              onChanged: (v) {
                setState(() => _query = v.trim());
                _load();
              },
              decoration: InputDecoration(
                hintText: 'Search name, email, username…',
                prefixIcon: const Icon(HugeIcons.strokeRoundedSearch01),
                isDense: true,
                suffixIcon: _query.isEmpty
                    ? null
                    : IconButton(
                        icon: const Icon(HugeIcons.strokeRoundedCancel01),
                        onPressed: () {
                          _searchController.clear();
                          setState(() => _query = '');
                          _load();
                        },
                      ),
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

  Widget _buildBody() {
    if (_loading) return AsyncState.loading();
    if (_error != null) return AsyncState.error(_error!, onRetry: _load);
    if (_items.isEmpty) {
      return AsyncState.empty(
        _query.isEmpty ? 'No users.' : 'No matches.',
      );
    }

    return ListView.builder(
      padding: const EdgeInsets.all(16),
      itemCount: _items.length + (_nextCursor != null ? 1 : 0),
      // Pre-build rows ahead of the viewport so fast flings don't hitch on
      // widget builds — keeps long lists smooth at the 120Hz vsync.
      scrollCacheExtent: const ScrollCacheExtent.pixels(600),
      // User tiles are `isThreeLine` ListTiles = fixed 88dp + 8dp bottom
      // gap = 96dp. A fixed itemExtent lets the viewport skip layout of
      // off-screen rows entirely (ListView.separated lost itemExtent in
      // Flutter 3.44, hence the builder + per-item bottom margin).
      itemExtent: 96,
      itemBuilder: (context, i) {
        if (i >= _items.length) {
          // Load-more sentinel — defer so we never setState during build.
          WidgetsBinding.instance.addPostFrameCallback((_) {
            if (mounted) _loadMore();
          });
          return const Center(
            child: SizedBox(
              width: 22,
              height: 22,
              child: CircularProgressIndicator(strokeWidth: 2),
            ),
          );
        }
        return Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: _UserTile(
            user: _items[i],
            canManage: _canManage,
            onOpen: () => _openUser(_items[i]),
            onEditRoles: () => _editRoles(_items[i]),
            onResetPassword: () => _resetPassword(_items[i]),
            onToggleStatus: () => _toggleStatus(_items[i]),
          ),
        );
      },
    );
  }
}

class _UserTile extends StatelessWidget {
  const _UserTile({
    required this.user,
    required this.canManage,
    this.onOpen,
    this.onEditRoles,
    this.onResetPassword,
    this.onToggleStatus,
  });

  final AdminUser user;
  final bool canManage;
  final VoidCallback? onOpen;
  final VoidCallback? onEditRoles;
  final VoidCallback? onResetPassword;
  final VoidCallback? onToggleStatus;

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
        leading: CircleAvatar(
          child: Text(
            user.label.isNotEmpty ? user.label[0].toUpperCase() : '?',
          ),
        ),
        title: Row(
          children: [
            Expanded(
              child: Text(
                user.label,
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
            ),
            if (!user.active)
              const StatusBadge(label: 'Inactive', color: Color(0xFFB3261E)),
          ],
        ),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Wrap(
            spacing: 6,
            runSpacing: 4,
            children: [
              if (user.email != null && user.email!.isNotEmpty)
                Text(user.email!,
                    style: const TextStyle(color: Colors.black54)),
              for (final group in user.groups)
                StatusBadge(
                    label: titleCaseOption(group),
                    color: const Color(0xFF37474F)),
              if (user.forceReset)
                const StatusBadge(
                    label: 'Force reset', color: Color(0xFF8A5A00)),
            ],
          ),
        ),
        isThreeLine: true,
        trailing: canManage
            ? PopupMenuButton<String>(
                icon: const Icon(HugeIcons.strokeRoundedMore),
                onSelected: (v) {
                  if (v == 'roles') onEditRoles?.call();
                  if (v == 'reset') onResetPassword?.call();
                  if (v == 'status') onToggleStatus?.call();
                },
                itemBuilder: (_) => [
                  const PopupMenuItem(
                      value: 'roles', child: Text('Edit roles')),
                  const PopupMenuItem(
                      value: 'reset', child: Text('Reset password')),
                  PopupMenuItem(
                    value: 'status',
                    child: Text(user.active ? 'Deactivate' : 'Activate'),
                  ),
                ],
              )
            : null,
      ),
    );
  }
}

class _UserDetailSheet extends StatelessWidget {
  const _UserDetailSheet({required this.user});

  final AdminUser user;

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
              title: user.label,
              subtitle: 'User #${user.id}',
            ),
            const SizedBox(height: 10),
            Wrap(
              spacing: 8,
              runSpacing: 6,
              children: [
                StatusBadge(
                  label: user.active ? 'Active' : 'Inactive',
                  color: user.active
                      ? const Color(0xFF1B7A43)
                      : const Color(0xFFB3261E),
                ),
                if (user.forceReset)
                  const StatusBadge(
                    label: 'Force reset',
                    color: Color(0xFF8A5A00),
                  ),
              ],
            ),
            const SizedBox(height: 12),
            _fact('Email', user.email ?? '—'),
            _fact('Username', user.username ?? '—'),
            _fact('Person', user.personName ?? '—'),
            _fact('Person kind', user.personKind ?? '—'),
            _fact('Status', user.status),
            _fact('Last active', user.lastActive ?? '—'),
            _fact('Created', user.createdAt),
            _fact('Updated', user.updatedAt),
            const SizedBox(height: 10),
            const Text(
              'Roles',
              style: TextStyle(fontSize: 13, fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 6),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final group in user.groups)
                  StatusBadge(
                    label: titleCaseOption(group),
                    color: const Color(0xFF37474F),
                  ),
                if (user.groups.isEmpty)
                  const Text(
                    'No roles assigned.',
                    style: TextStyle(fontSize: 12, color: Colors.black45),
                  ),
              ],
            ),
            const SizedBox(height: 14),
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
              width: 110,
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

class _RolesSheet extends StatefulWidget {
  const _RolesSheet({required this.initial});

  final List<String> initial;

  @override
  State<_RolesSheet> createState() => _RolesSheetState();
}

class _RolesSheetState extends State<_RolesSheet> {
  late final _selected = <String>{...widget.initial};

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const SheetHeader(
              title: 'Edit roles',
              subtitle: 'Group membership controls effective permissions.',
            ),
            const SizedBox(height: 8),
            Flexible(
              child: ListView(
                shrinkWrap: true,
                children: [
                  for (final role in _AdminUsersScreenState._roleOptions)
                    CheckboxListTile(
                      contentPadding: EdgeInsets.zero,
                      dense: true,
                      title: Text(titleCaseOption(role)),
                      subtitle: Text(role),
                      value: _selected.contains(role),
                      onChanged: (value) => setState(() {
                        if (value == true) {
                          _selected.add(role);
                        } else {
                          _selected.remove(role);
                        }
                      }),
                    ),
                ],
              ),
            ),
            const SizedBox(height: 8),
            FilledButton(
              onPressed: () =>
                  Navigator.of(context).pop(_selected.toList()..sort()),
              child: const Text('Save roles'),
            ),
          ],
        ),
      ),
    );
  }
}

class _DirectorySyncSheet extends StatefulWidget {
  const _DirectorySyncSheet({required this.latest});

  final Map<String, dynamic>? latest;

  @override
  State<_DirectorySyncSheet> createState() => _DirectorySyncSheetState();
}

class _DirectorySyncSheetState extends State<_DirectorySyncSheet> {
  String _kind = 'all';
  bool _dryRun = true;

  @override
  Widget build(BuildContext context) {
    final latest = widget.latest;
    final active = latest != null &&
        (latest['status'] == 'queued' || latest['status'] == 'running');
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const SheetHeader(
              title: 'MIS directory sync',
              subtitle:
                  'Preview first; apply only after checking the counts.',
            ),
            const SizedBox(height: 10),
            DropdownButtonFormField<String>(
              initialValue: _kind,
              decoration: const InputDecoration(
                labelText: 'Directory kind',
                border: OutlineInputBorder(),
                isDense: true,
              ),
              items: const [
                DropdownMenuItem(value: 'all', child: Text('Students and employees')),
                DropdownMenuItem(value: 'student', child: Text('Students')),
                DropdownMenuItem(value: 'employee', child: Text('Employees')),
              ],
              onChanged: (v) => setState(() => _kind = v ?? 'all'),
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: const Text('Dry run (preview only)'),
              subtitle: const Text('Turn off to write directory changes.'),
              value: _dryRun,
              onChanged: (v) => setState(() => _dryRun = v),
            ),
            if (latest != null) ...[
              const SizedBox(height: 6),
              Text(
                'Last job #${latest['id']} · ${latest['status']} · '
                '${latest['dry_run'] == true ? 'dry run' : 'apply'}'
                '${latest['current_page'] != null ? ' · page ${latest['current_page']}' : ''}',
                style: TextStyle(
                  fontSize: 11.5,
                  color: active
                      ? const Color(0xFFB45309)
                      : Colors.black54,
                ),
              ),
              if (active)
                const Padding(
                  padding: EdgeInsets.only(top: 4),
                  child: Text(
                    'A sync is already active. Wait for it to finish before '
                    'queueing another.',
                    style: TextStyle(
                      fontSize: 11.5,
                      color: Color(0xFFB3261E),
                    ),
                  ),
                ),
            ],
            const SizedBox(height: 12),
            FilledButton(
              onPressed: active
                  ? null
                  : () => Navigator.of(context).pop(<String, dynamic>{
                        'kind': _kind,
                        'dry_run': _dryRun,
                      }),
              child: Text(_dryRun ? 'Run preview' : 'Queue sync'),
            ),
          ],
        ),
      ),
    );
  }
}
