import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../data/models/agency_models.dart';
import '../data/services/agency_service.dart';

const _kApiKey = 'agency_api_key';
const _kApiSecret = 'agency_api_secret';
const _kDashboardTtlMinutes = 5;

class AgencyProvider extends ChangeNotifier {
  AgencyProvider(this._service) {
    _restoreCredentials();
  }

  final AgencyService _service;

  // ── State ─────────────────────────────────────────────────────────────
  AgencyProfile? agencyProfile;
  List<AgencyArticle> articles = [];
  List<AgencyTransaction> transactions = [];
  List<AgencyWithdrawal> withdrawals = [];
  AgencyRevenueSummary? revenueSummary;

  bool isLoading = false;
  String? errorMsg;
  bool isLoggedIn = false;

  DateTime? _dashboardCachedAt;

  // ── Credentials restore ───────────────────────────────────────────────

  Future<void> _restoreCredentials() async {
    final prefs = await SharedPreferences.getInstance();
    final key = prefs.getString(_kApiKey);
    final secret = prefs.getString(_kApiSecret);
    if (key != null && key.isNotEmpty && secret != null && secret.isNotEmpty) {
      _service.setCredentials(key, secret);
      isLoggedIn = true;
      notifyListeners();
    }
  }

  Future<void> _saveCredentials(String key, String secret) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kApiKey, key);
    await prefs.setString(_kApiSecret, secret);
  }

  // ── Auth ──────────────────────────────────────────────────────────────

  Future<bool> login(String email, String password) async {
    _setLoading(true);
    try {
      final result = await _service.login(email, password);
      final key = result['apiKey'] as String;
      final secret = result['apiSecret'] as String;
      _service.setCredentials(key, secret);
      await _saveCredentials(key, secret);
      isLoggedIn = true;
      errorMsg = null;
      notifyListeners();
      return true;
    } catch (e) {
      errorMsg = e.toString();
      notifyListeners();
      return false;
    } finally {
      _setLoading(false);
    }
  }

  Future<void> logout() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_kApiKey);
    await prefs.remove(_kApiSecret);
    _service.setCredentials('', '');
    isLoggedIn = false;
    agencyProfile = null;
    articles = [];
    transactions = [];
    withdrawals = [];
    revenueSummary = null;
    _dashboardCachedAt = null;
    errorMsg = null;
    notifyListeners();
  }

  // ── Dashboard ─────────────────────────────────────────────────────────

  Future<void> loadDashboard({bool forceRefresh = false}) async {
    if (!forceRefresh && _dashboardCachedAt != null) {
      final age = DateTime.now().difference(_dashboardCachedAt!).inMinutes;
      if (age < _kDashboardTtlMinutes) return;
    }
    _setLoading(true);
    try {
      final result = await _service.getDashboard();
      agencyProfile = result['profile'] as AgencyProfile;
      articles = (result['recentArticles'] as List<AgencyArticle>);
      _dashboardCachedAt = DateTime.now();
      errorMsg = null;
    } catch (e) {
      errorMsg = e.toString();
    } finally {
      _setLoading(false);
    }
  }

  // ── Articles ──────────────────────────────────────────────────────────

  Future<void> loadArticles({
    String? status,
    int page = 1,
    String? search,
  }) async {
    _setLoading(true);
    try {
      final result = await _service.getArticles(
          status: status, page: page, search: search);
      articles = result;
      errorMsg = null;
    } catch (e) {
      errorMsg = e.toString();
    } finally {
      _setLoading(false);
    }
  }

  Future<bool> submitArticle(Map<String, dynamic> data) async {
    _setLoading(true);
    try {
      final ok = await _service.submitArticle(data);
      if (ok) _dashboardCachedAt = null;
      errorMsg = ok ? null : 'Submission failed';
      return ok;
    } catch (e) {
      errorMsg = e.toString();
      return false;
    } finally {
      _setLoading(false);
    }
  }

  // ── Bulk upload ───────────────────────────────────────────────────────

  Future<Map<String, dynamic>?> uploadCsv(String filePath) async {
    _setLoading(true);
    try {
      final result = await _service.bulkUploadCsv(filePath);
      errorMsg = null;
      return result;
    } catch (e) {
      errorMsg = e.toString();
      return null;
    } finally {
      _setLoading(false);
    }
  }

  // ── Revenue ───────────────────────────────────────────────────────────

  Future<void> loadRevenue({String? from, String? to}) async {
    _setLoading(true);
    try {
      revenueSummary = await _service.getRevenue(from: from, to: to);
      errorMsg = null;
    } catch (e) {
      errorMsg = e.toString();
    } finally {
      _setLoading(false);
    }
  }

  // ── Wallet ────────────────────────────────────────────────────────────

  Future<void> loadWallet({int page = 1}) async {
    _setLoading(true);
    try {
      transactions = await _service.getTransactions(page: page);
      withdrawals = await _service.getWithdrawals(page: page);
      errorMsg = null;
    } catch (e) {
      errorMsg = e.toString();
    } finally {
      _setLoading(false);
    }
  }

  Future<bool> requestWithdrawal(
      double amount, String method, String accountDetails) async {
    _setLoading(true);
    try {
      final ok =
          await _service.requestWithdrawal(amount, method, accountDetails);
      if (ok) {
        agencyProfile = null;
        _dashboardCachedAt = null;
      }
      errorMsg = ok ? null : 'Withdrawal request failed';
      return ok;
    } catch (e) {
      errorMsg = e.toString();
      return false;
    } finally {
      _setLoading(false);
    }
  }

  // ── API Keys ──────────────────────────────────────────────────────────

  Future<Map<String, dynamic>> getApiKeys() async {
    try {
      return await _service.getApiKeys();
    } catch (_) {
      return {'apiKey': '', 'apiSecret': ''};
    }
  }

  Future<Map<String, dynamic>?> rotateApiKeys() async {
    _setLoading(true);
    try {
      final result = await _service.rotateApiKeys();
      final key = result['apiKey'] as String;
      final secret = result['apiSecret'] as String;
      _service.setCredentials(key, secret);
      await _saveCredentials(key, secret);
      if (agencyProfile != null) {
        // Refresh profile to reflect new keys
        _dashboardCachedAt = null;
      }
      errorMsg = null;
      return result;
    } catch (e) {
      errorMsg = e.toString();
      return null;
    } finally {
      _setLoading(false);
    }
  }

  // ── Helpers ───────────────────────────────────────────────────────────

  void _setLoading(bool v) {
    isLoading = v;
    notifyListeners();
  }
}
