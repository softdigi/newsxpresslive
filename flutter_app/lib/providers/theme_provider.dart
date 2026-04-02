import 'dart:async';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Manages app-wide theme and reading preferences, persisted in SharedPreferences.
///
/// Supported theme modes (persisted as strings):
///   'light'     → ThemeMode.light
///   'dark'      → ThemeMode.dark
///   'system'    → ThemeMode.system  (follows OS setting)
///   'auto_time' → Dark between 20:00–06:59, Light otherwise
class ThemeProvider extends ChangeNotifier {
  static const String _themeKey     = 'themeMode';   // 'light' | 'dark' | 'system' | 'auto_time'
  static const String _fontScaleKey = 'fontScale';   // double: 0.9 | 1.0 | 1.15

  // Night-time window for auto_time mode: 20:00 – 06:59
  static const int _nightStartHour = 20;
  static const int _nightEndHour   = 7;

  ThemeProvider(SharedPreferences prefs)
      : _prefs      = prefs,
        _storedMode = prefs.getString(_themeKey) ?? 'system',
        _fontScale  = prefs.getDouble(_fontScaleKey) ?? 1.0 {
    _scheduleAutoTimer();
  }

  final SharedPreferences _prefs;

  /// Raw persisted preference ('light', 'dark', 'system', 'auto_time').
  String _storedMode;
  double _fontScale;

  /// Timer that fires when the auto-time window boundary is crossed.
  Timer? _autoTimer;

  // ── Getters ───────────────────────────────────────────────────────────

  double    get fontScale   => _fontScale;

  /// True when the user selected time-based auto mode.
  bool get isAutoByTime => _storedMode == 'auto_time';

  /// The effective [ThemeMode] used by MaterialApp.
  ThemeMode get themeMode {
    switch (_storedMode) {
      case 'light':     return ThemeMode.light;
      case 'dark':      return ThemeMode.dark;
      case 'auto_time': return _isNightNow() ? ThemeMode.dark : ThemeMode.light;
      default:          return ThemeMode.system;
    }
  }

  /// Legacy helper for SettingsScreen switch tile.
  bool get isDark => themeMode == ThemeMode.dark;

  // ── Theme mode ────────────────────────────────────────────────────────

  Future<void> setThemeMode(ThemeMode mode) async {
    final s = _serializeThemeMode(mode);
    if (_storedMode == s) return;
    _storedMode = s;
    await _prefs.setString(_themeKey, s);
    _scheduleAutoTimer();
    notifyListeners();
  }

  Future<void> setAutoByTime(bool enable) async {
    final s = enable ? 'auto_time' : 'system';
    if (_storedMode == s) return;
    _storedMode = s;
    await _prefs.setString(_themeKey, s);
    _scheduleAutoTimer();
    notifyListeners();
  }

  /// Legacy toggle — switches between light and dark (ignores system/auto).
  Future<void> toggle() => setThemeMode(
        themeMode == ThemeMode.dark ? ThemeMode.light : ThemeMode.dark,
      );

  Future<void> setDark(bool value) =>
      setThemeMode(value ? ThemeMode.dark : ThemeMode.light);

  // ── Font scale ────────────────────────────────────────────────────────

  Future<void> setFontScale(double scale) async {
    if (_fontScale == scale) return;
    _fontScale = scale;
    await _prefs.setDouble(_fontScaleKey, scale);
    notifyListeners();
  }

  // ── Auto-time helpers ─────────────────────────────────────────────────

  /// Returns true if the current local time is within the night window
  /// (20:00:00 – 06:59:59).
  static bool _isNightNow() {
    final hour = DateTime.now().hour;
    return hour >= _nightStartHour || hour < _nightEndHour;
  }

  /// Schedule a [Timer] that fires exactly when the next light/dark boundary
  /// is crossed.  Only active when [_storedMode] == 'auto_time'.
  void _scheduleAutoTimer() {
    _autoTimer?.cancel();
    _autoTimer = null;
    if (_storedMode != 'auto_time') return;

    final now  = DateTime.now();
    final hour = now.hour;

    // Determine next boundary hour (7 or 20)
    late int targetHour;
    if (_isNightNow()) {
      // Currently night → flip to day at 07:00
      targetHour = _nightEndHour;
    } else {
      // Currently day → flip to night at 20:00
      targetHour = _nightStartHour;
    }

    var next = DateTime(now.year, now.month, now.day, targetHour);
    if (!next.isAfter(now)) next = next.add(const Duration(days: 1));

    final delay = next.difference(now);
    _autoTimer = Timer(delay, () {
      notifyListeners();         // trigger rebuild
      _scheduleAutoTimer();      // schedule next boundary
    });
  }

  @override
  void dispose() {
    _autoTimer?.cancel();
    super.dispose();
  }

  // ── Serialization ─────────────────────────────────────────────────────

  static String _serializeThemeMode(ThemeMode mode) {
    switch (mode) {
      case ThemeMode.light:  return 'light';
      case ThemeMode.dark:   return 'dark';
      default:               return 'system';
    }
  }
}
