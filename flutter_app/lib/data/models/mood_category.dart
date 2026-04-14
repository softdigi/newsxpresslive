import 'package:flutter/material.dart';

/// A news category enriched with popup / onboarding metadata.
///
/// Used by both [DailyMoodPopup] (all_categories / suggested_categories from
/// `check.php`) and the onboarding categories step (categories endpoint with
/// emoji + color_hex fields added in v31 migration).
class MoodCategory {
  const MoodCategory({
    required this.id,
    required this.slug,
    required this.name,
    this.emoji = '',
    this.colorHex = '#4A90D9',
    this.isCurrentlySelected = false,
    this.behaviorScore = 0.5,
    this.newArticlesCount = 0,
    this.isMoodCategory = true,
  });

  final int    id;
  final String slug;
  final String name;
  final String emoji;
  final String colorHex;
  final bool   isCurrentlySelected;
  final double behaviorScore;
  final int    newArticlesCount;
  final bool   isMoodCategory;

  // ── Factories ──────────────────────────────────────────────────────────

  /// Parse from the check.php `all_categories` / `suggested_categories` array.
  factory MoodCategory.fromCheckJson(Map<String, dynamic> json) {
    return MoodCategory(
      id:                   _parseInt(json['id']),
      slug:                 json['slug']  as String? ?? '',
      name:                 json['name']  as String? ?? '',
      emoji:                json['emoji'] as String? ?? '',
      colorHex:             json['color'] as String? ??
                            json['color_hex'] as String? ?? '#4A90D9',
      isCurrentlySelected:  json['is_currently_selected'] == true ||
                            json['is_currently_selected'] == 1,
      behaviorScore:        _parseDouble(json['behavior_score'] ?? 0.5),
      newArticlesCount:     _parseInt(json['new_articles_count'] ?? 0),
      isMoodCategory:       true,
    );
  }

  /// Parse from the `GET /api/categories.php` response (v31+ includes
  /// emoji, color_hex, is_mood_category).
  factory MoodCategory.fromCategoryJson(Map<String, dynamic> json) {
    return MoodCategory(
      id:               _parseInt(json['id']),
      slug:             json['slug']      as String? ?? '',
      name:             json['name']      as String? ?? '',
      emoji:            json['emoji']     as String? ?? '',
      colorHex:         json['color_hex'] as String? ?? '#4A90D9',
      isMoodCategory:   json['is_mood_category'] == true ||
                        json['is_mood_category'] == '1' ||
                        json['is_mood_category'] == 1,
    );
  }

  // ── Helpers ────────────────────────────────────────────────────────────

  /// Background color from [colorHex] at 15 % opacity — used on cards.
  Color get backgroundTint {
    final c = _parseColor(colorHex);
    return c.withOpacity(0.15);
  }

  /// Full opaque color from [colorHex].
  Color get color => _parseColor(colorHex);

  MoodCategory copyWith({bool? isCurrentlySelected}) => MoodCategory(
        id:                   id,
        slug:                 slug,
        name:                 name,
        emoji:                emoji,
        colorHex:             colorHex,
        isCurrentlySelected:  isCurrentlySelected ?? this.isCurrentlySelected,
        behaviorScore:        behaviorScore,
        newArticlesCount:     newArticlesCount,
        isMoodCategory:       isMoodCategory,
      );

  @override
  bool operator ==(Object other) => other is MoodCategory && other.slug == slug;

  @override
  int get hashCode => slug.hashCode;

  // ── Private helpers ────────────────────────────────────────────────────

  static Color _parseColor(String hex) {
    try {
      final cleaned = hex.replaceAll('#', '').trim();
      if (cleaned.length == 6) {
        return Color(int.parse('0xFF$cleaned'));
      }
    } catch (_) {}
    return const Color(0xFF4A90D9);
  }

  static int _parseInt(dynamic v) {
    if (v is int)    return v;
    if (v is double) return v.toInt();
    if (v is String) return int.tryParse(v) ?? 0;
    return 0;
  }

  static double _parseDouble(dynamic v) {
    if (v is double) return v;
    if (v is int)    return v.toDouble();
    if (v is String) return double.tryParse(v) ?? 0.0;
    return 0.0;
  }
}
