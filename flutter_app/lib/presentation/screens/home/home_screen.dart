import 'package:flutter/material.dart';
import 'package:lottie/lottie.dart';
import 'package:provider/provider.dart';
import '../../providers/news_provider.dart';
import '../../providers/language_provider.dart';
import '../widgets/news_card.dart';
import '../widgets/breaking_ticker.dart';
import '../widgets/category_chip.dart';
import 'home/widgets/shimmer_news_grid.dart';
import 'home/widgets/featured_news_card.dart';
import 'home/widgets/trending_section.dart';
import 'home/widgets/section_header.dart';
import '../../core/constants/app_colors.dart';
import '../../core/constants/app_strings.dart';
import '../../core/constants/lottie_assets.dart';
import '../screens/detail/article_detail_screen.dart';
import '../../main_navigation.dart';

/// Home screen: SliverAppBar, breaking ticker, category chips,
/// featured hero card, trending strip, paginated 2-col grid.
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  final ScrollController _scroll = ScrollController();

  @override
  void initState() {
    super.initState();
    _scroll.addListener(_onScroll);
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      final langProv = context.read<LanguageProvider>();
      await langProv.loadFromPrefs();
      final newsProv = context.read<NewsProvider>();
      newsProv.setLanguageCodes(langProv.selected);
      newsProv.init();
    });
  }

  void _onScroll() {
    if (_scroll.position.pixels >=
        _scroll.position.maxScrollExtent - 250) {
      final prov = context.read<NewsProvider>();
      if (!prov.isLoading && prov.hasMore) {
        prov.loadNewsFeed();
      }
    }
  }

  @override
  void dispose() {
    _scroll.dispose();
    super.dispose();
  }

  void _openArticle(String slug) {
    Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => ArticleDetailScreen(slug: slug)),
    );
  }

  void _goToSearch() => mainNavKey.currentState?.switchTab(1);

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Consumer<NewsProvider>(
        builder: (context, prov, _) {
          return RefreshIndicator(
            color:    AppColors.primary,
            onRefresh: prov.refresh,
            child: CustomScrollView(
              controller: _scroll,
              physics: const AlwaysScrollableScrollPhysics(),
              slivers: [
                // ── SliverAppBar ─────────────────────────────────────────
                _buildAppBar(context),

                // ── Breaking ticker ───────────────────────────────────────
                if (prov.breaking != null)
                  SliverToBoxAdapter(
                    child: BreakingTicker(
                      article: prov.breaking!,
                      onTap:   () => _openArticle(prov.breaking!.slug),
                    ),
                  ),

                // ── Category chips ────────────────────────────────────────
                if (prov.categories.isNotEmpty)
                  SliverToBoxAdapter(
                    child: Padding(
                      padding: const EdgeInsets.only(top: 14, bottom: 4),
                      child: CategoryChips(
                        categories: prov.categories,
                        selected:   prov.selectedCat,
                        onSelect:   prov.selectCategory,
                      ),
                    ),
                  ),

                // ── Featured article (first item) ─────────────────────────
                if (prov.loadState == LoadState.loading && prov.articles.isEmpty)
                  const SliverToBoxAdapter(
                    child: Padding(
                      padding: EdgeInsets.only(top: 16),
                      child: ShimmerFeaturedCard(),
                    ),
                  )
                else if (prov.articles.isNotEmpty)
                  SliverToBoxAdapter(
                    child: Padding(
                      padding: const EdgeInsets.only(top: 16),
                      child: FeaturedNewsCard(
                        article: prov.articles.first,
                        onTap:   () => _openArticle(prov.articles.first.slug),
                      ),
                    ),
                  ),

                // ── Trending section ──────────────────────────────────────
                if (prov.trending.isNotEmpty) ...[
                  const SliverToBoxAdapter(child: SizedBox(height: 20)),
                  SliverToBoxAdapter(
                    child: SectionHeader(title: '🔥 Trending'),
                  ),
                  const SliverToBoxAdapter(child: SizedBox(height: 10)),
                  SliverToBoxAdapter(
                    child: TrendingSection(
                      articles:      prov.trending,
                      onArticleTap:  (a) => _openArticle(a.slug),
                    ),
                  ),
                ],

                // ── Latest News section ───────────────────────────────────
                const SliverToBoxAdapter(child: SizedBox(height: 20)),
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 16),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text(
                          AppStrings.latestNews,
                          style: Theme.of(context).textTheme.titleMedium
                              ?.copyWith(fontWeight: FontWeight.w700),
                        ),
                        // Viral-sort toggle
                        Consumer<NewsProvider>(
                          builder: (_, p, __) => TextButton.icon(
                            onPressed: p.toggleViralSort,
                            icon: Text(
                              '🔥',
                              style: TextStyle(
                                fontSize: 14,
                                color: p.viralSort
                                    ? Colors.deepOrange
                                    : Colors.grey,
                              ),
                            ),
                            label: Text(
                              p.viralSort ? 'Viral' : 'Latest',
                              style: TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.w600,
                                color: p.viralSort
                                    ? Colors.deepOrange
                                    : Colors.grey,
                              ),
                            ),
                            style: TextButton.styleFrom(
                              minimumSize: Size.zero,
                              padding: const EdgeInsets.symmetric(
                                  horizontal: 8, vertical: 4),
                              tapTargetSize:
                                  MaterialTapTargetSize.shrinkWrap,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
                const SliverToBoxAdapter(child: SizedBox(height: 10)),

                // ── Error (empty state) ───────────────────────────────────
                if (prov.loadState == LoadState.error && prov.articles.isEmpty)
                  SliverFillRemaining(child: _errorView(prov)),

                // ── Shimmer skeleton (first load) ─────────────────────────
                else if (prov.loadState == LoadState.loading &&
                    prov.articles.isEmpty)
                  const SliverToBoxAdapter(child: ShimmerNewsGrid()),

                // ── News grid (skip first item — shown as featured) ───────
                else
                  SliverPadding(
                    padding: const EdgeInsets.symmetric(horizontal: 12),
                    sliver: SliverGrid(
                      gridDelegate:
                      const SliverGridDelegateWithFixedCrossAxisCount(
                        crossAxisCount:   2,
                        mainAxisSpacing:  10,
                        crossAxisSpacing: 10,
                        childAspectRatio: 0.72,
                      ),
                      delegate: SliverChildBuilderDelegate(
                        (context, i) {
                          // Skip index 0 — it's the featured card above
                          final article = prov.articles[i + 1];
                          return NewsCard(
                            article: article,
                            onTap:   () => _openArticle(article.slug),
                          );
                        },
                        childCount: (prov.articles.length - 1)
                            .clamp(0, prov.articles.length),
                      ),
                    ),
                  ),

                // ── Load-more indicator ───────────────────────────────────
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.all(20),
                    child: prov.isLoading && prov.articles.isNotEmpty
                        ? const Center(
                            child: CircularProgressIndicator(
                                color: AppColors.primary, strokeWidth: 2))
                        : !prov.hasMore && prov.articles.isNotEmpty
                            ? Center(
                                child: Text('— You\'ve reached the end —',
                                    style: TextStyle(
                                        color: Colors.grey.shade400,
                                        fontSize: 12)))
                            : const SizedBox.shrink(),
                  ),
                ),
              ],
            ),
          );
        },
      ),
    );
  }

  // ── SliverAppBar ─────────────────────────────────────────────────────

  Widget _buildAppBar(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return SliverAppBar(
      floating:   true,
      snap:       true,
      elevation:  0,
      backgroundColor: isDark
          ? AppColors.scaffoldDark
          : Colors.white,
      title: Row(
        children: [
          // Logo dot
          Container(
            width: 10, height: 10,
            decoration: const BoxDecoration(
              color: AppColors.primary,
              shape: BoxShape.circle,
            ),
          ),
          const SizedBox(width: 6),
          const Text(
            AppStrings.appName,
            style: TextStyle(
              color:       AppColors.primary,
              fontWeight:  FontWeight.w900,
              fontSize:    20,
              letterSpacing: -0.5,
            ),
          ),
        ],
      ),
      actions: [
        IconButton(
          icon:    const Icon(Icons.search_rounded),
          tooltip: AppStrings.navSearch,
          onPressed: _goToSearch,
        ),
        const SizedBox(width: 4),
      ],
    );
  }

  // ── Error view ───────────────────────────────────────────────────────

  Widget _errorView(NewsProvider prov) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Lottie.network(
              LottieAssets.networkError,
              width: 160,
              height: 160,
              fit: BoxFit.contain,
              errorBuilder: (_, __, ___) => const Icon(
                Icons.wifi_off_rounded,
                size: 52,
                color: Colors.grey,
              ),
            ),
            const SizedBox(height: 12),
            Text(
              prov.errorMsg.isNotEmpty
                  ? prov.errorMsg
                  : AppStrings.loadingFailed,
              textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.grey),
            ),
            const SizedBox(height: 20),
            ElevatedButton.icon(
              onPressed: prov.refresh,
              icon:  const Icon(Icons.refresh_rounded),
              label: const Text(AppStrings.retry),
            ),
          ],
        ),
      ),
    );
  }
}

