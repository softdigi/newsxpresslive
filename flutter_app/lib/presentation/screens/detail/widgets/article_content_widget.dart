import 'package:flutter/material.dart';
import 'package:flutter_html/flutter_html.dart';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:provider/provider.dart';
import '../../../../data/models/news_article.dart';
import '../../../../core/constants/app_colors.dart';
import '../../../../core/utils/date_formatter.dart';
import '../../../../core/utils/string_utils.dart';
import '../../../widgets/reporter_profile_card.dart';
import '../../../widgets/ad_banner_widget.dart';
import '../../../widgets/tts_player_widget.dart';
import '../../../../providers/tts_provider.dart';

/// Renders the article hero image, title, metadata, HTML body, ad banner,
/// and reporter profile card. Stateless — receives the article as a parameter.
class ArticleContentWidget extends StatelessWidget {
  const ArticleContentWidget({super.key, required this.article});

  final NewsArticle article;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final art   = article;

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Category / breaking badges
          if ((art.categoryName?.isNotEmpty ?? false) || art.isBreaking)
            Wrap(spacing: 6, children: [
              if (art.isBreaking)
                _badge('BREAKING', AppColors.breakingBadge),
              if (art.categoryName?.isNotEmpty ?? false)
                _badge(art.categoryName!, AppColors.primary),
            ]),
          const SizedBox(height: 10),

          // Title
          Text(
            art.title,
            style: theme.textTheme.headlineLarge?.copyWith(height: 1.35),
          ),
          const SizedBox(height: 8),

          // Meta row
          Wrap(
            spacing: 12,
            runSpacing: 4,
            children: [
              if (art.reporterName?.isNotEmpty ?? false)
                _metaChip(Icons.person_outline, art.reporterName!),
              _metaChip(
                  Icons.access_time, DateFormatter.timeAgo(art.createdAt)),
              _metaChip(
                  Icons.auto_stories_outlined,
                  '${StringUtils.readingMinutes(art.content)} min read'),
              if (art.views > 0)
                _metaChip(Icons.visibility_outlined,
                    '${StringUtils.formatViews(art.views)} views'),
            ],
          ),

          const Divider(height: 24),

          // TTS inline player (visible only when this article is playing)
          Consumer<TtsProvider>(
            builder: (_, tts, __) => tts.articleId == art.id
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

          // Banner ad after article body
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 12),
            child: AdBannerWidget(),
          ),

          // Reporter / Agency profile card
          if (art.reporterName != null && art.reporterName!.isNotEmpty) ...[
            const Divider(height: 24),
            ReporterProfileCard(
              reporterName:  art.reporterName!,
              reporterPhoto: art.reporterPhoto,
              agencyName:    art.agencyName,
            ),
          ],
        ],
      ),
    );
  }

  // ── Private helpers ────────────────────────────────────────────────────

  Widget _badge(String text, Color color) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
        decoration: BoxDecoration(
            color: color, borderRadius: BorderRadius.circular(4)),
        child: Text(
          text.toUpperCase(),
          style: const TextStyle(
            color:       Colors.white,
            fontSize:    10,
            fontWeight:  FontWeight.w700,
            letterSpacing: 0.4,
          ),
        ),
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
