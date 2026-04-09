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

  /// GET  ?limit=5 → top-viral articles (last 7 days), ordered by viral_score
  static const String trending = '$baseUrl/api/trending.php';

  /// POST { news_id } → increment shares_count, update viral score
  static const String shareTrack = '$baseUrl/api/share_track.php';

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

  // ── Personalization ───────────────────────────────────────────────────
  /// GET  ?page=1&limit=15&exclude=1,2,3  → interest-weighted article feed
  static const String personalizedFeed = '$baseUrl/api/personalized_feed.php';

  /// POST {source, categories:[{category_id,weight}], tags:[{tag,weight}]}
  static const String updateInterests = '$baseUrl/api/update_interests.php';

  /// GET  ?page=1&limit=20  → user's read history (requires Bearer token)
  /// POST {news_id, read_percent, time_spent_sec} → record a read event
  static const String readHistory = '$baseUrl/api/read_history.php';

  /// POST (admin only) {user_id?} → run rule-based personalization engine
  static const String personalizationEngine = '$baseUrl/api/personalization_engine.php';

  // ── Feature Flags ─────────────────────────────────────────────────────
  /// GET ?user_id=xx&platform=app → enabled feature flags
  static const String featureFlags = '$baseUrl/api/v1/feature_flags.php';

  // ── Agency Partner ────────────────────────────────────────────────────
  static const String agencyBase    = '$baseUrl/api/v1/agency';
  static const String agencyLogin   = '$agencyBase/auth.php';
  static const String agencySubmit  = '$agencyBase/submit.php';
  static const String agencyBulk    = '$agencyBase/bulk_submit.php';
  static const String agencyCsvUpload = '$agencyBase/csv_upload.php';
  static const String agencyArticles  = '$agencyBase/articles.php';
  static const String agencyRevenue   = '$agencyBase/revenue.php';
  static const String agencyWithdraw  = '$agencyBase/withdraw.php';

  // ── Reels ─────────────────────────────────────────────────────────────
  /// GET  ?page=1&per_page=5   → paginated published reels
  /// POST ?action=view&id=N    → increment view count
  static const String reels        = '$baseUrl/api/reels.php';

  /// POST { reel_id } → toggle like, returns { liked, likes_count }
  static const String reelLike     = '$baseUrl/api/reel_like.php';

  /// GET  ?reel_id=N           → approved comments for a reel
  /// POST { reel_id, author_name, content } → submit comment
  static const String reelComment  = '$baseUrl/api/reel_comment.php';

  // ── Complaints / Public Voice ─────────────────────────────────────────
  /// GET  ?page=1&category=&district_id= → list complaints
  static const String complaints       = '$baseUrl/api/complaints.php';

  /// POST { firebase_uid, name, title, description, category, district_id?, photo? }
  static const String complaintSubmit  = '$baseUrl/api/complaint_submit.php';

  /// POST { complaint_id, firebase_uid } → toggle support/vote
  static const String complaintVote    = '$baseUrl/api/complaint_vote.php';

  // ── Social Layer ──────────────────────────────────────────────────────
  /// POST  (auth)   { target_uid }  → toggle follow/unfollow
  ///   returns { success, is_following, followers_count }
  ///
  /// GET   (public) ?action=counts   &uid=  → { followers_count, following_count }
  /// GET   (auth)   ?action=check    &uid=  → { is_following }
  /// GET   (public) ?action=followers&uid=&page=&per_page= → { users:[...] }
  /// GET   (public) ?action=following&uid=&page=&per_page= → { users:[...] }
  static const String follow = '$baseUrl/api/follow.php';

  /// GET (auth) ?page=1&limit=15&exclude=1,2,3
  ///   → news from followed reporters, fallback to trending
  static const String socialFeed = '$baseUrl/api/social_feed.php';
}
