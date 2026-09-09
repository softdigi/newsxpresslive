import '../models/social_model.dart';
import 'api_service.dart';
import '../../core/constants/api_endpoints.dart';

/// Service for all social (follow / unfollow / social-feed) API calls.
///
/// Requires an [ApiService] that has [ApiService.idTokenProvider] set so that
/// authenticated endpoints (POST toggle, GET check, GET social_feed) receive
/// the Authorization: Bearer header automatically.
class SocialService {
  SocialService({required ApiService api}) : _api = api;

  final ApiService _api;

  // ── Follow / Unfollow ──────────────────────────────────────────────────────

  /// Toggle follow / unfollow [targetUid].
  ///
  /// Returns a map with `is_following` (bool) and `followers_count` (int),
  /// or null on error.
  Future<Map<String, dynamic>?> toggleFollow(String targetUid) async {
    try {
      final data = await _api.postJson(
        ApiEndpoints.follow,
        body: {'target_uid': targetUid},
      );
      if (data is Map<String, dynamic> && data['success'] == true) {
        return data;
      }
    } catch (_) {}
    return null;
  }

  // ── Check follow status ────────────────────────────────────────────────────

  /// Returns true if the authenticated user is following [targetUid].
  Future<bool> isFollowing(String targetUid) async {
    try {
      final data = await _api.get(
        ApiEndpoints.follow,
        queryParams: {'action': 'check', 'uid': targetUid},
      );
      if (data is Map<String, dynamic>) {
        return data['is_following'] == true;
      }
    } catch (_) {}
    return false;
  }

  // ── Counts ─────────────────────────────────────────────────────────────────

  /// Fetch [FollowCounts] (followers + following) for any [uid].
  /// No auth required.
  Future<FollowCounts> getFollowCounts(String uid) async {
    try {
      final data = await _api.get(
        ApiEndpoints.follow,
        queryParams: {'action': 'counts', 'uid': uid},
      );
      if (data is Map<String, dynamic> && data['success'] == true) {
        return FollowCounts.fromJson(data);
      }
    } catch (_) {}
    return FollowCounts.empty(uid);
  }

  // ── Follower / Following lists ─────────────────────────────────────────────

  /// Fetch a page of [SocialUser]s who follow [uid]. No auth required.
  Future<List<SocialUser>> getFollowers(
    String uid, {
    int page = 1,
    int perPage = 20,
  }) async {
    return _fetchUserList(uid: uid, action: 'followers', page: page, perPage: perPage);
  }

  /// Fetch a page of [SocialUser]s that [uid] is following. No auth required.
  Future<List<SocialUser>> getFollowing(
    String uid, {
    int page = 1,
    int perPage = 20,
  }) async {
    return _fetchUserList(uid: uid, action: 'following', page: page, perPage: perPage);
  }

  Future<List<SocialUser>> _fetchUserList({
    required String uid,
    required String action,
    required int page,
    required int perPage,
  }) async {
    try {
      final data = await _api.get(
        ApiEndpoints.follow,
        queryParams: {
          'action':   action,
          'uid':      uid,
          'page':     '$page',
          'per_page': '$perPage',
        },
      );
      if (data is! Map<String, dynamic>) return [];
      final list = data['users'];
      if (list is! List) return [];
      return list
          .whereType<Map<String, dynamic>>()
          .map(SocialUser.fromJson)
          .toList();
    } catch (_) {
      return [];
    }
  }

  // ── Social Feed ────────────────────────────────────────────────────────────

  /// Fetch the social news feed for the authenticated user.
  ///
  /// Returns articles from reporters the user follows, falling back to
  /// trending articles when the user doesn't follow anyone yet.
  Future<SocialFeedResult> getSocialFeed({
    int page = 1,
    int limit = 15,
    List<int> exclude = const [],
  }) async {
    try {
      final params = <String, String>{
        'page':  '$page',
        'limit': '$limit',
      };
      if (exclude.isNotEmpty) {
        params['exclude'] = exclude.join(',');
      }
      final data = await _api.get(
        ApiEndpoints.socialFeed,
        queryParams: params,
      );
      if (data is! Map<String, dynamic> || data['success'] != true) {
        return SocialFeedResult.empty();
      }
      final list = data['news'];
      return SocialFeedResult(
        page:       (data['page'] as num?)?.toInt() ?? page,
        hasMore:    data['has_more'] == true,
        isFallback: data['fallback'] == true,
        articles:   list is List
            ? list.whereType<Map<String, dynamic>>().toList()
            : [],
      );
    } catch (_) {
      return SocialFeedResult.empty();
    }
  }
}
