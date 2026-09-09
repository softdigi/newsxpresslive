import 'dart:async';
import 'package:flutter/material.dart';
import 'package:video_player/video_player.dart';
import '../../../data/models/live_model.dart';
import '../../../data/services/live_service.dart';
import '../../../data/services/api_service.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_strings.dart';

/// Full-screen live stream viewer with:
///  - HLS video playback via video_player
///  - Heartbeat (30 s interval) → live viewer count + chat updates
///  - Emoji reactions panel
///  - Live chat overlay
class WatchLiveScreen extends StatefulWidget {
  const WatchLiveScreen({super.key, required this.stream});
  final LiveStreamModel stream;

  @override
  State<WatchLiveScreen> createState() => _WatchLiveScreenState();
}

class _WatchLiveScreenState extends State<WatchLiveScreen> {
  // ── Services ──────────────────────────────────────────────────────────
  late final LiveService _svc;
  late final String      _sessionId;

  // ── Video ─────────────────────────────────────────────────────────────
  VideoPlayerController? _vpc;
  bool   _videoInitialized = false;
  bool   _videoError       = false;

  // ── Live state ────────────────────────────────────────────────────────
  int                  _viewerCount  = 0;
  String               _streamStatus = 'live';
  List<LiveChatMessage> _chat        = [];
  Timer?               _heartbeatTimer;

  // ── Chat ──────────────────────────────────────────────────────────────
  final _chatCtrl   = TextEditingController();
  final _chatScroll = ScrollController();
  bool  _chatVisible = false;
  bool  _sendingChat = false;
  String _chatAuthor = 'Viewer';

  // ── Reactions ─────────────────────────────────────────────────────────
  static const _emojis = ['❤️', '🔥', '👏', '😮', '😂', '😢'];
  final _floatingEmojis = <_FloatingEmoji>[];

  // ── Controls overlay ──────────────────────────────────────────────────
  bool _showControls = true;
  Timer? _hideControlsTimer;

  @override
  void initState() {
    super.initState();
    _svc       = LiveService(api: ApiService());
    _sessionId = LiveService.generateSessionId();
    _viewerCount  = widget.stream.viewerCount;
    _streamStatus = widget.stream.status;

    _initVideo();
    _startHeartbeat();
    _scheduleHideControls();
  }

  @override
  void dispose() {
    _heartbeatTimer?.cancel();
    _hideControlsTimer?.cancel();
    _vpc?.dispose();
    _chatCtrl.dispose();
    _chatScroll.dispose();
    super.dispose();
  }

  // ── Video initialisation ──────────────────────────────────────────────

  Future<void> _initVideo() async {
    final url = widget.stream.playbackUrl;
    if (url == null || url.isEmpty) {
      if (mounted) setState(() => _videoError = true);
      return;
    }
    try {
      final ctrl = VideoPlayerController.networkUrl(Uri.parse(url));
      await ctrl.initialize();
      if (!mounted) { ctrl.dispose(); return; }
      setState(() {
        _vpc                = ctrl;
        _videoInitialized   = true;
      });
      ctrl.play();
      ctrl.setLooping(false);
    } catch (_) {
      if (mounted) setState(() => _videoError = true);
    }
  }

  // ── Heartbeat ─────────────────────────────────────────────────────────

  void _startHeartbeat() {
    _sendHeartbeat();
    _heartbeatTimer = Timer.periodic(
      const Duration(seconds: 30),
      (_) => _sendHeartbeat(),
    );
  }

  Future<void> _sendHeartbeat() async {
    final hb = await _svc.heartbeat(
      streamId : widget.stream.id,
      sessionId: _sessionId,
    );
    if (hb == null || !mounted) return;
    setState(() {
      _viewerCount  = hb.viewerCount;
      _streamStatus = hb.streamStatus;
      // Append only new chat messages (by id)
      final existingIds = _chat.map((m) => m.id).toSet();
      final newMsgs = hb.chat.where((m) => !existingIds.contains(m.id)).toList();
      _chat = [..._chat, ...newMsgs];
    });
    // Scroll to bottom after new messages
    if (_chat.isNotEmpty) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (_chatScroll.hasClients) {
          _chatScroll.animateTo(
            _chatScroll.position.maxScrollExtent,
            duration: const Duration(milliseconds: 200),
            curve: Curves.easeOut,
          );
        }
      });
    }
  }

  // ── Controls overlay ──────────────────────────────────────────────────

  void _scheduleHideControls() {
    _hideControlsTimer?.cancel();
    _hideControlsTimer = Timer(const Duration(seconds: 4), () {
      if (mounted) setState(() => _showControls = false);
    });
  }

  void _toggleControls() {
    setState(() => _showControls = !_showControls);
    if (_showControls) _scheduleHideControls();
  }

  // ── Reactions ─────────────────────────────────────────────────────────

  void _sendReaction(String emoji) {
    _svc.react(streamId: widget.stream.id, sessionId: _sessionId, emoji: emoji);
    setState(() {
      _floatingEmojis.add(_FloatingEmoji(emoji: emoji));
    });
    Future.delayed(const Duration(milliseconds: 2000), () {
      if (mounted) {
        setState(() => _floatingEmojis.removeWhere((e) => !e.alive));
      }
    });
  }

  // ── Chat ──────────────────────────────────────────────────────────────

  Future<void> _submitChat() async {
    final msg = _chatCtrl.text.trim();
    if (msg.isEmpty || _sendingChat) return;
    setState(() => _sendingChat = true);
    final ok = await _svc.sendChat(
      streamId : widget.stream.id,
      sessionId: _sessionId,
      author   : _chatAuthor,
      message  : msg,
    );
    if (!mounted) return;
    if (ok) {
      _chatCtrl.clear();
      // Optimistic: add locally
      setState(() {
        _chat.add(LiveChatMessage(
          id:       DateTime.now().millisecondsSinceEpoch,
          author:   _chatAuthor,
          message:  msg,
          isPinned: false,
          ts:       DateTime.now().toIso8601String(),
        ));
      });
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (_chatScroll.hasClients) {
          _chatScroll.animateTo(
            _chatScroll.position.maxScrollExtent,
            duration: const Duration(milliseconds: 200),
            curve: Curves.easeOut,
          );
        }
      });
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text(AppStrings.liveChatRateLimited)),
      );
    }
    setState(() => _sendingChat = false);
  }

  // ── Build ─────────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      body: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTap: _toggleControls,
        child: Stack(
          children: [
            // ── Video ──────────────────────────────────────────────────
            Center(child: _buildVideoArea()),

            // ── Floating reactions ─────────────────────────────────────
            ..._floatingEmojis.map((e) => _FloatingEmojiWidget(emoji: e)),

            // ── Chat overlay (right-side slide-in) ─────────────────────
            if (_chatVisible)
              Align(
                alignment: Alignment.centerRight,
                child: _ChatPanel(
                  chat:    _chat,
                  scroll:  _chatScroll,
                  ctrl:    _chatCtrl,
                  sending: _sendingChat,
                  onSend:  _submitChat,
                  onClose: () => setState(() => _chatVisible = false),
                ),
              ),

            // ── Controls overlay ───────────────────────────────────────
            if (_showControls) _buildControls(context),
          ],
        ),
      ),
    );
  }

  Widget _buildVideoArea() {
    if (_videoError) {
      return Column(
        mainAxisSize: MainAxisSize.min,
        children: const [
          Icon(Icons.broken_image, size: 64, color: Colors.white38),
          SizedBox(height: 12),
          Text('Stream unavailable', style: TextStyle(color: Colors.white60)),
        ],
      );
    }
    if (!_videoInitialized) {
      return const CircularProgressIndicator(color: AppColors.primary);
    }
    return AspectRatio(
      aspectRatio: _vpc!.value.aspectRatio,
      child: VideoPlayer(_vpc!),
    );
  }

  Widget _buildControls(BuildContext context) {
    return SafeArea(
      child: Column(
        children: [
          // ── Top bar ──────────────────────────────────────────────────
          Container(
            color: Colors.black45,
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
            child: Row(
              children: [
                IconButton(
                  icon: const Icon(Icons.arrow_back, color: Colors.white),
                  onPressed: () => Navigator.pop(context),
                ),
                const SizedBox(width: 4),
                Expanded(
                  child: Text(
                    widget.stream.title,
                    style: const TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.bold,
                        fontSize: 15),
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                // Live badge + viewer count
                if (_streamStatus == 'live') ...[
                  Container(
                    padding: const EdgeInsets.symmetric(
                        horizontal: 6, vertical: 2),
                    decoration: BoxDecoration(
                      color: AppColors.primary,
                      borderRadius: BorderRadius.circular(4),
                    ),
                    child: const Text('LIVE',
                        style: TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.w900,
                            fontSize: 11,
                            letterSpacing: 1)),
                  ),
                  const SizedBox(width: 6),
                  const Icon(Icons.visibility_outlined,
                      size: 14, color: Colors.white70),
                  const SizedBox(width: 3),
                  Text(
                    '$_viewerCount',
                    style: const TextStyle(color: Colors.white70, fontSize: 13),
                  ),
                  const SizedBox(width: 8),
                ],
              ],
            ),
          ),

          const Spacer(),

          // ── Bottom bar ────────────────────────────────────────────────
          Container(
            color: Colors.black45,
            padding:
                const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            child: Row(
              children: [
                // Play / pause
                if (_videoInitialized)
                  IconButton(
                    icon: Icon(
                      _vpc!.value.isPlaying
                          ? Icons.pause
                          : Icons.play_arrow,
                      color: Colors.white, size: 28,
                    ),
                    onPressed: () => setState(() {
                      _vpc!.value.isPlaying
                          ? _vpc!.pause()
                          : _vpc!.play();
                    }),
                  ),

                const Spacer(),

                // Emoji row
                ..._emojis.map((e) => GestureDetector(
                  onTap: () => _sendReaction(e),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 4),
                    child: Text(e, style: const TextStyle(fontSize: 22)),
                  ),
                )),

                const SizedBox(width: 8),

                // Chat toggle
                IconButton(
                  icon: const Icon(Icons.chat_bubble_outline,
                      color: Colors.white),
                  onPressed: () => setState(
                      () => _chatVisible = !_chatVisible),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Chat Panel

class _ChatPanel extends StatelessWidget {
  const _ChatPanel({
    required this.chat,
    required this.scroll,
    required this.ctrl,
    required this.sending,
    required this.onSend,
    required this.onClose,
  });

  final List<LiveChatMessage>  chat;
  final ScrollController       scroll;
  final TextEditingController  ctrl;
  final bool                   sending;
  final VoidCallback           onSend;
  final VoidCallback           onClose;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 260,
      color: Colors.black54,
      child: SafeArea(
        child: Column(
          children: [
            // Header
            Row(
              children: [
                const Padding(
                  padding: EdgeInsets.only(left: 8),
                  child: Text('Live Chat',
                      style: TextStyle(
                          color: Colors.white, fontWeight: FontWeight.bold)),
                ),
                const Spacer(),
                IconButton(
                  icon: const Icon(Icons.close, color: Colors.white70, size: 18),
                  onPressed: onClose,
                ),
              ],
            ),
            const Divider(color: Colors.white24, height: 1),
            // Messages
            Expanded(
              child: ListView.builder(
                controller: scroll,
                padding: const EdgeInsets.symmetric(
                    horizontal: 8, vertical: 4),
                itemCount: chat.length,
                itemBuilder: (_, i) {
                  final m = chat[i];
                  return Padding(
                    padding: const EdgeInsets.symmetric(vertical: 2),
                    child: RichText(
                      text: TextSpan(
                        children: [
                          TextSpan(
                            text: '${m.author}: ',
                            style: const TextStyle(
                                color: AppColors.accent,
                                fontWeight: FontWeight.bold,
                                fontSize: 12),
                          ),
                          TextSpan(
                            text: m.message,
                            style: const TextStyle(
                                color: Colors.white, fontSize: 12),
                          ),
                        ],
                      ),
                    ),
                  );
                },
              ),
            ),
            const Divider(color: Colors.white24, height: 1),
            // Input
            Padding(
              padding: const EdgeInsets.all(6),
              child: Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: ctrl,
                      style: const TextStyle(color: Colors.white, fontSize: 13),
                      decoration: InputDecoration(
                        hintText: AppStrings.liveChatHint,
                        hintStyle: const TextStyle(color: Colors.white38),
                        isDense: true,
                        contentPadding: const EdgeInsets.symmetric(
                            horizontal: 8, vertical: 8),
                        border: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(20),
                          borderSide: const BorderSide(color: Colors.white24),
                        ),
                        enabledBorder: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(20),
                          borderSide: const BorderSide(color: Colors.white24),
                        ),
                      ),
                      onSubmitted: (_) => onSend(),
                    ),
                  ),
                  const SizedBox(width: 4),
                  sending
                      ? const SizedBox(
                          width: 24, height: 24,
                          child: CircularProgressIndicator(
                              strokeWidth: 2, color: AppColors.primary))
                      : IconButton(
                          icon: const Icon(Icons.send,
                              color: AppColors.primary, size: 20),
                          onPressed: onSend,
                          padding: EdgeInsets.zero,
                          constraints: const BoxConstraints(),
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

// ─────────────────────────────────────────────────────────────────────────────
// Floating emoji animation helpers

class _FloatingEmoji {
  _FloatingEmoji({required this.emoji});
  final String emoji;
  bool alive = true;
}

class _FloatingEmojiWidget extends StatefulWidget {
  const _FloatingEmojiWidget({required this.emoji});
  final _FloatingEmoji emoji;

  @override
  State<_FloatingEmojiWidget> createState() => _FloatingEmojiWidgetState();
}

class _FloatingEmojiWidgetState extends State<_FloatingEmojiWidget>
    with SingleTickerProviderStateMixin {
  late final AnimationController _ac;
  late final Animation<double>   _opacity;
  late final Animation<double>   _offsetY;

  @override
  void initState() {
    super.initState();
    _ac = AnimationController(
        vsync: this, duration: const Duration(milliseconds: 1800))
      ..addStatusListener((s) {
        if (s == AnimationStatus.completed) widget.emoji.alive = false;
      })
      ..forward();

    _opacity = Tween<double>(begin: 1, end: 0).animate(
        CurvedAnimation(parent: _ac, curve: const Interval(0.5, 1)));
    _offsetY = Tween<double>(begin: 0, end: -100).animate(
        CurvedAnimation(parent: _ac, curve: Curves.easeOut));
  }

  @override
  void dispose() {
    _ac.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Positioned(
      right: 80,
      bottom: 100,
      child: AnimatedBuilder(
        animation: _ac,
        builder: (_, __) => Transform.translate(
          offset: Offset(0, _offsetY.value),
          child: Opacity(
            opacity: _opacity.value,
            child: Text(widget.emoji.emoji,
                style: const TextStyle(fontSize: 30)),
          ),
        ),
      ),
    );
  }
}
