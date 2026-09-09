import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import '../../../../data/models/news_article.dart';
import '../../../../data/services/analytics_service.dart';
import '../../../../core/constants/app_colors.dart';
import '../../../../core/constants/app_strings.dart';
import '../../../../core/utils/string_utils.dart';
import '../../../../providers/bookmark_provider.dart';
import '../../../../providers/offline_provider.dart';
import '../../../../providers/tts_provider.dart';

/// AppBar action buttons: TTS, share, offline download and bookmark.
/// Designed to be placed as a single child inside [AppBar.actions].
class ArticleActionsWidget extends StatelessWidget {
  const ArticleActionsWidget({
    super.key,
    required this.article,
    required this.onShare,
  });

  final NewsArticle article;
  final VoidCallback onShare;

  @override
  Widget build(BuildContext context) {
    final art = article;

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        // ── TTS listen / stop ───────────────────────────────────────────
        Consumer<TtsProvider>(
          builder: (_, tts, __) {
            final isThisArticle = tts.articleId == art.id;
            final isActive      = isThisArticle && tts.isActive;
            return Semantics(
              label:  isActive ? AppStrings.ttsStop : AppStrings.ttsListen,
              button: true,
              child: IconButton(
                icon: Icon(
                  isActive
                      ? Icons.headphones_rounded
                      : Icons.headphones_outlined,
                  color: isActive ? AppColors.primary : null,
                ),
                tooltip: isActive ? AppStrings.ttsStop : AppStrings.ttsListen,
                onPressed: () {
                  if (isActive) {
                    tts.stop();
                  } else {
                    tts.speak(
                      articleId: art.id,
                      title:     art.title,
                      plainText: StringUtils.stripHtml(art.content),
                    );
                  }
                },
              ),
            );
          },
        ),

        // ── Share ────────────────────────────────────────────────────────
        Semantics(
          label:  AppStrings.share,
          button: true,
          child: IconButton(
            icon:    const Icon(Icons.share_rounded),
            tooltip: AppStrings.share,
            onPressed: onShare,
          ),
        ),

        // ── Offline download toggle ──────────────────────────────────────
        Consumer<OfflineProvider>(
          builder: (_, offline, __) {
            final saved  = offline.isOffline(art.slug);
            final label  = saved ? AppStrings.offlineRemove : AppStrings.offlineDownload;
            return Semantics(
              label:  label,
              button: true,
              child: IconButton(
                icon: Icon(saved
                    ? Icons.download_done_rounded
                    : Icons.download_for_offline_outlined),
                tooltip: label,
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
              ),
            );
          },
        ),

        // ── Bookmark ────────────────────────────────────────────────────
        Consumer<BookmarkProvider>(
          builder: (_, bm, __) {
            final saved = bm.isBookmarked(art.id);
            final label = saved
                ? AppStrings.bookmarkRemove
                : AppStrings.bookmark;
            return Semantics(
              label:  label,
              button: true,
              child: IconButton(
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
              ),
            );
          },
        ),
      ],
    );
  }
}
