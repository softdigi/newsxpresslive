import '../models/news_article.dart';
import 'api_service.dart';
import '../../core/constants/api_endpoints.dart';

/// Result type for a personalized feed page.
class PersonalizedPage {
  const PersonalizedPage({
    required this.articles,
    required this.hasMore,
    this.nextPage,
  });

  factory PersonalizedPage.empty() =>
      const PersonalizedPage(articles: [], hasMore: false);

  final List<NewsArticle> articles;
  final bool hasMore;

  /// Next page number to request (null when [hasMore] is false).
  final int? nextPage;
}

/// A single item from the user's read history.
class ReadHistoryItem {
  const ReadHistoryItem({
    required this.newsId,
    required this.title,
    required this.slug,
    this.featuredImage,
    required this.categoryName,
    this.categorySlug,
    required this.readPercent,
    required this.timeSpentSec,
    required this.readAt,
  });

  factory ReadHistoryItem.fromJson(Map<String, dynamic> json) {
    return ReadHistoryItem(
      newsId:       _parseInt(json['news_id']),
      title:        (json['title']  as String? ?? '').trim(),
      slug:         (json['slug']   as String? ?? '').trim(),
      featuredImage: json['featured_image'] as String?,
      categoryName:  json['category_name']  as String? ?? '',
      categorySlug:  json['category_slug']  as String?,
      readPercent:   _parseInt(json['read_percent']),
      timeSpentSec:  _parseInt(json['time_spent_sec']),
      readAt:        json['read_at'] as String? ?? '',
    );
  }

  final int    newsId;
  final String title;
  final String slug;
  final String? featuredImage;
  final String categoryName;
  final String? categorySlug;
  final int    readPercent;
  final int    timeSpentSec;
  final String readAt;

  static int _parseInt(dynamic v) {
    if (v is int)    return v;
    if (v is String) return int.tryParse(v) ?? 0;
    return 0;
  }
}

/// Represents a user interest entry passed to [updateInterests].
class UserInterest {
  const UserInterest({this.categoryId, this.tag, this.weight = 1.0})
      : assert(
          (categoryId != null) != (tag != null),
          'Provide either categoryId or tag, not both.',
        );

  final int?    categoryId;
  final String? tag;
  final double  weight;

  Map<String, dynamic> toJson() => {
        if (categoryId != null) 'category_id': categoryId,
        if (tag        != null) 'tag':         tag,
        'weight': weight,
      };
}

/// High-level service for personalization features:
///   Feature 1 — Personalized Feed
///   Feature 2 — Read History Tracking
///   Feature 3 — Interest Management (triggers server-side rule engine)
class PersonalizationService {
  PersonalizationService({required ApiService api}) : _api = api;

  final ApiService _api;

  // ── Feature 1 — Personalized Feed ────────────────────────────────────

  /// Fetch a personalized article feed for the authenticated user.
  ///
  /// [page]      — 1-based page number.
  /// [limit]     — articles per page (1–30).
  /// [excludeIds] — IDs already shown to avoid duplicates in the session.
  Future<PersonalizedPage> getPersonalizedFeed({
    int        page        = 1,
    int        limit       = 15,
    List<int>? excludeIds,
  }) async {
    final params = <String, String>{
      'page':  page.toString(),
      'limit': limit.toString(),
      if (excludeIds != null && excludeIds.isNotEmpty)
        'exclude': excludeIds.join(','),
    };

    final data = await _api.get(
      ApiEndpoints.personalizedFeed,
      queryParams: params,
    );

    if (data is! Map<String, dynamic>) return PersonalizedPage.empty();

    final list = data['news'];
    final articles = list is List
        ? list
            .map((e) => NewsArticle.fromJson(e as Map<String, dynamic>))
            .toList()
        : <NewsArticle>[];

    final hasMore  = data['has_more'] == true;
    final nextPage = hasMore ? page + 1 : null;

    return PersonalizedPage(
      articles: articles,
      hasMore:  hasMore,
      nextPage: nextPage,
    );
  }

  // ── Feature 1 — Update User Interests ────────────────────────────────

  /// Upsert user interest weights on the server.
  ///
  /// [source]     — `'onboarding'` during initial setup, `'explicit'` for
  ///               user-triggered changes in Settings.
  /// [categories] — List of [UserInterest] objects with [categoryId] set.
  /// [tags]       — List of [UserInterest] objects with [tag] set.
  Future<bool> updateInterests({
    String             source     = 'explicit',
    List<UserInterest> categories = const [],
    List<UserInterest> tags       = const [],
  }) async {
    final body = {
      'source':     source,
      'categories': categories.map((e) => e.toJson()).toList(),
      'tags':       tags.map((e) => e.toJson()).toList(),
    };

    final data = await _api.postJson(
      ApiEndpoints.updateInterests,
      body: body,
    );

    return data is Map && data['success'] == true;
  }

  // ── Feature 2 — Read History ──────────────────────────────────────────

  /// Record that the current user read (or partially read) an article.
  ///
  /// [newsId]       — article ID.
  /// [readPercent]  — how far they scrolled (0–100).
  /// [timeSpentSec] — seconds spent on the article page.
  Future<bool> recordRead({
    required int newsId,
    int          readPercent  = 0,
    int          timeSpentSec = 0,
  }) async {
    final body = {
      'news_id':        newsId,
      'read_percent':   readPercent.clamp(0, 100),
      'time_spent_sec': timeSpentSec.clamp(0, 3600),
    };

    final data = await _api.postJson(
      ApiEndpoints.readHistory,
      body: body,
    );

    return data is Map && data['success'] == true;
  }

  /// Fetch the authenticated user's read history.
  ///
  /// [page]  — 1-based page.
  /// [limit] — items per page (1–50).
  Future<({List<ReadHistoryItem> items, bool hasMore})> getReadHistory({
    int page  = 1,
    int limit = 20,
  }) async {
    final params = <String, String>{
      'page':  page.toString(),
      'limit': limit.toString(),
    };

    final data = await _api.get(
      ApiEndpoints.readHistory,
      queryParams: params,
    );

    if (data is! Map<String, dynamic>) {
      return (items: <ReadHistoryItem>[], hasMore: false);
    }

    final list = data['history'];
    final items = list is List
        ? list
            .map((e) => ReadHistoryItem.fromJson(e as Map<String, dynamic>))
            .toList()
        : <ReadHistoryItem>[];

    return (items: items, hasMore: data['has_more'] == true);
  }
}
