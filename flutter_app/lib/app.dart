import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'core/theme/app_theme.dart';
import 'core/constants/app_strings.dart';
import 'core/constants/app_colors.dart';
import 'providers/news_provider.dart';
import 'providers/theme_provider.dart';
import 'providers/bookmark_provider.dart';
import 'providers/auth_provider.dart';
import 'providers/onboarding_provider.dart';
import 'providers/feature_flags_provider.dart';
import 'main_navigation.dart';
import 'presentation/screens/auth/login_screen.dart';
import 'presentation/screens/onboarding/onboarding_screen.dart';

/// Root widget: sets up providers and routing (auth → onboarding → main).
class NewsXpressApp extends StatelessWidget {
  const NewsXpressApp({
    super.key,
    required this.themeProvider,
    required this.bookmarkProvider,
    required this.onboardingDone,
  });

  final ThemeProvider    themeProvider;
  final BookmarkProvider bookmarkProvider;
  final bool             onboardingDone;

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        ChangeNotifierProvider.value(value: themeProvider),
        ChangeNotifierProvider.value(value: bookmarkProvider),
        ChangeNotifierProvider(create: (_) => NewsProvider()),
        ChangeNotifierProvider(create: (_) => AuthProvider()),
        ChangeNotifierProvider(create: (_) => OnboardingProvider()),
        ChangeNotifierProvider(create: (_) => FeatureFlagsProvider()),
      ],
      child: Consumer<ThemeProvider>(
        builder: (context, theme, child) => MaterialApp(
          title:                      AppStrings.appName,
          debugShowCheckedModeBanner: false,
          theme:                      AppTheme.light,
          darkTheme:                  AppTheme.dark,
          themeMode:                  theme.themeMode,
          // Override text scale factor app-wide based on user preference
          builder: (context, widget) {
            final scale = theme.fontScale;
            return MediaQuery(
              data: MediaQuery.of(context).copyWith(
                textScaler: TextScaler.linear(scale),
              ),
              child: widget!,
            );
          },
          home: child,
        ),
        child: _AuthGate(onboardingDone: onboardingDone),
      ),
    );
  }
}

/// Routes to LoginScreen, OnboardingScreen, or MainNavigation based on
/// the current auth state and onboarding completion flag.
class _AuthGate extends StatefulWidget {
  const _AuthGate({required this.onboardingDone});

  final bool onboardingDone;

  @override
  State<_AuthGate> createState() => _AuthGateState();
}

class _AuthGateState extends State<_AuthGate> {
  bool _showOnboarding = false;

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();

    // While checking auth, show a splash
    if (auth.state == AuthState.unknown) {
      return const _SplashScreen();
    }

    // Not signed in at all — show login screen
    if (auth.state == AuthState.signedOut) {
      return const LoginScreen();
    }

    // Signed in but onboarding not done and not guest
    if (!widget.onboardingDone && !_showOnboarding && !auth.isGuest) {
      return OnboardingScreen(
        onComplete: () => setState(() => _showOnboarding = true),
      );
    }

    // All good — show main navigation
    return MainNavigation(key: mainNavKey);
  }
}

class _SplashScreen extends StatelessWidget {
  const _SplashScreen();

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      body: Center(
        child: CircularProgressIndicator(color: AppColors.primary),
      ),
    );
  }
}

