import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';

import '../../core/api/api_client.dart';
import '../../core/services/api_service.dart';
import '../common/widgets.dart';

/// Read-only role → permission matrix. Roles are code-defined and reviewed
/// in git; this mirrors the SPA AdminRolesPage.
class AdminRolesScreen extends StatefulWidget {
  const AdminRolesScreen({super.key});

  @override
  State<AdminRolesScreen> createState() => _AdminRolesScreenState();
}

class _AdminRolesScreenState extends State<AdminRolesScreen> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _roles = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _roles.isEmpty;
      _error = null;
    });
    try {
      final roles = await ApiService.I.adminRoles();
      if (!mounted) return;
      setState(() {
        _roles = roles;
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Roles'),
        actions: [
          IconButton(
            onPressed: _load,
            tooltip: 'Refresh',
            icon: const Icon(HugeIcons.strokeRoundedRefresh),
          ),
        ],
      ),
      body: _buildBody(),
    );
  }

  Widget _buildBody() {
    if (_loading) return AsyncState.loading();
    if (_error != null) return AsyncState.error(_error!, onRetry: _load);
    if (_roles.isEmpty) return AsyncState.empty('No roles in the catalog.');
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.separated(
        padding: const EdgeInsets.all(16),
        itemCount: _roles.length,
        separatorBuilder: (_, __) => const SizedBox(height: 8),
        itemBuilder: (context, i) {
          final role = _roles[i];
          final permissions = (role['permissions'] as List? ?? const [])
              .whereType<String>()
              .toList();
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
              onTap: () => _showPermissions(role, permissions),
              title: Text(
                (role['name'] ?? role['code'] ?? '') as String,
                style: const TextStyle(fontWeight: FontWeight.w700),
              ),
              subtitle: Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Wrap(
                  spacing: 6,
                  runSpacing: 4,
                  children: [
                    Text(
                      (role['code'] ?? '') as String,
                      style: const TextStyle(fontSize: 11.5),
                    ),
                    if (role['wildcard'] == true)
                      const StatusBadge(
                        label: 'Wildcard',
                        color: Color(0xFF5B4BA6),
                      ),
                    if (role['privileged'] == true)
                      const StatusBadge(
                        label: 'Privileged',
                        color: Color(0xFF8A5A00),
                      ),
                    StatusBadge(
                      label: '${permissions.length} permissions',
                      color: Colors.black54,
                    ),
                  ],
                ),
              ),
              trailing: const Icon(
                HugeIcons.strokeRoundedArrowRight01,
                size: 18,
              ),
            ),
          );
        },
      ),
    );
  }

  void _showPermissions(
    Map<String, dynamic> role,
    List<String> permissions,
  ) {
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SheetHeader(
                title: (role['name'] ?? role['code'] ?? 'Role') as String,
                subtitle: (role['code'] ?? '') as String,
              ),
              const SizedBox(height: 8),
              Flexible(
                child: permissions.isEmpty
                    ? const Padding(
                        padding: EdgeInsets.symmetric(vertical: 18),
                        child: Text(
                          'No permissions granted.',
                          style: TextStyle(
                              fontSize: 12.5, color: Colors.black45),
                        ),
                      )
                    : ListView.separated(
                        shrinkWrap: true,
                        itemCount: permissions.length,
                        separatorBuilder: (_, __) => const Divider(height: 1),
                        itemBuilder: (_, i) => ListTile(
                          contentPadding: EdgeInsets.zero,
                          dense: true,
                          title: Text(
                            permissions[i],
                            style: const TextStyle(fontSize: 12.5),
                          ),
                        ),
                      ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
