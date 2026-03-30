import 'dart:async';
import 'package:firebase_database/firebase_database.dart';
import '../models/comment.dart';
import '../models/news_article.dart';

/// Manages Firebase Realtime Database subscriptions for real-time features:
///
///  • Live comments  — `/live/comments/{newsId}/`
///  • Typing         — `/live/typing/{newsId}/`
///  • Breaking news  — `/live/breaking/latest`
///
/// ## Usage
/// ```dart
/// // In a StatefulWidget:
/// late final _rt = RealtimeService();
///
/// @override
/// void initState() {
///   super.initState();
///   _rt.listenToComments(newsId: article.id, onComment: _handleNewComment);
///   _rt.listenToTyping(newsId: article.id, userId: _myKey, onTypers: setState);
///   _rt.listenToBreaking(onBreaking: _handleBreaking);
/// }
///
/// @override
/// void dispose() {
///   _rt.dispose();
///   super.dispose();
/// }
/// ```
class RealtimeService {
  final FirebaseDatabase _db;

  /// Set to a non-null value after calling [listenToBreaking].
  StreamSubscription<DatabaseEvent>? _breakingSub;

  /// Per-article subscriptions (keyed by newsId).
  final Map<int, StreamSubscription<DatabaseEvent>> _commentSubs = {};
  final Map<int, StreamSubscription<DatabaseEvent>> _typingSubs  = {};

  /// Timer that periodically clears this user's typing presence.
  Timer? _typingTimer;

  /// Seconds of inactivity before a typing presence record auto-expires.
  static const int _typingTtlSeconds = 8;

  RealtimeService({FirebaseDatabase? db})
      : _db = db ?? FirebaseDatabase.instance;

  // ── Comments ─────────────────────────────────────────────────────────────

  /// Subscribe to approved comments for [newsId].
  ///
  /// [onComment] is called once for each existing approved comment (initial
  /// load) and again whenever a new one is written by the admin.
  void listenToComments({
    required int newsId,
    required void Function(Comment comment) onComment,
  }) {
    _commentSubs[newsId]?.cancel();

    final ref = _db.ref('live/comments/$newsId');
    _commentSubs[newsId] = ref.onChildAdded.listen((event) {
      final snap = event.snapshot;
      if (snap.value == null) return;

      final data = Map<String, dynamic>.from(snap.value as Map);
      // Ensure the key types that Comment.fromJson expects
      data['id']      = data['id']      ?? snap.key;
      data['news_id'] = data['news_id'] ?? newsId;

      try {
        onComment(Comment.fromJson(data));
      } catch (_) {
        // Malformed data — skip
      }
    });
  }

  void stopListeningToComments(int newsId) {
    _commentSubs[newsId]?.cancel();
    _commentSubs.remove(newsId);
  }

  // ── Typing indicator ─────────────────────────────────────────────────────

  /// Report that [userId] is typing in the comments for [newsId].
  ///
  /// Writes a presence record that expires after [_typingTtlSeconds] of
  /// inactivity (the timer is reset on each call).
  Future<void> reportTyping({
    required int    newsId,
    required String userId,
    required String displayName,
  }) async {
    _typingTimer?.cancel();

    await _db.ref('live/typing/$newsId/$userId').set({
      'name': displayName,
      'ts':   ServerValue.timestamp,
    });

    // Auto-remove typing presence after TTL
    _typingTimer = Timer(
      Duration(seconds: _typingTtlSeconds),
      () => clearTyping(newsId: newsId, userId: userId),
    );
  }

  /// Remove this user's typing presence immediately (e.g., on submit / blur).
  Future<void> clearTyping({
    required int    newsId,
    required String userId,
  }) async {
    _typingTimer?.cancel();
    await _db.ref('live/typing/$newsId/$userId').remove();
  }

  /// Subscribe to the typing presence list for [newsId].
  ///
  /// [onTypers] receives the set of display names currently typing.
  /// Records older than [_typingTtlSeconds] × 2 are treated as stale and
  /// excluded from the set.
  void listenToTyping({
    required int newsId,
    required String userId,
    required void Function(Set<String> typers) onTypers,
  }) {
    _typingSubs[newsId]?.cancel();

    final ref = _db.ref('live/typing/$newsId');
    _typingSubs[newsId] = ref.onValue.listen((event) {
      final snap = event.snapshot;
      if (snap.value == null) {
        onTypers({});
        return;
      }

      final now     = DateTime.now().millisecondsSinceEpoch;
      final staleMs = _typingTtlSeconds * 2 * 1000;
      final raw     = Map<String, dynamic>.from(snap.value as Map);

      final typers = <String>{};
      for (final entry in raw.entries) {
        // Skip our own presence
        if (entry.key == userId) continue;

        final record = Map<String, dynamic>.from(entry.value as Map);
        final ts     = record['ts'];
        if (ts == null) continue;

        final tsMs = ts is int ? ts : int.tryParse(ts.toString()) ?? 0;
        if (now - tsMs < staleMs) {
          final name = record['name'] as String?;
          if (name != null && name.isNotEmpty) {
            typers.add(name);
          }
        }
      }

      onTypers(typers);
    });
  }

  void stopListeningToTyping(int newsId) {
    _typingSubs[newsId]?.cancel();
    _typingSubs.remove(newsId);
  }

  // ── Breaking news ─────────────────────────────────────────────────────────

  /// Subscribe to `/live/breaking/latest`.
  ///
  /// [onBreaking] is called whenever admin marks an article as breaking.
  /// The callback receives a minimal [NewsArticle] built from the RTDB stub.
  void listenToBreaking({
    required void Function(NewsArticle article) onBreaking,
  }) {
    _breakingSub?.cancel();

    final ref = _db.ref('live/breaking/latest');
    _breakingSub = ref.onValue.listen((event) {
      final snap = event.snapshot;
      if (snap.value == null) return;

      final data = Map<String, dynamic>.from(snap.value as Map);
      try {
        onBreaking(NewsArticle.fromJson({
          'id':             data['id'],
          'title':          data['title'],
          'slug':           data['slug'],
          'content':        '',
          'featured_image': data['image'],
          'created_at':     data['created_at'] ?? '',
          'is_breaking':    1,
        }));
      } catch (_) {}
    });
  }

  void stopListeningToBreaking() {
    _breakingSub?.cancel();
    _breakingSub = null;
  }

  // ── Dispose ───────────────────────────────────────────────────────────────

  /// Cancel all active subscriptions and timers.
  void dispose() {
    _breakingSub?.cancel();
    for (final sub in _commentSubs.values) {
      sub.cancel();
    }
    for (final sub in _typingSubs.values) {
      sub.cancel();
    }
    _typingTimer?.cancel();
    _commentSubs.clear();
    _typingSubs.clear();
  }
}
