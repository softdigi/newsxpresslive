import 'dart:async';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'dart:convert';
import '../core/constants/app_colors.dart';
import '../presentation/screens/cricket/cricket_screen.dart';

class CricketMatch {
  final String matchId;
  final String? series;
  final String team1;
  final String team2;
  final String? score1;
  final String? score2;
  final String status;
  final String? statusText;
  final bool isLive;

  const CricketMatch({
    required this.matchId,
    this.series,
    required this.team1,
    required this.team2,
    this.score1,
    this.score2,
    required this.status,
    this.statusText,
    required this.isLive,
  });

  factory CricketMatch.fromJson(Map<String, dynamic> j) => CricketMatch(
        matchId: j['match_id'] ?? '',
        series: j['series'] as String?,
        team1: j['team1'] ?? '',
        team2: j['team2'] ?? '',
        score1: j['score1'] as String?,
        score2: j['score2'] as String?,
        status: j['status'] ?? 'upcoming',
        statusText: j['status_text'] as String?,
        isLive: j['is_live'] == true,
      );
}

/// Floating cricket score banner shown at top of HomeScreen when a match is live.
class CricketScoreWidget extends StatefulWidget {
  const CricketScoreWidget({super.key});

  @override
  State<CricketScoreWidget> createState() => _CricketScoreWidgetState();
}

class _CricketScoreWidgetState extends State<CricketScoreWidget> {
  List<CricketMatch> _matches = [];
  bool _dismissed = false;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _fetch();
    // Auto-refresh every 2 minutes
    _timer = Timer.periodic(const Duration(minutes: 2), (_) => _fetch());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _fetch() async {
    try {
      final res = await http
          .get(Uri.parse(
              'https://newsxpresslive.com/web/api/cricket/live.php'))
          .timeout(const Duration(seconds: 10));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body) as Map<String, dynamic>;
        final list = (data['matches'] as List? ?? [])
            .map((m) => CricketMatch.fromJson(m as Map<String, dynamic>))
            .toList();
        if (mounted) {
          setState(() {
            _matches = list;
            // Auto-unhide if a live match appears
            if (list.any((m) => m.isLive)) _dismissed = false;
          });
        }
      }
    } catch (_) {
      // Network errors are silently ignored for the widget
    }
  }

  @override
  Widget build(BuildContext context) {
    final live = _matches.where((m) => m.isLive).toList();
    if (live.isEmpty || _dismissed) return const SizedBox.shrink();

    final match = live.first;
    return GestureDetector(
      onTap: () => Navigator.push(
        context,
        MaterialPageRoute(builder: (_) => const CricketScreen()),
      ),
      child: Container(
        margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        decoration: BoxDecoration(
          color: const Color(0xFF0A3D21),
          borderRadius: BorderRadius.circular(12),
          boxShadow: [
            BoxShadow(
                color: Colors.black.withAlpha(50),
                blurRadius: 8,
                offset: const Offset(0, 2)),
          ],
        ),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Text('🏏', style: TextStyle(fontSize: 16)),
                const SizedBox(width: 6),
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                  decoration: BoxDecoration(
                      color: AppColors.primary,
                      borderRadius: BorderRadius.circular(4)),
                  child: const Text('LIVE',
                      style: TextStyle(
                          color: Colors.white,
                          fontSize: 10,
                          fontWeight: FontWeight.bold)),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    '${match.team1} vs ${match.team2}',
                    style: const TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.bold,
                        fontSize: 14),
                  ),
                ),
                GestureDetector(
                  onTap: () => setState(() => _dismissed = true),
                  child: const Icon(Icons.close, color: Colors.white54, size: 18),
                ),
              ],
            ),
            if (match.score1 != null || match.score2 != null) ...[
              const SizedBox(height: 4),
              Text(
                '${match.team1}: ${match.score1 ?? "Yet to bat"}',
                style: const TextStyle(color: Colors.white70, fontSize: 13),
              ),
              Text(
                '${match.team2}: ${match.score2 ?? "Yet to bat"}',
                style: const TextStyle(color: Colors.white70, fontSize: 13),
              ),
            ],
            if (match.statusText != null) ...[
              const SizedBox(height: 4),
              Text(
                match.statusText!,
                style: const TextStyle(
                    color: AppColors.accent, fontSize: 12),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
