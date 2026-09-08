import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_envelope.dart';
import '../../core/services/auth_controller.dart';

/// Login screen — mirrors `frontend/src/pages/LoginPage.tsx`.
///
/// No credentials are prefilled: the demo/dev accounts are seeded by
/// `DevUserSeeder` on the backend and must not ship inside the app
/// binary (a prefilled admin password is a credential leak for anyone
/// who installs the APK against a reachable dev backend).
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _identifierController = TextEditingController();
  final _passwordController = TextEditingController();
  bool _obscure = true;
  String? _error;

  @override
  void dispose() {
    _identifierController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() => _error = null);
    if (!_formKey.currentState!.validate()) return;

    final auth = context.read<AuthController>();
    try {
      await auth.login(_identifierController.text, _passwordController.text);
      // RootGate rebuilds on AuthController notify -> navigates to HomeShell.
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } catch (e) {
      setState(() => _error = '$e');
    }
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final auth = context.watch<AuthController>();
    final busy = auth.isBusy;

    return Scaffold(
      // Clean all-white login — no maroon band, no fade.
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                // Maroon logo — visible on the white background.
                Center(
                  child: Image.asset(
                    'assets/synapse-maroon.png',
                    height: 120,
                    fit: BoxFit.contain,
                  ),
                ),
                const SizedBox(height: 20),
                const Text(
                  'SYNAPSE',
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: Color(0xFF800000),
                    fontSize: 28,
                    fontWeight: FontWeight.w800,
                    letterSpacing: 2,
                  ),
                ),
                const SizedBox(height: 6),
                const Text(
                  'Use your university ID number and password. All access is audited.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Colors.black54, fontSize: 14),
                ),
                const SizedBox(height: 40),
                Form(
                  key: _formKey,
                  // Password-manager support (username + password hints).
                  child: AutofillGroup(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        TextFormField(
                          controller: _identifierController,
                          enabled: !busy,
                          keyboardType: TextInputType.text,
                          autocorrect: false,
                          autofillHints: const [AutofillHints.username],
                          textInputAction: TextInputAction.next,
                          decoration: const InputDecoration(
                            labelText: 'Student / Employee number',
                            helperText:
                                'Administrators: sign in with your email address.',
                            border: OutlineInputBorder(
                              borderRadius:
                                  BorderRadius.all(Radius.circular(12)),
                            ),
                          ),
                          validator: (v) => (v == null || v.trim().isEmpty)
                              ? 'Enter your student or employee number'
                              : null,
                        ),
                      const SizedBox(height: 16),
                        TextFormField(
                          controller: _passwordController,
                          enabled: !busy,
                          obscureText: _obscure,
                          autofillHints: const [AutofillHints.password],
                          textInputAction: TextInputAction.done,
                          decoration: InputDecoration(
                            labelText: 'Password',
                            border: const OutlineInputBorder(
                              borderRadius:
                                  BorderRadius.all(Radius.circular(12)),
                            ),
                            suffixIcon: IconButton(
                              icon: Icon(
                                _obscure
                                    ? HugeIcons.strokeRoundedViewOff
                                    : HugeIcons.strokeRoundedView,
                              ),
                              onPressed: () =>
                                  setState(() => _obscure = !_obscure),
                            ),
                          ),
                          validator: (v) => (v == null || v.isEmpty)
                              ? 'Enter your password'
                              : null,
                          onFieldSubmitted: (_) => busy ? null : _submit(),
                        ),
                      if (_error != null) ...[
                        const SizedBox(height: 16),
                        Container(
                          padding: const EdgeInsets.all(12),
                          decoration: BoxDecoration(
                            color: scheme.error.withValues(alpha: 0.08),
                            borderRadius: BorderRadius.circular(10),
                          ),
                          child: Row(
                            children: [
                              Icon(HugeIcons.strokeRoundedAlert02,
                                  size: 18, color: scheme.error),
                              const SizedBox(width: 8),
                              Expanded(
                                child: Text(
                                  _error!,
                                  style: TextStyle(color: scheme.error),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ],
                      const SizedBox(height: 24),
                      FilledButton(
                        onPressed: busy ? null : _submit,
                        style: FilledButton.styleFrom(
                          padding: const EdgeInsets.symmetric(vertical: 16),
                          // Same rounded-rectangle shape as the inputs.
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(12),
                          ),
                        ),
                        child: busy
                            ? const SizedBox(
                                width: 22,
                                height: 22,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2.5,
                                ),
                              )
                            : const Text('Sign in'),
                      ),
                    ],
                  ),                  ),                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
