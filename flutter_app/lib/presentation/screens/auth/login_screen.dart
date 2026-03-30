import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../../providers/auth_provider.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_strings.dart';

/// Login screen shown when the user is not authenticated.
///
/// Options:
///   1. Continue with Google (full account)
///   2. Continue as Guest (anonymous — skips login)
class LoginScreen extends StatelessWidget {
  const LoginScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final auth  = context.watch<AuthProvider>();
    final theme = Theme.of(context);

    return Scaffold(
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 32),
          child: Column(
            children: [
              const Spacer(flex: 2),

              // Logo & branding
              const _AppLogo(),
              const SizedBox(height: 12),

              Text(
                AppStrings.appName,
                style: theme.textTheme.headlineLarge?.copyWith(
                  color:      AppColors.primary,
                  fontWeight: FontWeight.w900,
                  fontSize:   32,
                ),
              ),
              const SizedBox(height: 6),
              Text(
                AppStrings.tagline,
                style: theme.textTheme.bodyMedium
                    ?.copyWith(color: Colors.grey),
                textAlign: TextAlign.center,
              ),

              const Spacer(flex: 3),

              // Error message
              if (auth.errorMsg != null) ...[
                Text(
                  auth.errorMsg!,
                  style: const TextStyle(color: Colors.red, fontSize: 13),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 16),
              ],

              // Google Sign-In button
              SizedBox(
                width: double.infinity,
                child: ElevatedButton.icon(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: Colors.white,
                    foregroundColor: Colors.black87,
                    side: const BorderSide(color: Color(0xFFDDDDDD)),
                    padding: const EdgeInsets.symmetric(vertical: 14),
                    elevation: 1,
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(8)),
                  ),
                  onPressed: auth.loading
                      ? null
                      : () => context.read<AuthProvider>().signInWithGoogle(),
                  icon: const _GoogleIcon(),
                  label: auth.loading
                      ? const SizedBox(
                          height: 18,
                          width:  18,
                          child: CircularProgressIndicator(strokeWidth: 2))
                      : const Text(
                          AppStrings.signInWithGoogle,
                          style: TextStyle(
                              fontWeight: FontWeight.w600, fontSize: 15),
                        ),
                ),
              ),

              const SizedBox(height: 14),

              // Guest button
              SizedBox(
                width: double.infinity,
                child: TextButton(
                  onPressed: auth.loading
                      ? null
                      : () =>
                          context.read<AuthProvider>().signInAnonymously(),
                  child: Text(
                    AppStrings.continueAsGuest,
                    style: const TextStyle(
                        color: Colors.grey, fontSize: 14),
                  ),
                ),
              ),

              const Spacer(),

              // Disclaimer
              Text(
                'By continuing, you agree to our Privacy Policy.',
                style: theme.textTheme.bodySmall
                    ?.copyWith(color: Colors.grey.shade400),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 24),
            ],
          ),
        ),
      ),
    );
  }
}

// ── Sub-widgets ───────────────────────────────────────────────────────────

class _AppLogo extends StatelessWidget {
  const _AppLogo();

  @override
  Widget build(BuildContext context) {
    return Container(
      width:  80,
      height: 80,
      decoration: BoxDecoration(
        color:        AppColors.primary,
        borderRadius: BorderRadius.circular(20),
      ),
      child: const Icon(Icons.newspaper_rounded,
          color: Colors.white, size: 44),
    );
  }
}

class _GoogleIcon extends StatelessWidget {
  const _GoogleIcon();

  @override
  Widget build(BuildContext context) {
    // Google 'G' logo using coloured text — no image dependency required.
    return const Text(
      'G',
      style: TextStyle(
        fontSize:   20,
        fontWeight: FontWeight.bold,
        color:      Color(0xFF4285F4),
        height:     1,
      ),
    );
  }
}
