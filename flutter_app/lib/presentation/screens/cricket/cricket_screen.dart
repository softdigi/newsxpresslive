import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'dart:async';
import 'dart:convert';
import '../../../core/constants/app_colors.dart';
import '../../../widgets/cricket_score_widget.dart';

/// Full cricket scorecard screen.
class CricketScreen extends StatefulWidget {
  const CricketScreen({super.key});

  @override
  State<CricketScreen> createState() => _CricketScreenState();
}

class _CricketScreenState extends State<CricketScreen> {
  List<CricketMatch> _matches = [];
  bool _loading = true;
  String? _error;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _fetch();
    _timer = Timer.periodic(const Duration(minutes: 2), (_) => _fetch());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _fetch() async {
    if (!_loading) setState(() => _loading = true);
    try {
      final res = await http
          .get(Uri.parse(
              'https://newsxpresslive.com/web/api/cricket/live.php'))
          .timeout(const Duration(seconds: 15));
      final data = jsonDecode(res.body) as Map<String, dynamic>;
      if (data['success'] == true) {
        setState(() {
          _matches = (data['matches'] as List? ?? [])
              .map((m) => CricketMatch.fromJson(m as Map<String, dynamic>))
              .toList();
          _error = null;
        });
      } else {
        setState(() => _error = 'Unable to load matches');
      }
    } catch (e) {
      setState(() => _error = 'Network error. Please try again.');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Cricket Scores'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh_rounded),
            onPressed: _fetch,
          ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.sports_cricket,
                          size: 48, color: Colors.grey),
                      const SizedBox(height: 12),
                      Text(_error!),
                      const SizedBox(height: 16),
                      ElevatedButton(
                          onPressed: _fetch, child: const Text('Retry')),
                    ],
                  ),
                )
              : _matches.isEmpty
                  ? const Center(
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text('🏏', style: TextStyle(fontSize: 48)),
                          SizedBox(height: 12),
                          Text('No live or upcoming matches right now.'),
                        ],
                      ),
                    )
                  : RefreshIndicator(
                      onRefresh: _fetch,
                      child: ListView.builder(
                        padding: const EdgeInsets.all(12),
                        itemCount: _matches.length,
                        itemBuilder: (ctx, i) =>
                            _MatchCard(match: _matches[i]),
                      ),
                    ),
    );
  }
}

class _MatchCard extends StatelessWidget {
  final CricketMatch match;
  const _MatchCard({required this.match});

  Color get _statusColor {
    switch (match.status) {
      case 'live':
        return AppColors.primary;
      case 'upcoming':
        return AppColors.accent;
      default:
        return Colors.grey;
    }
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: match.isLive
              ? const BorderSide(color: AppColors.primary, width: 1.5)
              : BorderSide.none),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Series + status badge
            Row(
              children: [
                if (match.series != null)
                  Expanded(
                    child: Text(match.series!,
                        style: TextStyle(
                            fontSize: 11, color: Colors.grey.shade500),
                        overflow: TextOverflow.ellipsis),
                  ),
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                  decoration: BoxDecoration(
                      color: _statusColor.withAlpha(30),
                      borderRadius: BorderRadius.circular(4)),
                  child: Text(
                    match.status.toUpperCase(),
                    style: TextStyle(
                        fontSize: 10,
                        color: _statusColor,
                        fontWeight: FontWeight.bold),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),

            // Teams + scores
            _TeamRow(team: match.team1, score: match.score1),
            const SizedBox(height: 6),
            _TeamRow(team: match.team2, score: match.score2),

            // Status text
            if (match.statusText != null) ...[
              const Divider(height: 16),
              Text(
                match.statusText!,
                style: TextStyle(
                    fontSize: 13,
                    color: match.isLive
                        ? AppColors.accent
                        : Colors.grey.shade500,
                    fontStyle: match.isLive
                        ? FontStyle.normal
                        : FontStyle.italic),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _TeamRow extends StatelessWidget {
  final String team;
  final String? score;
  const _TeamRow({required this.team, this.score});

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        SizedBox(
          width: 50,
          child: Text(team,
              style: const TextStyle(
                  fontSize: 16, fontWeight: FontWeight.bold)),
        ),
        const SizedBox(width: 12),
        if (score != null)
          Text(score!,
              style: const TextStyle(fontSize: 14, color: Colors.grey)),
        if (score == null)
          const Text('Yet to bat',
              style: TextStyle(fontSize: 13, color: Colors.grey)),
      ],
    );
  }
}
