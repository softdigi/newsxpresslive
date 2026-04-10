import 'dart:math';
import '../models/live_model.dart';
import 'api_service.dart';
import '../../core/constants/api_endpoints.dart';

/// Service for live-streaming API calls.
class LiveService {
  LiveService({required ApiService api}) : _api = api;

  final ApiService _api;

  // ── Feed ──────────────────────────────────────────────────────────────────

  /// Returns live + scheduled streams (first page).
  /// Pass [status] to filter: 'live', 'scheduled', 'live,scheduled', 'ended', etc.
  Future<List<LiveStreamModel>> fetchFeed({
    String status = 'live,scheduled',
    int    limit  = 20,
    int    cursor = 0,
  }) async {
    try {
      final data = await _api.get(
        ApiEndpoints.liveFeed,
        queryParams: {
          'status': status,
          'limit' : limit.toString(),
          if (cursor > 0) 'cursor': cursor.toString(),
        },
      );
      if (data is Map<String, dynamic>) {
        final list = data['streams'] as List<dynamic>? ?? [];
        return list
            .map((e) => LiveStreamModel.fromJson(e as Map<String, dynamic>))
            .toList();
      }
    } catch (_) {}
    return [];
  }

  // ── Heartbeat ─────────────────────────────────────────────────────────────

  /// Ping the backend to register presence and fetch updated viewer count + chat.
  Future<HeartbeatData?> heartbeat({
    required int    streamId,
    required String sessionId,
    int?            userId,
  }) async {
    try {
      final data = await _api.postJson(
        ApiEndpoints.liveHeartbeat,
        body: {
          'stream_id' : streamId,
          'session_id': sessionId,
          if (userId != null) 'user_id': userId,
        },
      );
      if (data is Map<String, dynamic> && data['ok'] == true) {
        return HeartbeatData.fromJson(data);
      }
    } catch (_) {}
    return null;
  }

  // ── React ─────────────────────────────────────────────────────────────────

  /// Send an emoji reaction.
  Future<bool> react({
    required int    streamId,
    required String sessionId,
    String          emoji = '❤️',
  }) async {
    try {
      final data = await _api.postJson(
        ApiEndpoints.liveReact,
        body: {
          'stream_id' : streamId,
          'session_id': sessionId,
          'emoji'     : emoji,
        },
      );
      return data is Map<String, dynamic> && data['ok'] == true;
    } catch (_) {
      return false;
    }
  }

  // ── Chat ──────────────────────────────────────────────────────────────────

  /// Submit a chat message.
  Future<bool> sendChat({
    required int    streamId,
    required String sessionId,
    required String author,
    required String message,
    int?            userId,
  }) async {
    try {
      final data = await _api.postJson(
        ApiEndpoints.liveChat,
        body: {
          'stream_id' : streamId,
          'session_id': sessionId,
          'author'    : author,
          'message'   : message,
          if (userId != null) 'user_id': userId,
        },
      );
      return data is Map<String, dynamic> && data['ok'] == true;
    } catch (_) {
      return false;
    }
  }

  // ── Utility ───────────────────────────────────────────────────────────────

  /// Generate a client-side session ID (UUID v4 lite).
  static String generateSessionId() {
    final rng = Random.secure();
    final bytes = List<int>.generate(16, (_) => rng.nextInt(256));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    String hex(int b) => b.toRadixString(16).padLeft(2, '0');
    return '${hex(bytes[0])}${hex(bytes[1])}${hex(bytes[2])}${hex(bytes[3])}'
        '-${hex(bytes[4])}${hex(bytes[5])}'
        '-${hex(bytes[6])}${hex(bytes[7])}'
        '-${hex(bytes[8])}${hex(bytes[9])}'
        '-${hex(bytes[10])}${hex(bytes[11])}${hex(bytes[12])}${hex(bytes[13])}${hex(bytes[14])}${hex(bytes[15])}';
  }
}
