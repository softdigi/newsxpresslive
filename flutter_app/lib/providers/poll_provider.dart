import 'package:flutter/foundation.dart';
import '../data/models/poll_model.dart';
import '../data/models/quiz_model.dart';
import '../data/services/poll_service.dart';

enum QuizStatus { idle, loading, loaded, submitting, error }

/// Manages state for polls (per-article) and the daily quiz.
///
/// Polls are keyed by article ID for easy lookup.
/// Quiz state is global (one quiz per day).
class PollProvider extends ChangeNotifier {
  PollProvider({PollService? service})
      : _svc = service ?? PollService();

  final PollService _svc;

  // ── Per-article polls ─────────────────────────────────────────────────

  /// Map from news_id → list of polls for that article.
  final Map<int, List<PollModel>> _articlePolls = {};

  /// Tracks which news_ids are currently loading.
  final Set<int> _loadingPolls = {};

  List<PollModel> pollsFor(int newsId) =>
      List.unmodifiable(_articlePolls[newsId] ?? []);

  bool isLoadingPolls(int newsId) => _loadingPolls.contains(newsId);

  /// Load (or reload) polls for the given [newsId].
  Future<void> loadPollsForArticle(int newsId, {String? firebaseUid}) async {
    if (_loadingPolls.contains(newsId)) return;
    _loadingPolls.add(newsId);
    notifyListeners();
    try {
      final polls = await _svc.getPollsForArticle(
        newsId,
        firebaseUid: firebaseUid,
      );
      _articlePolls[newsId] = polls;
    } catch (_) {
      _articlePolls.putIfAbsent(newsId, () => []);
    } finally {
      _loadingPolls.remove(newsId);
      notifyListeners();
    }
  }

  /// Cast a vote on [pollId] for the authenticated user [firebaseUid].
  ///
  /// Optimistically updates the local poll model; reverts on failure.
  /// Returns true on success.
  Future<bool> vote({
    required int    pollId,
    required int    optionIndex,
    required int    newsId,
    required String firebaseUid,
  }) async {
    final polls = _articlePolls[newsId];
    if (polls == null) return false;
    final idx = polls.indexWhere((p) => p.id == pollId);
    if (idx == -1) return false;

    final original = polls[idx];

    // Optimistic update: increment chosen option
    final optimisticCounts = original.options.map((o) {
      var v = o.votes;
      if (o.index == optionIndex) v++;
      if (original.hasVoted && o.index == original.userVote) v = (v - 1).clamp(0, 9999);
      return v;
    }).toList();
    polls[idx] = original.withVote(optionIndex, optimisticCounts);
    notifyListeners();

    final updated = await _svc.vote(
      pollId:      pollId,
      optionIndex: optionIndex,
      firebaseUid: firebaseUid,
    );
    if (updated != null) {
      polls[idx] = updated;
    } else {
      // Revert
      polls[idx] = original;
    }
    notifyListeners();
    return updated != null;
  }

  // ── Daily Quiz ────────────────────────────────────────────────────────

  QuizStatus _quizStatus  = QuizStatus.idle;
  QuizModel? _quiz;
  String?    _quizError;

  QuizStatus get quizStatus => _quizStatus;
  QuizModel? get quiz       => _quiz;
  String?    get quizError  => _quizError;

  bool get quizLoading    => _quizStatus == QuizStatus.loading;
  bool get quizSubmitting => _quizStatus == QuizStatus.submitting;

  Future<void> loadQuiz({String? firebaseUid}) async {
    if (_quizStatus == QuizStatus.loading) return;
    _quizStatus = QuizStatus.loading;
    _quizError  = null;
    notifyListeners();
    try {
      _quiz       = await _svc.getTodayQuiz(firebaseUid: firebaseUid);
      _quizStatus = QuizStatus.loaded;
    } catch (e) {
      _quizError  = e.toString();
      _quizStatus = QuizStatus.error;
    }
    notifyListeners();
  }

  /// Submit the user's answer to the current quiz.
  ///
  /// Updates [_quiz] with the result and returns the response map or null.
  Future<Map<String, dynamic>?> submitAnswer({
    required int    selectedIndex,
    required String firebaseUid,
  }) async {
    if (_quiz == null) return null;
    if (_quizStatus == QuizStatus.submitting) return null;
    _quizStatus = QuizStatus.submitting;
    notifyListeners();

    final result = await _svc.submitQuizAnswer(
      quizId:        _quiz!.id,
      selectedIndex: selectedIndex,
      firebaseUid:   firebaseUid,
    );
    if (result != null) {
      final isCorrect    = result['is_correct'] as bool? ?? false;
      final score        = result['score'] is int ? result['score'] as int : 0;
      final correctIndex = result['correct_index'] is int
          ? result['correct_index'] as int
          : 0;
      final explanation  = result['explanation'] as String?;
      _quiz = _quiz!.withAnswer(
        selectedIndex: selectedIndex,
        isCorrect:     isCorrect,
        score:         score,
        correctIndex:  correctIndex,
        explanation:   explanation,
      );
    }
    _quizStatus = QuizStatus.loaded;
    notifyListeners();
    return result;
  }

  // ── History ───────────────────────────────────────────────────────────

  List<QuizHistoryEntry> _history = [];
  bool _historyLoading = false;

  List<QuizHistoryEntry> get history        => List.unmodifiable(_history);
  bool                   get historyLoading => _historyLoading;

  Future<void> loadHistory(String firebaseUid) async {
    if (_historyLoading) return;
    _historyLoading = true;
    notifyListeners();
    try {
      _history = await _svc.getQuizHistory(firebaseUid);
    } catch (_) {}
    _historyLoading = false;
    notifyListeners();
  }
}
