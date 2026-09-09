import 'package:flutter/material.dart';
import '../../../data/models/live_model.dart';
import '../../../data/services/live_service.dart';
import '../../../data/services/api_service.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_strings.dart';
import 'watch_live_screen.dart';

/// Live News feed — three tabs: Live Now | Upcoming | Past.
class LiveFeedScreen extends StatefulWidget {
  const LiveFeedScreen({super.key});

  @override
  State<LiveFeedScreen> createState() => _LiveFeedScreenState();
}

class _LiveFeedScreenState extends State<LiveFeedScreen>
    with SingleTickerProviderStateMixin {

  static const _tabs = [
    (label: AppStrings.liveTabLive,      status: 'live'),
    (label: AppStrings.liveTabScheduled, status: 'scheduled'),
    (label: AppStrings.liveTabPast,      status: 'ended'),
  ];

  late final TabController _tabCtrl;
  final _svc = LiveService(api: ApiService());

  final _streams = <String, List<LiveStreamModel>>{
    'live'      : [],
    'scheduled' : [],
    'ended'     : [],
  };
  final _loading = <String, bool>{
    'live'      : true,
    'scheduled' : false,
    'ended'     : false,
  };
  final _loaded = <String, bool>{
    'live': false, 'scheduled': false, 'ended': false,
  };

  @override
  void initState() {
    super.initState();
    _tabCtrl = TabController(length: _tabs.length, vsync: this)
      ..addListener(() {
        if (!_tabCtrl.indexIsChanging) {
          final status = _tabs[_tabCtrl.index].status;
          if (!(_loaded[status] ?? false)) _fetchTab(status);
        }
      });
    _fetchTab('live');
  }

  @override
  void dispose() {
    _tabCtrl.dispose();
    super.dispose();
  }

  Future<void> _fetchTab(String status) async {
    if (_loading[status] == true) return;
    setState(() => _loading[status] = true);
    final list = await _svc.fetchFeed(status: status);
    if (!mounted) return;
    setState(() {
      _streams[status] = list;
      _loading[status] = false;
      _loaded[status]  = true;
    });
  }

  Future<void> _refresh(String status) async {
    _loaded[status] = false;
    await _fetchTab(status);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Row(
          children: [
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
              decoration: BoxDecoration(
                color: AppColors.primary,
                borderRadius: BorderRadius.circular(4),
              ),
              child: const Text(
                'LIVE',
                style: TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.w900,
                  fontSize: 13,
                  letterSpacing: 1.2,
                ),
              ),
            ),
            const SizedBox(width: 8),
            const Text(AppStrings.liveTitle),
          ],
        ),
        bottom: TabBar(
          controller: _tabCtrl,
          tabs: _tabs.map((t) => Tab(text: t.label)).toList(),
          indicatorColor: AppColors.primary,
          labelColor: AppColors.primary,
        ),
      ),
      body: TabBarView(
        controller: _tabCtrl,
        children: _tabs.map((t) => _TabPage(
          status:   t.status,
          streams:  _streams[t.status] ?? [],
          isLoading: _loading[t.status] ?? false,
          onRefresh: () => _refresh(t.status),
          onTap: (s) => _openStream(context, s),
        )).toList(),
      ),
    );
  }

  void _openStream(BuildContext context, LiveStreamModel stream) {
    Navigator.push(context, MaterialPageRoute(
      builder: (_) => WatchLiveScreen(stream: stream),
    ));
  }
}

// ─────────────────────────────────────────────────────────────────────────────

class _TabPage extends StatelessWidget {
  const _TabPage({
    required this.status,
    required this.streams,
    required this.isLoading,
    required this.onRefresh,
    required this.onTap,
  });

  final String                     status;
  final List<LiveStreamModel>      streams;
  final bool                       isLoading;
  final Future<void> Function()    onRefresh;
  final void Function(LiveStreamModel) onTap;

  @override
  Widget build(BuildContext context) {
    if (isLoading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (streams.isEmpty) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.live_tv_outlined, size: 64,
                color: Theme.of(context).colorScheme.outline),
            const SizedBox(height: 12),
            Text(AppStrings.liveNoStreams,
                style: Theme.of(context).textTheme.bodyLarge),
          ],
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: onRefresh,
      child: ListView.separated(
        padding: const EdgeInsets.all(12),
        itemCount: streams.length,
        separatorBuilder: (_, __) => const SizedBox(height: 12),
        itemBuilder: (ctx, i) => _StreamCard(
          stream: streams[i],
          onTap:  () => onTap(streams[i]),
        ),
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────

class _StreamCard extends StatelessWidget {
  const _StreamCard({required this.stream, required this.onTap});

  final LiveStreamModel stream;
  final VoidCallback    onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;

    return GestureDetector(
      onTap: onTap,
      child: Card(
        elevation: 3,
        clipBehavior: Clip.antiAlias,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Thumbnail
            AspectRatio(
              aspectRatio: 16 / 9,
              child: Stack(
                fit: StackFit.expand,
                children: [
                  stream.thumbnail != null
                      ? Image.network(
                          stream.thumbnail!,
                          fit: BoxFit.cover,
                          errorBuilder: (_, __, ___) => _placeholder(isDark),
                        )
                      : _placeholder(isDark),
                  // Status badge
                  Positioned(
                    top: 8, left: 8,
                    child: _StatusBadge(stream: stream),
                  ),
                  // Play overlay for live streams
                  if (stream.isLive)
                    const Center(
                      child: Icon(Icons.play_circle_fill,
                          size: 56, color: Colors.white70),
                    ),
                ],
              ),
            ),
            // Info
            Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(stream.title,
                      style: theme.textTheme.titleMedium
                          ?.copyWith(fontWeight: FontWeight.bold),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis),
                  const SizedBox(height: 6),
                  Row(
                    children: [
                      const Icon(Icons.person_outline, size: 14),
                      const SizedBox(width: 4),
                      Text(stream.authorName,
                          style: theme.textTheme.bodySmall),
                      const Spacer(),
                      if (stream.isLive) ...[
                        const Icon(Icons.visibility_outlined,
                            size: 14, color: AppColors.primary),
                        const SizedBox(width: 4),
                        Text(
                          '${_fmtCount(stream.viewerCount)} ${AppStrings.liveViewers}',
                          style: theme.textTheme.bodySmall
                              ?.copyWith(color: AppColors.primary),
                        ),
                      ],
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _placeholder(bool isDark) => Container(
    color: isDark ? AppColors.cardDark : AppColors.scaffoldLight,
    child: const Center(
      child: Icon(Icons.live_tv, size: 48, color: Colors.white38),
    ),
  );

  String _fmtCount(int n) {
    if (n >= 1000000) return '${(n / 1000000).toStringAsFixed(1)}M';
    if (n >= 1000)    return '${(n / 1000).toStringAsFixed(1)}K';
    return n.toString();
  }
}

// ─────────────────────────────────────────────────────────────────────────────

class _StatusBadge extends StatelessWidget {
  const _StatusBadge({required this.stream});
  final LiveStreamModel stream;

  @override
  Widget build(BuildContext context) {
    if (stream.isLive) {
      return Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
        decoration: BoxDecoration(
          color: AppColors.primary,
          borderRadius: BorderRadius.circular(4),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: const [
            Icon(Icons.fiber_manual_record,
                size: 8, color: Colors.white),
            SizedBox(width: 4),
            Text('LIVE',
                style: TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.w900,
                  fontSize: 11,
                  letterSpacing: 1,
                )),
          ],
        ),
      );
    }
    if (stream.isScheduled) {
      return Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
        decoration: BoxDecoration(
          color: AppColors.accent,
          borderRadius: BorderRadius.circular(4),
        ),
        child: const Text(
          AppStrings.liveScheduled,
          style: TextStyle(
            color: Colors.white,
            fontWeight: FontWeight.bold,
            fontSize: 11,
          ),
        ),
      );
    }
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: Colors.black54,
        borderRadius: BorderRadius.circular(4),
      ),
      child: const Text(
        AppStrings.liveEnded,
        style: TextStyle(color: Colors.white70, fontSize: 11),
      ),
    );
  }
}
