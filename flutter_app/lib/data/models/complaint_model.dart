/// Represents a single complaint / public-voice item from the API.
class ComplaintModel {
  final int     id;
  final String  title;
  final String  description;
  // Legacy single-photo (old API); new API uses images list
  final String? photo;
  // Enhanced fields
  final List<String> images;
  final String? videoUrl;
  final double? latitude;
  final double? longitude;
  final String? address;
  // Engagement
  final int  votesCount;    // old API field (votes_count)
  final int  upvotesCount;  // new API field (upvotes_count)
  final int  commentsCount;
  final int  viewsCount;
  final bool isViral;
  // Status
  final String  status;
  final String? statusLabel;
  // Location (old-style)
  final String? locationText;
  final String? districtName;
  // Author
  final String? authorName;
  final String? userId;
  final String? userAvatar;
  final bool    isAnonymous;
  // Timestamps
  final String  createdAt;
  final String? createdAgo;
  // Category
  final ComplaintCategory category;
  // Interaction state
  final bool userVoted;
  final bool userUpvoted;

  const ComplaintModel({
    required this.id,
    required this.title,
    required this.description,
    this.photo,
    this.images = const [],
    this.videoUrl,
    this.latitude,
    this.longitude,
    this.address,
    required this.votesCount,
    this.upvotesCount = 0,
    this.commentsCount = 0,
    this.viewsCount = 0,
    this.isViral = false,
    required this.status,
    this.statusLabel,
    this.locationText,
    this.districtName,
    this.authorName,
    this.userId,
    this.userAvatar,
    this.isAnonymous = false,
    required this.createdAt,
    this.createdAgo,
    required this.category,
    this.userVoted = false,
    this.userUpvoted = false,
  });

  factory ComplaintModel.fromJson(Map<String, dynamic> json) {
    // images can come as JSON list or be null
    final rawImages = json['images'];
    final List<String> imageList = rawImages is List
        ? rawImages.whereType<String>().toList()
        : <String>[];

    return ComplaintModel(
      id:            _parseInt(json['id']),
      title:         json['title']        as String? ?? '',
      description:   json['description']  as String? ?? '',
      photo:         json['photo']        as String?,
      images:        imageList,
      videoUrl:      json['video_url']    as String?,
      latitude:      _parseDouble(json['latitude']),
      longitude:     _parseDouble(json['longitude']),
      address:       json['address']      as String?,
      votesCount:    _parseInt(json['votes_count']),
      upvotesCount:  _parseInt(json['upvotes_count']),
      commentsCount: _parseInt(json['comments_count']),
      viewsCount:    _parseInt(json['views_count']),
      isViral:       json['is_viral'] == true || json['is_viral'] == 1,
      status:        json['status']       as String? ?? 'pending',
      statusLabel:   json['status_label'] as String?,
      locationText:  json['location_text'] as String?,
      districtName:  json['district_name'] as String?,
      authorName:    json['author_name']   as String? ?? json['user_name'] as String?,
      userId:        json['user_id']       as String?,
      userAvatar:    json['user_avatar']   as String?,
      isAnonymous:   json['is_anonymous'] == true || json['is_anonymous'] == 1,
      createdAt:     json['created_at']    as String? ?? '',
      createdAgo:    json['created_ago']   as String?,
      category:      ComplaintCategory.fromJson(
          json['category'] as Map<String, dynamic>? ?? {
            'name': json['category_name'] ?? '',
            'slug': json['category_slug'] ?? '',
            'icon': json['category_icon'],
            'name_hi': json['category_name_hi'],
          }),
      userVoted:   json['user_voted']   == true,
      userUpvoted: json['user_upvoted'] == true,
    );
  }

  /// Effective display count: use upvotesCount for new API, votesCount for legacy.
  int get effectiveUpvotes => upvotesCount > 0 ? upvotesCount : votesCount;

  /// First image URL (prefers new images list, falls back to legacy photo).
  String? get thumbnailUrl => images.isNotEmpty ? images.first : photo;

  ComplaintModel copyWith({
    int?  votesCount,
    int?  upvotesCount,
    bool? userVoted,
    bool? userUpvoted,
    bool? isViral,
    int?  commentsCount,
  }) {
    return ComplaintModel(
      id:            id,
      title:         title,
      description:   description,
      photo:         photo,
      images:        images,
      videoUrl:      videoUrl,
      latitude:      latitude,
      longitude:     longitude,
      address:       address,
      votesCount:    votesCount    ?? this.votesCount,
      upvotesCount:  upvotesCount  ?? this.upvotesCount,
      commentsCount: commentsCount ?? this.commentsCount,
      viewsCount:    viewsCount,
      isViral:       isViral       ?? this.isViral,
      status:        status,
      statusLabel:   statusLabel,
      locationText:  locationText,
      districtName:  districtName,
      authorName:    authorName,
      userId:        userId,
      userAvatar:    userAvatar,
      isAnonymous:   isAnonymous,
      createdAt:     createdAt,
      createdAgo:    createdAgo,
      category:      category,
      userVoted:     userVoted    ?? this.userVoted,
      userUpvoted:   userUpvoted  ?? this.userUpvoted,
    );
  }

  static int _parseInt(dynamic v) {
    if (v is int)    return v;
    if (v is double) return v.toInt();
    if (v is String) return int.tryParse(v) ?? 0;
    return 0;
  }

  static double? _parseDouble(dynamic v) {
    if (v == null) return null;
    if (v is double) return v;
    if (v is int)    return v.toDouble();
    if (v is String) return double.tryParse(v);
    return null;
  }
}

/// Category metadata for a complaint.
class ComplaintCategory {
  final String  name;
  final String  slug;
  final String? icon;
  final String? nameHi;
  final String? department;

  const ComplaintCategory({
    required this.name,
    required this.slug,
    this.icon,
    this.nameHi,
    this.department,
  });

  factory ComplaintCategory.fromJson(Map<String, dynamic> json) {
    return ComplaintCategory(
      name:       json['name']       as String? ?? '',
      slug:       json['slug']       as String? ?? '',
      icon:       json['icon']       as String?,
      nameHi:     json['name_hi']    as String?,
      department: json['department'] as String?,
    );
  }
}

/// Complaint comment from the detail API.
class ComplaintComment {
  final int     id;
  final String  comment;
  final bool    isOfficial;
  final String  createdAt;
  final String? userName;
  final String? userAvatar;

  const ComplaintComment({
    required this.id,
    required this.comment,
    required this.isOfficial,
    required this.createdAt,
    this.userName,
    this.userAvatar,
  });

  factory ComplaintComment.fromJson(Map<String, dynamic> json) {
    return ComplaintComment(
      id:          json['id']          is int ? json['id'] as int : int.tryParse('${json['id']}') ?? 0,
      comment:     json['comment']     as String? ?? '',
      isOfficial:  json['is_official'] == true || json['is_official'] == 1,
      createdAt:   json['created_at']  as String? ?? '',
      userName:    json['user_name']   as String?,
      userAvatar:  json['user_avatar'] as String?,
    );
  }
}

/// Status timeline entry from the detail API.
class ComplaintUpdate {
  final String? oldStatus;
  final String? newStatus;
  final String? updateNote;
  final String  createdAt;

  const ComplaintUpdate({
    this.oldStatus,
    this.newStatus,
    this.updateNote,
    required this.createdAt,
  });

  factory ComplaintUpdate.fromJson(Map<String, dynamic> json) {
    return ComplaintUpdate(
      oldStatus:  json['old_status']  as String?,
      newStatus:  json['new_status']  as String?,
      updateNote: json['update_note'] as String?,
      createdAt:  json['created_at']  as String? ?? '',
    );
  }
}
