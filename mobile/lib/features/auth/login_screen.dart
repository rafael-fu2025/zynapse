import 'dart:async';

import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_envelope.dart';
import '../../core/models/carousel_slide.dart';
import '../../core/services/api_service.dart';
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
  final _pageController = PageController();
  List<CarouselSlide> _slides = const [];
  int _slideIndex = 0;
  Timer? _carouselTimer;
  bool _obscure = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadSlides();
  }

  /// Public, unauthenticated slides. Any failure keeps the static login
  /// art — the login form must never depend on this request.
  Future<void> _loadSlides() async {
    try {
      final slides = await ApiService.I.carouselSlides();
      if (!mounted || slides.isEmpty) return;
      setState(() => _slides = slides);
      if (slides.length > 1) {
        _carouselTimer = Timer.periodic(const Duration(seconds: 5), (_) {
          if (!mounted || !_pageController.hasClients) return;
          final next = (_slideIndex + 1) % _slides.length;
          _pageController.animateToPage(
            next,
            duration: const Duration(milliseconds: 350),
            curve: Curves.easeOut,
          );
        });
      }
    } catch (_) {
      // Static logo fallback.
    }
  }

  @override
  void dispose() {
    _carouselTimer?.cancel();
    _pageController.dispose();
    _identifierController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() => _error = null);
    if (!_formKey.currentState!.validate()) return;

    final auth = context.read<AuthController>();
    auth.clearSessionNotice();
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
                if (_slides.isNotEmpty) ...[
                  const SizedBox(height: 20),
                  _LoginCarousel(
                    slides: _slides,
                    controller: _pageController,
                    index: _slideIndex,
                    onChanged: (index) =>
                        setState(() => _slideIndex = index),
                  ),
                ],
                if (auth.sessionNotice != null) ...[
                  const SizedBox(height: 20),
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: const Color(0xFFFFF4E5),
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: const Color(0xFFF0D9B5)),
                    ),
                    child: Row(
                      children: [
                        const Icon(
                          Icons.info_outline,
                          size: 18,
                          color: Color(0xFF8A5A00),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            auth.sessionNotice!,
                            style: const TextStyle(
                              color: Color(0xFF8A5A00),
                              fontSize: 13,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
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
                            labelText: 'ID Number',
                            border: OutlineInputBorder(
                              borderRadius:
                                  BorderRadius.all(Radius.circular(12)),
                            ),
                          ),
                          validator: (v) => (v == null || v.trim().isEmpty)
                              ? 'Enter your ID number'
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
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _LoginCarousel extends StatelessWidget {
  const _LoginCarousel({
    required this.slides,
    required this.controller,
    required this.index,
    required this.onChanged,
  });

  final List<CarouselSlide> slides;
  final PageController controller;
  final int index;
  final ValueChanged<int> onChanged;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        SizedBox(
          height: 150,
          child: PageView.builder(
            controller: controller,
            itemCount: slides.length,
            onPageChanged: onChanged,
            itemBuilder: (context, i) {
              final slide = slides[i];
              return ClipRRect(
                borderRadius: BorderRadius.circular(16),
                child: Stack(
                  fit: StackFit.expand,
                  children: [
                    if (slide.fitMode == 'contain_blur') ...[
                      Image.network(
                        slide.imageUrl,
                        fit: BoxFit.cover,
                        errorBuilder: (_, __, ___) => _fallbackArt(),
                      ),
                      const ColoredBox(color: Colors.black45),
                      Center(
                        child: Image.network(
                          slide.imageUrl,
                          fit: BoxFit.contain,
                          errorBuilder: (_, __, ___) => _fallbackArt(),
                        ),
                      ),
                    ] else
                      Image.network(
                        slide.imageUrl,
                        fit: BoxFit.cover,
                        errorBuilder: (_, __, ___) => _fallbackArt(),
                      ),
                    const DecoratedBox(
                      decoration: BoxDecoration(
                        gradient: LinearGradient(
                          begin: Alignment.topCenter,
                          end: Alignment.bottomCenter,
                          colors: [Colors.transparent, Colors.black54],
                        ),
                      ),
                    ),
                    if ((slide.title ?? '').isNotEmpty ||
                        (slide.subtitle ?? '').isNotEmpty)
                      Positioned(
                        left: 14,
                        right: 14,
                        bottom: 12,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            if ((slide.title ?? '').isNotEmpty)
                              Text(
                                slide.title!,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(
                                  color: Colors.white,
                                  fontSize: 15,
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                            if ((slide.subtitle ?? '').isNotEmpty)
                              Text(
                                slide.subtitle!,
                                maxLines: 2,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(
                                  color: Colors.white70,
                                  fontSize: 11.5,
                                ),
                              ),
                          ],
                        ),
                      ),
                  ],
                ),
              );
            },
          ),
        ),
        if (slides.length > 1) ...[
          const SizedBox(height: 8),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              for (var i = 0; i < slides.length; i++)
                Container(
                  width: i == index ? 16 : 6,
                  height: 6,
                  margin: const EdgeInsets.symmetric(horizontal: 2),
                  decoration: BoxDecoration(
                    color: i == index
                        ? const Color(0xFF800000)
                        : const Color(0xFFD6D3D1),
                    borderRadius: BorderRadius.circular(3),
                  ),
                ),
            ],
          ),
        ],
      ],
    );
  }

  Widget _fallbackArt() {
    return Container(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [Color(0xFFAC1E37), Color(0xFF751020)],
        ),
      ),
      child: const Center(
        child: Icon(
          Icons.local_hospital_outlined,
          color: Colors.white54,
          size: 34,
        ),
      ),
    );
  }
}
