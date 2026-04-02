import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_html/flutter_html.dart';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:provider/provider.dart';
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../../data/models/news_article.dart';
import '../../../data/models/comment.dart';
import '../../../data/services/api_service.dart';
import '../../../data/services/news_service.dart';
import '../../../data/services/analytics_service.dart';
import '../../../data/services/realtime_service.dart';
import '../../../providers/bookmark_provider.dart';
import '../../../providers/offline_provider.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_strings.dart';
import '../../../core/constants/api_endpoints.dart';
import '../../../core/utils/date_formatter.dart';
import '../../../core/utils/string_utils.dart';
import '../../widgets/reporter_profile_card.dart';
import '../../widgets/ad_banner_widget.dart';
import '../../widgets/tts_player_widget.dart';
import '../../../providers/tts_provider.dart';

/// Full article detail screen.
class ArticleDetailScreen extends StatefulWidget {
  const ArticleDetailScreen({super.key, required this.slug});

  final String slug;

  @override
  State<ArticleDetailScreen> createState() => _ArticleDetailScreenState();
}

class _ArticleDetailScreenState extends State<ArticleDetailScreen> {
  final _apiSvc  = ApiService();
  late final _api = NewsService(api: _apiSvc);
  final _rt      = RealtimeService();
  NewsArticle?   _article;
  bool           _loading   = true;
  String?        _error;

  // Comments (seeded from HTTP, then kept live via RTDB)
  final List<Comment> _comments    = [];
  bool                _commLoading = false;
  // IDs already added — prevents HTTP + RTDB duplicates
  final Set<int>      _commentIds  = {};

  // Typing indicator
  Set<String> _typers = {};

  // Comment form controllers
  final _nameCtrl    = TextEditingController();
  final _emailCtrl   = TextEditingController();
  final _contentCtrl = TextEditingController();
  bool   _submitting  = false;
  String _commMsg     = '';

  @override
  void initState() {
    super.initState();
    _loadArticle();
    // Track article open event
    AnalyticsService.instance.logArticleOpen(0, widget.slug, null);
    // Report typing when user types in comment box
    _contentCtrl.addListener(_onContentChanged);
  }

  void _onContentChanged() {
    if (_article == null) return;
    final name = _nameCtrl.text.trim();
    if (_contentCtrl.text.isNotEmpty) {
      _rt.reportTyping(
        newsId:      _article!.id,
        userId:      'user_${name.hashCode}',
        displayName: name.isEmpty ? 'Someone' : name,
      );
    }
  }

  Future<void> _loadArticle() async {
    setState(() { _loading = true; _error = null; });
    try {
      final art = await _api.getArticleDetail(widget.slug);
      _article = art;
      if (art != null) {
        await _loadComments(art.id);
        // Start real-time comment stream AFTER initial HTTP load
        _rt.listenToComments(
          newsId:    art.id,
          onComment: _onRtComment,
        );
        // Start typing listener
        _rt.listenToTyping(
          newsId:  art.id,
          userId:  'user_${art.id}',
          onTypers: (typers) {
            if (mounted) setState(() => _typers = typers);
          },
        );
        // Update analytics with actual article data
        AnalyticsService.instance
            .logArticleOpen(art.id, art.slug, art.categoryName);
      }
    } on ApiException catch (e) {
      _error = e.message;
    } catch (_) {
      _error = AppStrings.loadingFailed;
    }
    if (mounted) setState(() => _loading = false);
  }

  /// Called for each comment pushed to RTDB (both on initial subscription
  /// and whenever a new comment is approved by admin).
  void _onRtComment(Comment comment) {
    if (_commentIds.contains(comment.id)) return;
    _commentIds.add(comment.id);
    if (mounted) setState(() => _comments.add(comment));
  }

  Future<void> _loadComments(int newsId) async {
    setState(() => _commLoading = true);
    try {
      final fetched = await _api.getComments(newsId);
      for (final c in fetched) {
        if (!_commentIds.contains(c.id)) {
          _commentIds.add(c.id);
          _comments.add(c);
        }
      }
    } catch (_) {}
    if (mounted) setState(() => _commLoading = false);
  }

  Future<void> _submitComment() async {
    if (_article == null) return;
    final name    = _nameCtrl.text.trim();
    final content = _contentCtrl.text.trim();
    if (name.isEmpty || content.isEmpty) {
      setState(() => _commMsg = 'Name and comment are required.');
      return;
    }
    setState(() { _submitting = true; _commMsg = ''; });
    // Clear typing indicator immediately on submit
    _rt.clearTyping(
      newsId: _article!.id,
      userId: 'user_${name.hashCode}',
    );
    try {
      final res = await _api.submitComment(
        newsId:      _article!.id,
        authorName:  name,
        content:     content,
        authorEmail: _emailCtrl.text.trim().isEmpty ? null : _emailCtrl.text.trim(),
      );
      _commMsg = res['message'] as String? ?? AppStrings.commentSubmitted;
      if (res['success'] == true) {
        _nameCtrl.clear();
        _emailCtrl.clear();
        _contentCtrl.clear();
        await AnalyticsService.instance.logCommentPost(_article!.id);
      }
    } on ApiException catch (e) {
      _commMsg = e.message;
    } catch (_) {
      _commMsg = 'Could not submit comment.';
    }
    if (mounted) setState(() => _submitting = false);
  }

  void _share() {
    if (_article == null) return;
    HapticFeedback.lightImpact();
    final url = '${ApiEndpoints.baseUrl}/news/detail.php?slug=${_article!.slug}&source=app';
    Share.share('${_article!.title}\n$url');
    AnalyticsService.instance.logShareClick(_article!.id, _article!.slug);
    // Track share for viral score
    _trackShare(_article!.id);
  }

  Future<void> _trackShare(int newsId) async {
    try {
      await _apiSvc.postJson(
        ApiEndpoints.shareTrack,
        body: {'news_id': newsId},
      );
    } catch (_) {
      // Non-fatal — viral score will be recalculated by cron
    }
  }

  @override
  void dispose() {
    _contentCtrl.removeListener(_onContentChanged);
    if (_article != null) {
      _rt.stopListeningToComments(_article!.id);
      _rt.stopListeningToTyping(_article!.id);
    }
    _rt.dispose();
    _nameCtrl.dispose();
    _emailCtrl.dispose();
    _contentCtrl.dispose();
    _api.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Scaffold(
        body: Center(child: CircularProgressIndicator(color: AppColors.primary)),
      );
    }
    if (_error != null || _article == null) {
      return Scaffold(
        appBar: AppBar(),
        body: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(_error ?? 'Article not found'),
              const SizedBox(height: 12),
              ElevatedButton(
                  onPressed: _loadArticle,
                  child: const Text(AppStrings.retry)),
            ],
          ),
        ),
      );
    }

    final art   = _article!;
    final theme = Theme.of(context);

    return Scaffold(
      body: CustomScrollView(
        slivers: [
          // App Bar with hero image
          SliverAppBar(
            expandedHeight: art.featuredImage != null ? 220 : 56,
            pinned: true,
            flexibleSpace: art.featuredImage != null
                ? FlexibleSpaceBar(
                    background: CachedNetworkImage(
                      imageUrl: art.featuredImage!,
                      fit: BoxFit.cover,
                    ),
                  )
                : null,
            actions: [
              // TTS Listen button
              Consumer<TtsProvider>(
                builder: (_, tts, __) {
                  final isThisArticle = tts.articleId == art.id;
                  return IconButton(
                    icon: Icon(
                      isThisArticle && tts.isActive
                          ? Icons.headphones_rounded
                          : Icons.headphones_outlined,
                      color: isThisArticle && tts.isActive
                          ? AppColors.primary
                          : null,
                    ),
                    tooltip: AppStrings.ttsListen,
                    onPressed: () {
                      if (isThisArticle && tts.isActive) {
                        tts.stop();
                      } else {
                        tts.speak(
                          articleId: art.id,
                          title:     art.title,
                          plainText: StringUtils.stripHtml(art.content),
                        );
                      }
                    },
                  );
                },
              ),
              IconButton(
                icon: const Icon(Icons.share_rounded),
                tooltip: AppStrings.share,
                onPressed: _share,
              ),
              // Offline download toggle
              Consumer<OfflineProvider>(
                builder: (_, offline, __) {
                  final saved = offline.isOffline(art.slug);
                  return IconButton(
                    icon: Icon(saved
                        ? Icons.download_done_rounded
                        : Icons.download_for_offline_outlined),
                    tooltip: saved
                        ? AppStrings.offlineRemove
                        : AppStrings.offlineDownload,
                    onPressed: () async {
                      HapticFeedback.lightImpact();
                      await offline.toggle(art);
                      if (context.mounted) {
                        ScaffoldMessenger.of(context).showSnackBar(
                          SnackBar(
                            content: Text(saved
                                ? AppStrings.offlineRemovedSnack
                                : AppStrings.offlineSavedSnack),
                            duration: const Duration(seconds: 2),
                          ),
                        );
                      }
                    },
                  );
                },
              ),
              Consumer<BookmarkProvider>(
                builder: (_, bm, __) {
                  final saved = bm.isBookmarked(art.id);
                  return IconButton(
                    icon: Icon(saved
                        ? Icons.bookmark_rounded
                        : Icons.bookmark_border_rounded),
                    tooltip: AppStrings.bookmark,
                    onPressed: () {
                      HapticFeedback.lightImpact();
                      bm.toggle(art);
                      if (saved) {
                        AnalyticsService.instance
                            .logBookmarkRemove(art.id, art.slug);
                      } else {
                        AnalyticsService.instance
                            .logBookmarkAdd(art.id, art.slug);
                      }
                    },
                  );
                },
              ),
            ],
          ),

          // Content
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Badges
                  if ((art.categoryName?.isNotEmpty ?? false) || art.isBreaking)
                    Wrap(spacing: 6, children: [
                      if (art.isBreaking) _badge('BREAKING', AppColors.breakingBadge),
                      if (art.categoryName?.isNotEmpty ?? false)
                        _badge(art.categoryName!, AppColors.primary),
                    ]),
                  const SizedBox(height: 10),

                  // Title
                  Text(art.title,
                      style: theme.textTheme.headlineLarge?.copyWith(height: 1.35)),
                  const SizedBox(height: 8),

                  // Meta
                  Wrap(
                    spacing: 12,
                    runSpacing: 4,
                    children: [
                      if (art.reporterName?.isNotEmpty ?? false)
                        _metaChip(Icons.person_outline, art.reporterName!),
                      _metaChip(Icons.access_time,
                          DateFormatter.timeAgo(art.createdAt)),
                      _metaChip(Icons.auto_stories_outlined,
                          '${StringUtils.readingMinutes(art.content)} min read'),
                      if (art.views > 0)
                        _metaChip(Icons.visibility_outlined,
                            '${StringUtils.formatViews(art.views)} views'),
                    ],
                  ),

                  const Divider(height: 24),

                  // TTS inline player (visible when this article is playing)
                  Consumer<TtsProvider>(
                    builder: (_, tts, __) =>
                        tts.articleId == art.id
                            ? const TtsPlayerWidget()
                            : const SizedBox.shrink(),
                  ),

                  // Article body (HTML)
                  Html(
                    data: art.content,
                    onAnchorTap: (url, _, __) {
                      if (url != null) launchUrl(Uri.parse(url));
                    },
                    style: {
                      'body': Style(
                        fontSize: FontSize(15),
                        lineHeight: LineHeight(1.7),
                        color: theme.textTheme.bodyLarge?.color,
                      ),
                    },
                  ),

                  // Banner ad (after article body)
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 12),
                    child: AdBannerWidget(),
                  ),

                  // Reporter / Agency profile card
                  if (art.reporterName != null &&
                      art.reporterName!.isNotEmpty) ...[
                    const Divider(height: 24),
                    ReporterProfileCard(
                      reporterName:  art.reporterName!,
                      reporterPhoto: art.reporterPhoto,
                      agencyName:    art.agencyName,
                    ),
                  ],

                  const Divider(height: 32),

                  // Comments section
                  Text(AppStrings.comments,
                      style: theme.textTheme.headlineMedium),
                  const SizedBox(height: 12),

                  if (_commLoading)
                    const Center(
                        child: CircularProgressIndicator(
                            color: AppColors.primary))
                  else if (_comments.isEmpty)
                    Text(AppStrings.noComments,
                        style: theme.textTheme.bodySmall)
                  else
                    ..._comments.map(_buildComment),

                  // Typing indicator
                  if (_typers.isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(top: 4, bottom: 8),
                      child: Row(
                        children: [
                          const _TypingDots(),
                          const SizedBox(width: 8),
                          Text(
                            '${_typers.join(', ')} ${_typers.length == 1 ? 'is' : 'are'} typing…',
                            style: const TextStyle(
                                fontSize: 12, color: Colors.grey),
                          ),
                        ],
                      ),
                    ),

                  const SizedBox(height: 24),

                  // Comment form
                  _buildCommentForm(context),

                  const SizedBox(height: 40),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  // ── Comment display ───────────────────────────────────────────────────

  Widget _buildComment(Comment c) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            CircleAvatar(
              radius: 16,
              backgroundColor: AppColors.primary.withOpacity(0.15),
              child: Text(StringUtils.initials(c.authorName),
                  style: const TextStyle(
                      color: AppColors.primary,
                      fontSize: 12,
                      fontWeight: FontWeight.bold)),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(c.authorName,
                    style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                Text(DateFormatter.timeAgo(c.createdAt),
                    style: const TextStyle(fontSize: 11, color: Colors.grey)),
              ]),
            ),
          ]),
          const SizedBox(height: 6),
          Padding(
            padding: const EdgeInsets.only(left: 40),
            child: Text(c.content, style: const TextStyle(fontSize: 14, height: 1.5)),
          ),
          // Nested replies
          if (c.replies.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(left: 24, top: 8),
              child: Column(children: c.replies.map(_buildComment).toList()),
            ),
        ],
      ),
    );
  }

  // ── Comment form ──────────────────────────────────────────────────────

  Widget _buildCommentForm(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(AppStrings.leaveComment,
            style: Theme.of(context).textTheme.titleLarge),
        const SizedBox(height: 12),
        TextField(
          controller: _nameCtrl,
          decoration: const InputDecoration(
              labelText: AppStrings.commentNameHint),
          textInputAction: TextInputAction.next,
        ),
        const SizedBox(height: 10),
        TextField(
          controller: _emailCtrl,
          decoration: const InputDecoration(
              labelText: AppStrings.commentEmailHint),
          keyboardType: TextInputType.emailAddress,
          textInputAction: TextInputAction.next,
        ),
        const SizedBox(height: 10),
        TextField(
          controller: _contentCtrl,
          decoration: const InputDecoration(
              labelText: AppStrings.commentContentHint),
          maxLines: 4,
          maxLength: 1000,
        ),
        if (_commMsg.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(_commMsg,
                style: TextStyle(
                    color: _commMsg.contains('submitted')
                        ? Colors.green
                        : Colors.red,
                    fontSize: 13)),
          ),
        const SizedBox(height: 12),
        SizedBox(
          width: double.infinity,
          child: ElevatedButton(
            onPressed: _submitting ? null : _submitComment,
            child: _submitting
                ? const SizedBox(
                    height: 18,
                    width: 18,
                    child: CircularProgressIndicator(
                        color: Colors.white, strokeWidth: 2))
                : const Text(AppStrings.postComment),
          ),
        ),
      ],
    );
  }

  // ── Helpers ───────────────────────────────────────────────────────────

  Widget _badge(String text, Color color) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
    decoration:
        BoxDecoration(color: color, borderRadius: BorderRadius.circular(4)),
    child: Text(text.toUpperCase(),
        style: const TextStyle(
            color: Colors.white,
            fontSize: 10,
            fontWeight: FontWeight.w700,
            letterSpacing: 0.4)),
  );

  Widget _metaChip(IconData icon, String label) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      Icon(icon, size: 13, color: Colors.grey),
      const SizedBox(width: 3),
      Text(label,
          style: const TextStyle(fontSize: 12, color: Colors.grey)),
    ],
  );
}

// ── Typing dots animation ─────────────────────────────────────────────────────

class _TypingDots extends StatefulWidget {
  const _TypingDots();

  @override
  State<_TypingDots> createState() => _TypingDotsState();
}

class _TypingDotsState extends State<_TypingDots>
    with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl;
  late final Animation<double>    _anim;

  @override
  void initState() {
    super.initState();
    _ctrl = AnimationController(
      vsync:    this,
      duration: const Duration(milliseconds: 900),
    )..repeat();
    _anim = CurvedAnimation(parent: _ctrl, curve: Curves.easeInOut);
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _anim,
      builder: (_, __) {
        return Row(
          mainAxisSize: MainAxisSize.min,
          children: List.generate(3, (i) {
            final phase = (_anim.value + i / 3) % 1.0;
            final opacity = (phase < 0.5 ? phase * 2 : (1 - phase) * 2)
                .clamp(0.2, 1.0);
            return Padding(
              padding: const EdgeInsets.symmetric(horizontal: 1),
              child: Opacity(
                opacity: opacity,
                child: const CircleAvatar(
                  radius:          3,
                  backgroundColor: Colors.grey,
                ),
              ),
            );
          }),
        );
      },
    );
  }
}
