/// CDN URLs for Lottie animations used across the app.
///
/// All animations are served from LottieFiles' public CDN (free, no key).
/// Using network animations avoids bundling large JSON assets with the APK,
/// but requires an internet connection for first-render. After the first
/// render the lottie package caches the parsed composition in memory for
/// the lifetime of the isolate.
class LottieAssets {
  LottieAssets._();

  /// Spinning newspaper / loading indicator
  static const String loading =
      'https://assets3.lottiefiles.com/packages/lf20_szlepvdh.json';

  /// Empty bookmark / "nothing here" state
  static const String emptyBookmarks =
      'https://assets2.lottiefiles.com/packages/lf20_ydo1amjm.json';

  /// No offline articles saved
  static const String emptyOffline =
      'https://assets9.lottiefiles.com/packages/lf20_qm8eqzse.json';

  /// Network error / no connection
  static const String networkError =
      'https://assets5.lottiefiles.com/packages/lf20_qhrr4k7f.json';

  /// Search – no results
  static const String emptySearch =
      'https://assets7.lottiefiles.com/packages/lf20_wnqlfojb.json';
}
