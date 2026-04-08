import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:share_plus/share_plus.dart';
import 'package:video_player/video_player.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_strings.dart';
import '../../../data/models/reel_model.dart';
import '../../../data/services/reels_service.dart';

/// Full-screen, vertically-swipeable short-video feed.
///
/// Behaviour:
///  • Plays the active reel automatically; pauses inactive ones.
///  • Tap the video to pause/resume.
///  • Like, Comment, Share actions on the right-hand sidebar.
///  • Infinite-scroll: loads next page as the user nears the end.
class ReelsScreen extends StatefulWidget {
  const ReelsScreen({super.key});

  @override
  State<ReelsScreen> createState() => _ReelsScreenState();
}

class _ReelsScreenState extends State<ReelsScreen> {
  final _service    = ReelsService();
  final _controller = PageController();

  final List<ReelModel> _reels   = [];
  int  _currentPage = 1;
  int  _activeIndex = 0;
  bool _loading     = false;
  bool _hasMore     = true;
  bool _initError   = false;

  @override
  void initState() {
    super.initState();
    _loadMore();
  }

  @override
  void dispose() {
    _service.dispose();
    _controller.dispose();
    super.dispose();
  }

  Future<void> _loadMore() async {
    if (_loading || !_hasMore) return;
    setState(() => _loading = true);
    try {
      final batch = await _service.getReels(page: _currentPage);
      if (batch.isEmpty) {
        _hasMore = false;
      } else {
        _currentPage++;
        _reels.addAll(batch);
      }
    } catch (_) {
      if (_reels.isEmpty) _initError = true;
    }
    if (mounted) setState(() => _loading = false);
  }

  void _onPageChanged(int index) {
    setState(() => _activeIndex = index);
    if (index >= _reels.length - 2) _loadMore();
  }

  void _onLikeChanged(int index, int newCount, bool liked) {
    setState(() {
      _reels[index] = _reels[index].copyWith(
        likesCount: newCount,
        userLiked:  liked,
      );
    });
  }

  @override
  Widget build(BuildContext context) {
    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.light,
      child: Scaffold(
        backgroundColor: Colors.black,
        body: _buildBody(),
      ),
    );
  }

  Widget _buildBody() {
    if (_loading && _reels.isEmpty) {
      return const Center(
        child: CircularProgressIndicator(color: AppColors.primary),
      );
    }
    if (_initError || _reels.isEmpty) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.video_library_outlined,
                color: Colors.white54, size: 64),
            const SizedBox(height: 12),
            Text(
              _initError ? AppStrings.loadingFailed : AppStrings.reelNoReels,
              style: const TextStyle(color: Colors.white70),
            ),
            if (_initError) ...[
              const SizedBox(height: 12),
              TextButton(
                onPressed: () {
                  setState(() => _initError = false);
                  _loadMore();
                },
                child: Text(AppStrings.retry,
                    style: const TextStyle(color: AppColors.primary)),
              ),
            ],
          ],
        ),
      );
    }

    return PageView.builder(
      controller:      _controller,
      scrollDirection: Axis.vertical,
      onPageChanged:   _onPageChanged,
      itemCount:       _reels.length,
      itemBuilder: (_, i) => _ReelPage(
        reel:          _reels[i],
        isActive:      i == _activeIndex,
        service:       _service,
        onLikeChanged: (count, liked) => _onLikeChanged(i, count, liked),
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Single reel page
// ─────────────────────────────────────────────────────────────────────────────

class _ReelPage extends StatefulWidget {
  const _ReelPage({
    required this.reel,
    required this.isActive,
    required this.service,
    required this.onLikeChanged,
  });

  final ReelModel    reel;
  final bool         isActive;
  final ReelsService service;
  final void Function(int likesCount, bool liked) onLikeChanged;

  @override
  State<_ReelPage> createState() => _ReelPageState();
}

class _ReelPageState extends State<_ReelPage> {
  VideoPlayerController? _vpc;
  bool _initialized  = false;
  bool _liking       = false;
  bool _viewRecorded = false;

  @override
  void initState() {
    super.initState();
    _initVideo();
  }

  @override
  void didUpdateWidget(_ReelPage old) {
    super.didUpdateWidget(old);
    if (widget.isActive != old.isActive) {
      if (widget.isActive) {
        _vpc?.play();
        _recordView();
      } else {
        _vpc?.pause();
      }
    }
  }

  Future<void> _initVideo() async {
    if (widget.reel.videoUrl.isEmpty) return;
    try {
      final c = VideoPlayerController.networkUrl(
        Uri.parse(widget.reel.videoUrl),
      );
      await c.initialize();
      c.setLooping(true);
      if (widget.isActive) {
        c.play();
        _recordView();
      }
      if (mounted) setState(() { _vpc = c; _initialized = true; });
    } catch (_) {
      if (mounted) setState(() => _initialized = false);
    }
  }

  void _recordView() {
    if (_viewRecorded) return;
    _viewRecorded = true;
    widget.service.recordView(widget.reel.id);
  }

  @override
  void dispose() {
    _vpc?.dispose();
    super.dispose();
  }

  void _togglePlay() {
    if (_vpc == null) return;
    setState(() {
      _vpc!.value.isPlaying ? _vpc!.pause() : _vpc!.play();
    });
  }

  Future<void> _toggleLike() async {
    if (_liking) return;
    setState(() => _liking = true);
    final result = await widget.service.toggleLike(widget.reel.id);
    if (mounted) setState(() => _liking = false);
    if (result != null) {
      final liked = result['liked'] as bool? ?? !widget.reel.userLiked;
      final count = result['likes_count'] is int
          ? result['likes_count'] as int
          : widget.reel.likesCount + (liked ? 1 : -1);
      widget.onLikeChanged(count, liked);
    }
  }

  void _showComments() {
    showModalBottomSheet<void>(
      context:             context,
      isScrollControlled:  true,
      backgroundColor:     const Color(0xFF1A1A1A),
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (_) => _CommentsSheet(
        reelId:  widget.reel.id,
        service: widget.service,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Stack(
      fit: StackFit.expand,
      children: [
        // ── Video / thumbnail background ───────────────────────────────
        GestureDetector(
          onTap: _togglePlay,
          child: _initialized && _vpc != null
              ? FittedBox(
                  fit: BoxFit.cover,
                  child: SizedBox(
                    width:  _vpc!.value.size.width,
                    height: _vpc!.value.size.height,
                    child: VideoPlayer(_vpc!),
                  ),
                )
              : _ThumbnailPlaceholder(url: widget.reel.thumbnail),
        ),

        // ── Pause indicator ────────────────────────────────────────────
        if (_initialized && _vpc != null)
          ValueListenableBuilder<VideoPlayerValue>(
            valueListenable: _vpc!,
            builder: (_, val, __) {
              if (val.isPlaying) return const SizedBox.shrink();
              return const Center(
                child: Icon(Icons.play_circle_outline,
                    color: Colors.white70, size: 72),
              );
            },
          ),

        // ── Gradient overlay ───────────────────────────────────────────
        const _BottomGradient(),

        // ── Text info (bottom-left) ────────────────────────────────────
        Positioned(
          left: 12, right: 72, bottom: 48,
          child: _ReelInfo(reel: widget.reel),
        ),

        // ── Action sidebar (bottom-right) ──────────────────────────────
        Positioned(
          right: 8, bottom: 48,
          child: _ActionSidebar(
            reel:      widget.reel,
            liking:    _liking,
            onLike:    _toggleLike,
            onComment: _showComments,
            onShare:   () => Share.share(
              '${widget.reel.title}\n${widget.reel.videoUrl}',
            ),
          ),
        ),
      ],
    );
  }
}

// ── Sub-widgets ──────────────────────────────────────────────────────────────

class _ThumbnailPlaceholder extends StatelessWidget {
  const _ThumbnailPlaceholder({this.url});
  final String? url;

  @override
  Widget build(BuildContext context) {
    if (url != null && url!.isNotEmpty) {
      return Image.network(url!, fit: BoxFit.cover,
          errorBuilder: (_, __, ___) => _blank());
    }
    return _blank();
  }

  Widget _blank() => Container(
    color: Colors.black,
    child: const Center(
      child: Icon(Icons.play_circle_outline, color: Colors.white30, size: 80),
    ),
  );
}

class _BottomGradient extends StatelessWidget {
  const _BottomGradient();

  @override
  Widget build(BuildContext context) {
    return Positioned.fill(
      child: DecoratedBox(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end:   Alignment.bottomCenter,
            stops: [0.5, 1.0],
            colors: [Colors.transparent, Color(0xCC000000)],
          ),
        ),
      ),
    );
  }
}

class _ReelInfo extends StatelessWidget {
  const _ReelInfo({required this.reel});
  final ReelModel reel;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          '@${reel.reporterName}',
          style: const TextStyle(
              color: Colors.white, fontWeight: FontWeight.bold, fontSize: 14),
        ),
        const SizedBox(height: 4),
        Text(
          reel.title,
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          style: const TextStyle(color: Colors.white, fontSize: 13),
        ),
        if (reel.description != null && reel.description!.isNotEmpty) ...[
          const SizedBox(height: 2),
          Text(
            reel.description!,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(color: Colors.white70, fontSize: 12),
          ),
        ],
      ],
    );
  }
}

class _ActionSidebar extends StatelessWidget {
  const _ActionSidebar({
    required this.reel,
    required this.liking,
    required this.onLike,
    required this.onComment,
    required this.onShare,
  });

  final ReelModel    reel;
  final bool         liking;
  final VoidCallback onLike;
  final VoidCallback onComment;
  final VoidCallback onShare;

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        _SideAction(
          icon:    reel.userLiked
              ? Icons.favorite_rounded
              : Icons.favorite_border_rounded,
          color:   reel.userLiked ? AppColors.primary : Colors.white,
          label:   _fmt(reel.likesCount),
          loading: liking,
          onTap:   onLike,
        ),
        const SizedBox(height: 16),
        _SideAction(
          icon:  Icons.comment_outlined,
          color: Colors.white,
          label: _fmt(reel.commentsCount),
          onTap: onComment,
        ),
        const SizedBox(height: 16),
        _SideAction(
          icon:  Icons.share_outlined,
          color: Colors.white,
          label: AppStrings.reelShare,
          onTap: onShare,
        ),
        const SizedBox(height: 32),
      ],
    );
  }

  String _fmt(int n) {
    if (n >= 1000000) return '${(n / 1000000).toStringAsFixed(1)}M';
    if (n >= 1000)    return '${(n / 1000).toStringAsFixed(1)}K';
    return '$n';
  }
}

class _SideAction extends StatelessWidget {
  const _SideAction({
    required this.icon,
    required this.color,
    required this.label,
    required this.onTap,
    this.loading = false,
  });

  final IconData     icon;
  final Color        color;
  final String       label;
  final VoidCallback onTap;
  final bool         loading;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: loading ? null : onTap,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          loading
              ? const SizedBox(
                  width: 28, height: 28,
                  child: CircularProgressIndicator(
                      color: AppColors.primary, strokeWidth: 2))
              : Icon(icon, color: color, size: 28),
          const SizedBox(height: 4),
          Text(label,
              style: const TextStyle(color: Colors.white, fontSize: 11)),
        ],
      ),
    );
  }
}

// ── Comments bottom sheet ────────────────────────────────────────────────────

class _CommentsSheet extends StatefulWidget {
  const _CommentsSheet({required this.reelId, required this.service});
  final int          reelId;
  final ReelsService service;

  @override
  State<_CommentsSheet> createState() => _CommentsSheetState();
}

class _CommentsSheetState extends State<_CommentsSheet> {
  final _nameCtrl    = TextEditingController();
  final _contentCtrl = TextEditingController();

  List<ReelComment> _comments       = [];
  bool              _loadingComments = true;
  bool              _submitting      = false;
  String?           _submitMsg;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _nameCtrl.dispose();
    _contentCtrl.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final list = await widget.service.getComments(widget.reelId);
    if (mounted) setState(() { _comments = list; _loadingComments = false; });
  }

  Future<void> _submit() async {
    final name    = _nameCtrl.text.trim();
    final content = _contentCtrl.text.trim();
    if (name.isEmpty || content.isEmpty) return;
    setState(() { _submitting = true; _submitMsg = null; });
    final ok = await widget.service.submitComment(
        reelId: widget.reelId, authorName: name, content: content);
    if (mounted) {
      setState(() {
        _submitting = false;
        _submitMsg  = ok ? AppStrings.reelCommentSent : AppStrings.loadingFailed;
        if (ok) { _nameCtrl.clear(); _contentCtrl.clear(); }
      });
    }
  }

  InputDecoration _inputDeco(String hint) => InputDecoration(
    hintText:      hint,
    hintStyle:     const TextStyle(color: Colors.white38),
    filled:        true,
    fillColor:     const Color(0xFF2A2A2A),
    border:        OutlineInputBorder(
        borderRadius: BorderRadius.circular(8),
        borderSide: BorderSide.none),
    contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
  );

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.of(context).viewInsets.bottom;
    return Padding(
      padding: EdgeInsets.only(bottom: bottom),
      child: SizedBox(
        height: MediaQuery.of(context).size.height * 0.6,
        child: Column(
          children: [
            const SizedBox(height: 8),
            Container(
              width: 40, height: 4,
              decoration: BoxDecoration(
                color: Colors.white30,
                borderRadius: BorderRadius.circular(2),
              ),
            ),
            const SizedBox(height: 12),
            Text(AppStrings.reelComment,
                style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.bold,
                    fontSize: 16)),
            const Divider(color: Colors.white24),
            Expanded(
              child: _loadingComments
                  ? const Center(
                      child: CircularProgressIndicator(color: AppColors.primary))
                  : _comments.isEmpty
                      ? Center(
                          child: Text(AppStrings.noComments,
                              style: const TextStyle(color: Colors.white54)))
                      : ListView.builder(
                          itemCount: _comments.length,
                          itemBuilder: (_, i) => ListTile(
                            leading: const CircleAvatar(
                              backgroundColor: AppColors.primary,
                              child: Icon(Icons.person,
                                  color: Colors.white, size: 16),
                            ),
                            title: Text(_comments[i].authorName,
                                style: const TextStyle(
                                    color: Colors.white,
                                    fontWeight: FontWeight.w600,
                                    fontSize: 13)),
                            subtitle: Text(_comments[i].content,
                                style: const TextStyle(
                                    color: Colors.white70, fontSize: 12)),
                          ),
                        ),
            ),
            const Divider(color: Colors.white24),
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 4, 12, 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (_submitMsg != null)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 6),
                      child: Text(_submitMsg!,
                          style: TextStyle(
                              color: _submitMsg == AppStrings.reelCommentSent
                                  ? Colors.greenAccent
                                  : AppColors.primary,
                              fontSize: 12)),
                    ),
                  Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: _nameCtrl,
                          style: const TextStyle(color: Colors.white),
                          decoration: _inputDeco(AppStrings.reelCommentName),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 6),
                  Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: _contentCtrl,
                          style: const TextStyle(color: Colors.white),
                          decoration: _inputDeco(AppStrings.reelComment_hint),
                          maxLines: 2,
                        ),
                      ),
                      const SizedBox(width: 8),
                      _submitting
                          ? const SizedBox(
                              width: 36, height: 36,
                              child: CircularProgressIndicator(
                                  color: AppColors.primary, strokeWidth: 2))
                          : ElevatedButton(
                              onPressed: _submit,
                              style: ElevatedButton.styleFrom(
                                backgroundColor: AppColors.primary,
                                padding: const EdgeInsets.symmetric(
                                    horizontal: 14, vertical: 10),
                              ),
                              child: Text(AppStrings.reelPostComment,
                                  style: const TextStyle(
                                      color: Colors.white, fontSize: 13)),
                            ),
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
}
