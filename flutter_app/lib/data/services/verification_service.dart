import 'dart:io';
import 'package:http/http.dart' as http;
import 'dart:convert';

import '../../core/constants/api_endpoints.dart';
import '../models/blue_tick_model.dart';

/// Service for Blue Tick Verification — document submission, plan listing,
/// purchasing, and agency tick assignment.
class VerificationService {
  final String _idToken;

  VerificationService(this._idToken);

  Map<String, String> get _authHeaders => {
    'Authorization': 'Bearer $_idToken',
    'Content-Type': 'application/json',
  };

  // ── Media channels ────────────────────────────────────────────────────────

  /// Fetch active media channels for the verification form dropdown.
  Future<List<MediaChannel>> fetchChannels({
    String type = '',
    String q    = '',
    int page    = 1,
  }) async {
    final uri = Uri.parse(ApiEndpoints.verificationChannels).replace(
      queryParameters: {
        if (type.isNotEmpty) 'type': type,
        if (q.isNotEmpty)    'q':    q,
        'page':     page.toString(),
        'per_page': '100',
      },
    );
    final res = await http.get(uri);
    final body = jsonDecode(res.body) as Map<String, dynamic>;
    if (body['success'] != true) {
      throw Exception(body['message'] ?? 'Failed to load channels');
    }
    return (body['channels'] as List)
        .map((e) => MediaChannel.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  // ── Verification status ───────────────────────────────────────────────────

  Future<UserVerificationInfo> fetchStatus() async {
    final res = await http.get(
      Uri.parse(ApiEndpoints.verificationStatus),
      headers: _authHeaders,
    );
    final body = jsonDecode(res.body) as Map<String, dynamic>;
    if (body['success'] != true) {
      throw Exception(body['message'] ?? 'Failed to load status');
    }
    return UserVerificationInfo.fromJson(body);
  }

  // ── Submit reporter verification ──────────────────────────────────────────

  /// Submit reporter KYC documents.
  /// [aadharDoc] – required: File pointing to the Aadhar image/PDF.
  /// [panDoc]    – optional.
  Future<Map<String, dynamic>> submitReporterVerification({
    required String aadharNumber,
    String?         panNumber,
    int?            channelId,
    String?         channelName,
    required File   aadharDoc,
    File?           panDoc,
  }) async {
    final req = http.MultipartRequest(
      'POST',
      Uri.parse(ApiEndpoints.verificationSubmit),
    );
    req.headers['Authorization'] = 'Bearer $_idToken';
    req.fields['account_type']   = 'reporter';
    req.fields['aadhar_number']  = aadharNumber;
    if (panNumber != null)   req.fields['pan_number']   = panNumber;
    if (channelId != null)   req.fields['channel_id']   = channelId.toString();
    if (channelName != null) req.fields['channel_name'] = channelName;

    req.files.add(await http.MultipartFile.fromPath('aadhar_doc', aadharDoc.path));
    if (panDoc != null) {
      req.files.add(await http.MultipartFile.fromPath('pan_doc', panDoc.path));
    }

    final streamed = await req.send();
    final res      = await http.Response.fromStream(streamed);
    final body     = jsonDecode(res.body) as Map<String, dynamic>;
    if (body['success'] != true) {
      throw Exception(body['message'] ?? 'Submission failed');
    }
    return body;
  }

  // ── Submit agency verification ────────────────────────────────────────────

  Future<Map<String, dynamic>> submitAgencyVerification({
    required String businessName,
    required String contactName,
    required String contactPhone,
    String?         msmeNumber,
    String?         website,
    int?            reporterCount,
    File?           msmeDoc,
    File?           legalDoc,
  }) async {
    final req = http.MultipartRequest(
      'POST',
      Uri.parse(ApiEndpoints.verificationSubmit),
    );
    req.headers['Authorization']    = 'Bearer $_idToken';
    req.fields['account_type']      = 'agency';
    req.fields['business_name']     = businessName;
    req.fields['contact_name']      = contactName;
    req.fields['contact_phone']     = contactPhone;
    if (msmeNumber    != null) req.fields['msme_number']    = msmeNumber;
    if (website       != null) req.fields['website']        = website;
    if (reporterCount != null) req.fields['reporter_count'] = reporterCount.toString();

    if (msmeDoc  != null) req.files.add(await http.MultipartFile.fromPath('msme_doc',  msmeDoc.path));
    if (legalDoc != null) req.files.add(await http.MultipartFile.fromPath('legal_doc', legalDoc.path));

    final streamed = await req.send();
    final res      = await http.Response.fromStream(streamed);
    final body     = jsonDecode(res.body) as Map<String, dynamic>;
    if (body['success'] != true) {
      throw Exception(body['message'] ?? 'Submission failed');
    }
    return body;
  }

  // ── Blue-tick plans ───────────────────────────────────────────────────────

  Future<Map<String, dynamic>> fetchPlans({String type = ''}) async {
    final uri = Uri.parse(ApiEndpoints.blueTickPlans).replace(
      queryParameters: {if (type.isNotEmpty) 'type': type},
    );
    final res  = await http.get(uri);
    final body = jsonDecode(res.body) as Map<String, dynamic>;
    if (body['success'] != true) {
      throw Exception(body['message'] ?? 'Failed to load plans');
    }
    final plans = (body['plans'] as List)
        .map((e) => BlueTickPlan.fromJson(e as Map<String, dynamic>))
        .toList();
    final eb = body['early_bird'] as Map<String, dynamic>;
    return {
      'plans':        plans,
      'early_bird': {
        'reporter': EarlyBirdInfo.fromJson(eb['reporter'] as Map<String, dynamic>),
        'agency':   EarlyBirdInfo.fromJson(eb['agency']   as Map<String, dynamic>),
      },
    };
  }

  // ── Purchase / request a plan ─────────────────────────────────────────────

  Future<Map<String, dynamic>> purchasePlan({
    required int    planId,
    String?         paymentId,
    String?         orderId,
  }) async {
    final res = await http.post(
      Uri.parse(ApiEndpoints.blueTickPurchase),
      headers: _authHeaders,
      body: jsonEncode({
        'plan_id': planId,
        if (paymentId != null) 'payment_id': paymentId,
        if (orderId   != null) 'order_id':   orderId,
      }),
    );
    final body = jsonDecode(res.body) as Map<String, dynamic>;
    // Note: payment_required=true is not a hard error — caller handles it
    if (body['success'] != true && body['payment_required'] != true) {
      throw Exception(body['message'] ?? 'Purchase failed');
    }
    return body;
  }

  // ── Agency tick assignment ────────────────────────────────────────────────

  Future<List<BlueTickAssignment>> fetchAssignments() async {
    final res = await http.get(
      Uri.parse(ApiEndpoints.blueTickAssign),
      headers: _authHeaders,
    );
    final body = jsonDecode(res.body) as Map<String, dynamic>;
    if (body['success'] != true) {
      throw Exception(body['message'] ?? 'Failed to load assignments');
    }
    return (body['assignments'] as List)
        .map((e) => BlueTickAssignment.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<Map<String, dynamic>> assignTick(String reporterUid) async {
    final res = await http.post(
      Uri.parse(ApiEndpoints.blueTickAssign),
      headers: _authHeaders,
      body: jsonEncode({'reporter_uid': reporterUid, 'action': 'assign'}),
    );
    return jsonDecode(res.body) as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>> revokeTick(String reporterUid) async {
    final res = await http.post(
      Uri.parse(ApiEndpoints.blueTickAssign),
      headers: _authHeaders,
      body: jsonEncode({'reporter_uid': reporterUid, 'action': 'revoke'}),
    );
    final body = jsonDecode(res.body) as Map<String, dynamic>;
    if (body['success'] != true) {
      throw Exception(body['message'] ?? 'Revoke failed');
    }
    return body;
  }

  // ── Profile ───────────────────────────────────────────────────────────────

  Future<Map<String, dynamic>> fetchProfile() async {
    final res = await http.get(
      Uri.parse(ApiEndpoints.blueTickProfile),
      headers: _authHeaders,
    );
    final body = jsonDecode(res.body) as Map<String, dynamic>;
    if (body['success'] != true) {
      throw Exception(body['message'] ?? 'Failed to load profile');
    }
    return body;
  }

  Future<Map<String, dynamic>> updateProfile(Map<String, dynamic> fields) async {
    final res = await http.post(
      Uri.parse(ApiEndpoints.blueTickProfile),
      headers: _authHeaders,
      body: jsonEncode(fields),
    );
    final body = jsonDecode(res.body) as Map<String, dynamic>;
    if (body['success'] != true) {
      throw Exception(body['message'] ?? 'Update failed');
    }
    return body;
  }
}
