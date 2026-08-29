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
      final page = await ApiService.I.adminUsers(q: _query, cursor: _nextCursor);
      setState(() {
        _items = [..._items, ...page.items];
        _nextCursor = page.meta?.nextCursor;
      });
    } catch (_) {
      // ignore
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
        CrudField.text('email', 'Email',
            keyboard: TextInputType.emailAddress),
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Users')),
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
    this.onResetPassword,
    this.onToggleStatus,
  });

  final AdminUser user;
  final bool canManage;
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
          color: Theme.of(context).colorScheme.outlineVariant.withValues(alpha: 0.5),
        ),
      ),
      child: ListTile(
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
                Text(user.email!, style: const TextStyle(color: Colors.black54)),
              for (final group in user.groups)
                StatusBadge(label: titleCaseOption(group), color: const Color(0xFF37474F)),
              if (user.forceReset)
                const StatusBadge(label: 'Force reset', color: Color(0xFF8A5A00)),
            ],
          ),
        ),
        isThreeLine: true,
        trailing: canManage
            ? PopupMenuButton<String>(
                icon: const Icon(HugeIcons.strokeRoundedMore),
                onSelected: (v) {
                  if (v == 'reset') onResetPassword?.call();
                  if (v == 'status') onToggleStatus?.call();
                },
                itemBuilder: (_) => [
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
