import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/services/auth_controller.dart';
import '../admin/admin_users_screen.dart';
import '../audit/audit_screen.dart';
import '../clinic/clinic_screen.dart';
import '../counselling/counselling_screen.dart';
import '../facilities/facilities_screen.dart';
import '../facilities/waste_categories_screen.dart';
import '../inventory/inventory_screen.dart';
import '../kiosk/kiosk_admin_screen.dart';
import '../medicines/medicines_screen.dart';
import '../notifications/notifications_screen.dart';
import '../patients/patients_screen.dart';
import '../referrals/referrals_screen.dart';
import '../reports/reports_screen.dart';

class _Module {
  const _Module(
    this.label,
    this.icon,
    this.color,
    this.screen, [
    this.permission,
  ]);

  final String label;
  final IconData icon;
  final Color color;
  final Widget screen;

  /// Any-of permission gate (mirrors the SPA sidebar). Null = always shown.
  final List<String>? permission;
}

const _modules = <_Module>[
  _Module('Clinic', HugeIcons.strokeRoundedStethoscope, Color(0xFF0F766E),
      ClinicScreen(), ['clinic.encounters.read']),
  _Module('Patients', HugeIcons.strokeRoundedUserMultiple, Color(0xFF1E6FD9),
      PatientsScreen(), ['clinic.patients.read']),
  _Module('Inventory', HugeIcons.strokeRoundedPackage02, Color(0xFFB45309),
      InventoryScreen(), ['clinic.inventory.read']),
  _Module('Medicines', HugeIcons.strokeRoundedMedicine01, Color(0xFF1B7A43),
      MedicinesScreen(), ['clinic.inventory.read']),
  _Module('Counselling', HugeIcons.strokeRoundedMessage01, Color(0xFF5B4BA6),
      CounsellingScreen(), ['counselling.records.read']),
  _Module('Referrals', HugeIcons.strokeRoundedShare01, Color(0xFF0F766E),
      ReferralsScreen(), ['referrals.read']),
  _Module('Facilities', HugeIcons.strokeRoundedFactory01, Color(0xFF37474F),
      FacilitiesScreen(), ['facilities.units.read']),
  _Module('Waste Category', HugeIcons.strokeRoundedRecycle01, Color(0xFF4E5D33),
      WasteCategoriesScreen(), ['facilities.categories.manage']),
  _Module('Reports', HugeIcons.strokeRoundedChart01, Color(0xFF6D4C41),
      ReportsScreen(), ['reports.read']),
  _Module('Audit', HugeIcons.strokeRoundedAudit01, Color(0xFF546E7A),
      AuditScreen(), ['audit.read']),
  _Module('Notifications', HugeIcons.strokeRoundedNotification03,
      Color(0xFF8A5A00), NotificationsScreen(), ['notifications.read']),
  _Module('Users', HugeIcons.strokeRoundedUserGroup, Color(0xFF6A1B9A),
      AdminUsersScreen(), ['rbac.manage']),
  _Module('Kiosk', Icons.tv_outlined, Color(0xFF800000), KioskAdminScreen(),
      ['kiosk.content.manage']),
];

/// The "More / Modules" tab — a permission-gated grid of every module
/// surface (kiosk check-in / stations intentionally stay on the web app).
class ModuleHubScreen extends StatelessWidget {
  const ModuleHubScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final session = context.watch<AuthController>().session;
    final visible = _modules.where((m) {
      final p = m.permission;
      if (p == null) return true;
      return p.any((code) => session?.hasPermission(code) ?? false);
    }).toList();

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          'All modules',
          style: Theme.of(context)
              .textTheme
              .titleMedium
              ?.copyWith(fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 4),
        Text(
          'Kiosk check-in and the public display stay web-only; administrators can manage display content here.',
          style: Theme.of(context)
              .textTheme
              .bodySmall
              ?.copyWith(color: Colors.black45),
        ),
        const SizedBox(height: 12),
        GridView.count(
          crossAxisCount: 3,
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          mainAxisSpacing: 12,
          crossAxisSpacing: 12,
          childAspectRatio: 0.95,
          children: [
            for (final m in visible)
              _ModuleTile(
                module: m,
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => m.screen),
                ),
              ),
          ],
        ),
      ],
    );
  }
}

class _ModuleTile extends StatelessWidget {
  const _ModuleTile({required this.module, required this.onTap});

  final _Module module;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14),
      child: Container(
        decoration: BoxDecoration(
          color: Theme.of(context).colorScheme.surface,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
            color: Theme.of(context)
                .colorScheme
                .outlineVariant
                .withValues(alpha: 0.5),
          ),
        ),
        padding: const EdgeInsets.all(10),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            CircleAvatar(
              radius: 22,
              backgroundColor: module.color.withValues(alpha: 0.12),
              child: Icon(module.icon, color: module.color, size: 22),
            ),
            const SizedBox(height: 8),
            Text(
              module.label,
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
          ],
        ),
      ),
    );
  }
}
