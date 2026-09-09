/// One option inside a poll.
class PollOption {
  const PollOption({
    required this.index,
    required this.text,
    required this.votes,
    required this.pct,
  });

  final int    index;
  final String text;
  final int    votes;
  final double pct; // percentage 0–100

  factory PollOption.fromJson(Map<String, dynamic> json) => PollOption(
        index: _parseInt(json['index']),
        text:  json['text']  as String? ?? '',
        votes: _parseInt(json['votes']),
        pct:   _parseDouble(json['pct']),
      );

  static int    _parseInt(dynamic v) => v is int ? v : int.tryParse('$v') ?? 0;
  static double _parseDouble(dynamic v) =>
      v is double ? v : double.tryParse('$v') ?? 0.0;
}

/// A single news poll.
class PollModel {
  const PollModel({
    required this.id,
    this.newsId,
    required this.question,
    required this.options,
    required this.totalVotes,
    this.userVote,
    required this.isActive,
    this.endsAt,
    required this.createdAt,
  });

  final int          id;
  final int?         newsId;
  final String       question;
  final List<PollOption> options;
  final int          totalVotes;
  /// The option_index the authenticated user chose, or null.
  final int?         userVote;
  final bool         isActive;
  final String?      endsAt;
  final String       createdAt;

  bool get hasVoted => userVote != null;

  factory PollModel.fromJson(Map<String, dynamic> json) {
    final rawOpts = json['options'];
    final opts = rawOpts is List
        ? rawOpts
            .whereType<Map<String, dynamic>>()
            .map(PollOption.fromJson)
            .toList()
        : <PollOption>[];

    return PollModel(
      id:         _parseInt(json['id']),
      newsId:     json['news_id'] != null ? _parseInt(json['news_id']) : null,
      question:   json['question']   as String? ?? '',
      options:    opts,
      totalVotes: _parseInt(json['total_votes']),
      userVote:   json['user_vote'] != null ? _parseInt(json['user_vote']) : null,
      isActive:   _parseBool(json['is_active']),
      endsAt:     json['ends_at']    as String?,
      createdAt:  json['created_at'] as String? ?? '',
    );
  }

  /// Returns a copy with updated vote data after the user votes.
  PollModel withVote(int chosenIndex, List<int> newCounts) {
    final total = newCounts.fold<int>(0, (s, v) => s + v);
    final updatedOpts = options.map((o) {
      final v = newCounts.length > o.index ? newCounts[o.index] : o.votes;
      return PollOption(
        index: o.index,
        text:  o.text,
        votes: v,
        pct:   total > 0 ? v / total * 100 : 0.0,
      );
    }).toList();
    return PollModel(
      id:         id,
      newsId:     newsId,
      question:   question,
      options:    updatedOpts,
      totalVotes: total,
      userVote:   chosenIndex,
      isActive:   isActive,
      endsAt:     endsAt,
      createdAt:  createdAt,
    );
  }

  static int  _parseInt(dynamic v)  => v is int ? v : int.tryParse('$v') ?? 0;
  static bool _parseBool(dynamic v) =>
      v is bool ? v : (v is int ? v != 0 : '$v' == '1' || '$v' == 'true');
}
