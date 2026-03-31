import 'dart:convert';
import 'package:hive_flutter/hive_flutter.dart';
import '../models/news_article.dart';

/// Metadata attached to each offline-saved article.
class OfflineEntry {
  final NewsArticle article;
  final DateTime    savedAt;
  final DateTime    syncedAt;

  const OfflineEntry({
    required this.article,
    required this.savedAt,
    required this.syncedAt,
  });

  Map<String, dynamic> toMap() => {
    'article':   article.toJson(),
    'saved_at':  savedAt.toIso8601String(),
    'synced_at': syncedAt.toIso8601String(),
  };

  factory OfflineEntry.fromMap(Map<String, dynamic> map) {
    return OfflineEntry(
      article:  NewsArticle.fromJson(map['article'] as Map<String, dynamic>),
      savedAt:  DateTime.parse(map['saved_at'] as String),
      syncedAt: DateTime.parse(map['synced_at'] as String),
    );
  }
}

/// Hive-backed store for articles saved for offline reading.
///
/// Box name:  `offline_articles`
/// Key:       article slug (String)
/// Value:     JSON-encoded [OfflineEntry]
///
/// [init] must be called once after [CacheService.init] (Hive is already
/// initialised by then; this simply opens the box).
class OfflineService {
  OfflineService._();
  static final OfflineService instance = OfflineService._();

  static const String _boxName = 'offline_articles';
  Box? _box;

  // ── Lifecycle ─────────────────────────────────────────────────────────

  Future<void> init() async {
    try {
      // Hive.initFlutter() is idempotent; CacheService.init already called it.
      _box = await Hive.openBox(_boxName);
    } catch (_) {
      // Offline storage disabled — app degrades gracefully.
    }
  }

  bool get isAvailable => _box != null;

  // ── Save / remove ─────────────────────────────────────────────────────

  Future<void> saveArticle(NewsArticle article) async {
    if (_box == null) return;
    final now   = DateTime.now();
    final entry = OfflineEntry(article: article, savedAt: now, syncedAt: now);
    try {
      await _box!.put(article.slug, jsonEncode(entry.toMap()));
    } catch (_) {}
  }

  Future<void> removeArticle(String slug) async {
    if (_box == null) return;
    try {
      await _box!.delete(slug);
    } catch (_) {}
  }

  // ── Query ─────────────────────────────────────────────────────────────

  bool isOffline(String slug) {
    if (_box == null) return false;
    return _box!.containsKey(slug);
  }

  List<OfflineEntry> getAllEntries() {
    if (_box == null) return [];
    final entries = <OfflineEntry>[];
    for (final key in _box!.keys) {
      try {
        final raw = _box!.get(key) as String?;
        if (raw == null) continue;
        entries.add(OfflineEntry.fromMap(jsonDecode(raw) as Map<String, dynamic>));
      } catch (_) {}
    }
    // Most-recently saved first
    entries.sort((a, b) => b.savedAt.compareTo(a.savedAt));
    return entries;
  }

  int get count => _box?.length ?? 0;

  // ── Sync ──────────────────────────────────────────────────────────────

  /// Re-fetches every saved article from the API and updates the local copy.
  /// Returns the number of articles successfully refreshed.
  /// Silently skips articles the API cannot reach (keeps old copy).
  Future<int> syncAll(Future<NewsArticle?> Function(String slug) fetchArticle) async {
    if (_box == null) return 0;
    final slugs = List<String>.from(_box!.keys.whereType<String>());
    int refreshed = 0;
    for (final slug in slugs) {
      try {
        final fresh = await fetchArticle(slug);
        if (fresh == null) continue;
        final raw     = _box!.get(slug) as String?;
        final savedAt = raw != null
            ? OfflineEntry.fromMap(jsonDecode(raw) as Map<String, dynamic>).savedAt
            : DateTime.now();
        final updated = OfflineEntry(
          article:  fresh,
          savedAt:  savedAt,
          syncedAt: DateTime.now(),
        );
        await _box!.put(slug, jsonEncode(updated.toMap()));
        refreshed++;
      } catch (_) {
        // Keep stale copy on network error
      }
    }
    return refreshed;
  }

  // ── Clear ─────────────────────────────────────────────────────────────

  Future<void> clearAll() async {
    await _box?.clear();
  }
}
