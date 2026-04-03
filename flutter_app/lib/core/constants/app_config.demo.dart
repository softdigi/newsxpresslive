// ============================================================
// flutter_app/lib/core/constants/app_config.demo.dart
//
// DEMO / TEMPLATE FILE — placeholder values hain.
// Safe to commit. Real values sirf aap ko pata hone chahiye.
//
// COPY KARKE USE KARO:
//   cp lib/core/constants/app_config.demo.dart \
//      lib/core/constants/app_config.dart
//   Phir YOUR_ wali values replace karo.
//
// NOTE: app_config.dart .gitignore mein add karo agar
//       AdMob IDs private rakhni hain (recommended for production).
// ============================================================

/// App-level configuration constants.
/// Replace all YOUR_... values before building for release.
class AppConfig {
  AppConfig._();

  // ── App Identifiers ───────────────────────────────────────────────────

  /// Android package name — must match Firebase + Play Console
  static const String androidPackageName = 'com.yourcompany.newsxpresslive';

  /// iOS bundle identifier — must match Firebase + App Store Connect
  static const String iosBundleId = 'com.yourcompany.newsxpresslive';

  // ── AdMob ─────────────────────────────────────────────────────────────
  // Get real IDs from: https://admob.google.com → Apps → Ad units
  //
  // TEST IDs (use during development — safe, will show test ads):
  //   Android App ID:   ca-app-pub-3940256099942544~3347511713
  //   iOS App ID:       ca-app-pub-3940256099942544~1458002511
  //   Banner Android:   ca-app-pub-3940256099942544/6300978111
  //   Banner iOS:       ca-app-pub-3940256099942544/2934735716

  /// AdMob App ID — set in AndroidManifest.xml (android:value) and
  /// iOS Info.plist (GADApplicationIdentifier)
  static const String admobAppIdAndroid = 'ca-app-pub-YOUR_PUBLISHER_ID~YOUR_ANDROID_APP_ID';
  static const String admobAppIdIos     = 'ca-app-pub-YOUR_PUBLISHER_ID~YOUR_IOS_APP_ID';

  /// Banner ad unit IDs
  static const String admobBannerAndroid = 'ca-app-pub-YOUR_PUBLISHER_ID/YOUR_ANDROID_BANNER_ID';
  static const String admobBannerIos     = 'ca-app-pub-YOUR_PUBLISHER_ID/YOUR_IOS_BANNER_ID';

  // ── Backend API ───────────────────────────────────────────────────────
  // This mirrors ApiEndpoints.baseUrl — change both together

  /// Production server URL (no trailing slash)
  static const String apiBaseUrl = 'https://yourdomain.com';

  // ── Play Store / App Store ────────────────────────────────────────────

  static const String playStoreUrl  = 'https://play.google.com/store/apps/details?id=$androidPackageName';
  static const String appStoreUrl   = 'https://apps.apple.com/app/id YOUR_APP_STORE_ID';

  // ── Misc ──────────────────────────────────────────────────────────────

  /// Support email shown in Settings screen
  static const String supportEmail  = 'support@yourdomain.com';

  /// Privacy policy URL
  static const String privacyPolicyUrl = 'https://yourdomain.com/privacy-policy';

  /// Terms of service URL
  static const String termsUrl     = 'https://yourdomain.com/terms';
}
