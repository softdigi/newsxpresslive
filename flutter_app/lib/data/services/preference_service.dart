import '../models/mood_category.dart';
import '../models/popup_decision.dart';
import 'api_service.dart';
import '../../core/constants/api_endpoints.dart';

/// High-level service for the v31 Preference System APIs.
///
/// All methods require the [ApiService] to have a valid Firebase ID token
/// attached (set [ApiService.idTokenProvider]).
class PreferenceService {
  PreferenceService({required ApiService api}) : _api = api;

  final ApiService _api;

  // ── Check popup ────────────────────────────────────────────────────────

  /// Ask the server whether a preference popup should be shown.
  ///
  /// [durationMinutes] — how long the current session has been active.
  Future<PopupDecision> checkPopup({int durationMinutes = 999}) async {
    try {
      final data = await _api.get(
        ApiEndpoints.preferencesCheck,
        queryParams: {'duration_minutes': durationMinutes.toString()},
      );
      if (data is! Map<String, dynamic>) {
        return const PopupDecision(showPopup: false, reason: 'parse_error');
      }
      return PopupDecision.fromJson(data);
    } catch (e) {
      return const PopupDecision(showPopup: false, reason: 'network_error');
    }
  }

  // ── Save mood ──────────────────────────────────────────────────────────

  /// Save the user's mood/category selection for today.
  ///
  /// Returns `true` on success.
  Future<bool> saveMood({
    required List<String> categories,
    String context                = 'manual',
    bool   wasPopup               = false,
    int    timeToSelectSeconds    = 0,
  }) async {
    try {
      final data = await _api.postJson(
        ApiEndpoints.preferencesSaveMood,
        body: {
          'categories':             categories,
          'context':                context,
          'was_popup':              wasPopup,
          'time_to_select_seconds': timeToSelectSeconds,
        },
      );
      return data is Map && data['success'] == true;
    } catch (_) {
      return false;
    }
  }

  // ── Skip popup ─────────────────────────────────────────────────────────

  /// Record that the user dismissed (skipped) a preference popup.
  ///
  /// Returns a record with the new [streak] and nullable [snoozedUntil].
  Future<({int streak, String? snoozedUntil})> skipPopup({
    required String context,
    required String popupType,
  }) async {
    try {
      final data = await _api.postJson(
        ApiEndpoints.preferencesSkipPopup,
        body: {'context': context, 'popup_type': popupType},
      );
      if (data is Map) {
        return (
          streak:      (data['streak'] as num?)?.toInt() ?? 0,
          snoozedUntil: data['snoozed_until'] as String?,
        );
      }
    } catch (_) {}
    return (streak: 0, snoozedUntil: null);
  }

  // ── Onboarding steps ───────────────────────────────────────────────────

  /// Submit one step of the multi-step onboarding flow.
  ///
  /// Returns `{step_completed, next_step, onboarding_complete}` or null on error.
  Future<Map<String, dynamic>?> saveOnboardingStep({
    required String step,
    Map<String, dynamic> data = const {},
  }) async {
    try {
      final body = <String, dynamic>{'step': step, ...data};
      final response = await _api.postJson(
        ApiEndpoints.preferencesOnboarding,
        body: body,
      );
      if (response is Map<String, dynamic> && response['success'] == true) {
        return response;
      }
    } catch (_) {}
    return null;
  }

  // ── Categories list ────────────────────────────────────────────────────

  /// Fetch mood-eligible categories with emoji + colorHex metadata.
  ///
  /// Uses the main categories endpoint (v31+ returns emoji, color_hex).
  /// Returns only categories where [isMoodCategory] is true when [moodOnly]
  /// is set (default: true).
  Future<List<MoodCategory>> getMoodCategories({bool moodOnly = true}) async {
    try {
      final data = await _api.get(ApiEndpoints.categories);
      if (data is! List) return [];
      final cats = data
          .map((e) => MoodCategory.fromCategoryJson(e as Map<String, dynamic>))
          .toList();
      if (moodOnly) {
        return cats.where((c) => c.isMoodCategory).toList();
      }
      return cats;
    } catch (_) {
      return [];
    }
  }

  // ── Get preferences ────────────────────────────────────────────────────

  /// Fetch the authenticated user's full preference profile.
  Future<Map<String, dynamic>?> getPreferences() async {
    try {
      final data = await _api.get(ApiEndpoints.preferencesGet);
      if (data is Map<String, dynamic>) return data;
    } catch (_) {}
    return null;
  }
}
