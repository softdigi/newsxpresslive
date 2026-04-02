import '../models/news_article.dart';
import '../models/category.dart';
import '../models/comment.dart';
import '../services/api_service.dart';
import '../../core/constants/api_endpoints.dart';

/// Result type for cursor-based feed pagination.
class NewsPage {
  const NewsPage({
    required this.articles,
    required this.hasMore,
    this.nextLastId,
    this.nextLastCreatedAt,
  });

  factory NewsPage.empty() => const NewsPage(articles: [], hasMore: false);

  final List<NewsArticle> articles;
  final bool              hasMore;

  /// Cursor values to pass as [lastId] / [lastCreatedAt] on the next page
  /// request.  Both are null when [hasMore] is false.
  final int?    nextLastId;
  final String? nextLastCreatedAt;
}

/// High-level service methods for news-related API calls.
class NewsService {
  NewsService({required ApiService api}) : _api = api;

  final ApiService _api;

  // ── News list ──────────────────────────────────────────────────────────

  /// Fetch a paginated list of news articles.
  /// Pass [categorySlug] to filter by category.
  /// Pass [sort] = `'viral'` to enable feed-boost mode (viral articles
  /// injected at the configurable boost percentage).
  ///
  /// For cursor-based (keyset) pagination pass [lastId] and
  /// [lastCreatedAt] from the previous page's response.  Omit both on the
  /// first page.  The response exposes [NewsPage.nextLastId] and
  /// [NewsPage.nextLastCreatedAt] which the caller should forward on the
  /// next call.
  Future<NewsPage> getNewsPage({
    String? categorySlug,
    String? sort,
    int?    lastId,
    String? lastCreatedAt,
  }) async {
    final params = <String, String>{
      if (categorySlug != null) 'category': categorySlug,
      if (sort != null && sort.isNotEmpty) 'sort': sort,
      if (lastId        != null) 'last_id': lastId.toString(),
      if (lastCreatedAt != null) 'last_created_at': lastCreatedAt,
    };
    final data = await _api.get(ApiEndpoints.newsList, queryParams: params);
    if (data is! Map<String, dynamic>) return NewsPage.empty();
    final list = data['news'];
    final articles = list is List
        ? list
            .map((e) => NewsArticle.fromJson(e as Map<String, dynamic>))
            .toList()
        : <NewsArticle>[];
    return NewsPage(
      articles:        articles,
      hasMore:         data['has_more'] == true,
      nextLastId:      data['next_last_id'] as int?,
      nextLastCreatedAt: data['next_last_created_at'] as String?,
    );
  }

  /// Legacy offset-based list method kept for non-feed callers (search, etc.).
  Future<List<NewsArticle>> getNewsList({
    int page = 1,
    int perPage = 10,
    String? categorySlug,
    String? sort,
  }) async {
    final params = <String, String>{
      'page': page.toString(),
      'per':  perPage.toString(),
      if (categorySlug != null) 'category': categorySlug,
      if (sort != null && sort.isNotEmpty) 'sort': sort,
    };
    final data = await _api.get(ApiEndpoints.newsList, queryParams: params);
    if (data is! List) return [];
    return data.map((e) => NewsArticle.fromJson(e as Map<String, dynamic>)).toList();
  }

  // ── Article detail ────────────────────────────────────────────────────

  /// Fetch full article details by slug.
  Future<NewsArticle?> getArticleDetail(String slug) async {
    final data = await _api.get(
      ApiEndpoints.newsDetail,
      queryParams: {'slug': slug},
    );
    if (data == null) return null;
    return NewsArticle.fromJson(data as Map<String, dynamic>);
  }

  // ── Breaking news ─────────────────────────────────────────────────────

  /// Fetch the single latest breaking news headline.
  Future<NewsArticle?> getLatestBreaking() async {
    final data = await _api.get(ApiEndpoints.latestBreaking);
    if (data == null) return null;
    return NewsArticle.fromJson(data as Map<String, dynamic>);
  }

  // ── Trending news ─────────────────────────────────────────────────────

  /// Fetch top-viral articles from the last 7 days, ordered by viral_score.
  Future<List<NewsArticle>> getTrending({int limit = 5}) async {
    final data = await _api.get(
      ApiEndpoints.trending,
      queryParams: {'limit': limit.toString()},
    );
    if (data is! List) return [];
    return data.map((e) => NewsArticle.fromJson(e as Map<String, dynamic>)).toList();
  }

  // ── Search ────────────────────────────────────────────────────────────

  /// Search articles by keyword.
  Future<List<NewsArticle>> search(String query, {int page = 1}) async {
    final data = await _api.get(
      ApiEndpoints.search,
      queryParams: {'q': query, 'page': page.toString()},
    );
    if (data is! List) return [];
    return data.map((e) => NewsArticle.fromJson(e as Map<String, dynamic>)).toList();
  }

  // ── Categories ────────────────────────────────────────────────────────

  /// Fetch all categories.
  Future<List<Category>> getCategories() async {
    final data = await _api.get(ApiEndpoints.categories);
    if (data is! List) return [];
    return data.map((e) => Category.fromJson(e as Map<String, dynamic>)).toList();
  }

  // ── Comments ──────────────────────────────────────────────────────────

  /// Fetch approved comments for an article, returned as a threaded tree.
  Future<List<Comment>> getComments(int newsId) async {
    final data = await _api.get(
      ApiEndpoints.comments,
      queryParams: {'news_id': newsId.toString()},
    );
    if (data is! List) return [];
    final flat = data.map((e) => Comment.fromJson(e as Map<String, dynamic>)).toList();
    return buildCommentTree(flat);
  }

  /// Submit a new comment. Returns {success, message}.
  Future<Map<String, dynamic>> submitComment({
    required int    newsId,
    required String authorName,
    required String content,
    String?         authorEmail,
    int?            parentId,
  }) async {
    final body = <String, String>{
      'news_id':     newsId.toString(),
      'author_name': authorName,
      'content':     content,
      if (authorEmail != null && authorEmail.isNotEmpty)
        'author_email': authorEmail,
      if (parentId != null)
        'parent_id': parentId.toString(),
    };
    final data = await _api.post(ApiEndpoints.commentSubmit, body: body);
    if (data is Map<String, dynamic>) return data;
    return {'success': false, 'message': 'Unknown error'};
  }

  // ── Reporter ──────────────────────────────────────────────────────────

  /// Submit a reporter news story. Returns {success, news_id, status}.
  Future<Map<String, dynamic>> submitNews({
    required String firebaseUid,
    required String title,
    required String description,
    required int    categoryId,
    required int    languageId,
    int?            countryId,
    int?            stateId,
    int?            districtId,
  }) async {
    final data = await _api.postJson(ApiEndpoints.submitNews, body: {
      'firebase_uid': firebaseUid,
      'title':        title,
      'description':  description,
      'category_id':  categoryId,
      'language_id':  languageId,
      if (countryId  != null) 'country_id':  countryId,
      if (stateId    != null) 'state_id':    stateId,
      if (districtId != null) 'district_id': districtId,
    });
    if (data is Map<String, dynamic>) return data;
    return {'success': false, 'message': 'Unknown error'};
  }

  void dispose() => _api.dispose();
}
