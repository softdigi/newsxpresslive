import '../models/complaint_model.dart';
import 'api_service.dart';
import '../../core/constants/api_endpoints.dart';

/// Result type for paginated complaints list.
class ComplaintsPage {
  const ComplaintsPage({
    required this.items,
    required this.total,
    required this.hasMore,
  });

  factory ComplaintsPage.empty() =>
      const ComplaintsPage(items: [], total: 0, hasMore: false);

  final List<ComplaintModel> items;
  final int   total;
  final bool  hasMore;
}

/// Service for all complaint/public-voice API calls.
class ComplaintService {
  ComplaintService({ApiService? api}) : _api = api ?? ApiService();

  final ApiService _api;

  // ── Category list ──────────────────────────────────────────────────────

  Future<List<ComplaintCategory>> getCategories() async {
    try {
      final data = await _api.get(
        ApiEndpoints.complaints,
        queryParams: {'meta': '1'},
      );
      if (data is! Map<String, dynamic>) return [];
      final list = data['categories'];
      if (list is! List) return [];
      return list
          .whereType<Map<String, dynamic>>()
          .map(ComplaintCategory.fromJson)
          .toList();
    } catch (_) {
      return [];
    }
  }

  // ── Fetch complaints ───────────────────────────────────────────────────

  Future<ComplaintsPage> getComplaints({
    int    page        = 1,
    int    perPage     = 10,
    String? category,
    int?   districtId,
    String status      = 'approved',
  }) async {
    final params = <String, String>{
      'page':     '$page',
      'per_page': '$perPage',
      'status':   status,
      if (category   != null && category.isNotEmpty) 'category':    category,
      if (districtId != null) 'district_id': '$districtId',
    };
    try {
      final data = await _api.get(ApiEndpoints.complaints, queryParams: params);
      if (data is! Map<String, dynamic>) return ComplaintsPage.empty();
      final list = data['complaints'];
      final items = list is List
          ? list.whereType<Map<String, dynamic>>()
                .map(ComplaintModel.fromJson)
                .toList()
          : <ComplaintModel>[];
      return ComplaintsPage(
        items:   items,
        total:   data['total'] as int? ?? items.length,
        hasMore: data['has_more'] == true,
      );
    } catch (_) {
      return ComplaintsPage.empty();
    }
  }

  // ── Submit ─────────────────────────────────────────────────────────────

  /// Submit a new complaint.  Pass [photoPath] for an optional image upload.
  Future<Map<String, dynamic>> submit({
    required String title,
    required String description,
    required int    categoryId,
    int?    districtId,
    String? locationText,
    String? authorName,
    String? firebaseUid,
    bool    isAnonymous = false,
    String? photoPath,
  }) async {
    final fields = <String, String>{
      'title':        title,
      'description':  description,
      'category_id':  '$categoryId',
      'is_anonymous': isAnonymous ? '1' : '0',
      if (districtId   != null) 'district_id':   '$districtId',
      if (locationText != null) 'location_text':  locationText,
      if (authorName   != null) 'author_name':    authorName,
      if (firebaseUid  != null) 'firebase_uid':   firebaseUid,
    };

    try {
      final data = await _api.postMultipart(
        ApiEndpoints.complaintSubmit,
        fields:    fields,
        filePaths: photoPath != null ? {'photo': photoPath} : null,
      );
      if (data is Map<String, dynamic>) return data;
    } catch (e) {
      return {'success': false, 'message': e.toString()};
    }
    return {'success': false, 'message': 'Unknown error'};
  }

  // ── Vote ───────────────────────────────────────────────────────────────

  /// Toggle support vote on a complaint.
  Future<Map<String, dynamic>?> vote(int complaintId, {String? firebaseUid}) async {
    try {
      final data = await _api.postJson(
        ApiEndpoints.complaintVote,
        body: {
          'complaint_id': complaintId,
          if (firebaseUid != null) 'firebase_uid': firebaseUid,
        },
      );
      if (data is Map<String, dynamic>) return data;
    } catch (_) {}
    return null;
  }

  void dispose() => _api.dispose();
}
