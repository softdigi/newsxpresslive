import 'dart:convert';
import 'package:shared_preferences/shared_preferences.dart';
import 'api_service.dart';
import '../../core/constants/api_endpoints.dart';

/// Fetches and caches feature flags from the backend.
///
/// Flags are stored locally so they are available even when offline.
/// Call [fetch] once after login (pass [userId]).
class FeatureFlagsService {
  FeatureFlagsService._();

  static final FeatureFlagsService instance = FeatureFlagsService._();

  static const String _cacheKey = 'feature_flags_cache';

  final _flags = <String>{};
  bool _loaded = false;

  bool get isLoaded => _loaded;

  // ── Fetch from backend ────────────────────────────────────────────────

  Future<void> fetch(int userId) async {
    try {
      final api  = ApiService();
      final data = await api.get(
        ApiEndpoints.featureFlags,
        queryParams: {'user_id': userId.toString(), 'platform': 'app'},
      );
      api.dispose();

      if (data is Map && data['flags'] is List) {
        _flags
          ..clear()
          ..addAll((data['flags'] as List).cast<String>());
        _loaded = true;
        await _persist();
      }
    } catch (_) {
      // Network failure — load from cache
      await _loadFromCache();
    }
  }

  /// Loads flags cached from the last successful fetch (for offline use).
  Future<void> loadFromCache() async => _loadFromCache();

  Future<void> _loadFromCache() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final raw   = prefs.getString(_cacheKey);
      if (raw == null) return;
      final list = jsonDecode(raw) as List;
      _flags
        ..clear()
        ..addAll(list.cast<String>());
      _loaded = true;
    } catch (_) {}
  }

  Future<void> _persist() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_cacheKey, jsonEncode(_flags.toList()));
  }

  // ── Query ─────────────────────────────────────────────────────────────

  bool isEnabled(String flagKey) => _flags.contains(flagKey);
}

/// Well-known flag keys used in the app.
class FeatureFlag {
  static const String reporterMode       = 'reporter_mode';
  static const String adsEnabled         = 'ads_enabled';
  static const String analyticsEnabled   = 'analytics_enabled';
  static const String offlineCacheEnabled = 'offline_cache_enabled';
  static const String deepLinkingEnabled = 'deep_linking_enabled';
}
