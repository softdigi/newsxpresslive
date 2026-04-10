/// Data model for a live news stream.
class LiveStreamModel {
  final int     id;
  final String  title;
  final String? description;
  final String? thumbnail;
  final String? playbackUrl;
  final String  status; // "live" | "scheduled" | "ended" | "cancelled"
  final int     viewerCount;
  final int     peakViewers;
  final int     reactionCount;
  final bool    isFeatured;
  final String? scheduledAt;
  final String? startedAt;
  final String? endedAt;
  final String  createdAt;
  final String  authorName;
  final String? authorPhoto;
  final String  authorType; // "reporter" | "agency"

  const LiveStreamModel({
    required this.id,
    required this.title,
    this.description,
    this.thumbnail,
    this.playbackUrl,
    required this.status,
    required this.viewerCount,
    required this.peakViewers,
    required this.reactionCount,
    required this.isFeatured,
    this.scheduledAt,
    this.startedAt,
    this.endedAt,
    required this.createdAt,
    required this.authorName,
    this.authorPhoto,
    required this.authorType,
  });

  bool get isLive      => status == 'live';
  bool get isScheduled => status == 'scheduled';
  bool get isEnded     => status == 'ended';

  factory LiveStreamModel.fromJson(Map<String, dynamic> json) {
    return LiveStreamModel(
      id:            _parseInt(json['id']),
      title:         json['title']         as String? ?? '',
      description:   json['description']   as String?,
      thumbnail:     json['thumbnail']     as String?,
      playbackUrl:   json['playback_url']  as String?,
      status:        json['status']        as String? ?? 'scheduled',
      viewerCount:   _parseInt(json['viewer_count']),
      peakViewers:   _parseInt(json['peak_viewers']),
      reactionCount: _parseInt(json['reaction_count']),
      isFeatured:    json['is_featured'] == true || json['is_featured'] == 1,
      scheduledAt:   json['scheduled_at'] as String?,
      startedAt:     json['started_at']   as String?,
      endedAt:       json['ended_at']     as String?,
      createdAt:     json['created_at']   as String? ?? '',
      authorName:    json['author_name']  as String? ?? 'NewsXpress',
      authorPhoto:   json['author_photo'] as String?,
      authorType:    json['author_type']  as String? ?? 'reporter',
    );
  }

  static int _parseInt(dynamic v) {
    if (v is int)    return v;
    if (v is double) return v.toInt();
    if (v is String) return int.tryParse(v) ?? 0;
    return 0;
  }
}

/// A single chat message in a live stream.
class LiveChatMessage {
  final int    id;
  final String author;
  final String message;
  final bool   isPinned;
  final String ts;

  const LiveChatMessage({
    required this.id,
    required this.author,
    required this.message,
    required this.isPinned,
    required this.ts,
  });

  factory LiveChatMessage.fromJson(Map<String, dynamic> json) {
    return LiveChatMessage(
      id:       json['id'] is int ? json['id'] : int.tryParse('${json['id']}') ?? 0,
      author:   json['author']    as String? ?? 'Viewer',
      message:  json['message']   as String? ?? '',
      isPinned: json['is_pinned'] == true || json['is_pinned'] == 1,
      ts:       json['ts']        as String? ?? '',
    );
  }
}

/// Heartbeat response — viewer count + recent chat.
class HeartbeatData {
  final int                  viewerCount;
  final String               streamStatus;
  final int                  reactionCount;
  final List<LiveChatMessage> chat;

  const HeartbeatData({
    required this.viewerCount,
    required this.streamStatus,
    required this.reactionCount,
    required this.chat,
  });

  factory HeartbeatData.fromJson(Map<String, dynamic> json) {
    final chatList = (json['chat'] as List<dynamic>? ?? [])
        .map((m) => LiveChatMessage.fromJson(m as Map<String, dynamic>))
        .toList();
    return HeartbeatData(
      viewerCount:   json['viewer_count']   is int ? json['viewer_count']   : int.tryParse('${json['viewer_count']}')   ?? 0,
      streamStatus:  json['stream_status']  as String? ?? 'live',
      reactionCount: json['reaction_count'] is int ? json['reaction_count'] : int.tryParse('${json['reaction_count']}') ?? 0,
      chat:          chatList,
    );
  }
}
