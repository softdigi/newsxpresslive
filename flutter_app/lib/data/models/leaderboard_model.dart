// flutter_app/lib/data/models/leaderboard_model.dart
// Reporter leaderboard entry model

class LeaderboardEntry {
  final int userId;
  final String reporterName;
  final String? avatarUrl;
  final String? location;
  final double credibilityScore;
  final double weeklyScore;
  final int? rank;
  final int articlesApproved;
  final int followersCount;
  final bool hasBlueTick;

  const LeaderboardEntry({
    required this.userId,
    required this.reporterName,
    this.avatarUrl,
    this.location,
    required this.credibilityScore,
    required this.weeklyScore,
    this.rank,
    required this.articlesApproved,
    required this.followersCount,
    required this.hasBlueTick,
  });

  factory LeaderboardEntry.fromJson(Map<String, dynamic> json) {
    return LeaderboardEntry(
      userId: int.tryParse(json['user_id'].toString()) ?? 0,
      reporterName: json['reporter_name'] ?? '',
      avatarUrl: json['avatar_url'],
      location: json['location'],
      credibilityScore: double.tryParse(json['credibility_score'].toString()) ?? 0,
      weeklyScore: double.tryParse(json['weekly_score'].toString()) ?? 0,
      rank: json['rank'] != null ? int.tryParse(json['rank'].toString()) : null,
      articlesApproved: int.tryParse(json['articles_approved'].toString()) ?? 0,
      followersCount: int.tryParse(json['followers_count'].toString()) ?? 0,
      hasBlueTick: json['blue_tick_bonus'] == 1 || json['blue_tick_bonus'] == true,
    );
  }
}

class MyRankData {
  final int? rank;
  final double score;
  final double credibilityScore;
  final double weeklyScore;
  final int articlesApproved;
  final int followersCount;

  const MyRankData({
    this.rank,
    required this.score,
    required this.credibilityScore,
    required this.weeklyScore,
    required this.articlesApproved,
    required this.followersCount,
  });

  factory MyRankData.fromJson(Map<String, dynamic> json) {
    return MyRankData(
      rank: json['rank'] != null ? int.tryParse(json['rank'].toString()) : null,
      score: double.tryParse(json['score'].toString()) ?? 0,
      credibilityScore: double.tryParse(json['credibility_score'].toString()) ?? 0,
      weeklyScore: double.tryParse(json['weekly_score'].toString()) ?? 0,
      articlesApproved: int.tryParse(json['articles_approved'].toString()) ?? 0,
      followersCount: int.tryParse(json['followers_count'].toString()) ?? 0,
    );
  }
}
