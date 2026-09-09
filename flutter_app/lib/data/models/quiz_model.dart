/// One option in a quiz question.
class QuizOption {
  const QuizOption({required this.index, required this.text});

  final int    index;
  final String text;
}

/// A user's attempt at a daily quiz.
class QuizAttempt {
  const QuizAttempt({
    required this.selectedIndex,
    required this.isCorrect,
    required this.score,
  });

  final int  selectedIndex;
  final bool isCorrect;
  final int  score;

  factory QuizAttempt.fromJson(Map<String, dynamic> json) => QuizAttempt(
        selectedIndex: _parseInt(json['selected_index']),
        isCorrect:     _parseBool(json['is_correct']),
        score:         _parseInt(json['score']),
      );

  static int  _parseInt(dynamic v)  => v is int ? v : int.tryParse('$v') ?? 0;
  static bool _parseBool(dynamic v) =>
      v is bool ? v : (v is int ? v != 0 : '$v' == '1' || '$v' == 'true');
}

/// Today's daily quiz question.
class QuizModel {
  const QuizModel({
    required this.id,
    required this.question,
    required this.options,
    required this.quizDate,
    this.userAttempt,
    this.correctIndex,
    this.explanation,
  });

  final int          id;
  final String       question;
  final List<QuizOption> options;
  final String       quizDate;

  /// Non-null once the user has answered (or after GET reveals prior attempt).
  final QuizAttempt? userAttempt;

  /// Revealed only after the user has answered.
  final int?    correctIndex;
  final String? explanation;

  bool get hasAnswered  => userAttempt != null;
  bool get isAnsweredCorrectly =>
      hasAnswered && (userAttempt!.selectedIndex == correctIndex);

  factory QuizModel.fromJson(Map<String, dynamic> json) {
    final rawOpts = json['options'];
    final opts = rawOpts is List
        ? rawOpts
            .asMap()
            .entries
            .map((e) => QuizOption(index: e.key, text: e.value as String? ?? ''))
            .toList()
        : <QuizOption>[];

    QuizAttempt? attempt;
    if (json['user_attempt'] is Map<String, dynamic>) {
      attempt = QuizAttempt.fromJson(
        json['user_attempt'] as Map<String, dynamic>,
      );
    }

    return QuizModel(
      id:           _parseInt(json['id']),
      question:     json['question']   as String? ?? '',
      options:      opts,
      quizDate:     json['quiz_date']  as String? ?? '',
      userAttempt:  attempt,
      correctIndex: json['correct_index'] != null
          ? _parseInt(json['correct_index'])
          : null,
      explanation:  json['explanation'] as String?,
    );
  }

  /// Returns a copy after the user submits an answer.
  QuizModel withAnswer({
    required int  selectedIndex,
    required bool isCorrect,
    required int  score,
    required int  correctIndex,
    String?       explanation,
  }) =>
      QuizModel(
        id:           id,
        question:     question,
        options:      options,
        quizDate:     quizDate,
        userAttempt:  QuizAttempt(
          selectedIndex: selectedIndex,
          isCorrect:     isCorrect,
          score:         score,
        ),
        correctIndex: correctIndex,
        explanation:  explanation ?? this.explanation,
      );

  static int _parseInt(dynamic v) => v is int ? v : int.tryParse('$v') ?? 0;
}

/// A historical quiz attempt entry (for the history screen).
class QuizHistoryEntry {
  const QuizHistoryEntry({
    required this.quizId,
    required this.quizDate,
    required this.question,
    required this.selectedIndex,
    required this.correctIndex,
    required this.isCorrect,
    required this.score,
    required this.attemptedAt,
  });

  final int    quizId;
  final String quizDate;
  final String question;
  final int    selectedIndex;
  final int    correctIndex;
  final bool   isCorrect;
  final int    score;
  final String attemptedAt;

  factory QuizHistoryEntry.fromJson(Map<String, dynamic> json) =>
      QuizHistoryEntry(
        quizId:        _parseInt(json['quiz_id']),
        quizDate:      json['quiz_date']      as String? ?? '',
        question:      json['question']       as String? ?? '',
        selectedIndex: _parseInt(json['selected_index']),
        correctIndex:  _parseInt(json['correct_index']),
        isCorrect:     _parseBool(json['is_correct']),
        score:         _parseInt(json['score']),
        attemptedAt:   json['attempted_at']   as String? ?? '',
      );

  static int  _parseInt(dynamic v)  => v is int ? v : int.tryParse('$v') ?? 0;
  static bool _parseBool(dynamic v) =>
      v is bool ? v : (v is int ? v != 0 : '$v' == '1' || '$v' == 'true');
}
