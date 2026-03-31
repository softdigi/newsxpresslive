/// Represents a single news article from the API.
class NewsArticle {
  final int    id;
  final String title;
  final String slug;
  final String content;
  final String? featuredImage;
  final String createdAt;
  final bool   isBreaking;
  final String? categoryName;
  final String? categorySlug;
  final String? reporterName;
  final String? reporterPhoto;
  final String? agencyName;
  final int    views;

  /// Viral engagement score (views × weight + shares × weight + comments …).
  /// Populated by the trending and more_news APIs.
  final double viralScore;

  /// True when the backend cron has flagged this article as trending.
  final bool isTrending;

  const NewsArticle({
    required this.id,
    required this.title,
    required this.slug,
    required this.content,
    this.featuredImage,
    required this.createdAt,
    this.isBreaking  = false,
    this.categoryName,
    this.categorySlug,
    this.reporterName,
    this.reporterPhoto,
    this.agencyName,
    this.views      = 0,
    this.viralScore = 0.0,
    this.isTrending = false,
  });

  factory NewsArticle.fromJson(Map<String, dynamic> json) {
    return NewsArticle(
      id:            _parseInt(json['id']),
      title:         (json['title'] as String? ?? '').trim(),
      slug:          (json['slug']  as String? ?? '').trim(),
      content:       json['content'] as String? ?? '',
      featuredImage: json['featured_image'] as String?,
      createdAt:     json['created_at']     as String? ?? '',
      isBreaking:    _parseBool(json['is_breaking']),
      categoryName:  json['category_name']  as String?,
      categorySlug:  json['category_slug']  as String?,
      reporterName:  json['reporter_name']  as String?,
      reporterPhoto: json['reporter_photo'] as String?,
      agencyName:    json['agency_name']    as String?,
      views:         _parseInt(json['views']),
      viralScore:    _parseDouble(json['viral_score']),
      isTrending:    _parseBool(json['is_trending']),
    );
  }

  Map<String, dynamic> toJson() => {
    'id':             id,
    'title':          title,
    'slug':           slug,
    'content':        content,
    'featured_image': featuredImage,
    'created_at':     createdAt,
    'is_breaking':    isBreaking,
    'category_name':  categoryName,
    'category_slug':  categorySlug,
    'reporter_name':  reporterName,
    'reporter_photo': reporterPhoto,
    'agency_name':    agencyName,
    'views':          views,
    'viral_score':    viralScore,
    'is_trending':    isTrending,
  };

  static int    _parseInt(dynamic v)    => v == null ? 0 : int.tryParse(v.toString()) ?? 0;
  static double _parseDouble(dynamic v) => v == null ? 0.0 : double.tryParse(v.toString()) ?? 0.0;
  static bool   _parseBool(dynamic v)   => v == true || v == 1 || v == '1';

  @override
  bool operator ==(Object other) => other is NewsArticle && other.id == id;

  @override
  int get hashCode => id.hashCode;
}
