// ignore_for_file: subtype_of_sealed_class

import 'package:flutter_test/flutter_test.dart';
import 'package:newsxpresslive/data/models/news_article.dart';
import 'package:newsxpresslive/data/services/news_service.dart';
import 'package:newsxpresslive/providers/news_provider.dart';

// ── Minimal fakes ─────────────────────────────────────────────────────────────

class _FakeNewsService extends Fake implements NewsService {
  /// Controls what getNewsPage returns.
  NewsPage Function(
    String? categorySlug,
    String? sort,
    int?    lastId,
    String? lastCreatedAt,
  )? pageFactory;

  /// Optional override for getCategories.
  Future<List<dynamic>> Function()? categoriesFactory;

  int callCount = 0;

  @override
  Future<NewsPage> getNewsPage({
    String? categorySlug,
    String? sort,
    int?    lastId,
    String? lastCreatedAt,
  }) async {
    callCount++;
    return pageFactory?.call(categorySlug, sort, lastId, lastCreatedAt) ??
        NewsPage.empty();
  }

  @override
  Future<List<NewsArticle>> getTrending({int limit = 6}) async => [];

  @override
  Future<NewsArticle?> getLatestBreaking() async => null;

  @override
  Future<List<dynamic>> getCategories() async =>
      categoriesFactory != null ? await categoriesFactory!() : [];

  @override
  void dispose() {}
}

// ── Helpers ───────────────────────────────────────────────────────────────────

NewsArticle _article(int id) => NewsArticle(
  id:           id,
  title:        'Article $id',
  slug:         'article-$id',
  content:      'Content $id',
  createdAt:    DateTime(2024, 1, id),
  status:       'approved',
  views:        0,
  isBreaking:   false,
  isFeatured:   false,
  viralScore:   0,
);

// A NewsProvider subclass that accepts a pre-built service for testing.
class _TestableNewsProvider extends NewsProvider {
  _TestableNewsProvider(this._fakeService);

  final _FakeNewsService _fakeService;

  @override
  // ignore: overridden_fields
  NewsService get _service => _fakeService; // replaced via override path
}

// Because NewsProvider builds its own service internally we use a simple
// approach: expose a factory constructor that substitutes the service field.
// Rather than modifying production code we exercise the provider directly.

// ── Tests ─────────────────────────────────────────────────────────────────────

void main() {
  late _FakeNewsService svc;
  late NewsProvider provider;

  setUp(() {
    svc = _FakeNewsService();
    // Create provider with injected service via the internal constructor path.
    // (NewsProvider exposes a @visibleForTesting constructor for tests.)
    provider = NewsProvider.forTest(service: svc);
  });

  tearDown(() => provider.dispose());

  group('NewsProvider — feed loading', () {
    test('initial state is idle with empty articles', () {
      expect(provider.loadState, LoadState.idle);
      expect(provider.articles, isEmpty);
      expect(provider.hasMore, isTrue);
    });

    test('loadNewsFeed(reset:true) populates articles on success', () async {
      svc.pageFactory = (_, __, ___, ____) => NewsPage(
        articles:          [_article(1), _article(2)],
        hasMore:           true,
        nextLastId:        2,
        nextLastCreatedAt: '2024-01-02 00:00:00',
      );

      await provider.loadNewsFeed(reset: true);

      expect(provider.loadState, LoadState.loaded);
      expect(provider.articles, hasLength(2));
      expect(provider.hasMore, isTrue);
    });

    test('empty response sets hasMore to false', () async {
      svc.pageFactory = (_, __, ___, ____) => NewsPage.empty();

      await provider.loadNewsFeed(reset: true);

      expect(provider.hasMore, isFalse);
      expect(provider.articles, isEmpty);
    });

    test('reset clears previous articles before fetching', () async {
      svc.pageFactory = (_, __, ___, ____) => NewsPage(
        articles: [_article(1)], hasMore: false,
      );
      await provider.loadNewsFeed(reset: true);
      expect(provider.articles, hasLength(1));

      svc.pageFactory = (_, __, ___, ____) => NewsPage(
        articles: [_article(99)], hasMore: false,
      );
      await provider.loadNewsFeed(reset: true);
      expect(provider.articles, hasLength(1));
      expect(provider.articles.first.id, 99);
    });
  });

  group('NewsProvider — pagination (cursor)', () {
    test('second page appends articles', () async {
      var firstCall = true;
      svc.pageFactory = (_, __, lastId, lastCreatedAt) {
        if (firstCall) {
          firstCall = false;
          return NewsPage(
            articles:          [_article(1), _article(2)],
            hasMore:           true,
            nextLastId:        2,
            nextLastCreatedAt: '2024-01-02 00:00:00',
          );
        }
        // Second page
        expect(lastId, 2);
        expect(lastCreatedAt, '2024-01-02 00:00:00');
        return NewsPage(
          articles: [_article(3)], hasMore: false,
        );
      };

      await provider.loadNewsFeed(reset: true);
      await provider.loadNewsFeed(); // page 2

      expect(provider.articles, hasLength(3));
      expect(provider.hasMore, isFalse);
    });

    test('does not fetch again when hasMore is false', () async {
      svc.pageFactory = (_, __, ___, ____) => NewsPage.empty();
      await provider.loadNewsFeed(reset: true);

      final countBefore = svc.callCount;
      await provider.loadNewsFeed();
      expect(svc.callCount, countBefore); // no extra call
    });
  });

  group('NewsProvider — error states', () {
    test('ApiException sets loadState to error', () async {
      svc.pageFactory = (_, __, ___, ____) => throw ApiException('network');

      await provider.loadNewsFeed(reset: true);

      expect(provider.loadState, LoadState.error);
      expect(provider.errorMsg, 'network');
    });

    test('unknown exception sets generic error message', () async {
      svc.pageFactory = (_, __, ___, ____) => throw Exception('boom');

      await provider.loadNewsFeed(reset: true);

      expect(provider.loadState, LoadState.error);
      expect(provider.errorMsg, contains('Something went wrong'));
    });
  });
}
