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

  // ── Auth & User ───────────────────────────────────────────────────────
  /// POST firebase_uid, name → upsert user, returns user_id
  static const String userLogin = '$baseUrl/api/user_login.php';

  // ── Onboarding ────────────────────────────────────────────────────────
  /// POST firebase_uid, country_id, [state_id], [district_id]
  static const String saveLocation = '$baseUrl/api/save_location.php';

  /// POST firebase_uid, languages: [{language_id, priority}]
  static const String saveLanguages = '$baseUrl/api/save_languages.php';

  /// POST firebase_uid, categories: [category_id, ...]
  static const String saveInterests = '$baseUrl/api/save_interests.php';

  // ── Geo ───────────────────────────────────────────────────────────────
  /// GET → list of countries
  static const String countries = '$baseUrl/geo/countries.php';

  /// GET ?country_id=1 → states for a country
  static const String states = '$baseUrl/geo/states.php';

  /// GET ?state_id=1 → districts for a state
  static const String districts = '$baseUrl/geo/districts.php';

  /// GET → all supported languages
  static const String languages = '$baseUrl/geo/languages.php';

  // ── Reporter ──────────────────────────────────────────────────────────
  /// POST (JSON) firebase_uid, title, description, category_id, language_id, ...
  static const String submitNews = '$baseUrl/api/submit_news.php';

  /// POST (multipart) news_id, image file
  static const String uploadNewsImage = '$baseUrl/api/upload_news_image.php';

  /// GET ?firebase_uid=xx → articles submitted by this reporter
  static const String myNews = '$baseUrl/api/my_news.php';

  // ── Feature Flags ─────────────────────────────────────────────────────
  /// GET ?user_id=xx&platform=app → enabled feature flags
  static const String featureFlags = '$baseUrl/api/v1/feature_flags.php';
}
