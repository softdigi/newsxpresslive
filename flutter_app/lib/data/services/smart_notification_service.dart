import 'dart:convert';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Client-side intelligence layer for the push-notification pipeline.
///
/// Responsibilities:
///   1. **Interest filtering** — suppress notifications whose category is not
///      in the user's saved interest list.
///   2. **Quiet hours** — suppress notifications between [quietStartHour] and
///      [quietEndHour] (default 22:00–07:00).
///   3. **Frequency cap** — suppress notifications that would exceed
///      [hourlyLimit] or [dailyLimit] per device.
///   4. **Breaking alert personalisation** — breaking news can bypass interest
///      filtering when [breakingAlertsEnabled] is true.
///   5. **Engagement tracking** — records the hour whenever the user opens an
///      article, building a histogram that surfaces the user's most-active
///      hours for display in the Settings UI.
///
/// [init] must be called once (from main.dart) before any other method.
class SmartNotificationService {
  SmartNotificationService._();

  static final SmartNotificationService instance = SmartNotificationService._();

  // ── SharedPreferences keys ────────────────────────────────────────────
  static const String _keyInterestFilter     = 'sn_interest_filter';
  static const String _keyBreakingAlerts     = 'sn_breaking_alerts';
  static const String _keyQuietStart         = 'sn_quiet_start';
  static const String _keyQuietEnd           = 'sn_quiet_end';
  static const String _keyHourlyLimit        = 'sn_hourly_limit';
  static const String _keyDailyLimit         = 'sn_daily_limit';
  static const String _keyNotifLog           = 'sn_notif_log';
  static const String _keyEngagement         = 'sn_engagement';
  static const String _keyInterestCategories = 'sn_interest_categories';

  // ── Defaults ──────────────────────────────────────────────────────────
  static const int _defaultHourlyLimit = 5;
  static const int _defaultDailyLimit  = 20;
  static const int _defaultQuietStart  = 22;
  static const int _defaultQuietEnd    = 7;

  late SharedPreferences _prefs;
  bool _initialized = false;

  // ── Init ──────────────────────────────────────────────────────────────

  Future<void> init() async {
    _prefs = await SharedPreferences.getInstance();
    _initialized = true;
  }

  bool get isInitialized => _initialized;

  // ── Settings getters ──────────────────────────────────────────────────

  /// Whether notifications should be filtered by the user's interest
  /// categories.  Defaults to true (opt-in personalisation).
  bool get interestFilterEnabled =>
      _prefs.getBool(_keyInterestFilter) ?? true;

  /// Whether breaking news should bypass the interest filter and always
  /// show (subject to quiet-hours and frequency caps).
  bool get breakingAlertsEnabled =>
      _prefs.getBool(_keyBreakingAlerts) ?? true;

  /// Start hour of the quiet window (inclusive, 0–23).
  int get quietStartHour => _prefs.getInt(_keyQuietStart) ?? _defaultQuietStart;

  /// End hour of the quiet window (exclusive, 0–23).
  int get quietEndHour => _prefs.getInt(_keyQuietEnd) ?? _defaultQuietEnd;

  /// Maximum number of notifications to show in any rolling 1-hour window.
  int get hourlyLimit => _prefs.getInt(_keyHourlyLimit) ?? _defaultHourlyLimit;

  /// Maximum number of notifications to show in any rolling 24-hour window.
  int get dailyLimit => _prefs.getInt(_keyDailyLimit) ?? _defaultDailyLimit;

  /// Category slugs the user selected during onboarding / settings.
  List<String> get interestCategories =>
      _prefs.getStringList(_keyInterestCategories) ?? [];

  // ── Settings setters ──────────────────────────────────────────────────

  Future<void> setInterestFilter(bool v) =>
      _prefs.setBool(_keyInterestFilter, v);

  Future<void> setBreakingAlerts(bool v) =>
      _prefs.setBool(_keyBreakingAlerts, v);

  Future<void> setQuietHours(int start, int end) async {
    await _prefs.setInt(_keyQuietStart, start);
    await _prefs.setInt(_keyQuietEnd, end);
  }

  Future<void> setHourlyLimit(int v) => _prefs.setInt(_keyHourlyLimit, v);

  Future<void> setDailyLimit(int v) => _prefs.setInt(_keyDailyLimit, v);

  Future<void> setInterestCategories(List<String> slugs) =>
      _prefs.setStringList(_keyInterestCategories, slugs);

  // ── Core decision ─────────────────────────────────────────────────────

  /// Returns `true` if the notification represented by [message] should be
  /// shown to the user.  Fails-open (returns true) when the service has not
  /// been initialised.
  bool shouldShow(RemoteMessage message) {
    if (!_initialized) return true;

    final data       = message.data;
    final isBreaking = data['breaking'] == '1' ||
        data['breaking'] == 'true' ||
        (data['is_breaking'] == '1');
    final category = data['category'] as String?;

    // 1. Quiet hours
    if (_isQuietHour()) return false;

    // 2. Frequency cap
    if (!_withinFrequencyLimits()) return false;

    // 3. Breaking-alert override:
    //    If breaking alerts are enabled, let breaking news skip the interest
    //    filter and show regardless of category.
    if (isBreaking && breakingAlertsEnabled) return true;

    // 4. Breaking alerts disabled → suppress breaking notifications entirely
    if (isBreaking && !breakingAlertsEnabled) return false;

    // 5. Interest filter for non-breaking notifications
    if (interestFilterEnabled) {
      final interests = interestCategories;
      if (interests.isNotEmpty &&
          category != null &&
          category.isNotEmpty &&
          !interests.contains(category)) {
        return false;
      }
    }

    return true;
  }

  // ── Record events ─────────────────────────────────────────────────────

  /// Must be called every time a notification is actually shown so that
  /// frequency-cap counters stay accurate.
  Future<void> recordNotificationShown() async {
    if (!_initialized) return;
    final log = _getNotifLog();
    final now = DateTime.now().millisecondsSinceEpoch;
    log.add(now);
    // Prune entries older than 24 h to keep the list short
    final cutoff = now - const Duration(hours: 24).inMilliseconds;
    log.removeWhere((t) => t < cutoff);
    await _prefs.setStringList(
      _keyNotifLog,
      log.map((t) => t.toString()).toList(),
    );
  }

  /// Call whenever the user actively opens an article so we learn which
  /// hours of the day the user is most engaged.
  Future<void> recordEngagement() async {
    if (!_initialized) return;
    final hour = DateTime.now().hour;
    final map  = _getEngagementMap();
    map[hour] = (map[hour] ?? 0) + 1;
    await _prefs.setString(_keyEngagement, jsonEncode(
      map.map((k, v) => MapEntry(k.toString(), v)),
    ));
  }

  // ── Derived analytics ─────────────────────────────────────────────────

  /// Returns the top-3 hours (0–23) by engagement count, sorted ascending.
  /// Returns an empty list until at least one engagement event has been
  /// recorded.
  List<int> get bestEngagementHours {
    final map = _getEngagementMap();
    if (map.isEmpty) return [];
    final sorted = map.entries.toList()
      ..sort((a, b) => b.value.compareTo(a.value));
    return (sorted.take(3).map((e) => e.key).toList()..sort());
  }

  /// Returns the number of notifications shown in the last 24 hours.
  int get notificationsLast24h {
    if (!_initialized) return 0;
    final log     = _getNotifLog();
    final cutoff  = DateTime.now().millisecondsSinceEpoch -
        const Duration(hours: 24).inMilliseconds;
    return log.where((t) => t >= cutoff).length;
  }

  // ── Private helpers ───────────────────────────────────────────────────

  bool _isQuietHour() {
    final hour  = DateTime.now().hour;
    final start = quietStartHour;
    final end   = quietEndHour;
    if (start < end) {
      // Straight window e.g. 09:00–17:00
      return hour >= start && hour < end;
    } else if (start > end) {
      // Wraps midnight e.g. 22:00–07:00
      return hour >= start || hour < end;
    }
    return false; // start == end → no quiet window
  }

  bool _withinFrequencyLimits() {
    final log     = _getNotifLog();
    final now     = DateTime.now().millisecondsSinceEpoch;
    final hourAgo = now - const Duration(hours: 1).inMilliseconds;
    final dayAgo  = now - const Duration(hours: 24).inMilliseconds;
    final lastHour = log.where((t) => t >= hourAgo).length;
    final lastDay  = log.where((t) => t >= dayAgo).length;
    return lastHour < hourlyLimit && lastDay < dailyLimit;
  }

  List<int> _getNotifLog() {
    return (_prefs.getStringList(_keyNotifLog) ?? [])
        .map((s) => int.tryParse(s) ?? 0)
        .toList();
  }

  Map<int, int> _getEngagementMap() {
    final raw = _prefs.getString(_keyEngagement) ?? '{}';
    try {
      final decoded = jsonDecode(raw) as Map;
      return decoded.map(
        (k, v) => MapEntry(int.tryParse(k.toString()) ?? 0, (v as num).toInt()),
      );
    } catch (_) {
      return {};
    }
  }
}
