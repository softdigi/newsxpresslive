import 'package:flutter_test/flutter_test.dart';
import 'package:hive_flutter/hive_flutter.dart';
import 'package:newsxpresslive/data/services/cache_service.dart';

void main() {
  setUp(() async {
    // Use in-memory Hive for tests (no Flutter binding required).
    Hive.init('/tmp/hive_test_${DateTime.now().millisecondsSinceEpoch}');
    await CacheService.instance.init();
  });

  tearDown(() async {
    await CacheService.instance.clearAll();
    await Hive.deleteFromDisk();
  });

  group('CacheService — availability', () {
    test('isAvailable is true after init', () {
      expect(CacheService.instance.isAvailable, isTrue);
    });
  });

  group('CacheService — feed cache', () {
    test('getCachedFeed returns empty list when cache is cold', () {
      final result = CacheService.instance.getCachedFeed();
      expect(result, isEmpty);
    });

    test('saveFeed + getCachedFeed round-trips correctly', () async {
      final articles = [
        {'id': 1, 'title': 'Test article', 'slug': 'test-article'},
        {'id': 2, 'title': 'Second article', 'slug': 'second-article'},
      ];

      await CacheService.instance.saveFeed(articles);
      final cached = CacheService.instance.getCachedFeed();

      expect(cached, hasLength(2));
      expect(cached.first['title'], 'Test article');
      expect(cached[1]['slug'], 'second-article');
    });

    test('clearAll removes cached feed', () async {
      await CacheService.instance.saveFeed(
        [{'id': 1, 'title': 'article'}],
      );
      await CacheService.instance.clearAll();
      expect(CacheService.instance.getCachedFeed(), isEmpty);
    });
  });

  group('CacheService — article detail cache', () {
    test('getCachedArticle returns null on cache miss', () {
      final result = CacheService.instance.getCachedArticle('non-existent-slug');
      expect(result, isNull);
    });

    test('saveArticle + getCachedArticle round-trips correctly', () async {
      const slug = 'my-great-article';
      final data = {'id': 42, 'title': 'My Great Article', 'slug': slug};

      await CacheService.instance.saveArticle(slug, data);
      final cached = CacheService.instance.getCachedArticle(slug);

      expect(cached, isNotNull);
      expect(cached!['id'], 42);
      expect(cached['title'], 'My Great Article');
    });

    test('different slugs do not collide', () async {
      await CacheService.instance.saveArticle(
        'article-a',
        {'id': 1, 'title': 'A'},
      );
      await CacheService.instance.saveArticle(
        'article-b',
        {'id': 2, 'title': 'B'},
      );

      expect(CacheService.instance.getCachedArticle('article-a')!['id'], 1);
      expect(CacheService.instance.getCachedArticle('article-b')!['id'], 2);
    });

    test('clearAll removes article cache', () async {
      await CacheService.instance.saveArticle(
        'slug-x',
        {'id': 10, 'title': 'X'},
      );
      await CacheService.instance.clearAll();
      expect(CacheService.instance.getCachedArticle('slug-x'), isNull);
    });
  });
}
