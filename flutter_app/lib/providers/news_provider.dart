import 'dart:async';
import 'package:flutter/foundation.dart';
import '../data/models/news_article.dart';
import '../data/models/category.dart';
import '../data/services/news_service.dart';
import '../data/services/api_service.dart';

enum LoadState { idle, loading, loaded, error }

/// Manages news feed state: categories, articles, pagination, search, trending.
class NewsProvider extends ChangeNotifier {
  NewsProvider() : _service = NewsService(api: ApiService());

  final NewsService _service;
  bool _initialized = false;

  // ── Categories ────────────────────────────────────────────────────────
  List<Category> _categories    = [];
  Category?      _selectedCat;

  List<Category> get categories   => _categories;
  Category?      get selectedCat  => _selectedCat;

  // ── News feed ─────────────────────────────────────────────────────────
  final List<NewsArticle> _articles    = [];
  LoadState               _loadState   = LoadState.idle;
  String                  _errorMsg    = '';
  int                     _currentPage = 1;
  bool                    _hasMore     = true;

  List<NewsArticle> get articles  => _articles;
  LoadState         get loadState => _loadState;
  String            get errorMsg  => _errorMsg;
  bool              get hasMore   => _hasMore;
  bool              get isLoading => _loadState == LoadState.loading;

  // ── Trending news ─────────────────────────────────────────────────────
  final List<NewsArticle> _trending      = [];
  LoadState               _trendingState = LoadState.idle;

  List<NewsArticle> get trending      => _trending;
  LoadState         get trendingState => _trendingState;

  // ── Breaking news ─────────────────────────────────────────────────────
  NewsArticle? _breaking;
  NewsArticle? get breaking => _breaking;

  // ── Search ────────────────────────────────────────────────────────────
  final List<NewsArticle> _searchResults = [];
  LoadState               _searchState   = LoadState.idle;
  String                  _lastQuery     = '';
  Timer?                  _searchDebounce;

  List<NewsArticle> get searchResults => _searchResults;
  LoadState         get searchState   => _searchState;

  // ── Init ──────────────────────────────────────────────────────────────

  /// Call once from HomeScreen. Guards against re-loading on tab revisit.
  Future<void> init() async {
    if (_initialized) return;
    _initialized = true;
    await Future.wait([
      loadCategories(),
      loadNewsFeed(reset: true),
      loadBreaking(),
      loadTrending(),
    ]);
  }

  // ── Categories ────────────────────────────────────────────────────────

  Future<void> loadCategories() async {
    try {
      _categories = await _service.getCategories();
    } catch (_) {
      // Non-fatal — continue without categories
    }
    notifyListeners();
  }

  void selectCategory(Category? cat) {
    if (_selectedCat?.id == cat?.id) return;
    _selectedCat = cat;
    loadNewsFeed(reset: true);
  }

  // ── News feed ─────────────────────────────────────────────────────────

  Future<void> loadNewsFeed({bool reset = false}) async {
    if (_loadState == LoadState.loading) return;
    if (reset) {
      _articles.clear();
      _currentPage = 1;
      _hasMore     = true;
    }
    if (!_hasMore) return;

    _loadState = LoadState.loading;
    _errorMsg  = '';
    notifyListeners();

    try {
      final items = await _service.getNewsList(
        page:         _currentPage,
        perPage:      10,
        categorySlug: _selectedCat?.slug,
      );
      if (items.isEmpty) {
        _hasMore = false;
      } else {
        _articles.addAll(items);
        _currentPage++;
      }
      _loadState = LoadState.loaded;
    } on ApiException catch (e) {
      _loadState = LoadState.error;
      _errorMsg  = e.message;
    } catch (_) {
      _loadState = LoadState.error;
      _errorMsg  = 'Something went wrong. Please try again.';
    }
    notifyListeners();
  }

  Future<void> refresh() async {
    _initialized = false;
    await init();
  }

  // ── Trending ──────────────────────────────────────────────────────────

  Future<void> loadTrending() async {
    _trendingState = LoadState.loading;
    notifyListeners();
    try {
      final items = await _service.getTrending(limit: 6);
      _trending
        ..clear()
        ..addAll(items);
      _trendingState = LoadState.loaded;
    } catch (_) {
      _trendingState = LoadState.error;
    }
    notifyListeners();
  }

  // ── Breaking news ─────────────────────────────────────────────────────

  Future<void> loadBreaking() async {
    try {
      _breaking = await _service.getLatestBreaking();
      notifyListeners();
    } catch (_) {}
  }

  // ── Search ────────────────────────────────────────────────────────────

  Future<void> searchNews(String query) async {
    _searchDebounce?.cancel();

    if (query.trim().isEmpty) {
      _searchResults.clear();
      _searchState = LoadState.idle;
      _lastQuery   = '';
      notifyListeners();
      return;
    }

    // Show loading state immediately for visual feedback
    _searchState = LoadState.loading;
    notifyListeners();

    _searchDebounce = Timer(const Duration(milliseconds: 400), () async {
      if (query == _lastQuery && _searchState == LoadState.loaded) return;
      _lastQuery = query;

      try {
        final results = await _service.search(query);
        _searchResults
          ..clear()
          ..addAll(results);
        _searchState = LoadState.loaded;
      } on ApiException catch (e) {
        _searchState = LoadState.error;
        _errorMsg    = e.message;
      } catch (_) {
        _searchState = LoadState.error;
        _errorMsg    = 'Search failed. Please try again.';
      }
      notifyListeners();
    });
  }

  void clearSearch() {
    _searchDebounce?.cancel();
    _searchResults.clear();
    _searchState = LoadState.idle;
    _lastQuery   = '';
    notifyListeners();
  }

  @override
  void dispose() {
    _searchDebounce?.cancel();
    _service.dispose();
    super.dispose();
  }
}

