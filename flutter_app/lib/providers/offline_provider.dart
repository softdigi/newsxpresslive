import 'package:flutter/foundation.dart';
import '../data/models/news_article.dart';
import '../data/services/offline_service.dart';
import '../data/services/news_service.dart';
import '../data/services/api_service.dart';

export '../data/services/offline_service.dart' show OfflineEntry;

/// Manages articles saved for offline reading.
///
/// Backed by [OfflineService] (Hive). Exposes:
///   - [entries]    — list of [OfflineEntry] sorted newest-first
///   - [isOffline]  — whether a given slug is saved
///   - [save]       — persist an article
///   - [remove]     — un-save an article
///   - [sync]       — refresh all saved articles from the API
///   - [isSyncing]  — true while sync is in progress
///   - [lastSyncAt] — when the most recent sync completed (nullable)
class OfflineProvider extends ChangeNotifier {
  OfflineProvider() {
    _load();
  }

  final OfflineService _svc = OfflineService.instance;

  List<OfflineEntry> _entries    = [];
  bool               _isSyncing  = false;
  DateTime?          _lastSyncAt;

  List<OfflineEntry> get entries    => List.unmodifiable(_entries);
  bool               get isSyncing  => _isSyncing;
  DateTime?          get lastSyncAt => _lastSyncAt;
  int                get count      => _entries.length;

  bool isOffline(String slug) => _svc.isOffline(slug);

  // ── Load from Hive ────────────────────────────────────────────────────

  void _load() {
    _entries = _svc.getAllEntries();
  }

  // ── Save ──────────────────────────────────────────────────────────────

  Future<void> save(NewsArticle article) async {
    await _svc.saveArticle(article);
    _entries = _svc.getAllEntries();
    notifyListeners();
  }

  // ── Remove ────────────────────────────────────────────────────────────

  Future<void> remove(String slug) async {
    await _svc.removeArticle(slug);
    _entries = _svc.getAllEntries();
    notifyListeners();
  }

  // ── Toggle ────────────────────────────────────────────────────────────

  Future<void> toggle(NewsArticle article) async {
    if (isOffline(article.slug)) {
      await remove(article.slug);
    } else {
      await save(article);
    }
  }

  // ── Sync ──────────────────────────────────────────────────────────────

  /// Refreshes all saved articles from the network.
  /// No-op if already syncing.
  Future<void> sync() async {
    if (_isSyncing) return;
    _isSyncing = true;
    notifyListeners();

    try {
      final news = NewsService(api: ApiService());
      await _svc.syncAll((slug) => news.getArticleDetail(slug));
      news.dispose();
      _lastSyncAt = DateTime.now();
    } catch (_) {
      // Sync failure is non-fatal
    } finally {
      _entries   = _svc.getAllEntries();
      _isSyncing = false;
      notifyListeners();
    }
  }

  // ── Clear all ─────────────────────────────────────────────────────────

  Future<void> clearAll() async {
    await _svc.clearAll();
    _entries = [];
    notifyListeners();
  }
}
