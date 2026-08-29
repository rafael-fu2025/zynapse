import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import 'core/services/auth_controller.dart';
import 'features/auth/login_screen.dart';
import 'features/home/home_shell.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  // Maroon system chrome. The app runs edge-to-edge: the top status bar is
  // transparent so the maroon AppBar blends behind it (white icons), and the
  // bottom OS nav bar is transparent so the app's own maroon NavigationBar
  // paints down to the physical screen edge. The native MainActivity also
  // forces edge-to-edge + a transparent, scrim-free OS bar, because ColorOS
  // ignores setNavigationBarColor and otherwise renders a black/white strip.
  SystemChrome.setSystemUIOverlayStyle(
    const SystemUiOverlayStyle(
      statusBarColor: Colors.transparent,
      statusBarIconBrightness: Brightness.light,
      statusBarBrightness: Brightness.dark,
      systemNavigationBarColor: Colors.transparent,
      systemNavigationBarIconBrightness: Brightness.light,
      systemNavigationBarDividerColor: Colors.transparent,
      // Don't let Android overlay a translucent scrim that darkens the maroon.
      systemNavigationBarContrastEnforced: false,
    ),
  );
  // Kick off the session bootstrap before the first frame so the shell
  // shows a splash instead of flashing the login screen.
  AuthController.I.bootstrap();
  runApp(const SynapseApp());
}

/// SYNAPSE mobile app — a Flutter demo client for the CodeIgniter backend.
class SynapseApp extends StatelessWidget {
  const SynapseApp({super.key});

  @override
  Widget build(BuildContext context) {
    return ChangeNotifierProvider.value(
      value: AuthController.I,
      child: MaterialApp(
        title: 'SYNAPSE Mobile',
        debugShowCheckedModeBanner: false,
        theme: _buildTheme(),
        home: const RootGate(),
      ),
    );
  }

  ThemeData _buildTheme() {
    // SYNAPSE brand maroon — used for BOTH the app bar (header) and the
    // bottom navigation bar (footer) so the chrome is consistent.
    const maroon = Color(0xFF800000);
    final base = ColorScheme.fromSeed(seedColor: maroon);
    final scheme = base.copyWith(
      primary: maroon,
      onPrimary: Colors.white,
      // Pin explicit light surfaces: M3's derived neutral tones for a
      // maroon seed render muddy/gray on cards, which read as "dark mode"
      // on the module screens. White cards + light surfaces fix that.
      surface: Colors.white,
      onSurface: const Color(0xFF1C1917),
      surfaceContainerLowest: Colors.white,
      surfaceContainerLow: const Color(0xFFF7F5F4),
      surfaceContainer: const Color(0xFFF1EFEE),
      surfaceContainerHigh: const Color(0xFFEAE7E5),
      surfaceContainerHighest: const Color(0xFFE3E0DE),
      outlineVariant: const Color(0xFFE2DDDB),
    );
    return ThemeData(
      useMaterial3: true,
      // Figtree — the same font the web SPA loads from Google Fonts
      // (bundled variable font, wght 300–900), so mobile typography
      // matches the web app. TextStyle fontWeight drives the wght axis.
      fontFamily: 'Figtree',
      colorScheme: scheme,
      scaffoldBackgroundColor: const Color(0xFFF7F5F4),
      appBarTheme: AppBarTheme(
        backgroundColor: scheme.primary, // #800000 header
        foregroundColor: scheme.onPrimary,
        elevation: 0,
        centerTitle: false,
      ),
      // Tab bars sit on the maroon AppBar (Counselling, Reports) — the M3
      // default label color is `primary` (maroon), which is invisible on the
      // maroon header. Pin white labels + a white indicator globally.
      tabBarTheme: const TabBarThemeData(
        labelColor: Colors.white,
        unselectedLabelColor: Colors.white70,
        indicatorColor: Colors.white,
      ),
      // Maroon footer matching the header.
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: scheme.primary,
        indicatorColor: Colors.white.withValues(alpha: 0.22),
        iconTheme: WidgetStateProperty.resolveWith(
          (states) => IconThemeData(
            color: states.contains(WidgetState.selected)
                ? Colors.white
                : Colors.white.withValues(alpha: 0.75),
          ),
        ),
        labelTextStyle: WidgetStateProperty.resolveWith(
          (states) => TextStyle(
            fontSize: 12,
            fontWeight: states.contains(WidgetState.selected)
                ? FontWeight.w700
                : FontWeight.w500,
            color: states.contains(WidgetState.selected)
                ? Colors.white
                : Colors.white.withValues(alpha: 0.75),
          ),
        ),
      ),
      // Consistent white cards everywhere (module screens included).
      cardTheme: CardThemeData(
        color: Colors.white,
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
        ),
      ),
      dialogTheme: const DialogThemeData(backgroundColor: Colors.white),
      inputDecorationTheme: const InputDecorationTheme(
        border: OutlineInputBorder(),
        filled: true,
        fillColor: Colors.white,
      ),
    );
  }
}

/// Decides between the login screen and the home shell based on auth state.
///
/// Mirrors `frontend/src/components/ProtectedRoute.tsx` + the
/// `useBootstrapSession` gating: while booting we show a splash; once the
/// session resolves we render the authenticated shell or the login screen.
class RootGate extends StatelessWidget {
  const RootGate({super.key});

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthController>();
    if (auth.isBooting) {
      return Scaffold(
        backgroundColor: Colors.white,
        body: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Image.asset(
                'assets/synapse-maroon.png',
                height: 120,
                fit: BoxFit.contain,
              ),
              const SizedBox(height: 24),
              const SizedBox(
                width: 24,
                height: 24,
                child: CircularProgressIndicator(strokeWidth: 2.5),
              ),
              const SizedBox(height: 12),
              const Text(
                'Restoring session…',
                style: TextStyle(color: Colors.black54),
              ),
            ],
          ),
        ),
      );
    }
    return auth.isLoggedIn ? const HomeShell() : const LoginScreen();
  }
}
