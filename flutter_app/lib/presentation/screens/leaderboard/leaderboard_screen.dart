// flutter_app/lib/presentation/screens/leaderboard/leaderboard_screen.dart

import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:http/http.dart' as http;
import '../../../core/constants/api_endpoints.dart';
import '../../../core/theme/app_theme.dart';
import '../../../data/models/leaderboard_model.dart';

class LeaderboardScreen extends StatefulWidget {
  const LeaderboardScreen({super.key});

  @override
  State<LeaderboardScreen> createState() => _LeaderboardScreenState();
}

class _LeaderboardScreenState extends State<LeaderboardScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;

  List<LeaderboardEntry> _reporters = [];
  MyRankData? _myRank;
  bool _loading = true;
  String? _error;
  String _type = 'weekly'; // 'weekly' | 'all_time'

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
    _tabController.addListener(() {
      if (!_tabController.indexIsChanging) {
        setState(() {
          _type = _tabController.index == 0 ? 'weekly' : 'all_time';
          _loading = true;
        });
        _fetchLeaderboard();
      }
    });
    _fetchLeaderboard();
    _fetchMyRank();
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  Future<void> _fetchLeaderboard() async {
    try {
      final uri = Uri.parse(
        '${ApiEndpoints.baseUrl}/api/leaderboard.php?type=$_type&per_page=10',
      );
      final res = await http.get(uri);
      if (res.statusCode == 200) {
        final data = json.decode(res.body);
        if (data['success'] == true) {
          setState(() {
            _reporters = (data['reporters'] as List)
                .map((e) => LeaderboardEntry.fromJson(e))
                .toList();
            _loading = false;
            _error = null;
          });
          return;
        }
      }
      setState(() {
        _loading = false;
        _error = 'Failed to load leaderboard';
      });
    } catch (e) {
      setState(() {
        _loading = false;
        _error = e.toString();
      });
    }
  }

  Future<void> _fetchMyRank() async {
    // Only if authenticated; skip silently otherwise
    try {
      // In production, pass Bearer token here
      final uri = Uri.parse(
        '${ApiEndpoints.baseUrl}/api/leaderboard.php?action=my_rank&type=$_type',
      );
      final res = await http.get(uri);
      if (res.statusCode == 200) {
        final data = json.decode(res.body);
        if (data['success'] == true) {
          setState(() => _myRank = MyRankData.fromJson(data));
        }
      }
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF5F5F5),
      appBar: AppBar(
        backgroundColor: const Color(0xFF0D47A1),
        foregroundColor: Colors.white,
        title: const Text(
          'Reporter Leaderboard',
          style: TextStyle(fontWeight: FontWeight.bold),
        ),
        bottom: TabBar(
          controller: _tabController,
          indicatorColor: Colors.amber,
          labelColor: Colors.white,
          unselectedLabelColor: Colors.white70,
          tabs: const [
            Tab(text: 'Weekly'),
            Tab(text: 'All Time'),
          ],
        ),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      const Icon(Icons.error_outline,
                          color: Colors.red, size: 48),
                      const SizedBox(height: 12),
                      Text(_error!, textAlign: TextAlign.center),
                      const SizedBox(height: 16),
                      ElevatedButton(
                        onPressed: () {
                          setState(() {
                            _loading = true;
                            _error = null;
                          });
                          _fetchLeaderboard();
                        },
                        child: const Text('Retry'),
                      ),
                    ],
                  ),
                )
              : Column(
                  children: [
                    // ── Top 3 Podium ─────────────────────────────────
                    if (_reporters.length >= 3)
                      _buildPodium(_reporters.take(3).toList()),
                    // ── Ranks 4–10 ───────────────────────────────────
                    Expanded(
                      child: ListView.builder(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 16, vertical: 8),
                        itemCount: _reporters.length > 3
                            ? _reporters.length - 3
                            : 0,
                        itemBuilder: (ctx, i) =>
                            _buildRankTile(_reporters[i + 3], i + 4),
                      ),
                    ),
                    // ── My Rank Widget ────────────────────────────────
                    if (_myRank != null) _buildMyRankCard(),
                  ],
                ),
    );
  }

  // ── Podium (Top 3) ───────────────────────────────────────────────────────
  Widget _buildPodium(List<LeaderboardEntry> top3) {
    return Container(
      color: const Color(0xFF0D47A1),
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          // 2nd place
          Expanded(child: _podiumItem(top3[1], 2, 80)),
          // 1st place (taller)
          Expanded(child: _podiumItem(top3[0], 1, 100)),
          // 3rd place
          Expanded(child: _podiumItem(top3[2], 3, 64)),
        ],
      ),
    );
  }

  Widget _podiumItem(LeaderboardEntry e, int position, double avatarSize) {
    final colors = {1: Colors.amber, 2: Colors.grey[300]!, 3: Colors.brown[300]!};
    final medals = {1: '🥇', 2: '🥈', 3: '🥉'};
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        if (position == 1) ...[
          const Icon(Icons.emoji_events, color: Colors.amber, size: 28),
          const SizedBox(height: 4),
        ],
        CircleAvatar(
          radius: avatarSize / 2,
          backgroundColor: Colors.white24,
          child: e.avatarUrl != null
              ? ClipOval(
                  child: CachedNetworkImage(
                    imageUrl: e.avatarUrl!,
                    width: avatarSize,
                    height: avatarSize,
                    fit: BoxFit.cover,
                    errorWidget: (_, __, ___) => const Icon(Icons.person,
                        color: Colors.white, size: 24),
                  ),
                )
              : const Icon(Icons.person, color: Colors.white, size: 24),
        ),
        const SizedBox(height: 6),
        Text(
          medals[position]!,
          style: const TextStyle(fontSize: 18),
        ),
        Text(
          e.reporterName.split(' ').first,
          style: const TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.bold,
              fontSize: 12),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          textAlign: TextAlign.center,
        ),
        if (e.hasBlueTick)
          const Icon(Icons.verified, color: Colors.lightBlueAccent, size: 14),
        Text(
          _type == 'weekly'
              ? '${e.weeklyScore.toStringAsFixed(0)} pts'
              : '${e.credibilityScore.toStringAsFixed(0)} pts',
          style: TextStyle(
              color: colors[position], fontSize: 11, fontWeight: FontWeight.w600),
        ),
      ],
    );
  }

  // ── Rank tile (4th place and beyond) ─────────────────────────────────────
  Widget _buildRankTile(LeaderboardEntry e, int rank) {
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: ListTile(
        leading: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            SizedBox(
              width: 28,
              child: Text(
                '#$rank',
                style: const TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 14,
                    color: Color(0xFF0D47A1)),
              ),
            ),
            const SizedBox(width: 8),
            CircleAvatar(
              radius: 22,
              backgroundColor: const Color(0xFFE3F2FD),
              child: e.avatarUrl != null
                  ? ClipOval(
                      child: CachedNetworkImage(
                        imageUrl: e.avatarUrl!,
                        width: 44,
                        height: 44,
                        fit: BoxFit.cover,
                        errorWidget: (_, __, ___) =>
                            const Icon(Icons.person, size: 20),
                      ),
                    )
                  : const Icon(Icons.person, size: 20),
            ),
          ],
        ),
        title: Row(
          children: [
            Flexible(
              child: Text(
                e.reporterName,
                style: const TextStyle(fontWeight: FontWeight.w600),
                overflow: TextOverflow.ellipsis,
              ),
            ),
            if (e.hasBlueTick) ...[
              const SizedBox(width: 4),
              const Icon(Icons.verified, color: Colors.blue, size: 16),
            ],
          ],
        ),
        subtitle: Text(
          '${e.articlesApproved} articles  •  ${e.followersCount} followers'
          '${e.location != null ? "  •  ${e.location}" : ""}',
          style: const TextStyle(fontSize: 12),
        ),
        trailing: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Text(
              _type == 'weekly'
                  ? e.weeklyScore.toStringAsFixed(0)
                  : e.credibilityScore.toStringAsFixed(0),
              style: const TextStyle(
                  fontWeight: FontWeight.bold,
                  fontSize: 16,
                  color: Color(0xFF0D47A1)),
            ),
            const Text('pts',
                style: TextStyle(fontSize: 11, color: Colors.grey)),
          ],
        ),
      ),
    );
  }

  // ── My Rank Bottom Widget ─────────────────────────────────────────────────
  Widget _buildMyRankCard() {
    final r = _myRank!;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      decoration: BoxDecoration(
        color: Colors.white,
        boxShadow: [
          BoxShadow(
              color: Colors.black.withOpacity(0.08),
              blurRadius: 12,
              offset: const Offset(0, -4)),
        ],
      ),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            decoration: BoxDecoration(
              color: const Color(0xFF0D47A1),
              borderRadius: BorderRadius.circular(8),
            ),
            child: Text(
              r.rank != null ? '#${r.rank}' : 'N/A',
              style: const TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.bold,
                  fontSize: 18),
            ),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('Your Rank',
                    style:
                        TextStyle(fontSize: 12, color: Colors.grey)),
                const SizedBox(height: 2),
                Text(
                  '${r.articlesApproved} articles  •  '
                  '${r.followersCount} followers',
                  style: const TextStyle(fontSize: 12),
                ),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                _type == 'weekly'
                    ? r.weeklyScore.toStringAsFixed(0)
                    : r.credibilityScore.toStringAsFixed(0),
                style: const TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 20,
                    color: Color(0xFF0D47A1)),
              ),
              const Text('pts', style: TextStyle(fontSize: 12, color: Colors.grey)),
            ],
          ),
        ],
      ),
    );
  }
}
