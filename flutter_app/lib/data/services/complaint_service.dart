import '../models/complaint_model.dart';
import 'api_service.dart';
import '../../core/constants/api_endpoints.dart';

/// Result type for paginated complaints list.
class ComplaintsPage {
  const ComplaintsPage({
    required this.items,
    required this.total,
    required this.hasMore,
    this.nextCursor,
  });

  factory ComplaintsPage.empty() =>
      const ComplaintsPage(items: [], total: 0, hasMore: false);

  final List<ComplaintModel> items;
  final int   total;
  final bool  hasMore;
  final int?  nextCursor;
}

/// Service for all complaint/public-voice API calls.
///
/// Pass an [ApiService] with [idTokenProvider] set for endpoints that require
/// Firebase auth (submit, upvote, comment, my_complaints feed).
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

  // ── Enhanced feed (new API) ────────────────────────────────────────────

  /// Fetch complaints feed using the new enhanced API.
  /// [type] can be 'local', 'viral', 'recent', or 'my_complaints'.
  Future<ComplaintsPage> getFeed({
    String  type        = 'recent',
    int?    cursor,
    int     limit       = 20,
    double? lat,
    double? lng,
    int?    radiusKm,
    int?    stateId,
    int?    districtId,
    int?    categoryId,
    String? status,
  }) async {
    final params = <String, String>{
      'type':  type,
      'limit': '$limit',
      if (cursor      != null) 'cursor':      '$cursor',
      if (lat         != null) 'lat':          '$lat',
      if (lng         != null) 'lng':          '$lng',
      if (radiusKm    != null) 'radius_km':    '$radiusKm',
      if (stateId     != null) 'state_id':     '$stateId',
      if (districtId  != null) 'district_id':  '$districtId',
      if (categoryId  != null) 'category_id':  '$categoryId',
      if (status      != null && status.isNotEmpty) 'status': status,
    };
    try {
      final data = await _api.get(ApiEndpoints.complaintFeed, queryParams: params);
      if (data is! Map<String, dynamic>) return ComplaintsPage.empty();
      final list = data['items'];
      final items = list is List
          ? list.whereType<Map<String, dynamic>>()
                .map(ComplaintModel.fromJson)
                .toList()
          : <ComplaintModel>[];
      return ComplaintsPage(
        items:      items,
        total:      items.length,
        hasMore:    data['has_more'] == true,
        nextCursor: data['next_cursor'] as int?,
      );
    } catch (_) {
      return ComplaintsPage.empty();
    }
  }

  // ── Legacy feed (old API, kept for backward compat) ────────────────────

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

  // ── Complaint detail ───────────────────────────────────────────────────

  Future<Map<String, dynamic>?> getDetail(int complaintId) async {
    try {
      final data = await _api.get(
        ApiEndpoints.complaintDetail,
        queryParams: {'complaint_id': '$complaintId'},
      );
      if (data is Map<String, dynamic>) return data;
    } catch (_) {}
    return null;
  }

  // ── Submit (new enhanced multi-image API) ──────────────────────────────

  Future<Map<String, dynamic>> submitEnhanced({
    required int    categoryId,
    required String title,
    required String description,
    List<String>?   imagePaths,
    String?         videoUrl,
    double?         latitude,
    double?         longitude,
    String?         address,
    int?            stateId,
    int?            districtId,
    String?         city,
    String?         pincode,
    bool            isAnonymous = false,
  }) async {
    final fields = <String, String>{
      'category_id':  '$categoryId',
      'title':        title,
      'description':  description,
      'is_anonymous': isAnonymous ? '1' : '0',
      if (videoUrl   != null) 'video_url':   videoUrl,
      if (latitude   != null) 'latitude':    '$latitude',
      if (longitude  != null) 'longitude':   '$longitude',
      if (address    != null) 'address':     address,
      if (stateId    != null) 'state_id':    '$stateId',
      if (districtId != null) 'district_id': '$districtId',
      if (city       != null) 'city':        city,
      if (pincode    != null) 'pincode':     pincode,
    };

    // Build file map with array-style field names for multi-image upload
    final Map<String, String>? fileMap = imagePaths != null && imagePaths.isNotEmpty
        ? { for (var i = 0; i < imagePaths.length; i++) 'images[$i]': imagePaths[i] }
        : null;

    try {
      final data = await _api.postMultipart(
        ApiEndpoints.complaintSubmitEnhanced,
        fields:    fields,
        filePaths: fileMap,
      );
      if (data is Map<String, dynamic>) return data;
    } catch (e) {
      return {'success': false, 'message': e.toString()};
    }
    return {'success': false, 'message': 'Unknown error'};
  }

  // ── Submit (legacy single-photo API) ──────────────────────────────────

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

  // ── Upvote (new Firebase-auth based) ──────────────────────────────────

  Future<Map<String, dynamic>?> upvote(int complaintId) async {
    try {
      final data = await _api.postJson(
        ApiEndpoints.complaintUpvote,
        body: {'complaint_id': complaintId},
      );
      if (data is Map<String, dynamic>) return data;
    } catch (_) {}
    return null;
  }

  // ── Vote (legacy IP-based) ─────────────────────────────────────────────

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

  // ── Comments ───────────────────────────────────────────────────────────

  Future<List<ComplaintComment>> getComments(int complaintId, {int page = 1}) async {
    try {
      final data = await _api.get(
        ApiEndpoints.complaintComment,
        queryParams: {'complaint_id': '$complaintId', 'page': '$page'},
      );
      if (data is! Map<String, dynamic>) return [];
      final list = data['comments'];
      if (list is! List) return [];
      return list
          .whereType<Map<String, dynamic>>()
          .map(ComplaintComment.fromJson)
          .toList();
    } catch (_) {
      return [];
    }
  }

  Future<Map<String, dynamic>?> addComment({
    required int    complaintId,
    required String comment,
  }) async {
    try {
      final data = await _api.postJson(
        ApiEndpoints.complaintComment,
        body: {'complaint_id': complaintId, 'comment': comment},
      );
      if (data is Map<String, dynamic>) return data;
    } catch (_) {}
    return null;
  }

  void dispose() => _api.dispose();
}

/// Result type for paginated complaints list.
class ComplaintsPage {
  const ComplaintsPage({
    required this.items,
    required this.total,
    required this.hasMore,
    this.nextCursor,
  });

  factory ComplaintsPage.empty() =>
      const ComplaintsPage(items: [], total: 0, hasMore: false);

  final List<ComplaintModel> items;
  final int   total;
  final bool  hasMore;
  final int?  nextCursor;
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

  // ── Enhanced feed (new API) ────────────────────────────────────────────

  /// Fetch complaints feed using the new enhanced API.
  /// [type] can be 'local', 'viral', 'recent', or 'my_complaints'.
  Future<ComplaintsPage> getFeed({
    String type         = 'recent',
    int?   cursor,
    int    limit        = 20,
    double? lat,
    double? lng,
    int?   radiusKm,
    int?   stateId,
    int?   districtId,
    int?   categoryId,
    String? status,
    String? idToken,
  }) async {
    final params = <String, String>{
      'type':  type,
      'limit': '$limit',
      if (cursor      != null) 'cursor':      '$cursor',
      if (lat         != null) 'lat':          '$lat',
      if (lng         != null) 'lng':          '$lng',
      if (radiusKm    != null) 'radius_km':    '$radiusKm',
      if (stateId     != null) 'state_id':     '$stateId',
      if (districtId  != null) 'district_id':  '$districtId',
      if (categoryId  != null) 'category_id':  '$categoryId',
      if (status      != null && status.isNotEmpty) 'status': status,
    };
    try {
      final data = await _api.get(
        ApiEndpoints.complaintFeed,
        queryParams: params,
        authToken: idToken,
      );
      if (data is! Map<String, dynamic>) return ComplaintsPage.empty();
      final list = data['items'];
      final items = list is List
          ? list.whereType<Map<String, dynamic>>()
                .map(ComplaintModel.fromJson)
                .toList()
          : <ComplaintModel>[];
      return ComplaintsPage(
        items:      items,
        total:      items.length,
        hasMore:    data['has_more'] == true,
        nextCursor: data['next_cursor'] as int?,
      );
    } catch (_) {
      return ComplaintsPage.empty();
    }
  }

  // ── Legacy feed (old API, kept for backward compat) ────────────────────

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

  // ── Complaint detail ───────────────────────────────────────────────────

  Future<Map<String, dynamic>?> getDetail(int complaintId, {String? idToken}) async {
    try {
      final data = await _api.get(
        ApiEndpoints.complaintDetail,
        queryParams: {'complaint_id': '$complaintId'},
        authToken: idToken,
      );
      if (data is Map<String, dynamic>) return data;
    } catch (_) {}
    return null;
  }

  // ── Submit (new enhanced multi-image API) ──────────────────────────────

  Future<Map<String, dynamic>> submitEnhanced({
    required int    categoryId,
    required String title,
    required String description,
    required String idToken,
    List<String>?   imagePaths,
    String?         videoUrl,
    double?         latitude,
    double?         longitude,
    String?         address,
    int?            stateId,
    int?            districtId,
    String?         city,
    String?         pincode,
    bool            isAnonymous = false,
  }) async {
    final fields = <String, String>{
      'category_id':  '$categoryId',
      'title':        title,
      'description':  description,
      'is_anonymous': isAnonymous ? '1' : '0',
      if (videoUrl   != null) 'video_url':   videoUrl,
      if (latitude   != null) 'latitude':    '$latitude',
      if (longitude  != null) 'longitude':   '$longitude',
      if (address    != null) 'address':     address,
      if (stateId    != null) 'state_id':    '$stateId',
      if (districtId != null) 'district_id': '$districtId',
      if (city       != null) 'city':        city,
      if (pincode    != null) 'pincode':     pincode,
    };

    try {
      final data = await _api.postMultipart(
        ApiEndpoints.complaintSubmitEnhanced,
        fields:     fields,
        filePaths:  imagePaths != null
            ? { for (var i = 0; i < imagePaths.length; i++) 'images[$i]': imagePaths[i] }
            : null,
        authToken:  idToken,
      );
      if (data is Map<String, dynamic>) return data;
    } catch (e) {
      return {'success': false, 'message': e.toString()};
    }
    return {'success': false, 'message': 'Unknown error'};
  }

  // ── Submit (legacy single-photo API) ──────────────────────────────────

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

  // ── Upvote (new Firebase-auth based) ──────────────────────────────────

  Future<Map<String, dynamic>?> upvote(int complaintId, {required String idToken}) async {
    try {
      final data = await _api.postJson(
        ApiEndpoints.complaintUpvote,
        body: {'complaint_id': complaintId},
        authToken: idToken,
      );
      if (data is Map<String, dynamic>) return data;
    } catch (_) {}
    return null;
  }

  // ── Vote (legacy IP-based) ─────────────────────────────────────────────

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

  // ── Comments ───────────────────────────────────────────────────────────

  Future<List<ComplaintComment>> getComments(int complaintId, {int page = 1}) async {
    try {
      final data = await _api.get(
        ApiEndpoints.complaintComment,
        queryParams: {'complaint_id': '$complaintId', 'page': '$page'},
      );
      if (data is! Map<String, dynamic>) return [];
      final list = data['comments'];
      if (list is! List) return [];
      return list
          .whereType<Map<String, dynamic>>()
          .map(ComplaintComment.fromJson)
          .toList();
    } catch (_) {
      return [];
    }
  }

  Future<Map<String, dynamic>?> addComment({
    required int    complaintId,
    required String comment,
    required String idToken,
  }) async {
    try {
      final data = await _api.postJson(
        ApiEndpoints.complaintComment,
        body:      {'complaint_id': complaintId, 'comment': comment},
        authToken: idToken,
      );
      if (data is Map<String, dynamic>) return data;
    } catch (_) {}
    return null;
  }

  void dispose() => _api.dispose();
}
