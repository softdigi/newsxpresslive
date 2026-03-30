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
class ApiService {
  ApiService({http.Client? client}) : _client = client ?? http.Client();

  final http.Client _client;

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
      final response = await _client
          .get(uri, headers: _headers)
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
      final response = await _client
          .post(uri, headers: _headers, body: body)
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

  // ── Internals ─────────────────────────────────────────────────────────
  Map<String, String> get _headers => {
    'Accept': 'application/json',
    'Content-Type': 'application/x-www-form-urlencoded',
  };

  dynamic _handleResponse(http.Response response) {
    final body = response.body.trim();
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
