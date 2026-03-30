import 'package:flutter/material.dart';
import 'package:cached_network_image/cached_network_image.dart';
import '../../data/models/news_article.dart';
import '../../core/constants/app_colors.dart';
import '../../core/utils/date_formatter.dart';
import '../../core/utils/string_utils.dart';

/// Reusable card for displaying a news article preview.
///
/// Used in: Home feed, Search results, Related news, Bookmarks.
class NewsCard extends StatelessWidget {
  const NewsCard({
    super.key,
    required this.article,
    required this.onTap,
    this.compact = false,
  });

  final NewsArticle article;
  final VoidCallback onTap;

  /// [compact] = smaller card without the image (used in sidebar / related list).
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return compact ? _buildCompact(context) : _buildFull(context);
  }

  // ── Full card (image + headline + meta) ──────────────────────────────

  Widget _buildFull(BuildContext context) {
    final theme = Theme.of(context);
    return GestureDetector(
      onTap: onTap,
      child: Card(
        clipBehavior: Clip.antiAlias,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Thumbnail
            AspectRatio(
              aspectRatio: 16 / 9,
              child: _thumbnail(article.featuredImage),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 10, 12, 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Category + Breaking badges
                  if ((article.categoryName?.isNotEmpty ?? false) ||
                      article.isBreaking)
                    Wrap(
                      spacing: 6,
                      children: [
                        if (article.isBreaking)
                          _badge('BREAKING', AppColors.breakingBadge),
                        if (article.categoryName?.isNotEmpty ?? false)
                          _badge(article.categoryName!, AppColors.primary),
                      ],
                    ),
                  const SizedBox(height: 6),
                  // Title
                  Text(
                    article.title,
                    maxLines: 3,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w600,
                      height: 1.4,
                    ),
                  ),
                  const SizedBox(height: 6),
                  // Meta row
                  Row(
                    children: [
                      const Icon(Icons.access_time_rounded,
                          size: 12, color: Colors.grey),
                      const SizedBox(width: 4),
                      Text(
                        DateFormatter.timeAgo(article.createdAt),
                        style: theme.textTheme.bodySmall,
                      ),
                      if (article.views > 0) ...[
                        const SizedBox(width: 10),
                        const Icon(Icons.visibility_outlined,
                            size: 12, color: Colors.grey),
                        const SizedBox(width: 3),
                        Text(
                          StringUtils.formatViews(article.views),
                          style: theme.textTheme.bodySmall,
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

  // ── Compact card (no image, horizontal) ──────────────────────────────

  Widget _buildCompact(BuildContext context) {
    final theme = Theme.of(context);
    return GestureDetector(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            ClipRRect(
              borderRadius: BorderRadius.circular(8),
              child: SizedBox(
                width: 80,
                height: 60,
                child: _thumbnail(article.featuredImage),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    article.title,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w600),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    DateFormatter.timeAgo(article.createdAt),
                    style: theme.textTheme.bodySmall,
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  // ── Helpers ───────────────────────────────────────────────────────────

  Widget _thumbnail(String? url) {
    if (url == null || url.isEmpty) {
      return Container(
        color: AppColors.shimmerBase,
        child: const Center(
            child: Icon(Icons.image_not_supported_outlined,
                color: Colors.grey, size: 32)),
      );
    }
    return CachedNetworkImage(
      imageUrl: url,
      fit: BoxFit.cover,
      placeholder: (_, __) =>
          Container(color: AppColors.shimmerBase),
      errorWidget: (_, __, ___) =>
          Container(
            color: AppColors.shimmerBase,
            child: const Icon(Icons.broken_image_outlined,
                color: Colors.grey),
          ),
    );
  }

  Widget _badge(String text, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
      decoration: BoxDecoration(
        color: color,
        borderRadius: BorderRadius.circular(4),
      ),
      child: Text(
        text.toUpperCase(),
        style: const TextStyle(
            color: Colors.white,
            fontSize: 10,
            fontWeight: FontWeight.w700,
            letterSpacing: 0.4),
      ),
    );
  }
}
