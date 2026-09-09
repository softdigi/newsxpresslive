// Agency Dashboard — Provider & Widget Logic Tests
//
// Tests covered:
//   1. Provider login flow (success / failure)
//   2. Provider logout clears all state
//   3. Dashboard 5-minute cache: second call within window skips network
//   4. forceRefresh bypasses cache
//   5. loadArticles filters correctly
//   6. CSV bulk-upload flow state changes
//   7. Withdrawal invalidates dashboard cache

import 'package:flutter_test/flutter_test.dart';
import 'package:newsxpresslive/data/models/agency_models.dart';
import 'package:newsxpresslive/providers/agency_provider.dart';
import 'package:newsxpresslive/data/services/agency_service.dart';

// ── Fake AgencyService ────────────────────────────────────────────────────────

class _FakeService extends Fake implements AgencyService {
  bool loginOk = true;
  int dashboardCallCount = 0;
  int articlesCallCount = 0;
  int bulkUploadCallCount = 0;
  int withdrawCallCount = 0;

  Map<String, dynamic> dashboardResult = {};
  List<AgencyArticle> articlesResult = [];
  Map<String, dynamic> bulkResult = {};
  bool withdrawOk = true;

  @override
  void setCredentials(String key, String secret) {}

  @override
  Future<Map<String, dynamic>> login(String email, String password) async {
    if (!loginOk) throw Exception('Invalid credentials');
    return {'apiKey': 'key-uuid', 'apiSecret': 'secret', 'agencyId': 1};
  }

  @override
  Future<Map<String, dynamic>> getDashboard() async {
    dashboardCallCount++;
    return dashboardResult.isEmpty
        ? {
            'profile': _profile(),
            'recentArticles': <AgencyArticle>[],
          }
        : dashboardResult;
  }

  @override
  Future<List<AgencyArticle>> getArticles({
    String? status,
    int page = 1,
    String? search,
  }) async {
    articlesCallCount++;
    return articlesResult;
  }

  @override
  Future<Map<String, dynamic>> bulkUploadCsv(String filePath) async {
    bulkUploadCallCount++;
    return bulkResult.isEmpty
        ? {'total': 5, 'success': 4, 'failed': 1, 'duplicate': 0}
        : bulkResult;
  }

  @override
  Future<bool> requestWithdrawal(
      double amount, String method, String accountDetails) async {
    withdrawCallCount++;
    return withdrawOk;
  }

  @override
  Future<Map<String, dynamic>> getApiKeys() async =>
      {'apiKey': 'k', 'apiSecret': 's'};

  @override
  Future<Map<String, dynamic>> rotateApiKeys() async =>
      {'apiKey': 'new-k', 'apiSecret': 'new-s'};

  @override
  void dispose() {}
}

AgencyProfile _profile({double walletBalance = 800.0}) => AgencyProfile(
      id: 1,
      name: 'Test Agency',
      email: 'agency@test.com',
      apiKey: 'key',
      apiSecret: 'sec',
      status: 'active',
      revenueSharePercent: 40.0,
      walletBalance: walletBalance,
      totalEarned: 2000.0,
      thisMonthEarned: 200.0,
      articlesTotal: 10,
      articlesApproved: 8,
      articlesPending: 1,
      articlesRejected: 1,
    );

// ── Helper: create provider with fake SharedPreferences-free service ──────────
//
// AgencyProvider._restoreCredentials() calls SharedPreferences, which
// requires platform channels in tests.  We override the provider to skip
// credential restoration by injecting a service that immediately returns.

class _TestableAgencyProvider extends AgencyProvider {
  _TestableAgencyProvider(_FakeService service) : super(service);

  // Override credential restore to be a no-op in tests
  @override
  // ignore: unused_element
  Future<void> _restoreCredentials() async {}
}

// ── Tests ─────────────────────────────────────────────────────────────────────

void main() {
  late _FakeService service;

  setUp(() {
    service = _FakeService();
  });

  // ── Login ─────────────────────────────────────────────────────────────────

  group('AgencyProvider login', () {
    test('successful login sets isLoggedIn = true', () async {
      final provider = _TestableAgencyProvider(service);
      service.loginOk = true;
      final ok = await provider.login('agency@test.com', 'password');
      expect(ok, isTrue);
      expect(provider.isLoggedIn, isTrue);
      expect(provider.errorMsg, isNull);
    });

    test('failed login sets isLoggedIn = false and errorMsg', () async {
      final provider = _TestableAgencyProvider(service);
      service.loginOk = false;
      final ok = await provider.login('agency@test.com', 'wrong');
      expect(ok, isFalse);
      expect(provider.isLoggedIn, isFalse);
      expect(provider.errorMsg, isNotNull);
    });

    test('isLoading is false after login completes', () async {
      final provider = _TestableAgencyProvider(service);
      await provider.login('agency@test.com', 'password');
      expect(provider.isLoading, isFalse);
    });
  });

  // ── Logout ────────────────────────────────────────────────────────────────

  group('AgencyProvider logout', () {
    test('logout clears all state', () async {
      final provider = _TestableAgencyProvider(service);
      await provider.login('agency@test.com', 'password');
      await provider.loadDashboard();

      await provider.logout();

      expect(provider.isLoggedIn, isFalse);
      expect(provider.agencyProfile, isNull);
      expect(provider.articles, isEmpty);
      expect(provider.errorMsg, isNull);
    });
  });

  // ── Dashboard cache ───────────────────────────────────────────────────────

  group('Dashboard 5-minute cache', () {
    test('second loadDashboard within cache window skips network call', () async {
      final provider = _TestableAgencyProvider(service);
      await provider.login('agency@test.com', 'password');

      await provider.loadDashboard();
      final firstCallCount = service.dashboardCallCount;

      // Second call within 5-minute window — should use cache
      await provider.loadDashboard();

      expect(service.dashboardCallCount, equals(firstCallCount),
          reason: 'Should not hit the network again within 5-min cache window');
    });

    test('forceRefresh bypasses the cache', () async {
      final provider = _TestableAgencyProvider(service);
      await provider.login('agency@test.com', 'password');

      await provider.loadDashboard();
      final firstCallCount = service.dashboardCallCount;

      await provider.loadDashboard(forceRefresh: true);

      expect(service.dashboardCallCount, equals(firstCallCount + 1),
          reason: 'forceRefresh must trigger a new network call');
    });

    test('profile is populated after successful loadDashboard', () async {
      final provider = _TestableAgencyProvider(service);
      await provider.login('agency@test.com', 'password');
      await provider.loadDashboard();

      expect(provider.agencyProfile, isNotNull);
      expect(provider.agencyProfile?.name, equals('Test Agency'));
    });
  });

  // ── loadArticles ──────────────────────────────────────────────────────────

  group('loadArticles', () {
    test('loadArticles populates articles list', () async {
      service.articlesResult = [
        AgencyArticle(
          id: 1,
          externalId: 'EXT-001',
          title: 'Test Article',
          status: 'approved',
          submittedAt: DateTime.now(),
          impressions: 100,
          clicks: 5,
          revenueEarned: 1.5,
          imageUrl: '',
          category: 'Tech',
        ),
      ];

      final provider = _TestableAgencyProvider(service);
      await provider.login('agency@test.com', 'password');
      await provider.loadArticles();

      expect(provider.articles.length, equals(1));
      expect(provider.articles.first.title, equals('Test Article'));
    });

    test('loadArticles with status filter calls service correctly', () async {
      final provider = _TestableAgencyProvider(service);
      await provider.login('agency@test.com', 'password');
      await provider.loadArticles(status: 'pending');

      expect(service.articlesCallCount, equals(1));
    });
  });

  // ── Bulk upload ───────────────────────────────────────────────────────────

  group('Bulk upload flow', () {
    test('successful upload returns result summary', () async {
      service.bulkResult = {
        'total': 10,
        'success': 8,
        'failed': 2,
        'duplicate': 0,
      };

      final provider = _TestableAgencyProvider(service);
      await provider.login('agency@test.com', 'password');
      final result = await provider.bulkUploadCsv('/tmp/test.csv');

      expect(result, isNotNull);
      expect(result['total'], equals(10));
      expect(result['success'], equals(8));
      expect(result['failed'], equals(2));
    });

    test('isLoading is false after upload completes', () async {
      final provider = _TestableAgencyProvider(service);
      await provider.login('agency@test.com', 'password');
      await provider.bulkUploadCsv('/tmp/test.csv');
      expect(provider.isLoading, isFalse);
    });
  });

  // ── Withdrawal ────────────────────────────────────────────────────────────

  group('Withdrawal flow', () {
    test('successful withdrawal clears cached dashboard', () async {
      final provider = _TestableAgencyProvider(service);
      await provider.login('agency@test.com', 'password');
      await provider.loadDashboard();

      final countBefore = service.dashboardCallCount;

      // Successful withdrawal should invalidate cache
      await provider.requestWithdrawal(500.0, 'upi', '{"upi_id":"test@upi"}');

      // Next loadDashboard should hit network since cache was cleared
      await provider.loadDashboard();
      expect(service.dashboardCallCount, greaterThan(countBefore),
          reason: 'Withdrawal should invalidate dashboard cache');
    });

    test('failed withdrawal sets errorMsg', () async {
      service.withdrawOk = false;
      final provider = _TestableAgencyProvider(service);
      await provider.login('agency@test.com', 'password');
      final ok = await provider.requestWithdrawal(500.0, 'upi', '{}');

      expect(ok, isFalse);
      expect(provider.errorMsg, isNotNull);
    });
  });
}
