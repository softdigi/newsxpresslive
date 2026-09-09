import 'package:flutter/material.dart';
import 'package:cached_network_image/cached_network_image.dart';
import '../../../../data/models/news_article.dart';
import '../../../../core/constants/app_colors.dart';
import '../../../../core/utils/date_formatter.dart';
import '../../../../core/utils/string_utils.dart';

/// Large hero card — used as the first/featured article on the Home feed.
class FeaturedNewsCard extends StatelessWidget {
  const FeaturedNewsCard({
    super.key,
    required this.article,
    required this.onTap,
  });

  final NewsArticle  article;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return GestureDetector(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Hero image
            ClipRRect(
              borderRadius: BorderRadius.circular(14),
              child: AspectRatio(
                aspectRatio: 16 / 9,
                child: Stack(
                  fit: StackFit.expand,
                  children: [
                    _image(article.featuredImage),
                    // Gradient overlay
                    DecoratedBox(
                      decoration: BoxDecoration(
                        gradient: LinearGradient(
                          begin: Alignment.topCenter,
                          end:   Alignment.bottomCenter,
                          colors: [
                            Colors.transparent,
                            Colors.black.withOpacity(0.6),
                          ],
                          stops: const [0.5, 1.0],
                        ),
                      ),
                    ),
                    // Breaking badge inside image
                    if (article.isBreaking)
                      Positioned(
                        top: 10,
                        left: 10,
                        child: _badge('BREAKING', AppColors.breakingBadge),
                      ),
                    // Category badge inside image
                    if (article.categoryName?.isNotEmpty ?? false)
                      Positioned(
                        top: article.isBreaking ? 36 : 10,
                        left: 10,
                        child: _badge(article.categoryName!, AppColors.primary),
                      ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 10),
            // Title
            Text(
              article.title,
              maxLines: 3,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.headlineMedium?.copyWith(height: 1.35),
            ),
            const SizedBox(height: 6),
            // Meta row
            Row(
              children: [
                if (article.reporterName?.isNotEmpty ?? false) ...[
                  const Icon(Icons.person_outline, size: 13, color: Colors.grey),
                  const SizedBox(width: 3),
                  Text(article.reporterName!,
                      style: theme.textTheme.bodySmall),
                  const SizedBox(width: 10),
                ],
                const Icon(Icons.access_time_rounded, size: 13, color: Colors.grey),
                const SizedBox(width: 3),
                Text(DateFormatter.timeAgo(article.createdAt),
                    style: theme.textTheme.bodySmall),
                if (article.views > 0) ...[
                  const SizedBox(width: 10),
                  const Icon(Icons.visibility_outlined, size: 13, color: Colors.grey),
                  const SizedBox(width: 3),
                  Text(StringUtils.formatViews(article.views),
                      style: theme.textTheme.bodySmall),
                ],
              ],
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
              color: Colors.grey, size: 40),
        ),
      );
    }
    return CachedNetworkImage(
      imageUrl:    url,
      fit:         BoxFit.cover,
      placeholder: (_, __) => Container(color: AppColors.shimmerBase),
      errorWidget: (_, __, ___) => Container(
        color: AppColors.shimmerBase,
        child: const Icon(Icons.broken_image_outlined, color: Colors.grey),
      ),
    );
  }

  Widget _badge(String text, Color color) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
    decoration: BoxDecoration(
      color:        color,
      borderRadius: BorderRadius.circular(5),
    ),
    child: Text(
      text.toUpperCase(),
      style: const TextStyle(
        color:       Colors.white,
        fontSize:    10,
        fontWeight:  FontWeight.w800,
        letterSpacing: 0.4,
      ),
    ),
  );
}
