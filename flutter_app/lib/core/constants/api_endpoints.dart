// ignore_for_file: constant_identifier_names

/// All API endpoint paths for the NewsXpressLive backend.
/// Change [baseUrl] to point at your deployed server.
class ApiEndpoints {
  ApiEndpoints._();

  // ── Base ──────────────────────────────────────────────────────────────
  /// Change this before building for production.
  static const String baseUrl = 'http://localhost/web';

  // ── News ──────────────────────────────────────────────────────────────
  /// GET  ?page=1&per=9   → paginated news list
  static const String newsList = '$baseUrl/api/more_news.php';

  /// GET  ?page=1&per=9&category=slug → category news
  static const String categoryNews = '$baseUrl/api/more_news.php';

  /// GET  ?slug=article-slug → single article detail
  static const String newsDetail = '$baseUrl/news/detail_api.php';

  /// GET  → latest breaking news item
  static const String latestBreaking = '$baseUrl/api/latest_breaking.php';

  /// GET  ?q=keyword&page=1 → search results
  static const String search = '$baseUrl/api/search.php';

  /// GET  ?limit=5 → top-viewed articles (last 7 days)
  static const String trending = '$baseUrl/api/trending.php';

  // ── Categories ────────────────────────────────────────────────────────
  /// GET → all categories list
  static const String categories = '$baseUrl/api/categories.php';

  // ── Comments ──────────────────────────────────────────────────────────
  /// GET  ?news_id=123     → approved comments for article
  static const String comments = '$baseUrl/api/comments.php';

  /// POST news_id, author_name, content, [author_email], [parent_id]
  static const String commentSubmit = '$baseUrl/api/comment_submit.php';

  // ── Newsletter ────────────────────────────────────────────────────────
  /// POST email
  static const String subscribe = '$baseUrl/api/subscribe.php';
}
