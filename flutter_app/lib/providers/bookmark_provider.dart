import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'dart:convert';
import '../data/models/news_article.dart';

/// Manages locally bookmarked articles, persisted via SharedPreferences.
class BookmarkProvider extends ChangeNotifier {
  static const String _key = 'bookmarks';

  BookmarkProvider(SharedPreferences prefs) : _prefs = prefs {
    _load();
  }

  final SharedPreferences       _prefs;
  final List<NewsArticle>       _bookmarks = [];

  List<NewsArticle> get bookmarks => List.unmodifiable(_bookmarks);
  int               get count     => _bookmarks.length;

  bool isBookmarked(int newsId) => _bookmarks.any((a) => a.id == newsId);

  // ── Toggle ────────────────────────────────────────────────────────────

  Future<void> toggle(NewsArticle article) async {
    if (isBookmarked(article.id)) {
      _bookmarks.removeWhere((a) => a.id == article.id);
    } else {
      _bookmarks.insert(0, article);
    }
    notifyListeners();
    await _save();
  }

  Future<void> remove(int newsId) async {
    _bookmarks.removeWhere((a) => a.id == newsId);
    notifyListeners();
    await _save();
  }

  Future<void> clearAll() async {
    _bookmarks.clear();
    notifyListeners();
    await _save();
  }

  // ── Persistence ───────────────────────────────────────────────────────

  void _load() {
    final raw = _prefs.getString(_key);
    if (raw == null || raw.isEmpty) return;
    try {
      final list = jsonDecode(raw) as List;
      _bookmarks.addAll(
        list.map((e) => NewsArticle.fromJson(e as Map<String, dynamic>)),
      );
    } catch (_) {}
  }

  Future<void> _save() async {
    final encoded = jsonEncode(_bookmarks.map((a) => a.toJson()).toList());
    await _prefs.setString(_key, encoded);
  }
}
