/// Represents a user comment on a news article.
class Comment {
  final int     id;
  final int     newsId;
  final int?    parentId;
  final String  authorName;
  final String  content;
  final String  createdAt;
  final List<Comment> replies;

  const Comment({
    required this.id,
    required this.newsId,
    this.parentId,
    required this.authorName,
    required this.content,
    required this.createdAt,
    this.replies = const [],
  });

  factory Comment.fromJson(Map<String, dynamic> json) => Comment(
    id:         int.tryParse(json['id'].toString()) ?? 0,
    newsId:     int.tryParse(json['news_id'].toString()) ?? 0,
    parentId:   json['parent_id'] == null
                ? null
                : int.tryParse(json['parent_id'].toString()),
    authorName: json['author_name'] as String? ?? 'Anonymous',
    content:    json['content']     as String? ?? '',
    createdAt:  json['created_at']  as String? ?? '',
  );

  /// Creates a copy with replies attached (used when building threaded tree).
  Comment withReplies(List<Comment> replies) => Comment(
    id:         id,
    newsId:     newsId,
    parentId:   parentId,
    authorName: authorName,
    content:    content,
    createdAt:  createdAt,
    replies:    replies,
  );

  @override
  bool operator ==(Object other) => other is Comment && other.id == id;

  @override
  int get hashCode => id.hashCode;
}

/// Converts a flat comment list into a threaded tree.
List<Comment> buildCommentTree(List<Comment> flat) {
  final topLevel = flat.where((c) => c.parentId == null).toList();
  return topLevel.map((parent) {
    final replies = flat.where((c) => c.parentId == parent.id).toList();
    return parent.withReplies(replies);
  }).toList();
}
