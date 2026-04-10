import '../models/mandi_model.dart';
import 'api_service.dart';
import '../../core/constants/api_endpoints.dart';

/// Service for all Mandi Bhav API calls.
class MandiService {
  MandiService({ApiService? api}) : _api = api ?? ApiService();

  final ApiService _api;

  // ── Rates ──────────────────────────────────────────────────────────────

  /// Fetch today's rates for a mandi (by id or nearest to lat/lng).
  ///
  /// Pass either [mandiId] OR ([lat] + [lng]).
  Future<MandiRatesResponse?> getRates({
    int? mandiId,
    double? lat,
    double? lng,
    int? commodityId,
    String? date,
    int days = 1,
    String? category,
  }) async {
    final params = <String, String>{};
    if (mandiId != null) params['mandi_id'] = mandiId.toString();
    if (lat != null)     params['lat']       = lat.toString();
    if (lng != null)     params['lng']       = lng.toString();
    if (commodityId != null) params['commodity_id'] = commodityId.toString();
    if (date != null)    params['date']      = date;
    if (days > 1)        params['days']      = days.toString();
    if (category != null) params['category'] = category;

    try {
      final uri = Uri.parse(ApiEndpoints.mandiRates).replace(queryParameters: params);
      final data = await _api.get(uri.toString());
      if (data is Map<String, dynamic>) {
        return MandiRatesResponse.fromJson(data);
      }
    } catch (_) {}
    return null;
  }

  // ── Trend ──────────────────────────────────────────────────────────────

  /// Get N-day price trend for a commodity at a mandi.
  Future<MandiTrendData?> getTrend({
    required int commodityId,
    required int mandiId,
    int days = 30,
  }) async {
    try {
      final uri = Uri.parse(ApiEndpoints.mandiTrend).replace(queryParameters: {
        'commodity_id': commodityId.toString(),
        'mandi_id':     mandiId.toString(),
        'days':         days.toString(),
      });
      final data = await _api.get(uri.toString());
      if (data is Map<String, dynamic>) {
        return MandiTrendData.fromJson(data);
      }
    } catch (_) {}
    return null;
  }

  // ── Nearby ─────────────────────────────────────────────────────────────

  /// Get nearby mandis within [radiusKm] of [lat]/[lng].
  Future<List<NearbyMandi>> getNearby({
    required double lat,
    required double lng,
    double radiusKm = 100,
  }) async {
    try {
      final uri = Uri.parse(ApiEndpoints.mandiNearby).replace(queryParameters: {
        'lat':       lat.toString(),
        'lng':       lng.toString(),
        'radius_km': radiusKm.toString(),
      });
      final data = await _api.get(uri.toString());
      if (data is Map<String, dynamic> && data['mandis'] is List) {
        return (data['mandis'] as List)
            .map((e) => NearbyMandi.fromJson(e as Map<String, dynamic>))
            .toList();
      }
    } catch (_) {}
    return [];
  }

  // ── Alerts ─────────────────────────────────────────────────────────────

  /// Fetch all alerts for a user.
  Future<List<MandiAlert>> getAlerts(String firebaseUid) async {
    try {
      final uri = Uri.parse(ApiEndpoints.mandiAlert).replace(
        queryParameters: {'firebase_uid': firebaseUid},
      );
      final data = await _api.get(uri.toString());
      if (data is Map<String, dynamic> && data['alerts'] is List) {
        return (data['alerts'] as List)
            .map((e) => MandiAlert.fromJson(e as Map<String, dynamic>))
            .toList();
      }
    } catch (_) {}
    return [];
  }

  /// Create or update a price alert.
  Future<bool> setAlert({
    required String firebaseUid,
    required int commodityId,
    required int mandiId,
    required String alertType,
    required double targetPrice,
  }) async {
    try {
      final data = await _api.postJson(ApiEndpoints.mandiAlert, body: {
        'firebase_uid': firebaseUid,
        'commodity_id': commodityId,
        'mandi_id':     mandiId,
        'alert_type':   alertType,
        'target_price': targetPrice,
      });
      return data is Map && data['success'] == true;
    } catch (_) {}
    return false;
  }

  /// Delete an alert by id.
  Future<bool> deleteAlert({required int alertId, required String firebaseUid}) async {
    try {
      final uri = Uri.parse(ApiEndpoints.mandiAlert).replace(queryParameters: {
        'id':           alertId.toString(),
        'firebase_uid': firebaseUid,
      });
      final data = await _api.delete(uri.toString());
      return data is Map && data['success'] == true;
    } catch (_) {}
    return false;
  }

  /// Toggle alert active/inactive.
  Future<bool> toggleAlert({required int alertId, required String firebaseUid}) async {
    try {
      final uri = Uri.parse(ApiEndpoints.mandiAlert).replace(queryParameters: {
        'id':           alertId.toString(),
        'firebase_uid': firebaseUid,
      });
      final data = await _api.patch(uri.toString());
      return data is Map && data['success'] == true;
    } catch (_) {}
    return false;
  }
}
