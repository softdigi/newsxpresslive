import 'dart:io';
import 'package:http/http.dart' as http;
import 'dart:convert';
import '../models/listing_model.dart';
import 'api_service.dart';
import '../../core/constants/api_endpoints.dart';

/// Service for Classified & Marketplace API calls.
class ListingService {
  ListingService({ApiService? api}) : _api = api ?? ApiService();

  final ApiService _api;

  // ── Feed ───────────────────────────────────────────────────────────────

  Future<ListingFeedResponse?> getFeed({
    int? categoryId,
    String? listingType,
    double? minPrice,
    double? maxPrice,
    int? stateId,
    int? districtId,
    double? lat,
    double? lng,
    double radiusKm = 50,
    String? search,
    int cursor = 0,
    int limit = 20,
    bool featuredFirst = true,
  }) async {
    final params = <String, String>{};
    if (categoryId != null) params['category_id']   = categoryId.toString();
    if (listingType != null) params['listing_type'] = listingType;
    if (minPrice != null)   params['min_price']     = minPrice.toString();
    if (maxPrice != null)   params['max_price']     = maxPrice.toString();
    if (stateId != null)    params['state_id']      = stateId.toString();
    if (districtId != null) params['district_id']   = districtId.toString();
    if (lat != null)        params['lat']           = lat.toString();
    if (lng != null)        params['lng']           = lng.toString();
    if (lat != null)        params['radius_km']     = radiusKm.toString();
    if (search != null && search.isNotEmpty) params['search'] = search;
    if (cursor > 0)         params['cursor']        = cursor.toString();
    params['limit']          = limit.toString();
    params['featured_first'] = featuredFirst ? '1' : '0';

    try {
      final uri = Uri.parse(ApiEndpoints.listingsFeed)
          .replace(queryParameters: params);
      final data = await _api.get(uri.toString());
      if (data is Map<String, dynamic>) {
        return ListingFeedResponse.fromJson(data);
      }
    } catch (_) {}
    return null;
  }

  // ── Detail ─────────────────────────────────────────────────────────────

  Future<({ListingDetail listing, List<ListingSummary> similar})?> getDetail({
    required int id,
    String? firebaseUid,
  }) async {
    try {
      final params = <String, String>{'id': id.toString()};
      if (firebaseUid != null) params['firebase_uid'] = firebaseUid;
      final uri = Uri.parse(ApiEndpoints.listingsDetail)
          .replace(queryParameters: params);
      final data = await _api.get(uri.toString());
      if (data is Map<String, dynamic>) {
        final listing = ListingDetail.fromJson(
            data['listing'] as Map<String, dynamic>);
        final similar = (data['similar'] as List<dynamic>? ?? [])
            .map((e) => ListingSummary.fromJson(e as Map<String, dynamic>))
            .toList();
        return (listing: listing, similar: similar);
      }
    } catch (_) {}
    return null;
  }

  // ── Post ───────────────────────────────────────────────────────────────

  /// Create a listing with optional image files.
  Future<({int? listingId, String? status, String? error})> postListing({
    required String firebaseUid,
    required int categoryId,
    required String title,
    required String description,
    String listingType = 'sell',
    double? price,
    bool priceNegotiable = false,
    String priceType = 'fixed',
    int? stateId,
    int? districtId,
    String? city,
    String? pincode,
    double? latitude,
    double? longitude,
    String? contactName,
    String? contactPhone,
    bool showPhone = true,
    String? contactWhatsapp,
    int expiresDays = 30,
    List<File> images = const [],
  }) async {
    try {
      final uri = Uri.parse(ApiEndpoints.listingsPost);
      final request = http.MultipartRequest('POST', uri);
      request.fields['firebase_uid']    = firebaseUid;
      request.fields['category_id']     = categoryId.toString();
      request.fields['title']           = title;
      request.fields['description']     = description;
      request.fields['listing_type']    = listingType;
      if (price != null) request.fields['price'] = price.toString();
      request.fields['price_negotiable'] = priceNegotiable ? '1' : '0';
      request.fields['price_type']      = priceType;
      if (stateId != null)    request.fields['state_id']    = stateId.toString();
      if (districtId != null) request.fields['district_id'] = districtId.toString();
      if (city != null)       request.fields['city']        = city;
      if (pincode != null)    request.fields['pincode']     = pincode;
      if (latitude != null)   request.fields['latitude']    = latitude.toString();
      if (longitude != null)  request.fields['longitude']   = longitude.toString();
      if (contactName != null)     request.fields['contact_name']     = contactName;
      if (contactPhone != null)    request.fields['contact_phone']    = contactPhone;
      request.fields['show_phone'] = showPhone ? '1' : '0';
      if (contactWhatsapp != null) request.fields['contact_whatsapp'] = contactWhatsapp;
      request.fields['expires_days'] = expiresDays.toString();

      for (final f in images) {
        final stream = http.ByteStream(f.openRead());
        final length = await f.length();
        final name   = f.path.split('/').last;
        request.files.add(http.MultipartFile('images[]', stream, length,
            filename: name));
      }

      final streamed = await request.send()
          .timeout(const Duration(seconds: 60));
      final body = await streamed.stream.bytesToString();
      final json = jsonDecode(body) as Map<String, dynamic>;

      if (json['success'] == true) {
        return (
          listingId: json['listing_id'] as int?,
          status: json['status'] as String?,
          error: null,
        );
      }
      return (listingId: null, status: null, error: json['error'] as String?);
    } catch (e) {
      return (listingId: null, status: null, error: e.toString());
    }
  }

  // ── Save / Unsave ──────────────────────────────────────────────────────

  Future<({bool saved, int savesCount})?> toggleSave({
    required String firebaseUid,
    required int listingId,
  }) async {
    try {
      final data = await _api.postJson(ApiEndpoints.listingsSave, body: {
        'firebase_uid': firebaseUid,
        'listing_id':   listingId,
      });
      if (data is Map<String, dynamic>) {
        return (
          saved: (data['saved'] as bool?) ?? false,
          savesCount: (data['saves_count'] as int?) ?? 0,
        );
      }
    } catch (_) {}
    return null;
  }

  // ── Inquire ────────────────────────────────────────────────────────────

  Future<bool> sendInquiry({
    required String firebaseUid,
    required int listingId,
    required String message,
    String? contactPhone,
  }) async {
    try {
      final data = await _api.postJson(ApiEndpoints.listingsInquire, body: {
        'firebase_uid':  firebaseUid,
        'listing_id':    listingId,
        'message':       message,
        if (contactPhone != null) 'contact_phone': contactPhone,
      });
      return data is Map && data['success'] == true;
    } catch (_) {}
    return false;
  }

  // ── My Listings ────────────────────────────────────────────────────────

  Future<({List<MyListingItem> listings, bool hasMore, int? nextCursor})?> getMyListings({
    required String firebaseUid,
    String? status,
    int cursor = 0,
    int limit = 20,
  }) async {
    try {
      final params = <String, String>{
        'firebase_uid': firebaseUid,
        'limit': limit.toString(),
      };
      if (status != null) params['status'] = status;
      if (cursor > 0) params['cursor'] = cursor.toString();

      final uri = Uri.parse(ApiEndpoints.listingsMyListings)
          .replace(queryParameters: params);
      final data = await _api.get(uri.toString());
      if (data is Map<String, dynamic>) {
        final items = (data['listings'] as List<dynamic>)
            .map((e) => MyListingItem.fromJson(e as Map<String, dynamic>))
            .toList();
        return (
          listings: items,
          hasMore: (data['has_more'] as bool?) ?? false,
          nextCursor: data['next_cursor'] as int?,
        );
      }
    } catch (_) {}
    return null;
  }

  /// Delete a listing (sets status = rejected).
  Future<bool> deleteListing({
    required String firebaseUid,
    required int id,
  }) async {
    try {
      final uri = Uri.parse(ApiEndpoints.listingsMyListings)
          .replace(queryParameters: {
        'firebase_uid': firebaseUid,
        'id': id.toString(),
      });
      final data = await _api.delete(uri.toString());
      return data is Map && data['success'] == true;
    } catch (_) {}
    return false;
  }

  /// Mark a listing as sold / active / expired.
  Future<bool> updateListingStatus({
    required String firebaseUid,
    required int id,
    required String status,
  }) async {
    try {
      final uri = Uri.parse(ApiEndpoints.listingsMyListings)
          .replace(queryParameters: {
        'firebase_uid': firebaseUid,
        'id': id.toString(),
      });
      final data = await _api.patch(uri.toString());
      return data is Map && data['success'] == true;
    } catch (_) {}
    return false;
  }
}
