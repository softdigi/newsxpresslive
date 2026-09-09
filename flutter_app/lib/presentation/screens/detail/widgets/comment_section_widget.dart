import 'package:flutter/material.dart';
import '../../../../data/models/comment.dart';
import '../../../../core/constants/app_colors.dart';
import '../../../../core/constants/app_strings.dart';
import '../../../../core/utils/date_formatter.dart';
import '../../../../core/utils/string_utils.dart';

/// Renders the full comment section: loading indicator, empty state,
/// comment list with nested replies, and the live typing indicator.
class CommentSectionWidget extends StatelessWidget {
  const CommentSectionWidget({
    super.key,
    required this.comments,
    required this.commLoading,
    required this.typers,
  });

  final List<Comment> comments;
  final bool          commLoading;
  final Set<String>   typers;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(AppStrings.comments, style: theme.textTheme.headlineMedium),
        const SizedBox(height: 12),

        if (commLoading)
          const Center(
            child: CircularProgressIndicator(color: AppColors.primary),
          )
        else if (comments.isEmpty)
          Text(AppStrings.noComments, style: theme.textTheme.bodySmall)
        else
          ...comments.map(_buildComment),

        // Live typing indicator
        if (typers.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 4, bottom: 8),
            child: Row(
              children: [
                const _TypingDots(),
                const SizedBox(width: 8),
                Text(
                  '${typers.join(', ')} '
                  '${typers.length == 1 ? 'is' : 'are'} typing…',
                  style: const TextStyle(fontSize: 12, color: Colors.grey),
                ),
              ],
            ),
          ),
      ],
    );
  }

  // ── Comment tile ───────────────────────────────────────────────────────

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
              child: Text(
                StringUtils.initials(c.authorName),
                style: const TextStyle(
                  color:      AppColors.primary,
                  fontSize:   12,
                  fontWeight: FontWeight.bold,
                ),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(c.authorName,
                      style: const TextStyle(
                          fontWeight: FontWeight.w600, fontSize: 13)),
                  Text(DateFormatter.timeAgo(c.createdAt),
                      style: const TextStyle(
                          fontSize: 11, color: Colors.grey)),
                ],
              ),
            ),
          ]),
          const SizedBox(height: 6),
          Padding(
            padding: const EdgeInsets.only(left: 40),
            child: Text(c.content,
                style: const TextStyle(fontSize: 14, height: 1.5)),
          ),
          // Nested replies
          if (c.replies.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(left: 24, top: 8),
              child: Column(
                  children: c.replies.map(_buildComment).toList()),
            ),
        ],
      ),
    );
  }
}

// ── Typing-dots animation ─────────────────────────────────────────────────────

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
      builder: (_, __) => Row(
        mainAxisSize: MainAxisSize.min,
        children: List.generate(3, (i) {
          final phase   = (_anim.value + i / 3) % 1.0;
          final opacity =
              (phase < 0.5 ? phase * 2 : (1 - phase) * 2).clamp(0.2, 1.0);
          return Padding(
            padding: const EdgeInsets.symmetric(horizontal: 1),
            child: Opacity(
              opacity: opacity,
              child: const CircleAvatar(
                  radius: 3, backgroundColor: Colors.grey),
            ),
          );
        }),
      ),
    );
  }
}
