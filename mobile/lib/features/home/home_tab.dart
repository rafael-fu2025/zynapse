import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/services/auth_controller.dart';
import '../dashboard/dashboard_screen.dart';
import '../portal/portal_screen.dart';

/// The first (Home) bottom-nav tab.
///
/// Mirrors the SPA router's `HomeDispatcher`: staff/ops (hold
/// `employee.portal.read`) get the Dashboard; pure students get their
/// portal instead (a student's dashboard would be empty — they hold no
/// module counters permission).
class HomeTab extends StatelessWidget {
  const HomeTab({super.key});

  @override
  Widget build(BuildContext context) {
    final session = context.watch<AuthController>().session;
    if (session?.isStaff ?? false) return const DashboardScreen();
    return const PortalScreen();
  }
}
