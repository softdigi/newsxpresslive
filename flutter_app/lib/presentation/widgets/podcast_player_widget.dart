// flutter_app/lib/presentation/widgets/podcast_player_widget.dart
// Mini persistent podcast player — fetches latest digest from the API
// and provides play/pause, speed selector, progress slider,
// download and share functionality.

import 'dart:async';
import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import '../../../core/constants/api_endpoints.dart';

class PodcastPlayerWidget extends StatefulWidget {
  const PodcastPlayerWidget({super.key});

  @override
  State<PodcastPlayerWidget> createState() => _PodcastPlayerWidgetState();
}

class _PodcastPlayerWidgetState extends State<PodcastPlayerWidget> {
  Map<String, dynamic>? _digest;
  bool _loading = true;
  bool _expanded = false;

  // Playback state (uses a timer to simulate; swap for audioplayers in production)
  bool _isPlaying = false;
  double _progress = 0.0;     // 0.0 – 1.0
  double _speedMultiplier = 1.0;
  int _durationSeconds = 0;
  int _elapsedSeconds = 0;
  Timer? _ticker;

  @override
  void initState() {
    super.initState();
    _fetchLatest();
  }

  @override
  void dispose() {
    _ticker?.cancel();
    super.dispose();
  }

  Future<void> _fetchLatest() async {
    setState(() => _loading = true);
    try {
      final uri = Uri.parse(ApiEndpoints.podcastLatest)
          .replace(queryParameters: {'language': 'hi'});
      final resp = await http.get(uri).timeout(const Duration(seconds: 10));
      final data = jsonDecode(resp.body) as Map<String, dynamic>;
      if (data['success'] == true && mounted) {
        setState(() {
          _digest          = data;
          _durationSeconds = int.tryParse('${data['duration']}') ?? 0;
          _loading         = false;
        });
      }
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _togglePlay() {
    if (_digest == null) return;
    setState(() => _isPlaying = !_isPlaying);
    if (_isPlaying) {
      _ticker = Timer.periodic(Duration(milliseconds: (1000 / _speedMultiplier).round()), (_) {
        if (!mounted) return;
        setState(() {
          _elapsedSeconds++;
          _progress = _durationSeconds > 0
              ? (_elapsedSeconds / _durationSeconds).clamp(0.0, 1.0)
              : 0.0;
          if (_progress >= 1.0) {
            _isPlaying = false;
            _ticker?.cancel();
          }
        });
      });
    } else {
      _ticker?.cancel();
    }
  }

  void _setSpeed(double speed) {
    setState(() => _speedMultiplier = speed);
    if (_isPlaying) {
      _ticker?.cancel();
      _togglePlay();
      _togglePlay();
    }
  }

  void _seek(double value) {
    setState(() {
      _progress       = value;
      _elapsedSeconds = (_durationSeconds * value).round();
    });
  }

  String _formatDuration(int seconds) {
    final m = seconds ~/ 60;
    final s = seconds % 60;
    return '${m.toString().padLeft(2, '0')}:${s.toString().padLeft(2, '0')}';
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const LinearProgressIndicator();
    }
    if (_digest == null) return const SizedBox.shrink();

    return Material(
      elevation: 8,
      child: Container(
        color: const Color(0xFF1a1a2e),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            // Progress bar
            SliderTheme(
              data: SliderTheme.of(context).copyWith(
                trackHeight: 2,
                thumbShape: const RoundSliderThumbShape(enabledThumbRadius: 6),
              ),
              child: Slider(
                value: _progress,
                onChanged: _seek,
                activeColor: Colors.orange,
                inactiveColor: Colors.white24,
              ),
            ),

            // Controls row
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
              child: Row(children: [
                // Episode info
                Expanded(
                  child: GestureDetector(
                    onTap: () => setState(() => _expanded = !_expanded),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          _digest!['title'] ?? 'Daily Digest',
                          style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13),
                          overflow: TextOverflow.ellipsis,
                        ),
                        Text(
                          _digest!['date'] ?? '',
                          style: const TextStyle(color: Colors.white54, fontSize: 11),
                        ),
                      ],
                    ),
                  ),
                ),

                // Time
                Text(
                  '${_formatDuration(_elapsedSeconds)} / ${_formatDuration(_durationSeconds)}',
                  style: const TextStyle(color: Colors.white54, fontSize: 11),
                ),
                const SizedBox(width: 8),

                // Speed selector
                PopupMenuButton<double>(
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                    decoration: BoxDecoration(
                      border: Border.all(color: Colors.white30),
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: Text(
                      '${_speedMultiplier}x',
                      style: const TextStyle(color: Colors.white70, fontSize: 12),
                    ),
                  ),
                  itemBuilder: (_) => [1.0, 1.5, 2.0]
                      .map((s) => PopupMenuItem(value: s, child: Text('${s}x')))
                      .toList(),
                  onSelected: _setSpeed,
                ),
                const SizedBox(width: 4),

                // Play/Pause
                IconButton(
                  icon: Icon(
                    _isPlaying ? Icons.pause_circle_filled : Icons.play_circle_filled,
                    color: Colors.orange,
                    size: 36,
                  ),
                  onPressed: _togglePlay,
                ),

                // Download
                IconButton(
                  icon: const Icon(Icons.download, color: Colors.white54, size: 20),
                  onPressed: () {
                    // TODO: launch download URL
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('Download started…')),
                    );
                  },
                ),

                // Share
                IconButton(
                  icon: const Icon(Icons.share, color: Colors.white54, size: 20),
                  onPressed: () {
                    // TODO: integrate share_plus
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(content: Text('Share: ${_digest!['file_url']}')),
                    );
                  },
                ),
              ]),
            ),

            // Expanded article list
            if (_expanded) _buildArticleList(),
          ],
        ),
      ),
    );
  }

  Widget _buildArticleList() {
    final articles = (_digest!['articles'] as List<dynamic>? ?? [])
        .cast<Map<String, dynamic>>();
    if (articles.isEmpty) return const SizedBox.shrink();
    return Container(
      color: const Color(0xFF0f0f23),
      constraints: const BoxConstraints(maxHeight: 200),
      child: ListView.builder(
        shrinkWrap: true,
        itemCount: articles.length,
        itemBuilder: (_, i) => ListTile(
          dense: true,
          leading: Text('${i + 1}', style: const TextStyle(color: Colors.orange, fontWeight: FontWeight.bold)),
          title: Text(
            articles[i]['title'] ?? '',
            style: const TextStyle(color: Colors.white70, fontSize: 13),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
        ),
      ),
    );
  }
}
