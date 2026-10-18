import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/services/auth_controller.dart';
import '../../core/workstations.dart';
import '../dashboard/dashboard_screen.dart';
import '../dashboard/workstation_dashboard_screen.dart';
import '../portal/portal_screen.dart';

/// The first (Home) bottom-nav tab.
///
/// Mirrors the SPA router's `HomeDispatcher`: pure students get their portal;
/// a user with exactly one workstation permission lands directly on that
/// workstation dashboard; everyone else gets the multi-module overview.
class HomeTab extends StatelessWidget {
  const HomeTab({super.key});

  @override
  Widget build(BuildContext context) {
    final session = context.watch<AuthController>().session;
    if (session?.isStudent ?? false) return const PortalScreen();

    final units = permittedWorkstations(session);
    if (units.length == 1) {
      return WorkstationDashboardScreen(
        unit: units.single.unit,
        embedded: true,
      );
    }
    return const DashboardScreen();
  }
}
