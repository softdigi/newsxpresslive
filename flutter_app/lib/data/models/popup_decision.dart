import 'mood_category.dart';

/// Decoded response from `GET /api/preferences/check.php`.
///
/// When [showPopup] is `true`, the popup UI should be presented and
/// [allCategories] / [suggestedCategories] are populated.
///
/// When [showPopup] is `false`, only [reason] and [score] are meaningful.
class PopupDecision {
  const PopupDecision({
    required this.showPopup,
    this.popupType            = '',
    this.context              = '',
    this.contextMessage       = '',
    this.currentPreferences   = const [],
    this.suggestedCategories  = const [],
    this.allCategories        = const [],
    this.skipAllowed          = true,
    this.score                = 0,
    this.triggerReasons       = const [],
    this.reason,
  });

  /// Whether the popup should be shown to the user.
  final bool showPopup;

  /// `'onboarding'` | `'daily_mood'` | etc.
  final String popupType;

  /// Time-of-day / event context: `'morning'`, `'evening'`, `'breaking_news'`, …
  final String context;

  /// Human-readable contextual greeting (Hindi UI copy from backend).
  final String contextMessage;

  /// Slugs of categories the user currently has selected.
  final List<String> currentPreferences;

  /// Top-3 suggested categories (backend-scored by behavior + new content).
  final List<MoodCategory> suggestedCategories;

  /// All mood-eligible categories (enriched with scores + article counts).
  final List<MoodCategory> allCategories;

  /// Whether the user is allowed to skip this popup.
  final bool skipAllowed;

  /// Composite score that triggered the popup.
  final int score;

  /// Human-readable reasons the popup was triggered.
  final List<String> triggerReasons;

  /// Reason the popup was NOT shown (only set when [showPopup] is false).
  final String? reason;

  // ── Factory ─────────────────────────────────────────────────────────────

  factory PopupDecision.fromJson(Map<String, dynamic> json) {
    final show = json['show_popup'] == true;

    if (!show) {
      return PopupDecision(
        showPopup: false,
        score:     _parseInt(json['score'] ?? 0),
        reason:    json['reason'] as String?,
      );
    }

    final suggestedRaw = json['suggested_categories'];
    final allRaw       = json['all_categories'];

    return PopupDecision(
      showPopup:  true,
      popupType:  json['popup_type']      as String? ?? '',
      context:    json['context']         as String? ?? '',
      contextMessage: json['context_message'] as String? ?? '',
      currentPreferences: _parseStringList(json['current_preferences']),
      suggestedCategories: suggestedRaw is List
          ? suggestedRaw
              .map((e) => MoodCategory.fromCheckJson(e as Map<String, dynamic>))
              .toList()
          : [],
      allCategories: allRaw is List
          ? allRaw
              .map((e) => MoodCategory.fromCheckJson(e as Map<String, dynamic>))
              .toList()
          : [],
      skipAllowed:    json['skip_allowed'] != false,
      score:          _parseInt(json['score'] ?? 0),
      triggerReasons: _parseStringList(json['trigger_reasons']),
    );
  }

  // ── Private helpers ────────────────────────────────────────────────────

  static int _parseInt(dynamic v) {
    if (v is int)    return v;
    if (v is double) return v.toInt();
    if (v is String) return int.tryParse(v) ?? 0;
    return 0;
  }

  static List<String> _parseStringList(dynamic v) {
    if (v is List) return v.map((e) => e.toString()).toList();
    return [];
  }
}
