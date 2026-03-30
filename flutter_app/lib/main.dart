import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:firebase_core/firebase_core.dart';
import 'package:google_mobile_ads/google_mobile_ads.dart';
import 'package:app_links/app_links.dart';
import 'app.dart';
import 'providers/theme_provider.dart';
import 'providers/bookmark_provider.dart';
import 'data/services/notification_service.dart';
import 'data/services/analytics_service.dart';
import 'data/services/cache_service.dart';
import 'data/services/feature_flags_service.dart';
import 'data/services/onboarding_service.dart';
import 'presentation/screens/detail/article_detail_screen.dart';
import 'main_navigation.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Lock to portrait orientation
  await SystemChrome.setPreferredOrientations([
    DeviceOrientation.portraitUp,
    DeviceOrientation.portraitDown,
  ]);

  // Status bar style
  SystemChrome.setSystemUIOverlayStyle(
    const SystemUiOverlayStyle(
      statusBarColor:           Colors.transparent,
      statusBarIconBrightness:  Brightness.dark,
    ),
  );

  // ── Firebase ───────────────────────────────────────────────────────────
  // Requires google-services.json (Android) / GoogleService-Info.plist (iOS).
  // Silently degrades if not configured.
  try {
    await Firebase.initializeApp();
  } catch (_) {}

  // ── Hive offline cache ─────────────────────────────────────────────────
  await CacheService.instance.init();

  // ── AdMob ──────────────────────────────────────────────────────────────
  // Requires AdMob App ID in AndroidManifest.xml / Info.plist.
  try {
    await MobileAds.instance.initialize();
  } catch (_) {}

  // ── Analytics ─────────────────────────────────────────────────────────
  AnalyticsService.instance.init();

  // ── FCM Push Notifications ─────────────────────────────────────────────
  await NotificationService.instance.init();

  // Navigate to article when notification is tapped
  NotificationService.instance.onArticleTap = (slug) {
    mainNavKey.currentContext.let((ctx) {
      Navigator.of(ctx, rootNavigator: true).push(
        MaterialPageRoute(
          builder: (_) => ArticleDetailScreen(slug: slug),
        ),
      );
    });
  };

  final prefs = await SharedPreferences.getInstance();

  // ── Check onboarding completion ────────────────────────────────────────
  final onboardingDone = prefs.getBool(OnboardingPrefs.completed) ?? false;

  // ── Feature flags (offline cache for cold start) ───────────────────────
  await FeatureFlagsService.instance.loadFromCache();

  final app = NewsXpressApp(
    themeProvider:    ThemeProvider(prefs),
    bookmarkProvider: BookmarkProvider(prefs),
    onboardingDone:   onboardingDone,
  );

  runApp(app);

  // ── Deep linking (after runApp so Navigator context is ready) ──────────
  _initDeepLinks();
}

// ── Deep link handler ─────────────────────────────────────────────────────

void _initDeepLinks() {
  try {
    final appLinks = AppLinks();

    // Handle cold-start deep link
    appLinks.getInitialLink().then((uri) {
      if (uri != null) _handleDeepLink(uri);
    });

    // Handle while app is running
    appLinks.uriLinkStream.listen(_handleDeepLink);
  } catch (_) {}
}

/// Parses URLs of the form: https://domain.com/news/<slug>
void _handleDeepLink(Uri uri) {
  final segments = uri.pathSegments;
  if (segments.length >= 2 && segments[0] == 'news') {
    final slug = segments[1];
    final ctx = mainNavKey.currentContext;
    if (ctx != null && slug.isNotEmpty) {
      Navigator.of(ctx, rootNavigator: true).push(
        MaterialPageRoute(
          builder: (_) => ArticleDetailScreen(slug: slug),
        ),
      );
    }
  }
}

// ── Null-safe context helper ──────────────────────────────────────────────

extension _NullableContextExt on BuildContext? {
  void let(void Function(BuildContext) fn) {
    final ctx = this;
    if (ctx != null) fn(ctx);
  }
}

