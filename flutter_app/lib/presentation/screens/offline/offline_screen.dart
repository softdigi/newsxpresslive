import 'package:flutter/material.dart';
import 'package:lottie/lottie.dart';
import 'package:provider/provider.dart';
import '../../../providers/offline_provider.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_strings.dart';
import '../../../core/constants/lottie_assets.dart';
import '../../../core/utils/date_formatter.dart';
import '../../widgets/news_card.dart';
import '../detail/article_detail_screen.dart';

/// Offline Reading screen — lists articles saved for offline access.
class OfflineScreen extends StatelessWidget {
  const OfflineScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Consumer<OfflineProvider>(
      builder: (context, offline, _) {
        return Scaffold(
          appBar: AppBar(
            title: Text(offline.count > 0
                ? '${AppStrings.navOffline} (${offline.count})'
                : AppStrings.navOffline),
            actions: [
              // Sync button
              if (offline.isSyncing)
                const Padding(
                  padding: EdgeInsets.symmetric(horizontal: 16),
                  child: Center(
                    child: SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(
                          color: AppColors.primary, strokeWidth: 2),
                    ),
                  ),
                )
              else
                IconButton(
                  icon: const Icon(Icons.sync_rounded),
                  tooltip: AppStrings.offlineSync,
                  onPressed: offline.count > 0
                      ? () => offline.sync()
                      : null,
                ),
              // Clear all
              if (offline.count > 0)
                IconButton(
                  icon: const Icon(Icons.delete_sweep_rounded),
                  tooltip: AppStrings.clearAllBookmarks,
                  onPressed: () => _confirmClearAll(context, offline),
                ),
            ],
          ),
          body: RefreshIndicator(
            color: AppColors.primary,
            onRefresh: () => offline.sync(),
            child: offline.entries.isEmpty
                ? _buildEmpty(context)
                : _buildList(context, offline),
          ),
        );
      },
    );
  }

  // ── Empty state ───────────────────────────────────────────────────────

  Widget _buildEmpty(BuildContext context) {
    return ListView(
      // ListView lets RefreshIndicator work when there are no items
      physics: const AlwaysScrollableScrollPhysics(),
      children: [
        SizedBox(
          height: MediaQuery.of(context).size.height * 0.7,
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Lottie.network(
                LottieAssets.emptyOffline,
                width: 180,
                height: 180,
                fit: BoxFit.contain,
                errorBuilder: (_, __, ___) => Icon(
                  Icons.download_for_offline_outlined,
                  size: 72,
                  color: Colors.grey.shade300,
                ),
              ),
              const SizedBox(height: 16),
              Text(
                AppStrings.noOfflineArticles,
                textAlign: TextAlign.center,
                style: Theme.of(context)
                    .textTheme
                    .bodyMedium
                    ?.copyWith(color: Colors.grey),
              ),
              const SizedBox(height: 8),
              Text(
                AppStrings.offlineHint,
                textAlign: TextAlign.center,
                style: Theme.of(context)
                    .textTheme
                    .bodySmall
                    ?.copyWith(color: Colors.grey.shade400),
              ),
            ],
          ),
        ),
      ],
    );
  }

  // ── Article list ──────────────────────────────────────────────────────

  Widget _buildList(BuildContext context, OfflineProvider offline) {
    return Column(
      children: [
        // Sync status banner
        _SyncBanner(offline: offline),

        // List
        Expanded(
          child: ListView.separated(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(12),
            itemCount: offline.entries.length,
            separatorBuilder: (_, __) => const SizedBox(height: 8),
            itemBuilder: (context, i) {
              final entry   = offline.entries[i];
              final article = entry.article;
              return Dismissible(
                key:       ValueKey(article.slug),
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
                onDismissed: (_) => offline.remove(article.slug),
                child: Stack(
                  children: [
                    NewsCard(
                      article: article,
                      compact: false,
                      onTap: () => Navigator.push(
                        context,
                        MaterialPageRoute(
                          builder: (_) =>
                              ArticleDetailScreen(slug: article.slug),
                        ),
                      ),
                    ),
                    // Offline badge overlay
                    Positioned(
                      top: 8,
                      right: 8,
                      child: _OfflineBadge(
                        syncedAt: entry.syncedAt,
                      ),
                    ),
                  ],
                ),
              );
            },
          ),
        ),
      ],
    );
  }

  // ── Confirm clear all ─────────────────────────────────────────────────

  void _confirmClearAll(BuildContext context, OfflineProvider offline) {
    showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text(AppStrings.offlineClearTitle),
        content: const Text(AppStrings.offlineClearBody),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text(AppStrings.cancel),
          ),
          TextButton(
            onPressed: () {
              offline.clearAll();
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

// ── Sync status banner ────────────────────────────────────────────────────────

class _SyncBanner extends StatelessWidget {
  const _SyncBanner({required this.offline});

  final OfflineProvider offline;

  @override
  Widget build(BuildContext context) {
    if (offline.lastSyncAt == null && !offline.isSyncing) return const SizedBox.shrink();

    final label = offline.isSyncing
        ? AppStrings.offlineSyncing
        : '${AppStrings.offlineLastSynced} ${DateFormatter.timeAgo(offline.lastSyncAt!.toIso8601String())}';

    return Container(
      width: double.infinity,
      color: offline.isSyncing
          ? AppColors.primary.withOpacity(0.08)
          : Colors.green.withOpacity(0.08),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
      child: Row(
        children: [
          Icon(
            offline.isSyncing
                ? Icons.sync_rounded
                : Icons.check_circle_outline_rounded,
            size: 14,
            color: offline.isSyncing ? AppColors.primary : Colors.green,
          ),
          const SizedBox(width: 6),
          Text(
            label,
            style: TextStyle(
              fontSize: 12,
              color: offline.isSyncing ? AppColors.primary : Colors.green,
            ),
          ),
        ],
      ),
    );
  }
}

// ── Offline badge ─────────────────────────────────────────────────────────────

class _OfflineBadge extends StatelessWidget {
  const _OfflineBadge({required this.syncedAt});

  final DateTime syncedAt;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 3),
      decoration: BoxDecoration(
        color: Colors.black.withOpacity(0.65),
        borderRadius: BorderRadius.circular(6),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.download_done_rounded,
              size: 11, color: Colors.white),
          const SizedBox(width: 3),
          Text(
            AppStrings.offlineSaved,
            style: const TextStyle(
                fontSize: 10, color: Colors.white, fontWeight: FontWeight.w600),
          ),
        ],
      ),
    );
  }
}
