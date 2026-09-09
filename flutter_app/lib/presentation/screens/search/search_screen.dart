import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../../../providers/news_provider.dart';
import '../../widgets/news_card.dart';
import '../detail/article_detail_screen.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/app_strings.dart';

/// Search screen with debounced query input, recent search history,
/// and a results list.
class SearchScreen extends StatefulWidget {
  const SearchScreen({super.key});

  @override
  State<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends State<SearchScreen> {
  static const String _historyKey = 'search_history';
  static const int    _maxHistory = 8;

  final _ctrl      = TextEditingController();
  final _focusNode = FocusNode();

  List<String> _history = [];
  SharedPreferences? _prefs;

  @override
  void initState() {
    super.initState();
    _loadHistory();
  }

  @override
  void dispose() {
    _ctrl.dispose();
    _focusNode.dispose();
    super.dispose();
  }

  // ── History persistence ───────────────────────────────────────────────

  Future<void> _loadHistory() async {
    _prefs = await SharedPreferences.getInstance();
    if (!mounted) return;
    setState(() {
      _history = _prefs!.getStringList(_historyKey) ?? [];
    });
  }

  Future<void> _addToHistory(String query) async {
    final q = query.trim();
    if (q.isEmpty) return;
    _history
      ..remove(q)
      ..insert(0, q);
    if (_history.length > _maxHistory) _history = _history.sublist(0, _maxHistory);
    await _prefs?.setStringList(_historyKey, _history);
    if (mounted) setState(() {});
  }

  Future<void> _clearHistory() async {
    await _prefs?.remove(_historyKey);
    if (mounted) setState(() => _history.clear());
  }

  Future<void> _removeHistoryItem(String q) async {
    _history.remove(q);
    await _prefs?.setStringList(_historyKey, _history);
    if (mounted) setState(() {});
  }

  // ── Navigation ────────────────────────────────────────────────────────

  void _openArticle(String slug) {
    Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => ArticleDetailScreen(slug: slug)),
    );
  }

  void _submitSearch(String query) {
    final q = query.trim();
    if (q.isEmpty) return;
    _addToHistory(q);
    context.read<NewsProvider>().searchNews(q);
    _focusNode.unfocus();
  }

  void _pickHistory(String q) {
    _ctrl.text = q;
    _ctrl.selection = TextSelection.fromPosition(
      TextPosition(offset: q.length),
    );
    _submitSearch(q);
  }

  // ── Build ─────────────────────────────────────────────────────────────

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
            focusNode:  _focusNode,
            autofocus:  true,
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
                        setState(() {});
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
              setState(() {}); // rebuild suffix icon
              prov.searchNews(q);
            },
            onSubmitted: _submitSearch,
          ),
        ),
      ),
      body: Consumer<NewsProvider>(
        builder: (context, prov, _) {
          if (prov.searchState == LoadState.idle) {
            return _buildIdleView(context);
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
                article: article,
                onTap:   () {
                  _addToHistory(_ctrl.text.trim());
                  _openArticle(article.slug);
                },
                compact: true,
              );
            },
          );
        },
      ),
    );
  }

  // ── Idle view: recent searches ────────────────────────────────────────

  Widget _buildIdleView(BuildContext context) {
    final theme = Theme.of(context);
    if (_history.isEmpty) return const _SearchHint();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 16, 8, 4),
          child: Row(
            children: [
              Text(AppStrings.recentSearches,
                  style: theme.textTheme.titleMedium
                      ?.copyWith(fontWeight: FontWeight.w700)),
              const Spacer(),
              TextButton(
                onPressed: _clearHistory,
                style: TextButton.styleFrom(
                    foregroundColor: AppColors.primary,
                    padding: const EdgeInsets.symmetric(
                        horizontal: 8, vertical: 4)),
                child: const Text(AppStrings.clearHistory,
                    style: TextStyle(fontSize: 12)),
              ),
            ],
          ),
        ),
        Expanded(
          child: ListView.builder(
            padding: const EdgeInsets.symmetric(horizontal: 8),
            itemCount: _history.length,
            itemBuilder: (context, i) {
              final q = _history[i];
              return ListTile(
                contentPadding:
                    const EdgeInsets.symmetric(horizontal: 8, vertical: 0),
                leading: const Icon(Icons.history_rounded,
                    color: Colors.grey, size: 20),
                title: Text(q, style: theme.textTheme.bodyMedium),
                trailing: IconButton(
                  icon: const Icon(Icons.close, size: 16, color: Colors.grey),
                  onPressed: () => _removeHistoryItem(q),
                  tooltip: 'Remove',
                ),
                onTap: () => _pickHistory(q),
              );
            },
          ),
        ),
      ],
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
