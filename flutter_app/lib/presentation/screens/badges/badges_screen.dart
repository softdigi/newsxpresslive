// flutter_app/lib/presentation/screens/badges/badges_screen.dart

import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import '../../../core/constants/api_endpoints.dart';

class BadgesScreen extends StatefulWidget {
  const BadgesScreen({super.key});

  @override
  State<BadgesScreen> createState() => _BadgesScreenState();
}

class _BadgesScreenState extends State<BadgesScreen> {
  List<Map<String, dynamic>> _badges = [];
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadBadges();
  }

  Future<void> _loadBadges() async {
    try {
      // In production, pass Authorization header with Firebase token
      final uri = Uri.parse('${ApiEndpoints.baseUrl}/api/badges.php');
      final res = await http.get(uri, headers: {
        // 'Authorization': 'Bearer <firebase_token>',
      });
      if (res.statusCode == 200) {
        final data = json.decode(res.body);
        if (data['success'] == true) {
          setState(() {
            _badges = List<Map<String, dynamic>>.from(data['badges'] ?? []);
            _loading = false;
          });
          return;
        }
      }
      setState(() {
        _loading = false;
        _error = 'Failed to load badges';
      });
    } catch (e) {
      setState(() {
        _loading = false;
        _error = e.toString();
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final unlocked = _badges.where((b) => b['is_unlocked'] == 1).toList();
    final locked   = _badges.where((b) => b['is_unlocked'] != 1).toList();

    return Scaffold(
      backgroundColor: const Color(0xFFF5F5F5),
      appBar: AppBar(
        backgroundColor: const Color(0xFF0D47A1),
        foregroundColor: Colors.white,
        title: const Text(
          'My Badges',
          style: TextStyle(fontWeight: FontWeight.bold),
        ),
        actions: [
          Padding(
            padding: const EdgeInsets.only(right: 16),
            child: Center(
              child: Text(
                '${unlocked.length}/${_badges.length}',
                style: const TextStyle(
                    fontWeight: FontWeight.bold, fontSize: 16),
              ),
            ),
          ),
        ],
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
                      Text(_error!),
                      const SizedBox(height: 16),
                      ElevatedButton(
                          onPressed: () {
                            setState(() {
                              _loading = true;
                              _error = null;
                            });
                            _loadBadges();
                          },
                          child: const Text('Retry')),
                    ],
                  ),
                )
              : SingleChildScrollView(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      if (unlocked.isNotEmpty) ...[
                        _sectionHeader('🏆 Earned (${unlocked.length})'),
                        const SizedBox(height: 12),
                        _buildGrid(unlocked, unlocked: true),
                        const SizedBox(height: 24),
                      ],
                      if (locked.isNotEmpty) ...[
                        _sectionHeader('🔒 Locked (${locked.length})'),
                        const SizedBox(height: 12),
                        _buildGrid(locked, unlocked: false),
                      ],
                    ],
                  ),
                ),
    );
  }

  Widget _sectionHeader(String title) {
    return Text(
      title,
      style: const TextStyle(
          fontSize: 16, fontWeight: FontWeight.bold, color: Color(0xFF0D47A1)),
    );
  }

  Widget _buildGrid(List<Map<String, dynamic>> items, {required bool unlocked}) {
    return GridView.builder(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 3,
        crossAxisSpacing: 12,
        mainAxisSpacing: 12,
        childAspectRatio: 0.8,
      ),
      itemCount: items.length,
      itemBuilder: (ctx, i) => _BadgeTile(badge: items[i], unlocked: unlocked),
    );
  }
}

// ── Individual Badge Tile ────────────────────────────────────────────────────
class _BadgeTile extends StatelessWidget {
  final Map<String, dynamic> badge;
  final bool unlocked;

  const _BadgeTile({required this.badge, required this.unlocked});

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: () => _showBadgeDialog(context),
      child: Container(
        decoration: BoxDecoration(
          color: unlocked ? Colors.white : Colors.grey[200],
          borderRadius: BorderRadius.circular(12),
          border: Border.all(
            color: unlocked
                ? const Color(0xFFFFD700)
                : Colors.grey[300]!,
            width: unlocked ? 2 : 1,
          ),
          boxShadow: unlocked
              ? [
                  BoxShadow(
                      color: const Color(0xFFFFD700).withOpacity(0.3),
                      blurRadius: 8,
                      offset: const Offset(0, 2))
                ]
              : null,
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            // Badge icon
            ColorFiltered(
              colorFilter: unlocked
                  ? const ColorFilter.mode(
                      Colors.transparent, BlendMode.saturation)
                  : const ColorFilter.matrix([
                      0.2126, 0.7152, 0.0722, 0, 0,
                      0.2126, 0.7152, 0.0722, 0, 0,
                      0.2126, 0.7152, 0.0722, 0, 0,
                      0,      0,      0,      1, 0,
                    ]),
              child: badge['icon_url'] != null
                  ? Image.network(
                      badge['icon_url'],
                      width: 52,
                      height: 52,
                      errorBuilder: (_, __, ___) =>
                          _defaultIcon(badge['slug'] ?? ''),
                    )
                  : _defaultIcon(badge['slug'] ?? ''),
            ),
            const SizedBox(height: 8),
            Text(
              badge['name'] ?? '',
              style: TextStyle(
                fontSize: 11,
                fontWeight:
                    unlocked ? FontWeight.bold : FontWeight.normal,
                color: unlocked ? const Color(0xFF0D47A1) : Colors.grey,
              ),
              textAlign: TextAlign.center,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
            if (!unlocked)
              const Padding(
                padding: EdgeInsets.only(top: 4),
                child: Icon(Icons.lock, size: 14, color: Colors.grey),
              ),
          ],
        ),
      ),
    );
  }

  Widget _defaultIcon(String slug) {
    const icons = {
      'early_bird':   '🐦',
      'views_10k':    '👁',
      'viral_story':  '🔥',
      'rising_star':  '⭐',
      'top_reporter': '🏆',
    };
    return Text(
      icons[slug] ?? '🎖',
      style: const TextStyle(fontSize: 42),
    );
  }

  void _showBadgeDialog(BuildContext ctx) {
    showDialog(
      context: ctx,
      builder: (_) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Row(
          children: [
            Text(_defaultIconText(badge['slug'] ?? ''),
                style: const TextStyle(fontSize: 28)),
            const SizedBox(width: 8),
            Expanded(
                child: Text(badge['name'] ?? '',
                    style: const TextStyle(fontSize: 16))),
          ],
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(badge['description'] ?? '',
                style: const TextStyle(fontSize: 14)),
            if (unlocked && badge['unlocked_at'] != null) ...[
              const SizedBox(height: 12),
              Text(
                'Earned: ${_formatDate(badge['unlocked_at'])}',
                style: const TextStyle(
                    fontSize: 12,
                    color: Colors.green,
                    fontWeight: FontWeight.w600),
              ),
            ],
            if (!unlocked) ...[
              const SizedBox(height: 12),
              const Text(
                '🔒 Not yet earned — keep reporting!',
                style: TextStyle(
                    fontSize: 12, color: Colors.grey),
              ),
            ],
          ],
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('Close')),
        ],
      ),
    );
  }

  String _defaultIconText(String slug) {
    const icons = {
      'early_bird':   '🐦',
      'views_10k':    '👁',
      'viral_story':  '🔥',
      'rising_star':  '⭐',
      'top_reporter': '🏆',
    };
    return icons[slug] ?? '🎖';
  }

  String _formatDate(String? dateStr) {
    if (dateStr == null) return '';
    try {
      final dt = DateTime.parse(dateStr);
      return '${dt.day}/${dt.month}/${dt.year}';
    } catch (_) {
      return dateStr;
    }
  }
}
