import '../models/poll_model.dart';
import '../models/quiz_model.dart';
import 'api_service.dart';
import '../../core/constants/api_endpoints.dart';

/// Service for Polls and Daily Quiz API calls.
///
/// Pass an [ApiService] with [idTokenProvider] set for endpoints that require
/// Firebase auth (vote, quiz submit).
class PollService {
  PollService({ApiService? api}) : _api = api ?? ApiService();

  final ApiService _api;

  // ── Polls ─────────────────────────────────────────────────────────────────

  /// Fetch polls attached to a specific article [newsId].
  Future<List<PollModel>> getPollsForArticle(int newsId,
      {String? firebaseUid}) async {
    try {
      final params = <String, String>{'news_id': '$newsId'};
      if (firebaseUid != null) params['firebase_uid'] = firebaseUid;
      final data = await _api.get(ApiEndpoints.polls, queryParams: params);
      if (data is! Map<String, dynamic>) return [];
      final list = data['polls'];
      if (list is! List) return [];
      return list
          .whereType<Map<String, dynamic>>()
          .map(PollModel.fromJson)
          .toList();
    } catch (_) {
      return [];
    }
  }

  /// Fetch a single poll by its [pollId].
  Future<PollModel?> getPoll(int pollId, {String? firebaseUid}) async {
    try {
      final params = <String, String>{'poll_id': '$pollId'};
      if (firebaseUid != null) params['firebase_uid'] = firebaseUid;
      final data = await _api.get(ApiEndpoints.polls, queryParams: params);
      if (data is! Map<String, dynamic>) return null;
      final poll = data['poll'];
      if (poll is! Map<String, dynamic>) return null;
      return PollModel.fromJson(poll);
    } catch (_) {
      return null;
    }
  }

  /// Fetch a paginated list of active polls.
  Future<List<PollModel>> listPolls({
    int page = 1,
    int per  = 10,
    String? firebaseUid,
  }) async {
    try {
      final params = <String, String>{
        'list': '1',
        'page': '$page',
        'per':  '$per',
      };
      if (firebaseUid != null) params['firebase_uid'] = firebaseUid;
      final data = await _api.get(ApiEndpoints.polls, queryParams: params);
      if (data is! Map<String, dynamic>) return [];
      final list = data['polls'];
      if (list is! List) return [];
      return list
          .whereType<Map<String, dynamic>>()
          .map(PollModel.fromJson)
          .toList();
    } catch (_) {
      return [];
    }
  }

  /// Cast (or update) a vote on [pollId].
  ///
  /// Returns the updated [PollModel] on success, or null on failure.
  Future<PollModel?> vote({
    required int    pollId,
    required int    optionIndex,
    required String firebaseUid,
  }) async {
    try {
      final data = await _api.post(ApiEndpoints.pollVote, body: {
        'poll_id':      pollId,
        'option_index': optionIndex,
        'firebase_uid': firebaseUid,
      });
      if (data is! Map<String, dynamic> || data['success'] != true) return null;
      // Re-fetch to get full model with updated counts
      final updated = await getPoll(pollId, firebaseUid: firebaseUid);
      if (updated != null) return updated;
      // Fallback: return null
      return null;
    } catch (_) {
      return null;
    }
  }

  // ── Daily Quiz ────────────────────────────────────────────────────────────

  /// Fetch today's quiz. Pass [firebaseUid] to also receive the user's
  /// previous attempt (if any) and the answer reveal.
  Future<QuizModel?> getTodayQuiz({String? firebaseUid}) async {
    try {
      final params = <String, String>{};
      if (firebaseUid != null) params['firebase_uid'] = firebaseUid;
      final data = await _api.get(ApiEndpoints.quiz, queryParams: params);
      if (data is! Map<String, dynamic>) return null;
      final quiz = data['quiz'];
      if (quiz is! Map<String, dynamic>) return null;
      return QuizModel.fromJson(quiz);
    } catch (_) {
      return null;
    }
  }

  /// Submit a quiz answer. Returns the result map on success or null.
  ///
  /// Result keys: success, is_correct, score, correct_index, explanation
  Future<Map<String, dynamic>?> submitQuizAnswer({
    required int    quizId,
    required int    selectedIndex,
    required String firebaseUid,
  }) async {
    try {
      final data = await _api.post(ApiEndpoints.quizSubmit, body: {
        'quiz_id':        quizId,
        'selected_index': selectedIndex,
        'firebase_uid':   firebaseUid,
      });
      if (data is! Map<String, dynamic>) return null;
      return data;
    } catch (_) {
      return null;
    }
  }

  /// Fetch the user's quiz history (last 30 entries).
  Future<List<QuizHistoryEntry>> getQuizHistory(String firebaseUid) async {
    try {
      final data = await _api.get(ApiEndpoints.quiz, queryParams: {
        'history':      '1',
        'firebase_uid': firebaseUid,
      });
      if (data is! Map<String, dynamic>) return [];
      final list = data['history'];
      if (list is! List) return [];
      return list
          .whereType<Map<String, dynamic>>()
          .map(QuizHistoryEntry.fromJson)
          .toList();
    } catch (_) {
      return [];
    }
  }
}
