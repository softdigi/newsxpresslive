import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../../providers/news_provider.dart';
import '../../widgets/news_card.dart';
import '../detail/article_detail_screen.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_strings.dart';

/// Search screen with debounced query input and results list.
class SearchScreen extends StatefulWidget {
  const SearchScreen({super.key});

  @override
  State<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends State<SearchScreen> {
  final _ctrl = TextEditingController();
  final _focusNode = FocusNode();

  @override
  void dispose() {
    _ctrl.dispose();
    _focusNode.dispose();
    super.dispose();
  }

  void _openArticle(String slug) {
    Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => ArticleDetailScreen(slug: slug)),
    );
  }

  @override
  Widget build(BuildContext context) {
    final prov = context.read<NewsProvider>();

    return Scaffold(
      appBar: AppBar(
        automaticallyImplyLeading: false,
        titleSpacing: 0,
        title: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: TextField(
            controller: _ctrl,
            focusNode: _focusNode,
            autofocus: true,
            textInputAction: TextInputAction.search,
            decoration: InputDecoration(
              hintText: AppStrings.searchHint,
              prefixIcon: const Icon(Icons.search, color: Colors.grey),
              suffixIcon: _ctrl.text.isNotEmpty
                  ? IconButton(
                      icon: const Icon(Icons.clear, color: Colors.grey),
                      onPressed: () {
                        _ctrl.clear();
                        prov.clearSearch();
                      },
                    )
                  : null,
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(24),
                borderSide: BorderSide.none,
              ),
              filled: true,
              contentPadding: const EdgeInsets.symmetric(vertical: 0),
            ),
            onChanged: (q) {
              setState(() {}); // update suffix icon
              prov.searchNews(q);
            },
            onSubmitted: prov.searchNews,
          ),
        ),
      ),
      body: Consumer<NewsProvider>(
        builder: (context, prov, _) {
          if (prov.searchState == LoadState.idle) {
            return const _SearchHint();
          }
          if (prov.searchState == LoadState.loading) {
            return const Center(
                child: CircularProgressIndicator(color: AppColors.primary));
          }
          if (prov.searchState == LoadState.error) {
            return Center(
              child: Text(prov.errorMsg.isNotEmpty
                  ? prov.errorMsg
                  : AppStrings.loadingFailed),
            );
          }
          if (prov.searchResults.isEmpty) {
            return Center(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.search_off_rounded,
                      size: 48, color: Colors.grey),
                  const SizedBox(height: 8),
                  Text(AppStrings.noNews,
                      style: Theme.of(context).textTheme.bodyMedium),
                ],
              ),
            );
          }
          return ListView.separated(
            padding: const EdgeInsets.all(12),
            itemCount: prov.searchResults.length,
            separatorBuilder: (_, __) => const Divider(height: 1),
            itemBuilder: (context, i) {
              final article = prov.searchResults[i];
              return NewsCard(
                article:  article,
                onTap:    () => _openArticle(article.slug),
                compact:  true,
              );
            },
          );
        },
      ),
    );
  }
}

class _SearchHint extends StatelessWidget {
  const _SearchHint();

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(Icons.newspaper_rounded, size: 64,
              color: Colors.grey.shade300),
          const SizedBox(height: 12),
          Text('Search for any news topic',
              style: Theme.of(context)
                  .textTheme
                  .bodyMedium
                  ?.copyWith(color: Colors.grey)),
        ],
      ),
    );
  }
}
