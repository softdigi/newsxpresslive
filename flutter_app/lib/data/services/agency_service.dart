import '../models/agency_models.dart';
import 'api_service.dart';
import '../../core/constants/api_endpoints.dart';

/// Service for all Agency Partner API calls.
/// Attach credentials via [setCredentials] after login.
class AgencyService {
  AgencyService(this._api);

  final ApiService _api;

  String _apiKey = '';
  String _apiSecret = '';

  void setCredentials(String key, String secret) {
    _apiKey = key;
    _apiSecret = secret;
  }

  Map<String, String> get _authHeaders => {
        'X-Agency-Key': _apiKey,
        'X-Agency-Secret': _apiSecret,
      };

  // ── Auth ──────────────────────────────────────────────────────────────

  Future<Map<String, dynamic>> login(String email, String password) async {
    final res = await _api.postJson(
      ApiEndpoints.agencyLogin,
      body: {'email': email, 'password': password, 'action': 'login'},
    );
    final data = res as Map<String, dynamic>;
    return {
      'apiKey': data['api_key'] ?? '',
      'apiSecret': data['api_secret'] ?? '',
      'agencyId': data['agency_id'] ?? 0,
    };
  }

  // ── Profile ───────────────────────────────────────────────────────────

  Future<AgencyProfile> getProfile() async {
    final res = await _api.get(
      '${ApiEndpoints.agencyBase}/profile.php',
      queryParams: _authHeaders,
    );
    return AgencyProfile.fromJson(res as Map<String, dynamic>);
  }

  // ── Dashboard ─────────────────────────────────────────────────────────

  Future<Map<String, dynamic>> getDashboard() async {
    final res = await _api.get(
      '${ApiEndpoints.agencyBase}/dashboard.php',
      queryParams: _authHeaders,
    );
    final data = res as Map<String, dynamic>;
    final profile = AgencyProfile.fromJson(
        data['profile'] as Map<String, dynamic>? ?? {});
    final recentList =
        (data['recent_articles'] as List<dynamic>? ?? [])
            .map((e) => AgencyArticle.fromJson(e as Map<String, dynamic>))
            .toList();
    return {'profile': profile, 'recentArticles': recentList};
  }

  // ── Articles ──────────────────────────────────────────────────────────

  Future<List<AgencyArticle>> getArticles({
    String? status,
    int page = 1,
    String? search,
  }) async {
    final params = {
      ..._authHeaders,
      'page': page.toString(),
      if (status != null && status.isNotEmpty) 'status': status,
      if (search != null && search.isNotEmpty) 'search': search,
    };
    final res = await _api.get(ApiEndpoints.agencyArticles,
        queryParams: params);
    final list = (res as List<dynamic>? ?? []);
    return list
        .map((e) => AgencyArticle.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<bool> submitArticle(Map<String, dynamic> data) async {
    final body = {
      ..._authHeaders,
      for (final e in data.entries) e.key: e.value.toString(),
    };
    final res = await _api.post(ApiEndpoints.agencySubmit, body: body);
    return (res as Map?)?['success'] == true;
  }

  // ── Bulk upload ───────────────────────────────────────────────────────

  Future<Map<String, dynamic>> bulkUploadCsv(String filePath) async {
    final res = await _api.postMultipart(
      ApiEndpoints.agencyCsvUpload,
      fields: _authHeaders,
      filePaths: {'csv_file': filePath},
    );
    final data = res as Map<String, dynamic>? ?? {};
    return {
      'uploadId': data['upload_id'] ?? '',
      'total': data['total'] ?? 0,
      'validCount': data['valid_count'] ?? 0,
      'invalidCount': data['invalid_count'] ?? 0,
    };
  }

  Future<Map<String, dynamic>> getUploadStatus(String uploadId) async {
    final res = await _api.get(
      '${ApiEndpoints.agencyBase}/upload_status.php',
      queryParams: {..._authHeaders, 'upload_id': uploadId},
    );
    return res as Map<String, dynamic>? ?? {};
  }

  // ── Revenue ───────────────────────────────────────────────────────────

  Future<AgencyRevenueSummary> getRevenue({String? from, String? to}) async {
    final params = {
      ..._authHeaders,
      if (from != null) 'from': from,
      if (to != null) 'to': to,
    };
    final res = await _api.get(ApiEndpoints.agencyRevenue, queryParams: params);
    return AgencyRevenueSummary.fromJson(res as Map<String, dynamic>);
  }

  // ── Wallet ────────────────────────────────────────────────────────────

  Future<List<AgencyTransaction>> getTransactions(
      {int page = 1, String? type}) async {
    final params = {
      ..._authHeaders,
      'page': page.toString(),
      if (type != null && type.isNotEmpty) 'type': type,
    };
    final res = await _api.get(
      '${ApiEndpoints.agencyBase}/transactions.php',
      queryParams: params,
    );
    final list = res as List<dynamic>? ?? [];
    return list
        .map((e) => AgencyTransaction.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<List<AgencyWithdrawal>> getWithdrawals({int page = 1}) async {
    final res = await _api.get(
      ApiEndpoints.agencyWithdraw,
      queryParams: {..._authHeaders, 'page': page.toString()},
    );
    final list = res as List<dynamic>? ?? [];
    return list
        .map((e) => AgencyWithdrawal.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<bool> requestWithdrawal(
      double amount, String method, String accountDetails) async {
    final res = await _api.post(
      ApiEndpoints.agencyWithdraw,
      body: {
        ..._authHeaders,
        'action': 'request',
        'amount': amount.toString(),
        'method': method,
        'account_details': accountDetails,
      },
    );
    return (res as Map?)?['success'] == true;
  }

  // ── API Keys ──────────────────────────────────────────────────────────

  Future<Map<String, dynamic>> getApiKeys() async {
    final res = await _api.get(
      '${ApiEndpoints.agencyBase}/api_keys.php',
      queryParams: _authHeaders,
    );
    final data = res as Map<String, dynamic>? ?? {};
    return {
      'apiKey': data['api_key'] ?? '',
      'apiSecret': data['api_secret'] ?? '',
    };
  }

  Future<Map<String, dynamic>> rotateApiKeys() async {
    final res = await _api.post(
      '${ApiEndpoints.agencyBase}/api_keys.php',
      body: {..._authHeaders, 'action': 'rotate'},
    );
    final data = res as Map<String, dynamic>? ?? {};
    return {
      'apiKey': data['api_key'] ?? '',
      'apiSecret': data['api_secret'] ?? '',
    };
  }
}
