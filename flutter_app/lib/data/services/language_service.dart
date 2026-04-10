import 'dart:convert';
import 'package:shared_preferences/shared_preferences.dart';
import '../models/location_model.dart';
import 'api_service.dart';
import '../../core/constants/api_endpoints.dart';

/// Manages reading-language preferences:
///  - Fetch supported languages from the backend.
///  - Persist selected codes locally (SharedPreferences).
///  - Push selected codes to the backend (requires auth token).
///  - Expose selected codes for use in feed queries.
class LanguageService {
  LanguageService({required ApiService api}) : _api = api;

  final ApiService _api;

  static const String _prefsKey    = 'selected_languages';
  static const List<String> _defaultCodes = ['hi', 'en'];

  // ── Fetch supported languages ─────────────────────────────────────────────

  Future<List<LanguageModel>> getSupportedLanguages() async {
    final response = await _api.get(ApiEndpoints.newsLanguages);
    final data     = json.decode(response.body) as Map<String, dynamic>;

    if (data['success'] == true) {
      final list = data['languages'] as List<dynamic>;
      return list
          .map((e) => LanguageModel.fromJson(e as Map<String, dynamic>))
          .toList();
    }
    throw Exception(data['message'] ?? 'Could not load languages');
  }

  // ── Local persistence ─────────────────────────────────────────────────────

  Future<List<String>> getSelectedCodes() async {
    final prefs = await SharedPreferences.getInstance();
    final stored = prefs.getStringList(_prefsKey);
    return (stored != null && stored.isNotEmpty) ? stored : List.of(_defaultCodes);
  }

  Future<void> saveSelectedCodesLocally(List<String> codes) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setStringList(_prefsKey, codes);
  }

  // ── Backend sync ──────────────────────────────────────────────────────────

  /// Save [codes] on the backend.  Requires an authenticated [ApiService]
  /// (i.e. one with [idTokenProvider] set).
  Future<void> saveSelectedCodesToBackend(List<String> codes) async {
    final response = await _api.post(
      ApiEndpoints.newsLanguages,
      body: json.encode({'languages': codes}),
    );
    final data = json.decode(response.body) as Map<String, dynamic>;
    if (data['success'] != true) {
      throw Exception(data['message'] ?? 'Could not save languages');
    }
  }

  /// Convenience: save locally **and** sync to backend.
  Future<void> saveSelectedCodes(List<String> codes) async {
    await saveSelectedCodesLocally(codes);
    try {
      await saveSelectedCodesToBackend(codes);
    } catch (_) {
      // Backend sync failure is non-fatal; local prefs are the source of truth.
    }
  }

  void dispose() => _api.dispose();
}
