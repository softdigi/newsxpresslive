import 'package:flutter/material.dart';
import 'package:lottie/lottie.dart';
import 'package:provider/provider.dart';
import '../../../providers/bookmark_provider.dart';
import '../../widgets/news_card.dart';
import '../detail/article_detail_screen.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_strings.dart';
import '../../../core/constants/lottie_assets.dart';

/// Bookmarks screen — shows locally saved articles.
class BookmarksScreen extends StatelessWidget {
  const BookmarksScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Consumer<BookmarkProvider>(
      builder: (context, bm, _) {
        return Scaffold(
          appBar: AppBar(
            title: Text(bm.count > 0
                ? '${AppStrings.navBookmarks} (${bm.count})'
                : AppStrings.navBookmarks),
            actions: [
              if (bm.count > 0)
                IconButton(
                  icon: const Icon(Icons.delete_sweep_rounded),
                  tooltip: AppStrings.clearAllBookmarks,
                  onPressed: () => _confirmClearAll(context, bm),
                ),
            ],
          ),
          body: RefreshIndicator(
            color: AppColors.primary,
            onRefresh: () => bm.reload(),
            child: bm.bookmarks.isEmpty
                ? _buildEmpty(context)
                : _buildList(context, bm),
          ),
        );
      },
    );
  }

  // ── Empty state ───────────────────────────────────────────────────────

  Widget _buildEmpty(BuildContext context) {
    return ListView(
      // ListView is required so RefreshIndicator works even when empty
      physics: const AlwaysScrollableScrollPhysics(),
      children: [
        SizedBox(
          height: MediaQuery.of(context).size.height * 0.7,
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Lottie.network(
                LottieAssets.emptyBookmarks,
                width: 180,
                height: 180,
                fit: BoxFit.contain,
                errorBuilder: (_, __, ___) => const Icon(
                  Icons.bookmark_border_rounded,
                  size: 72,
                  color: Colors.grey,
                ),
              ),
              const SizedBox(height: 12),
              Text(
                AppStrings.noBookmarks,
                textAlign: TextAlign.center,
                style: Theme.of(context)
                    .textTheme
                    .bodyMedium
                    ?.copyWith(color: Colors.grey),
              ),
            ],
          ),
        ),
      ],
    );
  }

  // ── List ──────────────────────────────────────────────────────────────

  Widget _buildList(BuildContext context, BookmarkProvider bm) {
    return ListView.separated(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.all(12),
      itemCount: bm.bookmarks.length,
      separatorBuilder: (_, __) => const SizedBox(height: 8),
      itemBuilder: (context, i) {
        final article = bm.bookmarks[i];
        return Dismissible(
          key:       ValueKey(article.id),
          direction: DismissDirection.endToStart,
          background: Container(
            alignment: Alignment.centerRight,
            padding: const EdgeInsets.only(right: 20),
            decoration: BoxDecoration(
              color: Colors.red,
              borderRadius: BorderRadius.circular(12),
            ),
            child: const Icon(Icons.delete_rounded, color: Colors.white),
          ),
          onDismissed: (_) => bm.remove(article.id),
          child: NewsCard(
            article: article,
            compact: false,
            onTap: () => Navigator.push(
              context,
              MaterialPageRoute(
                builder: (_) => ArticleDetailScreen(slug: article.slug),
              ),
            ),
          ),
        );
      },
    );
  }

  // ── Confirm clear all ─────────────────────────────────────────────────

  void _confirmClearAll(BuildContext context, BookmarkProvider bm) {
    showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text(AppStrings.clearAllConfirm),
        content: const Text(AppStrings.clearAllBody),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text(AppStrings.cancel),
          ),
          TextButton(
            onPressed: () {
              bm.clearAll();
              Navigator.pop(ctx);
            },
            style: TextButton.styleFrom(foregroundColor: AppColors.primary),
            child: const Text(AppStrings.clear),
          ),
        ],
      ),
    );
  }
}
