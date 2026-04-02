import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:share_plus/share_plus.dart';
import '../../../data/models/news_article.dart';
import '../../../data/models/comment.dart';
import '../../../data/services/api_service.dart';
import '../../../data/services/news_service.dart';
import '../../../data/services/analytics_service.dart';
import '../../../data/services/realtime_service.dart';
import '../../../data/services/smart_notification_service.dart';
import '../../../data/services/moderation_service.dart';
import '../../../core/constants/app_strings.dart';
import '../../../core/constants/api_endpoints.dart';

/// ChangeNotifier that owns all state and business logic for the article
/// detail flow: loading, comments, typing indicators, share, analytics.
class ArticleDetailController extends ChangeNotifier {
  ArticleDetailController(this.slug) {
    nameCtrl    = TextEditingController();
    emailCtrl   = TextEditingController();
    contentCtrl = TextEditingController();
    contentCtrl.addListener(_onContentChanged);
    _init();
  }

  final String slug;

  final _apiSvc = ApiService();
  late final _api = NewsService(api: _apiSvc);
  final _rt       = RealtimeService();

  // ── Article ─────────────────────────────────────────────────────────
  NewsArticle? _article;
  bool         _loading = true;
  String?      _error;

  NewsArticle? get article => _article;
  bool         get loading => _loading;
  String?      get error   => _error;

  // ── Comments ─────────────────────────────────────────────────────────
  final List<Comment> _comments   = [];
  bool                _commLoading = false;
  final Set<int>      _commentIds  = {};

  List<Comment> get comments    => List.unmodifiable(_comments);
  bool          get commLoading => _commLoading;

  // ── Typing indicator ──────────────────────────────────────────────────
  Set<String> _typers = {};
  Set<String> get typers => _typers;

  // ── Comment form ───────────────────────────────────────────────────────
  late final TextEditingController nameCtrl;
  late final TextEditingController emailCtrl;
  late final TextEditingController contentCtrl;

  bool   _submitting = false;
  String _commMsg    = '';

  bool   get submitting => _submitting;
  String get commMsg    => _commMsg;

  // ── Init ───────────────────────────────────────────────────────────────

  void _init() {
    loadArticle();
    AnalyticsService.instance.logArticleOpen(0, slug, null);
    SmartNotificationService.instance.recordEngagement();
  }

  // ── Typing ─────────────────────────────────────────────────────────────

  void _onContentChanged() {
    if (_article == null) return;
    final name = nameCtrl.text.trim();
    if (contentCtrl.text.isNotEmpty) {
      _rt.reportTyping(
        newsId:      _article!.id,
        userId:      'user_${name.hashCode}',
        displayName: name.isEmpty ? 'Someone' : name,
      );
    }
  }

  // ── Article loading ────────────────────────────────────────────────────

  Future<void> loadArticle() async {
    _loading = true;
    _error   = null;
    notifyListeners();
    try {
      final art = await _api.getArticleDetail(slug);
      _article = art;
      if (art != null) {
        await _loadComments(art.id);
        _rt.listenToComments(
          newsId:    art.id,
          onComment: _onRtComment,
        );
        _rt.listenToTyping(
          newsId:   art.id,
          userId:   'user_${art.id}',
          onTypers: (typers) {
            _typers = typers;
            notifyListeners();
          },
        );
        AnalyticsService.instance
            .logArticleOpen(art.id, art.slug, art.categoryName);
      }
    } on ApiException catch (e) {
      _error = e.message;
    } catch (_) {
      _error = AppStrings.loadingFailed;
    }
    _loading = false;
    notifyListeners();
  }

  // ── RTDB comment callback ──────────────────────────────────────────────

  void _onRtComment(Comment comment) {
    if (_commentIds.contains(comment.id)) return;
    _commentIds.add(comment.id);
    _comments.add(comment);
    notifyListeners();
  }

  // ── Comment HTTP seed ──────────────────────────────────────────────────

  Future<void> _loadComments(int newsId) async {
    _commLoading = true;
    notifyListeners();
    try {
      final fetched = await _api.getComments(newsId);
      for (final c in fetched) {
        if (!_commentIds.contains(c.id)) {
          _commentIds.add(c.id);
          _comments.add(c);
        }
      }
    } catch (_) {}
    _commLoading = false;
    notifyListeners();
  }

  // ── Submit comment ─────────────────────────────────────────────────────

  Future<void> submitComment() async {
    if (_article == null) return;
    final name    = nameCtrl.text.trim();
    final content = contentCtrl.text.trim();
    if (name.isEmpty || content.isEmpty) {
      _commMsg = 'Name and comment are required.';
      notifyListeners();
      return;
    }

    final modResult = ModerationService.instance.analyseComment(content);
    if (modResult.isBlock) {
      _commMsg = modResult.reason;
      notifyListeners();
      return;
    }

    _submitting = true;
    _commMsg    = '';
    notifyListeners();

    _rt.clearTyping(
      newsId: _article!.id,
      userId: 'user_${name.hashCode}',
    );

    try {
      final res = await _api.submitComment(
        newsId:      _article!.id,
        authorName:  name,
        content:     content,
        authorEmail: emailCtrl.text.trim().isEmpty
            ? null
            : emailCtrl.text.trim(),
      );
      _commMsg = modResult.isWarn
          ? modResult.reason
          : (res['message'] as String? ?? AppStrings.commentSubmitted);
      if (res['success'] == true) {
        nameCtrl.clear();
        emailCtrl.clear();
        contentCtrl.clear();
        await AnalyticsService.instance.logCommentPost(_article!.id);
      }
    } on ApiException catch (e) {
      _commMsg = e.message;
    } catch (_) {
      _commMsg = 'Could not submit comment.';
    }
    _submitting = false;
    notifyListeners();
  }

  // ── Share ──────────────────────────────────────────────────────────────

  void share() {
    if (_article == null) return;
    HapticFeedback.lightImpact();
    final url =
        '${ApiEndpoints.baseUrl}/news/detail.php?slug=${_article!.slug}&source=app';
    Share.share('${_article!.title}\n$url');
    AnalyticsService.instance.logShareClick(_article!.id, _article!.slug);
    _trackShare(_article!.id);
  }

  Future<void> _trackShare(int newsId) async {
    try {
      await _apiSvc.postJson(
        ApiEndpoints.shareTrack,
        body: {'news_id': newsId},
      );
    } catch (_) {
      // Non-fatal — viral score recalculated by cron
    }
  }

  // ── Dispose ────────────────────────────────────────────────────────────

  @override
  void dispose() {
    contentCtrl.removeListener(_onContentChanged);
    if (_article != null) {
      _rt.stopListeningToComments(_article!.id);
      _rt.stopListeningToTyping(_article!.id);
    }
    _rt.dispose();
    nameCtrl.dispose();
    emailCtrl.dispose();
    contentCtrl.dispose();
    _api.dispose();
    super.dispose();
  }
}
