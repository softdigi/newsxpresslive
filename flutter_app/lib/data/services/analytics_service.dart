import 'package:firebase_analytics/firebase_analytics.dart';

/// Thin wrapper around Firebase Analytics.
///
/// All calls are fire-and-forget; failures are silently swallowed so a
/// missing firebase config never crashes the app.
class AnalyticsService {
  AnalyticsService._();

  static final AnalyticsService instance = AnalyticsService._();

  FirebaseAnalytics? _analytics;

  void init() {
    try {
      _analytics = FirebaseAnalytics.instance;
    } catch (_) {
      // Firebase not configured — analytics disabled.
    }
  }

  // ── Events ────────────────────────────────────────────────────────────

  Future<void> logArticleOpen(int articleId, String slug, String? category) async {
    try {
      await _analytics?.logEvent(
        name: 'article_open',
        parameters: {
          'article_id': articleId,
          'slug':       slug,
          'category':   category ?? 'unknown',
        },
      );
    } catch (_) {}
  }

  Future<void> logArticleScroll(int articleId, double percentScrolled) async {
    try {
      await _analytics?.logEvent(
        name: 'article_scroll',
        parameters: {
          'article_id':       articleId,
          'percent_scrolled': percentScrolled.round(),
        },
      );
    } catch (_) {}
  }

  Future<void> logBookmarkAdd(int articleId, String slug) async {
    try {
      await _analytics?.logEvent(
        name: 'bookmark_add',
        parameters: {'article_id': articleId, 'slug': slug},
      );
    } catch (_) {}
  }

  Future<void> logBookmarkRemove(int articleId, String slug) async {
    try {
      await _analytics?.logEvent(
        name: 'bookmark_remove',
        parameters: {'article_id': articleId, 'slug': slug},
      );
    } catch (_) {}
  }

  Future<void> logShareClick(int articleId, String slug) async {
    try {
      await _analytics?.logEvent(
        name: 'share_click',
        parameters: {'article_id': articleId, 'slug': slug},
      );
    } catch (_) {}
  }

  Future<void> logSearchQuery(String query) async {
    try {
      await _analytics?.logSearch(searchTerm: query);
    } catch (_) {}
  }

  Future<void> logCommentPost(int articleId) async {
    try {
      await _analytics?.logEvent(
        name: 'comment_post',
        parameters: {'article_id': articleId},
      );
    } catch (_) {}
  }

  Future<void> logNewsSubmit() async {
    try {
      await _analytics?.logEvent(name: 'reporter_news_submit');
    } catch (_) {}
  }

  Future<void> logLogin(String method) async {
    try {
      await _analytics?.logLogin(loginMethod: method);
    } catch (_) {}
  }

  Future<void> setUserId(String? uid) async {
    try {
      await _analytics?.setUserId(id: uid);
    } catch (_) {}
  }
}
