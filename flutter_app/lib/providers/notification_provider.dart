import 'package:flutter/foundation.dart';
import '../data/services/smart_notification_service.dart';

/// Exposes smart-notification settings to the UI layer and provides mutation
/// methods that persist changes through [SmartNotificationService].
class NotificationProvider extends ChangeNotifier {
  NotificationProvider() {
    _load();
  }

  final _svc = SmartNotificationService.instance;

  bool _interestFilter = true;
  bool _breakingAlerts = true;
  int  _quietStart     = 22;
  int  _quietEnd       = 7;

  // ── Getters ───────────────────────────────────────────────────────────

  bool get interestFilter => _interestFilter;
  bool get breakingAlerts => _breakingAlerts;
  int  get quietStart     => _quietStart;
  int  get quietEnd       => _quietEnd;

  /// Top-3 most-active hours (0–23) derived from article-open events.
  List<int> get bestHours => _svc.bestEngagementHours;

  /// Number of notifications shown in the last 24 h.
  int get notificationsLast24h => _svc.notificationsLast24h;

  /// Category slugs the user subscribed to.
  List<String> get interestCategories => _svc.interestCategories;

  // ── Load from persisted state ─────────────────────────────────────────

  void _load() {
    if (!_svc.isInitialized) return;
    _interestFilter = _svc.interestFilterEnabled;
    _breakingAlerts = _svc.breakingAlertsEnabled;
    _quietStart     = _svc.quietStartHour;
    _quietEnd       = _svc.quietEndHour;
  }

  /// Re-read settings from [SmartNotificationService] after it has been
  /// initialised (call from main.dart after [SmartNotificationService.init]).
  void reload() {
    _load();
    notifyListeners();
  }

  // ── Mutation helpers ──────────────────────────────────────────────────

  Future<void> setInterestFilter(bool v) async {
    if (_interestFilter == v) return;
    _interestFilter = v;
    await _svc.setInterestFilter(v);
    notifyListeners();
  }

  Future<void> setBreakingAlerts(bool v) async {
    if (_breakingAlerts == v) return;
    _breakingAlerts = v;
    await _svc.setBreakingAlerts(v);
    notifyListeners();
  }

  /// Update the quiet-hours window.
  ///
  /// Pass equal [start] and [end] to disable quiet hours entirely.
  Future<void> setQuietHours(int start, int end) async {
    if (_quietStart == start && _quietEnd == end) return;
    _quietStart = start;
    _quietEnd   = end;
    await _svc.setQuietHours(start, end);
    notifyListeners();
  }
}
