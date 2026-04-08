/// Represents a single complaint / public-voice item from the API.
class ComplaintModel {
  final int    id;
  final String title;
  final String description;
  final String? photo;
  final int    votesCount;
  final String status;
  final String? locationText;
  final String? districtName;
  final String? authorName;
  final String createdAt;
  final ComplaintCategory category;
  final bool   userVoted;

  const ComplaintModel({
    required this.id,
    required this.title,
    required this.description,
    this.photo,
    required this.votesCount,
    required this.status,
    this.locationText,
    this.districtName,
    this.authorName,
    required this.createdAt,
    required this.category,
    required this.userVoted,
  });

  factory ComplaintModel.fromJson(Map<String, dynamic> json) {
    return ComplaintModel(
      id:           _parseInt(json['id']),
      title:        json['title']        as String? ?? '',
      description:  json['description']  as String? ?? '',
      photo:        json['photo']        as String?,
      votesCount:   _parseInt(json['votes_count']),
      status:       json['status']       as String? ?? 'pending',
      locationText: json['location_text'] as String?,
      districtName: json['district_name'] as String?,
      authorName:   json['author_name']   as String?,
      createdAt:    json['created_at']    as String? ?? '',
      category:     ComplaintCategory.fromJson(
          json['category'] as Map<String, dynamic>? ?? {}),
      userVoted:    json['user_voted'] == true,
    );
  }

  ComplaintModel copyWith({int? votesCount, bool? userVoted}) {
    return ComplaintModel(
      id:           id,
      title:        title,
      description:  description,
      photo:        photo,
      votesCount:   votesCount   ?? this.votesCount,
      status:       status,
      locationText: locationText,
      districtName: districtName,
      authorName:   authorName,
      createdAt:    createdAt,
      category:     category,
      userVoted:    userVoted    ?? this.userVoted,
    );
  }

  static int _parseInt(dynamic v) {
    if (v is int)    return v;
    if (v is double) return v.toInt();
    if (v is String) return int.tryParse(v) ?? 0;
    return 0;
  }
}

/// Category metadata for a complaint.
class ComplaintCategory {
  final String name;
  final String slug;
  final String? icon;

  const ComplaintCategory({
    required this.name,
    required this.slug,
    this.icon,
  });

  factory ComplaintCategory.fromJson(Map<String, dynamic> json) {
    return ComplaintCategory(
      name: json['name'] as String? ?? '',
      slug: json['slug'] as String? ?? '',
      icon: json['icon'] as String?,
    );
  }
}
