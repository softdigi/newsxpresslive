import 'dart:convert';
import 'dart:io';
import 'package:http/http.dart' as http;

/// Generic exception wrapper for API errors.
class ApiException implements Exception {
  final String message;
  final int?   statusCode;
  const ApiException(this.message, {this.statusCode});

  @override
  String toString() => 'ApiException($statusCode): $message';
}

/// Low-level HTTP client for NewsXpressLive backend.
/// Wraps [http.Client] and provides uniform error handling.
///
/// Set [idTokenProvider] to attach a Firebase ID token as a Bearer header
/// on every request. The provider is called lazily before each request so
/// that refreshed tokens are always used.
class ApiService {
  ApiService({
    http.Client? client,
    this.idTokenProvider,
  }) : _client = client ?? http.Client();

  final http.Client _client;

  /// Optional async function that returns the current Firebase ID token.
  /// When set, an `Authorization: Bearer <token>` header is attached.
  final Future<String?> Function()? idTokenProvider;

  static const Duration _timeout = Duration(seconds: 15);

  // ── GET ────────────────────────────────────────────────────────────────
  Future<dynamic> get(String url, {Map<String, String>? queryParams}) async {
    Uri uri = Uri.parse(url);
    if (queryParams != null && queryParams.isNotEmpty) {
      uri = uri.replace(queryParameters: {
        ...uri.queryParameters,
        ...queryParams,
      });
    }
    try {
      final headers = await _buildHeaders();
      final response = await _client
          .get(uri, headers: headers)
          .timeout(_timeout);
      return _handleResponse(response);
    } on SocketException {
      throw const ApiException('No internet connection');
    } on HttpException {
      throw const ApiException('Network error');
    } on FormatException {
      throw const ApiException('Invalid server response');
    }
  }

  // ── POST (form-encoded) ────────────────────────────────────────────────
  Future<dynamic> post(String url, {Map<String, String>? body}) async {
    final uri = Uri.parse(url);
    try {
      final headers = await _buildHeaders();
      final response = await _client
          .post(uri, headers: headers, body: body)
          .timeout(_timeout);
      return _handleResponse(response);
    } on SocketException {
      throw const ApiException('No internet connection');
    } on HttpException {
      throw const ApiException('Network error');
    } on FormatException {
      throw const ApiException('Invalid server response');
    }
  }

  // ── POST (JSON body) ──────────────────────────────────────────────────
  Future<dynamic> postJson(String url,
      {Map<String, dynamic>? body}) async {
    final uri = Uri.parse(url);
    try {
      final headers = {
        ...await _buildHeaders(),
        'Content-Type': 'application/json',
      };
      final response = await _client
          .post(uri, headers: headers, body: jsonEncode(body ?? {}))
          .timeout(_timeout);
      return _handleResponse(response);
    } on SocketException {
      throw const ApiException('No internet connection');
    } on HttpException {
      throw const ApiException('Network error');
    } on FormatException {
      throw const ApiException('Invalid server response');
    }
  }

  // ── POST (multipart — for image upload) ───────────────────────────────
  Future<dynamic> postMultipart(
    String url, {
    Map<String, String>? fields,
    Map<String, String>? filePaths, // fieldName → localFilePath
  }) async {
    final uri = Uri.parse(url);
    try {
      final request = http.MultipartRequest('POST', uri);
      final token = await idTokenProvider?.call();
      if (token != null) {
        request.headers['Authorization'] = 'Bearer $token';
      }
      if (fields != null) request.fields.addAll(fields);
      if (filePaths != null) {
        for (final entry in filePaths.entries) {
          request.files.add(
            await http.MultipartFile.fromPath(entry.key, entry.value),
          );
        }
      }
      final streamed = await request.send().timeout(_timeout);
      final response = await http.Response.fromStream(streamed);
      return _handleResponse(response);
    } on SocketException {
      throw const ApiException('No internet connection');
    } on HttpException {
      throw const ApiException('Network error');
    } on FormatException {
      throw const ApiException('Invalid server response');
    }
  }

  // ── Internals ─────────────────────────────────────────────────────────
  Future<Map<String, String>> _buildHeaders() async {
    final base = <String, String>{
      'Accept': 'application/json',
      'Content-Type': 'application/x-www-form-urlencoded',
    };
    final token = await idTokenProvider?.call();
    if (token != null) {
      base['Authorization'] = 'Bearer $token';
    }
    return base;
  }

  dynamic _handleResponse(http.Response response) {
    final body = response.body.trim();

    // Handle 401 — caller should prompt re-authentication
    if (response.statusCode == 401) {
      throw const ApiException('Unauthorised', statusCode: 401);
    }

    if (response.statusCode >= 200 && response.statusCode < 300) {
      if (body.isEmpty) return null;
      return jsonDecode(body);
    }
    // Try to extract a server error message
    String errMsg = 'Server error (${response.statusCode})';
    try {
      final decoded = jsonDecode(body) as Map?;
      errMsg = (decoded?['message'] ?? decoded?['error'] ?? errMsg) as String;
    } catch (_) {}
    throw ApiException(errMsg, statusCode: response.statusCode);
  }

  void dispose() => _client.close();
}
