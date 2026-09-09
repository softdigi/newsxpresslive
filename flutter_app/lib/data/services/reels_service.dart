import '../models/reel_model.dart';
import 'api_service.dart';
import '../../core/constants/api_endpoints.dart';

/// Service for all reel-related API calls.
class ReelsService {
  ReelsService({ApiService? api}) : _api = api ?? ApiService();

  final ApiService _api;

  // ── Feed ───────────────────────────────────────────────────────────────

  /// Fetch a page of published reels.
  Future<List<ReelModel>> getReels({int page = 1, int perPage = 5}) async {
    final data = await _api.get(
      ApiEndpoints.reels,
      queryParams: {'page': '$page', 'per_page': '$perPage'},
    );
    if (data is! Map<String, dynamic>) return [];
    final list = data['reels'];
    if (list is! List) return [];
    return list
        .whereType<Map<String, dynamic>>()
        .map(ReelModel.fromJson)
        .toList();
  }

  // ── View ───────────────────────────────────────────────────────────────

  /// Record that the user watched reel [id].
  Future<void> recordView(int id) async {
    try {
      await _api.post('${ApiEndpoints.reels}?action=view&id=$id');
    } catch (_) {}
  }

  // ── Like ───────────────────────────────────────────────────────────────

  /// Toggle like on [reelId].
  /// Returns `{ liked: bool, likes_count: int }` or null on error.
  Future<Map<String, dynamic>?> toggleLike(int reelId) async {
    try {
      final data = await _api.postJson(
        ApiEndpoints.reelLike,
        body: {'reel_id': reelId},
      );
      if (data is Map<String, dynamic>) return data;
    } catch (_) {}
    return null;
  }

  // ── Comments ───────────────────────────────────────────────────────────

  /// Fetch approved comments for [reelId].
  Future<List<ReelComment>> getComments(int reelId) async {
    try {
      final data = await _api.get(
        ApiEndpoints.reelComment,
        queryParams: {'reel_id': '$reelId'},
      );
      if (data is! List) return [];
      return data
          .whereType<Map<String, dynamic>>()
          .map(ReelComment.fromJson)
          .toList();
    } catch (_) {
      return [];
    }
  }

  /// Submit a comment on [reelId].
  Future<bool> submitComment({
    required int    reelId,
    required String authorName,
    required String content,
  }) async {
    try {
      final data = await _api.postJson(
        ApiEndpoints.reelComment,
        body: {
          'reel_id':     reelId,
          'author_name': authorName,
          'content':     content,
        },
      );
      return data is Map && data['success'] == true;
    } catch (_) {
      return false;
    }
  }

  void dispose() => _api.dispose();
}
