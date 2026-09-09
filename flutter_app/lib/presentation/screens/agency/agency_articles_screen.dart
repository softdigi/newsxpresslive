import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../core/constants/app_colors.dart';
import '../../../data/models/agency_models.dart';
import '../../../providers/agency_provider.dart';

class AgencyArticlesScreen extends StatefulWidget {
  const AgencyArticlesScreen({super.key});

  @override
  State<AgencyArticlesScreen> createState() => _AgencyArticlesScreenState();
}

class _AgencyArticlesScreenState extends State<AgencyArticlesScreen> {
  String _statusFilter = '';
  final _searchCtrl = TextEditingController();
  int _page = 1;
  bool _hasMore = true;
  final _scrollCtrl = ScrollController();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load(reset: true));
    _scrollCtrl.addListener(_onScroll);
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
    _scrollCtrl.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (_scrollCtrl.position.pixels >=
            _scrollCtrl.position.maxScrollExtent - 200 &&
        !context.read<AgencyProvider>().isLoading &&
        _hasMore) {
      _loadMore();
    }
  }

  Future<void> _load({bool reset = false}) async {
    if (reset) {
      _page = 1;
      _hasMore = true;
    }
    final provider = context.read<AgencyProvider>();
    final before = provider.articles.length;
    await provider.loadArticles(
        status: _statusFilter, page: _page, search: _searchCtrl.text.trim());
    if (mounted) {
      final after = provider.articles.length;
      if (after == before && !reset) setState(() => _hasMore = false);
    }
  }

  void _loadMore() {
    _page++;
    _load();
  }

  void _showDetail(BuildContext context, AgencyArticle article) {
    showDialog(
      context: context,
      builder: (_) => AlertDialog(
        title: Text(article.title),
        content: SingleChildScrollView(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              _detailRow('Status', article.status),
              _detailRow('Category', article.category),
              _detailRow('External ID', article.externalId),
              _detailRow('Submitted',
                  article.submittedAt.toString().split('.').first),
              _detailRow('Impressions', article.impressions.toString()),
              _detailRow('Clicks', article.clicks.toString()),
              _detailRow(
                  'Revenue', '₹${article.revenueEarned.toStringAsFixed(2)}'),
              if (article.imageUrl.isNotEmpty)
                _detailRow('Image URL', article.imageUrl),
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Close'),
          ),
        ],
      ),
    );
  }

  Widget _detailRow(String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('$label: ',
              style: const TextStyle(
                  fontWeight: FontWeight.bold,
                  color: AppColors.textSecondaryLight,
                  fontSize: 13)),
          Expanded(
              child: Text(value, style: const TextStyle(fontSize: 13))),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Consumer<AgencyProvider>(
      builder: (context, provider, _) {
        return Scaffold(
          backgroundColor: AppColors.scaffoldLight,
          appBar: AppBar(
            backgroundColor: AppColors.primary,
            foregroundColor: Colors.white,
            title: const Text('Articles'),
          ),
          body: Column(
            children: [
              // Search bar
              Container(
                color: AppColors.cardLight,
                padding:
                    const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                child: TextField(
                  controller: _searchCtrl,
                  decoration: InputDecoration(
                    hintText: 'Search articles…',
                    prefixIcon: const Icon(Icons.search),
                    border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(10),
                        borderSide: BorderSide.none),
                    filled: true,
                    fillColor: AppColors.scaffoldLight,
                    contentPadding: const EdgeInsets.symmetric(vertical: 0),
                  ),
                  onSubmitted: (_) => _load(reset: true),
                ),
              ),
              // Filter chips
              Container(
                color: AppColors.cardLight,
                padding:
                    const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
                child: SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  child: Row(
                    children: ['', 'pending', 'approved', 'rejected']
                        .map((s) => Padding(
                              padding: const EdgeInsets.only(right: 8),
                              child: FilterChip(
                                label: Text(
                                    s.isEmpty ? 'All' : _capitalize(s)),
                                selected: _statusFilter == s,
                                selectedColor:
                                    AppColors.primary.withOpacity(0.15),
                                checkmarkColor: AppColors.primary,
                                onSelected: (_) {
                                  setState(() => _statusFilter = s);
                                  _load(reset: true);
                                },
                              ),
                            ))
                        .toList(),
                  ),
                ),
              ),
              const Divider(height: 1),
              // List
              Expanded(
                child: provider.isLoading && provider.articles.isEmpty
                    ? const Center(child: CircularProgressIndicator())
                    : provider.errorMsg != null && provider.articles.isEmpty
                        ? Center(
                            child: Column(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Text(provider.errorMsg!,
                                    style: const TextStyle(
                                        color: AppColors.primary)),
                                const SizedBox(height: 8),
                                ElevatedButton(
                                    onPressed: () => _load(reset: true),
                                    child: const Text('Retry')),
                              ],
                            ),
                          )
                        : RefreshIndicator(
                            onRefresh: () => _load(reset: true),
                            child: ListView.builder(
                              controller: _scrollCtrl,
                              padding: const EdgeInsets.all(12),
                              itemCount: provider.articles.length +
                                  (_hasMore ? 1 : 0),
                              itemBuilder: (ctx, i) {
                                if (i == provider.articles.length) {
                                  return const Padding(
                                    padding: EdgeInsets.all(16),
                                    child: Center(
                                        child: CircularProgressIndicator()),
                                  );
                                }
                                final article = provider.articles[i];
                                return _ArticleCard(
                                  article: article,
                                  onTap: () =>
                                      _showDetail(context, article),
                                );
                              },
                            ),
                          ),
              ),
            ],
          ),
        );
      },
    );
  }

  String _capitalize(String s) =>
      s.isEmpty ? s : s[0].toUpperCase() + s.substring(1);
}

class _ArticleCard extends StatelessWidget {
  const _ArticleCard({required this.article, required this.onTap});
  final AgencyArticle article;
  final VoidCallback onTap;

  Color _badgeColor(String s) {
    switch (s) {
      case 'approved':
        return Colors.green;
      case 'rejected':
        return AppColors.primary;
      default:
        return AppColors.accent;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      elevation: 2,
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: Text(
                      article.title,
                      style: const TextStyle(
                          fontWeight: FontWeight.w600, fontSize: 14),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Container(
                    padding: const EdgeInsets.symmetric(
                        horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: _badgeColor(article.status),
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: Text(
                      article.status,
                      style: const TextStyle(
                          color: Colors.white, fontSize: 11),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              Row(
                children: [
                  const Icon(Icons.calendar_today_outlined,
                      size: 12, color: AppColors.textSecondaryLight),
                  const SizedBox(width: 4),
                  Text(
                    article.submittedAt.toString().split(' ').first,
                    style: const TextStyle(
                        fontSize: 12,
                        color: AppColors.textSecondaryLight),
                  ),
                  const Spacer(),
                  const Icon(Icons.visibility_outlined,
                      size: 12, color: AppColors.textSecondaryLight),
                  const SizedBox(width: 4),
                  Text(
                    '${article.impressions}',
                    style: const TextStyle(
                        fontSize: 12,
                        color: AppColors.textSecondaryLight),
                  ),
                  const SizedBox(width: 12),
                  const Icon(Icons.attach_money,
                      size: 12, color: Colors.green),
                  Text(
                    article.revenueEarned.toStringAsFixed(2),
                    style: const TextStyle(fontSize: 12, color: Colors.green),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
