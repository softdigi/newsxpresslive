import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Manages app-wide theme and reading preferences, persisted in SharedPreferences.
class ThemeProvider extends ChangeNotifier {
  static const String _themeKey     = 'themeMode';   // 'light' | 'dark' | 'system'
  static const String _fontScaleKey = 'fontScale';   // double: 0.9 | 1.0 | 1.15

  ThemeProvider(SharedPreferences prefs)
      : _prefs      = prefs,
        _themeMode  = _parseThemeMode(prefs.getString(_themeKey) ?? 'system'),
        _fontScale  = prefs.getDouble(_fontScaleKey) ?? 1.0;

  final SharedPreferences _prefs;
  ThemeMode _themeMode;
  double    _fontScale;

  // ── Getters ───────────────────────────────────────────────────────────
  ThemeMode get themeMode => _themeMode;
  double    get fontScale => _fontScale;

  /// Legacy helper for SettingsScreen switch tile.
  bool get isDark => _themeMode == ThemeMode.dark;

  // ── Theme mode ────────────────────────────────────────────────────────

  Future<void> setThemeMode(ThemeMode mode) async {
    if (_themeMode == mode) return;
    _themeMode = mode;
    await _prefs.setString(_themeKey, _serializeThemeMode(mode));
    notifyListeners();
  }

  /// Legacy toggle — switches between light and dark (ignores system).
  Future<void> toggle() => setThemeMode(
        _themeMode == ThemeMode.dark ? ThemeMode.light : ThemeMode.dark,
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

  // ── Helpers ───────────────────────────────────────────────────────────

  static ThemeMode _parseThemeMode(String raw) {
    switch (raw) {
      case 'light':  return ThemeMode.light;
      case 'dark':   return ThemeMode.dark;
      default:       return ThemeMode.system;
    }
  }

  static String _serializeThemeMode(ThemeMode mode) {
    switch (mode) {
      case ThemeMode.light:  return 'light';
      case ThemeMode.dark:   return 'dark';
      default:               return 'system';
    }
  }
}
