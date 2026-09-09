/// Represents a single short-video reel from the API.
class ReelModel {
  final int    id;
  final String title;
  final String? description;
  final String videoUrl;
  final String? thumbnail;
  final String reporterName;
  final int    likesCount;
  final int    viewsCount;
  final int    commentsCount;
  final String createdAt;
  final bool   userLiked;

  const ReelModel({
    required this.id,
    required this.title,
    this.description,
    required this.videoUrl,
    this.thumbnail,
    required this.reporterName,
    required this.likesCount,
    required this.viewsCount,
    required this.commentsCount,
    required this.createdAt,
    required this.userLiked,
  });

  factory ReelModel.fromJson(Map<String, dynamic> json) {
    return ReelModel(
      id:            _parseInt(json['id']),
      title:         json['title']         as String? ?? '',
      description:   json['description']   as String?,
      videoUrl:      json['video_url']     as String? ?? '',
      thumbnail:     json['thumbnail']     as String?,
      reporterName:  json['reporter_name'] as String? ?? 'Reporter',
      likesCount:    _parseInt(json['likes_count']),
      viewsCount:    _parseInt(json['views_count']),
      commentsCount: _parseInt(json['comments_count']),
      createdAt:     json['created_at']    as String? ?? '',
      userLiked:     json['user_liked'] == true,
    );
  }

  ReelModel copyWith({
    int?  likesCount,
    bool? userLiked,
    int?  commentsCount,
  }) {
    return ReelModel(
      id:            id,
      title:         title,
      description:   description,
      videoUrl:      videoUrl,
      thumbnail:     thumbnail,
      reporterName:  reporterName,
      likesCount:    likesCount    ?? this.likesCount,
      viewsCount:    viewsCount,
      commentsCount: commentsCount ?? this.commentsCount,
      createdAt:     createdAt,
      userLiked:     userLiked    ?? this.userLiked,
    );
  }

  static int _parseInt(dynamic v) {
    if (v is int)    return v;
    if (v is double) return v.toInt();
    if (v is String) return int.tryParse(v) ?? 0;
    return 0;
  }
}

/// Represents a comment on a reel.
class ReelComment {
  final int    id;
  final String authorName;
  final String content;
  final String createdAt;

  const ReelComment({
    required this.id,
    required this.authorName,
    required this.content,
    required this.createdAt,
  });

  factory ReelComment.fromJson(Map<String, dynamic> json) {
    return ReelComment(
      id:         json['id']          is int ? json['id'] : int.tryParse('${json['id']}') ?? 0,
      authorName: json['author_name'] as String? ?? 'Anonymous',
      content:    json['content']     as String? ?? '',
      createdAt:  json['created_at']  as String? ?? '',
    );
  }
}
