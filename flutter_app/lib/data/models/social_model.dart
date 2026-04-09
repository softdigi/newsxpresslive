/// Social Layer — Data Models

// ─────────────────────────────────────────────────────────────
// FollowCounts
// ─────────────────────────────────────────────────────────────

/// Follower / following counts for a user.
class FollowCounts {
  const FollowCounts({
    required this.uid,
    required this.followersCount,
    required this.followingCount,
  });

  factory FollowCounts.fromJson(Map<String, dynamic> json) => FollowCounts(
        uid:            json['uid']              as String? ?? '',
        followersCount: _parseInt(json['followers_count']),
        followingCount: _parseInt(json['following_count']),
      );

  factory FollowCounts.empty(String uid) =>
      FollowCounts(uid: uid, followersCount: 0, followingCount: 0);

  final String uid;
  final int followersCount;
  final int followingCount;

  static int _parseInt(dynamic v) {
    if (v == null) return 0;
    if (v is int) return v;
    return int.tryParse(v.toString()) ?? 0;
  }
}

// ─────────────────────────────────────────────────────────────
// SocialUser
// ─────────────────────────────────────────────────────────────

/// A user as returned in followers / following lists.
class SocialUser {
  const SocialUser({
    required this.firebaseUid,
    this.displayName,
    this.avatarUrl,
    this.isReporter = false,
    this.isVerified = false,
  });

  factory SocialUser.fromJson(Map<String, dynamic> json) => SocialUser(
        firebaseUid: json['firebase_uid'] as String? ?? '',
        displayName: json['display_name'] as String?,
        avatarUrl:   json['avatar_url']   as String?,
        isReporter:  json['is_reporter']  == true || json['is_reporter'] == 1,
        isVerified:  json['is_verified']  == true || json['is_verified'] == 1,
      );

  final String  firebaseUid;
  final String? displayName;
  final String? avatarUrl;
  final bool    isReporter;
  final bool    isVerified;

  /// Convenience: initials for avatar placeholder.
  String get initials {
    final name = displayName ?? firebaseUid;
    final parts = name.trim().split(RegExp(r'\s+'));
    if (parts.length >= 2) {
      return '${parts.first[0]}${parts.last[0]}'.toUpperCase();
    }
    return name.isNotEmpty ? name[0].toUpperCase() : '?';
  }
}

// ─────────────────────────────────────────────────────────────
// SocialFeedResult
// ─────────────────────────────────────────────────────────────

/// Paginated result for the social feed.
class SocialFeedResult {
  const SocialFeedResult({
    required this.page,
    required this.hasMore,
    required this.isFallback,
    required this.articles,
  });

  factory SocialFeedResult.empty() => const SocialFeedResult(
        page: 1,
        hasMore: false,
        isFallback: false,
        articles: [],
      );

  final int  page;
  final bool hasMore;
  final bool isFallback;
  final List<Map<String, dynamic>> articles;
}
