// flutter_app/lib/presentation/widgets/stories_widget.dart
// Instagram-style horizontal stories strip + full-screen story viewer.
// Fetches from /api/stories/feed.php (auth required).

import 'dart:async';
import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:http/http.dart' as http;
import '../../../core/constants/api_endpoints.dart';

// ─────────────────────────────────────────────────────────────────────────────
// Data model
// ─────────────────────────────────────────────────────────────────────────────
class _ReporterStories {
  final String reporterUid;
  final String displayName;
  final String? photoUrl;
  final bool isBlueTick;
  int unseenCount;
  final List<Map<String, dynamic>> items;

  _ReporterStories({
    required this.reporterUid,
    required this.displayName,
    this.photoUrl,
    required this.isBlueTick,
    required this.unseenCount,
    required this.items,
  });

  factory _ReporterStories.fromJson(Map<String, dynamic> j) => _ReporterStories(
        reporterUid: j['reporter_uid'] as String,
        displayName: j['display_name'] as String? ?? '',
        photoUrl: j['photo_url'] as String?,
        isBlueTick: j['is_blue_tick'] == true,
        unseenCount: int.tryParse('${j['unseen_count']}') ?? 0,
        items: (j['items'] as List<dynamic>).cast<Map<String, dynamic>>(),
      );
}

// ─────────────────────────────────────────────────────────────────────────────
// Stories strip widget
// ─────────────────────────────────────────────────────────────────────────────
class StoriesWidget extends StatefulWidget {
  final String idToken;
  const StoriesWidget({super.key, required this.idToken});

  @override
  State<StoriesWidget> createState() => _StoriesWidgetState();
}

class _StoriesWidgetState extends State<StoriesWidget> {
  List<_ReporterStories> _stories = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _fetch();
  }

  Future<void> _fetch() async {
    try {
      final resp = await http.get(
        Uri.parse(ApiEndpoints.storiesFeed),
        headers: {'Authorization': 'Bearer ${widget.idToken}'},
      ).timeout(const Duration(seconds: 10));

      final data = jsonDecode(resp.body) as Map<String, dynamic>;
      if (data['success'] == true && mounted) {
        final list = (data['stories'] as List<dynamic>)
            .cast<Map<String, dynamic>>()
            .map(_ReporterStories.fromJson)
            .toList();
        setState(() {
          _stories = list;
          _loading = false;
        });
      }
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _openStories(_ReporterStories rs, int startIndex) {
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => _StoryViewerScreen(
        reporterStories: rs,
        startIndex: startIndex,
        idToken: widget.idToken,
        onViewed: (storyId) {
          // Mark as viewed locally
          setState(() {
            if (rs.unseenCount > 0) rs.unseenCount--;
          });
        },
      ),
    ));
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const SizedBox(height: 90, child: Center(child: CircularProgressIndicator()));
    }
    if (_stories.isEmpty) return const SizedBox.shrink();

    return SizedBox(
      height: 90,
      child: ListView.builder(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 8),
        itemCount: _stories.length,
        itemBuilder: (_, i) {
          final rs = _stories[i];
          final hasUnseen = rs.unseenCount > 0;

          return GestureDetector(
            onTap: () => _openStories(rs, 0),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 6),
              child: Column(
                children: [
                  Container(
                    padding: const EdgeInsets.all(2),
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      gradient: hasUnseen
                          ? const LinearGradient(colors: [Colors.blue, Colors.cyan])
                          : const LinearGradient(colors: [Colors.grey, Colors.grey]),
                    ),
                    child: CircleAvatar(
                      radius: 28,
                      backgroundImage: rs.photoUrl != null
                          ? CachedNetworkImageProvider(rs.photoUrl!)
                          : null,
                      child: rs.photoUrl == null
                          ? Text(rs.displayName.isNotEmpty ? rs.displayName[0] : '?',
                              style: const TextStyle(fontSize: 18))
                          : null,
                    ),
                  ),
                  const SizedBox(height: 4),
                  SizedBox(
                    width: 60,
                    child: Text(
                      rs.displayName,
                      style: const TextStyle(fontSize: 10),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      textAlign: TextAlign.center,
                    ),
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Full-screen story viewer
// ─────────────────────────────────────────────────────────────────────────────
class _StoryViewerScreen extends StatefulWidget {
  final _ReporterStories reporterStories;
  final int startIndex;
  final String idToken;
  final void Function(int storyId) onViewed;

  const _StoryViewerScreen({
    required this.reporterStories,
    required this.startIndex,
    required this.idToken,
    required this.onViewed,
  });

  @override
  State<_StoryViewerScreen> createState() => _StoryViewerScreenState();
}

class _StoryViewerScreenState extends State<_StoryViewerScreen>
    with SingleTickerProviderStateMixin {
  late int _index;
  late AnimationController _progressCtrl;
  Timer? _advanceTimer;

  @override
  void initState() {
    super.initState();
    _index = widget.startIndex;
    _progressCtrl = AnimationController(vsync: this);
    _startStory();
  }

  @override
  void dispose() {
    _advanceTimer?.cancel();
    _progressCtrl.dispose();
    super.dispose();
  }

  Map<String, dynamic> get _current => widget.reporterStories.items[_index];

  void _startStory() {
    final duration = Duration(seconds: int.tryParse('${_current['duration_seconds']}') ?? 5);
    _progressCtrl.duration = duration;
    _progressCtrl.forward(from: 0);
    _advanceTimer?.cancel();
    _advanceTimer = Timer(duration, _nextStory);
    _markViewed();
  }

  void _markViewed() async {
    final storyId = int.tryParse('${_current['id']}') ?? 0;
    if (storyId <= 0) return;
    widget.onViewed(storyId);
    // Fire-and-forget — mark viewed on server
    try {
      await http.post(
        Uri.parse(ApiEndpoints.storiesFeed),
        headers: {
          'Authorization': 'Bearer ${widget.idToken}',
          'Content-Type': 'application/json',
        },
        body: jsonEncode({'story_id': storyId, 'action': 'view'}),
      );
    } catch (_) {}
  }

  void _nextStory() {
    if (_index < widget.reporterStories.items.length - 1) {
      setState(() => _index++);
      _startStory();
    } else {
      Navigator.of(context).pop();
    }
  }

  void _prevStory() {
    if (_index > 0) {
      setState(() => _index--);
      _startStory();
    }
  }

  @override
  Widget build(BuildContext context) {
    final story   = _current;
    final storyType = story['story_type'] as String? ?? 'text';
    final bgHex   = story['background_color'] as String? ?? '#1a1a2e';
    final bgColor = Color(int.parse('FF${bgHex.replaceAll('#', '')}', radix: 16));
    final items   = widget.reporterStories.items;

    return Scaffold(
      backgroundColor: bgColor,
      body: GestureDetector(
        onTapDown: (d) {
          final w = MediaQuery.of(context).size.width;
          if (d.globalPosition.dx < w / 2) _prevStory() ; else _nextStory();
        },
        onVerticalDragEnd: (d) {
          // Swipe up = open linked article
          if (d.primaryVelocity != null && d.primaryVelocity! < -200) {
            final linkedId = story['linked_article_id'];
            if (linkedId != null) {
              ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(content: Text('Opening article #$linkedId…')),
              );
            }
          }
          // Swipe down = close
          if (d.primaryVelocity != null && d.primaryVelocity! > 400) {
            Navigator.of(context).pop();
          }
        },
        child: Stack(fit: StackFit.expand, children: [
          // Story content
          if (storyType == 'image' && story['media_url'] != null)
            CachedNetworkImage(
              imageUrl: story['media_url'] as String,
              fit: BoxFit.cover,
            )
          else if (storyType == 'text' || story['text_content'] != null)
            Center(
              child: Padding(
                padding: const EdgeInsets.all(32),
                child: Text(
                  story['text_content'] as String? ?? '',
                  style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.bold),
                  textAlign: TextAlign.center,
                ),
              ),
            ),

          // Gradient overlay
          Container(
            decoration: const BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                colors: [Colors.black54, Colors.transparent, Colors.black38],
              ),
            ),
          ),

          // Progress bars
          Positioned(
            top: MediaQuery.of(context).padding.top + 8,
            left: 8,
            right: 8,
            child: Row(
              children: List.generate(items.length, (i) => Expanded(
                child: Container(
                  margin: const EdgeInsets.symmetric(horizontal: 2),
                  height: 2.5,
                  decoration: BoxDecoration(
                    color: i < _index ? Colors.white : Colors.white30,
                    borderRadius: BorderRadius.circular(2),
                  ),
                  child: i == _index
                      ? AnimatedBuilder(
                          animation: _progressCtrl,
                          builder: (_, __) => FractionallySizedBox(
                            alignment: Alignment.centerLeft,
                            widthFactor: _progressCtrl.value,
                            child: Container(color: Colors.white),
                          ),
                        )
                      : null,
                ),
              )),
            ),
          ),

          // Reporter header
          Positioned(
            top: MediaQuery.of(context).padding.top + 20,
            left: 12,
            right: 12,
            child: Row(children: [
              CircleAvatar(
                radius: 18,
                backgroundImage: widget.reporterStories.photoUrl != null
                    ? CachedNetworkImageProvider(widget.reporterStories.photoUrl!)
                    : null,
                child: widget.reporterStories.photoUrl == null ? const Icon(Icons.person) : null,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  widget.reporterStories.displayName,
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold),
                ),
              ),
              IconButton(
                icon: const Icon(Icons.close, color: Colors.white),
                onPressed: () => Navigator.of(context).pop(),
              ),
            ]),
          ),

          // Swipe up hint for linked article
          if (story['linked_article_id'] != null)
            const Positioned(
              bottom: 30,
              left: 0,
              right: 0,
              child: Column(children: [
                Icon(Icons.keyboard_arrow_up, color: Colors.white, size: 28),
                Text('Swipe up for full story', style: TextStyle(color: Colors.white70, fontSize: 12)),
              ]),
            ),
        ]),
      ),
    );
  }
}
