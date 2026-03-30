import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../providers/news_provider.dart';
import '../../providers/bookmark_provider.dart';
import '../widgets/news_card.dart';
import '../widgets/breaking_ticker.dart';
import '../widgets/category_chip.dart';
import '../../core/constants/app_colors.dart';
import '../../core/constants/app_strings.dart';
import '../screens/detail/article_detail_screen.dart';

/// Home screen: breaking ticker, category chips, paginated news feed.
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
    WidgetsBinding.instance.addPostFrameCallback((_) {
      context.read<NewsProvider>().init();
    });
  }

  void _onScroll() {
    if (_scroll.position.pixels >=
        _scroll.position.maxScrollExtent - 200) {
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

  void _openArticle(BuildContext context, String slug) {
    Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => ArticleDetailScreen(slug: slug),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text(
          AppStrings.appName,
          style: TextStyle(
            color: AppColors.primary,
            fontWeight: FontWeight.w900,
            letterSpacing: -0.5,
          ),
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.search_rounded),
            tooltip: 'Search',
            onPressed: () => DefaultTabController.of(context),
          ),
        ],
      ),
      body: Consumer<NewsProvider>(
        builder: (context, prov, _) {
          return RefreshIndicator(
            color: AppColors.primary,
            onRefresh: prov.refresh,
            child: CustomScrollView(
              controller: _scroll,
              physics: const AlwaysScrollableScrollPhysics(),
              slivers: [
                // Breaking ticker
                if (prov.breaking != null)
                  SliverToBoxAdapter(
                    child: BreakingTicker(
                      article: prov.breaking!,
                      onTap: () =>
                          _openArticle(context, prov.breaking!.slug),
                    ),
                  ),

                const SliverToBoxAdapter(child: SizedBox(height: 12)),

                // Category chips
                if (prov.categories.isNotEmpty)
                  SliverToBoxAdapter(
                    child: CategoryChips(
                      categories: prov.categories,
                      selected:   prov.selectedCat,
                      onSelect:   prov.selectCategory,
                    ),
                  ),

                const SliverToBoxAdapter(child: SizedBox(height: 12)),

                // Section title
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 16),
                    child: Text(
                      AppStrings.latestNews,
                      style: Theme.of(context).textTheme.headlineMedium,
                    ),
                  ),
                ),

                const SliverToBoxAdapter(child: SizedBox(height: 10)),

                // Error state
                if (prov.loadState == LoadState.error &&
                    prov.articles.isEmpty)
                  SliverFillRemaining(
                    child: _errorView(context, prov),
                  )

                // News grid
                else
                  SliverPadding(
                    padding: const EdgeInsets.symmetric(horizontal: 12),
                    sliver: SliverGrid(
                      gridDelegate:
                      const SliverGridDelegateWithFixedCrossAxisCount(
                        crossAxisCount:     2,
                        mainAxisSpacing:    10,
                        crossAxisSpacing:   10,
                        childAspectRatio:   0.72,
                      ),
                      delegate: SliverChildBuilderDelegate(
                        (context, i) {
                          final article = prov.articles[i];
                          return NewsCard(
                            article: article,
                            onTap:   () => _openArticle(context, article.slug),
                          );
                        },
                        childCount: prov.articles.length,
                      ),
                    ),
                  ),

                // Load more / End indicator
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: prov.isLoading
                        ? const Center(
                            child: CircularProgressIndicator(
                                color: AppColors.primary))
                        : !prov.hasMore
                            ? const Center(
                                child: Text('You\'ve reached the end',
                                    style: TextStyle(
                                        color: Colors.grey,
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

  Widget _errorView(BuildContext context, NewsProvider prov) {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.wifi_off_rounded, size: 48, color: Colors.grey),
          const SizedBox(height: 12),
          Text(prov.errorMsg.isNotEmpty
              ? prov.errorMsg
              : AppStrings.loadingFailed),
          const SizedBox(height: 16),
          ElevatedButton.icon(
            onPressed: () => prov.refresh(),
            icon:  const Icon(Icons.refresh),
            label: const Text(AppStrings.retry),
          ),
        ],
      ),
    );
  }
}
