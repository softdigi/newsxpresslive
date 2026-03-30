import 'package:flutter/material.dart';
import 'package:cached_network_image/cached_network_image.dart';
import '../../../../data/models/news_article.dart';
import '../../../../core/constants/app_colors.dart';
import '../../../../core/utils/date_formatter.dart';

/// Horizontally scrollable trending news strip.
/// Each item is a small card with thumbnail, rank number, and title.
class TrendingSection extends StatelessWidget {
  const TrendingSection({
    super.key,
    required this.articles,
    required this.onArticleTap,
  });

  final List<NewsArticle>          articles;
  final void Function(NewsArticle) onArticleTap;

  @override
  Widget build(BuildContext context) {
    if (articles.isEmpty) return const SizedBox.shrink();

    return SizedBox(
      height: 190,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 16),
        itemCount: articles.length,
        separatorBuilder: (_, __) => const SizedBox(width: 12),
        itemBuilder: (context, i) {
          return _TrendingCard(
            article: articles[i],
            rank:    i + 1,
            onTap:   () => onArticleTap(articles[i]),
          );
        },
      ),
    );
  }
}

class _TrendingCard extends StatelessWidget {
  const _TrendingCard({
    required this.article,
    required this.rank,
    required this.onTap,
  });

  final NewsArticle  article;
  final int          rank;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return GestureDetector(
      onTap: onTap,
      child: SizedBox(
        width: 140,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Thumbnail with rank badge
            Stack(
              children: [
                ClipRRect(
                  borderRadius: BorderRadius.circular(10),
                  child: SizedBox(
                    height: 100,
                    width:  140,
                    child: _image(article.featuredImage),
                  ),
                ),
                // Rank badge
                Positioned(
                  top: 6,
                  left: 6,
                  child: Container(
                    width:  26,
                    height: 26,
                    alignment: Alignment.center,
                    decoration: const BoxDecoration(
                      color: AppColors.primary,
                      shape: BoxShape.circle,
                    ),
                    child: Text(
                      '$rank',
                      style: const TextStyle(
                        color:      Colors.white,
                        fontSize:   12,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 6),
            // Title
            Text(
              article.title,
              maxLines: 3,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodySmall?.copyWith(
                fontWeight: FontWeight.w600,
                height: 1.3,
                color: theme.textTheme.bodyMedium?.color,
              ),
            ),
            const SizedBox(height: 3),
            // Time
            Text(
              DateFormatter.timeAgo(article.createdAt),
              style: theme.textTheme.labelSmall,
              maxLines: 1,
            ),
          ],
        ),
      ),
    );
  }

  Widget _image(String? url) {
    if (url == null || url.isEmpty) {
      return Container(
        color: AppColors.shimmerBase,
        child: const Center(
          child: Icon(Icons.image_not_supported_outlined,
              color: Colors.grey, size: 24),
        ),
      );
    }
    return CachedNetworkImage(
      imageUrl:    url,
      fit:         BoxFit.cover,
      placeholder: (_, __) => Container(color: AppColors.shimmerBase),
      errorWidget: (_, __, ___) => Container(
        color: AppColors.shimmerBase,
        child: const Icon(Icons.broken_image_outlined,
            color: Colors.grey, size: 24),
      ),
    );
  }
}
