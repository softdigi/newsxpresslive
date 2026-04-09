import 'package:flutter/material.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_strings.dart';
import '../../../data/models/social_model.dart';
import '../../../data/services/social_service.dart';

/// Displays the social news feed — articles from reporters the
/// authenticated user follows.  Falls back to trending news when
/// the user's following list is empty.
class SocialFeedScreen extends StatefulWidget {
  const SocialFeedScreen({super.key, required this.socialService});

  final SocialService socialService;

  @override
  State<SocialFeedScreen> createState() => _SocialFeedScreenState();
}

class _SocialFeedScreenState extends State<SocialFeedScreen> {
  final List<Map<String, dynamic>> _articles = [];
  int  _page       = 1;
  bool _hasMore    = true;
  bool _loading    = false;
  bool _isFallback = false;

  final _scrollController = ScrollController();

  @override
  void initState() {
    super.initState();
    _scrollController.addListener(_onScroll);
    _loadPage();
  }

  @override
  void dispose() {
    _scrollController
      ..removeListener(_onScroll)
      ..dispose();
    super.dispose();
  }

  void _onScroll() {
    if (_scrollController.position.pixels >=
            _scrollController.position.maxScrollExtent - 200 &&
        !_loading &&
        _hasMore) {
      _loadPage();
    }
  }

  Future<void> _loadPage() async {
    if (_loading) return;
    setState(() => _loading = true);

    final seenIds = _articles.map((a) => a['id'] as int? ?? 0).toList();
    final result = await widget.socialService.getSocialFeed(
      page:    _page,
      limit:   15,
      exclude: seenIds,
    );

    if (!mounted) return;
    setState(() {
      _articles.addAll(result.articles);
      _hasMore    = result.hasMore;
      _isFallback = result.isFallback;
      _page       = result.page + 1;
      _loading    = false;
    });
  }

  Future<void> _refresh() async {
    setState(() {
      _articles.clear();
      _page    = 1;
      _hasMore = true;
    });
    await _loadPage();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text(AppStrings.socialFeedTitle),
        backgroundColor: AppColors.primary,
        foregroundColor: Colors.white,
        elevation: 0,
      ),
      body: RefreshIndicator(
        onRefresh: _refresh,
        color: AppColors.primary,
        child: _buildBody(),
      ),
    );
  }

  Widget _buildBody() {
    if (_articles.isEmpty && _loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_articles.isEmpty) {
      return ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          const SizedBox(height: 80),
          Center(
            child: Column(
              children: [
                const Icon(Icons.people_outline_rounded,
                    size: 64, color: Colors.grey),
                const SizedBox(height: 16),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 32),
                  child: Text(
                    AppStrings.socialFeedEmpty,
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: Colors.grey, fontSize: 15),
                  ),
                ),
              ],
            ),
          ),
        ],
      );
    }

    return ListView.builder(
      controller: _scrollController,
      physics: const AlwaysScrollableScrollPhysics(),
      itemCount: _articles.length + (_hasMore ? 1 : 0) + (_isFallback ? 1 : 0),
      itemBuilder: (context, index) {
        // Fallback banner at top
        if (_isFallback && index == 0) {
          return _FallbackBanner();
        }
        final articleIndex = _isFallback ? index - 1 : index;

        // Loading indicator at bottom
        if (articleIndex == _articles.length) {
          return const Padding(
            padding: EdgeInsets.all(24),
            child: Center(child: CircularProgressIndicator()),
          );
        }

        return _ArticleCard(article: _articles[articleIndex]);
      },
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────

class _FallbackBanner extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.all(12),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AppColors.primary.withOpacity(0.08),
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: AppColors.primary.withOpacity(0.25)),
      ),
      child: Row(
        children: [
          const Icon(Icons.info_outline_rounded,
              color: AppColors.primary, size: 18),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              AppStrings.socialFeedFallback,
              style: const TextStyle(
                  color: AppColors.primary, fontSize: 12),
            ),
          ),
        ],
      ),
    );
  }
}

class _ArticleCard extends StatelessWidget {
  const _ArticleCard({required this.article});
  final Map<String, dynamic> article;

  @override
  Widget build(BuildContext context) {
    final theme   = Theme.of(context);
    final isDark  = theme.brightness == Brightness.dark;
    final title   = article['title'] as String? ?? '';
    final excerpt = article['excerpt'] as String? ?? '';
    final image   = article['featured_image'] as String?;
    final catName = article['category_name'] as String? ?? '';
    final repName = article['reporter_name'] as String?;
    final repVerified = article['reporter_verified'] == true ||
        article['reporter_verified'] == 1;
    final createdAt = article['created_at'] as String? ?? '';

    return Card(
      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      color: isDark ? AppColors.cardDark : AppColors.cardLight,
      elevation: 1,
      shape:
          RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      child: InkWell(
        borderRadius: BorderRadius.circular(10),
        onTap: () {
          // Navigate to article detail — caller can override via route
        },
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Thumbnail
              if (image != null)
                ClipRRect(
                  borderRadius: BorderRadius.circular(8),
                  child: Image.network(
                    image,
                    width: 88,
                    height: 88,
                    fit: BoxFit.cover,
                    errorBuilder: (_, __, ___) => const SizedBox(
                        width: 88, height: 88,
                        child: Icon(Icons.broken_image_outlined,
                            color: Colors.grey)),
                  ),
                ),
              if (image != null) const SizedBox(width: 12),

              // Text
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // Category chip
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 8, vertical: 2),
                      decoration: BoxDecoration(
                        color: AppColors.chipBackground,
                        borderRadius: BorderRadius.circular(4),
                      ),
                      child: Text(catName,
                          style: const TextStyle(
                              color: AppColors.chipText,
                              fontSize: 11,
                              fontWeight: FontWeight.w600)),
                    ),
                    const SizedBox(height: 6),

                    // Title
                    Text(
                      title,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        fontWeight: FontWeight.bold,
                        fontSize: 14,
                        color: isDark
                            ? AppColors.textPrimaryDark
                            : AppColors.textPrimaryLight,
                      ),
                    ),

                    // Excerpt
                    if (excerpt.isNotEmpty) ...[
                      const SizedBox(height: 4),
                      Text(
                        excerpt,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          fontSize: 12,
                          color: isDark
                              ? AppColors.textSecondaryDark
                              : AppColors.textSecondaryLight,
                        ),
                      ),
                    ],

                    const SizedBox(height: 6),

                    // Reporter + date
                    Row(
                      children: [
                        if (repName != null) ...[
                          Icon(Icons.person_outline_rounded,
                              size: 12,
                              color: isDark
                                  ? AppColors.textSecondaryDark
                                  : AppColors.textSecondaryLight),
                          const SizedBox(width: 3),
                          Text(
                            repName,
                            style: TextStyle(
                              fontSize: 11,
                              color: isDark
                                  ? AppColors.textSecondaryDark
                                  : AppColors.textSecondaryLight,
                            ),
                          ),
                          if (repVerified) ...[
                            const SizedBox(width: 2),
                            const Icon(Icons.verified_rounded,
                                size: 11, color: AppColors.primary),
                          ],
                          const SizedBox(width: 8),
                        ],
                        if (createdAt.isNotEmpty)
                          Text(
                            _formatDate(createdAt),
                            style: TextStyle(
                              fontSize: 11,
                              color: isDark
                                  ? AppColors.textSecondaryDark
                                  : AppColors.textSecondaryLight,
                            ),
                          ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  String _formatDate(String raw) {
    try {
      final dt   = DateTime.parse(raw);
      final diff = DateTime.now().difference(dt);
      if (diff.inMinutes < 60) return '${diff.inMinutes}m ago';
      if (diff.inHours < 24)   return '${diff.inHours}h ago';
      return '${diff.inDays}d ago';
    } catch (_) {
      return raw;
    }
  }
}
