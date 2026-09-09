import 'dart:convert';
import 'package:hive_flutter/hive_flutter.dart';

/// Hive-backed offline cache for news feed and article detail.
///
/// Uses plain dynamic boxes (no code generation required).
/// Call [init] once in main before [runApp].
class CacheService {
  CacheService._();

  static final CacheService instance = CacheService._();

  static const String _feedBox    = 'cached_feed';
  static const String _articleBox = 'cached_articles';
  static const String _feedKey    = 'feed_page_1';

  Box? _feed;
  Box? _articles;

  // ── Init ──────────────────────────────────────────────────────────────

  Future<void> init() async {
    try {
      await Hive.initFlutter();
      _feed     = await Hive.openBox(_feedBox);
      _articles = await Hive.openBox(_articleBox);
    } catch (_) {
      // Hive init failure — cache disabled, app works online only.
    }
  }

  bool get isAvailable => _feed != null && _articles != null;

  // ── News Feed ─────────────────────────────────────────────────────────

  Future<void> saveFeed(List<Map<String, dynamic>> articles) async {
    if (_feed == null) return;
    try {
      await _feed!.put(_feedKey, jsonEncode(articles));
    } catch (_) {}
  }

  List<Map<String, dynamic>> getCachedFeed() {
    if (_feed == null) return [];
    try {
      final raw = _feed!.get(_feedKey) as String?;
      if (raw == null) return [];
      final list = jsonDecode(raw) as List;
      return list.cast<Map<String, dynamic>>();
    } catch (_) {
      return [];
    }
  }

  // ── Article Detail ────────────────────────────────────────────────────

  Future<void> saveArticle(String slug, Map<String, dynamic> article) async {
    if (_articles == null) return;
    try {
      await _articles!.put(slug, jsonEncode(article));
    } catch (_) {}
  }

  Map<String, dynamic>? getCachedArticle(String slug) {
    if (_articles == null) return null;
    try {
      final raw = _articles!.get(slug) as String?;
      if (raw == null) return null;
      return jsonDecode(raw) as Map<String, dynamic>;
    } catch (_) {
      return null;
    }
  }

  // ── Clear ─────────────────────────────────────────────────────────────

  Future<void> clearAll() async {
    await _feed?.clear();
    await _articles?.clear();
  }
}
