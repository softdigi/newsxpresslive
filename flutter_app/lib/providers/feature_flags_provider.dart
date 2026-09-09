import 'package:flutter/foundation.dart';
import '../data/services/feature_flags_service.dart';

/// Exposes [FeatureFlagsService] as a [ChangeNotifier] provider so widgets
/// can rebuild when flags are refreshed.
class FeatureFlagsProvider extends ChangeNotifier {
  final FeatureFlagsService _service = FeatureFlagsService.instance;

  bool isEnabled(String flag) => _service.isEnabled(flag);

  /// Re-fetch flags for a user and notify listeners.
  Future<void> refresh(int userId) async {
    await _service.fetch(userId);
    notifyListeners();
  }

  /// Load flags from local cache (offline startup).
  Future<void> loadFromCache() async {
    await _service.loadFromCache();
    notifyListeners();
  }
}
